<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Manual;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Tenant\Ledger\Exceptions\ImportRefused;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Ledger\Posting\RuleDescription;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Importing journal entries from a spreadsheet - PF-055.
 *
 * ## The dry run is the operation; the import is what happens afterwards
 *
 * A spreadsheet of four hundred journal lines has mistakes in it. The person
 * who prepared it has a column of codes from last year's chart, two rows
 * where the sign is wrong, and one entry that is out by the VAT.
 *
 * An import that posts what it can and reports the rest leaves a partial
 * month in the ledger and a spreadsheet to diff against it. So nothing is
 * written unless everything passes, and {@see ImportRefused} says how many
 * rows stopped it.
 *
 * ## Rows are grouped into entries by their reference
 *
 * A spreadsheet is not a list of lines. Rows sharing a reference are an
 * entry, and each entry has to balance on its OWN - a file whose grand total
 * is zero while two entries are out by equal and opposite amounts would post
 * two wrong entries and look right.
 *
 * ## Row numbers are spreadsheet row numbers
 *
 * Offset by the header, so row 1 of the data is row 2 on the screen the
 * person is looking at. An index into an array is a number they have to
 * translate, and they will translate it wrong on the fortieth one.
 */
final class JournalImport
{
    /** The rule the imported entries post under. */
    public const RULE = 'ledger.manual_import';

    public function __construct(
        private readonly ManualJournal $validator,
        private readonly Poster $poster,
        private readonly PostingRuleRegistry $rules,
        private readonly AuditTrail $audit,
        private readonly EntityContext $entities,
    ) {}

