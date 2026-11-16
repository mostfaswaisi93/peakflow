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
 * The posting an expense claim produces - PF-143.
 *
 * ## Dr/Cr
 *
 * ```
 *   Dr  the line's expense account              each line's net, plus its non-recoverable tax
 *   Dr  vat_input @ the jurisdiction            the recoverable tax
 *   Cr  employee_payable                        the gross owed to the claimant
 * ```
 *
 * ## The credit is to `employee_payable`, not `accounts_payable`
 *
 * An employee is not a supplier. The balance is reported differently in most
 * frameworks, it is frequently settled through payroll rather than through
 * the payment run, and an aging report that mixed the two would show a
 * colleague's taxi fare beside a supplier whose terms are thirty days.
 *
 * It matters for the fraud this module is about as well: "who do we owe money
 * to" with employees mixed into it is a list nobody reads closely, and an
 * expense claim is the cheapest place in a business to take money out of it.
 *
 * ## The tax split is the line's own, and it was decided at entry
 *
 * Recoverable tax reaches the input account; the rest rides with the line's
 * expense. The percentage is on the line because the decision was made when
 * the claim was entered - against the policy of the day and the receipt that
 * was attached - and re-deriving it here would answer with today's policy,
 * which is a different question from the one the return already answered.
 */
final class ExpenseClaimPostedRule implements PostingRule
{
    public const KEY = 'purchasing.expense_claim_posted';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'ترحيل مطالبة مصروفات',
            'The posting an employee expense claim produces',
            [
                ['Dr', 'the line\'s expense account', 'each line\'s net, plus its non-recoverable tax'],
                ['Dr', 'vat_input (per jurisdiction)', 'the recoverable tax - only where a receipt supports it'],
                ['Cr', 'employee_payable', 'the gross owed to the claimant, in a control account of its own'],
            ],
        );
    }

    /**
     * @param  array{
     *     claim_id: string,
     *     claimant_user_id: string,
     *     payable: string,
     *     narration?: string|null,
     *     expense: list<array{account_id: string, amount: string, tax_code_id?: string|null, tax_point?: string|null, dimensions?: array<int, string|null>}>,
     *     recoverable_tax: list<array{jurisdiction: string, amount: string, tax_code_id?: string|null, tax_point?: string|null}>,
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $currency = $context->functionalCurrency;
        $narration = $event['narration'] ?? null;

        $payable = Money::of((string) $event['payable'], $currency);

        if ($payable->isZero()) {
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
                /*
                 | No party on an expense line.
                 |
                 | The claimant is a user and not a party, so there is no
                 | party id to carry - and inventing one would put a
                 | colleague in the list every party-scoped control reads,
                 | the supplier bank-detail rule included.
                 */
                partyId: null,
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
                $amount,
                null,
                $narration,
                $tax['tax_code_id'] ?? null,
                $tax['tax_point'] ?? null,
            );
        }

        $lines[] = PostingLine::toRole(
            ControlRole::EmployeePayable,
            $payable->negated(),
            null,
            $narration,
        );

        return $lines;
    }
}
