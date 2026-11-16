<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Exceptions\CreditRefused;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Models\InvoiceReference;
use App\Tenant\Sales\Models\InvoiceTaxBreakdownRow;
use App\Tenant\Tax\Services\TaxCalculator;
use App\Tenant\Tax\Services\TaxRounding;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\TaxBreakdown;
use App\Tenant\Tax\TaxBreakdownEntry;
use App\Tenant\Tax\TaxableLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Drafting a credit note against an issued invoice - PF-097.
 *
 * ## Line-selectable, and amount-only
 *
 * Both exist because both happen. Goods come back, which credits lines; and a
 * dispute is settled for a round figure, which credits an amount. A system
 * that offers only the first makes people invent a line called "adjustment"
 * with no tax treatment, and a system that offers only the second loses the
 * link between what came back and what was charged for it.
 *
 * A line-selected credit carries `credits_line_id`, so the original can show
 * which of its lines have been credited and by how much. An amount-only
 * credit carries the invoice's own tax treatment, apportioned across its rate
 * groups - because a 1,000 settlement on an invoice with 15% and 5% lines is
 * not a 15% credit, and choosing one rate for the whole thing would misstate
 * both boxes of the return.
 *
 * ## What this class does NOT do
 *
 * Issue. The draft goes through `Issuer` like any other document, which is
 * what gives a credit note its number from its own series, its own hash
 * chain, its posting, its audit record and the duty check that refuses the
 * person who issued the invoice. A crediting path with its own issuing logic
 * would be a second implementation of all of that, and the second
 * implementation is the one that drifts.
 */
final class Crediting
{
    public function __construct(
        private readonly TaxCalculator $calculator,
        private readonly TaxRounding $rounding,
        private readonly Snapshots $snapshots,
    ) {}

    /**
     * A full credit: every line, in full.
     *
     * @throws CreditRefused
     */
    public function full(Invoice $invoice, ?Carbon $date = null): Invoice
    {
        $selections = [];

        foreach ($invoice->lines()->orderBy('line_no')->get() as $line) {
            $selections[] = [
                'line_id' => (string) $line->getKey(),
                'net' => (string) $line->net_amount,
                'quantity' => (string) $line->quantity,
            ];
        }

        return $this->forLines($invoice, $selections, $date);
    }

    /**
     * A credit for specific lines, in whole or in part.
     *
     * @param  list<array{line_id: string, net: string, quantity?: string}>  $selections
     *
     * @throws CreditRefused
     */
    public function forLines(Invoice $invoice, array $selections, ?Carbon $date = null): Invoice
    {
        $this->assertCreditable($invoice);

        if ($selections === []) {
            throw CreditRefused::ofNothing();
        }

        $date ??= Carbon::now();

        return DB::connection('tenant')->transaction(function () use ($invoice, $selections, $date): Invoice {
            $lines = [];

            foreach ($selections as $index => $selection) {
                $original = $invoice->lines()
                    ->where('id', (string) $selection['line_id'])
                    ->first();

                if ($original === null) {
                    throw CreditRefused::aLineOfAnotherInvoice((string) $selection['line_id']);
                }

                $net = Money::of((string) $selection['net'], (string) $invoice->currency);

                if ($net->isZero() || $net->isNegative()) {
                    throw CreditRefused::aLineOfNothing((int) $original->line_no);
                }

                $remaining = $this->remainingOn($original, (string) $invoice->currency);

                if ($net->isGreaterThan($remaining)) {
                    throw CreditRefused::moreThanTheLine(
                        (int) $original->line_no,
                        (string) $remaining->amount,
                        (string) $net->amount,
                    );
                }

                $lines[] = [
                    'original' => $original,
                    'net' => $net,
                    'quantity' => $selection['quantity'] ?? $this->proportionalQuantity($original, $net),
                    'line_no' => $index + 1,
                ];
            }

            return $this->write($invoice, $lines, $date);
        });
    }

