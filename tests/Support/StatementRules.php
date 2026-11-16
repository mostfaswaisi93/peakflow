<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Banking\Models\BankStatement;
use App\Tenant\Banking\Services\BankBalances;
use App\Tenant\Banking\Services\StatementImport;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Statements, and what an import refuses - PF-147, PF-148, PF-149.
 *
 * ## The three things that make this area trustworthy
 *
 * 1. **Continuity.** A statement's opening balance equals the previous
 *    one's closing balance, or the import stops. A reconciliation over a
 *    hole can never be completed and nobody knows why: the difference sits
 *    there for months and every month's work starts by rediscovering it.
 * 2. **Idempotency that reports.** People re-download overlapping files
 *    constantly. Importing the same line twice is a phantom duplicate
 *    payment; importing it silently and saying nothing is almost as bad,
 *    because the usual next step is to try again with a wider date range.
 * 3. **Immutability.** A transaction is a third party's assertion. A line
 *    somebody edited produces a statement that reconciles perfectly to our
 *    ledger and to nothing the bank has ever sent.
 *
 * ## And one thing that has to stay possible
 *
 * Two identical transactions on one day. A customer paying the same amount
 * twice in a morning is ordinary, and a deduplication that swallows the
 * second hides a real duplicate payment - which is the error this whole
 * area exists to catch. Every check about skipping is paired with that one.
 */
