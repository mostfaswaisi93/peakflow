<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Http\Controllers\Api;

use App\Support\Http\Api\Page;
use App\Support\Http\Api\Wire;
use App\Support\Http\Exceptions\FilterNotAllowed;
use App\Tenant\Ledger\Manual\DraftLine;
use App\Tenant\Ledger\Manual\ManualEntryPoster;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Tenancy\Exceptions\EntityNotInScope;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Journal entries over the wire - PF-220, PF-221, PF-222.
 *
 * ## Every amount is an object and every accounting date is a date
 *
 * Not one figure here is a JSON number. The bug that prevents is specific: a
 * client parsing `1.234` - a Jordanian dinar amount - as a float and
 * reformatting it to `1.23`. The lines then no longer sum to the total, and
 * the discrepancy is a third of a fils per line, which is exactly the size
 * nobody investigates.
 *
 * `accounting_date` is `YYYY-MM-DD`; `posted_at` is RFC 3339 in UTC. The
 * difference is a period: a timestamp for an accounting date is one timezone
 * conversion away from moving an entry into another month, another quarter
 * and another tax return.
 *
 * ## The POST posts. There is no draft.
 *
 * The screen has drafts because a person is interrupted halfway through a
 * forty-line accrual. An integration is not interrupted, and a draft only it
 * can see is a row nothing will ever come back and finish.
 *
 * So this is a write that posts to the ledger, which is why it requires
 * `Idempotency-Key` - a retried POST without one posts the entry twice, and
 * the second one is indistinguishable from a real transaction.
 *
 * ## A control account is refused, by the validator and then by the database
 *
 * It goes through the same validator the manual entry screen uses, so the API
 * and the screen refuse the same entry for the same reason and say the same
 * thing about it. The database refuses it again: no `peakflow.posting_rule`
 * is set for a line the validator built, so the control-account trigger
 * fires. Two independent refusals, because once a control account and its
 * subledger disagree neither can be shown to be the correct one without
 * re-deriving the whole history.
 */
final class JournalEntryApiController
{
    /**
     * The sortable fields, and the column each one means.
     *
     * `posted_at` is deliberately absent even though it is on the
     * representation. It is NULLABLE - a draft has not been posted - and a
     * keyset cursor over a nullable column silently loses rows: the
     * row-value comparison `(posted_at, id) > (NULL, ?)` evaluates to NULL,
     * which is not true, so every draft disappears from the page and the
     * client sees a shorter list with no indication that anything was left
     * out. A sort key has to be total, so the allowlist holds only columns
     * the database declares NOT NULL.
     *
     * @var array<string, string>
     */
    private const SORTABLE = [
        'accounting_date' => 'accounting_date',
        'number' => 'number',
    ];

    public function __construct(
        private readonly ManualEntryPoster $poster,
        private readonly EntityContext $entities,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('report.view');

        [$field, $direction] = $this->sort($request);

        $query = DB::connection('tenant')->table('journal_entries')
            ->select([
                'id', 'number', 'status', 'accounting_date', 'period_id', 'journal_id',
                'narration', 'posting_rule', 'source_type', 'source_id',
                'posted_at', 'posted_by_user_id', 'created_at',
            ]);

        $status = $request->query('filter');

        if (\is_array($status) && isset($status['status'])) {
            $wanted = (string) $status['status'];

            if (! \in_array($wanted, ['draft', 'posted', 'reversed'], true)) {
                throw FilterNotAllowed::filter('status', ['draft', 'posted', 'reversed']);
            }

            $query->where('status', $wanted);
        }

        $functional = $this->functionalCurrency();

        return Page::asked($request, 'listJournalEntries:'.$field.':'.$direction)
            ->fill(
                query: $query,
                sortColumn: self::SORTABLE[$field],
                direction: $direction,
                shape: fn (object $row): array => $this->shapeEntry($row, $functional),
                sortValue: static fn (object $row): string => (string) $row->{self::SORTABLE[$field]},
                idValue: static fn (object $row): string => (string) $row->id,
            )
            ->toResponse();
    }

