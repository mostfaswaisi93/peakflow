<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Support\Money\Money;
use App\Support\Withholding\WithheldAmount;
use App\Support\Withholding\WithheldAtSettlement;
use App\Tenant\Tax\Exceptions\WithholdingRefused;
use App\Tenant\Tax\Posting\Rules\WithholdingAtSettlementRule;
use App\Tenant\Tenancy\Models\Entity;
use Carbon\CarbonInterface;

/**
 * Tax's answer to settlement's question - PF-083, PF-120.
 *
 * ## What this adds to `Withholding`
 *
 * Two things, and neither is logic.
 *
 * The **jurisdiction**: `Withholding::rateFor()` takes one, because a payer
 * registered in two withholds under the rules of the one the payment falls
 * under. Settlement has a payment and a party and no business knowing what
 * a jurisdiction is, so it is derived here from the entity's primary
 * registration on the settlement date - the same read `ReverseCharge` makes
 * for the same reason, and dated for the same reason: a registration that
 * lapsed in April was valid in March.
 *
 * The **`null` that is not a zero**: `rateFor()` already distinguishes them
 * and this keeps the distinction across the boundary rather than collapsing
 * it into a zero. A payer that withholds nothing because nobody configured
 * a rate is liable for what it should have withheld.
 *
 * Everything else is `Withholding`'s, unchanged: the specificity ordering
 * that makes a party's treaty rate beat an item's, the base the regime
 * chose, the rounding, the refusal above the base, and the certificate.
 */
final readonly class WithholdingAtSettlement implements WithheldAtSettlement
{
    public function __construct(private Withholding $withholding) {}

    public function on(
        string $entityId,
        string $partyId,
        Money $base,
        Money $tax,
        CarbonInterface $on,
        ?string $itemType = null,
    ): ?WithheldAmount {
        $jurisdiction = $this->jurisdictionOf($entityId, $on);

        if ($jurisdiction === null) {
            return null;
        }

        $rate = $this->withholding->rateFor($entityId, $jurisdiction, $on, $partyId, $itemType);

        if ($rate === null) {
            return null;
        }

        $deduction = $this->withholding->deduct($base, $tax, $rate);

        return new WithheldAmount(
            $deduction->base,
            $deduction->withheld,
            $deduction->ratePercent,
            $deduction->jurisdiction,
        );
    }

    public function certify(
        string $entityId,
        string $partyId,
        WithheldAmount $amount,
        CarbonInterface $settledOn,
        string $paymentReference,
    ): string {
        if ($amount->isZero()) {
            throw WithholdingRefused::nothingToCertify();
        }

        /*
         | Rebuilt as Tax's own value, rather than `certify()` taking ours.
         |
         | `certify()` writes the rate row's id and the source it was quoted
         | from onto the certificate, which is provenance an auditor reads
         | and the caller never had. Re-resolving the rate here would be a
         | second lookup that could disagree with the first; so the figures
         | come from the caller - they are what was POSTED - and the
         | provenance comes from the row behind them.
         */
        $rate = $this->withholding->rateFor(
            $entityId,
            $amount->jurisdiction,
            $settledOn,
            $partyId,
        );

        if ($rate === null) {
            throw WithholdingRefused::because(sprintf(
                'The %s rate that produced this deduction is no longer effective on %s, so a '.
                'certificate written now would cite a rate the authority cannot find. The '.
                'deduction stands; what is missing is the row it came from.',
                $amount->jurisdiction,
                $settledOn->toDateString(),
            ));
        }

        return (string) $this->withholding->certify(
            $entityId,
            $partyId,
            new \App\Tenant\Tax\WithholdingDeduction(
                $amount->base,
                $amount->withheld,
                $amount->ratePercent,
                $amount->jurisdiction,
                $rate->base,
                (string) $rate->getKey(),
                $rate->source,
            ),
            $settledOn,
            $paymentReference,
        )->number;
    }

    public function postingRuleKey(string $documentType): string
    {
        /*
         | One direction exists, and the other refuses rather than guessing.
         |
         | A bill settled short is a real case - the SUPPLIER is the payee
         | and we are the payer, so we withhold from them and owe the
         | authority - and its entry is the mirror: Dr payable, Cr
         | withholding_payable. That rule is PF-121's, and until it exists
         | the honest answer is a refusal. Returning the receivable rule for
         | a bill would relieve a payable into a receivable account and
         | balance while doing it.
         */
        if (\in_array($documentType, ['bill', 'supplier_credit'], true)) {
            throw WithholdingRefused::because(
                'Withholding on a bill we pay is the mirror entry - the payable relieved against '.
                'what we owe the authority - and it has no rule yet. Posting the receivable rule '.
                'here would relieve a payable into a receivable account, and the entry would '.
                'balance, so nothing would look wrong until the supplier compared their statement.',
            );
        }

        return WithholdingAtSettlementRule::KEY;
    }

    /** The jurisdiction the entity was registered under on that date. */
    private function jurisdictionOf(string $entityId, CarbonInterface $on): ?string
    {
        $entity = Entity::query()->find($entityId);

        if ($entity === null) {
            return null;
        }

        foreach ($entity->taxRegistrations as $registration) {
            if ($registration->is_primary !== true) {
                continue;
            }

            $from = (string) $registration->effective_from;
            $until = $registration->effective_to === null ? null : (string) $registration->effective_to;
            $date = $on->toDateString();

            if ($from <= $date && ($until === null || $until >= $date)) {
                return (string) $registration->jurisdiction;
            }
        }

        return null;
    }
}
