<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Support\Audit\AuditChain;
use App\Tenant\Ledger\Enums\ControlRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The global proof - PF-054.
 *
 * ## Why a global check and not more unit tests
 *
 * An individual assertion can be written to pass. A test that posts an
 * invoice and then asserts the receivable moved by the invoice total will
 * pass even if the rule posts to the wrong receivable account, posts twice,
 * or posts into a closed period - because the test only looks at what it
 * thought to look at.
 *
 * These checks look at the whole ledger and ask questions no individual test
 * can fake: does every entry balance, does every subledger equal its control
 * account, is anything posted where it should not be. They are run as the
 * final assertion of every feature test that posts, so a rule that breaks an
 * invariant fails the test that exercised it rather than a test written later
 * by someone who guessed the right question.
 *
 * ## It reports, it does not repair
 *
 * Silent repair hides the bug that caused the drift, and the bug will produce
 * it again - differently, later, after more has been built on top.
 */
final class LedgerVerifier
{
    public function __construct(
        private readonly AuditChain $chain,
        private readonly \App\Support\Verification\LedgerChecks $registered = new \App\Support\Verification\LedgerChecks(),
    ) {}

    /**
     * @return Collection<int, LedgerFinding>
     */
    public function verify(?string $entityId = null): Collection
    {
        return collect([
            ...$this->unbalancedEntries($entityId),
            ...$this->postingsInUnacceptingPeriods($entityId),
            ...$this->linesOutOfStepWithTheirEntry($entityId),
            ...$this->controlAccountsPostedManually($entityId),
            ...$this->orphanedLines($entityId),
            ...$this->translationsWithNoProvenance($entityId),
            ...$this->translationsAtStaleRates($entityId),
            ...$this->entriesWhoseSubstanceChanged($entityId),
            ...$this->postedEntriesWithNoFingerprint($entityId),
            ...$this->theAuditChain(),
            /*
             | And whatever other modules assert about the ledger - PF-163.
             |
             | Through a registry in `Support`, so the verifier does not
             | import the banking service to ask whether suspense is
             | traceable. The ledger depends on nothing but `Tenancy`, and
             | the first module reached from here directly would carry the
             | rest in behind it.
             */
            ...$this->fromRegisteredChecks($entityId),
        ]);
    }

    /**
     * Findings contributed by other modules.
     *
     * @return list<LedgerFinding>
     */
    private function fromRegisteredChecks(?string $entityId): array
    {
        return array_map(
            static fn (array $finding): LedgerFinding => $finding['severity'] === 'warning'
                ? LedgerFinding::warning($finding['kind'], $finding['detail'], $finding['consequence'])
                : LedgerFinding::critical($finding['kind'], $finding['detail'], $finding['consequence']),
            $this->registered->findings($entityId),
        );
    }

    /**
     * Lines that used a rate and cannot say which one - PF-069.
     *
     * A warning, not a critical finding, and not something the database
     * refuses on these rows: the completeness constraint on `journal_lines`
     * is NOT VALID, so it governs every new line and leaves alone the ones
     * translated before the provenance columns existed.
     *
     * That was deliberate. Those lines have a rate value and nothing else,
     * and the provenance cannot be recovered - two feeds can have sent the
     * same figure for different days, and the row may since have been
     * superseded. Filling the columns with a plausible guess would be
     * inventing audit evidence.
     *
     * So they are reported by name instead. "We know these cannot be traced
     * and here they are" is a position an auditor can work with; discovering
     * it mid-audit is not.
     *
     * @return list<LedgerFinding>
     */
    private function translationsWithNoProvenance(?string $entityId): array
    {
        $rows = $this->query($entityId, <<<'SQL'
            SELECT e.number, count(*) AS lines
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            WHERE l.fx_rate IS NOT NULL
              AND (l.fx_rate_set IS NULL OR l.fx_rate_as_of IS NULL OR l.fx_rate_provider IS NULL)
              AND (cast(? AS char(26)) IS NULL OR l.entity_id = cast(? AS char(26)))
            GROUP BY e.number
            LIMIT 50
        SQL);

        return array_map(static fn (object $row): LedgerFinding => LedgerFinding::warning(
            'translation with no provenance',
            sprintf(
                'Entry %s has %d line(s) carrying a rate with no date, set or provider.',
                $row->number,
                (int) $row->lines,
            ),
            'The figure can be checked but not placed: nobody can say whether the rate was current, whether it '.
            'was the market or the statutory one, or which feed supplied it. These lines predate the provenance '.
            'columns and the information cannot be recovered - a guess here would be invented audit evidence.',
        ), $rows);
    }

