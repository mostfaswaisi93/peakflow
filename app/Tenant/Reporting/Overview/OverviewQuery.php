<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Overview;

use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Overview for one entity - UX-032.
 *
 * ## Eight queries, whatever the size of the books
 *
 * Every figure is an aggregate computed IN PostgreSQL, so the page costs the
 * same number of round trips for an entity with ten lines as for one with ten
 * million: two for cash (the opening balance, then the days), one each for
 * receivables, payables, reconciliation, drafts, the tax period and the
 * activity. `php bin/verify reporting` (tests/Support/OverviewRules.php)
 * runs it and holds the count at QUERIES.
 *
 * ## Read-only, and reading everything
 *
 * Reporting may read every module and nothing may depend on it (deptrac.yaml).
 * It reads tables directly rather than through each module's models because
 * the figures are aggregates, and loading documents to add them up in PHP is
 * the query budget failing in the most expensive way.
 *
 * ## The definitions it relies on, rather than repeating
 *
 * - Posted means `status IN ('posted', 'reversed')`: a reversal is its own
 *   entry that cancels the original, so both count (TrialBalance).
 * - An open document balance is `peakflow_document_open_balance()`, the one
 *   definition in the database (migration 009300).
 * - Days past due come from the due dates FROZEN in the document's terms
 *   snapshot, as ExpectedCreditLoss does, so changing a customer's terms
 *   later does not restate what was overdue.
 * - A subtree is read as a path RANGE, never a LIKE (AccountTree).
 *
 * Every query filters `entity_id` itself. Row-level security also does on
 * most tables, but not on `audit_log`, and a dashboard that relied on RLS
 * for one tile and not another would be correct by accident.
 */
final class OverviewQuery
{
    /** The cash sparkline's window, and the trend's comparison point. */
    public const int CASH_DAYS = 90;

    public const int TREND_DAYS = 30;

    /** How far back bank lines count towards reconciliation progress. */
    public const int RECONCILIATION_DAYS = 90;

    public const int ACTIVITY_ITEMS = 6;

    /** The whole page's database round trips. A change here is a decision. */
    public const int QUERIES = 8;

    public function for(Entity $entity, ?CarbonImmutable $today = null): Overview
    {
        $today ??= CarbonImmutable::now($this->timezone($entity))->startOfDay();
        $entityId = self::text(['id' => $entity->getKey()], 'id', '');
        $currency = self::text(['currency' => $entity->getAttribute('functional_currency')], 'currency', '');

        return new Overview(
            currency: $currency,
            cash: $this->cash($entityId, $today),
            receivables: $this->receivables($entityId, $currency, $today),
            payables: $this->payables($entityId, $currency, $today),
            reconciliation: $this->reconciliation($entityId, $today),
            draftInvoices: ($drafts = $this->drafts($entityId))['invoices'],
            draftBills: $drafts['bills'],
            draftJournals: $drafts['journals'],
            tax: $this->tax($entityId, $today),
            activity: $this->activity($entityId),
        );
    }

    private function cash(string $entityId, CarbonImmutable $today): CashPosition
    {
        $from = $today->subDays(self::CASH_DAYS - 1);

        // The bank account and its subtree, as a path range.
        $accounts = <<<'SQL'
            SELECT a.id
            FROM accounts a
            JOIN account_roles r ON r.entity_id = a.entity_id AND r.role = 'bank'
            JOIN accounts root ON root.id = r.account_id
            WHERE a.entity_id = cast(? AS char(26))
              AND a.path >= root.path
              AND a.path < root.path || '/'
        SQL;

        $opening = $this->db()->selectOne(<<<SQL
            SELECT coalesce(sum(l.functional_amount), 0)::text AS balance,
                   count(*) > 0 AS any_line
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id AND e.status IN ('posted', 'reversed')
            WHERE l.entity_id = cast(? AS char(26))
              AND l.accounting_date < ?::date
              AND l.account_id IN ({$accounts})
        SQL, [$entityId, $from->toDateString(), $entityId]);

        $days = $this->db()->select(<<<SQL
            SELECT l.accounting_date::text AS day, sum(l.functional_amount)::text AS net
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id AND e.status IN ('posted', 'reversed')
            WHERE l.entity_id = cast(? AS char(26))
              AND l.accounting_date BETWEEN ?::date AND ?::date
              AND l.account_id IN ({$accounts})
            GROUP BY l.accounting_date
        SQL, [$entityId, $from->toDateString(), $today->toDateString(), $entityId]);

        $net = [];

        foreach ($days as $row) {
            $fields = self::fields($row);
            $net[self::text($fields, 'day', '')] = self::text($fields, 'net');
        }

        $opening = self::fields($opening);
        $running = BigDecimal::of(self::text($opening, 'balance'));
        $series = [];

        for ($day = $from; $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            $running = $running->plus($net[$day->toDateString()] ?? '0');
            $series[] = (string) $running->toScale(4);
        }

        $then = $series[\count($series) - 1 - self::TREND_DAYS] ?? null;

        return new CashPosition(
            balance: (string) end($series),
            series: $series,
            balanceThen: $then,
            days: self::CASH_DAYS,
            hasActivity: ($opening['any_line'] ?? false) === true || $net !== [],
        );
    }

