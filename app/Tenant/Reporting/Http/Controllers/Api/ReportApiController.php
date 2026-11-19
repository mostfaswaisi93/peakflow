<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Http\Controllers\Api;

use App\Support\Http\Api\Wire;
use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Tenant\Ledger\Enums\EntryStatus;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Reporting\Exceptions\ReportRefused;
use App\Tenant\Reporting\Services\StatementOfFinancialPosition;
use App\Tenant\Reporting\Services\StatementOfProfitOrLoss;
use App\Tenant\Reporting\Services\TrialBalanceReport;
use App\Tenant\Tenancy\Exceptions\EntityNotInScope;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The financial statements, over the wire - PF-263.
 *
 * The same three services the browser's report pages call, with the same
 * period rules, so a figure on a phone is the figure on the desk. The
 * services return their amounts as decimal strings in the functional
 * currency; on the way out every one of them becomes a `Money` object,
 * named in {@see self::MONEY} rather than guessed at from its shape.
 *
 * ## A period is chosen the way the screen chooses it
 *
 * `?period=` names one, from the non-adjusting periods. Without it, the
 * latest period that has anything posted in it - an empty current month
 * would otherwise be the default, and a report of nothing is the answer to
 * nobody's question.
 */
final class ReportApiController
{
    /**
     * The members that are money, in all three reports.
     *
     * Named, not inferred: a member that happens to hold a decimal string is
     * not thereby money - a tax rate is not, an exchange rate is not - and
     * converting by shape is how a rate would arrive as "SAR 0.1500".
     */
    private const MONEY = [
        'opening', 'movement', 'closing', 'total_debit', 'total_credit',
        'amount', 'comparative', 'subtotal', 'total', 'comparative_total',
        'profit_for_the_period', 'profit_for_the_period_comparative',
        'total_comprehensive_income', 'total_comprehensive_income_comparative',
        'assets', 'liabilities', 'equity', 'liabilities_and_equity', 'difference', 'unclassified',
    ];

    public function __construct(private readonly EntityContext $entities) {}

    /** The periods a report may be asked for, newest first. */
    public function periods(): JsonResponse
    {
        Gate::authorize('report.view');

        $withPostings = $this->periodsWithPostings();

        return new JsonResponse([
            'data' => $this->choosable()->map(static fn (Period $period): array => [
                'resource' => 'period',
                'id' => (string) $period->id,
                'label' => (string) $period->label,
                'starts_on' => Wire::accountingDate($period->starts_on),
                'ends_on' => Wire::accountingDate($period->ends_on),
                'state' => $period->state->value,
                'has_postings' => \in_array((string) $period->id, $withPostings, true),
            ])->values()->all(),
        ]);
    }

    public function trialBalance(Request $request, TrialBalanceReport $report): JsonResponse|Problem
    {
        Gate::authorize('report.view');

        [$entity, $period] = $this->scope($request);

        try {
            $built = $report->forPeriod(
                entityId: (string) $entity->id,
                functionalCurrency: (string) $entity->functional_currency,
                from: $period->starts_on,
                until: $period->ends_on,
            );
        } catch (ReportRefused $refused) {
            return $this->refused($refused);
        }

        return $this->respond('trial_balance', $entity, $period, null, $built);
    }

    public function profitOrLoss(Request $request, StatementOfProfitOrLoss $statement): JsonResponse|Problem
    {
        Gate::authorize('report.view');

        [$entity, $period] = $this->scope($request);
        $comparative = $this->comparative($request, $period, 'profit_or_loss');

        try {
            $built = $statement->forPeriod(
                entityId: (string) $entity->id,
                functionalCurrency: (string) $entity->functional_currency,
                from: $period->starts_on,
                until: $period->ends_on,
                comparativeFrom: $comparative?->starts_on,
                comparativeUntil: $comparative?->ends_on,
            );
        } catch (ReportRefused $refused) {
            return $this->refused($refused);
        }

        return $this->respond('profit_or_loss', $entity, $period, $comparative, $built);
    }

    public function financialPosition(Request $request, StatementOfFinancialPosition $statement): JsonResponse|Problem
    {
        Gate::authorize('report.view');

        [$entity, $period] = $this->scope($request);
        $comparative = $this->comparative($request, $period, 'financial_position');

        try {
            $built = $statement->asAt(
                entityId: (string) $entity->id,
                functionalCurrency: (string) $entity->functional_currency,
                from: $period->starts_on,
                until: $period->ends_on,
                comparativeFrom: $comparative?->starts_on,
                comparativeUntil: $comparative?->ends_on,
            );
        } catch (ReportRefused $refused) {
            return $this->refused($refused);
        }

        return $this->respond('financial_position', $entity, $period, $comparative, $built);
    }

