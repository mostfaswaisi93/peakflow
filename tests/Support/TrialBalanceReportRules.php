<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Reporting\Services\TrialBalanceReport;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The trial balance as a report - PF-183.
 *
 * ## What a check here is for
 *
 * **A trial balance that does not balance and prints anyway.** It is the
 * loudest signal this product has: an unbalanced ENTRY is refused by a
 * deferred constraint, so an unbalanced trial balance means a row was
 * written around the posting path or a read model has drifted. A report
 * that put that in a boolean an export may drop is a report that hides
 * the only thing on it that matters.
 *
 * **Three columns that disagree.** Opening, movement and closing have to
 * be one arithmetic. Summing the period's own lines for the movement
 * gives a fourth figure that is right in a different way, and the first
 * time the two differ nobody can say which is the error.
 *
 * **A translated figure nobody can reproduce.** A presentation currency
 * without its rate, its date and its source is a number that reconciles
 * to nothing - and the reader cannot tell.
 */
final class TrialBalanceReportRules
{
    use Concerns\IssuesInvoices;
    use Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['trial balance report (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'opening, movement and closing are one arithmetic' => fn (): array => $this->theThreeColumnsAgree(),
                'a posting on the first day is movement, not opening' => fn (): array => $this->theFirstDayIsMovement(),
                'an account that never moved is left out' => fn (): array => $this->emptyAccountsAreOmitted(),
                'it says plainly that it balances' => fn (): array => $this->itSaysItBalances(),
                'and shouts when it does not' => fn (): array => $this->itShoutsWhenItDoesNot(),
                'a reversed period is refused' => fn (): array => $this->aReversedPeriodIsRefused(),
                'a presentation currency carries its rate and date' => fn (): array => $this->translationCarriesItsBasis(),
                'and is refused when there is no rate' => fn (): array => $this->translationNeedsARate(),
                'the basis says whether adjusting entries are in' => fn (): array => $this->theBasisIsStated(),
            ]));
        } catch (Throwable $e) {
            return ['the trial balance report could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function theThreeColumnsAgree(): array
    {
        /*
         | One invoice BEFORE the period and one inside it.
         |
         | With an opening of zero the movement equals the closing
         | balance, so a product that reported the closing figure in
         | the movement column would pass - which the bite sweep
         | demonstrated. Two dates are the only way to tell them apart.
         |
         | The second is issued on a later day by moving the fixture's
         | own date, not by editing the journal: an attempt to backdate
         | the posted lines was refused by the ledger's freeze trigger,
         | which is the ledger behaving correctly and the fixture
         | reaching for the wrong lever.
         */
        $this->issue();

        $was = $this->date;
        $this->date = Carbon::parse('2026-05-20');

        try {
            $this->issue();
        } finally {
            $this->date = $was;
        }

        $report = $this->report('2026-05-19', '2026-05-31');
        $failures = [];

        if ($report['rows'] === []) {
            return ['a month with an issued invoice in it reports no rows at all'];
        }

        /*
         | Closing minus opening, on every row.
         |
         | The only definition under which the three columns cannot
         | disagree. A product that summed the period's own lines for
         | the movement would produce a fourth figure that is right in a
         | different way - and when the two differ, nobody can say which
         | is the error.
         */
        foreach ($report['rows'] as $row) {
            $opening = BigDecimal::of((string) $row['opening']);
            $movement = BigDecimal::of((string) $row['movement']);
            $closing = BigDecimal::of((string) $row['closing']);

            if ($opening->plus($movement)->compareTo($closing) !== 0) {
                $failures[] = sprintf(
                    'account %s opens at %s, moves %s and closes at %s - which does not add up, so a '.
                    'reader has to decide which of the three to believe',
                    (string) $row['code'],
                    (string) $row['opening'],
                    (string) $row['movement'],
                    (string) $row['closing'],
                );
            }
        }

        /*
         | And the receivable moved by the invoice, not by nothing.
         |
         | A check that only asserted the arithmetic would pass on a
         | report where every column was zero.
         */
        $receivable = $this->rowFor($report, 'accounts_receivable');

        if ($receivable === null) {
            return [...$failures, 'the receivables control is not on the report at all'];
        }

        /*
         | Opening 1,150 from the earlier invoice, movement 1,150 from
         | the later one, closing 2,300.
         |
         | Three different numbers, which is what makes the columns
         | distinguishable at all.
         */
        foreach ([
            ['opening', '1150'],
            ['movement', '1150'],
            ['closing', '2300'],
        ] as [$column, $expected]) {
            if (BigDecimal::of((string) $receivable[$column])->compareTo($expected) !== 0) {
                $failures[] = sprintf(
                    'receivables %s reads %s rather than %s - with an invoice before the period and '.
                    'one inside it, the three columns have to be three different figures',
                    $column,
                    (string) $receivable[$column],
                    $expected,
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function theFirstDayIsMovement(): array
    {
        $this->issue();

        /*
         | The period that STARTS on the invoice's own date.
         |
         | Opening is the balance the day before. A product that read it
         | on the first day would report the month's first invoice as
         | something that was always there - and the movement column
         | would be short by it every month.
         */
        $report = $this->report('2026-05-18', '2026-05-31');
        $receivable = $this->rowFor($report, 'accounts_receivable');

        if ($receivable === null) {
            return ['the receivables control is missing from a period that contains an invoice'];
        }

        $failures = [];

        if (BigDecimal::of((string) $receivable['opening'])->compareTo('0') !== 0) {
            $failures[] = sprintf(
                'an invoice dated the first day of the period is in the OPENING balance (%s), so the '.
                'month it was raised in reports it as something that was always there',
                (string) $receivable['opening'],
            );
        }

        if (BigDecimal::of((string) $receivable['movement'])->compareTo('1150') !== 0) {
            $failures[] = sprintf('the movement reads %s rather than 1,150', (string) $receivable['movement']);
        }

        return $failures;
    }

    /** @return list<string> */
    private function emptyAccountsAreOmitted(): array
    {
        $this->issue();

        $report = $this->report('2026-05-01', '2026-05-31');

        foreach ($report['rows'] as $row) {
            if (BigDecimal::of((string) $row['opening'])->isZero()
                && BigDecimal::of((string) $row['closing'])->isZero()) {
                return [sprintf(
                    'account %s opens and closes at zero and is on the report anyway - a trial balance '.
                    'padded with every unused account in the chart is one nobody reads to the bottom',
                    (string) $row['code'],
                )];
            }
        }

        return [];
    }

    /** @return list<string> */
    private function itSaysItBalances(): array
    {
        $this->issue();

        $report = $this->report('2026-05-01', '2026-05-31');
        $failures = [];

        if (($report['balances'] ?? false) !== true) {
            $failures[] = 'a ledger with only posted entries in it does not balance: '.
                (string) ($report['declaration']['en'] ?? '-');
        }

        /*
         | In words, in both languages.
         |
         | The boolean sits in an array a screen may ignore, a PDF may
         | not print and an export may drop. The sentence is what
         | reaches the page.
         */
        foreach (['en', 'ar'] as $language) {
            if (trim((string) ($report['declaration'][$language] ?? '')) === '') {
                $failures[] = sprintf('the report carries no declaration in %s', $language);
            }
        }

        if (($report['declaration']['severity'] ?? '') !== 'ok') {
            $failures[] = 'a balanced report does not say so at the severity a reader can skim';
        }

        return $failures;
    }

    /** @return list<string> */
    private function itShoutsWhenItDoesNot(): array
    {
        $this->issue();

        /*
         | One line written around the posting path.
         |
         | Which is the only way a trial balance can fail to balance: a
         | deferred constraint refuses an unbalanced ENTRY, so this
         | cannot be a posting that slipped through. It is a row
         | somebody wrote directly, or a read model that has drifted.
         */
        $line = DB::connection('tenant')->table('journal_lines')
            ->where('entity_id', $this->entityId)
            ->first();

        DB::connection('tenant')->table('journal_lines')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'journal_entry_id' => (string) $line->journal_entry_id,
            'accounting_date' => (string) $line->accounting_date,
            'period_id' => (string) $line->period_id,
            'account_id' => (string) $line->account_id,
            'amount' => '99.0000',
            'currency' => 'SAR',
            'functional_amount' => '99.0000',
            'narration' => 'a line written around the posting path',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = $this->report('2026-05-01', '2026-05-31', fromJournal: true);
        $failures = [];

        if (($report['balances'] ?? true) !== false) {
            return ['a 99.00 line written straight into the journal did not unbalance the trial '.
                'balance, so the report is not reading what it claims to'];
        }

        $declaration = (string) ($report['declaration']['en'] ?? '');

        if (($report['declaration']['severity'] ?? '') !== 'broken') {
            $failures[] = 'an unbalanced trial balance does not raise its severity, so a reader '.
                'skimming the page sees nothing wrong';
        }

        /*
         | And the sentence says three things: that it does not balance,
         | by how much, and what to do.
         |
         | "Does not balance" alone sends somebody to a spreadsheet.
         | Naming the difference and the command that finds its cause
         | sends them to the answer.
         */
        foreach ([
            'DOES NOT BALANCE' => 'that it does not balance, in words a reader cannot skim past',
            '99.00' => 'by how much',
            'rebuild-balances --check' => 'what to run to find the cause',
        ] as $needle => $what) {
            if (! str_contains($declaration, $needle)) {
                $failures[] = sprintf('the declaration does not say %s: %s', $what, $declaration);
            }
        }

        if (! str_contains($declaration, 'unreliable')) {
            $failures[] = 'the declaration does not say that the figures below it cannot be trusted, '.
                'which is the thing a reader most needs to know';
        }

        return $failures;
    }

    /** @return list<string> */
    private function aReversedPeriodIsRefused(): array
    {
        $refusal = $this->refusal(fn () => $this->report('2026-05-31', '2026-05-01'));

        return $refusal === null
            ? ['a period that ends before it starts was accepted, so the opening balance is later than '.
                'the closing one and the movement reads as its own negative']
            : [];
    }

    /** @return list<string> */
    private function translationCarriesItsBasis(): array
    {
        $this->issue();
        $this->aRate('SAR', 'USD', '0.266667', '2026-05-31');

        $report = $this->report('2026-05-01', '2026-05-31', presentIn: 'USD');
        $failures = [];

        if (($report['currency'] ?? '') !== 'USD') {
            $failures[] = 'the report was not presented in the currency asked for';
        }

        /*
         | The rate, the date and the source, on the output.
         |
         | A figure in a currency the books are not kept in is a
         | translation, and a translation nobody can reproduce is a
         | number that reconciles to nothing - which is the only thing
         | a reader can do with it when it disagrees with theirs.
         */
        foreach (['from', 'to', 'rate', 'as_of', 'source'] as $field) {
            if (trim((string) ($report['translation'][$field] ?? '')) === '') {
                $failures[] = sprintf('the translation does not state its %s', $field);
            }
        }

        $receivable = $this->rowFor($report, 'accounts_receivable');

        if ($receivable !== null
            && BigDecimal::of((string) $receivable['movement'])->compareTo("306.6671") !== 0) {
            $failures[] = sprintf(
                '1,150 at 0.266667 came out at %s rather than 306.6671',
                (string) $receivable['movement'],
            );
        }

        /*
         | And it says the books are still kept in the other currency.
         |
         | A presentation is not a second set of books, and a reader who
         | thinks it is will reconcile the wrong figures.
         */
        if (! str_contains((string) ($report['translation']['why'] ?? ''), 'not a second set')) {
            $failures[] = 'the translation does not say that it is a presentation rather than a '.
                'second set of books';
        }

        return $failures;
    }

    /** @return list<string> */
    private function translationNeedsARate(): array
    {
        $this->issue();

        $refusal = $this->refusal(fn () => $this->report('2026-05-01', '2026-05-31', presentIn: 'JPY'));

        if ($refusal === null) {
            return ['a report was presented in a currency with no rate on record, so its figures '.
                'cannot be reproduced and the reader cannot tell'];
        }

        return str_contains($refusal, 'reconciles to nothing')
            ? []
            : ['the refusal does not say why a rate-less translation is worse than no report: '.$refusal];
    }

    /** @return list<string> */
    private function theBasisIsStated(): array
    {
        $this->issue();

        $report = $this->report('2026-05-01', '2026-05-31');
        $failures = [];

        if (($report['basis'] ?? []) === []) {
            return ['the report carries no basis, so nobody can tell whether adjusting entries are in it'];
        }

        foreach (['declaration_ar', 'declaration_en'] as $field) {
            if (trim((string) ($report['basis'][$field] ?? '')) === '') {
                $failures[] = sprintf('the basis carries no %s', $field);
            }
        }

        if (($report['includes_adjusting'] ?? null) === null) {
            $failures[] = 'the report does not say whether adjusting entries are included';
        }

        return $failures;
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function report(
        string $from,
        string $until,
        bool $includeAdjusting = true,
        ?string $presentIn = null,
        bool $fromJournal = false,
    ): array {
        unset($fromJournal);

        return app(TrialBalanceReport::class)->forPeriod(
            entityId: $this->entityId,
            functionalCurrency: 'SAR',
            from: Carbon::parse($from),
            until: Carbon::parse($until),
            includeAdjusting: $includeAdjusting,
            presentIn: $presentIn,
        );
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>|null
     */
    private function rowFor(array $report, string $role): ?array
    {
        $code = (string) DB::connection('tenant')->table('account_roles')
            ->join('accounts', 'accounts.id', '=', 'account_roles.account_id')
            ->where('account_roles.entity_id', $this->entityId)
            ->where('account_roles.role', $role)
            ->value('accounts.code');

        foreach ($report['rows'] as $row) {
            if ((string) $row['code'] === $code) {
                return $row;
            }
        }

        return null;
    }

    private function aRate(string $from, string $to, string $value, string $on): void
    {
        DB::connection('tenant')->table('fx_rates')->insert([
            'id' => (string) Str::ulid(),
            'rate_set' => 'market',
            'from_currency' => $from,
            'to_currency' => $to,
            'rate' => $value,
            'as_of_date' => $on,
            'provider' => 'fixture',
            'fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A refusal, inside a savepoint. */
    private function refusal(callable $attempt): ?string
    {
        $savepoint = 'tb_'.bin2hex(random_bytes(4));

        $this->connection?->statement('SAVEPOINT '.$savepoint);

        try {
            $attempt();
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return null;
        } catch (Throwable $e) {
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return $e::class.': '.Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300);
        }
    }
}