    /**
     * Postings translated at a rate older than its set allows - PF-069.
     *
     * The requirement is that a stale rate is flagged on every document that
     * uses it, and this is the ledger-wide form of the same question. It is a
     * warning because trading on an old rate is legitimate - a feed outage
     * must not stop a business issuing invoices - and silently normal is what
     * it must never be.
     *
     * Measured against the LINE's accounting date rather than today, because
     * the question is whether the rate was stale when the posting was made.
     * Against today, every rate in a closed year eventually looks stale and
     * the finding becomes noise nobody reads.
     *
     * @return list<LedgerFinding>
     */
    private function translationsAtStaleRates(?string $entityId): array
    {
        $rows = $this->query($entityId, <<<'SQL'
            SELECT
                e.number,
                l.fx_rate_set,
                max(l.accounting_date - l.fx_rate_as_of) AS days_old,
                s.staleness_threshold_days AS threshold,
                count(*) AS lines
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN fx_rate_sets s ON s.code = l.fx_rate_set
            WHERE l.fx_rate_as_of IS NOT NULL
              AND l.accounting_date - l.fx_rate_as_of > s.staleness_threshold_days
              AND (cast(? AS char(26)) IS NULL OR l.entity_id = cast(? AS char(26)))
            GROUP BY e.number, l.fx_rate_set, s.staleness_threshold_days
            LIMIT 50
        SQL);

        return array_map(static fn (object $row): LedgerFinding => LedgerFinding::warning(
            'posted at a stale rate',
            sprintf(
                'Entry %s used a %s rate up to %d days old, against a threshold of %d, on %d line(s).',
                $row->number,
                $row->fx_rate_set,
                (int) $row->days_old,
                (int) $row->threshold,
                (int) $row->lines,
            ),
            'Trading on an old rate is legitimate when a feed is down and must not block an invoice - but it '.
            'has to be visible, because a month of invoices at a stale rate produces a difference that is '.
            'found at the close and explained by nobody.',
        ), $rows);
    }

    /**
     * Every entry sums to zero in the functional currency.
     *
     * The deferred trigger makes this impossible to violate through the
     * database - which is exactly why it is checked: if this ever finds
     * something, the trigger has been dropped, disabled, or bypassed by a
     * restore, and that is worth knowing before the trial balance is.
     *
     * @return list<LedgerFinding>
     */
    private function unbalancedEntries(?string $entityId): array
    {
        $rows = $this->query($entityId, <<<'SQL'
            SELECT e.id, e.number, sum(l.functional_amount) AS imbalance
            FROM journal_entries e
            JOIN journal_lines l ON l.journal_entry_id = e.id
            WHERE e.status <> 'draft'
              AND (cast(? AS char(26)) IS NULL OR e.entity_id = cast(? AS char(26)))
            GROUP BY e.id, e.number
            HAVING sum(l.functional_amount) <> 0
            LIMIT 50
        SQL);

        return array_map(static fn (object $row): LedgerFinding => LedgerFinding::critical(
            'unbalanced entry',
            sprintf('Entry %s is out by %s in the functional currency.', $row->number, $row->imbalance),
            'The deferred balance trigger cannot be violated through the database, so if this appears it has '.
            'been dropped, disabled, or the data came from a restore that skipped it.',
        ), $rows);
    }

