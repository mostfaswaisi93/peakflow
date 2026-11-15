<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

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
        ]);
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
    private function postingsInUnacceptingPeriods(?string $entityId): array
    {
        $rows = $this->query($entityId, <<<'SQL'
            SELECT e.number, p.label, p.state
            FROM journal_entries e
            JOIN periods p ON p.id = e.period_id
            WHERE e.status = 'posted'
              AND p.state NOT IN ('open', 'soft_closed')
              AND (cast(? AS char(26)) IS NULL OR e.entity_id = cast(? AS char(26)))
            LIMIT 50
        SQL);

        return array_map(static fn (object $row): LedgerFinding => LedgerFinding::critical(
            'posting in a closed period',
            sprintf('Entry %s is posted in period %s, which is %s.', $row->number, $row->label, $row->state),
            'A figure somebody has already reported on has changed since they reported it.',
        ), $rows);
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