    /**
     * A credit for an amount, apportioned across the invoice's rate groups.
     *
     * ## The agreed figure is the GROSS, so the arithmetic runs backwards
     *
     * A settlement is negotiated as "1,000 off invoice 41", tax included.
     * Computing the net from a rate and then the tax from the net gives a
     * gross that is a minor unit or two away from the figure both parties
     * agreed - measured here: a 1,000.00 credit came out at 999.9952 and
     * left 150.0048 creditable on a 1,150.00 invoice, which is a residue
     * nobody can explain and nobody can clear.
     *
     * So the gross is allocated across the lines, each line's net is backed
     * out of its own share at its own rate, and each rate group's tax is the
     * remainder. The totals then add to the figure that was asked for by
     * construction. This is ordinary tax-inclusive arithmetic and the reason
     * the tax may differ by a minor unit from `net x rate`: the net is the
     * derived quantity here, not the given one.
     *
     * A line credit works the other way round, because there the net is what
     * was agreed - "credit these two lines" - and the tax follows the
     * jurisdiction's rounding policy.
     *
     * @throws CreditRefused
     */
    public function forAnAmount(Invoice $invoice, string $gross, ?Carbon $date = null): Invoice
    {
        $this->assertCreditable($invoice);

        $currency = (string) $invoice->currency;
        $asked = Money::of($gross, $currency);
        $room = $this->remainingOn($invoice, $currency);

        if ($asked->isZero() || $asked->isNegative()) {
            throw CreditRefused::ofNothing();
        }

        if ($asked->isGreaterThan($room)) {
            throw CreditRefused::moreThanTheInvoice(
                (string) $invoice->number,
                (string) $room->amount,
                (string) $asked->amount,
            );
        }

        $date ??= Carbon::now();
        $originals = $invoice->lines()->orderBy('line_no')->get();
        $weights = [];

        foreach ($originals as $line) {
            // Each line's own GROSS is its weight, because a 15% line carries
            // more of a tax-inclusive settlement than a 5% line of the same
            // net does.
            $weights[] = (string) $this->grossOf($line, $currency)->amount;
        }

        $shares = $asked->allocate($weights, RoundingPolicy::halfUp(Scale::Amount));

        return DB::connection('tenant')->transaction(function () use ($invoice, $originals, $shares, $date, $currency): Invoice {
            $lines = [];

            foreach ($originals as $index => $line) {
                $lineGross = $shares[$index];

                if ($lineGross->isZero()) {
                    continue;
                }

                $net = $lineGross->dividedBy(
                    $this->taxFactor((string) $line->tax_rate),
                    RoundingPolicy::halfUp(Scale::Amount),
                );

                $lines[] = [
                    'original' => $line,
                    'net' => $net,
                    'gross' => $lineGross,
                    'quantity' => $this->proportionalQuantity($line, $net),
                    'line_no' => \count($lines) + 1,
                ];
            }

            if ($lines === []) {
                throw CreditRefused::ofNothing();
            }

            return $this->write($invoice, $lines, $date, $this->inclusiveBreakdown($lines, $currency));
        });
    }

    /**
     * Everything still creditable on an invoice, as money.
     *
     * Public because the sales screens need it to offer a default, and
     * because it is the figure the database trigger enforces: a service that
     * computed it differently from the trigger would offer an amount that is
     * then refused.
     */
    public function remainingOn(Invoice|InvoiceLine $on, string $currency): Money
    {
        if ($on instanceof InvoiceLine) {
            $credited = InvoiceLine::query()
                ->where('credits_line_id', (string) $on->getKey())
                ->whereHas('invoice', fn ($q) => $q->where('status', 'issued'))
                ->sum('net_amount');

            return Money::of((string) $on->net_amount, $currency)
                ->minus(Money::of((string) $credited, $currency));
        }

        $credited = Invoice::query()
            ->where('status', 'issued')
            ->whereHas('references', fn ($q) => $q
                ->where('kind', 'credits')
                ->where('referenced_invoice_id', (string) $on->getKey()))
            ->sum('gross_amount');

        return Money::of((string) $on->gross_amount, $currency)
            ->minus(Money::of((string) $credited, $currency));
    }

    /**
     * The credited document's payment terms, resolved for the credit note.
     *
     * Null when the original carried none - a document with no terms cannot
     * lend any, and inventing "30 days" here would put a date on a credit
     * note that nobody agreed to.
     */
    private function termsFor(Invoice $invoice, Carbon $date, Money $gross): ?array
    {
        $snapshot = (array) ($invoice->terms_snapshot ?? []);
        $termId = $snapshot['payment_term_id'] ?? null;

        if (! \is_string($termId) || $termId === '') {
            return null;
        }

        $term = \App\Tenant\Catalog\Models\PaymentTerm::query()->find($termId);
        $entity = \App\Tenant\Tenancy\Models\Entity::query()->find((string) $invoice->entity_id);

        if ($term === null || $entity === null) {
            return null;
        }

        return $this->snapshots->terms($term, $entity, $date, $gross);
    }

