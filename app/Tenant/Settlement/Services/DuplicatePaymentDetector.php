<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Settlement\Exceptions\PaymentRefused;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paying the same supplier the same amount twice - PF-180.
 *
 * ## The duplicate that matters is in another entity
 *
 * A group of companies shares suppliers. The same invoice arrives at two
 * of them - forwarded by somebody being helpful - and each pays it.
 * Neither run looks wrong from inside its own entity, both bank accounts
 * reconcile perfectly, and the supplier says nothing.
 *
 * So the scan goes through `peakflow_similar_payments`, which runs as
 * the owner and reads past the entity row policy. That is the one query
 * in this product that does, it returns a fixed projection - the entity
 * code, the run number, the date, the days apart - and the reason is
 * written on the function itself.
 *
 * ## It looks at what has ALREADY gone out, not only at open runs
 *
 * The half a run-only check misses: the first payment was recorded a
 * week ago, by hand or in a run since archived, and the second run has
 * nothing to compare against. So `payments` is scanned too.
 *
 * ## A warning is accepted by a person, with a reason
 *
 * Not dismissed. "Same supplier, same amount, four days apart" is
 * usually a duplicate and sometimes a monthly retainer, and the
 * difference is knowledge nobody can put in a rule. So the release is
 * blocked until somebody says which it is and signs their name to it -
 * and the signature is what an investigation reads six months later.
 *
 * ## Why this is not simply refused
 *
 * Because real businesses pay the same amount to the same supplier
 * twice in a week: a retainer, two identical deliveries, a deposit and
 * a balance that happen to match. A product that refused would be
 * worked around by changing an amount by a penny, and then nothing is
 * checked at all.
 */
