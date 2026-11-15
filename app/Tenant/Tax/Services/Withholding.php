<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Tax\Exceptions\WithholdingRefused;
use App\Tenant\Tax\Models\WithholdingCertificate;
use App\Tenant\Tax\Models\WithholdingRate;
use App\Tenant\Tax\WithholdingDeduction;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Withholding tax: resolve the rate, deduct it, certify it - PF-083.
 *
 * ## Nothing here reaches a document total
 *
 * Withholding belongs to the settlement path. This class computes a deduction
 * from a payment and records the certificate; it has no method that returns a
 * figure for an invoice, deliberately, because the moment one exists somebody
 * will put it on one.
 *
 * ## The rate is resolved by specificity, not by whichever row comes back
 *
 * Four shapes of row can match one payment: this party and this item type,
 * this party, this item type, or neither. The most specific wins, and a
 * party-specific row beats an item-specific one - see
 * {@see WithholdingRate::specificity()} for why that ordering is a decision
 * rather than a convenience.
 *
 * Two rows of equal specificity cannot both match: the exclusion constraint on
 * the table refuses overlapping validity for the same four keys, so the order
 * is total and the resolution is deterministic without a tie-break.
 *
 * ## Its own reporting, separate from VAT
 *
 * {@see totalsFor()} reads the certificates, not the ledger and not the VAT
 * accumulators. Withholding has its own return in most regimes, filed on its
 * own cycle, and a figure derived from a VAT accumulator would be right only
 * where the two cycles happen to coincide.
 */
final class Withholding
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    /**
     * The rate that applies, or null if the jurisdiction has none configured.
     *
     * Null rather than a zero rate: "nobody has configured this jurisdiction"
     * and "this party is exempt" are different facts, and a payer that
     * withholds nothing because of the first is liable for what it should have
     * withheld. {@see deduct()} takes a rate rather than looking one up, so a
     * caller cannot skip the distinction by accident.
     */
    public function rateFor(
        string $entityId,
        string $jurisdiction,
        CarbonInterface $on,
        ?string $partyId = null,
        ?string $itemType = null,
    ): ?WithholdingRate {
        $candidates = WithholdingRate::query()
            ->where('entity_id', $entityId)
            ->where('jurisdiction', $jurisdiction)
            ->effectiveOn($on)
            ->where(fn ($q) => $q->whereNull('party_id')->when(
                $partyId !== null,
                fn ($inner) => $inner->orWhere('party_id', $partyId),
            ))
            ->where(fn ($q) => $q->whereNull('item_type')->when(
                $itemType !== null,
                fn ($inner) => $inner->orWhere('item_type', $itemType),
            ))
            ->get();

        $best = null;

        foreach ($candidates as $candidate) {
            if ($best === null || $candidate->specificity() > $best->specificity()) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * What the payer withholds from one payment.
     *
     * Takes the net and the tax separately because the base is a regime's
     * choice: some withhold on the net, some on the gross, and the difference
     * is the whole VAT amount rather than a rounding difference.
     *
     * @throws WithholdingRefused
     */
    public function deduct(
        Money $net,
        Money $tax,
        WithholdingRate $rate,
        ?RoundingPolicy $policy = null,
    ): WithholdingDeduction {
        if ($net->currency !== $tax->currency) {
            throw WithholdingRefused::mixedCurrencies($net->currency, $tax->currency);
        }

        $policy ??= RoundingPolicy::halfUp(Scale::Amount);
        $base = $rate->isOnTheNet() ? $net : $net->plus($tax);

        if ($rate->isZero()) {
            return WithholdingDeduction::none($base, $rate->jurisdiction, $rate);
        }

        $withheld = Money::of(
            $base->multipliedBy($rate->fraction(), $policy)->toPresentation($policy),
            $base->currency,
        );

        if ($withheld->abs()->isGreaterThan($base->abs())) {
            throw WithholdingRefused::moreThanTheBase((string) $withheld, (string) $base);
        }

        return new WithholdingDeduction(
            $base,
            $withheld,
            (string) $rate->percentage()->strippedOfTrailingZeros(),
            $rate->jurisdiction,
            $rate->base,
            (string) $rate->getKey(),
            $rate->source,
        );
    }

    /**
     * Record the certificate the payee needs.
     *
     * The period is the DEDUCTION's, taken from the payment date rather than
     * from an invoice: a credit is claimed in the period the deduction was
     * made, and an invoice raised in March but paid in May produces a May
     * certificate.
     *
     * @throws WithholdingRefused
     */
    public function certify(
        string $entityId,
        string $partyId,
        WithholdingDeduction $deduction,
        CarbonInterface $paidOn,
        ?string $paymentReference = null,
    ): WithholdingCertificate {
        if ($deduction->isZero()) {
            throw WithholdingRefused::nothingToCertify();
        }

        $period = $paidOn->format('Y-m');

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw WithholdingRefused::notAMonth($period);
        }

        return DB::connection('tenant')->transaction(fn (): WithholdingCertificate => WithholdingCertificate::create([
            'entity_id' => $entityId,
            'number' => $this->numbers->next(
                $entityId,
                'withholding_certificate',
                'WHT',
                Carbon::parse($paidOn->toDateString()),
            ),
            'party_id' => $partyId,
            'jurisdiction' => $deduction->jurisdiction,
            'issued_on' => $paidOn->toDateString(),
            'period' => $period,
            'currency' => $deduction->base->currency,
            'base_amount' => (string) $deduction->base,
            'withheld_amount' => (string) $deduction->withheld,
            'rate' => $deduction->ratePercent,
            'payment_reference' => $paymentReference,
        ]));
    }

    /**
     * What was withheld in a period, per party and in total.
     *
     * Read from the certificates rather than from the ledger: the ledger holds
     * the posting, which is right for a trial balance and wrong for a return
     * that has to list the payees and their certificate numbers.
     *
     * Void certificates are excluded, because a voided certificate is one the
     * payee cannot claim against.
     *
     * @return array{total: Money, by_party: array<string, Money>, certificates: int}
     */
    public function totalsFor(string $entityId, string $period, string $currency): array
    {
        $certificates = WithholdingCertificate::query()
            ->where('entity_id', $entityId)
            ->where('period', $period)
            ->where('currency', $currency)
            ->where('status', 'issued')
            ->get();

        $total = Money::zero($currency);
        $byParty = [];

        foreach ($certificates as $certificate) {
            $total = $total->plus($certificate->withheld());

            $party = (string) $certificate->party_id;
            $byParty[$party] = ($byParty[$party] ?? Money::zero($currency))->plus($certificate->withheld());
        }

        return ['total' => $total, 'by_party' => $byParty, 'certificates' => $certificates->count()];
    }
}