    /**
     * Nothing is posted into a period that does not accept postings.
     *
     * @return list<LedgerFinding>
     */
    /**
     * Entries posted while their period did not accept postings - PF-054.
     *
     * ## Two earlier versions of this were wrong, in opposite directions
     *
     * The first asked whether the entry's period is closed TODAY. Closing
     * February does not invalidate the entries posted into February while it
     * was open, so in production this would have reported every entry in
     * every closed period from the first month-end - thousands of critical
     * findings, every one of them correct behaviour. Found by running the
     * global proof at the end of the ledger suite, where a year-end close
     * legitimately closes a period it had just posted into.
     *
     * The second read the state from the transition log at the entry's
     * `posted_at`. Right question, and the data cannot answer it: `now()` is
     * the TRANSACTION timestamp, so a close and a posting in one transaction
     * carry the identical instant and cannot be ordered. Measured on a
     * year-end close and its reversal: eight transitions and three entries,
     * all stamped the same second.
     *
     * So the state is read from `period_state_when_posted`, which the period
     * trigger writes down as it decides whether to refuse. A recorded fact
     * instead of a reconstruction.
     *
     * ## And NULL is the other half of the finding
     *
     * The column is only ever written by the trigger a bypass goes around, so
     * "posted and recording no state" is the trace of a write the trigger
     * never saw. Separated by date from the entries that predate the column,
     * because those are a migration boundary rather than a bypass.
     */
    private function postingsInUnacceptingPeriods(?string $entityId): array
    {
        $rows = $this->query($entityId, <<<'SQL'
            SELECT
                e.number,
                p.label,
                p.state AS state_now,
                e.period_state_when_posted AS state_then,
                coalesce(e.posted_at, e.entered_at, e.created_at) AS posted_at
            FROM journal_entries e
            JOIN periods p ON p.id = e.period_id
            -- A reversed entry was posted too, and if it went into a period
            -- that was not accepting that is just as wrong as a live one
            -- being there.
            WHERE e.status IN ('posted', 'reversed')
              AND e.period_state_when_posted IS NOT NULL
              AND e.period_state_when_posted NOT IN ('open', 'soft_closed')
              AND (cast(? AS char(26)) IS NULL OR e.entity_id = cast(? AS char(26)))
            LIMIT 50
        SQL);

        $findings = array_map(static fn (object $row): LedgerFinding => LedgerFinding::critical(
            'posting into a period that was not accepting',
            sprintf(
                'Entry %s was posted at %s, when period %s was %s. It is %s now.',
                (string) $row->number,
                (string) $row->posted_at,
                (string) $row->label,
                (string) $row->state_then,
                (string) $row->state_now,
            ),
            'The period trigger records the state it saw as it decides whether to refuse, so an entry '.
            'carrying a non-accepting state got past it. A figure has entered a period somebody had '.
            'already reported on.',
        ), $rows);

        return [...$findings, ...$this->postingsThatRecordedNoPeriodState($entityId)];
    }

    /**
     * Posted entries that recorded no period state at all.
     *
     * Reported as one finding with a count, not one per entry: on a database
     * that predates the column every entry is in this set, and fifty thousand
     * findings is a report nobody reads.
     *
     * @return list<LedgerFinding>
     */
    private function postingsThatRecordedNoPeriodState(?string $entityId): array
    {
        $rows = $this->query($entityId, <<<'SQL'
            SELECT
                count(*)               AS entries,
                min(e.accounting_date) AS earliest,
                max(e.accounting_date) AS latest
            FROM journal_entries e
            WHERE e.status IN ('posted', 'reversed')
              AND e.period_state_when_posted IS NULL
              AND (cast(? AS char(26)) IS NULL OR e.entity_id = cast(? AS char(26)))
        SQL);

        $row = $rows[0] ?? null;

        if ($row === null || (int) $row->entries === 0) {
            return [];
        }

        return [LedgerFinding::warning(
            'postings that recorded no period state',
            sprintf(
                '%d posted entr%s between %s and %s recorded no period state.',
                (int) $row->entries,
                (int) $row->entries === 1 ? 'y' : 'ies',
                (string) $row->earliest,
                (string) $row->latest,
            ),
            'The column is written only by the trigger that decides whether a period accepts a posting, '.
            'so an entry with none was either posted before the column existed - a fixed set that does '.
            'not grow - or written by something that went around the trigger. A number that grows is the '.
            'second.',
        )];
    }

    /**
     * A line's date and period match its entry's.
     *
     * Denormalized for partitioning and kept in step by a trigger, so a
     * divergence means a line is filed in a different month from its entry -
     * invisible to every report bounded by the entry's period and present in
     * every report bounded by the line's.
     *
     * @return list<LedgerFinding>
     */
    private function linesOutOfStepWithTheirEntry(?string $entityId): array
    {
        $rows = $this->query($entityId, <<<'SQL'
            SELECT e.number, l.accounting_date AS line_date, e.accounting_date AS entry_date
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            WHERE (l.accounting_date <> e.accounting_date OR l.period_id <> e.period_id)
              AND (cast(? AS char(26)) IS NULL OR l.entity_id = cast(? AS char(26)))
            LIMIT 50
        SQL);

        return array_map(static fn (object $row): LedgerFinding => LedgerFinding::critical(
            'line out of step with its entry',
            sprintf('Entry %s is dated %s but has a line dated %s.', $row->number, $row->entry_date, $row->line_date),
            'The line is in a different partition from its entry, so one report counts it and another does not.',
        ), $rows);
    }

