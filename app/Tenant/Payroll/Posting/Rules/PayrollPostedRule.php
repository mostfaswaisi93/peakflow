<?php

declare(strict_types=1);

namespace App\Tenant\Payroll\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * A payroll run, in one entry - PF-256.
 *
 * ## Dr/Cr
 *
 *   Dr  salaries and wages            (the GROSS)
 *   Dr  employer contributions        (what we owe on top)
 *     Cr  employee payables           (the net, which goes out as bills)
 *     Cr  payroll tax withheld        (held for an authority)
 *     Cr  social insurance payable    (both shares, one transfer)
 *
 * ## Why the gross and not the net
 *
 * A single net figure hides the liability the business still owes. Post
 * the net as the cost and the salary line moves whenever a tax rate
 * changes, with a liability for the difference appearing from nowhere -
 * and "what do our people cost" becomes a question about tax policy.
 *
 * ## Why the employer's contribution is its own debit
 *
 * The employee never sees it, it is set by a rate the business does not
 * choose, and most frameworks disclose it separately. Folding it into
 * salaries makes "what does an employee cost us" right and "what did we
 * pay them" wrong - and the second is the figure in every conversation
 * with the employee.
 *
 * ## Why the net credits `employee_payable` and not `accounts_payable`
 *
 * An employee is not a supplier. The balance is reported differently in
 * most frameworks, and a creditors list with employees mixed into it is
 * a list nobody reads closely - which is the cheapest place in a
 * business to take money out of.
 *
 * The net then goes out through PF-174's payment run as ordinary bills,
 * with maker-checker and beneficiary verification, because a separate
 * path for payroll is a separate path ROUND the controls.
 */
final class PayrollPostedRule implements PostingRule
{
    public const KEY = 'payroll.run_posted';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'ترحيل مسيّر رواتب',
            'Payroll run posted',
            [
                ['Dr', ControlRole::SalaryExpense, 'the gross, because a net figure hides what is owed'],
                ['Dr', ControlRole::EmployerContributionExpense, 'what the employer owes on top of the pay'],
                ['Cr', ControlRole::EmployeePayable, 'the net, which goes out as bills through the payment run'],
                ['Cr', ControlRole::PayrollTaxPayable, 'tax deducted and not yet handed over'],
                ['Cr', ControlRole::SocialInsurancePayable, 'both shares, paid in one transfer'],
            ],
        );
    }

    /**
     * @param  array{gross: string, employer: string, net: string, payroll_tax: string, social_insurance: string, narration?: string}  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $narration = (string) ($event['narration'] ?? 'Payroll run');

        $gross = $context->money((string) $event['gross']);
        $employer = $context->money((string) $event['employer']);
        $net = $context->money((string) $event['net']);
        $tax = $context->money((string) $event['payroll_tax']);
        $insurance = $context->money((string) $event['social_insurance']);

        $lines = [
            PostingLine::toRole(ControlRole::SalaryExpense, $gross, narration: $narration),
            PostingLine::toRole(ControlRole::EmployeePayable, $net->negated(), narration: $narration),
        ];

        /*
         | The optional legs are omitted when they are zero, not posted
         | as zero.
         |
         | A business with no payroll tax - which is most of the Gulf for
         | its nationals - should not have a zero line in every payroll
         | entry. A ledger full of zero lines is one where the lines that
         | matter are harder to see, and the account itself would appear
         | in reports that list accounts with movement.
         */
        foreach ([
            [$employer, ControlRole::EmployerContributionExpense, false],
            [$tax, ControlRole::PayrollTaxPayable, true],
            [$insurance, ControlRole::SocialInsurancePayable, true],
        ] as [$amount, $role, $credit]) {
            if ($amount->isZero()) {
                continue;
            }

            $lines[] = PostingLine::toRole(
                $role,
                $credit ? $amount->negated() : $amount,
                narration: $narration,
            );
        }

        return $lines;
    }
}
