<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Services;

use App\Support\Money\Money;
use App\Tenant\Reporting\Exceptions\ReportRefused;
use App\Tenant\Reporting\Registers\ReportParameters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every entry in a period, which is what an auditor samples from - PF-188.
 *
 * ## A sampling frame, not a summary
 *
 * An auditor picks entries off this list and asks to see each one. That
 * makes completeness the only property that matters: a register missing one
 * entry is a register from which that entry can never be selected, and no
 * amount of totalling finds it. So the list is every entry in the window,
 * in a fixed order, with the handle needed to open each one.
 *
 * ## Drafts are LISTED and not TOTALLED
 *
 * A draft entry has not happened - it is in no balance and in no statement
 * - so adding it to the register's totals would produce a register that
 * does not tie to the trial balance, which is the first thing an auditor
 * reconciles it against. Leaving it off the list entirely is the other
 * mistake: a draft sitting in a period about to be closed is precisely
 * what somebody needs to see, and a register that hides it is how a month
 * gets closed over an unposted invoice.
 *
 * So drafts appear with their status, the totals cover posted and reversed
 * only, and the declaration says how many were listed but not counted.
 *
 * ## Why gaplessness is NOT re-derived here
 *
 * An auditor's next question is whether the numbering has a hole in it.
 * That guarantee belongs to the numbering series, which issues numbers
 * inside the posting transaction and is proved under twenty parallel
 * issuers by its own check. Re-deriving it here from the strings in the
 * register would be a second opinion about it: the two would disagree the
 * first time a journal changed its number format, and nobody could say
 * which was right. The register reports what it has - the count and the
 * first and last number in each journal - which is what makes a sample
 * reproducible, and leaves the invariant where it is enforced.
 *
 * ## Who created and posted an entry is one level down
 *
 * Deliberately not on this page. "Who did this" is audit-trail
 * information, gated on `audit.view`, and {@see DrillDown} is the one
 * place in the product that answers it - with that gate applied. A
 * register that printed the names would hand out, to every holder of
 * `report.view`, exactly what the drill-down checks a permission for; the
 * two gates would then have to be kept in step by somebody remembering.
 */
final class JournalRegister
{
    /** Posted and reversed are the ledger's figures; a draft has not happened. */
    private const COUNTED = ['posted', 'reversed'];

