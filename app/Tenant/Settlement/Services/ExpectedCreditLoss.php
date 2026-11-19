<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Ledger\Services\Reverser;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Settlement\Exceptions\PaymentRefused;
use App\Tenant\Settlement\Posting\Rules\EclProvisionChargedRule;
use App\Tenant\Settlement\Posting\Rules\EclProvisionReleasedRule;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Expected credit loss - PF-173, IFRS 9's simplified approach.
 *
 * ## A provision is an estimate; a write-off is a conclusion
 *
 * The distinction everything here turns on. A provision says a portion of
 * what is owed probably will not arrive and is revised every period; a
 * write-off says a particular debt will not, and is final.
 *
 * They post to different accounts, and a write-off **releases** the
 * provision that covered its document rather than charging the loss
 * again. Without the release the period of a write-off carries both: bad
 * debt takes the full amount while the provision still claims part of it
 * is coming, and the impairment line reads as twice the loss that
 * happened.
 *
 * ## Reversed and recomputed, never accumulated
 *
 * Each run reverses the previous one in full and posts a fresh figure.
 * Accumulating movements is the arrangement that drifts: a rate change, a
 * regraded customer or a corrected invoice each leave a residue, and four
 * quarters later the provision is the sum of four approximations nobody
 * can take apart.
 *
 * It costs more journal entries. It buys a provision that is one entry
 * derived from one matrix and one set of open documents - which is what
 * makes it something an auditor re-performs rather than accepts.
 *
 * ## Ageing is from the due date the document froze
 *
 * Not from its issue date. A customer on ninety-day terms is not overdue
 * on day thirty-one, and a matrix keyed on the wrong clock provisions the
 * patient customers and excuses the late ones. The due dates come from
 * the terms snapshot taken when the document was issued, so a change of
 * terms afterwards does not restate a provision that has been reported.
 */
