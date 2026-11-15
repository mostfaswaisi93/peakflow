<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Tenant\Ledger\Exceptions\InterEntityRefused;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Posting\Rules\InterEntityDueFromRule;
use App\Tenant\Ledger\Posting\Rules\InterEntityDueToRule;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Posting both sides of a transaction between two entities - PF-057.
 *
 * ## One transaction, two entries, one amount
 *
 * The settlement amount is written once, in one currency, and both entries
 * translate from it. Two rows holding two numbers is how inter-company
 * balances come to disagree; there is one row and it holds one number.
 *
 * ## Both sides in ONE database transaction
 *
 * A receivable with no payable is the failure this service exists to make
 * impossible. The deferred constraint on the transaction row catches it at
 * commit; this makes the window where it could happen zero rather than
 * merely short.
 *
 * ## Each side posts inside its own entity's scope
 *
 * Row-level security scopes every query to the entity in context, and each
 * side's rule resolves ITS entity's inter-entity account, period and number
 * series. `EntityContext::during()` is what makes the second side land in the
 * second entity's books instead of the first's - and it restores whatever was
 * in scope before, so posting between two subsidiaries from inside a session
 * working in a third does not silently move the user.
 *
 * ## The rates go in, rather than being looked up
 *
 * `Ledger` must not know what the catalog is. Whoever posts chooses the rate
 * for each side and the lines record it with its provenance (PF-069), so the
 * figure can be placed afterwards: which rate set, as of when, from which
 * provider. The two rates are separate arguments because the two entities
 * translate the same settlement amount into different currencies, and a
 * single rate could only be right for one of them.
 */
