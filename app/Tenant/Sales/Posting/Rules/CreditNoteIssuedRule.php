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
 * The posting an issued credit note produces - PF-097.
 *
 * ## Dr/Cr
 *
 *   Dr  the line's revenue account     each credited line's net
 *   Dr  vat_output @ the jurisdiction   each rate group's tax
 *   Cr  accounts_receivable            the gross no longer owed
 *
 * The mirror of the invoice, and a rule of its own rather than the invoice
 * rule run with negative figures.
 *
 * ## Why not "reverse the original entry"
 *
 * Because a partial credit has no original entry to reverse. Crediting two
 * lines of a nine-line invoice is not the reversal of anything: it is a
 * posting whose shape happens to be the mirror of part of an earlier one.
 * A reversal, in this ledger, is `Reverser` - it takes an entry and writes
 * its opposite, which is right for a mistake and wrong for a credit note.
 *
 * The difference is visible in the books. A reversal says "that posting
 * should not have happened". A credit note says "this happened, and now this
 * other thing has happened too" - which is what an auditor reading the
 * receivable needs, because the invoice was correct when it was issued and
 * the goods really did come back.
 *
 * ## Why the tax leg goes back to `vat_output` and not to `vat_input`
 *
 * The tax on a credit note reduces output tax; it is not input tax. Posting
 * it as input would leave both figures overstated by the same amount, the
 * net due would be right, and both boxes on the return would be wrong - the
 * failure that reconciles perfectly and is reported incorrectly.
 */
final class CreditNoteIssuedRule implements PostingRule
{
    public const KEY = 'sales.credit_note_issued';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'ترحيل إشعار دائن صادر',
            'The posting an issued credit note produces',
            [
                ['Dr', 'the line\'s revenue account', 'each credited line\'s net'],
                ['Dr', 'vat_output (per jurisdiction)', 'each rate group\'s tax'],
                ['Cr', 'accounts_receivable', 'the gross no longer owed'],
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
            PostingLine::toRole(ControlRole::AccountsReceivable, $gross->negated(), $party, $narration),
        ];

        foreach ($event['revenue'] as $revenue) {
            $account = (string) ($revenue['account_id'] ?? '');

            if ($account === '') {
                throw IssueRefused::aLineWithNoRevenueAccount();
            }

            $lines[] = PostingLine::toAccount(
                accountId: $account,
                amount: Money::of((string) $revenue['amount'], $currency),
                partyId: $party,
                narration: $narration,
                taxCodeId: $revenue['tax_code_id'] ?? null,
                dimensions: $revenue['dimensions'] ?? [],
                taxPoint: $revenue['tax_point'] ?? null,
            );
        }

        foreach ($event['tax'] as $tax) {
            $amount = Money::of((string) $tax['amount'], $currency);

            if ($amount->isZero()) {
                // A zero-rated group posts nothing - PF-041 refuses a zero
                // line, and a zero line is not a posting.
                continue;
            }

            $lines[] = PostingLine::toRoleIn(
                ControlRole::VatOutput,
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
