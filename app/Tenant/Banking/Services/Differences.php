<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Banking\Enums\DifferenceType;
use App\Tenant\Banking\Exceptions\MatchRefused;
use App\Tenant\Identity\Services\SodEngine;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\Settings;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recording a difference, once somebody has classified it - PF-164.
 *
 * ## The tolerance is per type, and that is the point
 *
 * A rounding tolerance of 0.05 is arithmetic. A short-payment tolerance of
 * 0.05 is a policy that nothing is written off without a decision. One
 * figure for all six would have to be the largest of them, and the largest
 * is the one that absorbs theft - one invoice at a time, every individual
 * write-off looking like housekeeping.
 *
 * Each tolerance is a tenant setting bounded by a **platform ceiling**.
 * "Write off anything under 50" is a policy decision somebody in the
 * business makes; it is not a developer's constant, and it is not
 * unlimited.
 *
 * ## Above tolerance is a decision, and a decision has an author
 *
 * It needs `reconciliation.write_off`, and that duty conflicts with
 * matching: the person who decided a payment did not cover an invoice is
 * not the person who decides the shortfall need not be chased. The
 * constraint on the table says the same thing from underneath, because a
 * service is one code path and an import is another.
 *
 * ## Withholding is recognised here and resolved elsewhere
 *
 * Its tolerance ceiling is zero, deliberately: a customer who deducted tax
 * at source handed us a **credit against our own liability**, and the only
 * correct outcome is claiming it. Absorbing it as a difference would be
 * throwing real money away quietly, at a rate of a few percent of revenue
 * in every withholding jurisdiction the business sells into.
 *
 * So recording one is allowed - the receivable has to close - and the
 * result says plainly that the credit is not claimable until PF-083 has a
 * certificate for it.
 */