    public function show(Request $request, string $id): JsonResponse
    {
        Gate::authorize('report.view');

        /*
         | Resolved under the entity scope, so an id belonging to another
         | company in the same tenant is a 404 - indistinguishable from one
         | that never existed, which is what stops a numbering series being
         | walked from outside.
         */
        $entry = JournalEntry::query()->findOrFail($id);

        $functional = $this->functionalCurrency();

        $body = $this->shapeEntry((object) $entry->getAttributes(), $functional);
        $body['lines'] = $this->lines($id);

        return new JsonResponse($body);
    }

    /**
     * Post an entry.
     *
     * The permission is `journal.post` and it is asserted here as well as on
     * the route, which is also what makes this handler's authorization
     * visible to the architecture check that reads it.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('journal.post');

        $validated = $request->validate([
            'accounting_date' => ['required', 'date_format:Y-m-d'],
            'narration' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:500'],
            'lines.*.account_code' => ['required', 'string', 'max:40'],
            /*
             | A STRING, and the rule says so.
             |
             | `numeric` would accept the JSON number 1.234, which PHP has
             | already turned into a float by the time any rule sees it - so
             | the precision is gone before validation runs and no later check
             | can recover it. A client that sends a number is told to send a
             | string, which is the only form that survives the trip.
             */
            'lines.*.amount' => ['required', 'string', 'regex:/^-?\d{1,17}(\.\d{1,4})?$/'],
            'lines.*.currency' => ['nullable', 'string', 'size:3'],
            'lines.*.narration' => ['nullable', 'string', 'max:500'],
            'lines.*.dimensions' => ['nullable', 'array'],
        ]);

        $entity = $this->entity();

        /** @var list<array<string, mixed>> $lines */
        $lines = $validated['lines'];

        $entry = $this->poster->post(
            entity: $entity,
            lines: array_map(static fn (array $line): DraftLine => DraftLine::fromArray($line), $lines),
            accountingDate: Carbon::createFromFormat('Y-m-d', (string) $validated['accounting_date'])->startOfDay(),
            narration: isset($validated['narration']) ? (string) $validated['narration'] : null,
        );

        $body = $this->shapeEntry((object) $entry->getAttributes(), (string) $entity->functional_currency);
        $body['lines'] = $this->lines((string) $entry->getKey());

        return new JsonResponse($body, JsonResponse::HTTP_CREATED, [
            'Location' => sprintf('%s/journal-entries/%s', rtrim(url(config('api.prefix')), '/'), $entry->getKey()),
        ]);
    }

    /**
     * One entry on the wire.
     *
     * @return array<string, mixed>
     */
    private function shapeEntry(object $row, string $functional): array
    {
        return [
            'resource' => 'journal_entry',
            'id' => (string) $row->id,
            'number' => (string) $row->number,
            'status' => (string) $row->status,
            'accounting_date' => Wire::accountingDate($row->accounting_date),
            'period_id' => (string) $row->period_id,
            'journal_id' => (string) $row->journal_id,
            'narration' => $row->narration === null ? null : (string) $row->narration,
            'posting_rule' => $row->posting_rule === null ? null : (string) $row->posting_rule,
            'source' => [
                'type' => $row->source_type === null ? null : (string) $row->source_type,
                /*
                 | The document or event that caused the posting, where there
                 | was one. Null for a manual entry.
                 |
                 | Deliberately NOT the client's idempotency key, which would
                 | be the obvious thing to put here: the column is a ULID -
                 | `char(26)` - and a key is whatever the client generated,
                 | so writing one in silently truncates or fails depending on
                 | its length. The key-to-document mapping is the idempotency
                 | record's `subject_id`, which exists for the 24 hours the
                 | guarantee does; after that the client has the id from the
                 | response it was given.
                 */
                'reference' => $row->source_id === null ? null : (string) $row->source_id,
            ],
            'posted_at' => Wire::timestamp($row->posted_at),
            'posted_by_user_id' => $row->posted_by_user_id === null ? null : (string) $row->posted_by_user_id,
            'currency' => $functional,
        ];
    }

    /**
     * The lines of one entry, with both amounts and the rate's provenance.
     *
     * Both amounts, always. `amount` is what the line is DENOMINATED in and
     * `functional_amount` is what the ledger balances on, and they are the
     * same figure only when there was no translation. A client given one of
     * them cannot tell which case it is in - and an integration reconciling a
     * euro bank account against a riyal ledger needs both.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(string $entryId): array
    {
        $rows = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $entryId)
            ->orderBy('id')
            ->get([
                'id', 'account_id', 'amount', 'currency', 'functional_amount',
                'fx_rate', 'fx_rate_set', 'fx_rate_as_of', 'fx_rate_provider',
                'party_id', 'narration', 'accounting_date',
            ]);

        $functional = (string) $this->functionalCurrency();

        return $rows->map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'account_id' => (string) $row->account_id,
            /*
             | Signed: debits positive, credits negative, which is the
             | storage convention and the only one under which "the lines sum
             | to zero" is a sentence a client can check for itself.
             */
            'amount' => Wire::amount((string) $row->amount, (string) $row->currency),
            'functional_amount' => Wire::amount((string) $row->functional_amount, $functional),
            /*
             | WHICH rate, not merely what number - PF-069.
             |
             | The value alone cannot answer "was it current", "was it the
             | statutory rate" or "which feed said so", and looking it up
             | again later cannot either: it may have been superseded, and
             | two feeds can send the same figure for different days.
             |
             | Null throughout when nothing was translated, and never 1 - a
             | rate of 1 asserts that a translation happened and came out
             | even, which is a different claim from no translation at all.
             */
            'fx' => $row->fx_rate === null ? null : [
                'rate' => (string) $row->fx_rate,
                'rate_set' => $row->fx_rate_set === null ? null : (string) $row->fx_rate_set,
                'as_of' => Wire::accountingDate($row->fx_rate_as_of),
                'provider' => $row->fx_rate_provider === null ? null : (string) $row->fx_rate_provider,
            ],
            'party_id' => $row->party_id === null ? null : (string) $row->party_id,
            'narration' => $row->narration === null ? null : (string) $row->narration,
        ])->all();
    }

    private function functionalCurrency(): string
    {
        return (string) $this->entity()->functional_currency;
    }

    /**
     * The entity this request acts in, asserted rather than assumed.
     *
     * Unreachable behind `ResolveApiEntity`, and checked anyway: reading or
     * writing with no entity established would either return every company's
     * rows - which looks like data rather than an error - or write rows the
     * row-level security policy then hides from everybody including the
     * writer.
     */
    private function entity(): Entity
    {
        $entity = $this->entities->entity();

        if (! $entity instanceof Entity) {
            throw EntityNotInScope::forQueryOn(JournalEntry::class);
        }

        return $entity;
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws FilterNotAllowed
     */
    private function sort(Request $request): array
    {
        $asked = $request->query('sort');

        if ($asked === null || $asked === '') {
            /*
             | Newest first, by accounting date.
             |
             | Which is what a client polling for what has changed wants, and
             | it is also the order the index is in. A default of oldest-first
             | would make the common case - "give me the last page" - the
             | expensive one.
             */
            return ['accounting_date', 'desc'];
        }

        if (! \is_string($asked)) {
            throw FilterNotAllowed::sort('(not a string)', array_keys(self::SORTABLE));
        }

        $descending = str_starts_with($asked, '-');
        $field = $descending ? substr($asked, 1) : $asked;

        if (! \array_key_exists($field, self::SORTABLE)) {
            throw FilterNotAllowed::sort($asked, array_keys(self::SORTABLE));
        }

        return [$field, $descending ? 'desc' : 'asc'];
    }
}
