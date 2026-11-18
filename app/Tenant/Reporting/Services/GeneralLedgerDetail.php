<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Services;

use App\Support\Money\Money;
use App\Tenant\Reporting\Exceptions\ReportRefused;
use App\Tenant\Reporting\Registers\ReportParameters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The general ledger, line by line - PF-188.
 *
 * ## One query shape, two questions
 *
 * "The detail for account 4100 in May" and "everything the Riyadh cost
 * centre did in May" are the same read with different bounds, so they are
 * the same code. Written as two reports they drift: one learns about the
 * adjusting period and the other does not, one counts reversed entries and
 * the other does not, and the two disagree about the same account with no
 * way to tell which is wrong. {@see activity()} is the report and
 * {@see forAccount()} is that report with one account named.
 *
 * ## The running balance is per ACCOUNT, and only per account
 *
 * A cumulative total down a list that mixes accounts is a figure nobody can
 * use and everybody reads as a balance - the last row of a filtered
 * activity report would look like the closing position of something. So the
 * rows are grouped into one block per account, each block opens with its
 * own balance, and the running column only ever moves within a block.
 *
 * ## The opening balance obeys the filter, and says so
 *
 * This is the part a filtered general ledger usually gets wrong. Take the
 * account's whole opening balance, apply only the cost centre's movements
 * to it, and the closing figure is arithmetic between two different
 * populations: it is not the account's balance and it is not the cost
 * centre's, and it is wrong by whatever the other cost centres were
 * carrying. Nothing on the page says so, and the column that balances is
 * the movement.
 *
 * So the opening is read under the SAME filters as the rows, from the lines
 * before the window, and `opening_basis` says that is what it is. With no
 * filter it is the account's balance and equals the trial balance's figure
 * for that account - which is asserted by a check rather than assumed,
 * because two reads of the same ledger agreeing is the only thing that
 * makes either of them worth printing.
 *
 * ## A filter that names something that does not exist is refused
 *
 * A typed cost centre code, a party id from another screen, a journal that
 * was renamed: each one produces an empty report, and an empty report reads
 * as "nothing happened here". That is the worst available answer, because
 * it is the one a reader acts on. So an unknown filter value is a refusal
 * naming what was not found.
 */
final class GeneralLedgerDetail
{
    /**
     * Which statuses a register counts, and why `reversed` is among them.
     *
     * `reversed` records that a REVERSING ENTRY EXISTS, not that these
     * lines were withdrawn: the original posting happened and is part of
     * the history, and the reversal is a separate entry whose own lines
     * cancel it. Counting only `posted` drops the original while keeping
     * the reversal, so every reversed transaction appears as its own
     * negative - the same reasoning, and the same list, as
     * {@see \App\Tenant\Ledger\Services\TrialBalance}.
     */
    private const COUNTED = ['posted', 'reversed'];

