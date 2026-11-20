<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Sales\Enums\OrderState;
use App\Tenant\Sales\Enums\QuoteState;
use App\Tenant\Sales\Exceptions\OrderRefused;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Models\InvoiceTaxBreakdownRow;
use App\Tenant\Sales\Models\Quote;
use App\Tenant\Sales\Models\SalesOrder;
use App\Tenant\Sales\Models\SalesOrderLine;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Services\TaxCalculator;
use App\Tenant\Tax\Services\TaxRounding;
use App\Tenant\Tax\TaxableLine;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales orders, and invoicing against them - PF-090.
 *
 * ## An order never posts
 *
 * A confirmed order is a commitment, and a commitment is neither a
 * receivable nor a liability: nothing has been supplied and nothing is owed.
 * It shows against the customer's credit exposure, and where a tenant wants
 * budget visibility it shows there - but it writes no journal entry, and a
 * check asserts that no entry ever names an order as its source.
 *
 * ## Partial invoicing is the point
 *
 * An order invoiced across several documents is the ordinary case: a deposit
 * now, the balance on delivery, or one line this month and the rest next. The
 * balance is tracked **per line** because that is the question people ask -
 * "have we invoiced the installation yet?" - and an order-level balance
 * cannot answer it, so the second invoice duplicates a line and nobody
 * notices until the customer does.
 */
