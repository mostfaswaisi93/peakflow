<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Ledger\Exceptions\OpeningBalancesRefused;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Posting\Rules\OpeningBalanceRule;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The one way a human may reach a control account - PF-038.
 *
 * ## Why an override exists at all
 *
 * A control account may be reached only by a registered posting rule. That is
 * the invariant that keeps a subledger equal to its control account, and it is
 * the one that cannot be restored once somebody has posted into one by hand:
 * the receivables ledger and the receivables account disagree, and no amount
 * of later care tells you which is right.
 *
 * Opening balances are the exception, because no document produces them.
 * Somebody has to type what the receivables were on the day the books opened.
 *
 * ## So the exception is a window, not a door
 *
 * Open while the entity has nothing else posted, closed forever afterwards.
 * An opening balance typed six months into the year is indistinguishable from
 * the real one and is found a year later by whoever compares this opening to
 * last year's closing - and by then everything downstream has been built on
 * it.
 *
 * The window is enforced by a database trigger as well as here, because the
 * things that would ignore this class - a backfill script, a psql session, a
 * future bug - are exactly the things the invariant has to survive.
 *
 * ## Three checks before anything is written, in this order
 *
 * The permission, the window, then the figures. In that order because the
 * messages are what somebody acts on: "you may not do this" is a different
 * conversation from "it is too late to do this", and both are different from
 * "these figures do not add up".
 */
final class OpeningBalances
{
    public function __construct(
        private readonly Poster $poster,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Whether the window is still open, and why it is not.
     *
     * Asked by the UI so the screen can say so before somebody types forty
     * figures into it. The same question the trigger asks, phrased for a
     * person.
     *
     * @return array{open: bool, blocked_by: ?string, opening_entries: int}
     */
    public function window(string $entityId): array
    {
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT
                (SELECT count(*) FROM journal_entries e
                  WHERE e.entity_id = cast(? AS char(26))
                    AND e.status IN ('posted', 'reversed')
                    AND e.posting_rule = 'ledger.opening_balance') AS opening_entries,
                (SELECT e.number || ' of ' || e.accounting_date
                   FROM journal_entries e
                  WHERE e.entity_id = cast(? AS char(26))
                    AND e.status IN ('posted', 'reversed')
                    AND coalesce(e.posting_rule, '') <> 'ledger.opening_balance'
                  ORDER BY e.accounting_date, e.number
                  LIMIT 1) AS blocker
        SQL, [$entityId, $entityId]);

        return [
            'open' => ($row->blocker ?? null) === null,
            'blocked_by' => $row->blocker === null ? null : (string) $row->blocker,
            'opening_entries' => (int) ($row->opening_entries ?? 0),
        ];
    }

    /**
     * Post an opening balance set.
     *
     * @param  array<string, string>  $balances  account code => signed amount, debit positive
     *
     * @throws OpeningBalancesRefused
     */
    public function post(
        Entity $entity,
        Carbon $asAt,
        array $balances,
        string $reason,
    ): JournalEntry {
        $entityId = (string) $entity->getKey();

        /*
         | The permission first.
         |
         | `ledger.opening_balance` rather than `journal.post`, and that is
         | the point of having it: posting a journal is an everyday act and
         | naming a control account by hand is not. It carries step-up
         | authentication for the same reason.
         */
        if (Gate::denies('ledger.opening_balance')) {
            throw OpeningBalancesRefused::notPermitted();
        }

        $window = $this->window($entityId);

        if (! $window['open']) {
            throw OpeningBalancesRefused::windowClosed((string) $window['blocked_by']);
        }

        if (mb_strlen(trim($reason)) < 20) {
            /*
             | A reason, and a real one.
             |
             | Twenty characters is not a high bar; it is enough to stop
             | "import" and "setup". It is read during the first audit of
             | these books, when the only artefact is what somebody typed,
             | and the question is where the figures came from.
             */
            throw OpeningBalancesRefused::needsAReason();
        }

        if ($balances === []) {
            throw OpeningBalancesRefused::nothingToPost();
        }

        $resolved = $this->resolve($entityId, $balances);

        return DB::connection('tenant')->transaction(function () use (
            $entity,
            $entityId,
            $asAt,
            $resolved,
            $balances,
            $reason,
        ): JournalEntry {
            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: OpeningBalanceRule::KEY,
                event: ['balances' => $resolved],
                accountingDate: $asAt,
                journalCode: 'OPN',
                sourceType: 'opening_balance',
                narration: sprintf('Opening balances as at %s', $asAt->toDateString()),
            );

            /*
             | Audited with the FIGURES, not just the act.
             |
             | "Somebody entered opening balances on the 3rd" is not the
             | record anybody needs. What they need is which accounts and
             | what amounts, because the question during the first audit is
             | where those numbers came from - and the entry itself can be
             | reversed, while the audit row cannot.
             */
            $this->audit->record(
                category: 'ledger',
                action: 'ledger.opening_balance',
                subjectType: JournalEntry::class,
                subjectId: (string) $entry->getKey(),
                subjectLabel: (string) $entry->number,
                after: ['as_at' => $asAt->toDateString(), 'balances' => $balances],
                reason: $reason,
                entityId: $entityId,
                periodId: (string) $entry->period_id,
            );

            return $entry;
        });
    }

    /**
     * Account codes to ids, refusing anything unpostable.
     *
     * Resolved before the transaction opens, so a typo in the fortieth code
     * is reported as a typo rather than as a foreign-key violation inside a
     * rolled-back posting.
     *
     * @param  array<string, string>  $balances
     * @return list<array{account_id: string, amount: string}>
     */
    private function resolve(string $entityId, array $balances): array
    {
        $accounts = Account::query()
            ->where('entity_id', $entityId)
            ->whereIn('code', array_keys($balances))
            ->get(['id', 'code', 'postable'])
            ->keyBy('code');

        $resolved = [];
        $unknown = [];
        $unpostable = [];

        foreach ($balances as $code => $amount) {
            $account = $accounts->get((string) $code);

            if ($account === null) {
                $unknown[] = (string) $code;

                continue;
            }

            if (! $account->postable) {
                $unpostable[] = (string) $code;

                continue;
            }

            $resolved[] = [
                'account_id' => (string) $account->getKey(),
                'amount' => (string) $amount,
            ];
        }

        if ($unknown !== []) {
            throw OpeningBalancesRefused::unknownAccounts($unknown);
        }

        if ($unpostable !== []) {
            throw OpeningBalancesRefused::unpostableAccounts($unpostable);
        }

        return $resolved;
    }
}
