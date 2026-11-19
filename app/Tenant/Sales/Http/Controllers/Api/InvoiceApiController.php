<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Http\Controllers\Api;

use App\Support\Http\Api\Page;
use App\Support\Http\Api\Row;
use App\Support\Http\Api\SortOrder;
use App\Support\Http\Api\Wire;
use App\Tenant\Sales\Models\Invoice;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sales invoices, read-only, over the wire - PF-262.
 *
 * The browser's invoice pages, as data, under the same permission they gate
 * their figures on (`report.view`) and reading the same things the same way:
 *
 * - The open balance is the DATABASE's answer,
 *   `peakflow_document_open_balance()`, never a subtraction here. Two places
 *   that compute "what is still owed" are two answers the day an allocation
 *   is reversed.
 * - The customer's name is the one frozen on the invoice when it was issued,
 *   falling back to the party record for a draft. An invoice states who it
 *   was addressed to, and renaming a customer must not rewrite that.
 *
 * Every row is the current entity's: row-level security scopes `invoices` and
 * every table beside it, so an id from another company is a 404 here exactly
 * as it is on the screen.
 */
final class InvoiceApiController
{
    /** @var array<string, string> NOT NULL columns only - see SortOrder. */
    private const SORTABLE = [
        'issue_date' => 'invoices.issue_date',
    ];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('report.view');

        $sort = SortOrder::from($request, self::SORTABLE, 'issue_date', 'desc');

        $query = $this->listing();

        $status = SortOrder::filter($request, 'status', ['draft', 'issued']);

        if ($status !== null) {
            $query->where('invoices.status', $status);
        }

        $customer = $request->query('filter');

        if (\is_array($customer) && isset($customer['customer_id']) && \is_string($customer['customer_id'])) {
            $query->where('invoices.party_id', $customer['customer_id']);
        }