    /**
     * A control account was reached by something other than a posting rule.
     *
     * The database refuses this at insert time, so a finding means the row
     * predates the trigger or arrived through a path that set the posting-rule
     * variable without being a rule.
     *
     * @return list<LedgerFinding>
     */
    private function controlAccountsPostedManually(?string $entityId): array
    {
        $rows = $this->query($entityId, <<<'SQL'
            SELECT e.number, r.role, a.code
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN account_roles r ON r.account_id = l.account_id
            JOIN accounts a ON a.id = l.account_id
            WHERE e.posting_rule IS NULL
              AND e.status <> 'draft'
              AND (cast(? AS char(26)) IS NULL OR l.entity_id = cast(? AS char(26)))
            LIMIT 50
        SQL);

        return array_map(static fn (object $row): LedgerFinding => LedgerFinding::critical(
            'manual posting to a control account',
            sprintf('Entry %s touches %s, the %s control account, with no posting rule recorded.',
                $row->number, $row->code, $row->role),
            'The subledger and the control account now disagree, and neither can be shown to be the correct '.
            'one without re-deriving the whole history.',
        ), $rows);
    }

    /**
     * Every line belongs to an entry that exists.
     *
     * @return list<LedgerFinding>
     */
    private function orphanedLines(?string $entityId): array
    {
        $rows = $this->query($entityId, <<<'SQL'
            SELECT l.id, l.functional_amount
            FROM journal_lines l
            LEFT JOIN journal_entries e ON e.id = l.journal_entry_id
            WHERE e.id IS NULL
              AND (cast(? AS char(26)) IS NULL OR l.entity_id = cast(? AS char(26)))
            LIMIT 50
        SQL);

        return array_map(static fn (object $row): LedgerFinding => LedgerFinding::critical(
            'orphaned line',
            sprintf('Line %s (%s) belongs to no entry.', $row->id, $row->functional_amount),
            'It is counted in every balance and explicable by nothing.',
        ), $rows);
    }

