<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Alerts;

use App\Support\Telemetry\Alerts\Alert;
use App\Support\Telemetry\Alerts\InvariantWatcher;
use App\Support\Telemetry\Alerts\WatchScope;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Ledger\Services\LedgerFinding;
use App\Tenant\Ledger\Services\LedgerVerifier;

/**
 * `ledger:verify`, watched rather than merely runnable - PF-242.
 *
 * ## It adds no invariant and that is the whole idea
 *
 * Every condition this raises is one the ledger already proves: the
 * balanced-entry rule, the control accounts a posting rule has to reach, the
 * entry fingerprint, the audit chain, and whatever the other modules have
 * registered - suspense traceable to a bank line, in transit equal to
 * released-and-unreconciled, inventory equal to its movements.
 *
 * All of it was runnable before this class existed and none of it was
 * watched. Nothing in the repository invoked `ledger:verify` on a schedule,
 * so the first time a breach would have been noticed is the first time
 * somebody thought to ask - which in a financial system is an audit.
 *
 * ## One pass, three keys
 *
 * `verify()` is a dozen queries over the whole ledger. Splitting this into
 * three watchers so that each raised one key would mean three passes an hour
 * to produce the findings of one, and the three would eventually disagree
 * about what they had looked at.
 *
 * ## The mapping is explicit, and an unmapped critical finding still fires
 *
 * A `match` with a default that dropped the finding would mean a new check
 * registered by a future module is proved by `ledger:verify` and invisible to
 * the alerting - the exact gap this task exists to close. So anything
 * critical that is not recognised raises `subledger_mismatch`, which is the
 * key whose runbook says to find the posting that diverged.
 *
 * ## Warnings are NOT raised
 *
 * `entries_outside_the_proof` is a true statement about a reprovisioned
 * database and would fire every hour for ever, which is how an alert channel
 * becomes something people mute. The warnings are in `ledger:verify`'s output
 * where somebody reads them deliberately.
 */
final class LedgerInvariantWatcher implements InvariantWatcher
{
    public function __construct(private readonly LedgerVerifier $verifier) {}

    /** @return list<string> */
    public function keys(): array
    {
        return ['unbalanced_entry_in_database', 'subledger_mismatch', 'hash_chain_divergence'];
    }

    public function scope(): WatchScope
    {
        return WatchScope::Entity;
    }

    /** @return list<Alert> */
    public function watch(string $tenant, ?string $entityId): array
    {
        $findings = $this->verifier->verify($entityId)
            ->filter(static fn (LedgerFinding $finding): bool => $finding->isCritical())
            ->values();

        if ($findings->isEmpty()) {
            return [];
        }

        /*
         | Grouped by key, and only the FIRST of each is raised.
         |
         | A restore that dropped the balance trigger produces thousands of
         | unbalanced entries, and a page per entry is a pager nobody can
         | read - and the first one is the actionable fact, because it dates
         | the breach. The count rides along in the sentence so the reader
         | knows whether it is one entry or the whole ledger.
         */
        $grouped = [];

        foreach ($findings as $finding) {
            /** @var LedgerFinding $finding */
            $grouped[$this->keyFor($finding)][] = $finding;
        }

        $alerts = [];

        foreach ($grouped as $key => $group) {
            $first = $group[0];
            $period = $this->periodOf($entityId);

            $alerts[] = Alert::critical(
                key: $key,
                tenant: $tenant,
                entityId: $entityId ?? Alert::NO_ENTITY,
                period: $period,
                firstDivergence: $first->detail,
                messageAr: sprintf(
                    'انكسر ثابت في دفتر الأستاذ: %s. عدد النتائج %d، وأولها: %s. %s',
                    $first->kind,
                    count($group),
                    $first->detail,
                    $first->consequence,
                ),
                messageEn: sprintf(
                    'A ledger invariant is broken: %s. %d finding(s), the first being: %s %s',
                    $first->kind,
                    count($group),
                    $first->detail,
                    $first->consequence,
                ),
                observed: (string) count($group),
                threshold: '0',
            );
        }

        return $alerts;
    }

    private function keyFor(LedgerFinding $finding): string
    {
        return match (true) {
            str_contains($finding->kind, 'unbalanced') => 'unbalanced_entry_in_database',
            $finding->kind === 'audit_chain_broken' => 'hash_chain_divergence',
            $finding->kind === 'entry_substance_changed' => 'hash_chain_divergence',
            default => 'subledger_mismatch',
        };
    }

    /**
     * The period the alert belongs to.
     *
     * The oldest OPEN period, because that is the one a first response can
     * still act in: anything in a closed period is a restatement, and saying
     * so in the alert is what tells the reader which conversation they are
     * about to have. Where an entity has no open period the alert says that
     * rather than leaving the field empty.
     */
    private function periodOf(?string $entityId): string
    {
        if ($entityId === null) {
            return Alert::NO_PERIOD;
        }

        $period = Period::query()
            ->where('entity_id', $entityId)
            ->where('state', 'open')
            ->orderBy('starts_on')
            ->first();

        return $period === null
            ? '(no open period: every correction here is a restatement)'
            : (string) $period->label;
    }
}
