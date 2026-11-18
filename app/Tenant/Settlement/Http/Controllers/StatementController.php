<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Http\Controllers;

use App\Support\Money\Money;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Settlement\Services\Statements;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A statement of account for one party - PF-181.
 *
 * ## Both forms on one page, because they answer different questions
 *
 * {@see Statements::balanceForward()} opens with the balance brought
 * forward and lists what happened since: it answers "how did we get from
 * last month's figure to this one", which is what somebody asks when the
 * two sides disagree about the total. {@see Statements::openItems()}
 * lists the documents that are not settled, which is the only form that
 * can be reconciled line by line and the only form that can be paid
 * from.
 *
 * A page with only the first cannot explain a movement and a page with
 * only the second cannot be paid from, so this renders both, from the
 * one service, for one party on one date.
 *
 * ## The proof is rendered, not implied
 *
 * `balanceForward()` adds the movements to the opening balance and
 * compares the result against a closing balance computed by a separate
 * query, and returns `adds_up` either way. This page prints that line in
 * both cases. A column that does not add up is the first thing a
 * customer notices and the last thing they forgive, and a page that only
 * showed the proof when it held would be silent exactly when it matters.
 *
 * `reconciliation()` is the other half, one level up: the sum of every
 * party's open items against the control account. A statement that is
 * right about one customer while the control account disagrees by a few
 * hundred sends somebody looking in the wrong place for a week, so the
 * entity-wide tie is stated at the foot of the page rather than left for
 * a report nobody runs.
 *
 * ## Unapplied cash and credits arrive as their own lines, and stay that way
 *
 * The service lists them labelled rather than netted against the oldest
 * invoice, because netting silently is a decision made on the customer's
 * behalf that they cannot see - and the dispute it causes is the one it
 * was meant to prevent. This page keeps them as separate rows for the
 * same reason, grouped under their own heading so the open documents
 * still total on their own.
 *
 * ## There is no default side, so the page asks
 *
 * The service refuses a side it does not recognise, deliberately: a
 * customer statement reads from invoices against receivables and a
 * supplier statement from bills against payables, and there is no
 * sensible default between them. A party that is both gets two
 * statements, and the page offers the other one rather than guessing.
 *
 * ## No party selected is a question, not an error
 *
 * Arriving with no `?party=` is the normal way to reach this page from a
 * menu. It renders the picker.
 */
final class StatementController
{
    private const SIDES = ['customer', 'supplier'];

    /** How many parties the picker lists before it says there are more. */
    private const PICKER_LIMIT = 50;

    public function __construct(private readonly Statements $statements) {}

    /**
     * @permission report.view - a statement of account is a report over
     *     the journal and the open documents. It names what a party owes
     *     and what has been paid, which is the entity's financial
     *     position for one counterparty.
     */
    public function __invoke(Request $request, EntityContext $context): View
    {
        Gate::authorize('report.view');

        $entity = $context->entity();
        $party = $this->requestedParty($request);

        if ($entity === null || $party === null) {
            /*
             | One row beyond the limit is fetched so the page can SAY
             | there are more rather than stopping silently at fifty,
             | which is the version somebody concludes a party does not
             | exist from.
             */
            $candidates = $entity === null ? [] : $this->candidates($request);

            return view('app.settlement.statements', [
                'entity' => $entity,
                'party' => null,
                'term' => $this->term($request),
                'candidates' => \array_slice($candidates, 0, self::PICKER_LIMIT),
                'more' => \count($candidates) > self::PICKER_LIMIT,
            ]);
        }

        $entityId = (string) $entity->getKey();
        $side = $this->side($request, $party);
        $currencies = $this->currenciesFor($entityId, (string) $party->getKey(), $side);
        $currency = $this->currency($request, $party, $entity, $currencies);

        /*
         | `as_of` is resolved before anything is read, and the resolved
         | date is what the page prints.
         |
         | A typed `?as_of=2026-02-31` is a date Carbon rolls forward to
         | the 3rd of March. Printing the date that was actually used -
         | in the heading and back in the form - is what stops a reader
         | believing they are looking at February.
         */
        $asOf = $this->date($request->query('as_of'), Carbon::now()->startOfDay());

        /*
         | The period opens on the first of the month `as_of` falls in.
         |
         | A statement covers a period and the balance brought forward is
         | the balance the day before it starts. The month is the usual
         | cycle and `?from=` overrides it; a `from` after `as_of` is
         | ignored rather than passed on, because the service refuses the
         | reversed pair - correctly, since the movement between them
         | would read as its own negative - and a typed URL should not be
         | a 500.
         */
        $from = $this->date($request->query('from'), $asOf->copy()->startOfMonth());

        if ($from->gt($asOf)) {
            $from = $asOf->copy()->startOfMonth();
        }

        $openItems = $this->statements->openItems($entityId, (string) $party->getKey(), $currency, $asOf, $side);
        $movement = $this->statements->balanceForward($entityId, (string) $party->getKey(), $currency, $from, $asOf, $side);
        $tie = $this->statements->reconciliation($entityId, $currency, $side);

        return view('app.settlement.statements', [
            'entity' => $entity,
            'party' => $party,
            'side' => $side,
            /*
             | Whether the other side exists for this party.
             |
             | The same company sells to you and buys from you, and the
             | two statements are different documents against different
             | control accounts. Offering the other one is the only way a
             | reader discovers it is there.
             */
            'other_side' => $this->otherSide($party, $side),
            'currency' => $currency,
            'currencies' => $currencies,
            'as_of' => $asOf->toDateString(),
            'from' => $from->toDateString(),

            'documents' => $this->documents($openItems, $currency),
            'credits' => $this->credits($openItems, $currency),
            'open_total' => Money::of((string) $openItems['total'], $currency),
            'open_why' => $openItems['why'],

            'brought_forward' => Money::of((string) $movement['opening'], $currency),
            'movements' => $this->movements($movement, $currency),
            'closing' => Money::of((string) $movement['closing'], $currency),
            'adds_up' => (bool) $movement['adds_up'],
            'movement_why' => $movement['why'],

            'tie' => [
                'subsidiary' => Money::of((string) $tie['subsidiary_ledger'], $currency),
                'control' => Money::of((string) $tie['control_account'], $currency),
                'difference' => Money::of((string) $tie['difference'], $currency),
                'reconciles' => (bool) $tie['reconciles'],
                'why' => $tie['why'],
            ],

            'term' => $this->term($request),
            'candidates' => [],
            'more' => false,
        ]);
    }