final class ExpectedCreditLoss
{
    /**
     * The buckets, in the order a report reads them.
     *
     * Fixed rather than configurable, for the same reason suspense ageing
     * is: a matrix whose buckets differ between two entities cannot be
     * added up, and a group provision is the figure most of this exists
     * to produce.
     */
    public const BUCKETS = ['current', '1-30', '31-60', '61-90', '90+'];

    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Poster $poster,
        private readonly Reverser $reverser,
    ) {}

    /**
     * Set a loss rate for a bucket and grade.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function setRate(
        string $entityId,
        ?string $riskGrade,
        string $bucket,
        string $rate,
        string $userId,
        ?string $basis = null,
    ): array {
        if (! \in_array($bucket, self::BUCKETS, true)) {
            throw PaymentRefused::because(sprintf(
                '"%s" is not an ageing bucket. They are %s - fixed rather than configurable, '.
                'because a matrix whose buckets differ between two entities cannot be added up.',
                $bucket,
                implode(', ', self::BUCKETS),
            ));
        }

        $value = BigDecimal::of($rate);

        if ($value->isNegative() || $value->isGreaterThan(BigDecimal::one())) {
            throw PaymentRefused::because(sprintf(
                'A loss rate is a proportion between 0 and 1, and %s is not. Above 1 would '.
                'provision more than is owed, which is not an estimate - it is a negative '.
                'receivable on the balance sheet.',
                $rate,
            ));
        }

        DB::connection('tenant')->table('ecl_matrix')->updateOrInsert(
            ['entity_id' => $entityId, 'risk_grade' => $riskGrade, 'bucket' => $bucket],
            [
                'id' => (string) Str::ulid(),
                'rate' => (string) $value->toScale(6, RoundingMode::HalfUp),
                'basis' => $basis,
                'set_by' => $userId,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $this->audit->record(
            category: 'settlement',
            action: 'ecl.rate_set',
            subjectType: 'ecl_matrix',
            subjectId: $entityId,
            subjectLabel: sprintf('%s / %s', $riskGrade ?? 'ungraded', $bucket),
            after: ['risk_grade' => $riskGrade, 'bucket' => $bucket, 'rate' => (string) $value],
            reason: $basis,
            entityId: $entityId,
        );

        return [
            'risk_grade' => $riskGrade,
            'bucket' => $bucket,
            'rate' => (string) $value,
            'why' => sprintf(
                'A %s loss rate for %s receivables%s. It applies from the next recompute, because '.
                'a rate that restated a provision already reported would be a change nobody could '.
                'see.',
                (string) $value->multipliedBy(100)->toScale(2, RoundingMode::HalfUp).'%',
                $bucket,
                $riskGrade === null ? ' with no risk grade' : ' graded '.$riskGrade,
            ),
        ];
    }

    /** The matrix, as it stands. @return list<array<string, mixed>> */
    public function matrix(string $entityId): array
    {
        $rows = DB::connection('tenant')->table('ecl_matrix')
            ->where('entity_id', $entityId)
            ->orderByRaw('coalesce(risk_grade, \'~\'), bucket')
            ->get();

        return $rows->map(static fn (object $row): array => [
            'risk_grade' => $row->risk_grade === null ? null : (string) $row->risk_grade,
            'bucket' => (string) $row->bucket,
            'rate' => (string) $row->rate,
            'basis' => $row->basis === null ? null : (string) $row->basis,
        ])->all();
    }

    /**
     * Recompute the provision, reversing the run before it.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function recompute(
        string $entityId,
        string $periodLabel,
        Carbon $asOf,
        string $userId,
    ): array {
        $entity = Entity::query()->findOrFail($entityId);
        $currency = (string) $entity->functional_currency;

        $matrix = $this->matrixLookup($entityId);

        if ($matrix === []) {
            throw PaymentRefused::because(
                'There is no provision matrix for this entity, so there is nothing to compute a '.
                'provision from. Provisioning nothing because nobody set a rate would report a '.
                'ledger with no expected losses at all, which is a statement rather than an '.
                'absence of one.',
            );
        }

        $existing = DB::connection('tenant')->table('ecl_runs')
            ->where('entity_id', $entityId)
            ->where('period_label', $periodLabel)
            ->first();

        if ($existing !== null) {
            throw PaymentRefused::because(sprintf(
                'A provision was already computed for %s on %s. Recomputing the same period would '.
                'leave two runs claiming to be its provision - reverse that one through a later '.
                'period, or correct the matrix and recompute the period after it.',
                $periodLabel,
                Carbon::parse((string) $existing->computed_at)->toDateString(),
            ));
        }

        $previous = $this->latestRun($entityId);
        $documents = $this->openReceivables($entityId, $currency, $asOf);

        $lines = [];
        $total = Money::zero($currency);

        foreach ($documents as $document) {
            $bucket = $this->bucketFor((int) $document->days_past_due);
            $grade = $document->risk_grade === null ? null : (string) $document->risk_grade;
            $rate = $this->rateFor($matrix, $grade, $bucket);

            if ($rate === null) {
                /*
                 | A grade and bucket the matrix does not cover.
                 |
                 | Skipped rather than provisioned at zero, and counted in
                 | the result so somebody sees it. Zero would be a rate
                 | nobody set, reported as though they had.
                 */
                continue;
            }

            $open = Money::of((string) $document->open_balance, $currency);

            if (! $open->isPositive()) {
                continue;
            }

            $provision = BigDecimal::of($open->amount)
                ->multipliedBy($rate)
                ->toScale(4, RoundingMode::HalfUp);

            $lines[] = [
                'document_type' => (string) $document->document_type,
                'document_id' => (string) $document->id,
                'party_id' => $document->party_id === null ? null : (string) $document->party_id,
                'risk_grade' => $grade,
                'bucket' => $bucket,
                'rate' => (string) $rate,
                'open_balance' => (string) $open->amount,
                'provision' => (string) $provision,
                'days_past_due' => (int) $document->days_past_due,
            ];

            $total = $total->plus(Money::of((string) $provision, $currency));
        }

        $uncovered = \count($documents) - \count($lines);

        return DB::connection('tenant')->transaction(function () use (
            $entityId,
            $entity,
            $periodLabel,
            $asOf,
            $currency,
            $previous,
            $lines,
            $total,
            $uncovered,
            $userId,
        ): array {
            /*
             | The previous run is reversed in full, first.
             |
             | Not adjusted by the difference. A rate change, a regraded
             | customer or a corrected invoice each leave a residue in an
             | adjustment, and after four quarters the provision is the
             | sum of four approximations nobody can take apart.
             */
            $reversal = null;

            if ($previous !== null && $previous->journal_entry_id !== null) {
                $reversal = $this->reverser->reverse(
                    JournalEntry::query()->findOrFail((string) $previous->journal_entry_id),
                    $asOf,
                    sprintf(
                        'Reversing the %s provision in full before recomputing %s, because a '.
                        'provision adjusted by its difference drifts with every rate change.',
                        (string) $previous->period_label,
                        $periodLabel,
                    ),
                );
            }

            $entry = $total->isPositive()
                ? $this->poster->post(
                    entity: $entity,
                    ruleKey: EclProvisionChargedRule::KEY,
                    event: [
                        'amount' => (string) $total->amount,
                        'narration' => sprintf('Expected credit loss provision, %s', $periodLabel),
                    ],
                    accountingDate: $asOf,
                    journalCode: 'GEN',
                    sourceType: 'ecl_run',
                    sourceId: $periodLabel,
                    narration: sprintf('%d documents across the matrix', \count($lines)),
                )
                : null;

            $runId = (string) Str::ulid();

            DB::connection('tenant')->table('ecl_runs')->insert([
                'id' => $runId,
                'entity_id' => $entityId,
                'period_label' => $periodLabel,
                'as_of' => $asOf->toDateString(),
                'currency' => $currency,
                'provision' => (string) $total->amount,
                'documents' => \count($lines),
                'supersedes_id' => $previous === null ? null : (string) $previous->id,
                'journal_entry_id' => $entry === null ? null : (string) $entry->getKey(),
                'reversal_entry_id' => $reversal === null ? null : (string) $reversal->getKey(),
                'computed_by' => $userId,
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($lines as $line) {
                DB::connection('tenant')->table('ecl_provision_lines')->insert([
                    'id' => (string) Str::ulid(),
                    'entity_id' => $entityId,
                    'run_id' => $runId,
                    ...$line,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->audit->record(
                category: 'settlement',
                action: 'ecl.recomputed',
                subjectType: 'ecl_run',
                subjectId: $runId,
                subjectLabel: $periodLabel,
                before: $previous === null ? null : ['provision' => (string) $previous->provision],
                after: [
                    'provision' => (string) $total->amount,
                    'documents' => \count($lines),
                    'uncovered_documents' => $uncovered,
                    'journal_entry_id' => $entry === null ? null : (string) $entry->getKey(),
                    'reversal_entry_id' => $reversal === null ? null : (string) $reversal->getKey(),
                ],
                reason: sprintf('Recomputed in full for %s', $periodLabel),
                entityId: $entityId,
            );

            return [
                'id' => $runId,
                'provision' => $total,
                'documents' => \count($lines),
                'uncovered_documents' => $uncovered,
                'journal_entry_id' => $entry === null ? null : (string) $entry->getKey(),
                'reversal_entry_id' => $reversal === null ? null : (string) $reversal->getKey(),
                'why' => sprintf(
                    '%s provisioned across %d document%s%s. The %s run was reversed in full first, '.
                    'so this figure is one entry derived from one matrix rather than four '.
                    'quarters of adjustments.',
                    (string) $total->toPresentation(),
                    \count($lines),
                    \count($lines) === 1 ? '' : 's',
                    $uncovered === 0
                        ? ''
                        : sprintf(
                            ', with %d the matrix does not cover and which were left out rather '.
                            'than provisioned at a rate nobody set',
                            $uncovered,
                        ),
                    $previous === null ? 'previous' : (string) $previous->period_label,
                ),
            ];
        });
    }

    /**
     * Release the provision a write-off used up - PF-173.
     *
     * @return array<string, mixed>|null the release, or null when nothing was provisioned
     */
    public function releaseFor(
        string $entityId,
        string $writeOffId,
        string $documentType,
        string $documentId,
        Money $writtenOff,
        Carbon $on,
    ): ?array {
        $run = $this->latestRun($entityId);

        if ($run === null) {
            return null;
        }

        $line = DB::connection('tenant')->table('ecl_provision_lines')
            ->where('run_id', (string) $run->id)
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->first();

        if ($line === null) {
            /*
             | Nothing was provisioned for this document.
             |
             | Which is ordinary: a current invoice in a grade with a zero
             | rate, or one issued after the last recompute. There is
             | nothing to release, and releasing something anyway would
             | credit a provision that was never charged.
             */
            return null;
        }

        $provisioned = Money::of((string) $line->provision, (string) $run->currency);

        /*
         | The smaller of what was provisioned and what was written off.
         |
         | A partial write-off releases a part. Releasing the whole
         | provision would leave the remaining receivable unprovisioned
         | until the next recompute, which is the opposite error and just
         | as invisible.
         */
        $release = $provisioned->amount->isLessThan($writtenOff->abs()->amount)
            ? $provisioned
            : Money::of((string) $writtenOff->abs()->amount, (string) $run->currency);

        if (! $release->isPositive()) {
            return null;
        }

        $entity = Entity::query()->findOrFail($entityId);

        $entry = $this->poster->post(
            entity: $entity,
            ruleKey: EclProvisionReleasedRule::KEY,
            event: [
                'amount' => (string) $release->amount,
                'narration' => sprintf('Provision released on write-off of %s', $documentType),
            ],
            accountingDate: $on,
            journalCode: 'GEN',
            sourceType: 'write_off',
            sourceId: $writeOffId,
            narration: 'The estimate was right about this one, so the provision is used rather than charged again',
        );

        $id = (string) Str::ulid();

        DB::connection('tenant')->table('ecl_releases')->insert([
            'id' => $id,
            'entity_id' => $entityId,
            'write_off_id' => $writeOffId,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'amount' => (string) $release->amount,
            'currency' => (string) $run->currency,
            'released_on' => $on->toDateString(),
            'from_run_id' => (string) $run->id,
            'journal_entry_id' => (string) $entry->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $id,
            'amount' => $release,
            'journal_entry_id' => (string) $entry->getKey(),
            'why' => sprintf(
                '%s of provision used up. Without this the period would carry both the estimate '.
                'and the conclusion, and the impairment line would read as twice the loss that '.
                'happened.',
                (string) $release->toPresentation(),
            ),
        ];
    }

    /**
     * The roll-forward: opening, charge, release, utilization, closing.
     *
     * All five, because the closing figure alone says nothing about how it
     * got there - and "the provision went from 40,000 to 45,000" has four
     * different explanations with four different consequences.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function rollForward(string $entityId, string $periodLabel): array
    {
        $run = DB::connection('tenant')->table('ecl_runs')
            ->where('entity_id', $entityId)
            ->where('period_label', $periodLabel)
            ->first();

        if ($run === null) {
            throw PaymentRefused::because(sprintf(
                'No provision was computed for %s, so there is no roll-forward to report. An '.
                'empty one would read as a period with no expected losses rather than a period '.
                'nobody computed.',
                $periodLabel,
            ));
        }

        $currency = (string) $run->currency;
        $previous = $run->supersedes_id === null
            ? null
            : DB::connection('tenant')->table('ecl_runs')->where('id', (string) $run->supersedes_id)->first();

        $opening = Money::of($previous === null ? '0' : (string) $previous->provision, $currency);
        $closing = Money::of((string) $run->provision, $currency);

        /*
         | Utilization is what ate into the OPENING figure, by run.
         |
         | Not by date. A write-off on the same day the opening provision
         | was computed is genuinely ambiguous on dates alone: the
         | document was open when the figure was computed and written off
         | afterwards, so the release belongs to this period - and
         | `released_on > previous.as_of` excludes it while `>=` would
         | wrongly swallow releases the opening figure already reflects.
         |
         | The release records which run's provision it consumed, which is
         | exact and does not depend on ordering within a day. A check
         | caught this: the write-off vanished from the roll-forward and
         | the five figures still tied, because a utilization that is
         | counted nowhere is indistinguishable from one that never
         | happened.
         */
        $utilisation = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(amount), 0)::text AS used
            FROM ecl_releases
            WHERE entity_id = ?
              AND from_run_id IS NOT DISTINCT FROM ?::char(26)
        SQL, [$entityId, $previous === null ? null : (string) $previous->id]);

        $used = Money::of((string) ($utilisation->used ?? '0'), $currency);

        /*
         | The movement, split into a charge and a release.
         |
         | After utilization, the provision had to travel from
         | (opening - used) to the closing figure. Upwards is a charge -
         | the estimate grew; downwards is a release - it shrank. One
         | figure called "movement" would hide which, and they mean
         | opposite things about the ledger.
         */
        $afterUse = $opening->minus($used);
        $movement = $closing->minus($afterUse);

        $charge = $movement->isPositive() ? $movement : Money::zero($currency);
        $released = $movement->isNegative() ? $movement->negated() : Money::zero($currency);

        return [
            'period' => $periodLabel,
            'as_of' => (string) $run->as_of,
            'opening' => $opening,
            'charge' => $charge,
            'release' => $released,
            'utilisation' => $used,
            'closing' => $closing,
            'ties' => $afterUse->plus($charge)->minus($released)->equals($closing),
            'why' => sprintf(
                '%s opening, %s used up by write-offs, %s %s, leaving %s. The five figures are '.
                'reported separately because "the provision went from %s to %s" has four '.
                'explanations and they mean different things.',
                (string) $opening->toPresentation(),
                (string) $used->toPresentation(),
                (string) ($charge->isPositive() ? $charge : $released)->toPresentation(),
                $charge->isPositive() ? 'charged' : 'released',
                (string) $closing->toPresentation(),
                (string) $opening->toPresentation(),
                (string) $closing->toPresentation(),
            ),
        ];
    }

    /** The provision standing now, if anybody has computed one. */
    public function latestRun(string $entityId): ?object
    {
        return DB::connection('tenant')->table('ecl_runs')
            ->where('entity_id', $entityId)
            ->orderByDesc('as_of')
            ->orderByDesc('computed_at')
            ->first();
    }

    public function bucketFor(int $daysPastDue): string
    {
        return match (true) {
            $daysPastDue <= 0 => 'current',
            $daysPastDue <= 30 => '1-30',
            $daysPastDue <= 60 => '31-60',
            $daysPastDue <= 90 => '61-90',
            default => '90+',
        };
    }

    /**
     * @param  array<string, string>  $matrix
     */
    private function rateFor(array $matrix, ?string $grade, string $bucket): ?BigDecimal
    {
        /*
         | The graded rate, then the ungraded one.
         |
         | Most customers are ungraded in most businesses, so the fallback
         | is the row that does most of the work - and a grade with no row
         | falls back to it rather than to zero, because a customer being
         | graded is not a reason to provision less for them.
         */
        $key = ($grade ?? '~').'|'.$bucket;
        $fallback = '~|'.$bucket;

        $rate = $matrix[$key] ?? $matrix[$fallback] ?? null;

        return $rate === null ? null : BigDecimal::of($rate);
    }

    /** @return array<string, string> */
    private function matrixLookup(string $entityId): array
    {
        $rows = DB::connection('tenant')->table('ecl_matrix')
            ->where('entity_id', $entityId)
            ->get();

        $lookup = [];

        foreach ($rows as $row) {
            $lookup[($row->risk_grade === null ? '~' : (string) $row->risk_grade).'|'.$row->bucket] = (string) $row->rate;
        }

        return $lookup;
    }

    /**
     * Open receivables with their ageing, from the due date they froze.
     *
     * @return list<object>
     */
    private function openReceivables(string $entityId, string $currency, Carbon $asOf): array
    {
        return DB::connection('tenant')->select(<<<'SQL'
            SELECT 'invoice'::text AS document_type,
                   i.id,
                   i.party_id,
                   p.risk_grade,
                   peakflow_document_open_balance('invoice', i.id)::text AS open_balance,
                   /*
                    | Days past the due date the document froze at issue.
                    |
                    | A customer on ninety-day terms is not overdue on day
                    | thirty-one, and a matrix keyed on the issue date
                    | provisions the patient customers while excusing the
                    | late ones. The terms snapshot is used rather than
                    | the party's current terms, so changing them later
                    | does not restate a provision already reported.
                    */
                   (?::date - coalesce((
                       SELECT max((d->>'date')::date)
                       FROM jsonb_array_elements(i.terms_snapshot->'due_dates') d
                   ), i.issue_date)) AS days_past_due
            FROM invoices i
            LEFT JOIN parties p ON p.id = i.party_id
            WHERE i.entity_id = ?
              AND i.currency = ?
              AND i.status = 'issued'
              /*
               | The receivable types, from the enum - PF-090.
               |
               | This was `IN ('380', '383', '386')`, written before the
               | partial invoice and the corrected invoice existed and never
               | told about either. Both are money a customer owes.
               | `DocumentType::receivableCodes()` is now the one answer, and
               | its `match` has no `default`, so a type added later will not
               | compile until somebody decides which side of the question it
               | is on.
               */
              AND i.document_type = ANY(?)
              AND i.issue_date <= ?::date
            ORDER BY i.issue_date, i.number
        SQL, [
            $asOf->toDateString(), $entityId, $currency,
            '{'.implode(',', DocumentType::receivableCodes()).'}', $asOf->toDateString(),
        ]);
    }
}