final class StatementRules
{
    use \Tests\Support\Concerns\OpensBankAccounts;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['statements (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheBankSide();

            return $this->runEachInASavepoint([
                // ---- Importing ------------------------------------------
                'a statement imports with its lines' => fn (): array => $this->imports(),
                'our sequence and the bank\'s number are separate' => fn (): array => $this->numbering(),
                'the first statement must match the opening balance' => fn (): array => $this->firstMustMatch(),
                'an unknown format is refused, and lists the known ones' => fn (): array => $this->unknownFormat(),
                'a reversed period is refused' => fn (): array => $this->reversedPeriod(),

                // ---- Lines that do not add up ---------------------------
                'lines that do not add up to the movement are refused' => fn (): array => $this->linesMustAddUp(),
                'and the refusal states the difference' => fn (): array => $this->differenceIsStated(),

                // ---- Continuity - PF-147 -------------------------------
                'a gap in continuity stops the import' => fn (): array => $this->gapIsRefused(),
                'and the refusal names the gap and both balances' => fn (): array => $this->gapIsNamed(),
                'continuity is on the balance, not the number' => fn (): array => $this->continuityIsOnTheBalance(),
                'a continuous second statement imports' => fn (): array => $this->continuousSecond(),

                // ---- Idempotency - PF-149 ------------------------------
                'the same file three times changes nothing, and says so' => fn (): array => $this->sameFileTwice(),
                'a statement cannot be deleted' => fn (): array => $this->statementsAreNotDeleted(),
                'an overlapping file imports only the new lines' => fn (): array => $this->overlappingFile(),
                'and reports every line it skipped' => fn (): array => $this->skipsAreReported(),
                'two identical transactions on one day stay two' => fn (): array => $this->twinsSurvive(),
                'a line with a bank reference is identified by it' => fn (): array => $this->referenceIsTheIdentity(),

                // ---- Immutability - PF-148 -----------------------------
                'a bank transaction cannot be edited' => fn (): array => $this->transactionsAreImmutable(),
                'and DELETE is revoked, on every partition' => fn (): array => $this->deleteIsRevoked(),
                'what we think about one goes somewhere else' => fn (): array => $this->enrichmentIsSeparate(),

                // ---- Superseding ---------------------------------------
                'a re-issued statement supersedes rather than replaces' => fn (): array => $this->supersedes(),
                'superseding needs a reason' => fn (): array => $this->supersedingNeedsAReason(),
                'a statement already superseded cannot be again' => fn (): array => $this->supersededOnce(),
                'a reason with nothing to supersede is refused' => fn (): array => $this->reasonWithoutAReplacement(),
                'and only by one for the same account' => fn (): array => $this->sameAccountOnly(),

                // ---- A deliberate gap ----------------------------------
                'a bank-side gap is recorded as a statement' => fn (): array => $this->recordedGap(),
                'and recording one needs a real explanation' => fn (): array => $this->gapNeedsAnExplanation(),

                // ---- Intraday ------------------------------------------
                'an intraday report does not set the baseline' => fn (): array => $this->intradayIsNotTheBaseline(),
                'and its lines are not unrecorded bank items' => fn (): array => $this->intradayIsNotUnrecorded(),
                'but it does feed the cash position' => fn (): array => $this->intradayFeedsThePosition(),

                // ---- Isolation -----------------------------------------
                'another entity cannot see these statements' => fn (): array => $this->entityScope(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    // ---- Importing --------------------------------------------------------

    /** @return list<string> */
    private function imports(): array
    {
        $report = $this->importFirstStatement();

        $failures = [];

        if ($report['imported'] !== 4) {
            $failures[] = sprintf('%d of the four lines were imported', (int) $report['imported']);
        }

        if ($report['skipped'] !== 0) {
            $failures[] = sprintf('%d lines were skipped on a first import', (int) $report['skipped']);
        }

        if ($this->transactionCount() !== 4) {
            $failures[] = sprintf('%d transaction rows exist', $this->transactionCount());
        }

        $statement = $this->statementOf((string) $report['statement_id']);

        if ($statement === null) {
            return [...$failures, 'the statement could not be read back'];
        }

        if ((string) $statement->closing()->amount !== '51500.0000') {
            $failures[] = sprintf('the closing balance stored as %s', (string) $statement->closing()->amount);
        }

        if ((string) $statement->movement()->amount !== '1500.0000') {
            $failures[] = sprintf('the movement computes as %s', (string) $statement->movement()->amount);
        }

        return $failures;
    }

    /** @return list<string> */
    private function numbering(): array
    {
        $statement = $this->statementOf((string) $this->importFirstStatement()['statement_id']);

        if ($statement === null) {
            return ['the statement could not be read back'];
        }

        $failures = [];

        if ((int) $statement->sequence !== 1) {
            $failures[] = sprintf('the first statement took sequence %d', (int) $statement->sequence);
        }

        /*
         | Ours and the bank's, side by side.
         |
         | Banks number statements per month, per page or per download, so a
         | continuity assertion on the number fires on a bank that simply
         | numbers differently - and the first thing anybody does with a
         | check that fires wrongly is turn it off. The bank's own label is
         | kept because the person reconciling has the paper statement in
         | front of them and that is what it says.
         */
        if ((string) $statement->statement_number !== '2026-05-A') {
            $failures[] = sprintf(
                'the bank\'s own number was stored as "%s" rather than kept verbatim',
                (string) $statement->statement_number,
            );
        }

        if ($statement->label() !== '2026-05-A') {
            $failures[] = sprintf('the statement is labelled "%s" rather than by the bank\'s number', $statement->label());
        }

        return $failures;
    }

    /** @return list<string> */
    private function firstMustMatch(): array
    {
        $refusal = $this->refusal(fn () => $this->importFirstStatement(
            openingBalance: '49000.0000',
            closingBalance: '50500.0000',
        ));

        if ($refusal === null) {
            return [
                'the first statement opened at 49,000 against an account whose opening balance is '.
                '50,000, and was accepted - which starts the account\'s history with a difference '.
                'nobody can explain',
            ];
        }

        $failures = [];

        foreach (['49000.0000', '50000.0000'] as $figure) {
            if (! str_contains($refusal, $figure)) {
                $failures[] = sprintf('the refusal does not name %s: %s', $figure, $refusal);
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function unknownFormat(): array
    {
        $refusal = $this->refusal(fn () => $this->importFirstStatement(sourceFormat: 'qif'));

        if ($refusal === null) {
            return ['a statement in an unknown format was imported'];
        }

        $failures = [];

        foreach (['camt053', 'mt940', 'csv'] as $known) {
            if (! str_contains($refusal, $known)) {
                $failures[] = sprintf('the refusal does not list %s among the known formats', $known);
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function reversedPeriod(): array
    {
        $refusal = $this->refusal(fn () => $this->importFirstStatement(
            periodFrom: $this->openingDate()->addDays(9),
            periodTo: $this->openingDate(),
        ));

        return $refusal === null
            ? ['a statement whose period ends before it starts was imported']
            : [];
    }

    // ---- Lines that do not add up ----------------------------------------

    /** @return list<string> */
    private function linesMustAddUp(): array
    {
        $lines = $this->statementLines();
        array_pop($lines);  // Drop the -500 line: the rest total +2,000.

        $refusal = $this->refusal(fn () => $this->importFirstStatement(lines: $lines));

        if ($refusal === null) {
            return [
                'a file whose lines total 2,000 against a stated movement of 1,500 was imported - '.
                'which is a parse that lost something, and the difference is the only sign of it',
            ];
        }

        return str_contains($refusal, 'lost something')
            ? []
            : [sprintf('the refusal does not say what a mismatch means: %s', $refusal)];
    }

    /** @return list<string> */
    private function differenceIsStated(): array
    {
        $lines = $this->statementLines();
        array_pop($lines);

        $refusal = (string) $this->refusal(fn () => $this->importFirstStatement(lines: $lines));

        $failures = [];

        /*
         | The figures, not just the fact.
         |
         | Somebody has a file open and is looking for one missing line. The
         | difference IS the search term - 500.0000 is what they grep the
         | file for - and a message that says only "the lines do not add up"
         | makes them compute it by hand from a thousand-line statement.
         */
        foreach (['1500.0000', '2000.0000', '-500.0000'] as $figure) {
            if (! str_contains($refusal, $figure)) {
                $failures[] = sprintf('the refusal does not state %s: %s', $figure, $refusal);
            }
        }

        if (! str_contains($refusal, '3 booked lines')) {
            $failures[] = 'the refusal does not say how many lines it counted';
        }

        return $failures;
    }

    // ---- Continuity -------------------------------------------------------

    /** @return list<string> */
    private function gapIsRefused(): array
    {
        $this->importFirstStatement();

        // Statement one closed at 51,500; this one opens at 52,000.
        $refusal = $this->refusal(fn () => $this->importSecondStatement(opening: '52000.0000'));

        return $refusal === null
            ? ['a statement opening 500 away from the previous closing balance was imported']
            : [];
    }

    /** @return list<string> */
    private function gapIsNamed(): array
    {
        $this->importFirstStatement();

        $refusal = (string) $this->refusal(fn () => $this->importSecondStatement(opening: '52000.0000'));

        $failures = [];

        foreach (['52000.0000', '51500.0000', '500.0000'] as $figure) {
            if (! str_contains($refusal, $figure)) {
                $failures[] = sprintf('the refusal does not name %s: %s', $figure, $refusal);
            }
        }

        if (! str_contains($refusal, 'statement 1')) {
            $failures[] = 'the refusal does not say which statement it compared against';
        }

        /*
         | And it offers the two legitimate routes.
         |
         | A person holding a file their bank will not re-issue needs a route
         | that is not the configuration flag. Without that sentence the only
         | visible way forward is to turn continuity off, after which the
         | next real gap passes silently.
         */
        if (! str_contains($refusal, 'Import the missing statement')) {
            $failures[] = 'the refusal does not say to import the missing statement';
        }

        if (! str_contains($refusal, 'record a bank-side gap deliberately')) {
            $failures[] = 'the refusal does not offer the deliberate-gap route, so the only visible route is the flag';
        }

        return $failures;
    }

    /** @return list<string> */
    private function continuityIsOnTheBalance(): array
    {
        $this->importFirstStatement();

        /*
         | The same bank number twice, continuous balances.
         |
         | A bank that numbers every download "1" is not a bank with a
         | continuity problem. An assertion on the number would refuse this,
         | and the check that fires wrongly is the check somebody disables.
         */
        try {
            $this->importSecondStatement(number: '2026-05-A');
        } catch (\Throwable $e) {
            return [sprintf(
                'two statements carrying the same number from the bank were refused although their '.
                'balances are continuous: %s',
                $e->getMessage(),
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function continuousSecond(): array
    {
        $this->importFirstStatement();
        $report = $this->importSecondStatement();

        $statement = $this->statementOf((string) $report['statement_id']);

        if ($statement === null) {
            return ['the second statement could not be read back'];
        }

        $failures = [];

        if ((int) $statement->sequence !== 2) {
            $failures[] = sprintf('the second statement took sequence %d', (int) $statement->sequence);
        }

        $balance = app(BankBalances::class)->statement($this->bank(), $this->date->copy());

        if ((string) $balance->amount !== '52000.0000') {
            $failures[] = sprintf(
                'the statement balance is %s rather than the newest closing balance of 52000.0000',
                (string) $balance->amount,
            );
        }

        return $failures;
    }

    // ---- Idempotency ------------------------------------------------------

    /**
     * The same file three times, which is PF-149's own wording.
     *
     * Three rather than two because the second import takes a different
     * path from the third: the second finds one existing statement, the
     * third finds a statement that was itself the answer to a repeat. A
     * check that stopped at two would not notice an implementation that
     * recorded the no-op as a new row with the same hash.
     *
     * @return list<string>
     */
    private function sameFileTwice(): array
    {
        $first = $this->importFirstStatement();
        $second = $this->importFirstStatement();
        $third = $this->importFirstStatement();

        $failures = [];

        foreach (['second' => $second, 'third' => $third] as $which => $report) {
            if ($report['already_imported'] !== true) {
                $failures[] = sprintf('the %s import of the same file was not recognised as a repeat', $which);
            }

            if ((string) $report['statement_id'] !== (string) $first['statement_id']) {
                $failures[] = sprintf('the %s import created another statement row for the same file', $which);
            }
        }

        $statements = BankStatement::query()
            ->where('bank_account_id', (string) $this->bank()->getKey())
            ->count();

        if ($statements !== 1) {
            $failures[] = sprintf('%d statement rows exist after three imports of one file', $statements);
        }

        if ($this->transactionCount() !== 4) {
            $failures[] = sprintf(
                '%d transactions exist after importing the same file three times - every one of the '.
                'extra rows is a phantom payment somebody will chase',
                $this->transactionCount(),
            );
        }

        /*
         | And it SAYS so.
         |
         | An import that does nothing and reports nothing leaves the person
         | unsure whether it worked, and the usual next step is to try again
         | with a wider date range - which is how the same afternoon gets
         | imported four times.
         */
        if (! str_contains((string) $second['why'], 'already imported')) {
            $failures[] = sprintf('the report does not say the file was already imported: %s', (string) $second['why']);
        }

        if (! str_contains((string) $second['why'], 'Nothing was changed')) {
            $failures[] = 'the report does not confirm that nothing changed';
        }

        return $failures;
    }

    /** @return list<string> */
    private function overlappingFile(): array
    {
        $this->importFirstStatement();

        /*
         | A week's file re-downloaded with one more day on the end.
         |
         | A different file, so the content hash does not catch it, and the
         | overlap has to be caught line by line. The new day's line is the
         | only one that should land.
         */
        $overlapping = [
            ...$this->statementLines(),
            [
                'booking_date' => $this->openingDate()->addDays(5)->toDateString(),
                'value_date' => $this->openingDate()->addDays(5)->toDateString(),
                'amount' => '750.0000',
                'bank_reference' => 'BR-ALPHA-003',
                'counterparty_name' => 'Gulf Retail LLC',
                'remittance_unstructured' => 'INV-1003',
            ],
        ];

        $report = $this->importFirstStatement(
            lines: $overlapping,
            closingBalance: '52250.0000',
            periodTo: $this->openingDate()->addDays(10),
            contentHash: hash('sha256', 'statement-a-redownloaded'),
            // The overlapping file re-states the period, so it supersedes.
            replaces: true,
        );

        $failures = [];

        if ($report['imported'] !== 1) {
            $failures[] = sprintf('%d lines were imported rather than the one new one', (int) $report['imported']);
        }

        if ($report['skipped'] !== 4) {
            $failures[] = sprintf('%d lines were skipped rather than the four already present', (int) $report['skipped']);
        }

        if ($this->transactionCount() !== 5) {
            $failures[] = sprintf(
                '%d transactions exist rather than five - an overlapping download is the normal case, '.
                'not an error',
                $this->transactionCount(),
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function skipsAreReported(): array
    {
        $this->importFirstStatement();

        $report = $this->importFirstStatement(
            contentHash: hash('sha256', 'statement-a-again'),
            replaces: true,
        );

        $failures = [];

        if (\count((array) $report['skipped_detail']) !== 4) {
            $failures[] = sprintf(
                'the report itemises %d skipped lines out of four',
                \count((array) $report['skipped_detail']),
            );
        }

        foreach ((array) $report['skipped_detail'] as $skipped) {
            foreach (['sequence', 'booking_date', 'amount', 'why'] as $key) {
                if (! \array_key_exists($key, (array) $skipped)) {
                    $failures[] = sprintf('a skipped line does not report its "%s"', $key);
                }
            }
        }

        /*
         | A line with the bank's reference is named by it.
         |
         | That is the string somebody searches their statement for to
         | confirm the skipped lines are the ones they expected. "4 lines
         | skipped" is a figure nobody can check.
         */
        $reasons = array_map(static fn (array $row): string => (string) $row['why'], (array) $report['skipped_detail']);

        if (! str_contains(implode(' ', $reasons), 'BR-ALPHA-001')) {
            $failures[] = 'a skipped line with a bank reference does not name it';
        }

        if (! str_contains((string) $report['why'], 'overlapping one already imported is normal')) {
            $failures[] = sprintf(
                'the summary does not say that an overlapping file is normal, so a legitimate '.
                're-import reads as a failure: %s',
                (string) $report['why'],
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function twinsSurvive(): array
    {
        $this->importFirstStatement();

        $rows = DB::connection('tenant')->table('bank_transactions')
            ->where('bank_account_id', (string) $this->bank()->getKey())
            ->where('amount', '500.0000')
            ->whereNull('bank_reference')
            ->get(['identity_key', 'statement_sequence']);

        $failures = [];

        if ($rows->count() !== 2) {
            $failures[] = sprintf(
                'a customer paying 500 twice in one morning left %d rows - collapsing the pair hides '.
                'a real duplicate payment, which is the error this whole area exists to catch',
                $rows->count(),
            );
        }

        $keys = $rows->pluck('identity_key')->unique();

        if ($keys->count() !== $rows->count()) {
            $failures[] = 'the two identical lines share an identity key, so the next import would swallow one';
        }

        /*
         | And what distinguishes them is their position in the statement.
         |
         | Nothing else can: same day, same amount, same counterparty, same
         | remittance. The statement sequence is the only thing the bank
         | gives us, which is why the generated key includes it.
         */
        $sequences = $rows->pluck('statement_sequence')->unique();

        if ($sequences->count() !== $rows->count()) {
            $failures[] = 'the two lines share a statement sequence, so nothing distinguishes them';
        }

        return $failures;
    }

    /** @return list<string> */
    private function referenceIsTheIdentity(): array
    {
        $this->importFirstStatement();

        $key = (string) DB::connection('tenant')->table('bank_transactions')
            ->where('bank_account_id', (string) $this->bank()->getKey())
            ->where('bank_reference', 'BR-ALPHA-001')
            ->value('identity_key');

        $failures = [];

        if (! str_starts_with($key, 'ref:')) {
            $failures[] = sprintf(
                'a line carrying the bank\'s own reference is identified by "%s" rather than by the '.
                'reference - which is the one identifier the bank guarantees is stable across '.
                'downloads',
                $key,
            );
        }

        if (! str_contains($key, 'BR-ALPHA-001')) {
            $failures[] = 'the identity key does not contain the reference';
        }

        $hashed = (string) DB::connection('tenant')->table('bank_transactions')
            ->where('bank_account_id', (string) $this->bank()->getKey())
            ->whereNull('bank_reference')
            ->value('identity_key');

        if (! str_starts_with($hashed, 'hash:')) {
            $failures[] = sprintf(
                'a line with no reference is identified by "%s" rather than by a hash of what the '.
                'bank did give us',
                $hashed,
            );
        }

        return $failures;
    }

    // ---- Immutability -----------------------------------------------------

    /** @return list<string> */
    private function transactionsAreImmutable(): array
    {
        $this->importFirstStatement();

        $id = (string) DB::connection('tenant')->table('bank_transactions')
            ->where('bank_account_id', (string) $this->bank()->getKey())
            ->where('bank_reference', 'BR-ALPHA-001')
            ->value('id');

        $failures = [];

        foreach ([
            'amount' => '9999.0000',
            'counterparty_name' => 'Somebody else',
            'remittance_unstructured' => 'A tidier description',
            'bank_reference' => 'BR-TIDIED',
        ] as $column => $value) {
            $refusal = $this->refusal(fn () => DB::connection('tenant')->table('bank_transactions')
                ->where('id', $id)
                ->update([$column => $value]));

            if ($refusal === null) {
                $failures[] = sprintf('%s was edited on an imported transaction', $column);

                continue;
            }

            if (! str_contains($refusal, 'dispute with the bank')) {
                $failures[] = sprintf('the refusal for %s does not say what to do instead: %s', $column, $refusal);
            }
        }

        /*
         | The two columns that MAY move.
         |
         | An intraday entry is explicitly replaced by the booked statement,
         | so `is_provisional` and `superseded_by_id` are the exceptions -
         | and they have to stay writable or the intraday path has no way to
         | retire its own rows.
         */
        $allowed = $this->refusal(fn () => DB::connection('tenant')->table('bank_transactions')
            ->where('id', $id)
            ->update(['is_provisional' => true]));

        if ($allowed !== null) {
            $failures[] = sprintf('an intraday line cannot be marked provisional: %s', $allowed);
        }

        return $failures;
    }

    /** @return list<string> */
    private function deleteIsRevoked(): array
    {
        $failures = [];

        /*
         | Checked as a PRIVILEGE, and on every partition.
         |
         | A trigger refusing a delete can be disabled by whoever owns the
         | table; a privilege the application role does not hold cannot be
         | used at all. And a partitioned table's privileges are checked per
         | relation, so a grant left on one month's partition is a hole that
         | opens for one month a year.
         */
        $granted = DB::connection('tenant')->scalar(
            'SELECT has_table_privilege(current_user, ?, ?)',
            ['bank_transactions', 'DELETE'],
        );

        if ($granted === true) {
            $failures[] = 'the application role holds DELETE on bank_transactions';
        }

        $partitions = DB::connection('tenant')->select(<<<'SQL'
            SELECT child.relname AS name,
                   has_table_privilege(current_user, child.oid, 'DELETE') AS deletable
            FROM pg_inherits i
            JOIN pg_class parent ON parent.oid = i.inhparent
            JOIN pg_class child ON child.oid = i.inhrelid
            WHERE parent.relname = 'bank_transactions'
        SQL);

        if ($partitions === []) {
            $failures[] = 'bank_transactions has no partitions, so the table is not partitioned at all';
        }

        foreach ($partitions as $partition) {
            if ($partition->deletable === true) {
                $failures[] = sprintf('DELETE is granted on partition %s', (string) $partition->name);
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function enrichmentIsSeparate(): array
    {
        $this->importFirstStatement();

        $row = DB::connection('tenant')->table('bank_transactions')
            ->where('bank_account_id', (string) $this->bank()->getKey())
            ->where('bank_reference', 'BR-ALPHA-001')
            ->first(['id', 'booking_date']);

        if ($row === null) {
            return ['the transaction could not be read back'];
        }

        try {
            DB::connection('tenant')->table('bank_transaction_enrichment')->insert([
                'id' => (string) Str::ulid(),
                'entity_id' => $this->entityId,
                'bank_transaction_id' => (string) $row->id,
                'booking_date' => (string) $row->booking_date,
                'party_id' => (string) $this->party?->getKey(),
                'party_source' => 'human',
                'normalised_counterparty' => 'Gulf Retail LLC',
                'extracted_reference' => 'INV-1001',
                'enriched_by' => $this->actorId(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            return [sprintf('what we think about a transaction could not be recorded: %s', $e->getMessage())];
        }

        /*
         | A party we decided on names HOW we decided.
         |
         | "The account number is on file" and "somebody chose from a list"
         | are the same party at different levels of confidence, and the
         | second is the one an auditor asks about.
         |
         | Attempted against a DIFFERENT transaction. The first version
         | re-used the one above, and `(bank_transaction_id, booking_date)`
         | is unique - so the insert was refused by the unique index whether
         | the source constraint existed or not, and the check passed for the
         | wrong reason. Proved by dropping the constraint: the check stayed
         | green.
         */
        $other = DB::connection('tenant')->table('bank_transactions')
            ->where('bank_account_id', (string) $this->bank()->getKey())
            ->where('bank_reference', 'BR-ALPHA-002')
            ->first(['id', 'booking_date']);

        if ($other === null) {
            return ['a second transaction could not be read back'];
        }

        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('bank_transaction_enrichment')
            ->insert([
                'id' => (string) Str::ulid(),
                'entity_id' => $this->entityId,
                'bank_transaction_id' => (string) $other->id,
                'booking_date' => (string) $other->booking_date,
                'party_id' => (string) $this->party?->getKey(),
                'party_source' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        if ($refusal === null) {
            return [
                'a party was attributed to a transaction without saying how it was decided - the '.
                'account number being on file and somebody choosing from a list are the same party '.
                'at different levels of confidence, and the second is the one an auditor asks about',
            ];
        }

        return str_contains($refusal, 'names_its_source')
            ? []
            : [sprintf('the refusal is not the source constraint, so something else caught it: %s', $refusal)];
    }

    // ---- Superseding ------------------------------------------------------

    /** @return list<string> */
    private function supersedes(): array
    {
        $first = $this->importFirstStatement();
        $old = $this->statementOf((string) $first['statement_id']);

        if ($old === null) {
            return ['the statement could not be read back'];
        }

        $report = $this->importFirstStatement(
            closingBalance: '51600.0000',
            lines: [...$this->statementLines(), [
                'booking_date' => $this->openingDate()->addDays(4)->toDateString(),
                'value_date' => $this->openingDate()->addDays(4)->toDateString(),
                'amount' => '100.0000',
                'bank_reference' => 'BR-ALPHA-004',
                'counterparty_name' => 'Riyadh Bank',
                'remittance_unstructured' => 'Interest credited',
            ]],
            contentHash: hash('sha256', 'statement-a-reissued'),
            replaces: true,
        );

        $old = $old->fresh();
        $failures = [];

        if ($old === null) {
            $failures[] = 'the superseded statement was deleted - it is the document somebody reconciled against';

            return $failures;
        }

        if (! $old->isSuperseded()) {
            $failures[] = sprintf('the old statement is "%s" rather than superseded', (string) $old->status);
        }

        if ((string) $old->superseded_by_id !== (string) $report['statement_id']) {
            $failures[] = 'the old statement does not point at its replacement';
        }

        if (trim((string) $old->supersession_reason) === '') {
            $failures[] = 'the supersession has no reason on the record';
        }

        $balance = app(BankBalances::class)->statement($this->bank(), $this->date->copy());

        if ((string) $balance->amount !== '51600.0000') {
            $failures[] = sprintf(
                'the statement balance is %s - the superseded statement is still being counted',
                (string) $balance->amount,
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function supersedingNeedsAReason(): array
    {
        $first = $this->importFirstStatement();
        $old = $this->statementOf((string) $first['statement_id']);

        if ($old === null) {
            return ['the statement could not be read back'];
        }

        $refusal = $this->refusal(fn () => app(StatementImport::class)->import(
            account: $this->bank(),
            sourceFormat: 'camt053',
            periodFrom: $this->openingDate(),
            periodTo: $this->openingDate()->addDays(9),
            openingBalance: '50000.0000',
            closingBalance: '51500.0000',
            lines: $this->statementLines(),
            contentHash: hash('sha256', 'statement-a-noreason'),
            userId: $this->actorId(),
            replaces: $old,
            supersessionReason: 'fixed',
        ));

        if ($refusal === null) {
            return ['a statement was superseded with "fixed" as the reason'];
        }

        return str_contains($refusal, 'no longer ties')
            ? []
            : [sprintf('the refusal does not say why a reason matters: %s', $refusal)];
    }

    /** @return list<string> */
    private function supersededOnce(): array
    {
        $first = $this->importFirstStatement();
        $old = $this->statementOf((string) $first['statement_id']);

        if ($old === null) {
            return ['the statement could not be read back'];
        }

        $this->importFirstStatement(
            contentHash: hash('sha256', 'statement-a-v2'),
            replaces: true,
        );

        $refusal = $this->refusal(fn () => $this->importFirstStatement(
            contentHash: hash('sha256', 'statement-a-v3'),
            replaces: true,
            replacing: $old->fresh(),
        ));

        if ($refusal === null) {
            return [
                'a statement already superseded was superseded again, leaving two statements claiming '.
                'to replace it and no way to tell which one the account\'s history runs through',
            ];
        }

        return str_contains($refusal, 'already superseded')
            ? []
            : [sprintf('the refusal does not say it was already superseded: %s', $refusal)];
    }

    /** @return list<string> */
    private function reasonWithoutAReplacement(): array
    {
        $refusal = $this->refusal(fn () => app(StatementImport::class)->import(
            account: $this->bank(),
            sourceFormat: 'camt053',
            periodFrom: $this->openingDate(),
            periodTo: $this->openingDate()->addDays(9),
            openingBalance: '50000.0000',
            closingBalance: '51500.0000',
            lines: $this->statementLines(),
            userId: $this->actorId(),
            supersessionReason: 'The bank re-issued this statement with a corrected reversal.',
        ));

        if ($refusal === null) {
            return [
                'a supersession reason was accepted with no statement to supersede, so the import '.
                'would read as having replaced something',
            ];
        }

        return str_contains($refusal, 'no statement to supersede')
            ? []
            : [sprintf('the refusal does not say the reason had no subject: %s', $refusal)];
    }

    /** @return list<string> */
    private function sameAccountOnly(): array
    {
        $first = $this->importFirstStatement();
        $old = $this->statementOf((string) $first['statement_id']);

        if ($old === null) {
            return ['the statement could not be read back'];
        }

        $second = $this->openAccount(
            openingBalance: '0',
            iban: self::SA_IBAN_OTHER,
            glAccountId: $this->secondBankGlAccountId(),
            label: $this->uniqueLabel('Other account'),
        );

        $refusal = $this->refusal(fn () => app(StatementImport::class)->import(
            account: $second,
            sourceFormat: 'camt053',
            periodFrom: $this->openingDate(),
            periodTo: $this->openingDate()->addDays(9),
            openingBalance: '0',
            closingBalance: '0',
            lines: [],
            userId: $this->actorId(),
            replaces: $old,
            supersessionReason: 'Trying to supersede another account\'s statement.',
        ));

        if ($refusal === null) {
            return ['one account\'s statement was superseded by another account\'s'];
        }

        return str_contains($refusal, 'different bank account')
            ? []
            : [sprintf('the refusal does not say the accounts differ: %s', $refusal)];
    }

    // ---- A deliberate gap -------------------------------------------------

    /** @return list<string> */
    private function recordedGap(): array
    {
        $this->importFirstStatement();

        try {
            $gap = app(StatementImport::class)->recordAGap(
                account: $this->bank(),
                periodFrom: $this->openingDate()->addDays(10),
                periodTo: $this->openingDate()->addDays(20),
                closingBalance: '52000.0000',
                userId: $this->actorId(),
                reason: 'Al Rajhi will not re-issue statements older than four years and this period '.
                        'predates the migration from the previous system.',
            );
        } catch (\Throwable $e) {
            return [sprintf('a bank-side gap could not be recorded: %s', $e->getMessage())];
        }

        $failures = [];

        /*
         | It opens where the last one closed, by construction.
         |
         | Which is the point: the gap is a continuous statement whose
         | movement is unexplained, rather than a break in continuity. The
         | difference between its opening and closing balance IS the gap,
         | and it is visible as a figure somebody signed for.
         */
        if ((string) $gap->opening_balance !== '51500.0000') {
            $failures[] = sprintf('the gap opens at %s rather than where the last statement closed', (string) $gap->opening_balance);
        }

        if ((string) $gap->movement()->amount !== '500.0000') {
            $failures[] = sprintf('the gap\'s unexplained movement computes as %s', (string) $gap->movement()->amount);
        }

        /*
         | Stated in words, not just present as digits.
         |
         | The first version looked for "500.0000" anywhere in the note,
         | which any sprintf accident satisfies - proved by a bite that
         | mangled the sentence into "deliberately.SAR500.0000" and left the
         | check green. What the record has to carry is the phrase somebody
         | reading the account's history three years later can act on.
         */
        if (! str_contains((string) $gap->note, 'Unexplained movement of SAR 500.0000')) {
            $failures[] = sprintf('the record does not state the unexplained movement: %s', (string) $gap->note);
        }

        if (! str_contains((string) $gap->note, 'Al Rajhi will not re-issue')) {
            $failures[] = 'the record does not keep the explanation';
        }

        // And the account's history runs through it.
        try {
            $this->importSecondStatement(
                opening: '52000.0000',
                closing: '52500.0000',
                from: $this->openingDate()->addDays(21),
                to: $this->openingDate()->addDays(25),
            );
        } catch (\Throwable $e) {
            $failures[] = sprintf('the statement after a recorded gap was refused: %s', $e->getMessage());
        }

        return $failures;
    }

    /** @return list<string> */
    private function gapNeedsAnExplanation(): array
    {
        $refusal = $this->refusal(fn () => app(StatementImport::class)->recordAGap(
            account: $this->bank(),
            periodFrom: $this->openingDate()->addDays(10),
            periodTo: $this->openingDate()->addDays(20),
            closingBalance: '52000.0000',
            userId: $this->actorId(),
            reason: 'bank lost it',
        ));

        if ($refusal === null) {
            return ['a bank-side gap was recorded with "bank lost it" as the explanation'];
        }

        return str_contains($refusal, 'which bank refused what')
            ? []
            : [sprintf('the refusal does not say what the explanation is for: %s', $refusal)];
    }

    // ---- Intraday ---------------------------------------------------------

    /** @return list<string> */
    private function intradayIsNotTheBaseline(): array
    {
        $this->importFirstStatement();
        $this->importSecondStatement(format: 'camt052');

        $balance = app(BankBalances::class)->statement($this->bank(), $this->date->copy());

        return (string) $balance->amount === '51500.0000'
            ? []
            : [sprintf(
                'an intraday report moved the reconciliation baseline to %s. CAMT.052 is provisional: '.
                'it carries the same movements the booked statement will, so treating it as final '.
                'double-counts every line on it when the CAMT.053 arrives',
                (string) $balance->amount,
            )];
    }

    /** @return list<string> */
    private function intradayIsNotUnrecorded(): array
    {
        $this->postTheOpeningEntry();
        $this->importFirstStatement();

        $before = app(BankBalances::class)->unrecordedBankItems($this->bank(), $this->date->copy());

        $this->importSecondStatement(format: 'camt052');

        $after = app(BankBalances::class)->unrecordedBankItems($this->bank(), $this->date->copy());

        $failures = [];

        if (! $after->amount->isEqualTo($before->amount)) {
            $failures[] = sprintf(
                'an intraday report added %s to the unrecorded bank items, which counts the same '.
                'movement twice once the booked statement arrives',
                (string) $after->minus($before)->amount,
            );
        }

        $provisional = DB::connection('tenant')->table('bank_transactions')
            ->where('bank_account_id', (string) $this->bank()->getKey())
            ->where('is_provisional', true)
            ->count();

        if ($provisional === 0) {
            $failures[] = 'the intraday lines were not marked provisional, so nothing distinguishes them';
        }

        return $failures;
    }

    /** @return list<string> */
    private function intradayFeedsThePosition(): array
    {
        $this->importFirstStatement();
        $this->importSecondStatement(format: 'camt052');

        $position = app(BankBalances::class)->intradayPosition($this->bank(), $this->date->copy());

        $failures = [];

        if ((string) $position['balance'] !== '52000.0000') {
            $failures[] = sprintf(
                'the cash position is %s and ignores the intraday report, which is the one thing the '.
                'report is for',
                (string) $position['balance'],
            );
        }

        if ($position['provisional'] !== true) {
            $failures[] = 'the cash position does not say it is provisional, so it reads as a settled figure';
        }

        if ((string) $position['basis'] !== 'camt052') {
            $failures[] = sprintf('the cash position does not name its basis: %s', (string) $position['basis']);
        }

        return $failures;
    }

    // ---- Isolation --------------------------------------------------------

    /** @return list<string> */
    private function entityScope(): array
    {
        $report = $this->importFirstStatement();

        $other = Entity::create([
            'code' => 'ST'.Str::upper(Str::random(6)),
            'legal_name' => 'A second entity in the same tenant',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $context = app(EntityContext::class);

        try {
            $context->use($other);

            $failures = [];

            if (BankStatement::query()->whereKey((string) $report['statement_id'])->count() !== 0) {
                $failures[] = 'a statement belonging to another entity is visible from this one';
            }

            return $failures;
        } finally {
            $context->use($this->entity());
        }
    }

    // ---- Helpers ----------------------------------------------------------


    /**
     * A statement is superseded, never deleted - PF-147.
     *
     * As a privilege rather than a trigger, for the same reason as the
     * transactions: a trigger refusing a delete can be disabled by whoever
     * owns the table, and a privilege the application role does not hold
     * cannot be used at all.
     *
     * The account goes the same way. It is closed, not deleted, because its
     * history is what every reconciliation since it opened was measured
     * against - and a deleted account takes its statements' foreign keys
     * with it.
     *
     * @return list<string>
     */
    private function statementsAreNotDeleted(): array
    {
        $failures = [];

        foreach (['bank_statements', 'bank_accounts'] as $table) {
            $granted = DB::connection('tenant')->scalar(
                'SELECT has_table_privilege(current_user, ?, ?)',
                [$table, 'DELETE'],
            );

            if ($granted === true) {
                $failures[] = sprintf(
                    'the application role holds DELETE on %s, so a console command can remove the '.
                    'document last month was reconciled against',
                    $table,
                );
            }
        }

        return $failures;
    }

    /**
     * A second statement, continuous with the first by default.
     *
     * @return array<string, mixed>
     */
    private function importSecondStatement(
        string $opening = '51500.0000',
        string $closing = '52000.0000',
        string $format = 'camt053',
        ?string $number = '2026-05-B',
        ?\Illuminate\Support\Carbon $from = null,
        ?\Illuminate\Support\Carbon $to = null,
    ): array {
        $from ??= $this->openingDate()->addDays(10);
        $to ??= $this->openingDate()->addDays(14);

        return app(StatementImport::class)->import(
            account: $this->bank(),
            sourceFormat: $format,
            periodFrom: $from,
            periodTo: $to,
            openingBalance: $opening,
            closingBalance: $closing,
            lines: [[
                'booking_date' => $from->copy()->addDay()->toDateString(),
                'value_date' => $from->copy()->addDay()->toDateString(),
                'amount' => \Brick\Math\BigDecimal::of($closing)->minus($opening)->__toString(),
                'bank_reference' => 'BR-BETA-'.Str::upper(Str::random(5)),
                'counterparty_name' => 'Gulf Retail LLC',
                'remittance_unstructured' => 'INV-2001',
            ]],
            statementNumber: $number,
            contentHash: hash('sha256', 'statement-b-'.$format.'-'.$opening.'-'.$closing.'-'.$from->toDateString()),
            userId: $this->actorId(),
        );
    }
}
