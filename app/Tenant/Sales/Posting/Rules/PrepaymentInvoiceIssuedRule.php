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
 * The posting a prepayment invoice produces - PF-099, IFRS 15.
 *
 * ## Dr/Cr
 *
 *   Dr  accounts_receivable            the gross the customer owes now
 *   Cr  deferred_revenue               the net taken before performance
 *   Cr  vat_output @ the jurisdiction  the tax, where the regime taxes it
 *
 * ## Why the credit is a liability and not revenue
 *
 * Because nothing has been performed. Money taken before a supply is an
 * obligation to make the supply or give the money back - a contract
 * liability under IFRS 15 - and crediting revenue instead overstates income
 * and the tax base at once, in a period where the work has not happened.
 *
 * It also cannot be fixed later by "the final invoice posts less revenue":
 * the overstatement is in a period that may be closed, reported and filed,
 * and the understatement is in a later one. Two wrong periods, netting to
 * right, which is the shape nobody notices until an auditor asks what the
 * revenue in Q1 was for.
 *
 * ## Why the tax leg is conditional and the liability is not
 *
 * The tax point is a jurisdiction's decision - `config/jurisdictions.php`,
 * `prepayment_tax` - and in most VAT regimes it is the earlier of payment
 * and invoice, so the prepayment carries the tax. Where a regime taxes only
 * at the final invoice, the prepayment document carries none and the event
 * arrives here with an empty tax block.
 *
 * The liability never depends on that. Whether the authority wants its money
 * now or later has nothing to do with whether the business owes the customer
 * a supply.
 */
final class PrepaymentInvoiceIssuedRule implements PostingRule
{
    public const KEY = 'sales.prepayment_invoice_issued';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'ترحيل فاتورة دفعة مقدمة',
            'The posting an issued prepayment invoice produces',
            [
                ['Dr', 'accounts_receivable', 'the gross the customer owes now'],
                ['Cr', 'deferred_revenue', 'the net taken before performance (IFRS 15)'],
                ['Cr', 'vat_output (per jurisdiction)', 'the tax, where the regime taxes it'],
            ],
        );
    }

    /**
     * @param  array{
     *     invoice_id: string,
     *     party_id: string,
     *     gross: string,
     *     net?: string,
     *     narration?: string|null,
     *     tax: list<array{jurisdiction: string, amount: string, tax_code_id?: string|null, tax_point?: string|null}>,
     *     revenue: list<array{amount: string, tax_code_id?: string|null, tax_point?: string|null}>,
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $currency = $context->functionalCurrency;
        $party = (string) $event['party_id'];
        $narration = $event['narration'] ?? null;

        $gross = Money::of((string) $event['gross'], $currency);

        if ($gross->isZero()) {
            throw IssueRefused::aDocumentOfNothing();
        }

        $lines = [
            PostingLine::toRole(ControlRole::AccountsReceivable, $gross, $party, $narration),
        ];

        /*
         | The liability carries each line's tax code and tax point.
         |
         | Because the prepayment IS a taxable event where the regime says
         | so, and a return assembled from codes needs the taxable amount to
         | have a code on it - otherwise the box shows tax with nothing
         | behind it, which is PF-086's complaint about every posting that
         | forgets this.
         */
        foreach ($event['revenue'] as $line) {
            $amount = Money::of((string) $line['amount'], $currency);

            if ($amount->isZero()) {
                continue;
            }

            $lines[] = PostingLine::toRole(
                ControlRole::DeferredRevenue,
                $amount->negated(),
                $party,
                $narration,
                $line['tax_code_id'] ?? null,
                $line['tax_point'] ?? null,
            );
        }

        foreach ($event['tax'] as $tax) {
            $amount = Money::of((string) $tax['amount'], $currency);

            if ($amount->isZero()) {
                continue;
            }

            $lines[] = PostingLine::toRoleIn(
                ControlRole::VatOutput,
                (string) $tax['jurisdiction'],
                $amount->negated(),
                $party,
                $narration,
                $tax['tax_code_id'] ?? null,
                $tax['tax_point'] ?? null,
            );
        }

        return $lines;
    }
}