    /**
     * Each subledger control account equals the sum of its own lines.
     *
     * Checked per role so that a discrepancy names which one. Until the
     * subledgers exist (PF-05x onwards) this compares the control account to
     * itself and is therefore trivially true - it is here now because
     * `ledger:verify` is wired into every posting test from the start, and a
     * check added later is a check nobody runs against the history.
     *
     * @return Collection<int, array{role: string, total: string}>
     */
    /**
     * Entries whose substance no longer matches their fingerprint - PF-054.
     *
     * ## The one check the immutability triggers cannot make
     *
     * They refuse an UPDATE to a posted entry, which proves that no statement
     * went THROUGH them and changed a figure. It proves nothing about a
     * statement that did not: a role that can disable a trigger, a restore
     * from a doctored dump, a superuser session, a bug in a future migration.
     *
     * Each of those leaves a ledger where every trigger is present and
     * correct and a figure has moved - and the trial balance still ties,
     * because a tamper that balances is the only kind worth doing.
     *
     * The fingerprint is recomputed in the database by the same function that
     * wrote it, so this compares like with like. A changed amount, a moved
     * account, an added or removed line, a re-dated entry: each changes the
     * hash.
     *
     * @return list<LedgerFinding>
     */
    public function entriesWhoseSubstanceChanged(?string $entityId = null): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT
                e.number,
                e.accounting_date,
                f.fingerprinted_at,
                f.line_count AS lines_then,
                (SELECT count(*) FROM journal_lines l WHERE l.journal_entry_id = e.id) AS lines_now
            FROM journal_entry_fingerprints f
            JOIN journal_entries e ON e.id = f.journal_entry_id
            WHERE (cast(? AS char(26)) IS NULL OR e.entity_id = cast(? AS char(26)))
              AND peakflow_entry_fingerprint(e.id) <> f.content_hash
            ORDER BY e.accounting_date, e.number
        SQL, [$entityId, $entityId]);

        return array_map(
            static fn (object $row): LedgerFinding => LedgerFinding::critical(
                'entry_substance_changed',
                sprintf(
                    'Entry %s of %s no longer matches the fingerprint taken when it was posted at %s. It '.
                    'had %d line(s) then and has %d now.',
                    (string) $row->number,
                    (string) $row->accounting_date,
                    (string) $row->fingerprinted_at,
                    (int) $row->lines_then,
                    (int) $row->lines_now,
                ),
                'A posted figure has changed without going through the immutability trigger, which means '.
                'it was changed by something that bypassed the application entirely - a disabled trigger, '.
                'a restored dump, a direct session. Every report printed from this entry since it was '.
                'posted is wrong, and the trial balance may still tie: a tamper that balances is the only '.
                'kind worth doing. Stop writing, find out what ran, and restore from a backup taken '.
                'before the fingerprint diverged.',
            ),
            $rows,
        );
    }

    /**
     * Posted entries with no fingerprint at all - PF-054.
     *
     * A warning rather than critical, because there is a legitimate reason
     * for it: entries posted before the fingerprint existed have none, and
     * cannot be given a trustworthy one after the fact - a retroactive hash
     * certifies whatever the table says today.
     *
     * It is still worth reporting, and the count is the point. A handful of
     * old entries is the migration boundary. A growing number means something
     * is writing entries in a way that bypasses the deferred trigger, and
     * those entries are outside the proof.
     *
     * @return list<LedgerFinding>
     */
    public function postedEntriesWithNoFingerprint(?string $entityId = null): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT
                count(*)             AS entries,
                min(e.accounting_date) AS earliest,
                max(e.accounting_date) AS latest
            FROM journal_entries e
            LEFT JOIN journal_entry_fingerprints f ON f.journal_entry_id = e.id
            WHERE (cast(? AS char(26)) IS NULL OR e.entity_id = cast(? AS char(26)))
              AND e.status IN ('posted', 'reversed')
              AND f.journal_entry_id IS NULL
        SQL, [$entityId, $entityId]);

        $row = $rows[0] ?? null;

        if ($row === null || (int) $row->entries === 0) {
            return [];
        }

        return [LedgerFinding::warning(
            'entries_outside_the_proof',
            sprintf(
                '%d posted entr%s between %s and %s carr%s no fingerprint.',
                (int) $row->entries,
                (int) $row->entries === 1 ? 'y' : 'ies',
                (string) $row->earliest,
                (string) $row->latest,
                (int) $row->entries === 1 ? 'ies' : 'y',
            ),
            'Those entries are outside the tamper proof: a change to one of them would not be detected. '.
            'Entries posted before the fingerprint existed legitimately have none and cannot be given a '.
            'trustworthy one afterwards. A number that GROWS means something is writing entries in a way '.
            'that bypasses the deferred trigger.',
        )];
    }

    /**
     * The audit chain, as part of the same proof - PF-054, PF-035.
     *
     * Through the `Support` contract, because the chain belongs to `Identity`
     * and the ledger may not depend on it.
     *
     * Two claims make up "no posted entry has been mutated" and this is the
     * second. The fingerprint says the entry's substance is unchanged; the
     * chain says the record of who did what has not been edited. Without the
     * chain, whoever changed a figure could delete the audit entry showing
     * they did. Without the fingerprint, an intact chain proves only that
     * nobody edited the LOG.
     *
     * @return list<LedgerFinding>
     */
    public function theAuditChain(): array
    {
        $verdict = $this->chain->verdict();

        if ($verdict['intact']) {
            return [];
        }

        return [LedgerFinding::critical(
            'audit_chain_broken',
            $verdict['detail'] ?? sprintf('the audit chain diverges after %d record(s)', $verdict['checked']),
            'The record of who changed what has been edited or excised. Everything before the divergence '.
            'is still intact and everything after it is unverifiable - including, for any figure that '.
            'moved, the entry that would say who moved it.',
        )];
    }

    public function controlTotals(string $entityId): Collection
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT r.role, coalesce(sum(l.functional_amount), 0) AS total
            FROM account_roles r
            LEFT JOIN journal_lines l ON l.account_id = r.account_id
            WHERE r.entity_id = ?
            GROUP BY r.role
            ORDER BY r.role
        SQL, [$entityId]);

        return collect($rows)->map(static fn (object $row): array => [
            'role' => (string) $row->role,
            'total' => (string) $row->total,
        ]);
    }

    /**
     * @return list<object>
     *
     * The entity parameter is cast explicitly in every query. A bare `?` in
     * `? IS NULL` gives PostgreSQL nothing to infer a type from, and the
     * whole statement fails with "could not determine data type of parameter
     * $1" - which says nothing about which of five queries it was.
     */
    private function query(?string $entityId, string $sql): array
    {
        return DB::connection('tenant')->select($sql, [$entityId, $entityId]);
    }

    /** The roles a complete entity must have, for the report's summary. */
    public function requiredRoles(): array
    {
        return array_values(array_filter(
            ControlRole::cases(),
            static fn (ControlRole $role): bool => $role->isRequiredToPost(),
        ));
    }
}
