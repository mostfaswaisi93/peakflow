<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Closing;

use App\Support\Closing\ClosingCheck;
use App\Tenant\Banking\Models\BankAccount;
use Illuminate\Support\Facades\DB;

/**
 * A period does not close over an unreconciled bank account - PF-165.
 *
 * ## Why this is the last thing the close asks about
 *
 * Every other control in this phase protects one fact. This protects the
 * conclusion: that the cash figure in the accounts is the cash the
 * business has. A period closed without it is a period whose most
 * verifiable number was never verified - and cash is the one balance an
 * auditor can confirm independently, which is why it is the first one
 * they ask about.
 *
 * ## It asks for a statement covering the period, not for a tidy account
 *
 * The check is deliberately not "is the account reconciled today". An
 * account reconciled last week says nothing about March. What has to
 * exist is a **standing reconciliation whose statement period reaches the
 * end of the period being closed** - which is the document an auditor
 * would re-perform for that month.
 *
 * ## An account with no statements at all is not blocked
 *
 * A bank account opened mid-period with nothing on it yet has nothing to
 * reconcile, and refusing the close over it would be refusing it over an
 * absence of facts. An account that HAS statements in the period and no
 * reconciliation covering it is the case worth stopping, because that is
 * a month of movements nobody has checked against the bank.
 */
final class ReconciliationCheck implements ClosingCheck
{
    public function name(): string
    {
        return 'bank reconciliation';
    }

    /** @return list<string> */
    public function reasonsToRefuse(string $entityId, string $label, string $from, string $until): array
    {
        $accounts = BankAccount::query()
            ->where('entity_id', $entityId)
            /*
             | Dormant and closed accounts are not asked about.
             |
             | A closed account has no movements to reconcile, and a
             | dormant one is dormant precisely because nothing happens on
             | it - the statement test below would pass it anyway, and
             | naming them here says so rather than leaving it to a count.
             */
            ->where('status', 'active')
            ->get();

        $reasons = [];

        foreach ($accounts as $account) {
            $statements = DB::connection('tenant')->table('bank_statements')
                ->where('bank_account_id', (string) $account->getKey())
                ->whereNull('superseded_at')
                ->where('period_from', '<', $until)
                ->where('period_to', '>=', $from)
                ->count();

            if ($statements === 0) {
                /*
                 | No statements in the period: nothing to reconcile.
                 |
                 | An account opened mid-period with nothing on it yet.
                 | Refusing a close over an absence of facts would make
                 | the control the thing people route around.
                 */
                continue;
            }

            $reconciled = DB::connection('tenant')->table('reconciliations as r')
                ->join('bank_statements as s', 's.id', '=', 'r.bank_statement_id')
                ->where('r.bank_account_id', (string) $account->getKey())
                ->whereNull('r.undone_at')
                /*
                 | Reaching the end of the period being closed.
                 |
                 | `$until` is the first day NOT in the period, so a
                 | statement ending the day before it covers the month.
                 | A reconciliation of the first half of March does not
                 | let March close.
                 */
                ->where('s.period_to', '>=', \Illuminate\Support\Carbon::parse($until)->subDay()->toDateString())
                ->exists();

            if ($reconciled) {
                continue;
            }

            $reasons[] = sprintf(
                '%s has %d statement(s) in %s and no reconciliation standing for the end of it. '.
                'Cash is the one balance an auditor can confirm independently, and closing without '.
                'it leaves the most verifiable figure in these accounts unverified',
                (string) $account->label,
                $statements,
                $label,
            );
        }

        return $reasons;
    }
}
