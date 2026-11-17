<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Settlement\Posting\Rules\TransitClearedRule;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Closing the in-transit position when the bank says the money left - PF-179.
 *
 * ## Driven from here, not from the match
 *
 * The event is a bank line being matched to a released run, which happens
 * in `Banking` - and `Banking` may not depend on `Settlement`, nor should
 * it: a reconciliation screen has no business knowing what a payment run
 * posts.
 *
 * So the clearing is pulled rather than pushed. This reads the matches
 * (which `Settlement` may do) and posts what the bank has confirmed. The
 * cost is that clearing is a step rather than an instant; what it buys is
 * that the step is **idempotent and re-runnable**, which matters more -
 * a push that failed halfway leaves a position nobody can reconstruct,
 * and this one simply runs again.
 *
 * ## The three-way tie
 *
 *   accounts payable        what we still owe
 *   payments in transit     what we have told the bank to send
 *   bank                    what the bank says we have
 *
 * In transit is the difference between the second and third that we can
 * explain. Without it, the difference is a figure somebody reconstructs
 * from release dates every month - and the reconstruction happens in a
 * spreadsheet beside the accounting system.
 *
 * ## And it ages
 *
 * An in-transit item older than a few days is a payment that left the
 * system and never appeared on a statement. That is one of: a file
 * nobody submitted, a file the bank rejected with a report nobody
 * ingested, or a payment that cleared and was never matched. All three
 * are worth asking about, and none of them announces itself.
 */
