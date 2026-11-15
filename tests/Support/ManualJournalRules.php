<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Tenant\Catalog\Services\CurrencyPolicy;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Manual\DraftLine;
use App\Tenant\Ledger\Exceptions\ImportRefused;
use App\Tenant\Ledger\Manual\JournalImport;
use App\Tenant\Ledger\Manual\JournalTemplates;
use App\Tenant\Ledger\Manual\ManualJournal;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Ledger\Services\TrialBalance;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Manual journal entry: validation, templates and import - PF-055.
 *
 * ## What is being checked, and what is not
 *
 * The screen itself - the keyboard-operable grid - is Phase 11's, and it
 * depends on a design system that does not exist yet. What a grid needs from
 * the ledger exists and is checked here: a running imbalance on every
 * keystroke, a problem per line with its field named, and a refusal that
 * explains rather than hides.
 *
 * ## The imbalance is the whole value of the screen
 *
 * "This entry does not balance" is useless while somebody is typing. The
 * amount it is out BY is what tells them which mistake they made, and the
 * shape of the number says which: 90 is a transposition, twice the amount is
 * a sign the wrong way round, a round figure is a missing line.
 */
final class ManualJournalRules
{
    use Concerns\ProvesTheLedger;

    private ?Connection $connection = null;

    private Entity $entity;

    private string $entityId = '';

