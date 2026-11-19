<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Http\Controllers\Api;

use Illuminate\Contracts\Auth\Authenticatable;
use App\Support\Http\Api\DatabaseRefusal;
use App\Support\Http\Api\Page;
use App\Support\Http\Api\Row;
use App\Support\Http\Api\SortOrder;
use App\Support\Http\Api\Wire;
use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Tenant\Purchasing\Exceptions\BillRefused;
use App\Tenant\Purchasing\Models\ApprovalRequest;
use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Purchasing\Services\BillApprovals;
use App\Tenant\Tenancy\Exceptions\EntityNotInScope;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
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
    public function __construct(
        private readonly BillApprovals $approvals,
        private readonly EntityContext $entities,
    ) {}

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

        $body['approval'] = $this->approval($id);

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

    /**
     * Submit a draft bill for approval - PF-265.
     *
     * The chain is compiled from the company's rules and written down; a bill
     * no rule covers is refused rather than waved through.
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        Gate::authorize('bill.create');

        [$bill, $entity, $user] = $this->subject($request, $id);

        $this->approvals->submit($bill, $entity, $user->getAuthIdentifier());

        return $this->show($id);
    }

    /**
     * Approve the open step. When it is the last, the bill is posted in the
     * same transaction - see BillApprovals.
     */
    public function approve(Request $request, string $id): JsonResponse|Problem
    {
        Gate::authorize('bill.approve');

        $request->validate(['comment' => ['nullable', 'string', 'max:500']]);

        return $this->decide($request, $id, 'approved');
    }

    /** Reject: the bill returns to draft, with the reason on the chain. */
    public function reject(Request $request, string $id): JsonResponse|Problem
    {
        Gate::authorize('bill.approve');

        $request->validate(['comment' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->decide($request, $id, 'rejected');
    }

    /** @param 'approved'|'rejected' $decision */
    private function decide(Request $request, string $id, string $decision): JsonResponse|Problem
    {
        [$bill, $entity, $user] = $this->subject($request, $id);

        try {
            $this->approvals->decide(
                $bill,
                $entity,
                $user->getAuthIdentifier(),
                $decision,
                $request->filled('comment') ? $request->string('comment')->trim()->value() : null,
            );
        } catch (BillRefused $refused) {
            /*
             | The ledger refused to book the bill the last approval cleared -
             | a duplicate nobody acknowledged, an open three-way match. The
             | whole decision rolled back with it, so the bill is still
             | awaiting this approval, and can be approved again once fixed.
             */
            return Problem::of(ProblemType::ValidationFailed)->withDetail($refused->getMessage());
        } catch (QueryException $e) {
            return DatabaseRefusal::problem($e) ?? throw $e;
        }

        return $this->show($id);
    }

    /** @return array{0: Bill, 1: Entity, 2: Authenticatable} */
    private function subject(Request $request, string $id): array
    {
        /** @var Bill $bill */
        $bill = Bill::query()->findOrFail($id);
        $entity = $this->entities->entity();
        $user = $request->user();

        if (! $entity instanceof Entity) {
            throw EntityNotInScope::forQueryOn(Bill::class);
        }

        if (! $user instanceof Authenticatable) {
            throw new AuthenticationException();
        }

        return [$bill, $entity, $user];
    }

    /**
     * The bill's latest approval chain, as the app shows it.
     *
     * @return array<string, mixed>|null
     */
    private function approval(string $billId): ?array
    {
        $chain = DB::connection('tenant')->table('approval_requests')
            ->where('subject_type', 'bill')
            ->where('subject_id', $billId)
            ->orderByDesc('requested_at')
            ->first(['id', 'status', 'requested_by', 'requested_at', 'decided_at', 'outcome_detail']);

        if ($chain === null) {
            return null;
        }

        $chainId = Row::text($chain, 'id');
        $status = Row::text($chain, 'status');

        $model = $status === 'pending' ? ApprovalRequest::query()->find($chainId) : null;

        return [
            'id' => $chainId,
            'status' => $status,
            'requested_by' => Row::nullableText($chain, 'requested_by'),
            'requested_at' => Wire::timestamp(Row::nullableText($chain, 'requested_at')),
            'decided_at' => Wire::timestamp(Row::nullableText($chain, 'decided_at')),
            'outcome' => Row::nullableText($chain, 'outcome_detail'),
            'who_can_decide' => $model instanceof ApprovalRequest ? $this->approvals->whoCanDecide($model) : null,
            'steps' => DB::connection('tenant')->table('approval_steps')
                ->where('approval_request_id', $chainId)
                ->orderBy('sequence')
                ->get(['sequence', 'role', 'user_id', 'quorum', 'approvals', 'status'])
                ->map(static fn (object $step): array => [
                    'sequence' => (int) Row::text($step, 'sequence'),
                    'role' => Row::nullableText($step, 'role'),
                    'user_id' => Row::nullableText($step, 'user_id'),
                    'quorum' => (int) Row::text($step, 'quorum'),
                    'approvals' => (int) Row::text($step, 'approvals'),
                    'status' => Row::text($step, 'status'),
                ])
                ->values()
                ->all(),
            'decisions' => DB::connection('tenant')->table('approval_decisions')
                ->leftJoin('users', 'users.id', '=', 'approval_decisions.user_id')
                ->where('approval_decisions.approval_request_id', $chainId)
                ->orderBy('approval_decisions.decided_at')
                ->get(['approval_decisions.user_id', 'users.name', 'approval_decisions.decision', 'approval_decisions.comment', 'approval_decisions.decided_at'])
                ->map(static fn (object $decision): array => [
                    'user_id' => Row::text($decision, 'user_id'),
                    'name' => Row::nullableText($decision, 'name'),
                    'decision' => Row::text($decision, 'decision'),
                    'comment' => Row::nullableText($decision, 'comment'),
                    'decided_at' => Wire::timestamp(Row::nullableText($decision, 'decided_at')),
                ])
                ->values()
                ->all(),
        ];
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