final class TransitClearing
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Poster $poster,
    ) {}

    /**
     * Post the clearing for everything the bank has confirmed.
     *
     * @return array<string, mixed>
     */
    public function clear(string $entityId, string $userId): array
    {
        $entity = Entity::query()->findOrFail($entityId);
        $cleared = [];

        foreach ($this->confirmed($entityId) as $row) {
            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: TransitClearedRule::KEY,
                event: [
                    'amount' => (string) $row->amount,
                    'narration' => sprintf(
                        'Cleared the bank on %s, matched to run %s',
                        (string) $row->booking_date,
                        (string) $row->run_number,
                    ),
                ],
                /*
                 | Dated on the STATEMENT line, not on today.
                 |
                 | The bank's own date for the movement is the only date
                 | that makes the bank reconciliation tie: posting cash on
                 | the day somebody happened to run this step would put
                 | the ledger's bank balance and the statement out of step
                 | by however long nobody ran it.
                 */
                accountingDate: Carbon::parse((string) $row->booking_date),
                journalCode: 'BNK',
                sourceType: 'payment_run_instruction',
                sourceId: (string) $row->id,
            );

            DB::connection('tenant')->table('payment_run_instructions')
                ->where('id', (string) $row->id)
                ->update([
                    'cleared_on' => (string) $row->booking_date,
                    'updated_at' => now(),
                ]);

            $cleared[] = [
                'instruction_id' => (string) $row->id,
                'amount' => (string) $row->amount,
                'booking_date' => (string) $row->booking_date,
                'journal_entry_id' => (string) $entry->getKey(),
            ];
        }

        if ($cleared !== []) {
            $this->audit->record(
                category: 'settlement',
                action: 'transit.cleared',
                subjectType: 'entity',
                subjectId: $entityId,
                subjectLabel: (string) $entity->code,
                after: ['instructions' => \count($cleared)],
                reason: sprintf(
                    '%d in-transit instruction(s) were confirmed by a bank statement and posted to cash',
                    \count($cleared),
                ),
                entityId: $entityId,
            );
        }

        return [
            'cleared' => $cleared,
            'why' => $cleared === []
                ? 'Nothing is waiting: every released instruction either has no statement line yet or '.
                  'has already been posted to cash.'
                : sprintf(
                    '%d instruction(s) posted to cash, each dated on the bank\'s own statement line '.
                    'rather than today - which is what makes the bank reconciliation tie.',
                    \count($cleared),
                ),
        ];
    }

    /**
     * What in transit should be, derived rather than stored.
     *
     * Released, not cleared, not refused. The figure the ledger's
     * in-transit control account has to equal - and a derivation rather
     * than a second balance, because two places holding one truth is how
     * they come to disagree.
     */
    public function expected(string $entityId, string $currency): Money
    {
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(i.amount), 0)::text AS total
            FROM payment_run_instructions i
            JOIN payment_runs r ON r.id = i.run_id
            WHERE i.entity_id = ?
              AND r.state = 'released'
              AND r.currency = ?
              AND i.cleared_on IS NULL
              /*
               | A refused instruction is not in transit.
               |
               | The bank said it will not send it, so the payable came
               | back and the in-transit position closed. Counting it
               | would make the control account disagree with its own
               | derivation by exactly the rejections nobody ingested.
               */
              AND coalesce(i.bank_status, '') <> 'RJCT'
        SQL, [$entityId, $currency]);

        return Money::of((string) ($row->total ?? '0'), $currency);
    }

    /**
     * In-transit items older than the tolerance.
     *
     * @return list<array<string, mixed>>
     */
    public function aged(string $entityId, int $days = 5): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT i.id,
                   i.amount::text AS amount,
                   i.currency,
                   r.number AS run_number,
                   r.pay_on,
                   r.released_at,
                   coalesce(p.legal_name, 'a beneficiary') AS party_name,
                   i.bank_status,
                   (current_date - r.pay_on) AS age_in_days,
                   EXISTS (
                       SELECT 1 FROM payment_files f
                       WHERE f.run_id = r.id AND f.downloaded_at IS NOT NULL
                   ) AS was_handed_over
            FROM payment_run_instructions i
            JOIN payment_runs r ON r.id = i.run_id
            LEFT JOIN parties p ON p.id = i.party_id
            WHERE i.entity_id = ?
              AND r.state = 'released'
              AND i.cleared_on IS NULL
              AND coalesce(i.bank_status, '') <> 'RJCT'
              AND (current_date - r.pay_on) > ?
            ORDER BY r.pay_on
        SQL, [$entityId, $days]);

        return array_map(static fn (object $row): array => [
            'instruction_id' => (string) $row->id,
            'run' => (string) $row->run_number,
            'party' => (string) $row->party_name,
            'amount' => Money::of((string) $row->amount, (string) $row->currency),
            'pay_on' => (string) $row->pay_on,
            'age_in_days' => (int) $row->age_in_days,
            'bank_status' => $row->bank_status === null ? null : (string) $row->bank_status,
            /*
             | The three causes, named.
             |
             | Because "aged in transit" on a report is a figure, and what
             | somebody needs is the question to ask - and which question
             | depends on what the file and the status report say.
             */
            'why' => match (true) {
                ! $row->was_handed_over => sprintf(
                    '%s has been in transit for %d days and the file for run %s was never handed over - '.
                    'so this payment was authorised and nobody sent it. The supplier is waiting and '.
                    'nothing in the system looks wrong.',
                    (string) $row->party_name,
                    (int) $row->age_in_days,
                    (string) $row->run_number,
                ),
                $row->bank_status === null => sprintf(
                    '%s has been in transit for %d days, the file went to the bank, and no status report '.
                    'has been ingested for it. Either the bank sent one nobody loaded, or it refused the '.
                    'instruction and the report is sitting in a mailbox.',
                    (string) $row->party_name,
                    (int) $row->age_in_days,
                ),
                default => sprintf(
                    '%s has been in transit for %d days and the bank reported %s, so it accepted the '.
                    'instruction. The payment has probably cleared and the statement line was never '.
                    'matched to the run - which means the bank balance is right and the in-transit '.
                    'position is not.',
                    (string) $row->party_name,
                    (int) $row->age_in_days,
                    (string) $row->bank_status,
                ),
            },
        ], $rows);
    }

    /**
     * Instructions a bank statement has confirmed and nothing has posted.
     *
     * @return list<object>
     */
    private function confirmed(string $entityId): array
    {
        return DB::connection('tenant')->select(<<<'SQL'
            SELECT i.id,
                   i.amount::text AS amount,
                   r.number AS run_number,
                   m.booking_date
            FROM payment_run_instructions i
            JOIN payment_runs r ON r.id = i.run_id
            JOIN matches m
              ON m.reversed_at IS NULL
             AND (
                 (m.matched_type = 'payment_run_instruction' AND m.matched_id = i.id)
                 /*
                  | Or the batch, which the bank debited as one line.
                  |
                  | Both shapes are what the same file produces at
                  | different banks - PF-156's pass 2 matches either, and
                  | a clearing step that only understood one would leave
                  | every instruction from the other bank in transit for
                  | ever.
                  */
                 OR (m.matched_type = 'payment_run' AND m.matched_id = r.id)
             )
            WHERE i.entity_id = ?
              AND r.state = 'released'
              AND i.cleared_on IS NULL
              AND coalesce(i.bank_status, '') <> 'RJCT'
            ORDER BY i.id
        SQL, [$entityId]);
    }
}
