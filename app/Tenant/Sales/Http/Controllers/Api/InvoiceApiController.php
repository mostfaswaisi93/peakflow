<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Http\Controllers\Api;

use App\Support\Http\Api\DatabaseRefusal;
use App\Support\Http\Api\Page;
use App\Support\Http\Api\Row;
use App\Support\Http\Api\SortOrder;
use App\Support\Http\Api\Wire;
use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Tenant\Catalog\Models\PaymentTerm;
use App\Tenant\Sales\Exceptions\IssueRefused;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Services\InvoiceDrafts;
use App\Tenant\Sales\Services\Issuer;
use App\Tenant\Tax\Exceptions\EvidenceMissing;
use App\Tenant\Tax\Exceptions\TaxCodeRefused;
use App\Tenant\Tax\Services\TaxCodes;
use App\Tenant\Tenancy\Exceptions\EntityNotInScope;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
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
    public function __construct(
        private readonly InvoiceDrafts $drafts,
        private readonly Issuer $issuer,
        private readonly TaxCodes $taxCodes,
        private readonly EntityContext $entities,
    ) {}

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

    /**
     * What a draft can be made of here - PF-266.
     *
     * The revenue accounts, the tax codes in force today with their rates,
     * and the payment terms: everything an app needs to build an invoice form
     * without the chart of accounts, which `account.manage` guards and a sales
     * clerk does not hold.
     */
    public function options(): JsonResponse
    {
        Gate::authorize('invoice.draft');

        $today = Carbon::today();

        $taxCodes = [];

        foreach ($this->taxCodes->availableOn($today) as $code) {
            try {
                [$taxCode, $rate] = $this->taxCodes->resolve((string) $code->code, $today);
            } catch (TaxCodeRefused) {
                continue;
            }

            $taxCodes[] = [
                'code' => (string) $taxCode->code,
                'category' => $taxCode->category->value,
                'rate' => (string) $rate->rate,
                'label' => ['ar' => Row::text($taxCode, 'label_ar'), 'en' => Row::text($taxCode, 'label_en')],
            ];
        }

        return new JsonResponse([
            'revenue_accounts' => DB::connection('tenant')->table('accounts')
                ->where('type', 'revenue')
                ->where('postable', true)
                ->where('blocked', false)
                ->whereNull('deleted_at')
                ->orderBy('code')
                ->get(['code', 'name_ar', 'name_en'])
                ->map(static fn (object $a): array => [
                    'code' => Row::text($a, 'code'),
                    'name' => ['ar' => Row::text($a, 'name_ar'), 'en' => Row::text($a, 'name_en')],
                ])
                ->values()
                ->all(),
            'tax_codes' => $taxCodes,
            'payment_terms' => DB::connection('tenant')->table('payment_terms')
                ->where('active', true)
                ->orderBy('code')
                ->get(['code', 'name_ar', 'name_en'])
                ->map(static fn (object $t): array => [
                    'code' => Row::text($t, 'code'),
                    'name' => ['ar' => Row::text($t, 'name_ar'), 'en' => Row::text($t, 'name_en')],
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Draft an invoice from lines - PF-266.
     *
     * Every figure is computed; none is accepted. Drafting books nothing, so
     * there is no Idempotency-Key: a retried draft is a second draft the list
     * shows and a person deletes, which is a different order of mistake from
     * a second posted invoice.
     */
    public function store(Request $request): JsonResponse|Problem
    {
        Gate::authorize('invoice.draft');

        $request->validate([
            'customer_id' => ['required', 'string', 'size:26'],
            'issue_date' => ['nullable', 'date_format:Y-m-d'],
            'delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'size:3'],
            'revenue_account_code' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,8})?$/'],
            'lines.*.unit_price' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,8})?$/'],
            'lines.*.unit_code' => ['nullable', 'string', 'max:3'],
            'lines.*.item_id' => ['nullable', 'string', 'size:26'],
            'lines.*.tax_code' => ['required', 'string', 'max:32'],
            'lines.*.revenue_account_code' => ['nullable', 'string', 'max:40'],
        ]);

        $entity = $this->entity();
        $userId = $this->actor($request);
        $currency = $request->filled('currency')
            ? $request->string('currency')->upper()->value()
            : (string) $entity->functional_currency;

        $problem = Problem::of(ProblemType::ValidationFailed)
            ->withDetail('The invoice was not drafted: one or more fields name something that cannot be used.');
        $refused = false;

        $customerId = $request->string('customer_id')->value();
        $customer = DB::connection('tenant')->table('parties')
            ->where('id', $customerId)
            ->whereNull('deleted_at')
            ->first(['is_customer', 'active']);

        if ($customer === null || ! Row::bool($customer, 'is_customer') || ! Row::bool($customer, 'active')) {
            $problem->withFieldError('/customer_id', 'not_a_customer', 'No active customer has that id.');
            $refused = true;
        }

        $defaultAccount = $request->filled('revenue_account_code') ? $request->string('revenue_account_code')->value() : null;
        $lines = [];

        foreach (array_values((array) $request->input('lines', [])) as $index => $line) {
            $line = \is_array($line) ? $line : [];
            $accountCode = \is_string($line['revenue_account_code'] ?? null) ? $line['revenue_account_code'] : $defaultAccount;
            $accountId = $accountCode === null ? null : $this->revenueAccount($accountCode);

            if ($accountId === null) {
                $problem->withFieldError(
                    '/lines/'.$index.'/revenue_account_code',
                    'not_a_revenue_account',
                    $accountCode === null
                        ? 'Name the revenue account - on the line, or once as revenue_account_code for every line.'
                        : sprintf('%s is not a postable revenue account here.', $accountCode),
                );
                $refused = true;
            }

            [$itemId, $itemCode, $unit] = $this->item($line['item_id'] ?? null);

            if (($line['item_id'] ?? null) !== null && $itemId === null) {
                $problem->withFieldError('/lines/'.$index.'/item_id', 'not_sellable', 'No active, sellable item has that id.');
                $refused = true;
            }

            $lines[] = [
                'description' => self::field($line, 'description', ''),
                'quantity' => self::field($line, 'quantity', '0'),
                'unit_price' => self::field($line, 'unit_price', '0'),
                'unit_code' => \is_string($line['unit_code'] ?? null) ? $line['unit_code'] : ($unit ?? 'C62'),
                'item_id' => $itemId,
                'item_code' => $itemCode,
                'tax_code' => self::field($line, 'tax_code', ''),
                'revenue_account_id' => (string) $accountId,
            ];
        }

        if ($refused) {
            return $problem;
        }

        try {
            $invoice = $this->drafts->create(
                entity: $entity,
                partyId: $customerId,
                issueDate: $request->filled('issue_date')
                    ? Carbon::createFromFormat('Y-m-d', $request->string('issue_date')->value())?->startOfDay() ?? Carbon::today()
                    : Carbon::today(),
                deliveryDate: $request->filled('delivery_date')
                    ? Carbon::createFromFormat('Y-m-d', $request->string('delivery_date')->value())?->startOfDay()
                    : null,
                currency: $currency,
                lines: $lines,
                note: $request->filled('note') ? $request->string('note')->value() : null,
                userId: $userId,
            );
        } catch (TaxCodeRefused $e) {
            return Problem::of(ProblemType::ValidationFailed)->withDetail($e->getMessage());
        } catch (QueryException $e) {
            return DatabaseRefusal::problem($e) ?? throw $e;
        }

        $response = $this->show($invoice->id);
        $response->setStatusCode(JsonResponse::HTTP_CREATED);
        $response->headers->set('Location', sprintf('%s/invoices/%s', rtrim(URL::to(Config::string('api.prefix')), '/'), $invoice->id));

        return $response;
    }

    /**
     * Issue a draft: validated, numbered, posted, frozen - PF-266.
     *
     * Through {@see Issuer::issue()}, unchanged - segregation of duties, the
     * validator, the number, the posting and the hash chain are all its. It
     * posts to the ledger, so the route requires an Idempotency-Key.
     */
    public function issue(Request $request, string $id): JsonResponse|Problem
    {
        Gate::authorize('invoice.issue');

        $request->validate(['payment_term' => ['nullable', 'string', 'max:32']]);

        /** @var Invoice $draft */
        $draft = Invoice::query()->findOrFail($id);
        $entity = $this->entity();
        $userId = $this->actor($request);

        if ((string) $draft->status !== 'draft') {
            return Problem::of(ProblemType::StateTransitionNotAllowed)->withDetail(sprintf(
                'This invoice is %s already. An issued invoice is corrected by a credit note, never by issuing it again.',
                (string) $draft->status,
            ));
        }

        $terms = null;

        if ($request->filled('payment_term')) {
            $terms = PaymentTerm::query()->where('code', $request->string('payment_term')->value())->where('active', true)->first();

            if (! $terms instanceof PaymentTerm) {
                return Problem::of(ProblemType::ValidationFailed)
                    ->withFieldError('/payment_term', 'unknown', 'No active payment term has that code.');
            }
        }

        $first = DB::connection('tenant')->table('invoice_lines')->where('invoice_id', $id)->orderBy('line_no')->first(['tax_category', 'tax_rate']);

        try {
            $this->issuer->issue(
                $draft,
                $entity,
                $userId,
                [
                    'country' => \is_string($entity->country) ? $entity->country : 'SA',
                    'rule' => 'domestic',
                    'category' => $first === null ? null : Row::text($first, 'tax_category'),
                    'rate' => $first === null ? null : Row::text($first, 'tax_rate'),
                ],
                $terms,
            );
        } catch (IssueRefused|EvidenceMissing $refused) {
            /*
             | The validator's findings, or a missing piece of tax evidence -
             | the document is not valid to issue as it stands. Nothing was
             | numbered or posted; correct the draft and issue it again.
             */
            return Problem::of(ProblemType::DocumentValidationFailed)->withDetail($refused->getMessage());
        } catch (QueryException $e) {
            return DatabaseRefusal::problem($e) ?? throw $e;
        }

        return $this->show($id);
    }

    private function entity(): Entity
    {
        $entity = $this->entities->entity();

        if (! $entity instanceof Entity) {
            throw EntityNotInScope::forQueryOn(Invoice::class);
        }

        return $entity;
    }

    private function actor(Request $request): string
    {
        $user = $request->user();
        $id = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;

        if (! \is_string($id) || $id === '') {
            throw new AuthenticationException();
        }

        return $id;
    }

    private function revenueAccount(string $code): ?string
    {
        $id = DB::connection('tenant')->table('accounts')
            ->where('code', $code)
            ->where('type', 'revenue')
            ->where('postable', true)
            ->where('blocked', false)
            ->whereNull('deleted_at')
            ->value('id');

        return \is_string($id) ? $id : null;
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} id, code, unit - all null when no item, or not a sellable one */
    private function item(mixed $id): array
    {
        if (! \is_string($id) || $id === '') {
            return [null, null, null];
        }

        $item = DB::connection('tenant')->table('items')
            ->where('id', $id)
            ->where('sellable', true)
            ->where('active', true)
            ->whereNull('deleted_at')
            ->first(['id', 'code', 'unit_code']);

        return $item === null
            ? [null, null, null]
            : [Row::text($item, 'id'), Row::nullableText($item, 'code'), Row::nullableText($item, 'unit_code')];
    }

    /** @param array<mixed> $line */
    private static function field(array $line, string $key, string $default): string
    {
        $value = $line[$key] ?? null;

        return \is_string($value) ? $value : $default;
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