final class DuplicatePaymentDetector
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Examine every instruction in a run, and raise what looks wrong.
     *
     * @return array<string, mixed>
     */
    public function examine(string $runId): array
    {
        $run = DB::connection('tenant')->table('payment_runs')->where('id', $runId)->first();

        if ($run === null) {
            throw PaymentRefused::because('There is no payment run with that id to examine.');
        }

        $window = (int) config('settlement.duplicate_window_days', 10);
        $payOn = Carbon::parse((string) $run->pay_on);

        $instructions = DB::connection('tenant')->table('payment_run_instructions')
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get();

        $raised = [];

        foreach ($instructions as $instruction) {
            /*
             | Cleared first, so an examination can be re-run.
             |
             | A run is examined on preparation and again on release -
             | the world moves in between, and the second look is the
             | one that matters. Warnings that were ACCEPTED stay:
             | somebody signed for those, and clearing them would ask
             | them again and lose the signature.
             */
            DB::connection('tenant')->table('payment_duplicate_warnings')
                ->where('instruction_id', (string) $instruction->id)
                ->whereNull('acknowledged_at')
                ->delete();

            /*
             | What was already signed for, so it is not raised again.
             |
             | Keeping the accepted rows is not enough: the re-scan
             | finds the same pair and inserts a FRESH unacknowledged
             | warning beside it, so the run can never be released -
             | every look adds one. Measured by the check that asserts
             | an examination does not ask twice, which is the only
             | reason this is here.
             |
             | Matched on the finding rather than on the row: the same
             | reason against the same other document is the same
             | question, and somebody has answered it.
             */
            $alreadyAccepted = DB::connection('tenant')->table('payment_duplicate_warnings')
                ->where('instruction_id', (string) $instruction->id)
                ->whereNotNull('acknowledged_at')
                ->get()
                ->map(static fn (object $row): string => sprintf(
                    '%s|%s',
                    (string) $row->reason,
                    (string) ($row->other_reference ?? ''),
                ))
                ->all();

            $similar = DB::connection('tenant')->select(
                'SELECT * FROM peakflow_similar_payments(?, ?, ?, ?, ?, ?, ?)',
                [
                    (string) $instruction->entity_id,
                    (string) $instruction->party_id,
                    (string) $instruction->amount,
                    (string) $instruction->currency,
                    $payOn->toDateString(),
                    $window,
                    $runId,
                ],
            );

            foreach ($similar as $other) {
                $finding = sprintf('%s|%s', (string) $other->source, (string) $other->other_reference);

                if (\in_array($finding, $alreadyAccepted, true)) {
                    continue;
                }

                $id = (string) Str::ulid();
                $amount = Money::of((string) $instruction->amount, (string) $instruction->currency);

                DB::connection('tenant')->table('payment_duplicate_warnings')->insert([
                    'id' => $id,
                    'entity_id' => (string) $instruction->entity_id,
                    'run_id' => $runId,
                    'instruction_id' => (string) $instruction->id,
                    'party_id' => (string) $instruction->party_id,
                    'amount' => (string) $instruction->amount,
                    'currency' => (string) $instruction->currency,
                    'reason' => (string) $other->source,
                    'detail' => $this->describe($other, $amount, (int) $other->days_apart),
                    'other_entity_code' => (string) $other->other_entity_code,
                    'other_reference' => (string) $other->other_reference,
                    'other_date' => (string) $other->other_date,
                    'days_apart' => (int) $other->days_apart,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $raised[] = $id;
            }
        }

        return [
            'run' => (string) $run->number,
            'window_days' => $window,
            'raised' => \count($raised),
            'why' => $raised === []
                ? sprintf(
                    'Nothing in this run matches a payment to the same party for the same amount '.
                    'within %d day(s), in this entity or any other.',
                    $window,
                )
                : sprintf(
                    '%d warning(s). Each needs somebody to say whether it is a duplicate or a '.
                    'coincidence, and to sign for it - the difference is knowledge nobody can put in a '.
                    'rule.',
                    \count($raised),
                ),
        ];
    }

    /**
     * Warnings in this run that nobody has accepted.
     *
     * What `PaymentRuns::release()` asks before it releases anything.
     *
     * @return list<array<string, mixed>>
     */
    public function unacknowledged(string $runId): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT w.id,
                   w.reason,
                   w.detail,
                   w.amount::text AS amount,
                   w.currency,
                   w.other_entity_code,
                   w.other_reference,
                   w.other_date,
                   w.days_apart,
                   coalesce(p.trading_name, p.legal_name) AS party_name
            FROM payment_duplicate_warnings w
            LEFT JOIN parties p ON p.id = w.party_id
            WHERE w.run_id = ?
              AND w.acknowledged_at IS NULL
            ORDER BY w.days_apart, w.id
        SQL, [$runId]);

        return array_map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'reason' => (string) $row->reason,
            'detail' => (string) $row->detail,
            'amount' => (string) $row->amount,
            'currency' => (string) $row->currency,
            'party' => $row->party_name === null ? null : (string) $row->party_name,
            'other_entity' => $row->other_entity_code === null ? null : (string) $row->other_entity_code,
            'other_reference' => $row->other_reference === null ? null : (string) $row->other_reference,
            'other_date' => $row->other_date === null ? null : (string) $row->other_date,
            'days_apart' => $row->days_apart === null ? null : (int) $row->days_apart,
        ], $rows);
    }

    /**
     * Somebody takes responsibility for one warning.
     *
     * @throws PaymentRefused
     */
    public function acknowledge(string $warningId, string $explanation, string $userId): void
    {
        $warning = DB::connection('tenant')->table('payment_duplicate_warnings')
            ->where('id', $warningId)
            ->first();

        if ($warning === null) {
            throw PaymentRefused::because('There is no duplicate warning with that id.');
        }

        if ($warning->acknowledged_at !== null) {
            throw PaymentRefused::because(sprintf(
                'That warning was accepted by %s on %s. Accepting it again would replace the name '.
                'against a payment that may already have gone out.',
                (string) $warning->acknowledged_by,
                (string) $warning->acknowledged_at,
            ));
        }

        if (mb_strlen(trim($explanation)) < 15) {
            throw PaymentRefused::because(
                'Accepting a duplicate warning needs the reason in a sentence - "this is the monthly '.
                'retainer, not the invoice we paid on the 3rd". It is what an investigation reads six '.
                'months later when the supplier has been paid twice, and a dismissal with no sentence '.
                'is what it finds instead of an answer.',
            );
        }

        DB::connection('tenant')->table('payment_duplicate_warnings')->where('id', $warningId)->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $userId,
            'acknowledgement' => trim($explanation),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'settlement',
            action: 'duplicate_payment.accepted',
            subjectType: 'payment_duplicate_warning',
            subjectId: $warningId,
            subjectLabel: sprintf(
                '%s %s against %s',
                (string) $warning->currency,
                (string) $warning->amount,
                (string) ($warning->other_reference ?? 'a payment already made'),
            ),
            after: ['acknowledged_by' => $userId],
            reason: trim($explanation),
            entityId: (string) $warning->entity_id,
            partyId: (string) $warning->party_id,
        );
    }

    /** The sentence a person reads, naming what it looks like. */
    private function describe(object $other, Money $amount, int $daysApart): string
    {
        $where = match ((string) $other->source) {
            'same_amount_another_entity' => sprintf(
                'Entity %s is paying the same party %s in run %s',
                (string) $other->other_entity_code,
                (string) $amount->toPresentation(),
                (string) $other->other_reference,
            ),
            'same_amount_already_released' => sprintf(
                'Run %s has already been released with the same party and %s',
                (string) $other->other_reference,
                (string) $amount->toPresentation(),
            ),
            'same_amount_already_paid' => sprintf(
                'Payment %s to the same party for %s has already gone out',
                (string) $other->other_reference,
                (string) $amount->toPresentation(),
            ),
            default => sprintf(
                'Run %s pays the same party %s',
                (string) $other->other_reference,
                (string) $amount->toPresentation(),
            ),
        };

        return sprintf(
            '%s, dated %s - %d day(s) apart. Usually a duplicate and sometimes a retainer or two '.
            'identical deliveries, which is why somebody has to say which.',
            $where,
            (string) $other->other_date,
            $daysApart,
        );
    }
}