    private function receivables(string $entityId, string $currency, CarbonImmutable $today): Ageing
    {
        $row = $this->db()->selectOne(<<<'SQL'
            WITH open AS (
                SELECT i.currency,
                       peakflow_document_open_balance('invoice', i.id) AS balance,
                       (?::date - coalesce((
                           SELECT max((d->>'date')::date)
                           FROM jsonb_array_elements(i.terms_snapshot->'due_dates') d
                       ), i.issue_date)) AS days_past_due
                FROM invoices i
                WHERE i.entity_id = cast(? AS char(26))
                  AND i.status = 'issued'
                  AND i.document_type IN ('380', '383', '386')
                  AND i.issue_date <= ?::date
            )
            SELECT
                count(*) FILTER (WHERE currency = ? AND balance <> 0) AS documents,
                count(*) FILTER (WHERE currency <> ? AND balance <> 0) AS other_currency,
                coalesce(sum(balance) FILTER (WHERE currency = ? AND days_past_due <= 0), 0)::text AS b_current,
                coalesce(sum(balance) FILTER (WHERE currency = ? AND days_past_due BETWEEN 1 AND 30), 0)::text AS b_30,
                coalesce(sum(balance) FILTER (WHERE currency = ? AND days_past_due BETWEEN 31 AND 60), 0)::text AS b_60,
                coalesce(sum(balance) FILTER (WHERE currency = ? AND days_past_due BETWEEN 61 AND 90), 0)::text AS b_90,
                coalesce(sum(balance) FILTER (WHERE currency = ? AND days_past_due > 90), 0)::text AS b_over
            FROM open
        SQL, [
            $today->toDateString(), $entityId, $today->toDateString(),
            $currency, $currency, $currency, $currency, $currency, $currency, $currency,
        ]);

        $row = self::fields($row);

        return new Ageing(
            buckets: [
                'current' => self::text($row, 'b_current'),
                '1-30' => self::text($row, 'b_30'),
                '31-60' => self::text($row, 'b_60'),
                '61-90' => self::text($row, 'b_90'),
                '90+' => self::text($row, 'b_over'),
            ],
            documents: self::count($row, 'documents'),
            otherCurrencyDocuments: self::count($row, 'other_currency'),
        );
    }

    private function payables(string $entityId, string $currency, CarbonImmutable $today): PayablesDue
    {
        $row = $this->db()->selectOne(<<<'SQL'
            WITH open AS (
                SELECT b.currency,
                       peakflow_document_open_balance('bill', b.id) AS balance,
                       coalesce((
                           SELECT max((d->>'date')::date)
                           FROM jsonb_array_elements(b.terms_snapshot->'due_dates') d
                       ), b.supplier_document_date) AS due_on
                FROM bills b
                WHERE b.entity_id = cast(? AS char(26))
                  AND b.status IN ('approved', 'posted')
                  AND b.document_type IN ('380', '383')
            )
            SELECT
                count(*) FILTER (WHERE currency = ? AND balance <> 0) AS documents,
                count(*) FILTER (WHERE currency <> ? AND balance <> 0) AS other_currency,
                count(*) FILTER (WHERE currency = ? AND balance <> 0 AND due_on BETWEEN ?::date AND ?::date) AS due_week_documents,
                coalesce(sum(balance) FILTER (WHERE currency = ?), 0)::text AS total,
                coalesce(sum(balance) FILTER (WHERE currency = ? AND due_on < ?::date), 0)::text AS overdue,
                coalesce(sum(balance) FILTER (WHERE currency = ? AND due_on BETWEEN ?::date AND ?::date), 0)::text AS due_week
            FROM open
        SQL, [
            $entityId,
            $currency, $currency,
            $currency, $today->toDateString(), $today->addDays(6)->toDateString(),
            $currency,
            $currency, $today->toDateString(),
            $currency, $today->toDateString(), $today->addDays(6)->toDateString(),
        ]);

        $row = self::fields($row);

        return new PayablesDue(
            total: self::text($row, 'total'),
            overdue: self::text($row, 'overdue'),
            dueThisWeek: self::text($row, 'due_week'),
            documents: self::count($row, 'documents'),
            dueThisWeekDocuments: self::count($row, 'due_week_documents'),
            otherCurrencyDocuments: self::count($row, 'other_currency'),
        );
    }