    /**
     * Read the file and say what it would do. Writes nothing.
     *
     * @param  list<array<string, mixed>>  $rows  as a CSV or spreadsheet parses
     * @param  array<string, Rate>  $rates
     */
    public function dryRun(Entity $entity, array $rows, array $rates = [], int $headerRows = 1): ImportReport
    {
        $functional = (string) $entity->functional_currency;
        $rejections = [];
        $entries = [];
        $grouped = [];

        $totalDebit = Money::zero($functional);
        $totalCredit = Money::zero($functional);

        foreach ($rows as $index => $row) {
            $spreadsheetRow = $index + 1 + $headerRows;

            $reference = $this->text($row['reference'] ?? $row['entry'] ?? null);
            $date = $this->text($row['date'] ?? $row['accounting_date'] ?? null);

            if ($reference === null) {
                $rejections[] = $this->rejection(
                    $spreadsheetRow,
                    null,
                    'reference',
                    'reference_missing',
                    'هذا السطر لا يحمل مرجعًا، فلا يُعرف إلى أيّ قيد ينتمي.',
                    'This row has no reference, so there is nothing to say which entry it belongs to.',
                );

                continue;
            }

            if ($date === null) {
                $rejections[] = $this->rejection(
                    $spreadsheetRow,
                    $reference,
                    'date',
                    'date_missing',
                    'هذا السطر لا يحمل تاريخًا.',
                    'This row has no date.',
                );

                continue;
            }

            $parsed = $this->readDate($date);

            if ($parsed === null) {
                $rejections[] = $this->rejection(
                    $spreadsheetRow,
                    $reference,
                    'date',
                    'date_unreadable',
                    sprintf(
                        '"%s" ليس تاريخًا. الصيغ المقبولة: 2026-08-05 أو 05/08/2026 أو 05-08-2026.',
                        $date,
                    ),
                    sprintf(
                        '"%s" is not a date. Accepted: 2026-08-05, 05/08/2026 or 05-08-2026.',
                        $date,
                    ),
                );

                continue;
            }

            $grouped[$reference] ??= ['date' => $parsed, 'rows' => [], 'dates' => []];
            $grouped[$reference]['rows'][$spreadsheetRow] = DraftLine::fromArray($row);
            $grouped[$reference]['dates'][$parsed->toDateString()] = true;
        }

        foreach ($grouped as $reference => $entry) {
            /*
             | One date per entry.
             |
             | Two rows of the same entry carrying different dates is the
             | commonest spreadsheet mistake after the sign, and it is the
             | one an import would silently resolve by taking whichever row
             | it saw first.
             */
            if (count($entry['dates']) > 1) {
                foreach (array_keys($entry['rows']) as $row) {
                    $rejections[] = $this->rejection(
                        $row,
                        (string) $reference,
                        'date',
                        'entry_has_two_dates',
                        sprintf(
                            'القيد %s يحمل تاريخين: %s. القيد له تاريخ واحد، واختيار أحدهما تلقائيًّا '.
                            'يعني قيدًا في فترة لم يقصدها أحد.',
                            (string) $reference,
                            implode(' and ', array_keys($entry['dates'])),
                        ),
                        sprintf(
                            'Entry %s carries two dates: %s. An entry has one date, and picking one '.
                            'would put it in a period nobody chose.',
                            (string) $reference,
                            implode(' and ', array_keys($entry['dates'])),
                        ),
                    );
                }

                continue;
            }

            $verdict = $this->validator->validate($entity, array_values($entry['rows']), $rates);

            /*
             | The validator numbers its problems from 1 within the entry;
             | the report needs the spreadsheet row.
             */
            $rowNumbers = array_keys($entry['rows']);

            foreach ($verdict->problems as $problem) {
                $rejections[] = $this->rejection(
                    $rowNumbers[$problem->line - 1] ?? $problem->line,
                    (string) $reference,
                    $problem->field,
                    $problem->code,
                    $problem->messageAr,
                    $problem->messageEn,
                );
            }

            if (! $verdict->imbalance->isZero()) {
                /*
                 | The imbalance is reported on the entry's FIRST row.
                 |
                 | Not on all of them: the rows are each fine and pointing
                 | at four correct lines teaches people the report is
                 | approximate. The first row is where the entry starts and
                 | is where somebody will look.
                 */
                $rejections[] = $this->rejection(
                    (int) ($rowNumbers[0] ?? 0),
                    (string) $reference,
                    'amount',
                    'entry_does_not_balance',
                    sprintf(
                        'القيد %s غير متوازن بمقدار %s (ناقص في الجانب %s).',
                        (string) $reference,
                        (string) $verdict->imbalance->amount,
                        $verdict->imbalanceSide() === 'credit' ? 'الدائن' : 'المدين',
                    ),
                    sprintf(
                        'Entry %s is out by %s, short on the %s side.',
                        (string) $reference,
                        (string) $verdict->imbalance->amount,
                        (string) $verdict->imbalanceSide(),
                    ),
                );
            }

            if (count($verdict->lines) < 2) {
                $rejections[] = $this->rejection(
                    (int) ($rowNumbers[0] ?? 0),
                    (string) $reference,
                    'reference',
                    'entry_has_one_line',
                    sprintf('القيد %s يحمل سطرًا واحدًا.', (string) $reference),
                    sprintf('Entry %s has one line.', (string) $reference),
                );
            }

            $entries[] = [
                'reference' => (string) $reference,
                'lines' => count($verdict->lines),
                'date' => $entry['date']->toDateString(),
                'balances' => $verdict->imbalance->isZero(),
                'imbalance' => (string) $verdict->imbalance->amount,
            ];

            $totalDebit = $totalDebit->plus($verdict->debits);
            $totalCredit = $totalCredit->plus($verdict->credits);
        }

        usort($rejections, static fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        return new ImportReport(
            clean: $rejections === [] && $entries !== [],
            rows: count($rows),
            entries: $entries,
            rejections: $rejections,
            totalDebit: (string) $totalDebit->amount,
            totalCredit: (string) $totalCredit->amount,
            currency: $functional,
        );
    }

    /**
     * Post the file, or refuse the whole of it.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, Rate>  $rates
     * @return array{report: ImportReport, entries: list<JournalEntry>}
     *
     * @throws ImportRefused
     */
    public function import(
        Entity $entity,
        array $rows,
        string $reason,
        array $rates = [],
        int $headerRows = 1,
    ): array {
        if (Gate::denies('journal.post')) {
            throw ImportRefused::notPermitted();
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw ImportRefused::needsAReason();
        }

        $report = $this->dryRun($entity, $rows, $rates, $headerRows);

        if (! $report->clean) {
            throw ImportRefused::theFileHasProblems($report);
        }

        $this->registerTheImportRule();

        return DB::connection('tenant')->transaction(function () use (
            $entity,
            $rows,
            $rates,
            $headerRows,
            $reason,
            $report,
        ): array {
            $posted = [];
            $grouped = $this->groupRows($rows, $headerRows);

            foreach ($grouped as $reference => $entry) {
                $verdict = $this->validator->validate($entity, array_values($entry['rows']), $rates);

                $posted[] = $this->poster->post(
                    entity: $entity,
                    ruleKey: self::RULE,
                    /*
                     | The built PostingLines, not the display rows.
                     |
                     | They carry the translation and its provenance - the
                     | rate, its set, its date and its provider - which a
                     | rule rebuilding the line from an account code and a
                     | functional amount would lose. The database refuses a
                     | foreign line with no rate, so losing it turns an
                     | imported euro line into a riyal line that nothing
                     | afterwards can trace back.
                     */
                    event: ['posting_lines' => $verdict->postingLines],
                    accountingDate: $entry['date'],
                    journalCode: 'GEN',
                    sourceType: 'spreadsheet_import',
                    sourceId: (string) $reference,
                    narration: sprintf('Imported %s: %s', (string) $reference, $reason),
                );
            }

            $this->audit->record(
                category: 'ledger',
                action: 'ledger.import',
                subjectType: 'journal_entries',
                subjectLabel: sprintf('%d entries from a spreadsheet', count($posted)),
                after: [
                    'rows' => $report->rows,
                    'entries' => count($posted),
                    'total_debit' => $report->totalDebit,
                    'numbers' => array_map(static fn (JournalEntry $e): string => (string) $e->number, $posted),
                ],
                reason: $reason,
                entityId: (string) $entity->getKey(),
            );

            return ['report' => $report, 'entries' => $posted];
        });
    }

    /**
     * Read a date, strictly, or refuse it.
     *
     * ## Why `Carbon::parse` is not good enough here
     *
     * It is lenient, and leniency on a date in a spreadsheet is the worst
     * place for it. `2026-02-30` does not exist and `Carbon::parse` returns
     * **2 March** - silently, two days into the next period. Measured: an
     * import of a file with that date was accepted and reported only that
     * the entry "carries two dates", which is true and is not the problem.
     *
     * A date that shifts by two days lands in the wrong month, and a month
     * that is closed by then cannot take it at all. So the formats are
     * explicit and a value that does not match one exactly is refused.
     *
     * Three formats, because those are the ones people type: ISO, and
     * day-first with either separator. Month-first is deliberately absent -
     * `05/08/2026` is the fifth of August in both of this product's markets,
     * and accepting both readings of it would mean guessing.
     */
    private function readDate(string $value): ?Carbon
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            /*
             | `createFromFormat` THROWS on a format that does not match, in
             | this version - it does not return false. Trying three formats
             | therefore means catching, and catching the first two is the
             | normal path rather than an error.
             */
            try {
                $parsed = Carbon::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }

            if (! $parsed instanceof Carbon) {
                continue;
            }

            /*
             | And the round trip is what catches 30 February.
             |
             | PHP's parser accepts it and rolls it forward to 2 March, with
             | a WARNING rather than an error - so re-formatting and
             | comparing is the test that actually holds. Measured: an import
             | of a file dated 2026-02-30 was accepted as 2 March and
             | reported only that the entry "carried two dates", which is
             | true and is not the problem.
             */
            if ($parsed->format($format) !== $value) {
                continue;
            }

            return $parsed->startOfDay();
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{date: Carbon, rows: array<int, DraftLine>}>
     */
    private function groupRows(array $rows, int $headerRows): array
    {
        $grouped = [];

        foreach ($rows as $index => $row) {
            $reference = (string) ($row['reference'] ?? $row['entry'] ?? '');
            $date = (string) ($row['date'] ?? $row['accounting_date'] ?? '');

            $grouped[$reference] ??= ['date' => $this->readDate($date) ?? Carbon::parse($date)->startOfDay(), 'rows' => []];
            $grouped[$reference]['rows'][$index + 1 + $headerRows] = DraftLine::fromArray($row);
        }

        return $grouped;
    }

    /**
     * The rule imported entries post under.
     *
     * Registered rather than declared as a class, because its lines come
     * from the validator - which has already resolved the accounts, read the
     * amounts and applied the rates. A rule that re-derived them would be a
     * second implementation of the thing the dry run just proved correct.
     */
    private function registerTheImportRule(): void
    {
        if ($this->rules->has(self::RULE)) {
            return;
        }

        $this->rules->register(new class implements PostingRule
        {
            public function key(): string
            {
                return JournalImport::RULE;
            }

            public function describe(): RuleDescription
            {
                return RuleDescription::of(
                    JournalImport::RULE,
                    'قيد مستورد من ملف',
                    'An entry imported from a spreadsheet',
                    [['Dr/Cr', 'as the file says', 'validated by the dry run before anything is written']],
                );
            }

            /**
             * The lines the validator already built.
             *
             * This rule derives nothing. The dry run resolved the accounts,
             * read the amounts and applied the rates, and re-deriving any of
             * it here would be a second implementation of the thing the dry
             * run just proved correct - and the one that is not under test.
             *
             * @return list<PostingLine>
             */
            public function lines(array $event, PostingContext $context): array
            {
                return array_values(array_filter(
                    (array) ($event['posting_lines'] ?? []),
                    static fn (mixed $line): bool => $line instanceof PostingLine,
                ));
            }
        });
    }

    /** @return array<string, mixed> */
    private function rejection(
        int $row,
        ?string $reference,
        string $field,
        string $code,
        string $messageAr,
        string $messageEn,
    ): array {
        return [
            'row' => $row,
            'reference' => $reference,
            'field' => $field,
            'code' => $code,
            'message_ar' => $messageAr,
            'message_en' => $messageEn,
        ];
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