final class Differences
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Poster $poster,
        private readonly Settings $settings,
        private readonly SodEngine $sod,
    ) {}

    /**
     * There is deliberately no check that the difference account exists.
     *
     * There was one, and it duplicated a refusal the ledger already makes
     * better: `ControlAccountNotConfigured` names the role and says to
     * assign it in the chart of accounts before posting. Two refusals for
     * one fact is two messages to keep in step, and the one further from
     * the fact is the one that drifts.
     *
     * Found by a bite: removing the guard changed nothing, because the
     * ledger had been doing the work all along.
     */

    /** What a tenant may absorb of this type without anybody approving it. */
    public function tolerance(DifferenceType $type, string $entityId): Money
    {
        $entity = Entity::query()->findOrFail($entityId);

        return Money::of(
            (string) $this->settings->get($type->toleranceSetting(), $entityId),
            (string) $entity->functional_currency,
        );
    }

    /**
     * Record a difference, posting it where it belongs.
     *
     * @param  array{
     *     entity_id: string,
     *     subject_type: string,
     *     subject_id: string,
     *     counter_account_id: string,
     *     party_id?: string|null,
     *     arose_on?: string|null,
     * }  $against
     * @return array<string, mixed>
     *
     * @throws MatchRefused
     */
    public function record(
        DifferenceType $type,
        Money $amount,
        array $against,
        string $userId,
        string $reason,
        ?string $approvedBy = null,
    ): array {
        if (mb_strlen(trim($reason)) <= 10) {
            throw MatchRefused::because(
                'A difference needs a reason. It is the sentence somebody reads when they ask why '.
                'an invoice closed for less than it was issued for, and the type alone does not '.
                'answer that.',
            );
        }

        if ($amount->isZero()) {
            throw MatchRefused::because(
                'There is no difference to record. A zero difference would appear on the report as '.
                'an event nobody can act on and would make the count of differences meaningless.',
            );
        }

        $entityId = $this->entityOf($against);
        $tolerance = $this->tolerance($type, $entityId);
        $within = ! $amount->abs()->amount->isGreaterThan($tolerance->amount);

        if (! $within && $approvedBy === null) {
            throw MatchRefused::because(sprintf(
                'This %s of %s is beyond the %s your entity accepts without approval. Somebody with '.
                'the write-off permission has to decide it, because deciding that money need not be '.
                'chased is a decision whatever the figure - and a tolerance that let it through '.
                'would be a tolerance for making that decision quietly.',
                $type->labelEn(),
                (string) $amount->abs()->toPresentation(),
                (string) $tolerance->toPresentation(),
            ));
        }

        if (! $within && $approvedBy !== null) {
            /*
             | The approver's duty, asserted here rather than on a route.
             |
             | The same argument as every other duty in this codebase: this
             | method is reached from a controller, a console command and
             | whatever calls it next, and a middleware protects only the
             | first of those.
             */
            $this->sod->assert(
                duty: 'reconciliation.write_off',
                subjectType: (string) $against['subject_type'],
                subjectId: (string) $against['subject_id'],
                subjectLabel: sprintf('%s of %s', $type->labelEn(), (string) $amount->abs()->toPresentation()),
            );
        }

        $entity = Entity::query()->findOrFail($entityId);
        $aroseOn = Carbon::parse($against['arose_on'] ?? now()->toDateString());
        $partyId = isset($against['party_id']) ? (string) $against['party_id'] : null;

        return DB::connection('tenant')->transaction(function () use (
            $type,
            $amount,
            $against,
            $entity,
            $entityId,
            $tolerance,
            $within,
            $aroseOn,
            $partyId,
            $userId,
            $reason,
            $approvedBy,
        ): array {
            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: $type->postingRule(),
                event: [
                    'amount' => (string) $amount->amount,
                    'counter_account_id' => (string) $against['counter_account_id'],
                    'party_id' => $partyId,
                    'narration' => sprintf('%s: %s', ucfirst($type->labelEn()), $reason),
                ],
                accountingDate: $aroseOn,
                journalCode: 'BNK',
                sourceType: (string) $against['subject_type'],
                sourceId: (string) $against['subject_id'],
                narration: $reason,
            );

            $id = (string) Str::ulid();

            DB::connection('tenant')->table('reconciliation_differences')->insert([
                'id' => $id,
                'entity_id' => $entityId,
                'type' => $type->value,
                'subject_type' => (string) $against['subject_type'],
                'subject_id' => (string) $against['subject_id'],
                'party_id' => $partyId,
                'amount' => (string) $amount->amount,
                'currency' => $amount->currency,
                /*
                 | The tolerance as it stood, stored beside the verdict.
                 |
                 | Both, because "it was within tolerance" is the claim and
                 | the figure is the evidence. A tenant who raises a
                 | tolerance next week would otherwise make every past
                 | difference look as though it had always been inside one.
                 */
                'tolerance' => (string) $tolerance->amount,
                'within_tolerance' => $within,
                'arose_on' => $aroseOn->toDateString(),
                'period_id' => $entry->period_id,
                'reason' => $reason,
                'recorded_by' => $userId,
                'recorded_at' => now(),
                'approved_by' => $within ? null : $approvedBy,
                'approved_at' => $within ? null : now(),
                'journal_entry_id' => (string) $entry->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'banking',
                action: $within ? 'difference.recorded' : 'difference.written_off',
                subjectType: (string) $against['subject_type'],
                subjectId: (string) $against['subject_id'],
                subjectLabel: sprintf('%s of %s', $type->labelEn(), (string) $amount->abs()->toPresentation()),
                after: [
                    'type' => $type->value,
                    'amount' => (string) $amount->amount,
                    'tolerance' => (string) $tolerance->amount,
                    'within_tolerance' => $within,
                    'approved_by' => $within ? null : $approvedBy,
                    'journal_entry_id' => (string) $entry->getKey(),
                ],
                reason: $reason,
                entityId: $entityId,
                partyId: $partyId,
            );

            return [
                'id' => $id,
                'type' => $type,
                'amount' => $amount,
                'tolerance' => $tolerance,
                'within_tolerance' => $within,
                'journal_entry_id' => (string) $entry->getKey(),
                'why' => $this->explain($type, $amount, $tolerance, $within),
            ];
        });
    }

    /**
     * Differences by type and by period - PF-164's standing report.
     *
     * By type **and** by period, because the figure that matters is a
     * trend. A bank-charge line of 400 one month is a cost; the same line
     * rising for four months is a bank that repriced, a payment route that
     * should change, or a customer base that started paying by a more
     * expensive method - and none of those are visible in a single total.
     *
     * @return array<string, mixed>
     */
    public function report(string $entityId, ?string $from = null, ?string $until = null): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT type,
                   to_char(arose_on, 'YYYY-MM') AS period,
                   count(*) AS items,
                   sum(amount)::text AS total,
                   sum(CASE WHEN within_tolerance THEN 0 ELSE 1 END) AS approved,
                   max(currency) AS currency
            FROM reconciliation_differences
            WHERE entity_id = ?
              AND (?::date IS NULL OR arose_on >= ?::date)
              AND (?::date IS NULL OR arose_on < ?::date)
            GROUP BY type, to_char(arose_on, 'YYYY-MM')
            ORDER BY to_char(arose_on, 'YYYY-MM'), type
        SQL, [$entityId, $from, $from, $until, $until]);

        $byType = [];
        $byPeriod = [];
        $rising = [];

        foreach ($rows as $row) {
            $type = (string) $row->type;
            $period = (string) $row->period;
            $amount = Money::of((string) $row->total, (string) $row->currency);

            $byType[$type] ??= ['total' => Money::zero((string) $row->currency), 'items' => 0, 'approved' => 0];
            $byType[$type]['total'] = $byType[$type]['total']->plus($amount);
            $byType[$type]['items'] += (int) $row->items;
            $byType[$type]['approved'] += (int) $row->approved;

            $byPeriod[$period][$type] = [
                'total' => (string) $amount->amount,
                'items' => (int) $row->items,
                'approved' => (int) $row->approved,
            ];

            $rising[$type][] = ['period' => $period, 'total' => BigDecimal::of((string) $row->total)];
        }

        return [
            'by_type' => $byType,
            'by_period' => $byPeriod,
            'trends' => $this->trends($rising),
            'why' => $byType === []
                ? 'No differences recorded in this window.'
                : sprintf(
                    '%d type%s of difference across %d month%s. %s',
                    \count($byType),
                    \count($byType) === 1 ? '' : 's',
                    \count($byPeriod),
                    \count($byPeriod) === 1 ? '' : 's',
                    $this->trends($rising) === []
                        ? 'None of them is rising month on month.'
                        : 'A rising line is information: '.implode('; ', $this->trends($rising)),
                ),
        ];
    }

    /**
     * Which types rose in every month of the window.
     *
     * Strictly, in every month. A looser rule - "higher than the first
     * month" - calls a single bad month a trend, and a report that cries
     * wolf about bank charges is one people stop opening.
     *
     * @param  array<string, list<array{period: string, total: BigDecimal}>>  $series
     * @return list<string>
     */
    private function trends(array $series): array
    {
        $rising = [];

        foreach ($series as $type => $points) {
            if (\count($points) < 3) {
                /*
                 | Three months, or it is not a trend.
                 |
                 | Two points are a line through any two numbers. The
                 | smallest number of months that can show a direction is
                 | three, and calling two a trend is how a report loses the
                 | reader it needed.
                 */
                continue;
            }

            $up = true;

            for ($i = 1; $i < \count($points); $i++) {
                if (! $points[$i]['total']->abs()->isGreaterThan($points[$i - 1]['total']->abs())) {
                    $up = false;

                    break;
                }
            }

            if ($up) {
                $rising[] = sprintf(
                    '%s rose in each of %d months, from %s to %s',
                    str_replace('_', ' ', (string) $type),
                    \count($points),
                    (string) $points[0]['total']->abs(),
                    (string) $points[\count($points) - 1]['total']->abs(),
                );
            }
        }

        return $rising;
    }

    private function explain(DifferenceType $type, Money $amount, Money $tolerance, bool $within): string
    {
        $elsewhere = $type->resolvesElsewhere();

        $head = $within
            ? sprintf(
                '%s of %s recorded, within the %s your entity accepts.',
                ucfirst($type->labelEn()),
                (string) $amount->abs()->toPresentation(),
                (string) $tolerance->toPresentation(),
            )
            : sprintf(
                '%s of %s written off above the %s tolerance, with an approver against it.',
                ucfirst($type->labelEn()),
                (string) $amount->abs()->toPresentation(),
                (string) $tolerance->toPresentation(),
            );

        if ($elsewhere === null) {
            return $head;
        }

        return $head.sprintf(
            ' The receivable is closed, but this is not yet a claimable credit - %s.',
            $elsewhere,
        );
    }

    private function entityOf(array $against): string
    {
        $entityId = $against['entity_id'] ?? null;

        if ($entityId !== null) {
            return (string) $entityId;
        }

        throw MatchRefused::because(
            'A difference has to name the entity it belongs to. Without it the tolerance read '.
            'would be some other entity\'s, which is how one company\'s policy comes to govern '.
            'another\'s write-offs.',
        );
    }

}
