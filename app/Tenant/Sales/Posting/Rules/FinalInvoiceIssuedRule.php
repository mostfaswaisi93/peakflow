<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Posting\Rules;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;
use App\Tenant\Sales\Exceptions\IssueRefused;

/**
 * The posting a final invoice produces when a prepayment is applied - PF-099.
 *
 * ## Dr/Cr
 *
 *   Dr  accounts_receivable            the gross LESS what was prepaid
 *   Dr  deferred_revenue               the prepayment's net, released
 *   Cr  the line's revenue account     the whole supply's net
 *   Cr  vat_output @ the jurisdiction  the tax not already charged
 *
 * ## Why the receivable is only the balance
 *
 * Because the prepayment invoice already made its own gross receivable, and
 * the customer has probably already paid it. Posting the full gross again
 * would show the customer owing for the same supply twice: once on the
 * prepayment, once here - and the aging report would chase them for it.
 *
 * ## Why the revenue is the WHOLE supply
 *
 * This is the moment performance happens, which is the moment IFRS 15 calls
 * revenue. The prepayment's net was a liability until now; it is released by
 * the debit to `deferred_revenue`, and the full value of the supply is
 * credited to revenue in one place. Splitting revenue between the two
 * documents would make the prepayment look like a sale and leave anybody
 * reading the revenue account unable to say what was earned when.
 *
 * ## Why the tax is only the increment
 *
 * Where the regime taxed the prepayment, that tax was already declared and
 * the customer already claimed it. Charging it again would declare it twice
 * and leave the customer holding two input claims for one supply - which the
 * authority's matching WILL find, because it is looking at both sides.
 *
 * ## The entry balances by construction
 *
 * `(gross - prepaid) + prepaid_net = net + (tax - prepaid_tax)`, because
 * `gross = net + tax` and `prepaid = prepaid_net + prepaid_tax`. If the
 * figures handed in do not satisfy that, the ledger's own invariant refuses
 * the entry rather than posting something that nets out somewhere else.
 */
final class FinalInvoiceIssuedRule implements PostingRule
{
    public const KEY = 'sales.final_invoice_issued';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'ترحيل فاتورة نهائية بعد دفعة مقدمة',
            'The posting a final invoice produces when a prepayment is applied',
            [
                ['Dr', 'accounts_receivable', 'the gross less what was prepaid'],
                ['Dr', 'deferred_revenue', 'the prepayment\'s net, released into revenue'],
                ['Cr', 'the line\'s revenue account', 'the whole supply\'s net'],
                ['Cr', 'vat_output (per jurisdiction)', 'the tax not already charged'],
            ],
        );
    }

    /**
     * @param  array{
     *     invoice_id: string,
     *     party_id: string,
     *     gross: string,
     *     narration?: string|null,
     *     revenue: list<array{account_id: string, amount: string, tax_code_id?: string|null, tax_point?: string|null, dimensions?: array<int, string|null>}>,
     *     tax: list<array{jurisdiction: string, amount: string, tax_code_id?: string|null, tax_point?: string|null}>,
     *     prepayment: array{gross: string, net: string, tax: string, jurisdiction: string, tax_code_id?: string|null, tax_point?: string|null},
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $currency = $context->functionalCurrency;
        $party = (string) $event['party_id'];
        $narration = $event['narration'] ?? null;

        $prepayment = $event['prepayment'] ?? null;

        if ($prepayment === null) {
            throw IssueRefused::aFinalInvoiceWithNoPrepayment();
        }

        $gross = Money::of((string) $event['gross'], $currency);
        $prepaidGross = Money::of((string) $prepayment['gross'], $currency);
        $prepaidNet = Money::of((string) $prepayment['net'], $currency);
        $prepaidTax = Money::of((string) $prepayment['tax'], $currency);

        if ($gross->isZero()) {
            throw IssueRefused::aDocumentOfNothing();
        }

        if ($prepaidGross->isGreaterThan($gross)) {
            throw IssueRefused::aPrepaymentLargerThanTheInvoice(
                (string) $prepaidGross->amount,
                (string) $gross->amount,
            );
        }

        $lines = [];
        $balance = $gross->minus($prepaidGross);

        /*
         | A zero balance posts no receivable line.
         |
         | An invoice settled entirely by its prepayment leaves nothing owed,
         | and `journal_lines_amount_not_zero` refuses a zero line - PF-041.
         | The entry still balances: the liability released equals the
         | revenue recognised.
         */
        if (! $balance->isZero()) {
            $lines[] = PostingLine::toRole(ControlRole::AccountsReceivable, $balance, $party, $narration);
        }

        if (! $prepaidNet->isZero()) {
            $lines[] = PostingLine::toRole(
                ControlRole::DeferredRevenue,
                $prepaidNet,
                $party,
                $narration,
                $prepayment['tax_code_id'] ?? null,

                /*
                 | The PREPAYMENT's tax point on the release.
                 |
                 | The liability was created with that tax point and the
                 | return it was reported in used it. Releasing it under
                 | today's date would make the reconciliation between the
                 | control account and the returns differ by the prepayment,
                 | in both periods - PF-088's "explained by timing" would
                 | have to explain something that is not timing.
                 */
                $prepayment['tax_point'] ?? null,
            );
        }

        foreach ($event['revenue'] as $revenue) {
            $account = (string) ($revenue['account_id'] ?? '');

            if ($account === '') {
                throw IssueRefused::aLineWithNoRevenueAccount();
            }

            $amount = Money::of((string) $revenue['amount'], $currency);

            if ($amount->isZero()) {
                continue;
            }

            $lines[] = PostingLine::toAccount(
                accountId: $account,
                amount: $amount->negated(),
                partyId: $party,
                narration: $narration,
                taxCodeId: $revenue['tax_code_id'] ?? null,
                dimensions: $revenue['dimensions'] ?? [],
                taxPoint: $revenue['tax_point'] ?? null,
            );
        }

        /*
         | The tax already charged on the prepayment is deducted from the
         | group it was charged in.
         |
         | Which is the group with the same jurisdiction: a prepayment taxed
         | at 15% in Saudi Arabia reduces the Saudi 15% leg, and if the final
         | invoice's lines turned out to be in another jurisdiction the
         | deduction has nowhere to go - so it is refused rather than netted
         | against a tax the authority never saw.
         */
        $remaining = $prepaidTax;

        foreach ($event['tax'] as $tax) {
            $amount = Money::of((string) $tax['amount'], $currency);
            $jurisdiction = (string) $tax['jurisdiction'];

            if ($jurisdiction === (string) $prepayment['jurisdiction'] && ! $remaining->isZero()) {
                $deducted = $remaining->isGreaterThan($amount) ? $amount : $remaining;
                $amount = $amount->minus($deducted);
                $remaining = $remaining->minus($deducted);
            }

            if ($amount->isZero()) {
                continue;
            }

            $lines[] = PostingLine::toRoleIn(
                ControlRole::VatOutput,
                $jurisdiction,
                $amount->negated(),
                $party,
                $narration,
                $tax['tax_code_id'] ?? null,
                $tax['tax_point'] ?? null,
            );
        }

        if (! $remaining->isZero()) {
            throw IssueRefused::aPrepaymentTaxedElsewhere(
                (string) $remaining->amount,
                (string) $prepayment['jurisdiction'],
            );
        }

        return $lines;
    }
}
