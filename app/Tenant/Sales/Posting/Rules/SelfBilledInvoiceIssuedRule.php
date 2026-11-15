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
 * The posting a self-billed invoice produces - PF-100.
 *
 * ## Dr/Cr
 *
 *   Dr  the line's expense account    each line's net
 *   Dr  vat_input @ the jurisdiction  each rate group's tax
 *   Cr  accounts_payable              the gross owed to the supplier
 *
 * ## The part that is easy to get backwards
 *
 * This document is issued by this system and it is **not a sale**. Self-
 * billing is the buyer writing the supplier's invoice on their behalf under
 * an agreement, so what it records is a purchase: money owed to a supplier,
 * input tax to reclaim, and an expense.
 *
 * Posting it as a sale is the kind of mistake that looks right in every
 * individual figure. The receivable rises instead of the payable, so the
 * supplier appears as a customer who owes money; the tax lands in output
 * instead of input, so the return declares tax on a purchase and claims
 * nothing; and revenue rises by the cost of the goods. Every one of those is
 * a defensible-looking number, the entry balances, and the trial balance
 * adds up. What is wrong is the direction of the whole document.
 *
 * ## Why the line's account is checked rather than assumed
 *
 * `invoice_lines.revenue_account_id` holds the account a line posts to, and
 * on a purchase-side document that is an expense account. The name is
 * historical and the ambiguity is real, so `InvoiceValidator` asserts the
 * account's TYPE matches the document's side: a `389` whose lines point at
 * revenue accounts is refused rather than posted the wrong way round.
 */
final class SelfBilledInvoiceIssuedRule implements PostingRule
{
    public const KEY = 'sales.self_billed_invoice_issued';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'ترحيل فاتورة ذاتية صادرة عن المشتري',
            'The posting a self-billed invoice produces - a PURCHASE, not a sale',
            [
                ['Dr', 'the line\'s expense account', 'each line\'s net'],
                ['Dr', 'vat_input (per jurisdiction)', 'each rate group\'s tax'],
                ['Cr', 'accounts_payable', 'the gross owed to the supplier'],
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
            PostingLine::toRole(ControlRole::AccountsPayable, $gross->negated(), $party, $narration),
        ];

        foreach ($event['revenue'] as $line) {
            $account = (string) ($line['account_id'] ?? '');

            if ($account === '') {
                throw IssueRefused::aLineWithNoExpenseAccount();
            }

            $lines[] = PostingLine::toAccount(
                accountId: $account,
                amount: Money::of((string) $line['amount'], $currency),
                partyId: $party,
                narration: $narration,
                taxCodeId: $line['tax_code_id'] ?? null,
                dimensions: $line['dimensions'] ?? [],
                taxPoint: $line['tax_point'] ?? null,
            );
        }

        foreach ($event['tax'] as $tax) {
            $amount = Money::of((string) $tax['amount'], $currency);

            if ($amount->isZero()) {
                continue;
            }

            /*
             | INPUT tax, which is the whole point.
             |
             | The tax on a purchase is reclaimable. Posting it to output
             | declares tax the business does not owe and claims nothing
             | back, and the two errors are the same size in opposite
             | directions - so the net due is wrong by twice the tax and both
             | boxes of the return are wrong.
             */
            $lines[] = PostingLine::toRoleIn(
                ControlRole::VatInput,
                (string) $tax['jurisdiction'],
                $amount,
                $party,
                $narration,
                $tax['tax_code_id'] ?? null,
                $tax['tax_point'] ?? null,
            );
        }

        return $lines;
    }
}
