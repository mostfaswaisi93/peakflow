<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * Cash claimed by a document - PF-168.
 *
 * ## Dr/Cr
 *
 *   Dr  unapplied cash, releasing what was held for the payer
 *   Cr  accounts receivable, against the party
 *
 * and the reverse for a disbursement against a bill.
 *
 * ## This is where a party balance moves, and the only place
 *
 * The receipt put money in a clearing position; this is somebody saying
 * which document it was for. Those two acts are separate because they are
 * separate facts, and keeping them separate is what makes a customer
 * ledger something you can take apart: every movement on the balance
 * names the document it belongs to.
 *
 * ## The party is on both the clearing line and the receivable line
 *
 * On receivables because that is what makes the subledger agree with the
 * control account. On unapplied cash because "whose money is still
 * unallocated" is the question the ageing report answers - and an
 * allocation that released the clearing position without naming the party
 * would make that report drift from the first partial allocation onwards.
 */
final class AllocationPostedRule implements PostingRule
{
    public const KEY = 'settlement.allocation_posted';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'تخصيص مقبوضات على مستند',
            'A payment allocated to a document',
            [
                ['Dr', ControlRole::UnappliedCash, 'releasing what was held for the payer, on a receipt'],
                ['Cr', ControlRole::AccountsReceivable, 'the part of the document this receipt settles, against the party'],
                ['Dr', ControlRole::AccountsPayable, 'the part of the bill this payment settles, against the party - a payable is CLOSED by a debit'],
                ['Cr', ControlRole::UnappliedCash, 'releasing what was held for the payee, on a disbursement'],
            ],
        );
    }

    /**
     * @param  array{
     *     amount: string,
     *     currency?: string,
     *     payment_rate?: \App\Support\Money\Rate|null,
     *     document_rate?: \App\Support\Money\Rate|null,
     *     party_id?: string|null,
     *     payable?: bool,
     *     narration?: string,
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $narration = (string) ($event['narration'] ?? 'Allocation');
        $partyId = isset($event['party_id']) ? (string) $event['party_id'] : null;
        $currency = (string) ($event['currency'] ?? $context->functionalCurrency);

        /*
         | A bill settles the payable side, and it settles it the OTHER WAY
         | round - PF-281.
         |
         | This used to read "same shape, other control account", and swap
         | only the role. It is not the same shape. A customer receipt
         | releases cash held for the payer and relieves a receivable: Dr
         | clearing, Cr the control. A supplier payment is the mirror - the
         | money left the bank, so the clearing position is released the
         | other way and the liability is CLOSED, which is a debit.
         |
         | Swapping the role alone credits accounts payable to pay a bill,
         | so the entry still balances, every row is individually valid,
         | and the company appears to owe MORE after paying. In the showcase
         | fixture it put 524,860 into a clearing account that must wash to
         | zero - twice the 262,430 that had been paid, once for the sign on
         | each leg - and overstated the payables control by the same.
         |
         | Nothing caught it because nothing posted an allocation against a
         | bill and then looked at the two accounts. `ledger:verify` found it
         | the first time a fixture paid a supplier.
         */
        $settlesAPayable = ($event['payable'] ?? false) === true;

        $control = $settlesAPayable
            ? ControlRole::AccountsPayable
            : ControlRole::AccountsReceivable;

        if ($currency === $context->functionalCurrency) {
            $amount = $context->money((string) $event['amount']);

            $clearing = $settlesAPayable ? $amount->negated() : $amount;
            $relief = $settlesAPayable ? $amount : $amount->negated();

            return [
                PostingLine::toRole(
                    ControlRole::UnappliedCash,
                    $clearing,
                    partyId: $partyId,
                    narration: $narration,
                ),
                PostingLine::toRole(
                    $control,
                    $relief,
                    partyId: $partyId,
                    narration: $narration,
                ),
            ];
        }

        /*
         | Two rates, and the difference between them is realized - PF-170.
         |
         | The clearing position is released at the rate the money arrived
         | at, because that is what was credited when it arrived. The
         | receivable is relieved at the rate the document was booked at,
         | because that is what was debited when it was issued. Neither
         | figure is a choice: each line reverses exactly what it reverses.
         |
         | What is left over is the gain or loss, and it is left over by
         | construction rather than computed - which is why it cannot be
         | out. A rule that posted both sides at one rate would balance
         | and would leave either the clearing position or the receivable
         | holding a residue nobody can explain, in a currency nobody is
         | owed.
         */
        $payment = $event['payment_rate'] ?? null;
        $document = $event['document_rate'] ?? null;

        if (! $payment instanceof Rate || ! $document instanceof Rate) {
            throw \App\Tenant\Settlement\Exceptions\PaymentRefused::because(sprintf(
                'An allocation in %s needs both rates: the one the document was booked at and the '.
                'one the money arrived at. The difference between them is a realized gain or loss, '.
                'and without both there is nothing to compute it from.',
                $currency,
            ));
        }

        $original = Money::of((string) $event['amount'], $currency);
        $policy = RoundingPolicy::halfUp(Scale::Amount);

        // The same mirroring as the functional-currency branch above: for a
        // payable the clearing position is released the other way and the
        // liability is closed by a debit.
        $clearing = PostingLine::translatedToRole(
            role: ControlRole::UnappliedCash,
            original: $settlesAPayable ? $original->negated() : $original,
            rate: $payment,
            policy: $policy,
            partyId: $partyId,
            narration: $narration,
        );

        $relief = PostingLine::translatedToRole(
            role: $control,
            original: $settlesAPayable ? $original : $original->negated(),
            rate: $document,
            policy: $policy,
            partyId: $partyId,
            narration: $narration,
        );

        $realized = $clearing->amount->plus($relief->amount);

        if ($realized->isZero()) {
            return [$clearing, $relief];
        }

        return [
            $clearing,
            $relief,
            /*
             | Realized, so `fx_gain_loss` - never `fx_revaluation`.
             |
             | The money arrived; this difference is settled and will not
             | move again. The revaluation account holds what is still
             | open and still moving, and keeping them apart is what stops
             | the two being added together by a report that cannot tell
             | the difference.
             */
            PostingLine::toRole(
                ControlRole::FxGainLoss,
                $realized->negated(),
                narration: sprintf(
                    'Realized exchange difference on %s: booked at %s, settled at %s',
                    (string) $original->toPresentation(),
                    (string) $document->value,
                    (string) $payment->value,
                ),
            ),
        ];
    }
}