final class InterEntityPosting
{
    public function __construct(
        private readonly Poster $poster,
        private readonly EntityContext $entities,
        private readonly NumberAllocator $numbers,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Post both sides.
     *
     * @param  Entity  $from  the entity that is owed - it gave something up
     * @param  Entity  $to  the entity that owes
     * @param  Money  $settlement  the one figure both sides agree on
     * @param  string  $fromCounterAccountId  what `from` gave up: cash, an expense it paid
     * @param  string  $toCounterAccountId  what `to` received
     * @param  Rate|null  $fromRate  settlement -> `from`'s functional currency, if they differ
     * @param  Rate|null  $toRate  settlement -> `to`'s functional currency, if they differ
     * @return array{
     *     transaction_id: string,
     *     reference: string,
     *     from_entry: JournalEntry,
     *     to_entry: JournalEntry,
     * }
     *
     * @throws InterEntityRefused
     */
    public function post(
        Entity $from,
        Entity $to,
        Money $settlement,
        Carbon $accountingDate,
        string $description,
        string $fromCounterAccountId,
        string $toCounterAccountId,
        ?Rate $fromRate = null,
        ?Rate $toRate = null,
    ): array {
        if ((string) $from->getKey() === (string) $to->getKey()) {
            throw InterEntityRefused::sameEntity((string) $from->code);
        }

        if ($settlement->isZero()) {
            throw InterEntityRefused::zeroSettlement();
        }

        if (mb_strlen(trim($description)) < 10) {
            /*
             | A description, and a real one.
             |
             | It is what the other entity's accountant reads when they ask
             | what this balance is. "Transfer" is not an answer, and the
             | question is asked months later by somebody who was not there.
             */
            throw InterEntityRefused::needsADescription();
        }

        return DB::connection('tenant')->transaction(fn (): array => $this->entities->during(
            $from,
            /*
             | The whole act runs inside the LENDING entity's scope.
             |
             | Not just the posting. The transaction row is under row-level
             | security too, and its policy admits a row only when the
             | current entity is one of the two sides - so an insert with no
             | entity established is refused, which is what happened the
             | first time this was written. The lending entity is the one
             | creating the transaction, so its scope is the right one to be
             | in, and `during()` puts back whatever was there before.
             */
            fn (): array => $this->postBothSides(
                $from,
                $to,
                $settlement,
                $accountingDate,
                $description,
                $fromCounterAccountId,
                $toCounterAccountId,
                $fromRate,
                $toRate,
            ),
        ));
    }

    /**
     * @return array{
     *     transaction_id: string,
     *     reference: string,
     *     from_entry: JournalEntry,
     *     to_entry: JournalEntry,
     * }
     */
    private function postBothSides(
        Entity $from,
        Entity $to,
        Money $settlement,
        Carbon $accountingDate,
        string $description,
        string $fromCounterAccountId,
        string $toCounterAccountId,
        ?Rate $fromRate,
        ?Rate $toRate,
    ): array {
        $transactionId = (string) Str::ulid();

        $reference = $this->numbers->next(
            (string) $from->getKey(),
            'inter_entity',
            'IE',
            $accountingDate,
        );

        /*
         | The row FIRST, because the lines name it.
         |
         | Which is also why the both-sides constraint is deferred: at this
         | moment neither entry exists, and that is the only order in which
         | they can.
         */
        DB::connection('tenant')->table('inter_entity_transactions')->insert([
            'id' => $transactionId,
            'from_entity_id' => (string) $from->getKey(),
            'to_entity_id' => (string) $to->getKey(),
            'reference' => $reference,
            'description' => $description,
            'accounting_date' => $accountingDate->toDateString(),
            'settlement_currency' => $settlement->currency,
            'settlement_amount' => (string) $settlement->amount,
            'created_by_user_id' => auth()->guard('web')->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = [
            'transaction_id' => $transactionId,
            'settlement_amount' => (string) $settlement->amount,
            'settlement_currency' => $settlement->currency,
            'narration' => $description,
        ];

        $fromEntry = $this->poster->post(
            entity: $from,
            ruleKey: InterEntityDueFromRule::KEY,
            event: $event + [
                'counter_account_id' => $fromCounterAccountId,
                'rate' => $fromRate,
            ],
            accountingDate: $accountingDate,
            journalCode: 'GEN',
            sourceType: 'inter_entity',
            sourceId: $transactionId,
            narration: sprintf('%s (%s, due from %s)', $description, $reference, (string) $to->code),
        );

        /*
         | The borrowing side, in the borrowing entity's scope.
         |
         | Nested inside the lending entity's `during()`, which restores it
         | on the way out - so the row update and the audit entry below are
         | back in the lending entity's scope, where their own policies
         | expect to find them.
         */
        $toEntry = $this->entities->during($to, fn (): JournalEntry => $this->poster->post(
            entity: $to,
            ruleKey: InterEntityDueToRule::KEY,
            event: $event + [
                'counter_account_id' => $toCounterAccountId,
                'rate' => $toRate,
            ],
            accountingDate: $accountingDate,
            journalCode: 'GEN',
            sourceType: 'inter_entity',
            sourceId: $transactionId,
            narration: sprintf('%s (%s, due to %s)', $description, $reference, (string) $from->code),
        ));

        DB::connection('tenant')->table('inter_entity_transactions')
            ->where('id', $transactionId)
            ->update([
                'from_entry_id' => (string) $fromEntry->getKey(),
                'to_entry_id' => (string) $toEntry->getKey(),
                'updated_at' => now(),
            ]);

        /*
         | Audited once, for the transaction, against the LENDING entity.
         |
         | Not twice. Two audit rows for one act would read as two acts, and
         | the one thing anybody wants to know later - what this balance is
         | and who created it - is one fact.
         */
        $this->audit->record(
            category: 'ledger',
            action: 'ledger.inter_entity',
            subjectType: 'inter_entity_transactions',
            subjectId: $transactionId,
            subjectLabel: $reference,
            after: [
                'from' => (string) $from->code,
                'to' => (string) $to->code,
                'settlement' => (string) $settlement->amount.' '.$settlement->currency,
                'from_entry' => (string) $fromEntry->number,
                'to_entry' => (string) $toEntry->number,
            ],
            reason: $description,
            entityId: (string) $from->getKey(),
        );

        return [
            'transaction_id' => $transactionId,
            'reference' => $reference,
            'from_entry' => $fromEntry,
            'to_entry' => $toEntry,
        ];
    }
}
