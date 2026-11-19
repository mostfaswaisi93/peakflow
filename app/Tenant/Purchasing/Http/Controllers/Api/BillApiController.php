<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Http\Controllers\Api;

use App\Support\Http\Api\Page;
use App\Support\Http\Api\Row;
use App\Support\Http\Api\SortOrder;
use App\Support\Http\Api\Wire;
use App\Tenant\Purchasing\Models\Bill;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Supplier bills, read-only, over the wire - PF-262.
 *
 * The browser's bill pages as data, under the same permission and with the
 * same readings:
 *
 * - The open balance is `peakflow_document_open_balance('bill', …)`, not
 *   `Bill::outstanding()` - the screen chose the database's answer on
 *   purpose, and an app that computed its own would be the second opinion.
 * - A bill has no due-date column. Its due date is the latest date in the
 *   payment terms frozen on it, falling back to the supplier's document date:
 *   the screen's own expression, copied rather than re-derived.
 */
final class BillApiController
{
    /** @var array<string, string> NOT NULL columns only - see SortOrder. */
    private const SORTABLE = [
        'posting_date' => 'bills.posting_date',
        'supplier_document_date' => 'bills.supplier_document_date',
    ];

    private const STATUSES = ['draft', 'awaiting_approval', 'approved', 'posted', 'cancelled'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('report.view');

        $sort = SortOrder::from($request, self::SORTABLE, 'posting_date', 'desc');

        $query = $this->listing();

        $status = SortOrder::filter($request, 'status', self::STATUSES);

        if ($status !== null) {
            $query->where('bills.status', $status);
        }

        $filters = $request->query('filter');

        if (\is_array($filters) && isset($filters['supplier_id']) && \is_string($filters['supplier_id'])) {
            $query->where('bills.party_id', $filters['supplier_id']);
        }

        return Page::asked($request, $sort->scope('listBills'))
            ->fill(
                query: $query,
                sortColumn: $sort->column,
                direction: $sort->direction,
                shape: fn (object $row): array => $this->summary($row),
                sortValue: static fn (object $row): string => Row::text($row, $sort->field),
                idValue: static fn (object $row): string => Row::text($row, 'id'),
                idColumn: 'bills.id',
            )
            ->toResponse();
    }

    public function show(string $id): JsonResponse
    {
        Gate::authorize('report.view');

        Bill::query()->findOrFail($id);

        $row = $this->listing()->where('bills.id', $id)->first();

        if ($row === null) {
            abort(404);
        }

        $currency = Row::text($row, 'currency');
        $body = $this->summary($row);

        $body['tax_point'] = Wire::accountingDate(Row::nullableText($row, 'tax_point'));
        $body['recoverable_tax'] = Row::money($row, 'recoverable_tax_amount', $currency);
        $body['non_recoverable_tax'] = Row::money($row, 'non_recoverable_tax_amount', $currency);

        $body['lines'] = DB::connection('tenant')->table('bill_lines')
            ->where('bill_id', $id)
            ->orderBy('line_no')
            ->get(['line_no', 'item_code', 'description', 'quantity', 'unit_code', 'unit_price', 'net_amount', 'tax_category', 'tax_rate', 'tax_amount', 'recoverable_percent', 'reverse_charge'])
            ->map(static fn (object $line): array => [
                'line_no' => (int) Row::text($line, 'line_no'),
                'item_code' => Row::nullableText($line, 'item_code'),
                'description' => Row::text($line, 'description'),
                'quantity' => Row::text($line, 'quantity'),
                'unit_code' => Row::nullableText($line, 'unit_code'),
                'unit_price' => Row::text($line, 'unit_price'),
                'net' => Row::money($line, 'net_amount', $currency),
                'tax_category' => Row::nullableText($line, 'tax_category'),
                'tax_rate' => Row::nullableText($line, 'tax_rate'),
                'tax' => Row::money($line, 'tax_amount', $currency),
                'recoverable_percent' => Row::nullableText($line, 'recoverable_percent'),
                'reverse_charge' => Row::bool($line, 'reverse_charge'),
            ])
            ->values()
            ->all();

        $body['tax_breakdown'] = DB::connection('tenant')->table('bill_tax_breakdown')
            ->where('bill_id', $id)
            ->orderBy('category')
            ->orderBy('rate')
            ->get(['category', 'rate', 'taxable_amount', 'tax_amount', 'recoverable_amount', 'reverse_charge'])
            ->map(static fn (object $tax): array => [
                'category' => Row::text($tax, 'category'),
                'rate' => Row::text($tax, 'rate'),
                'taxable' => Row::money($tax, 'taxable_amount', $currency),
                'tax' => Row::money($tax, 'tax_amount', $currency),
                'recoverable' => Row::money($tax, 'recoverable_amount', $currency),
                'reverse_charge' => Row::bool($tax, 'reverse_charge'),
            ])
            ->values()
            ->all();

        return new JsonResponse($body);
    }

    private function listing(): Builder
    {
        return DB::connection('tenant')->table('bills')
            ->leftJoin('parties', 'parties.id', '=', 'bills.party_id')
            ->select([
                'bills.id', 'bills.status', 'bills.document_type', 'bills.supplier_document_number',
                'bills.supplier_document_date', 'bills.posting_date', 'bills.tax_point', 'bills.party_id',
                'bills.currency', 'bills.net_amount', 'bills.tax_amount', 'bills.gross_amount',
                'bills.payable_amount', 'bills.recoverable_tax_amount', 'bills.non_recoverable_tax_amount',
                'bills.approved_at', 'bills.posted_at',
            ])
            ->selectRaw("coalesce(bills.supplier_snapshot->>'legal_name', parties.legal_name) AS supplier_name")
            ->selectRaw("peakflow_document_open_balance('bill', bills.id)::text AS open_balance")
            ->selectRaw(<<<'SQL'
                coalesce((
                    SELECT max((d->>'date')::date)
                    FROM jsonb_array_elements(bills.terms_snapshot->'due_dates') d
                ), bills.supplier_document_date)::text AS due_on
            SQL);
    }

    /** @return array<string, mixed> */
    private function summary(object $row): array
    {
        $currency = Row::text($row, 'currency');

        return [
            'resource' => 'bill',
            'id' => Row::text($row, 'id'),
            'status' => Row::text($row, 'status'),
            'document_type' => Row::text($row, 'document_type'),
            'supplier_document_number' => Row::text($row, 'supplier_document_number'),
            'supplier_document_date' => Wire::accountingDate(Row::nullableText($row, 'supplier_document_date')),
            'posting_date' => Wire::accountingDate(Row::nullableText($row, 'posting_date')),
            'due_on' => Wire::accountingDate(Row::nullableText($row, 'due_on')),
            'supplier' => [
                'id' => Row::text($row, 'party_id'),
                'name' => Row::text($row, 'supplier_name'),
            ],
            'currency' => $currency,
            'net' => Row::money($row, 'net_amount', $currency),
            'tax' => Row::money($row, 'tax_amount', $currency),
            'gross' => Row::money($row, 'gross_amount', $currency),
            'payable' => Row::money($row, 'payable_amount', $currency),
            'open_balance' => Row::money($row, 'open_balance', $currency),
            'approved_at' => Wire::timestamp(Row::nullableText($row, 'approved_at')),
            'posted_at' => Wire::timestamp(Row::nullableText($row, 'posted_at')),
        ];
    }
}