        return Page::asked($request, $sort->scope('listInvoices'))
            ->fill(
                query: $query,
                sortColumn: $sort->column,
                direction: $sort->direction,
                shape: fn (object $row): array => $this->summary($row),
                sortValue: static fn (object $row): string => Row::text($row, 'issue_date'),
                idValue: static fn (object $row): string => Row::text($row, 'id'),
                idColumn: 'invoices.id',
            )
            ->toResponse();
    }

    public function show(string $id): JsonResponse
    {
        Gate::authorize('report.view');

        // Through the model first, so another entity's id is the same 404 as
        // one that never existed - EntityScope and the policy both say so.
        Invoice::query()->findOrFail($id);

        $row = $this->listing()->where('invoices.id', $id)->first();

        if ($row === null) {
            abort(404);
        }

        $currency = Row::text($row, 'currency');
        $body = $this->summary($row);

        $body['delivery_date'] = Wire::accountingDate(Row::nullableText($row, 'delivery_date'));
        $body['prepaid'] = Row::money($row, 'prepaid_amount', $currency);

        $body['lines'] = DB::connection('tenant')->table('invoice_lines')
            ->where('invoice_id', $id)
            ->orderBy('line_no')
            ->get(['line_no', 'item_code', 'description', 'quantity', 'unit_code', 'unit_price', 'net_amount', 'tax_category', 'tax_rate'])
            ->map(static fn (object $line): array => [
                'line_no' => (int) Row::text($line, 'line_no'),
                'item_code' => Row::nullableText($line, 'item_code'),
                'description' => Row::text($line, 'description'),
                // Quantities and unit prices are held at eight places: they
                // are factors, not money, and Money's four would round them.
                'quantity' => Row::text($line, 'quantity'),
                'unit_code' => Row::nullableText($line, 'unit_code'),
                'unit_price' => Row::text($line, 'unit_price'),
                'net' => Row::money($line, 'net_amount', $currency),
                'tax_category' => Row::nullableText($line, 'tax_category'),
                'tax_rate' => Row::nullableText($line, 'tax_rate'),
            ])
            ->values()
            ->all();

        $body['tax_breakdown'] = DB::connection('tenant')->table('invoice_tax_breakdown')
            ->where('invoice_id', $id)
            ->orderBy('category')
            ->orderBy('rate')
            ->get(['category', 'rate', 'taxable_amount', 'tax_amount', 'exemption_reason_code', 'exemption_reason_text'])
            ->map(static fn (object $tax): array => [
                'category' => Row::text($tax, 'category'),
                'rate' => Row::text($tax, 'rate'),
                'taxable' => Row::money($tax, 'taxable_amount', $currency),
                'tax' => Row::money($tax, 'tax_amount', $currency),
                'exemption_reason_code' => Row::nullableText($tax, 'exemption_reason_code'),
                'exemption_reason' => Row::nullableText($tax, 'exemption_reason_text'),
            ])
            ->values()
            ->all();

        // The payments applied to it - the screen's own query.
        $body['payments'] = array_values(array_map(
            static fn (mixed $allocation): array => self::payment(\is_object($allocation) ? $allocation : new \stdClass()),
            DB::connection('tenant')->select(<<<'SQL'
                SELECT p.number AS payment_number,
                       p.value_date,
                       a.allocated_on,
                       a.amount::text AS amount,
                       a.currency,
                       a.reversed_at
                FROM allocations a
                JOIN payments p ON p.id = a.payment_id
                WHERE a.document_type = 'invoice'
                  AND a.document_id = ?
                ORDER BY a.allocated_on, a.id
            SQL, [$id]),
        ));

        return new JsonResponse($body);
    }

    /** @return array<string, mixed> */
    private static function payment(object $allocation): array
    {
        return [
            'payment_number' => Row::nullableText($allocation, 'payment_number'),
            'value_date' => Wire::accountingDate(Row::nullableText($allocation, 'value_date')),
            'allocated_on' => Wire::accountingDate(Row::nullableText($allocation, 'allocated_on')),
            'amount' => Row::money($allocation, 'amount', Row::text($allocation, 'currency')),
            'reversed_at' => Wire::timestamp(Row::nullableText($allocation, 'reversed_at')),
        ];
    }

    /** The listing query, shared by the page and the detail so the two can never disagree. */
    private function listing(): Builder
    {
        return DB::connection('tenant')->table('invoices')
            ->leftJoin('parties', 'parties.id', '=', 'invoices.party_id')
            ->select([
                'invoices.id', 'invoices.number', 'invoices.status', 'invoices.document_type',
                'invoices.issue_date', 'invoices.delivery_date', 'invoices.party_id', 'invoices.currency',
                'invoices.net_amount', 'invoices.tax_amount', 'invoices.gross_amount',
                'invoices.prepaid_amount', 'invoices.payable_amount', 'invoices.issued_at',
            ])
            ->selectRaw("coalesce(invoices.customer_snapshot->>'legal_name', parties.legal_name) AS customer_name")
            ->selectRaw("peakflow_document_open_balance('invoice', invoices.id)::text AS open_balance")
            ->selectRaw(<<<'SQL'
                (SELECT max((d->>'date')::date)
                 FROM jsonb_array_elements(coalesce(invoices.terms_snapshot->'due_dates', '[]'::jsonb)) d)::text AS due_on
            SQL);
    }

    /** @return array<string, mixed> */
    private function summary(object $row): array
    {
        $currency = Row::text($row, 'currency');

        return [
            'resource' => 'invoice',
            'id' => Row::text($row, 'id'),
            'number' => Row::nullableText($row, 'number'),
            'status' => Row::text($row, 'status'),
            'document_type' => Row::text($row, 'document_type'),
            'issue_date' => Wire::accountingDate(Row::nullableText($row, 'issue_date')),
            'due_on' => Wire::accountingDate(Row::nullableText($row, 'due_on')),
            'customer' => [
                'id' => Row::text($row, 'party_id'),
                'name' => Row::text($row, 'customer_name'),
            ],
            'currency' => $currency,
            'net' => Row::money($row, 'net_amount', $currency),
            'tax' => Row::money($row, 'tax_amount', $currency),
            'gross' => Row::money($row, 'gross_amount', $currency),
            'payable' => Row::money($row, 'payable_amount', $currency),
            'open_balance' => Row::money($row, 'open_balance', $currency),
            'issued_at' => Wire::timestamp(Row::nullableText($row, 'issued_at')),
        ];
    }
}
