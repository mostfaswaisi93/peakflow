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
 * The posting a corrected invoice produces - PF-098.
 *
 * ## Both legs, one entry
 *
 *   Dr  the replaced invoice's revenue accounts    its net, reversed
 *   Dr  vat_output @ the jurisdiction              its tax, reversed
 *   Cr  accounts_receivable                        its gross, reversed
 *   Dr  accounts_receivable                        the corrected gross
 *   Cr  the corrected revenue accounts             the corrected net
 *   Cr  vat_output @ the jurisdiction              the corrected tax
 *
 * ## Why one entry rather than two
 *
 * Because it is one event. A replacement document says "this invoice is not
 * what it should have said; this is". Two entries - a reversal and a new
 * posting - read as two decisions taken at two moments, and anybody
 * reconciling the receivable has to find both and know they belong together.
 * One entry, posted by one rule, from one document, with both halves visible
 * in it.
 *
 * It also makes the arithmetic self-checking: the entry balances only if the
 * reversal and the restatement are each internally balanced, so a correction
 * that reverses 1,150 and posts 1,000 cannot be written at all - the ledger's
 * own invariant refuses it before anybody reads a report.
 *
 * ## Why the reversal is not `Reverser`
 *
 * `Reverser` writes the opposite of an entry and marks the original reversed,
 * which is right for a mistake in the books. This is not a mistake in the
 * books: the original invoice was posted correctly from the document that
 * existed at the time. What changed is the document.
 */
final class CorrectedInvoiceIssuedRule implements PostingRule
{
    public const KEY = 'sales.corrected_invoice_issued';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'ترحيل فاتورة مصححة بديلة',
            'The posting a replacement corrected invoice produces',
            [
                ['Dr', 'the replaced invoice\'s revenue and tax', 'its figures, reversed'],
                ['Cr', 'accounts_receivable', 'the replaced gross'],
                ['Dr', 'accounts_receivable', 'the corrected gross'],
                ['Cr', 'the corrected revenue and tax', 'the corrected figures'],
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
     *     replaced: array{
     *         gross: string,
     *         revenue: list<array{account_id: string, amount: string, tax_code_id?: string|null, tax_point?: string|null}>,
     *         tax: list<array{jurisdiction: string, amount: string, tax_code_id?: string|null, tax_point?: string|null}>,
     *     },
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $currency = $context->functionalCurrency;
        $party = (string) $event['party_id'];
        $narration = $event['narration'] ?? null;

        $gross = Money::of((string) $event['gross'], $currency);
        $replaced = $event['replaced'] ?? null;

        if ($replaced === null) {
            throw IssueRefused::aCorrectionOfNothing();
        }

        $replacedGross = Money::of((string) $replaced['gross'], $currency);

        if ($gross->isZero() && $replacedGross->isZero()) {
            throw IssueRefused::aDocumentOfNothing();
        }

        $lines = [];

        // The reversal of what the replaced document said.
        if (! $replacedGross->isZero()) {
            $lines[] = PostingLine::toRole(
                ControlRole::AccountsReceivable, $replacedGross->negated(), $party, $narration,
            );
        }

        foreach ($replaced['revenue'] as $revenue) {
            $amount = Money::of((string) $revenue['amount'], $currency);

            if ($amount->isZero()) {
                continue;
            }

            $lines[] = PostingLine::toAccount(
                accountId: (string) $revenue['account_id'],
                amount: $amount,
                partyId: $party,
                narration: $narration,
                taxCodeId: $revenue['tax_code_id'] ?? null,
                taxPoint: $revenue['tax_point'] ?? null,
            );
        }

        foreach ($replaced['tax'] as $tax) {
            $amount = Money::of((string) $tax['amount'], $currency);

            if ($amount->isZero()) {
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

        // And the restatement.
        if (! $gross->isZero()) {
            $lines[] = PostingLine::toRole(ControlRole::AccountsReceivable, $gross, $party, $narration);
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