    private Carbon $date;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return ['manual journal entry (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return $this->withTheLedgerProof([
                'the imbalance is a figure, on every keystroke' => $this->theImbalanceIsAFigure(),
                'a control account is refused and the refusal explains' => $this->controlAccountsAreExplained(),
                'an amount that cannot be read is named, not guessed' => $this->amountsAreReadStrictly(),
                'a template is the shape, and one figure drives it' => $this->templatesApportion(),
                'a template refuses what would break every entry made from it' => $this->templatesAreChecked(),
                'an import reports every rejected row before writing any' => $this->theDryRunIsComplete(),
                'a file posts whole or not at all' => $this->theFilePostsWhole(),
            ], $this->entityId);
        } catch (Throwable $e) {
            return ['manual journal entry could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 240),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function theImbalanceIsAFigure(): array
    {
        $violations = [];
        $journal = app(ManualJournal::class);

        $balanced = $journal->validate($this->entity, $this->draft([
            ['1.1.1.1', '1500.00'],
            ['4.1', '-1500.00'],
            ['', ''],
        ]));

        if (! $balanced->postable) {
            $violations[] = sprintf(
                'a balanced pair with a blank row at the bottom is not postable: %s',
                implode('; ', array_map(
                    static fn ($p): string => $p->messageEn,
                    $balanced->problems,
                )),
            );
        }

        if ($balanced->blankLines !== 1) {
            $violations[] = 'the blank row at the bottom of the grid was not counted as blank. It is '.
                'normal and is not an error until somebody tries to post';
        }

        /*
         | Out by 90, which is the shape of a transposition.
         |
         | The figure is what tells somebody which mistake they made, and the
         | side is what saves them reading the sign convention. A boolean
         | would tell them neither.
         */
        $transposed = $journal->validate($this->entity, $this->draft([
            ['1.1.1.1', '1,500.00'],
            ['4.1', '-1410.00'],
        ]));

        if (! BigDecimal::of((string) $transposed->imbalance->amount)->isEqualTo(BigDecimal::of('90.0000'))) {
            $violations[] = sprintf(
                'a transposition of 1500 against 1410 reports an imbalance of %s where 90.0000 belongs',
                (string) $transposed->imbalance->amount,
            );
        }

        if ($transposed->imbalanceSide() !== 'credit') {
            $violations[] = sprintf(
                'the entry is short on the credit side and reports "%s". A signed figure needs reading '.
                'and nobody should have to remember the storage convention to read a form',
                (string) $transposed->imbalanceSide(),
            );
        }

        if ($transposed->problems !== []) {
            $violations[] = 'an entry whose lines are each fine and do not sum to zero reported a problem '.
                'with a line. A screen highlighting them all would be pointing at two correct rows';
        }

        /*
         | And a thousands separator is a number.
         |
         | Somebody typing 1,500.00 has typed a number and the form should
         | take it; the imbalance above proves it did.
         */
        if (! BigDecimal::of((string) $transposed->debits->amount)->isEqualTo(BigDecimal::of('1500.0000'))) {
            $violations[] = sprintf(
                '"1,500.00" was read as %s. A thousands separator is how people type',
                (string) $transposed->debits->amount,
            );
        }

        // Arabic-Indic digits and the Arabic decimal separator, likewise.
        $arabic = $journal->validate($this->entity, $this->draft([
            ['1.1.1.1', '١٢٬٥٠٠٫٥٠'],
            ['4.1', '-12500.50'],
        ]));

        if (! $arabic->postable) {
            $violations[] = 'an amount typed in Arabic-Indic digits with Arabic separators was not read. '.
                'It is how the keyboard in both target markets produces numbers';
        }

        $oneLine = $journal->validate($this->entity, $this->draft([['1.1.1.1', '100.00']]));

        if ($oneLine->postable) {
            $violations[] = 'a single line was postable as a whole entry';
        }

        return $violations;
    }

    /** @return list<string> */
    public function controlAccountsAreExplained(): array
    {
        $violations = [];

        $verdict = app(ManualJournal::class)->validate($this->entity, $this->draft([
            ['1.1.2.1', '100.00'],
            ['1.1', '-100.00'],
        ]));

        $byCode = [];

        foreach ($verdict->problems as $problem) {
            $byCode[$problem->code] = $problem;
        }

        if (! isset($byCode['account_is_a_control'])) {
            $violations[] = 'a control account was accepted on a manual line. The subledger IS the '.
                'receivable, and a manual posting makes the two disagree with nothing afterwards able to '.
                'say which is right';
        } else {
            $message = $byCode['account_is_a_control']->messageEn;

            /*
             | The refusal has to EXPLAIN, which is the criterion.
             |
             | Hiding the account from the picker is what gets built by
             | default - it makes the screen simpler - and it leaves somebody
             | hunting for an account they can see in the chart. So the
             | message has to say what the account is, what would break, and
             | what to do instead.
             */
            foreach ([
                'subledger' => 'what the account is',
                'disagree' => 'what would break',
                'counter account' => 'what to do instead',
            ] as $needle => $what) {
                if (! str_contains($message, $needle)) {
                    $violations[] = sprintf(
                        'the control-account refusal does not say %s. PF-055 asks the UI to explain '.
                        'rather than hide, and "not allowed" is the version that gets built: %s',
                        $what,
                        Str::limit($message, 90),
                    );
                }
            }

            if ($byCode['account_is_a_control']->field !== 'account_code') {
                $violations[] = 'the control-account problem does not name the account field, so the '.
                    'message cannot land next to the input that caused it';
            }

            if ($byCode['account_is_a_control']->line !== 1) {
                $violations[] = 'the control-account problem does not name line 1, so the message cannot '.
                    'land in the right row';
            }

            if ($byCode['account_is_a_control']->messageAr === $message) {
                $violations[] = 'the control-account refusal is not translated. It is the message most '.
                    'likely to be read by somebody who does not read English';
            }
        }

        if (! isset($byCode['account_is_a_heading'])) {
            $violations[] = 'a group heading was accepted on a manual line';
        }

        return $violations;
    }

    /** @return list<string> */
    public function amountsAreReadStrictly(): array
    {
        $violations = [];

        $verdict = app(ManualJournal::class)->validate($this->entity, $this->draft([
            ['1.1.1.1', 'about 12k'],
            ['4.1', '0'],
            ['5.1', '10.000005'],
            ['9.9.9', '5'],
            ['5.2', ''],
            ['', '77'],
        ]));

        $expected = [
            1 => 'amount_unreadable',
            2 => 'amount_is_zero',
            3 => 'amount_too_precise',
            4 => 'account_unknown',
            5 => 'amount_missing',
            6 => 'account_missing',
        ];

        $byLine = [];

        foreach ($verdict->problems as $problem) {
            $byLine[$problem->line] = $problem->code;
        }

        foreach ($expected as $line => $code) {
            if (($byLine[$line] ?? null) !== $code) {
                $violations[] = sprintf(
                    'line %d should report %s and reports %s',
                    $line,
                    $code,
                    var_export($byLine[$line] ?? null, true),
                );
            }
        }

        /*
         | A currency the entity has not enabled, and a foreign line with no
         | rate: both reported on the LINE rather than refusing the entry.
         |
         | Which is what lets the screen ask for the rate in place, instead
         | of refusing the whole entry with a message about a currency the
         | person can see they typed.
         */
        $foreign = app(ManualJournal::class)->validate($this->entity, $this->draft([
            ['1.1.1.1', '1000.00', 'EUR'],
            ['4.1', '-4012.50'],
        ]));

        if (($foreign->problems[0]->code ?? null) !== 'rate_missing') {
            $violations[] = sprintf(
                'a euro line with no rate reports %s. A rate of 1 would assert the currencies are at par',
                var_export($foreign->problems[0]->code ?? null, true),
            );
        }

        $withRate = app(ManualJournal::class)->validate($this->entity, $this->draft([
            ['1.1.1.1', '1000.00', 'EUR'],
            ['4.1', '-4012.50'],
        ]), [
            'EUR/SAR' => new Rate('EUR', 'SAR', '4.012500000000', $this->date->toDateTimeImmutable(), 'manual', 'market'),
        ]);

        if (! $withRate->postable) {
            $violations[] = sprintf(
                'the same entry with the rate supplied is still not postable: %s',
                implode('; ', array_map(static fn ($p): string => $p->messageEn, $withRate->problems)),
            );
        }

        $unenabled = app(ManualJournal::class)->validate($this->entity, $this->draft([
            ['1.1.1.1', '1000.00', 'JPY'],
            ['4.1', '-1000.00'],
        ]));

        if (($unenabled->problems[0]->code ?? null) !== 'currency_not_enabled') {
            $violations[] = 'a line in a currency the entity has not enabled was accepted, or refused for '.
                'another reason';
        }

        return $violations;
    }

    /** @return list<string> */
    public function templatesApportion(): array
    {
        $violations = [];
        $templates = app(JournalTemplates::class);
        $journal = app(ManualJournal::class);
        $key = 'accrual-'.Str::random(6);

        $templates->save($this->entity, $key, 'استحقاق شهري', 'Monthly accrual', [
            ['account_code' => '5.1', 'share' => '0.333333333333'],
            ['account_code' => '5.2', 'share' => '0.333333333333'],
            ['account_code' => '1.2.1', 'share' => '0.333333333334'],
            ['account_code' => '4.2', 'share' => '-1.000000000000'],
        ], recurrence: 'monthly', nextRunOn: $this->date->copy()->startOfMonth());

        /*
         | The total has to come out as the total.
         |
         | Three equal shares of 100.00 are 33.3333 each and 99.9999 in
         | total. The first implementation put the residual on the largest
         | share - the -1 credit, which was already exact - and produced
         | three debits of 33.3333 against a credit of 99.9999: balanced, and
         | not the figure anybody asked for. Which is the kind of wrong that
         | reconciles.
         */
        foreach (['100.0000', '99.9900', '1000000.0000', '0.0300'] as $total) {
            $lines = $templates->prepare($this->entity, $key, Money::of($total, 'SAR'));
            $verdict = $journal->validate($this->entity, $lines);

            if (! $verdict->imbalance->isZero()) {
                $violations[] = sprintf(
                    'a %s accrual from the template is out by %s',
                    $total,
                    (string) $verdict->imbalance->amount,
                );
            }

            if (! BigDecimal::of((string) $verdict->debits->amount)->isEqualTo(BigDecimal::of($total))) {
                $violations[] = sprintf(
                    'a %s accrual came out at %s. The residual went onto a line that was already exact, '.
                    'so the entry balances and the total is not what anybody asked for',
                    $total,
                    (string) $verdict->debits->amount,
                );
            }

            if (count($lines) !== 4) {
                $violations[] = sprintf('the template produced %d lines of 4', count($lines));
            }
        }

        $due = $templates->due($this->entityId, $this->date);

        if (! in_array($key, array_column($due, 'key'), true)) {
            $violations[] = 'a monthly template whose next run has passed is not reported as due, so '.
                'nothing would prepare the accrual';
        }

        $advanced = $templates->advance($this->entityId, $key);

        if ($advanced === null || Carbon::parse($advanced)->lessThanOrEqualTo($this->date->copy()->startOfMonth())) {
            $violations[] = sprintf(
                'advancing a monthly template moved it to %s. A template that does not advance prepares '.
                'the same month again next month, and two drafts for one accrual is worse than none',
                var_export($advanced, true),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function templatesAreChecked(): array
    {
        $violations = [];
        $templates = app(JournalTemplates::class);

        foreach ([
            'shares that do not cancel' => [
                [['account_code' => '5.1', 'share' => '0.5'], ['account_code' => '4.2', 'share' => '-0.4']],
                'sum to',
            ],
            'a control account' => [
                [['account_code' => '1.1.2.1', 'share' => '1.0'], ['account_code' => '4.2', 'share' => '-1.0']],
                'control account',
            ],
            'a group heading' => [
                [['account_code' => '1.1', 'share' => '1.0'], ['account_code' => '4.2', 'share' => '-1.0']],
                'group heading',
            ],
            'an account that does not exist' => [
                [['account_code' => '9.9.9', 'share' => '1.0'], ['account_code' => '4.2', 'share' => '-1.0']],
                'does not exist',
            ],
            'a single line' => [
                [['account_code' => '5.1', 'share' => '1.0']],
                'at least two',
            ],
            'a share of zero' => [
                [
                    ['account_code' => '5.1', 'share' => '0'],
                    ['account_code' => '5.2', 'share' => '1.0'],
                    ['account_code' => '4.2', 'share' => '-1.0'],
                ],
                'share of zero',
            ],
        ] as $label => [$lines, $needle]) {
            $refusal = $this->refusal(fn () => $templates->save(
                $this->entity,
                'bad-'.Str::random(6),
                'سيئ',
                'Bad',
                $lines,
            ));

            if ($refusal === null) {
                $violations[] = sprintf('a template with %s was saved', $label);
            } elseif (! str_contains($refusal, $needle)) {
                $violations[] = sprintf(
                    'a template with %s was refused for some other reason: %s',
                    $label,
                    Str::limit($refusal, 110),
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function theDryRunIsComplete(): array
    {
        $violations = [];
        $import = app(JournalImport::class);
        $date = $this->date->toDateString();
        $nextDay = $this->date->copy()->addDay()->toDateString();

        $rows = [
            ['reference' => 'JV-001', 'date' => $date, 'account_code' => '1.1.1.1', 'amount' => '500.00'],
            ['reference' => 'JV-001', 'date' => $date, 'account_code' => '4.1', 'amount' => '-450.00'],
            ['reference' => 'JV-002', 'date' => $date, 'account_code' => '1.1.2.1', 'amount' => '100.00'],
            ['reference' => 'JV-002', 'date' => $date, 'account_code' => '4.1', 'amount' => '-100.00'],
            ['reference' => 'JV-003', 'date' => '2026-02-30', 'account_code' => '5.1', 'amount' => '10.00'],
            ['reference' => 'JV-003', 'date' => $date, 'account_code' => '4.1', 'amount' => '-10.00'],
            ['reference' => '', 'date' => $date, 'account_code' => '5.1', 'amount' => '20.00'],
            ['reference' => 'JV-004', 'date' => $date, 'account_code' => '5.1', 'amount' => '30.00'],
            ['reference' => 'JV-004', 'date' => $nextDay, 'account_code' => '4.1', 'amount' => '-30.00'],
        ];

        $report = $import->dryRun($this->entity, $rows);

        if ($report->clean) {
            return ['a file with six distinct mistakes in it was reported as ready'];
        }

        $codes = array_column($report->rejections, 'code');

        foreach ([
            'entry_does_not_balance' => 'an entry out by 50',
            'account_is_a_control' => 'a control account',
            'date_unreadable' => '30 February',
            'reference_missing' => 'a row with no reference',
            'entry_has_two_dates' => 'an entry carrying two dates',
        ] as $code => $what) {
            if (! in_array($code, $codes, true)) {
                $violations[] = sprintf('the dry run did not report %s', $what);
            }
        }

        /*
         | 30 February is the one worth naming.
         |
         | `Carbon::parse` accepts it and returns 2 MARCH - silently, two
         | days into the next period, which may be closed by then. Measured:
         | before the formats were made explicit, that row was accepted and
         | the only complaint was that the entry "carried two dates", which
         | is true and is not the problem.
         */
        $februaryThirty = array_values(array_filter(
            $report->rejections,
            static fn (array $r): bool => $r['code'] === 'date_unreadable',
        ));

        if (($februaryThirty[0]['row'] ?? null) !== 6) {
            $violations[] = sprintf(
                '30 February was reported on row %s where 6 belongs. The row number is the one in the '.
                'spreadsheet, not an index - a number somebody has to translate is a number they will '.
                'translate wrong on the fortieth one',
                var_export($februaryThirty[0]['row'] ?? null, true),
            );
        }

        /*
         | Every rejection carries a row, a field and both languages.
         |
         | Missing any of them makes the report something to read rather
         | than something to act on.
         */
        foreach ($report->rejections as $rejection) {
            foreach (['row', 'field', 'code', 'message_ar', 'message_en'] as $required) {
                if (($rejection[$required] ?? null) === null || $rejection[$required] === '') {
                    $violations[] = sprintf(
                        'a rejection is missing its %s: %s',
                        $required,
                        json_encode($rejection, JSON_UNESCAPED_UNICODE),
                    );

                    break;
                }
            }
        }

        if (! str_contains($report->summary('en'), 'Nothing was written')) {
            $violations[] = 'the summary does not say that nothing was written. It is the sentence that '.
                'stops somebody going to look for a partial import';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theFilePostsWhole(): array
    {
        $violations = [];
        $import = app(JournalImport::class);
        $date = $this->date->toDateString();

        $good = [
            ['reference' => 'JV-010', 'date' => $date, 'account_code' => '1.1.1.1', 'amount' => '1200.00'],
            ['reference' => 'JV-010', 'date' => $date, 'account_code' => '4.1', 'amount' => '-1200.00'],
            ['reference' => 'JV-011', 'date' => $date, 'account_code' => '5.1', 'amount' => '340.50'],
            ['reference' => 'JV-011', 'date' => $date, 'account_code' => '1.1.1.1', 'amount' => '-340.50'],
        ];

        $bad = $good;
        $bad[1]['amount'] = '-1100.00';

        $before = app(TrialBalance::class)
            ->for($this->entityId, 'SAR', $this->date, fromJournal: true)['total_debit'];

        /*
         | The refusal has to carry the REPORT, and that is what the guard is
         | for.
         |
         | Atomicity is not: the import runs in one database transaction, so
         | removing the "refuse unless clean" check still posts nothing - the
         | Poster refuses the unbalanced entry and the whole transaction rolls
         | back. Measured: deleting the guard left every check in this suite
         | green.
         |
         | What the guard provides is the answer the screen needs. Without
         | it, what comes back is one constraint violation about one entry,
         | and the person with a four-hundred-row spreadsheet has to import
         | it again to find the second mistake. And again.
         */
        $report = null;

        try {
            $this->connection->statement('SAVEPOINT whole_file');
            $import->import($this->entity, $bad, 'from the accrual workbook');
            $this->connection->statement('ROLLBACK TO SAVEPOINT whole_file');

            $violations[] = 'a file with one unbalanced entry was imported';
        } catch (ImportRefused $e) {
            $this->connection->statement('ROLLBACK TO SAVEPOINT whole_file');
            $report = $e->report;
        } catch (Throwable $e) {
            $this->connection->statement('ROLLBACK TO SAVEPOINT whole_file');

            $violations[] = sprintf(
                'the import failed with %s rather than refusing with a report. The person with a '.
                'four-hundred-row spreadsheet then has to import it again to find the second mistake, '.
                'and again: %s',
                get_class($e),
                Str::limit($e->getMessage(), 110),
            );
        }

        if ($report !== null) {
            if ($report->rejections === []) {
                $violations[] = 'the refusal carried a report with no rejections in it';
            }

            if ($report->clean !== false) {
                $violations[] = 'the refusal carried a report marked clean';
            }

            if (! str_contains($report->summary('en'), 'Nothing was written')) {
                $violations[] = 'the refusal\'s summary does not say that nothing was written';
            }
        }

        $after = app(TrialBalance::class)
            ->for($this->entityId, 'SAR', $this->date, fromJournal: true)['total_debit'];

        if (! BigDecimal::of($after)->isEqualTo(BigDecimal::of($before))) {
            $violations[] = sprintf(
                'the refused file still moved the trial balance from %s to %s. A file that posts the rows '.
                'it can leaves a partial month in the ledger and a spreadsheet to diff against it',
                $before,
                $after,
            );
        }

        $result = $import->import($this->entity, $good, 'from the accrual workbook, August');

        if (count($result['entries']) !== 2) {
            $violations[] = sprintf('a clean file of two entries posted %d', count($result['entries']));
        }

        $statement = app(TrialBalance::class)
            ->for($this->entityId, 'SAR', $this->date, fromJournal: true);

        if ($statement['balances'] !== true) {
            $violations[] = 'the ledger does not balance after the import';
        }

        $moved = BigDecimal::of($statement['total_debit'])->minus($before);

        if (! $moved->isEqualTo(BigDecimal::of('1540.5000'))) {
            $violations[] = sprintf(
                'the import moved the trial balance by %s where 1540.5000 belongs',
                (string) $moved,
            );
        }

        $thin = $this->refusal(fn () => $import->import($this->entity, $good, 'csv'));

        if ($thin === null || ! str_contains($thin, 'at least ten characters')) {
            $violations[] = 'an import was accepted with no real reason. It is what somebody reads when '.
                'they ask why forty entries appeared on the same day';
        }

        return $violations;
    }

    // ---------------------------------------------------------------------

    /**
     * @param  list<array{0: string, 1: string, 2?: string}>  $rows
     * @return list<DraftLine>
     */
    private function draft(array $rows): array
    {
        return array_map(
            static fn (array $row): DraftLine => DraftLine::fromArray([
                'account_code' => $row[0],
                'amount' => $row[1],
                'currency' => $row[2] ?? null,
            ]),
            $rows,
        );
    }

    /** @return string|null the refusal, or null if it succeeded */
    private function refusal(callable $attempt): ?string
    {
        $savepoint = 'mj_'.bin2hex(random_bytes(4));

        $this->connection->statement('SAVEPOINT '.$savepoint);

        try {
            $attempt();
            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return null;
        } catch (Throwable $e) {
            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 240);
        }
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $this->entity = Entity::create([
            'code' => 'MJ'.random_int(100, 999),
            'legal_name' => 'Manual journal check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $context->use($this->entity);
        $this->entityId = (string) $this->entity->getKey();

        app(CurrencyPolicy::class)->enable($this->entity, 'EUR');

        $this->date = Carbon::now()->startOfYear()->addMonths(7)->addDays(4);

        $period = app(FiscalCalendarGenerator::class)->periodFor($this->entityId, $this->date);

        if ($period !== null && $period->state !== PeriodState::Open) {
            app(PeriodStateMachine::class)->transition(
                $period,
                PeriodState::Open,
                'opened for the manual journal entry check',
            );
        }

        $user = User::create([
            'name' => 'Manual journal check',
            'email' => 'mj'.random_int(100000, 999999).'@check.test',
            'password' => 'x-not-a-real-password',
            'active' => true,
        ]);

        UserEntityRole::create([
            'user_id' => $user->getKey(),
            'entity_id' => $this->entityId,
            'role_id' => Role::query()->where('key', 'controller')->firstOrFail()->getKey(),
        ]);

        auth()->guard('web')->setUser($user);
    }

    private function tenant(): ?Tenant
    {
        try {
            return Tenant::query()->with('connection')->whereHas('connection')->orderBy('slug')->first();
        } catch (Throwable) {
            return null;
        }
    }
}