    /**
     * The detail for one account, with its running balance.
     *
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function forAccount(
        string $entityId,
        string $functionalCurrency,
        string $accountCode,
        Carbon $from,
        Carbon $until,
        bool $includeAdjusting = true,
    ): array {
        $report = $this->activity(
            $entityId,
            $functionalCurrency,
            $from,
            $until,
            accountCode: $accountCode,
            includeAdjusting: $includeAdjusting,
        );

        /*
         | The one block is lifted to the top level for a caller that
         | asked about one account.
         |
         | An account with no movement in the window still gets a block,
         | carrying its opening balance and no rows. Returning nothing
         | would read as "this account does not exist", and the two are
         | different answers to a question somebody is about to act on.
         */
        return array_merge($report, [
            'account' => $report['accounts'][0] ?? $this->emptyBlock(
                $entityId,
                $accountCode,
                $functionalCurrency,
                $from,
                $includeAdjusting,
            ),
        ]);
    }

    /**
     * Everything that happened in a window, by account, under filters.
     *
     * @param  array<string, string>|null  $dimensions  axis key => dimension value code
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function activity(
        string $entityId,
        string $functionalCurrency,
        Carbon $from,
        Carbon $until,
        ?string $accountCode = null,
        ?string $partyId = null,
        ?string $journalCode = null,
        ?array $dimensions = null,
        bool $includeAdjusting = true,
    ): array {
        if ($until->lt($from)) {
            throw ReportRefused::because(
                'The window ends before it starts. A register is a list of what happened between two '.
                'dates, and reversed it is empty - which reads as "nothing happened" rather than as '.
                'a reversed window.',
            );
        }

        $filters = $this->resolveFilters($entityId, $accountCode, $partyId, $journalCode, $dimensions);

        $parameters = ReportParameters::for(
            report: 'general_ledger_detail',
            entityId: $entityId,
            from: $from,
            until: $until,
            currency: $functionalCurrency,
            includesAdjusting: $includeAdjusting,
            filters: $filters['stated'],
        );

        $openings = $this->openings($entityId, $from, $includeAdjusting, $filters);
        $rows = $this->rows($entityId, $from, $until, $includeAdjusting, $filters);

        $blocks = [];
        $codes = array_values(array_unique([...array_keys($openings), ...array_keys($rows)]));
        sort($codes);

        $totalDebit = Money::zero($functionalCurrency);
        $totalCredit = Money::zero($functionalCurrency);

        foreach ($codes as $code) {
            $block = $this->block(
                $code,
                $openings[$code] ?? null,
                $rows[$code] ?? [],
                $functionalCurrency,
                $filters['stated'] !== [],
            );

            $totalDebit = $totalDebit->plus(Money::of($block['debits'], $functionalCurrency));
            $totalCredit = $totalCredit->plus(Money::of($block['credits'], $functionalCurrency));

            $blocks[] = $block;
        }

        return [
            'parameters' => $parameters->toArray(),
            'parameter_lines' => $parameters->lines(),
            'columns' => $this->columns(),
            'accounts' => $blocks,
            'total_debits' => (string) $totalDebit->amount,
            'total_credits' => (string) $totalCredit->amount,
            'lines' => array_sum(array_map(
                static fn (array $block): int => \count($block['rows']),
                $blocks,
            )),
            'declaration' => $this->declare($blocks, $filters['stated'], $functionalCurrency),
        ];
    }

    /**
     * The filters, resolved to what the query needs and what a reader sees.
     *
     * Both at once, deliberately. The query needs a column and an id; the
     * page needs "cost centre: Riyadh branch". Resolving them in two places
     * is how a report comes to state a filter it did not apply.
     *
     * @param  array<string, string>|null  $dimensions
     * @return array{
     *     stated: array<string, string>,
     *     account: ?string,
     *     party: ?string,
     *     journal: ?string,
     *     dimensions: array<string, string>,
     * }
     *
     * @throws ReportRefused
     */
    private function resolveFilters(
        string $entityId,
        ?string $accountCode,
        ?string $partyId,
        ?string $journalCode,
        ?array $dimensions,
    ): array {
        $stated = [];
        $resolved = ['account' => null, 'party' => null, 'journal' => null, 'dimensions' => []];

        if ($accountCode !== null) {
            $account = DB::connection('tenant')->table('accounts')
                ->where('entity_id', $entityId)
                ->where('code', $accountCode)
                ->first(['code', 'name_en']);

            if ($account === null) {
                throw ReportRefused::because(sprintf(
                    'This entity has no account "%s". The report is refused rather than returned empty, '.
                    'because an empty general ledger reads as "this account never moved" - which is the '.
                    'answer a reader acts on.',
                    $accountCode,
                ));
            }

            $resolved['account'] = (string) $account->code;
            $stated['account'] = sprintf('%s - %s', (string) $account->code, (string) $account->name_en);
        }

        if ($partyId !== null) {
            $party = DB::connection('tenant')->table('parties')
                ->where('id', $partyId)
                ->value('legal_name');

            if ($party === null) {
                throw ReportRefused::because(
                    'No party with that id. An unknown party filter returns an empty register, which '.
                    'reads as a customer who has done nothing rather than as a filter that found '.
                    'nobody.',
                );
            }

            $resolved['party'] = $partyId;
            $stated['party'] = (string) $party;
        }

        if ($journalCode !== null) {
            $journal = DB::connection('tenant')->table('journals')
                ->where('entity_id', $entityId)
                ->where('code', $journalCode)
                ->first(['code', 'name_en']);

            if ($journal === null) {
                throw ReportRefused::because(sprintf(
                    'This entity has no journal "%s", so nothing could be in it. A journal that was '.
                    'renamed is the usual cause, and an empty register would hide that entirely.',
                    $journalCode,
                ));
            }

            $resolved['journal'] = (string) $journal->code;
            $stated['journal'] = sprintf('%s - %s', (string) $journal->code, (string) $journal->name_en);
        }

        foreach ($dimensions ?? [] as $axisKey => $valueCode) {
            $row = DB::connection('tenant')->selectOne(<<<'SQL'
                SELECT x.axis, x.name_en AS axis_name, v.id, v.code, v.name_en
                FROM dimension_axes x
                JOIN dimension_values v ON v.dimension_axis_id = x.id
                WHERE x.entity_id = cast(? AS char(26))
                  AND x.key = ?
                  AND v.code = ?
            SQL, [$entityId, $axisKey, $valueCode]);

            if ($row === null) {
                throw ReportRefused::because(sprintf(
                    'There is no "%s" on the "%s" axis for this entity. A mistyped dimension code '.
                    'returns a register with nothing in it, and an empty register is read as a cost '.
                    'centre that spent nothing.',
                    $valueCode,
                    $axisKey,
                ));
            }

            /*
             | The axis NUMBER decides the column.
             |
             | `dimension_1` to `dimension_8` are positions, and which
             | axis occupies which position is the entity's decision -
             | held in `dimension_axes.axis`. Hard-coding "cost centre is
             | dimension_1" would read the project axis on an entity that
             | set them up in another order, and every figure would be
             | plausible.
             */
            $axis = (int) $row->axis;

            if ($axis < 1 || $axis > 8) {
                throw ReportRefused::because(sprintf(
                    'The "%s" axis is recorded at position %d, and a journal line carries eight. '.
                    'Reading any other column would filter on a dimension nobody asked for.',
                    $axisKey,
                    $axis,
                ));
            }

            $resolved['dimensions']['dimension_'.$axis] = (string) $row->id;
            $stated['dimension:'.$axisKey] = sprintf('%s - %s', (string) $row->code, (string) $row->name_en);
        }

        return [
            'stated' => $stated,
            'account' => $resolved['account'],
            'party' => $resolved['party'],
            'journal' => $resolved['journal'],
            'dimensions' => $resolved['dimensions'],
        ];
    }

    /**
     * The WHERE clause and its bindings, shared by both reads.
     *
     * One builder, because the opening balance and the rows have to see
     * exactly the same population. Two copies of this clause would be two
     * populations, and the symptom would be an opening balance that does
     * not belong to the rows under it.
     *
     * @param  array{account: ?string, party: ?string, journal: ?string, dimensions: array<string, string>, stated: array<string, string>}  $filters
     * @return array{0: string, 1: array<string, string>}
     */
    private function where(array $filters, bool $includeAdjusting): array
    {
        $clauses = [];
        $bindings = [];

        if ($filters['account'] !== null) {
            $clauses[] = 'a.code = :account';
            $bindings['account'] = $filters['account'];
        }

        if ($filters['party'] !== null) {
            $clauses[] = 'l.party_id = cast(:party AS char(26))';
            $bindings['party'] = $filters['party'];
        }

        if ($filters['journal'] !== null) {
            $clauses[] = 'j.code = :journal';
            $bindings['journal'] = $filters['journal'];
        }

        foreach ($filters['dimensions'] as $column => $valueId) {
            // The column name comes from the axis position, which is an
            // integer this class bounded to 1-8; the value is bound.
            $clauses[] = sprintf('l.%s = cast(:%s AS char(26))', $column, $column);
            $bindings[$column] = $valueId;
        }

        /*
         | The adjusting period shares its calendar days with December, so
         | it cannot be excluded by date - only by the period's own flag.
         | That is the whole reason `is_adjusting` is a column.
         */
        if (! $includeAdjusting) {
            $clauses[] = 'p.is_adjusting = false';
        }

        return [$clauses === [] ? '' : ' AND '.implode(' AND ', $clauses), $bindings];
    }

    /**
     * The balance each account carried into the window, under the filters.
     *
     * @param  array{account: ?string, party: ?string, journal: ?string, dimensions: array<string, string>, stated: array<string, string>}  $filters
     * @return array<string, object>
     */
    private function openings(
        string $entityId,
        Carbon $from,
        bool $includeAdjusting,
        array $filters,
    ): array {
        [$clause, $bindings] = $this->where($filters, $includeAdjusting);
        $statuses = "'".implode("', '", self::COUNTED)."'";

        /*
         | Strictly BEFORE the window opens.
         |
         | A posting dated the first belongs to the period, and an opening
         | balance that included it would report the month's first invoice
         | as something that was always there.
         */
        $rows = DB::connection('tenant')->select(<<<SQL
            SELECT a.code, a.name_ar, a.name_en, a.type,
                   coalesce(sum(l.functional_amount), 0)::text AS balance
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN journals j ON j.id = e.journal_id
            JOIN periods p ON p.id = l.period_id
            JOIN accounts a ON a.id = l.account_id
            WHERE l.entity_id = cast(:entity AS char(26))
              AND e.status IN ({$statuses})
              AND l.accounting_date < cast(:from AS date)
              {$clause}
            GROUP BY a.code, a.name_ar, a.name_en, a.type
        SQL, array_merge($bindings, ['entity' => $entityId, 'from' => $from->toDateString()]));

        $byCode = [];

        foreach ($rows as $row) {
            $byCode[(string) $row->code] = $row;
        }

        return $byCode;
    }

    /**
     * The lines in the window, grouped by account, in a fixed order.
     *
     * @param  array{account: ?string, party: ?string, journal: ?string, dimensions: array<string, string>, stated: array<string, string>}  $filters
     * @return array<string, list<object>>
     */
    private function rows(
        string $entityId,
        Carbon $from,
        Carbon $until,
        bool $includeAdjusting,
        array $filters,
    ): array {
        [$clause, $bindings] = $this->where($filters, $includeAdjusting);
        $statuses = "'".implode("', '", self::COUNTED)."'";

        /*
         | The order ends with the line's own id, and that is not tidiness.
         |
         | Two lines of one entry on one account - a revaluation and its
         | reversal, two instalments of one allocation - are identical on
         | date, journal and entry number. With no further key the
         | executor returns them in whatever order it finds them, which
         | changes with the plan, and the RUNNING BALANCE column changes
         | with it. The figures are right and the report is not
         | reproducible, which is the one thing PF-188 asks of it.
         */
        $rows = DB::connection('tenant')->select(<<<SQL
            SELECT
                l.id AS line_id,
                e.id AS entry_id,
                e.number,
                e.status,
                j.code AS journal_code,
                l.accounting_date::text AS accounting_date,
                a.code AS account_code,
                a.name_ar AS account_name_ar,
                a.name_en AS account_name_en,
                p.is_adjusting,
                e.narration AS entry_narration,
                l.narration AS line_narration,
                e.source_type,
                e.source_id,
                e.posting_rule,
                l.party_id,
                t.legal_name AS party_name,
                l.currency,
                l.amount::text AS amount,
                l.functional_amount::text AS functional_amount
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN journals j ON j.id = e.journal_id
            JOIN periods p ON p.id = l.period_id
            JOIN accounts a ON a.id = l.account_id
            LEFT JOIN parties t ON t.id = l.party_id
            WHERE l.entity_id = cast(:entity AS char(26))
              AND e.status IN ({$statuses})
              AND l.accounting_date BETWEEN cast(:from AS date) AND cast(:until AS date)
              {$clause}
            ORDER BY l.accounting_date, j.code, e.number, l.id
        SQL, array_merge($bindings, [
            'entity' => $entityId,
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
        ]));

        $byAccount = [];

        foreach ($rows as $row) {
            $byAccount[(string) $row->account_code][] = $row;
        }

        return $byAccount;
    }

    /**
     * One account's block: its opening, its lines, its running balance.
     *
     * @param  list<object>  $rows
     * @return array<string, mixed>
     */
    private function block(
        string $code,
        ?object $opening,
        array $rows,
        string $currency,
        bool $filtered,
    ): array {
        $shape = $rows[0] ?? $opening;

        $running = Money::of((string) ($opening->balance ?? '0'), $currency);
        $openingBalance = $running;

        $debits = Money::zero($currency);
        $credits = Money::zero($currency);
        $detail = [];

        foreach ($rows as $row) {
            $amount = Money::of((string) $row->functional_amount, $currency);

            /*
             | The sign becomes two columns HERE and nowhere else.
             |
             | Storage is one signed column - positive is a debit - and a
             | general ledger is read in two. Splitting it in more than
             | one place is how a report comes to show an account in both
             | columns at once.
             */
            $debit = $amount->isPositive() ? $amount : Money::zero($currency);
            $credit = $amount->isNegative() ? $amount->negated() : Money::zero($currency);

            $debits = $debits->plus($debit);
            $credits = $credits->plus($credit);
            $running = $running->plus($amount);

            $detail[] = [
                'line_id' => (string) $row->line_id,
                'entry_id' => (string) $row->entry_id,
                'entry_number' => (string) $row->number,
                'status' => (string) $row->status,
                'journal' => (string) $row->journal_code,
                'date' => (string) $row->accounting_date,
                'is_adjusting' => (bool) $row->is_adjusting,
                'narration' => (string) ($row->line_narration ?? $row->entry_narration ?? ''),
                'source_type' => $row->source_type === null ? null : (string) $row->source_type,
                'source_id' => $row->source_id === null ? null : (string) $row->source_id,
                'posting_rule' => $row->posting_rule === null ? null : (string) $row->posting_rule,
                'party_id' => $row->party_id === null ? null : (string) $row->party_id,
                'party' => $row->party_name === null ? null : (string) $row->party_name,
                'currency' => (string) $row->currency,
                'amount' => (string) $row->amount,
                'debit' => (string) $debit->amount,
                'credit' => (string) $credit->amount,
                'running_balance' => (string) $running->amount,
            ];
        }

        return [
            'code' => $code,
            'name_ar' => (string) ($shape->name_ar ?? $shape->account_name_ar ?? ''),
            'name_en' => (string) ($shape->name_en ?? $shape->account_name_en ?? ''),
            'opening' => (string) $openingBalance->amount,
            /*
             | What the opening balance IS, said on the output.
             |
             | Under a filter it is the filtered population's balance and
             | not the account's, and the two differ by whatever the other
             | cost centres were carrying. A reader who takes the first
             | for the second reconciles it against the trial balance and
             | finds a difference that is not an error.
             */
            'opening_basis' => $filtered
                ? 'the balance of the filtered lines before this window, which is not the account\'s '.
                    'own opening balance'
                : 'the account\'s balance the day before this window opened',
            'rows' => $detail,
            'debits' => (string) $debits->amount,
            'credits' => (string) $credits->amount,
            'movement' => (string) $debits->minus($credits)->amount,
            /*
             | Closing is opening plus the movement, which is the running
             | balance of the last line - the only definition under which
             | the column and the total cannot disagree. Reading the
             | balance again from the ledger would give a second figure
             | that is right in a different way.
             */
            'closing' => (string) $running->amount,
        ];
    }

    /**
     * An account named by a caller that no line and no balance reached.
     *
     * @return array<string, mixed>
     */
    private function emptyBlock(
        string $entityId,
        string $code,
        string $currency,
        Carbon $from,
        bool $includeAdjusting,
    ): array {
        $account = DB::connection('tenant')->table('accounts')
            ->where('entity_id', $entityId)
            ->where('code', $code)
            ->first(['name_ar', 'name_en']);

        return [
            'code' => $code,
            'name_ar' => (string) ($account->name_ar ?? ''),
            'name_en' => (string) ($account->name_en ?? ''),
            'opening' => (string) Money::zero($currency)->amount,
            'opening_basis' => 'nothing had reached this account before the window opened',
            'rows' => [],
            'debits' => (string) Money::zero($currency)->amount,
            'credits' => (string) Money::zero($currency)->amount,
            'movement' => (string) Money::zero($currency)->amount,
            'closing' => (string) Money::zero($currency)->amount,
        ];
    }

    /**
     * The columns, named once, for the page and for the export alike.
     *
     * Declared rather than inferred from the first row, because a report
     * whose columns come from its data has no columns at all when it has no
     * data - and the export of an empty month arrives as a file with no
     * header, which reads as a broken export rather than as a quiet month.
     *
     * @return list<array{key: string, label_ar: string, label_en: string}>
     */
    private function columns(): array
    {
        return [
            ['key' => 'date', 'label_ar' => 'التاريخ', 'label_en' => 'Date'],
            ['key' => 'journal', 'label_ar' => 'اليومية', 'label_en' => 'Journal'],
            ['key' => 'entry_number', 'label_ar' => 'رقم القيد', 'label_en' => 'Entry'],
            ['key' => 'narration', 'label_ar' => 'البيان', 'label_en' => 'Narration'],
            ['key' => 'party', 'label_ar' => 'الطرف', 'label_en' => 'Party'],
            ['key' => 'debit', 'label_ar' => 'مدين', 'label_en' => 'Debit'],
            ['key' => 'credit', 'label_ar' => 'دائن', 'label_en' => 'Credit'],
            ['key' => 'running_balance', 'label_ar' => 'الرصيد الجاري', 'label_en' => 'Running balance'],
        ];
    }

    /**
     * What the reader is told before any figure.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    private function declare(array $blocks, array $filters, string $currency): array
    {
        $lines = array_sum(array_map(static fn (array $block): int => \count($block['rows']), $blocks));

        if ($lines === 0) {
            /*
             | An empty register says WHY it is empty.
             |
             | "Nothing happened" and "your filter excluded everything"
             | are different answers, and the reader acts on the first.
             | The filter values that do not exist are refused before this
             | point, so a filtered empty report means the population is
             | genuinely empty - and saying that is what stops somebody
             | concluding the ledger lost a month.
             */
            return [
                'severity' => 'empty',
                'en' => $filters === []
                    ? 'No posted or reversed line falls in this window. Nothing was filtered out: the '.
                        'window itself is empty.'
                    : sprintf(
                        'No line in this window matches %s. The filters all name things that exist - an '.
                        'unknown value would have been refused - so this population is genuinely empty '.
                        'rather than mistyped.',
                        implode(', ', array_keys($filters)),
                    ),
                'ar' => 'لا يوجد أي سطر مُرحَّل في هذه الفترة وفق هذه المعايير.',
            ];
        }

        return [
            'severity' => 'ok',
            'en' => sprintf(
                '%d line%s across %d account%s. Each account opens with its own balance and the running '.
                'column moves only within that account, so the last row of a block is that account\'s '.
                'closing balance and the last row of the report is not a balance at all. Amounts are in '.
                '%s.',
                $lines,
                $lines === 1 ? '' : 's',
                \count($blocks),
                \count($blocks) === 1 ? '' : 's',
                $currency,
            ),
            'ar' => sprintf(
                '%d سطرًا في %d حسابًا. يبدأ كل حساب برصيده الافتتاحي، والرصيد الجاري يتحرك داخل الحساب '.
                'وحده - فآخر سطر في الحساب هو رصيده الختامي، وآخر سطر في التقرير ليس رصيدًا.',
                $lines,
                \count($blocks),
            ),
        ];
    }
}
