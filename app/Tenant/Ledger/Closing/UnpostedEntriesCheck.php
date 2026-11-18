<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Closing;

use App\Support\Closing\ClosingCheck;
use Illuminate\Support\Facades\DB;

/**
 * A document dated inside the period that nobody posted - PF-196.
 *
 * ## What closing does to it
 *
 * A draft invoice, a draft bill or a draft journal entry dated inside the
 * period is work somebody started and did not finish. Closing does not
 * delete it: it makes it unpostable, because the period will refuse the
 * posting it was waiting for. So it sits there, the revenue or the cost it
 * carries is not in the accounts, and the tax return filed from those
 * accounts is short by exactly that amount.
 *
 * Nobody notices, because a draft is invisible on every report in the
 * product - that is what a draft is for.
 *
 * ## Documents, not only journal entries
 *
 * The first version of this read `journal_entries` alone, which is the
 * smaller half: a draft invoice has no journal entry AT ALL until it is
 * issued, so the thing most likely to be left unfinished was the thing the
 * check could not see. The criterion says "unposted documents" and means
 * it.
 *
 * ## Why the reason names the count and the dates
 *
 * "There are unposted entries" sends somebody to a list to find out
 * whether it is one test entry from March or forty from last week. The
 * count and the span is the difference between a minute and an afternoon,
 * and it is free to compute here.
 */
final class UnpostedEntriesCheck implements ClosingCheck
{
    public function name(): string
    {
        return 'unposted entries';
    }

    /** @return list<string> */
    public function reasonsToRefuse(string $entityId, string $label, string $from, string $until): array
    {
        /*
         | `until` is the first day NOT in the period - the contract says
         | so, and a `<=` here would pull in the first day of the next one.
         | A check that blocked a close on next month's drafts is a check
         | that gets switched off.
         */
        /*
         | `until` is the first day NOT in the period - the contract says
         | so, and a `<=` here would pull in the first day of the next one.
         | A check that blocked a close on next month's drafts is a check
         | that gets switched off.
         */
        $sources = [
            'invoice' => ['invoices', 'issue_date'],
            'supplier bill' => ['bills', 'posting_date'],
            'journal entry' => ['journal_entries', 'accounting_date'],
        ];

        $found = [];

        foreach ($sources as $what => [$table, $dateColumn]) {
            $row = DB::connection('tenant')->selectOne(
                sprintf(
                    'SELECT count(*) AS n, min(%1$s)::text AS earliest, max(%1$s)::text AS latest '.
                    'FROM %2$s WHERE entity_id = ? AND status = \'draft\' AND %1$s >= ? AND %1$s < ?',
                    $dateColumn,
                    $table,
                ),
                [$entityId, $from, $until],
            );

            if ($row === null || (int) $row->n === 0) {
                continue;
            }

            $found[] = sprintf(
                '%d draft %s%s (%s to %s)',
                (int) $row->n,
                $what,
                (int) $row->n === 1 ? '' : 's',
                (string) $row->earliest,
                (string) $row->latest,
            );
        }

        if ($found === []) {
            return [];
        }

        return [sprintf(
            '%s in %s %s still unposted. Closing does not remove them - it makes them unpostable, so '.
            'what they carry never reaches the accounts and the return filed from those accounts is '.
            'short by exactly that. A draft is invisible on every report, which is why nobody would '.
            'notice.',
            implode(', ', $found),
            $label,
            \count($found) === 1 && ! str_contains($found[0], 's (') ? 'is' : 'are',
        )];
    }
}
