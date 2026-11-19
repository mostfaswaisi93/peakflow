<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Http\Controllers\Api;

use App\Support\Http\Api\Page;
use App\Support\Http\Api\Row;
use App\Support\Http\Api\SortOrder;
use App\Support\Http\Api\Wire;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Customers and suppliers, read-only, over the wire - PF-262.
 *
 * ## Tenant-wide, like the screen
 *
 * A party is shared by every company in the workspace - one customer, many
 * subsidiaries selling to it - so `parties` has no entity and no row-level
 * policy. What IS per company (the credit limit, the hold, the open
 * documents) is read through the entity-scoped tables beside it.
 *
 * ## Never a bank account number
 *
 * Only the last four digits, the way the screen shows them. Reading a full
 * IBAN is an audited act with a stated reason, through `BankDetailDisclosure`
 * and nowhere else - an architecture rule enforces that - and a phone app is
 * the last place a list of them should arrive in bulk.
 */
final class PartyApiController
{
    /** @var array<string, string> NOT NULL columns only - see SortOrder. */
    private const SORTABLE = [
        'legal_name' => 'parties.legal_name',
    ];

    public function __construct(private readonly EntityContext $entities) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('party.manage');

        $sort = SortOrder::from($request, self::SORTABLE, 'legal_name', 'asc');

        $query = $this->listing();

        $role = SortOrder::filter($request, 'role', ['customer', 'supplier', 'employee']);

        if ($role !== null) {
            $query->where('parties.is_'.$role, true);
        }

        $active = SortOrder::filter($request, 'active', ['true', 'false']);

        if ($active !== null) {
            $query->where('parties.active', $active === 'true');
        }

        /*
         | A search, with LIKE's own wildcards escaped - `%` and `_` typed by
         | a person are looked for, not obeyed.
         */
        $q = $request->query('q');

        if (\is_string($q) && trim($q) !== '') {
            $like = '%'.addcslashes(trim(mb_substr($q, 0, 100)), '%_\\').'%';

            $query->where(static fn (Builder $w) => $w
                ->where('parties.legal_name', 'ilike', $like)
                ->orWhere('parties.trading_name', 'ilike', $like)
                ->orWhere('parties.code', 'ilike', $like));
        }

        return Page::asked($request, $sort->scope('listParties'))
            ->fill(
                query: $query,
                sortColumn: $sort->column,
                direction: $sort->direction,
                shape: fn (object $row): array => $this->summary($row),
                sortValue: static fn (object $row): string => Row::text($row, 'legal_name'),
                idValue: static fn (object $row): string => Row::text($row, 'id'),
                idColumn: 'parties.id',
            )
            ->toResponse();
    }

    public function show(string $id): JsonResponse
    {
        Gate::authorize('party.manage');

        Party::query()->findOrFail($id);

        $row = $this->listing()->where('parties.id', $id)->first();

        if ($row === null) {
            abort(404);
        }

        $body = $this->summary($row);
        $entity = $this->entities->entity();
        $functional = $entity === null ? '' : (string) $entity->functional_currency;

        $setting = DB::connection('tenant')->table('party_entity_settings')
            ->where('party_id', $id)
            ->first(['credit_limit', 'credit_hold', 'credit_hold_reason', 'credit_hold_at']);

        $body['credit'] = [
            'limit' => $setting === null || $functional === '' ? null : Row::money($setting, 'credit_limit', $functional),
            'on_hold' => $setting !== null && Row::bool($setting, 'credit_hold'),
            'hold_reason' => $setting === null ? null : Row::nullableText($setting, 'credit_hold_reason'),
            'held_at' => $setting === null ? null : Wire::timestamp(Row::nullableText($setting, 'credit_hold_at')),
        ];

        $body['bank_accounts'] = DB::connection('tenant')->table('party_bank_accounts')
            ->where('party_id', $id)
            ->whereNull('deleted_at')
            ->orderByDesc('requires_reverification')
            ->orderByDesc('active')
            ->orderBy('created_at')
            ->get(['id', 'label', 'bank_name', 'country', 'currency', 'last_four', 'active', 'requires_reverification', 'verified_at'])
            ->map(static fn (object $account): array => [
                'id' => Row::text($account, 'id'),
                'label' => Row::nullableText($account, 'label'),
                'bank_name' => Row::nullableText($account, 'bank_name'),
                'country' => Row::nullableText($account, 'country'),
                'currency' => Row::nullableText($account, 'currency'),
                'masked' => '•••• '.Row::text($account, 'last_four'),
                'active' => Row::bool($account, 'active'),
                'requires_reverification' => Row::bool($account, 'requires_reverification'),
                'verified_at' => Wire::timestamp(Row::nullableText($account, 'verified_at')),
            ])
            ->values()
            ->all();

        /*
         | What is still open with them, in this company - the screen's own
         | query: issued invoices and approved bills whose database open
         | balance is not zero.
         */
        $body['open_documents'] = array_values(array_map(
            static fn (object $document): array => [
                'kind' => Row::text($document, 'kind'),
                'id' => Row::text($document, 'id'),
                'reference' => Row::text($document, 'reference'),
                'document_date' => Wire::accountingDate(Row::nullableText($document, 'document_date')),
                'gross' => Row::money($document, 'gross_amount', Row::text($document, 'currency')),
                'open_balance' => Row::money($document, 'open_balance', Row::text($document, 'currency')),
            ],
            DB::connection('tenant')->select(<<<'SQL'
                SELECT 'invoice' AS kind,
                       i.id,
                       coalesce(i.number, '') AS reference,
                       i.issue_date AS document_date,
                       i.currency AS currency,
                       i.gross_amount::text AS gross_amount,
                       peakflow_document_open_balance('invoice', i.id)::text AS open_balance
                FROM invoices i
                WHERE i.party_id = cast(? AS char(26))
                  AND i.status = 'issued'
                  AND peakflow_document_open_balance('invoice', i.id) <> 0
                UNION ALL
                SELECT 'bill' AS kind,
                       b.id,
                       b.supplier_document_number AS reference,
                       b.supplier_document_date AS document_date,
                       b.currency AS currency,
                       b.gross_amount::text AS gross_amount,
                       peakflow_document_open_balance('bill', b.id)::text AS open_balance
                FROM bills b
                WHERE b.party_id = cast(? AS char(26))
                  AND b.approved_at IS NOT NULL
                  AND peakflow_document_open_balance('bill', b.id) <> 0
                ORDER BY document_date, reference
            SQL, [$id, $id]),
        ));

        return new JsonResponse($body);
    }

    private function listing(): Builder
    {
        return DB::connection('tenant')->table('parties')
            ->whereNull('parties.deleted_at')
            ->select([
                'parties.id', 'parties.code', 'parties.legal_name', 'parties.trading_name',
                'parties.is_customer', 'parties.is_supplier', 'parties.is_employee',
                'parties.country', 'parties.default_currency', 'parties.active',
            ]);
    }

    /** @return array<string, mixed> */
    private function summary(object $row): array
    {
        return [
            'resource' => 'party',
            'id' => Row::text($row, 'id'),
            'code' => Row::nullableText($row, 'code'),
            'legal_name' => Row::text($row, 'legal_name'),
            'trading_name' => Row::nullableText($row, 'trading_name'),
            'is_customer' => Row::bool($row, 'is_customer'),
            'is_supplier' => Row::bool($row, 'is_supplier'),
            'is_employee' => Row::bool($row, 'is_employee'),
            'country' => Row::nullableText($row, 'country'),
            'default_currency' => Row::nullableText($row, 'default_currency'),
            'active' => Row::bool($row, 'active'),
        ];
    }
}
