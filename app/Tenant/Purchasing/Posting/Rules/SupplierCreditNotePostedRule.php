<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Posting\Rules;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;
use App\Tenant\Purchasing\Exceptions\BillRefused;

/**
 * The posting a supplier's credit note produces - PF-134.
 *
 * ## Dr/Cr - the mirror of {@see BillPostedRule}
 *
 * ```
 *   Dr  accounts_payable                       the gross no longer owed
 *   Cr  the line's expense or asset account    each line's net, plus its non-recoverable tax
 *   Cr  vat_input @ the jurisdiction           the recoverable tax, given back
 * ```
 *
 * And under the reverse charge, additionally:
 *
 * ```
 *   Cr  vat_input @ the jurisdiction           the self-assessed input, reversed
 *   Dr  vat_output @ the jurisdiction          and the output with it
 * ```
 *
 * ## A rule of its own, not `BillPostedRule` with the signs flipped
 *
 * Which is PF-134's fourth criterion - "reverses through a posting rule, not
 * by negating rows" - and the reason is that the two documents are not
 * symmetrical in the one place it matters: **a credit note can be for less
 * than the bill**. A negation of the original's rows credits everything the
 * original debited, in the original's proportions, and a partial credit is
 * not in those proportions. A supplier who credits one line of a four-line
 * invoice credits one expense account, and the tax on that line only.
 *
 * Reversing the stored *entry* has the same problem from the other side: the
 * ledger's reversal machinery exists for correcting an entry that should not
 * have been made, and this entry should have been made. The credit note is a
 * second real event, with its own date, its own period and its own number
 * from the supplier.
 *
 * ## The amounts are positive on the document and negative here
 *
 * The credit note carries positive figures, because that is what the
 * supplier's document says and because a query for "what do we owe" must be
 * a filter on `document_type` rather than a sum that somebody can forget to
 * write. The sign is this rule's business.
 */
final class SupplierCreditNotePostedRule implements PostingRule
{
    public const KEY = 'purchasing.supplier_credit_note_posted';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'ترحيل إشعار دائن من مورّد',
            'The posting a supplier credit note produces',
            [
                ['Dr', 'accounts_payable', 'the gross no longer owed'],
                ['Cr', 'the line\'s expense or asset account', 'each line\'s net, plus its non-recoverable tax'],
                ['Cr', 'vat_input (per jurisdiction)', 'the recoverable tax, given back'],
                ['Dr', 'vat_output (per jurisdiction)', 'the reverse-charge output, reversed with its input'],
            ],
        );
    }

    /**
     * @param  array{
     *     bill_id: string,
     *     party_id: string,
     *     payable: string,
     *     narration?: string|null,
     *     expense: list<array{account_id: string, amount: string, tax_code_id?: string|null, tax_point?: string|null, dimensions?: array<int, string|null>}>,
     *     recoverable_tax: list<array{jurisdiction: string, amount: string, tax_code_id?: string|null, tax_point?: string|null}>,
     *     reverse_charge: list<array{jurisdiction: string, amount: string, tax_code_id?: string|null, tax_point?: string|null}>,
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $currency = $context->functionalCurrency;
        $party = (string) $event['party_id'];
        $narration = $event['narration'] ?? null;

        $payable = Money::of((string) $event['payable'], $currency);

        if ($payable->isZero() && ($event['reverse_charge'] ?? []) === []) {
            throw BillRefused::aDocumentOfNothing();
        }

        $lines = [];

        foreach ($event['expense'] as $expense) {
            $account = (string) ($expense['account_id'] ?? '');

            if ($account === '') {
                throw BillRefused::aLineWithNoAccount();
            }

            $lines[] = PostingLine::toAccount(
                accountId: $account,
                amount: Money::of((string) $expense['amount'], $currency)->negated(),
                partyId: $party,
                narration: $narration,
                taxCodeId: $expense['tax_code_id'] ?? null,
                dimensions: $expense['dimensions'] ?? [],
                taxPoint: $expense['tax_point'] ?? null,
            );
        }

        foreach ($event['recoverable_tax'] as $tax) {
            $amount = Money::of((string) $tax['amount'], $currency);

            if ($amount->isZero()) {
                continue;
            }

            $lines[] = PostingLine::toRoleIn(
                ControlRole::VatInput,
                (string) $tax['jurisdiction'],
                $amount->negated(),
                $party,
                $narration,
                $tax['tax_code_id'] ?? null,
                $tax['tax_point'] ?? null,
            );
        }

        /*
         | The reverse charge, both sides, reversed.
         |
         | A credit note against a reverse-charge purchase reduces the input
         | AND the output. Reversing only the input would leave the return
         | declaring output tax on a supply that was credited, which is the
         | same defect as the original rule's in the opposite direction - and
         | it is just as invisible in the accounts, because the two sides
         | still net to nothing.
         */
        foreach ($event['reverse_charge'] ?? [] as $tax) {
            $amount = Money::of((string) $tax['amount'], $currency);

            if ($amount->isZero()) {
                continue;
            }

            $lines[] = PostingLine::toRoleIn(
                ControlRole::VatInput,
                (string) $tax['jurisdiction'],
                $amount->negated(),
                $party,
                $narration,
                $tax['tax_code_id'] ?? null,
                $tax['tax_point'] ?? null,
            );

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

        if (! $payable->isZero()) {
            $lines[] = PostingLine::toRole(
                ControlRole::AccountsPayable,
                $payable,
                $party,
                $narration,
            );
        }

        return $lines;
    }
}
