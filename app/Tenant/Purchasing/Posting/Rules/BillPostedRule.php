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
 * The posting a bill produces - PF-133.
 *
 * ## Dr/Cr
 *
 * ```
 *   Dr  the line's expense or asset account    each line's net
 *   Dr  vat_input @ the jurisdiction           the RECOVERABLE tax
 *   Dr  the line's expense account             the NON-recoverable tax
 *   Cr  accounts_payable                       the gross the supplier is owed
 * ```
 *
 * And under the reverse charge, additionally:
 *
 * ```
 *   Dr  vat_input @ the jurisdiction           the self-assessed tax
 *   Cr  vat_output @ the jurisdiction          the same amount
 * ```
 *
 * ## The reverse charge posts BOTH sides, and this is the half most systems
 * forget
 *
 * Under the reverse charge the supplier charges no tax and the recipient
 * accounts for it as if they had charged themselves. That means **two**
 * postings of the same amount: an output, because the recipient owes the tax,
 * and an input, because they are also entitled to claim it.
 *
 * A system that posts only the input side produces a return whose input box
 * is right and whose output box understates the liability by exactly the
 * reverse-charge tax - and because the two sides net to nothing in the
 * accounts, nothing looks wrong until the authority compares the return
 * against the supplier's own declaration.
 *
 * It also means the reverse-charge tax is **not** part of the payable. The
 * supplier is owed the net; the tax is owed to the authority and claimed
 * back from them in the same breath.
 *
 * ## Non-recoverable tax goes to expense
 *
 * Not to the input account. Tax that will never be claimed sitting in the
 * input account is tax that will not tie to any box on any form, and the
 * difference is found during an audit by somebody who did not put it there.
 * The expense it belongs to is the line's own, because the whole point is
 * that the cost of the purchase includes tax that cannot be recovered.
 */
final class BillPostedRule implements PostingRule
{
    public const KEY = 'purchasing.bill_posted';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'ترحيل فاتورة مورّد',
            'The posting a supplier bill produces',
            [
                ['Dr', 'the line\'s expense or asset account', 'each line\'s net, plus its non-recoverable tax'],
                ['Dr', 'vat_input (per jurisdiction)', 'the recoverable tax, and the reverse-charge input'],
                ['Cr', 'vat_output (per jurisdiction)', 'the reverse-charge output - the half most systems forget'],
                ['Cr', 'accounts_payable', 'the gross the supplier is owed'],
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
                amount: Money::of((string) $expense['amount'], $currency),
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
                // A zero-rated or exempt group claims nothing, and a zero
                // line is refused by the ledger - PF-041.
                continue;
            }

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

        /*
         | The reverse charge, both sides.
         |
         | The input because the recipient may claim it, the output because
         | the recipient owes it. Equal and opposite in the accounts, and
         | two different boxes on the return - which is why they cannot be
         | netted here even though the entry would still balance.
         */
        foreach ($event['reverse_charge'] ?? [] as $tax) {
            $amount = Money::of((string) $tax['amount'], $currency);

            if ($amount->isZero()) {
                continue;
            }

            $lines[] = PostingLine::toRoleIn(
                ControlRole::VatInput,
                (string) $tax['jurisdiction'],
                $amount,
                $party,
                $narration,
                $tax['tax_code_id'] ?? null,
                $tax['tax_point'] ?? null,
            );

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

        if (! $payable->isZero()) {
            $lines[] = PostingLine::toRole(
                ControlRole::AccountsPayable,
                $payable->negated(),
                $party,
                $narration,
            );
        }

        return $lines;
    }
}