    private function reconciliation(string $entityId, CarbonImmutable $today): ReconciliationProgress
    {
        // Bounded on booking_date, the partition key, so only the window's
        // partitions are read.
        $row = $this->db()->selectOne(<<<'SQL'
            SELECT
                count(*) FILTER (WHERE s.matched) AS matched,
                count(*) FILTER (WHERE NOT s.matched AND s.proposed) AS suggested,
                count(*) FILTER (WHERE NOT s.matched AND NOT s.proposed) AS waiting
            FROM bank_transactions t
            CROSS JOIN LATERAL (
                SELECT
                    EXISTS (
                        SELECT 1 FROM matches m
                        WHERE m.bank_transaction_id = t.id
                          AND m.booking_date = t.booking_date
                          AND m.reversed_at IS NULL
                    ) AS matched,
                    EXISTS (
                        SELECT 1 FROM match_proposals p
                        WHERE p.bank_transaction_id = t.id
                          AND p.booking_date = t.booking_date
                          AND p.status = 'proposed'
                    ) AS proposed
            ) s
            WHERE t.entity_id = cast(? AS char(26))
              AND t.booking_date BETWEEN ?::date AND ?::date
              AND t.is_provisional = false
              AND t.superseded_by_id IS NULL
        SQL, [$entityId, $today->subDays(self::RECONCILIATION_DAYS - 1)->toDateString(), $today->toDateString()]);

        $row = self::fields($row);

        return new ReconciliationProgress(
            matched: self::count($row, 'matched'),
            suggested: self::count($row, 'suggested'),
            waiting: self::count($row, 'waiting'),
            days: self::RECONCILIATION_DAYS,
        );
    }

    /** @return array{invoices: int, bills: int, journals: int} */
    private function drafts(string $entityId): array
    {
        $row = $this->db()->selectOne(<<<'SQL'
            SELECT
                (SELECT count(*) FROM invoices WHERE entity_id = cast(? AS char(26)) AND status = 'draft') AS invoices,
                (SELECT count(*) FROM bills WHERE entity_id = cast(? AS char(26)) AND status IN ('draft', 'awaiting_approval')) AS bills,
                (SELECT count(*) FROM journal_entries WHERE entity_id = cast(? AS char(26)) AND status = 'draft') AS journals
        SQL, [$entityId, $entityId, $entityId]);

        $row = self::fields($row);

        return [
            'invoices' => self::count($row, 'invoices'),
            'bills' => self::count($row, 'bills'),
            'journals' => self::count($row, 'journals'),
        ];
    }

    private function tax(string $entityId, CarbonImmutable $today): ?TaxPeriodDue
    {
        $row = $this->db()->selectOne(<<<'SQL'
            SELECT label, due_on::text AS due_on
            FROM return_periods
            WHERE entity_id = cast(? AS char(26))
              AND state = 'open'
            ORDER BY upper(period) ASC, label ASC
            LIMIT 1
        SQL, [$entityId]);

        $row = self::fields($row);

        if ($row === []) {
            return null;
        }

        $dueOn = self::text($row, 'due_on', '');

        return new TaxPeriodDue(
            label: self::text($row, 'label', ''),
            dueOn: $dueOn !== '' ? CarbonImmutable::parse($dueOn, $today->getTimezone())->startOfDay() : null,
            today: $today,
        );
    }

    /** @return list<ActivityItem> */
    private function activity(string $entityId): array
    {
        // `audit_log` has no entity row-level security, so entity_id is
        // filtered here or not at all; bounded on created_at, its partition
        // key, so old months are never opened.
        $rows = $this->db()->select(<<<'SQL'
            SELECT actor_name, action, subject_label, created_at::text AS at
            FROM audit_log
            WHERE entity_id = cast(? AS char(26))
              AND chain = 'events'
              AND created_at >= now() - interval '90 days'
            ORDER BY created_at DESC
            LIMIT ?
        SQL, [$entityId, self::ACTIVITY_ITEMS]);

        $items = [];

        foreach ($rows as $row) {
            $row = self::fields($row);
            $subject = self::text($row, 'subject_label', '');

            $items[] = new ActivityItem(
                actor: self::text($row, 'actor_name', ''),
                action: self::text($row, 'action', ''),
                subject: $subject !== '' ? $subject : null,
                at: CarbonImmutable::parse(self::text($row, 'at', 'now')),
            );
        }

        return $items;
    }

    private function timezone(Entity $entity): string
    {
        $timezone = $entity->getAttribute('timezone');

        if (\is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

        $default = config('app.timezone');

        return \is_string($default) ? $default : 'UTC';
    }

    /**
     * A result row's columns. Raw selects come back as stdClass, which static
     * analysis cannot see into; these read each column with its type checked
     * rather than cast blind.
     *
     * @return array<string, mixed>
     */
    private static function fields(mixed $row): array
    {
        $fields = [];

        foreach (\is_object($row) ? get_object_vars($row) : [] as $column => $value) {
            $fields[(string) $column] = $value;
        }

        return $fields;
    }

    /**
     * A column as a string. Amounts arrive as `::text`, so they are never
     * floats on the way in.
     *
     * @param  array<string, mixed>  $fields
     */
    private static function text(array $fields, string $column, string $default = '0'): string
    {
        $value = $fields[$column] ?? null;

        return \is_string($value) || \is_int($value) ? (string) $value : $default;
    }

    /** @param  array<string, mixed>  $fields */
    private static function count(array $fields, string $column): int
    {
        $value = $fields[$column] ?? null;

        if (\is_int($value)) {
            return $value;
        }

        return \is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }

    private function db(): Connection
    {
        return DB::connection('tenant');
    }
}