    /**
     * Write the draft, its lines, its breakdown and its reference.
     *
     * @param  list<array{original: InvoiceLine, net: Money, quantity: string, line_no: int, gross?: Money}>  $lines
     * @param  TaxBreakdown|null  $breakdown  given by the amount path, computed from the lines otherwise
     */
    private function write(Invoice $invoice, array $lines, Carbon $date, ?TaxBreakdown $breakdown = null): Invoice
    {
        $currency = (string) $invoice->currency;

        $breakdown ??= $this->calculator->compute(
            array_map(static fn (array $line): TaxableLine => TaxableLine::of(
                $line['net'],
                $line['original']->tax_category,
                (string) $line['original']->tax_rate,
            ), $lines),
            $this->rounding->policyFor((string) ($invoice->profile ?? 'en16931'), $currency),
        );

        $credit = Invoice::create([
            'entity_id' => $invoice->entity_id,
            'series_key' => DocumentType::CreditNote->seriesKey(),
            'document_type' => DocumentType::CreditNote->value,
            'status' => 'draft',
            'party_id' => $invoice->party_id,
            'issue_date' => $date->toDateString(),

            /*
             | The tax point is the CREDIT's, not the invoice's.
             |
             | A credit note issued in June against an April invoice belongs
             | in June's return - the supply did not un-happen in April, and
             | a return already filed is corrected by PF-087's procedure and
             | not by backdating a document into it.
             */
            'tax_point' => $date->toDateString(),
            'currency' => $currency,
            'fx_rate' => $invoice->fx_rate,
            'fx_source' => $invoice->fx_source,
            'fx_rate_date' => $invoice->fx_rate_date,
            'net_amount' => (string) $breakdown->net->amount,
            'tax_amount' => (string) $breakdown->tax->amount,
            'gross_amount' => (string) $breakdown->gross()->amount,
            'payable_amount' => (string) $breakdown->gross()->amount,
            'presentation_exponent' => $invoice->presentation_exponent,
            'profile' => $invoice->profile,

            /*
             | The credited document's terms, re-frozen on the credit's own
             | date and amount - PF-097, EN 16931 BR-CO-25.
             |
             | Not copied: a partial credit against a 10,000 invoice with
             | three instalments would otherwise state the invoice's
             | instalment amounts on a document for 500. Re-resolved from the
             | same payment term, so the figures are the credit's own.
             |
             | And not omitted. BR-CO-25 requires a due date or payment terms
             | wherever an amount is due, and a credit note's amount is due
             | in EN 16931 (the type code carries the direction, not the
             | sign). Measured: the semantic model refused the first credit
             | note built through this path for exactly that rule, which is
             | the recipient's own validator finding it one step earlier.
             */
            'terms_snapshot' => $this->termsFor($invoice, $date, $breakdown->gross()),
        ]);

        foreach ($lines as $line) {
            /** @var InvoiceLine $original */
            $original = $line['original'];

            InvoiceLine::create([
                'entity_id' => $invoice->entity_id,
                'invoice_id' => (string) $credit->getKey(),
                'line_no' => $line['line_no'],
                'item_id' => $original->item_id,
                'item_code' => $original->item_code,
                'description' => $original->description,
                'unit_code' => $original->unit_code,
                'quantity' => $line['quantity'],
                'unit_price' => $original->unit_price,
                'net_amount' => (string) $line['net']->amount,
                'revenue_account_id' => $original->revenue_account_id,
                'tax_code_id' => $original->tax_code_id,
                'tax_category' => $original->tax_category->value,
                'tax_rate' => $original->tax_rate,
                'tax_point' => $date->toDateString(),
                'place_of_supply' => $original->place_of_supply,
                'place_of_supply_rule' => $original->place_of_supply_rule,
                'credits_line_id' => (string) $original->getKey(),

                /*
                 | And the ORDER line the credited line invoiced - PF-090.
                 |
                 | Measured: without it, crediting an invoice left the order
                 | showing as fully invoiced, so a customer who asked for a
                 | corrected invoice would be told the order was finished and
                 | the re-issue would be refused as over-invoicing.
                 */
                'order_line_id' => $original->order_line_id,
                'dimension_1' => $original->dimension_1,
                'dimension_2' => $original->dimension_2,
                'dimension_3' => $original->dimension_3,
                'dimension_4' => $original->dimension_4,
            ]);
        }

        foreach ($breakdown->entries as $entry) {
            InvoiceTaxBreakdownRow::create([
                'entity_id' => $invoice->entity_id,
                'invoice_id' => (string) $credit->getKey(),
                'category' => $entry->category->value,
                'rate' => $entry->ratePercent,
                'taxable_amount' => (string) $entry->taxableAmount->amount,
                'tax_amount' => (string) $entry->taxAmount->amount,
                'exemption_reason_code' => $entry->reasonCode,
                'exemption_reason_text' => $entry->reasonText,
                'report_box' => $this->reportBoxOf($invoice, $entry->category->value),
            ]);
        }

        /*
         | The reference, EN 16931 BT-25.
         |
         | Written with the draft rather than at issue time, because the
         | database's over-credit cap reads it when the document is issued -
         | and because a credit note that does not yet know what it credits
         | is not a draft of anything.
         */
        InvoiceReference::create([
            'entity_id' => $invoice->entity_id,
            'invoice_id' => (string) $credit->getKey(),
            'kind' => 'credits',
            'referenced_invoice_id' => (string) $invoice->getKey(),
            'reference' => (string) $invoice->number,
            'reference_date' => $invoice->issue_date?->toDateString(),
        ]);

        return $credit->refresh();
    }