    /**
     * The open items that are documents, with their ages.
     *
     * @param  array<string, mixed>  $openItems
     * @return list<array<string, mixed>>
     */
    private function documents(array $openItems, string $currency): array
    {
        $documents = [];

        foreach ($openItems['items'] as $item) {
            if (($item['kind'] ?? null) !== 'document') {
                continue;
            }

            $documents[] = [
                'label' => (string) $item['label'],
                'reference' => (string) $item['reference'],
                'issued_on' => (string) $item['issued_on'],
                'due_on' => $item['due_on'] === null ? null : (string) $item['due_on'],
                'gross' => Money::of((string) $item['gross'], $currency),
                'open' => Money::of((string) $item['open'], $currency),
                /*
                 | The age as the service computed it, floored at zero.
                 |
                 | A document not yet due has no age, and a negative one
                 | printed in an aging column reads as a document that is
                 | overdue in the other direction.
                 */
                'days_overdue' => $item['days_overdue'] === null ? null : (int) $item['days_overdue'],
                'disputed' => (bool) $item['disputed'],
                'note' => $item['note'] === null ? null : (string) $item['note'],
            ];
        }

        return $documents;
    }

    /**
     * The lines that are not documents: unapplied cash and credits.
     *
     * @param  array<string, mixed>  $openItems
     * @return list<array<string, mixed>>
     */
    private function credits(array $openItems, string $currency): array
    {
        $credits = [];

        foreach ($openItems['items'] as $item) {
            if (($item['kind'] ?? null) === 'document') {
                continue;
            }

            $credits[] = [
                'kind' => (string) ($item['kind'] ?? 'unknown'),
                'label' => (string) $item['label'],
                'open' => Money::of((string) $item['open'], $currency),
                'note' => ($item['note'] ?? null) === null ? null : (string) $item['note'],
            ];
        }

        return $credits;
    }

    /**
     * The movements, with the running balance the service carried.
     *
     * @param  array<string, mixed>  $movement
     * @return list<array<string, mixed>>
     */
    private function movements(array $movement, string $currency): array
    {
        $lines = [];

        foreach ($movement['movements'] as $line) {
            $lines[] = [
                'on' => (string) $line['on'],
                'label' => (string) $line['label'],
                'reference' => (string) $line['reference'],
                'amount' => Money::of((string) $line['amount'], $currency),
                'running' => Money::of((string) $line['running'], $currency),
            ];
        }

        return $lines;
    }

    /** The party named by `?party=`, if it is one this entity can show. */
    private function requestedParty(Request $request): ?Party
    {
        $parameter = $request->query('party');

        /*
         | A query parameter can arrive as an array - `?party[]=x` - and
         | casting one to a string is a 500 on a URL anybody can type.
         */
        if (! \is_string($parameter) || $parameter === '') {
            return null;
        }

        return Party::query()->find($parameter);
    }