    /**
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function forPeriod(
        string $entityId,
        string $functionalCurrency,
        Carbon $from,
        Carbon $until,
        bool $includeAdjusting = true,
        ?string $journalCode = null,
        ?Carbon $ranAt = null,
    ): array {
        if ($until->lt($from)) {
            throw ReportRefused::because(
                'The period ends before it starts, so the register is empty - and an empty register '.
                'reads as a month in which nothing was posted, which is the one answer nobody checks.',
            );
        }

        $stated = [];

        if ($journalCode !== null) {
            $journal = DB::connection('tenant')->table('journals')
                ->where('entity_id', $entityId)
                ->where('code', $journalCode)
                ->first(['code', 'name_en']);

            if ($journal === null) {
                throw ReportRefused::because(sprintf(
                    'This entity has no journal "%s". A register filtered to a journal that does not '.
                    'exist is empty, and an auditor sampling from it would conclude the journal was '.
                    'unused rather than misnamed.',
                    $journalCode,
                ));
            }

            $stated['journal'] = sprintf('%s - %s', (string) $journal->code, (string) $journal->name_en);
        }

        $parameters = ReportParameters::for(
            report: 'journal_register',
            entityId: $entityId,
            from: $from,
            until: $until,
            currency: $functionalCurrency,
        /*
         | The run clock is a PARAMETER here, not a reading of the wall.
         |
         | Without this the same report run twice is two different files -
         | the parameter block prints the clock - and PF-198's byte-
         | stability guarantee cannot be stated, let alone checked. A
         | caller who wants a reproducible artefact names the instant; a
         | caller who wants "now" leaves it out and gets it.
         */
            includesAdjusting: $includeAdjusting,
            filters: $stated,
            runAt: $ranAt,
        );

        $adjusting = $includeAdjusting ? '' : ' AND p.is_adjusting = false';
        $journalBound = $journalCode === null ? '' : ' AND j.code = :journal';
        $bindings = [
            'entity' => $entityId,
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
        ];

        if ($journalCode !== null) {
            $bindings['journal'] = $journalCode;
        }

        /*
         | The lines are aggregated in the same statement, not fetched per
         | entry.
         |
         | A register of a busy month is thousands of entries, and a line
         | count per entry is an N+1 that no functional check can see: the
         | figures come out right and the page takes a minute. The lateral
         | join reads each entry's lines once, from the index on
         | `journal_entry_id`.
         |
         | Ordered to the entry's own id at the end: two entries can share
         | a date, a journal and - across journals - a number, and without
         | a final key the order changes with the plan. An auditor who
         | sampled "rows 10, 40 and 80" could not take the same sample
         | twice, which is the whole value of a register.
         */
        $rows = DB::connection('tenant')->select(<<<SQL
            SELECT
                e.id AS entry_id,
                e.number,
                e.status,
                j.code AS journal_code,
                j.name_ar AS journal_name_ar,
                j.name_en AS journal_name_en,
                e.accounting_date::text AS accounting_date,
                e.entered_at::text AS entered_at,
                e.posted_at::text AS posted_at,
                p.is_adjusting,
                p.label AS period_label,
                e.narration,
                e.source_type,
                e.source_id,
                e.posting_rule,
                e.reverses_entry_id,
                coalesce(l.lines, 0) AS lines,
                coalesce(l.accounts, 0) AS accounts,
                coalesce(l.debits, 0)::text AS debits,
                coalesce(l.credits, 0)::text AS credits
            FROM journal_entries e
            JOIN journals j ON j.id = e.journal_id
            JOIN periods p ON p.id = e.period_id
            LEFT JOIN LATERAL (
                SELECT count(*) AS lines,
                       count(DISTINCT account_id) AS accounts,
                       coalesce(sum(functional_amount) FILTER (WHERE functional_amount > 0), 0) AS debits,
                       coalesce(-sum(functional_amount) FILTER (WHERE functional_amount < 0), 0) AS credits
                FROM journal_lines
                WHERE journal_entry_id = e.id
            ) l ON true
            WHERE e.entity_id = cast(:entity AS char(26))
              AND e.accounting_date BETWEEN cast(:from AS date) AND cast(:until AS date)
              {$adjusting}{$journalBound}
            ORDER BY e.accounting_date, j.code, e.number, e.id
        SQL, $bindings);

        $entries = [];
        $byJournal = [];
        $drafts = 0;
        $unbalanced = [];

        $totalDebits = Money::zero($functionalCurrency);
        $totalCredits = Money::zero($functionalCurrency);

        foreach ($rows as $row) {
            $debits = Money::of((string) $row->debits, $functionalCurrency);
            $credits = Money::of((string) $row->credits, $functionalCurrency);
            $counted = \in_array((string) $row->status, self::COUNTED, true);

            if ($counted) {
                $totalDebits = $totalDebits->plus($debits);
                $totalCredits = $totalCredits->plus($credits);
            } else {
                $drafts++;
            }

            /*
             | An entry whose own sides disagree is reported by name.
             |
             | A deferred constraint refuses an unbalanced entry at commit,
             | so one appearing here cannot be a posting that slipped
             | through - it is a row written around the posting path. That
             | is the loudest thing this register can find, and it is
             | named entry by entry rather than left to a reader who
             | happens to add two columns.
             */
            if ($counted && ! $debits->equals($credits)) {
                $unbalanced[] = sprintf(
                    '%s/%s is out by %s',
                    (string) $row->journal_code,
                    (string) $row->number,
                    (string) $debits->minus($credits)->amount,
                );
            }

            $entries[] = [
                'entry_id' => (string) $row->entry_id,
                'number' => (string) $row->number,
                'journal' => (string) $row->journal_code,
                'journal_name_ar' => (string) $row->journal_name_ar,
                'journal_name_en' => (string) $row->journal_name_en,
                'date' => (string) $row->accounting_date,
                'period' => (string) $row->period_label,
                'is_adjusting' => (bool) $row->is_adjusting,
                'status' => (string) $row->status,
                'counted' => $counted,
                'narration' => (string) ($row->narration ?? ''),
                'source_type' => $row->source_type === null ? null : (string) $row->source_type,
                'source_id' => $row->source_id === null ? null : (string) $row->source_id,
                'posting_rule' => $row->posting_rule === null ? null : (string) $row->posting_rule,
                'reverses_entry_id' => $row->reverses_entry_id === null
                    ? null
                    : (string) $row->reverses_entry_id,
                'lines' => (int) $row->lines,
                'accounts' => (int) $row->accounts,
                'debits' => (string) $debits->amount,
                'credits' => (string) $credits->amount,
                'balanced' => $debits->equals($credits),
                'entered_at' => $row->entered_at === null ? null : (string) $row->entered_at,
                'posted_at' => $row->posted_at === null ? null : (string) $row->posted_at,
            ];

            $code = (string) $row->journal_code;
            $byJournal[$code] ??= [
                'journal' => $code,
                'name_ar' => (string) $row->journal_name_ar,
                'name_en' => (string) $row->journal_name_en,
                'entries' => 0,
                'drafts' => 0,
                'first_number' => (string) $row->number,
                'last_number' => (string) $row->number,
            ];

            $byJournal[$code]['entries']++;
            $byJournal[$code]['last_number'] = (string) $row->number;

            if (! $counted) {
                $byJournal[$code]['drafts']++;
            }
        }

        ksort($byJournal);

        return [
            'parameters' => $parameters->toArray(),
            'parameter_lines' => $parameters->lines(),
            'columns' => $this->columns(),
            'entries' => $entries,
            'by_journal' => array_values($byJournal),
            'counted_entries' => \count($entries) - $drafts,
            'draft_entries' => $drafts,
            'total_debits' => (string) $totalDebits->amount,
            'total_credits' => (string) $totalCredits->amount,
            'balances' => $totalDebits->equals($totalCredits),
            'declaration' => $this->declare(
                \count($entries),
                $drafts,
                $unbalanced,
                $totalDebits,
                $totalCredits,
            ),
        ];
    }

    /**
     * The same register, as a file - PF-197.
     *
     * It calls {@see self::forPeriod()} rather than taking its output,
     * which is the point: an export that accepted an array could be handed
     * figures from anywhere, and the guarantee this product sells is that a
     * printed report and the screen it came from answer the same question.
     * Same method, same parameters, same digest printed on both.
     *
     * The declaration is deliberately NOT in the file. It is a paragraph of
     * prose - "AN ENTRY IN THIS REGISTER DOES NOT BALANCE" and its
     * explanation - and a paragraph in a spreadsheet cell is a paragraph
     * nobody reads, in a column somebody then sorts. The severity reaches
     * the reader on the screen, where it can be a banner; the file carries
     * the figures and the parameters that produced them.
     *
     * @throws ReportRefused
     */
    public function csvForPeriod(
        string $entityId,
        string $functionalCurrency,
        Carbon $from,
        Carbon $until,
        bool $includeAdjusting = true,
        string $locale = 'ar',
        ?Carbon $ranAt = null,
    ): string {
        $report = $this->forPeriod(
            $entityId,
            $functionalCurrency,
            $from,
            $until,
            $includeAdjusting,
            ranAt: $ranAt,
        );

        return app(\App\Tenant\Reporting\Export\ReportExport::class)->csv(
            parameterLines: $report['parameter_lines'],
            columns: $report['columns'],
            rows: $report['entries'],
            locale: $locale,
        );
    }

    /**
     * @return list<array{key: string, label_ar: string, label_en: string}>
     */
    private function columns(): array
    {
        return [
            ['key' => 'date', 'label_ar' => 'التاريخ', 'label_en' => 'Date'],
            ['key' => 'journal', 'label_ar' => 'اليومية', 'label_en' => 'Journal'],
            ['key' => 'number', 'label_ar' => 'رقم القيد', 'label_en' => 'Entry'],
            ['key' => 'status', 'label_ar' => 'الحالة', 'label_en' => 'Status'],
            ['key' => 'narration', 'label_ar' => 'البيان', 'label_en' => 'Narration'],
            ['key' => 'lines', 'label_ar' => 'عدد السطور', 'label_en' => 'Lines'],
            ['key' => 'debits', 'label_ar' => 'مدين', 'label_en' => 'Debits'],
            ['key' => 'credits', 'label_ar' => 'دائن', 'label_en' => 'Credits'],
        ];
    }

    /**
     * @param  list<string>  $unbalanced
     * @return array<string, string>
     */
    private function declare(
        int $entries,
        int $drafts,
        array $unbalanced,
        Money $debits,
        Money $credits,
    ): array {
        if ($unbalanced !== []) {
            return [
                'severity' => 'broken',
                'en' => sprintf(
                    'AN ENTRY IN THIS REGISTER DOES NOT BALANCE: %s. The database refuses an unbalanced '.
                    'entry at commit, so this is not a posting that slipped through - it is a row '.
                    'written around the posting path. Nothing on this register ties to the trial '.
                    'balance until it is explained.',
                    implode('; ', $unbalanced),
                ),
                'ar' => 'يوجد قيد غير متوازن في هذا السجل. قاعدة البيانات ترفض القيد غير المتوازن، '.
                    'فهذا صفٌّ كُتب خارج مسار الترحيل.',
            ];
        }

        if (! $debits->equals($credits)) {
            return [
                'severity' => 'broken',
                'en' => sprintf(
                    'THE REGISTER DOES NOT BALANCE: debits are %s and credits are %s. Every entry on it '.
                    'balances individually, so the difference is a line this register did not read - '.
                    'which means it is not the complete list it claims to be, and a sample taken from '.
                    'it is not a sample of the period.',
                    (string) $debits->amount,
                    (string) $credits->amount,
                ),
                'ar' => 'مجموع السجل غير متوازن مع أنّ كل قيد فيه متوازن، أي أنّ السجل ليس القائمة '.
                    'الكاملة التي يدّعيها.',
            ];
        }

        $draftSentence = $drafts === 0
            ? 'Every entry in it is posted or reversed.'
            : sprintf(
                '%d of them %s still a draft: listed, because a draft in a period about to be closed is '.
                'the thing somebody needs to see, and NOT included in the totals, because a draft is in '.
                'no balance and a register that counted it would not tie to the trial balance.',
                $drafts,
                $drafts === 1 ? 'is' : 'are',
            );

        return [
            'severity' => $drafts === 0 ? 'ok' : 'attention',
            'en' => sprintf(
                '%d entr%s in this window, totalling %s on each side. %s',
                $entries,
                $entries === 1 ? 'y' : 'ies',
                (string) $debits->amount,
                $draftSentence,
            ),
            'ar' => sprintf(
                '%d قيدًا في هذه الفترة، ومجموع كل جانب %s. %s',
                $entries,
                (string) $debits->amount,
                $drafts === 0
                    ? 'جميعها مُرحَّلة أو معكوسة.'
                    : sprintf('منها %d مسودة: معروضة ولا تدخل في المجاميع.', $drafts),
            ),
        ];
    }
}