    /** @throws CreditRefused */
    private function assertCreditable(Invoice $invoice): void
    {
        if (! $invoice->isIssued()) {
            throw CreditRefused::aDraft();
        }

        if ($invoice->document_type->isCredit()) {
            throw CreditRefused::aCreditNote((string) $invoice->number);
        }
    }

    /**
     * The quantity a partial credit represents.
     *
     * Proportional to the net being credited, so a half-value credit of ten
     * units reads as five units rather than as ten units at half price -
     * which is what a warehouse receiving the goods back needs to see.
     */
    private function proportionalQuantity(InvoiceLine $original, Money $net): string
    {
        $lineNet = BigDecimal::of((string) $original->net_amount);

        if ($lineNet->isZero()) {
            return (string) $original->quantity;
        }

        return (string) BigDecimal::of((string) $original->quantity)
            ->multipliedBy(BigDecimal::of((string) $net->amount)->dividedBy($lineNet, 12, RoundingMode::HalfUp))
            ->toScale(8, RoundingMode::HalfUp);
    }

    /**
     * A rate group's tax as the remainder of its gross - the inclusive case.
     *
     * Built here rather than by `TaxCalculator` because the calculator's job
     * is the other direction: net and a rate in, tax out, under the
     * jurisdiction's rounding policy. Here the gross is the given quantity
     * and the tax is what is left of it, which is what makes the document
     * total the figure that was agreed.
     *
     * @param  list<array{original: InvoiceLine, net: Money, gross?: Money, quantity: string, line_no: int}>  $lines
     */
    private function inclusiveBreakdown(array $lines, string $currency): TaxBreakdown
    {
        /** @var array<string, array{category: TaxCategory, rate: string, net: Money, gross: Money}> $groups */
        $groups = [];

        $net = Money::zero($currency);
        $tax = Money::zero($currency);

        foreach ($lines as $line) {
            $category = $line['original']->tax_category;
            $rate = (string) $line['original']->tax_rate;
            $key = $category->value.'@'.$rate;

            $groups[$key] ??= [
                'category' => $category,
                'rate' => $rate,
                'net' => Money::zero($currency),
                'gross' => Money::zero($currency),
            ];

            $groups[$key]['net'] = $groups[$key]['net']->plus($line['net']);
            $groups[$key]['gross'] = $groups[$key]['gross']->plus($line['gross'] ?? $line['net']);
        }

        $entries = [];

        foreach ($groups as $group) {
            $groupTax = $group['gross']->minus($group['net']);

            $entries[] = new TaxBreakdownEntry(
                $group['category'],
                $group['rate'],
                $group['net'],
                $groupTax,
            );

            $net = $net->plus($group['net']);
            $tax = $tax->plus($groupTax);
        }

        return new TaxBreakdown($entries, $net, $tax);
    }

    /** A line's gross, from its own net and its own rate. */
    private function grossOf(InvoiceLine $line, string $currency): Money
    {
        return Money::of((string) $line->net_amount, $currency)->multipliedBy(
            $this->taxFactor((string) $line->tax_rate),
            RoundingPolicy::halfUp(Scale::Amount),
        );
    }

    /** `1 + rate/100`, at twelve decimals, in one place. */
    private function taxFactor(string $ratePercent): string
    {
        return (string) BigDecimal::one()->plus(
            BigDecimal::of($ratePercent)->dividedBy('100', 12, RoundingMode::HalfUp),
        );
    }

    /** The report box the invoice used for this category, so both sides agree. */
    private function reportBoxOf(Invoice $invoice, string $category): ?string
    {
        $box = $invoice->taxBreakdown()
            ->where('category', $category)
            ->value('report_box');

        return $box === null ? null : (string) $box;
    }
}