    /** The side asked for, or the only one this party has. */
    private function side(Request $request, Party $party): string
    {
        $parameter = $request->query('side');

        if (\is_string($parameter) && \in_array($parameter, self::SIDES, true)) {
            return $parameter;
        }

        /*
         | A party that is only a supplier gets the supplier statement.
         |
         | Not because there is a default - the service is right that
         | there is not - but because asking "customer or supplier" about
         | a company that has never been billed is a question with one
         | answer, and the page shows which side it chose.
         */
        return $party->is_customer ? 'customer' : 'supplier';
    }

    /** The other statement this party has, where they have one. */
    private function otherSide(Party $party, string $side): ?string
    {
        if ($side === 'customer' && $party->is_supplier) {
            return 'supplier';
        }

        if ($side === 'supplier' && $party->is_customer) {
            return 'customer';
        }

        return null;
    }

    /**
     * The currencies this party has actually been dealt with in.
     *
     * Offered rather than guessed. A statement defaulted to the entity's
     * functional currency for a supplier billed only in euro is an empty
     * page with nothing on it to say why, and the reader's conclusion is
     * that the supplier owes nothing.
     *
     * Payments are unioned in, because a party whose only open position
     * is cash received and not yet applied has no document to read a
     * currency from.
     *
     * @return list<string>
     */
    private function currenciesFor(string $entityId, string $partyId, string $side): array
    {
        $sql = $side === 'customer'
            ? <<<'SQL'
                SELECT DISTINCT d.currency
                FROM invoices d
                WHERE d.entity_id = ? AND d.party_id = ? AND d.status = 'issued'
                UNION
                SELECT DISTINCT p.currency
                FROM payments p
                WHERE p.entity_id = ? AND p.party_id = ? AND p.direction = 'receipt' AND p.reversed_at IS NULL
                ORDER BY 1
            SQL
            : <<<'SQL'
                SELECT DISTINCT d.currency
                FROM bills d
                WHERE d.entity_id = ? AND d.party_id = ? AND d.approved_at IS NOT NULL
                UNION
                SELECT DISTINCT p.currency
                FROM payments p
                WHERE p.entity_id = ? AND p.party_id = ? AND p.direction = 'disbursement' AND p.reversed_at IS NULL
                ORDER BY 1
            SQL;

        $rows = DB::connection('tenant')->select($sql, [$entityId, $partyId, $entityId, $partyId]);

        return array_map(static fn (object $row): string => (string) $row->currency, $rows);
    }

    /**
     * The currency to read the statement in.
     *
     * @param  list<string>  $currencies
     */
    private function currency(Request $request, Party $party, Entity $entity, array $currencies): string
    {
        $parameter = $request->query('currency');

        if (\is_string($parameter) && \in_array($parameter, $currencies, true)) {
            return $parameter;
        }

        $preferred = $party->default_currency === null ? null : (string) $party->default_currency;

        if ($preferred !== null && \in_array($preferred, $currencies, true)) {
            return $preferred;
        }

        /*
         | Then whichever currency the party actually has documents in,
         | and the entity's own currency only when there are none.
         |
         | The last case is a party nothing has been posted for: the
         | statement is empty whichever currency is chosen, and the
         | entity's functional currency is the one the reader expects to
         | see on an empty page.
         */
        return $currencies[0] ?? (string) $entity->functional_currency;
    }

    /** An ISO date from the query string, or the fallback. */
    private function date(mixed $value, Carbon $fallback): Carbon
    {
        if (! \is_string($value) || ! Carbon::hasFormat($value, 'Y-m-d')) {
            return $fallback;
        }

        return Carbon::parse($value)->startOfDay();
    }

    /** What was typed into the picker's search box. */
    private function term(Request $request): string
    {
        $parameter = $request->query('q');

        return \is_string($parameter) ? trim($parameter) : '';
    }

    /**
     * Parties to choose from, matching whatever was typed.
     *
     * @return list<Party>
     */
    private function candidates(Request $request): array
    {
        $term = $this->term($request);

        /*
         | `%` and `_` are ILIKE's own wildcards, so they are escaped
         | rather than passed through: searching for the code `AC_1`
         | otherwise matches `AC-1` as well, and a row that is not the
         | one you typed looks exactly like a row that is.
         */
        $like = '%'.addcslashes($term, '%_\\').'%';

        return Party::query()
            ->where(static fn (Builder $query): Builder => $query
                ->where('is_customer', true)
                ->orWhere('is_supplier', true))
            ->when($term !== '', static fn (Builder $query): Builder => $query->where(
                static fn (Builder $match): Builder => $match
                    ->where('code', 'ilike', $like)
                    ->orWhere('legal_name', 'ilike', $like)
                    ->orWhere('trading_name', 'ilike', $like),
            ))
            /*
             | By legal name, which is the name on the document.
             |
             | `code` is issued in sequence, so ordering by it would sort
             | the list by when each party was created - which is
             | invisible to somebody scanning for a name.
             */
            ->orderBy('legal_name')
            ->limit(self::PICKER_LIMIT + 1)
            ->get()
            ->all();
    }
}
