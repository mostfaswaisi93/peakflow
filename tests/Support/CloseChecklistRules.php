<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Closing\ClosingChecks;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * What must stop a period closing - PF-196.
 *
 * ## Why each of these is blocking rather than a warning
 *
 * Closing a period is not reversible in the way people assume. Reopening
 * costs a permission, a step-up and a written reason, and anything found
 * afterwards is a prior-period adjustment under IAS 8 - a conversation
 * with an auditor rather than a command.
 *
 * So the question for each item is not "is this untidy" but "what does
 * closing over it freeze". A draft entry becomes unpostable and its
 * revenue never reaches the accounts. A drifted balance becomes the figure
 * somebody reported. Neither is visible on any report afterwards, which is
 * exactly why neither can be left to a warning somebody reads and
 * dismisses.
 *
 * ## And why every reason comes back at once
 *
 * A close that failed on the first problem would be found three times by
 * the same person on the same afternoon. The registry collects every
 * reason from every check, and a check that THROWS is itself a reason -
 * because a period whose checks nobody could compute must not close.
 */
final class CloseChecklistRules
{
    use Concerns\IssuesInvoices;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['the close checklist (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->runEachInASavepoint([
                'both ledger blockers are registered' => fn (): array => $this->theyAreRegistered(),
                'a draft in the period stops the close' => fn (): array => $this->aDraftBlocks(),
                'and a draft in another period does not' => fn (): array => $this->aDraftElsewhereDoesNot(),
                'drifted balances stop the close' => fn (): array => $this->driftBlocks(),
                'every reason comes back, not just the first' => fn (): array => $this->allReasonsAtOnce(),
                'a check that throws is itself a reason' => fn (): array => $this->aThrowingCheckBlocks(),
                'and the state machine refuses with them' => fn (): array => $this->theStateMachineRefuses(),
            ]);
        } catch (Throwable $e) {
            return ['the close checklist could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function theyAreRegistered(): array
    {
        $names = app(ClosingChecks::class)->names();
        $violations = [];

        foreach (['read-model drift', 'unposted entries'] as $expected) {
            if (! \in_array($expected, $names, true)) {
                $violations[] = sprintf(
                    'the "%s" check is not registered, so nothing consults it and the period closes '.
                    'over it in silence. Registered checks: %s',
                    $expected,
                    implode(', ', $names),
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function aDraftBlocks(): array
    {
        $this->draft();

        $reasons = $this->reasons();

        foreach ($reasons as $reason) {
            if (str_contains($reason, 'still unposted')) {
                return [];
            }
        }

        return ['a draft journal entry dated inside the period did not stop the close. Closing makes it '.
            'unpostable, so what it carries never reaches the accounts and the return filed from them '.
            'is short by exactly that - invisibly, because a draft is on no report. Reasons given: '
            .($reasons === [] ? '(none)' : implode(' | ', $reasons))];
    }

    /** @return list<string> */
    private function aDraftElsewhereDoesNot(): array
    {
        /*
         | A draft on the FIRST DAY OF THE NEXT PERIOD.
         |
         | `until` is the first day NOT in the period, so the bound is
         | `< until`. A check written with `<=` would pull that draft in and
         | block the May close for work dated in June - and a check that
         | blocks a close for work nobody has started is a check somebody
         | switches off.
         |
         | The date has to be exactly the boundary. The first version of
         | this check drafted mid-May and asked about April, where `<` and
         | `<=` give the same answer - so the bite sweep changed the bound
         | and nothing failed.
         */
        $was = $this->date;
        $this->date = \Illuminate\Support\Carbon::parse('2026-06-01');

        try {
            $this->draft();
        } finally {
            $this->date = $was;
        }

        $reasons = $this->reasons();

        foreach ($reasons as $reason) {
            if (str_contains($reason, 'still unposted')) {
                return ['closing May was blocked by a draft dated 1 June. `until` is the first day NOT '.
                    'in the period, so a bound of `<=` reaches into the next one and blocks a close for '.
                    'work nobody has started.'];
            }
        }

        return [];
    }

    /** @return list<string> */
    private function driftBlocks(): array
    {
        $this->issue();

        /*
         | Drift, made the way it actually happens.
         |
         | `account_balances` is maintained by triggers, so the realistic
         | failure is a row that stopped agreeing with the journal - not a
         | row that is missing. Moving one by a unit is the smallest
         | possible version of that and is what a partial rebuild or a
         | restored backup leaves behind.
         */
        $moved = $this->connection->update(
            'UPDATE account_balances SET net = net + 1 WHERE entity_id = ? AND id = '.
            '(SELECT id FROM account_balances WHERE entity_id = ? ORDER BY id LIMIT 1)',
            [$this->entityId, $this->entityId],
        );

        if ($moved === 0) {
            return ['the fixture posted an invoice and `account_balances` holds no row for the entity, '.
                'so this check cannot see drift at all'];
        }

        foreach ($this->reasons() as $reason) {
            if (str_contains($reason, 'disagree with the journal')) {
                return [];
            }
        }

        return ['a stored balance that disagrees with the journal did not stop the close. Every report '.
            'reads the stored balances, so the trial balance and the statements are wrong and look '.
            'exactly as right as they did yesterday.'];
    }

    /** @return list<string> */
    private function allReasonsAtOnce(): array
    {
        /*
         | An ISSUED invoice and a draft one.
         |
         | The drift half needs a posting, because `account_balances` has
         | no row for an entity that has never posted and the UPDATE below
         | would move nothing. The first version drafted only, so neither
         | problem existed and the check reported the product as silent
         | when the fixture was.
         */
        $this->issue();
        $this->draft();

        $this->connection->update(
            'UPDATE account_balances SET net = net + 1 WHERE entity_id = ? AND id = '.
            '(SELECT id FROM account_balances WHERE entity_id = ? ORDER BY id LIMIT 1)',
            [$this->entityId, $this->entityId],
        );

        $reasons = $this->reasons();

        $sawDraft = false;
        $sawDrift = false;

        foreach ($reasons as $reason) {
            $sawDraft = $sawDraft || str_contains($reason, 'still unposted');
            $sawDrift = $sawDrift || str_contains($reason, 'disagree with the journal');
        }

        if (! $sawDraft || ! $sawDrift) {
            return [sprintf(
                'two independent problems produced %d reason(s) and not both. A close that reports the '.
                'first problem is found three times by the same person on the same afternoon.',
                \count($reasons),
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function aThrowingCheckBlocks(): array
    {
        $registry = app(ClosingChecks::class);

        $registry->register(new class implements \App\Support\Closing\ClosingCheck
        {
            public function name(): string
            {
                return 'a check that cannot run';
            }

            public function reasonsToRefuse(string $entityId, string $label, string $from, string $until): array
            {
                throw new \RuntimeException('the data this check needs is unavailable');
            }
        });

        try {
            foreach ($this->reasons() as $reason) {
                if (str_contains($reason, 'could not be computed')) {
                    return [];
                }
            }

            return ['a closing check that threw let the close through. A period whose checks nobody '.
                'could compute must not close: the alternative is a close that succeeded because '.
                'something was broken.'];
        } finally {
            /*
             | Put a harmless check back under the same name.
             |
             | The registry is a singleton keyed by name and has no
             | unregister - rightly, since nothing in the product removes a
             | check. But a savepoint rolls back the DATABASE and not the
             | container, so without this the throwing check leaked into
             | every later check in this file and reported them all as
             | broken. It took one confusing failure to find.
             */
            $registry->register(new class implements \App\Support\Closing\ClosingCheck
            {
                public function name(): string
                {
                    return 'a check that cannot run';
                }

                public function reasonsToRefuse(string $e, string $l, string $f, string $u): array
                {
                    return [];
                }
            });
        }
    }

    /** @return list<string> */
    private function theStateMachineRefuses(): array
    {
        $this->draft();

        $period = $this->period;

        if ($period === null) {
            return ['the fixture has no period to close'];
        }

        /*
         | Closed, not soft-closed.
         |
         | The state machine consults the registry on the way to Closed
         | only, and says why: the soft close is the operational one that
         | happens while the audit is still running, and Locked is reached
         | from Closed so anything blocking has already been dealt with.
         | The first version of this check asserted a soft close was
         | refused and reported the product as broken - it was the check
         | that had not read the comment.
         */
        $machine = app(PeriodStateMachine::class);

        $machine->transition($period, PeriodState::SoftClosed, 'the operational close, before the audit');

        try {
            $machine->transition(
                $period->refresh(),
                PeriodState::Closed,
                'closing with a draft in the period, for the checklist check',
            );
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'unposted')
                ? []
                : ['the close was refused for a reason that does not name the draft: '.$e->getMessage()];
        }

        return ['the period closed with a draft entry in it. The registry knows and the state machine '.
            'did not ask, which makes every check in it advisory.'];
    }

    /** @return list<string> */
    private function reasons(
        string $label = '2026-05',
        string $from = '2026-05-01',
        string $until = '2026-06-01',
    ): array {
        return app(ClosingChecks::class)->reasonsToRefuse($this->entityId, $label, $from, $until);
    }
}