    /**
     * @param  array<mixed>  $built
     */
    private function respond(string $resource, Entity $entity, Period $period, ?Period $comparative, array $built): JsonResponse
    {
        $currency = (string) $entity->functional_currency;

        return new JsonResponse([
            'resource' => $resource,
            'entity_id' => (string) $entity->id,
            'currency' => $currency,
            'period' => $this->periodShape($period),
            'comparative_period' => $comparative === null ? null : $this->periodShape($comparative),
            'report' => $this->moneyIn($built, $currency),
        ]);
    }

    /**
     * Every member named in MONEY, wherever it sits, as Money.
     *
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private function moneyIn(array $node, string $currency): array
    {
        $out = [];

        foreach ($node as $key => $value) {
            if (\is_array($value)) {
                $out[$key] = $this->moneyIn($value, $currency);
            } elseif (\is_string($key) && \in_array($key, self::MONEY, true) && \is_string($value)
                && preg_match('/^-?\d{1,17}(\.\d{1,4})?$/', $value) === 1) {
                $out[$key] = Wire::amount($value, $currency);
            } elseif ($value instanceof \DateTimeInterface) {
                $out[$key] = Wire::accountingDate($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array{0: Entity, 1: Period}
     */
    private function scope(Request $request): array
    {
        $entity = $this->entities->entity();

        if (! $entity instanceof Entity) {
            throw EntityNotInScope::forQueryOn(Period::class);
        }

        $periods = $this->choosable();
        $asked = $request->query('period');

        if (\is_string($asked) && $asked !== '') {
            $period = $periods->firstWhere('id', $asked);

            if (! $period instanceof Period) {
                abort(404);
            }

            return [$entity, $period];
        }

        $withPostings = $this->periodsWithPostings();

        $period = $periods->first(static fn (Period $candidate): bool => \in_array((string) $candidate->id, $withPostings, true))
            ?? $periods->first();

        if (! $period instanceof Period) {
            abort(404);
        }

        return [$entity, $period];
    }

    /**
     * The comparative: `?comparative=<period id>`, or `none`.
     *
     * Without one, the same period a year earlier - the screen's default -
     * and none at all when there is no such period, rather than an error.
     */
    private function comparative(Request $request, Period $period, string $report): ?Period
    {
        $asked = $request->query('comparative');

        if ($asked === 'none') {
            return null;
        }

        $earlier = $this->choosable()->filter(static fn (Period $candidate): bool => $report === 'profit_or_loss'
            ? $candidate->ends_on->lt($period->starts_on)
            : $candidate->ends_on->lt($period->ends_on));

        if (\is_string($asked) && $asked !== '') {
            $chosen = $earlier->firstWhere('id', $asked);

            if (! $chosen instanceof Period) {
                abort(404);
            }

            return $chosen;
        }

        $lastYear = $period->starts_on->copy()->subYearNoOverflow()->toDateString();

        $found = $earlier->first(static fn (Period $candidate): bool => $candidate->starts_on->toDateString() === $lastYear);

        return $found instanceof Period ? $found : null;
    }

    /** @return Collection<int, Period> */
    private function choosable(): Collection
    {
        return Period::query()->where('is_adjusting', false)->orderByDesc('starts_on')->get();
    }

    /** @return list<string> */
    private function periodsWithPostings(): array
    {
        return array_values(JournalEntry::query()
            ->whereIn('status', [EntryStatus::Posted->value, EntryStatus::Reversed->value])
            ->distinct()
            ->pluck('period_id')
            ->map(static fn (mixed $id): string => \is_string($id) ? $id : '')
            ->all());
    }

    /** @return array<string, mixed> */
    private function periodShape(Period $period): array
    {
        return [
            'id' => (string) $period->id,
            'label' => (string) $period->label,
            'starts_on' => Wire::accountingDate($period->starts_on),
            'ends_on' => Wire::accountingDate($period->ends_on),
        ];
    }

    private function refused(ReportRefused $refused): Problem
    {
        return Problem::of(ProblemType::ValidationFailed)->withDetail($refused->getMessage());
    }
}