final class SalesOrders
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly TaxCalculator $calculator,
        private readonly TaxRounding $rounding,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * An order from an accepted quote.
     *
     * @throws OrderRefused
     */
    public function fromQuote(Quote $quote, ?Carbon $date = null): SalesOrder
    {
        if ($quote->status !== QuoteState::Accepted) {
            throw OrderRefused::fromAnUnacceptedQuote($quote->label(), $quote->status->label('en'));
        }

        if ($quote->outcome_id !== null) {
            throw OrderRefused::theQuoteAlreadyBecameSomething(
                $quote->label(),
                (string) $quote->outcome_type,
            );
        }

        $party = $quote->party;

        if ($party === null) {
            throw OrderRefused::noCustomer();
        }

        $entity = Entity::query()->findOrFail((string) $quote->entity_id);
        $date ??= Carbon::now();

        $lines = $quote->lines()->get()->map(static fn (object $line): array => [
            'description' => (string) $line->description,
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'unit_code' => (string) $line->unit_code,
            'item_id' => $line->item_id,
            'item_code' => $line->item_code,
            'revenue_account_id' => $line->revenue_account_id,
            'tax_code_id' => $line->tax_code_id,
            'tax_category' => (string) $line->tax_category,
            'tax_rate' => (string) $line->tax_rate,
        ])->all();

        return DB::connection('tenant')->transaction(function () use ($entity, $party, $lines, $date, $quote): SalesOrder {
            $order = $this->draft($entity, $party, $lines, $date, (string) $quote->getKey());

            $quote->update(['outcome_type' => 'order', 'outcome_id' => (string) $order->getKey()]);

            return $order;
        });
    }

    /**
     * An order from scratch, in draft.
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws OrderRefused
     */
    public function draft(
        Entity $entity,
        Party $customer,
        array $lines,
        ?Carbon $date = null,
        ?string $quoteId = null,
        ?string $profile = null,
    ): SalesOrder {
        $date ??= Carbon::now();

        if (! $customer->is_customer) {
            throw OrderRefused::notACustomer((string) $customer->code);
        }

        if ($lines === []) {
            throw OrderRefused::ofNothing();
        }

        $currency = (string) ($customer->default_currency ?? $entity->functional_currency);
        $profile ??= (string) config('jurisdictions.default_profile', 'generic');

        return DB::connection('tenant')->transaction(function () use (
            $entity, $customer, $lines, $date, $quoteId, $currency, $profile
        ): SalesOrder {
            $taxable = [];
            $prepared = [];

            foreach ($lines as $index => $line) {
                $net = Money::of(
                    (string) BigDecimal::of((string) $line['quantity'])
                        ->multipliedBy((string) $line['unit_price'])
                        ->toScale(4, RoundingMode::HalfUp),
                    $currency,
                );

                if (! $net->isPositive()) {
                    throw OrderRefused::aLineOfNothing($index + 1);
                }

                $category = TaxCategory::from((string) ($line['tax_category'] ?? 'S'));
                $prepared[] = $line + ['line_no' => $index + 1, 'net' => $net, 'category' => $category];
                $taxable[] = TaxableLine::of($net, $category, (string) ($line['tax_rate'] ?? '0'));
            }

            $breakdown = $this->calculator->compute(
                $taxable,
                $this->rounding->policyFor($profile, $currency),
            );

            $order = SalesOrder::create([
                'entity_id' => (string) $entity->getKey(),
                'reference' => $this->numbers->next((string) $entity->getKey(), 'sales_order', 'SO', $date),
                'party_id' => (string) $customer->getKey(),
                'status' => OrderState::Draft->value,
                'order_date' => $date->toDateString(),
                'currency' => $currency,
                'net_amount' => (string) $breakdown->net->amount,
                'tax_amount' => (string) $breakdown->tax->amount,
                'gross_amount' => (string) $breakdown->gross()->amount,
                'presentation_exponent' => 2,
                'profile' => $profile,
                'quote_id' => $quoteId,
            ]);

            foreach ($prepared as $line) {
                SalesOrderLine::create([
                    'entity_id' => (string) $entity->getKey(),
                    'sales_order_id' => (string) $order->getKey(),
                    'line_no' => $line['line_no'],
                    'item_id' => $line['item_id'] ?? null,
                    'supply_type' => \App\Tenant\Sales\SupplyTypes::of(
                        \is_string($line['supply_type'] ?? null) ? (string) $line['supply_type'] : null,
                        \is_string($line['item_id'] ?? null) ? (string) $line['item_id'] : null,
                    ),
                    'item_code' => $line['item_code'] ?? null,
                    'description' => (string) $line['description'],
                    'unit_code' => (string) ($line['unit_code'] ?? 'C62'),
                    'quantity' => (string) $line['quantity'],
                    'unit_price' => (string) $line['unit_price'],
                    'net_amount' => (string) $line['net']->amount,
                    'revenue_account_id' => $line['revenue_account_id'] ?? null,
                    'tax_code_id' => $line['tax_code_id'] ?? null,
                    'tax_category' => $line['category']->value,
                    'tax_rate' => (string) ($line['tax_rate'] ?? '0'),
                ]);
            }

            return $order->refresh();
        });
    }

    /** @throws OrderRefused */
    public function confirm(SalesOrder $order, ?string $userId = null): SalesOrder
    {
        $this->assertMayBecome($order, OrderState::Confirmed);

        return DB::connection('tenant')->transaction(function () use ($order, $userId): SalesOrder {
            $order->update([
                'status' => OrderState::Confirmed->value,
                'confirmed_at' => now(),
                'confirmed_by' => $userId,
            ]);

            $this->audit->record(
                category: 'sales',
                action: 'order.confirmed',
                subjectType: 'sales_order',
                subjectId: (string) $order->getKey(),
                subjectLabel: (string) $order->reference,
                after: ['gross' => (string) $order->gross_amount],
                entityId: (string) $order->entity_id,
                partyId: (string) $order->party_id,
            );

            return $order->refresh();
        });
    }

    /**
     * Cancel it, with a reason.
     *
     * @throws OrderRefused
     */
    public function cancel(SalesOrder $order, string $reason): SalesOrder
    {
        $this->assertMayBecome($order, OrderState::Cancelled);

        if (mb_strlen(trim($reason)) < 10 || ! str_contains(trim($reason), ' ')) {
            throw OrderRefused::aCancellationWithNoReason();
        }

        $invoiced = $order->lines()->sum('invoiced_net');

        if (Money::of((string) $invoiced, (string) $order->currency)->isPositive()) {
            throw OrderRefused::alreadyInvoiced(
                (string) $order->reference,
                (string) $invoiced,
            );
        }

        return DB::connection('tenant')->transaction(function () use ($order, $reason): SalesOrder {
            $order->update([
                'status' => OrderState::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_reason' => trim($reason),
            ]);

            $this->audit->record(
                category: 'sales',
                action: 'order.cancelled',
                subjectType: 'sales_order',
                subjectId: (string) $order->getKey(),
                subjectLabel: (string) $order->reference,
                after: ['gross' => (string) $order->gross_amount],
                reason: trim($reason),
                entityId: (string) $order->entity_id,
                partyId: (string) $order->party_id,
            );

            return $order->refresh();
        });
    }

    /**
     * An invoice draft for part or all of a confirmed order.
     *
     * @param  array<string, string>|null  $quantities  order line id => quantity to invoice; null invoices everything left
     *
     * @throws OrderRefused
     */
    public function invoice(SalesOrder $order, ?array $quantities = null, ?Carbon $date = null): Invoice
    {
        if (! $order->status->mayBeInvoiced()) {
            throw OrderRefused::notConfirmed((string) $order->reference, $order->status->label('en'));
        }

        $date ??= Carbon::now();
        $currency = (string) $order->currency;
        $selected = [];

        foreach ($order->lines()->get() as $line) {
            $quantity = $quantities === null
                ? (string) $line->remainingQuantity()
                : (string) ($quantities[(string) $line->getKey()] ?? '0');

            $asked = BigDecimal::of($quantity);

            if ($asked->isZero()) {
                continue;
            }

            if ($asked->isNegative()) {
                throw OrderRefused::aNegativeQuantity((int) $line->line_no);
            }

            if ($asked->isGreaterThan($line->remainingQuantity())) {
                throw OrderRefused::overInvoiced(
                    (int) $line->line_no,
                    (string) $line->remainingQuantity(),
                    (string) $asked,
                );
            }

            $net = Money::of(
                (string) $asked->multipliedBy((string) $line->unit_price)->toScale(4, RoundingMode::HalfUp),
                $currency,
            );

            $selected[] = ['line' => $line, 'quantity' => (string) $asked, 'net' => $net];
        }

        if ($selected === []) {
            throw OrderRefused::nothingLeftToInvoice((string) $order->reference);
        }

        return DB::connection('tenant')->transaction(function () use ($order, $selected, $date, $currency): Invoice {
            $breakdown = $this->calculator->compute(
                array_map(static fn (array $s): TaxableLine => TaxableLine::of(
                    $s['net'],
                    TaxCategory::from((string) $s['line']->tax_category),
                    (string) $s['line']->tax_rate,
                ), $selected),
                $this->rounding->policyFor((string) ($order->profile ?? 'generic'), $currency),
            );

            $invoice = Invoice::create([
                'entity_id' => (string) $order->entity_id,
                'series_key' => 'INV',
                'document_type' => '380',
                'status' => 'draft',
                'party_id' => (string) $order->party_id,
                'issue_date' => $date->toDateString(),
                'tax_point' => $date->toDateString(),
                'currency' => $currency,
                'net_amount' => (string) $breakdown->net->amount,
                'tax_amount' => (string) $breakdown->tax->amount,
                'gross_amount' => (string) $breakdown->gross()->amount,
                'payable_amount' => (string) $breakdown->gross()->amount,
                'presentation_exponent' => (int) $order->presentation_exponent,
                'profile' => $order->profile,
            ]);

            foreach ($selected as $index => $chosen) {
                /** @var SalesOrderLine $line */
                $line = $chosen['line'];

                InvoiceLine::create([
                    'entity_id' => (string) $order->entity_id,
                    'invoice_id' => (string) $invoice->getKey(),
                    'line_no' => $index + 1,
                    'item_id' => $line->item_id,
                    'item_code' => $line->item_code,
                    'description' => (string) $line->description,
                    'unit_code' => (string) $line->unit_code,
                    'quantity' => $chosen['quantity'],
                    'unit_price' => (string) $line->unit_price,
                    'net_amount' => (string) $chosen['net']->amount,
                    'revenue_account_id' => $line->revenue_account_id,
                    'tax_code_id' => $line->tax_code_id,
                    'supply_type' => \App\Tenant\Sales\SupplyTypes::of(
                        \is_string($line->supply_type ?? null) ? (string) $line->supply_type : null,
                        $line->item_id === null ? null : (string) $line->item_id,
                    ),
                    'tax_category' => (string) $line->tax_category,
                    'tax_rate' => (string) $line->tax_rate,
                    'tax_point' => $date->toDateString(),
                    /*
                     | Not stated on a draft - PF-078.
                     |
                     | This used to write `'domestic'` as a literal, which
                     | satisfied the validator's `place_of_supply_unresolved`
                     | gate with the very fiction the gate exists to catch.
                     | `Issuer` asks the rule book at issue and writes both
                     | columns from the decision; until then the honest value
                     | is "not decided yet".
                     */
                    'place_of_supply' => null,
                    'place_of_supply_rule' => null,

                    /*
                     | The link that makes the balance per line real.
                     |
                     | Without it the cap could only be enforced per order,
                     | and an order invoiced to 60% could be any combination
                     | of its lines.
                     */
                    'order_line_id' => (string) $line->getKey(),
                ]);
            }

            foreach ($breakdown->entries as $entry) {
                InvoiceTaxBreakdownRow::create([
                    'entity_id' => (string) $order->entity_id,
                    'invoice_id' => (string) $invoice->getKey(),
                    'category' => $entry->category->value,
                    'rate' => $entry->ratePercent,
                    'taxable_amount' => (string) $entry->taxableAmount->amount,
                    'tax_amount' => (string) $entry->taxAmount->amount,
                    'exemption_reason_code' => $entry->reasonCode,
                    'exemption_reason_text' => $entry->reasonText,
                    'report_box' => 'standard_sales',
                ]);
            }

            \App\Tenant\Sales\Models\InvoiceReference::create([
                'entity_id' => (string) $order->entity_id,
                'invoice_id' => (string) $invoice->getKey(),
                'kind' => 'order',
                'reference' => (string) $order->reference,
                'reference_date' => $order->order_date?->toDateString(),
            ]);

            return $invoice->refresh();
        });
    }

    /**
     * What confirmed orders commit, for credit exposure and budgets - PF-090.
     *
     * ## Why this is a figure and not a posting
     *
     * A commitment is not a liability or a receivable: nothing has been
     * supplied and nothing is owed. Posting it would put a number in the
     * trial balance that no statement has a line for, and somebody would
     * reconcile it.
     *
     * So it is read, by the credit check and by whatever budget report wants
     * it, and a check asserts no journal entry ever names an order.
     *
     * @return array{committed: string, by_party: array<string, string>}
     */
    public function commitment(string $entityId, ?string $currency = null): array
    {
        $orders = SalesOrder::query()
            ->where('entity_id', $entityId)
            ->where('status', OrderState::Confirmed->value)
            ->when($currency !== null, fn ($q) => $q->where('currency', $currency))
            ->with('lines')
            ->get();

        $byParty = [];
        $total = null;

        foreach ($orders as $order) {
            $remaining = $order->uninvoicedNet();
            $total = $total === null ? $remaining : $total->plus($remaining);

            $party = (string) $order->party_id;
            $byParty[$party] = isset($byParty[$party])
                ? Money::of($byParty[$party], (string) $order->currency)->plus($remaining)->amount
                : $remaining->amount;
        }

        return [
            'committed' => (string) ($total?->amount ?? '0.0000'),
            'by_party' => array_map('strval', $byParty),
        ];
    }

    /** @throws OrderRefused */
    private function assertMayBecome(SalesOrder $order, OrderState $to): void
    {
        if (! \in_array($to, $order->status->mayBecome(), true)) {
            throw OrderRefused::theTransition(
                (string) $order->reference,
                $order->status,
                $to,
            );
        }
    }
}
