<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Reporting\Overview\Ageing;
use App\Tenant\Reporting\Overview\Overview;
use App\Tenant\Reporting\Overview\OverviewQuery;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\IssuesInvoices;

/**
 * The Overview: what it counts, and what it costs - UX-032.
 *
 * Run against a real tenant inside a transaction that is rolled back, with
 * documents issued through the real Issuer, so the figures are checked
 * against the definitions the rest of the application uses rather than
 * against the dashboard's own idea of them.
 *
 * ## The query count is measured, not reasoned about
 *
 * "Every figure is one aggregate" is a claim about the code today; the first
 * tile that loops over documents to add them up in PHP breaks it silently and
 * fast pages become slow ones at month-end. So the page is built with no
 * documents and again with several, and the count must be the same
 * constant both times.
 */
final class OverviewRules
{
    use IssuesInvoices;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['the overview (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);

        $connection = DB::connection('tenant');
        $this->connection = $connection;
        $connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->runEachInASavepoint([
                'an entity with nothing in it gets the welcome, not a grid of zeros' => fn (): array => $this->emptyIsWelcome(),
                'the query count is a constant, not a function of the documents' => fn (): array => $this->queryCountIsConstant(),
                'an issued invoice is receivable, in the bucket its due date puts it' => fn (): array => $this->receivablesAge(),
                'a draft is counted as a draft, and is not receivable' => fn (): array => $this->draftsAreDrafts(),
            ]);
        } finally {
            $connection->rollBack();
        }
    }

    /** @return list<string> */
    private function emptyIsWelcome(): array
    {
        $overview = $this->overview();

        return $overview->isEmpty()
            ? []
            : ['A freshly seeded entity with no documents and no postings was not treated as empty.'];
    }

    /** @return list<string> */
    private function queryCountIsConstant(): array
    {
        $empty = $this->countQueries();

        foreach (range(1, 4) as $ignored) {
            $this->issue();
        }

        $busy = $this->countQueries();
        $problems = [];

        if ($empty !== OverviewQuery::QUERIES || $busy !== OverviewQuery::QUERIES) {
            $problems[] = \sprintf(
                'The Overview ran %d queries with no documents and %d with four; it is documented as %d. '.
                'A count that moves with the data is a page that slows down as the books grow.',
                $empty,
                $busy,
                OverviewQuery::QUERIES,
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function receivablesAge(): array
    {
        $this->issue();
        $this->issue();

        $problems = [];
        $issueDay = CarbonImmutable::parse($this->date->toDateString());

        // On the day of issue nothing is past due.
        $sameDay = $this->overview($issueDay)->receivables;

        if ($sameDay->documents !== 2) {
            $problems[] = \sprintf('Two issued invoices were counted as %d open documents.', $sameDay->documents);
        }

        if (! BigDecimal::of($sameDay->buckets['current'])->isEqualTo('2300')) {
            $problems[] = \sprintf(
                'On the issue date the current bucket was %s; two invoices of 1,150.00 are 2,300.00 not yet due.',
                $sameDay->buckets['current'],
            );
        }

        // Far enough later that every due date has passed by over ninety days.
        $later = $this->overview($issueDay->addDays(400))->receivables;

        if (! BigDecimal::of($later->buckets['90+'])->isEqualTo('2300') || ! BigDecimal::of($later->buckets['current'])->isZero()) {
            $problems[] = \sprintf(
                'Four hundred days later the 90+ bucket was %s and current %s; all 2,300.00 should be over ninety days past due.',
                $later->buckets['90+'],
                $later->buckets['current'],
            );
        }

        if (! BigDecimal::of($later->total())->isEqualTo($later->overdue())) {
            $problems[] = 'Everything was past due, but overdue() did not equal total().';
        }

        if (array_keys($later->buckets) !== Ageing::BUCKETS) {
            $problems[] = 'The buckets are not in the order ExpectedCreditLoss and PayablesAging use.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function draftsAreDrafts(): array
    {
        $this->draft();

        $overview = $this->overview();
        $problems = [];

        if ($overview->draftInvoices !== 1) {
            $problems[] = \sprintf('One draft invoice was counted as %d.', $overview->draftInvoices);
        }

        if ($overview->receivables->documents !== 0) {
            $problems[] = 'A draft invoice was counted as receivable. It has not been issued; nobody owes it yet.';
        }

        if ($overview->isEmpty()) {
            $problems[] = 'An entity with a draft invoice was shown the welcome, as if nothing had started.';
        }

        return $problems;
    }

    private function overview(?CarbonImmutable $today = null): Overview
    {
        return app(OverviewQuery::class)->for($this->entity(), $today ?? CarbonImmutable::parse($this->date->toDateString()));
    }

    /**
     * The tenant connection's own query log, as TrialBalanceBenchmark counts:
     * it holds exactly the statements the Overview sent and nothing that ran
     * before or after it.
     */
    private function countQueries(): int
    {
        $connection = DB::connection('tenant');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $this->overview();

            return \count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }
}
