<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Models\InvoiceTaxBreakdownRow;
use App\Tenant\Tax\Services\TaxCalculator;
use App\Tenant\Tax\Services\TaxCodes;
use App\Tenant\Tax\Services\TaxRounding;
use App\Tenant\Tax\TaxableLine;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A sales invoice, drafted from what a person typed - PF-266.
 *
 * Until now every draft in this product came from another document - a quote,
 * a sales order, a recurring template, a correction - and each of those
 * builds its invoice by hand. This is the one that starts from nothing: a
 * customer, some lines, a tax code and a revenue account per line.
 *
 * ## The figures are computed here, never accepted
 *
 * A client sends quantities and unit prices. The line net, the tax and every
 * total are computed by {@see TaxCalculator} under the entity's rounding
 * policy - the same calculator the quote-to-invoice path uses - because an
 * invoice whose tax a client asserted is an invoice whose tax nobody checked.
 *
 * ## Each line's net is rounded to the currency, then held at four places
 *
 * EN 16931 states a line net amount in the currency's minor unit (BT-131),
 * so `2 × 12.345` is 24.69, not 24.6900 waiting to round somewhere later. A
 * line rounded late is a header that differs from the sum of its lines by a
 * fils, which {@see InvoiceValidator} refuses.
 *
 * ## A draft is a draft
 *
 * Nothing is numbered, posted or frozen here. {@see Issuer::issue()} does all
 * of that, and runs the validator on what this wrote - so a draft that is
 * wrong in a way this did not catch is still refused before it reaches the
 * ledger.
 */
final class InvoiceDrafts
{
    public function __construct(
        private readonly TaxCalculator $calculator,
        private readonly TaxRounding $rounding,
        private readonly TaxCodes $taxCodes,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  list<array{description: string, quantity: string, unit_price: string, unit_code: string, item_id: ?string, item_code: ?string, tax_code: string, revenue_account_id: string, supply_type?: ?string}>  $lines
     * @param  ?string  $deliveryCountry where the goods went - PF-078. Null defaults to the
     *                  customer's own country, ON THE DOCUMENT, where it is stored, shown and
     *                  editable. `SupplyContext` refuses to default it in code for a reason its
     *                  own docblock gives: a customer in one country taking delivery in another
     *                  is an export or an intra-bloc supply, and an invisible default would tax
     *                  it domestically and be right most of the time.
     *
     * @throws \App\Tenant\Tax\Exceptions\TaxCodeRefused for a tax code that is unknown or not in force on the issue date
     */
    public function create(
        Entity $entity,
        string $partyId,
        Carbon $issueDate,
        ?Carbon $deliveryDate,
        string $currency,
        array $lines,
        ?string $note,
        string $userId,
        ?string $deliveryCountry = null,
    ): Invoice {
        $exponent = self::exponentOf($currency);
        $taxPoint = $deliveryDate ?? $issueDate;
        $country = \is_string($entity->country) ? $entity->country : 'SA';

        /*
         | Every line resolved before anything is written: a tax code that is
         | not in force on the date refuses the whole draft, not the line after
         | the first three were saved.
         */
        $resolved = [];

        foreach ($lines as $line) {
            [$taxCode, $taxRate] = $this->taxCodes->resolve($line['tax_code'], $taxPoint);

            $net = BigDecimal::of($line['quantity'])
                ->multipliedBy($line['unit_price'])
                ->toScale(max(0, $exponent), RoundingMode::HalfUp)
                ->toScale(4);

            /*
             | The line's supply type, from its item where it has one - PF-078.
             |
             | `items.type` has carried it since PF-060 with a comment
             | saying it "drives place of supply", and nothing read it: the
             | rule book was never asked. Copied onto the line rather than
             | read from the item at decision time, because an item retyped
             | from `good` to `service` next year must not change what an
             | issued invoice says it was.
             */
            $resolved[] = $line + [
                'supply_type' => \App\Tenant\Sales\SupplyTypes::of(
                    \is_string($line['supply_type'] ?? null) ? (string) $line['supply_type'] : null,
                    \is_string($line['item_id'] ?? null) ? (string) $line['item_id'] : null,
                ),
                'net' => (string) $net,
                'tax_code_id' => \is_string($taxCodeId = $taxCode->getKey()) ? $taxCodeId : '',
                'category' => $taxCode->category,
                'rate' => (string) $taxRate->rate,
            ];
        }

        $breakdown = $this->calculator->compute(
            array_map(
                static fn (array $line): TaxableLine => TaxableLine::of(
                    Money::of($line['net'], $currency),
                    $line['category'],
                    $line['rate'],
                ),
                $resolved,
            ),
            $this->rounding->policyFor('generic', $currency),
        );

        return DB::connection('tenant')->transaction(function () use (
            $entity, $partyId, $issueDate, $deliveryDate, $taxPoint, $currency, $exponent, $country,
            $resolved, $breakdown, $note, $userId, $deliveryCountry,
        ): Invoice {
            $invoice = Invoice::create([
                'entity_id' => $entity->id,
                'series_key' => 'INV',
                'document_type' => '380',
                'status' => 'draft',
                'party_id' => $partyId,
                'issue_date' => $issueDate->toDateString(),
                'delivery_date' => $deliveryDate?->toDateString(),
                'tax_point' => $taxPoint->toDateString(),
                /*
                 | Defaulted here, on the document, and visible - PF-078.
                 |
                 | The customer's own country, which is right for the great
                 | majority of supplies and wrong for the ones that matter:
                 | a French customer collecting goods from a Saudi warehouse
                 | is a Saudi supply, and the highest-precedence rule in the
                 | book exists for exactly that. A default in the deciding
                 | code would be invisible; a default in a column somebody
                 | can read and change is a starting value.
                 */
                'delivery_country' => $deliveryCountry ?? DB::connection('tenant')->table('parties')
                    ->where('id', $partyId)
                    ->value('country'),
                'currency' => $currency,
                'net_amount' => (string) $breakdown->net->amount,
                'tax_amount' => (string) $breakdown->tax->amount,
                'gross_amount' => (string) $breakdown->gross()->amount,
                'payable_amount' => (string) $breakdown->gross()->amount,
                'presentation_exponent' => $exponent,
                'note' => $note,
                'created_by' => $userId,
            ]);

            foreach ($resolved as $index => $line) {
                InvoiceLine::create([
                    'entity_id' => $entity->id,
                    'invoice_id' => $invoice->id,
                    'line_no' => $index + 1,
                    'item_id' => $line['item_id'],
                    'item_code' => $line['item_code'],
                    'supply_type' => $line['supply_type'],
                    'description' => $line['description'],
                    'unit_code' => $line['unit_code'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'net_amount' => $line['net'],
                    'revenue_account_id' => $line['revenue_account_id'],
                    'tax_code_id' => $line['tax_code_id'],
                    'tax_category' => $line['category']->value,
                    'tax_rate' => $line['rate'],
                    'tax_point' => $taxPoint->toDateString(),
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
                ]);
            }

            foreach ($breakdown->entries as $entry) {
                InvoiceTaxBreakdownRow::create([
                    'entity_id' => $entity->id,
                    'invoice_id' => $invoice->id,
                    'category' => $entry->category->value,
                    'rate' => $entry->ratePercent,
                    'taxable_amount' => (string) $entry->taxableAmount->amount,
                    'tax_amount' => (string) $entry->taxAmount->amount,
                    'exemption_reason_code' => $entry->reasonCode,
                    'exemption_reason_text' => $entry->reasonText,
                    'report_box' => 'standard_sales',
                ]);
            }

            $this->audit->record(
                category: 'sales',
                action: 'invoice.drafted',
                subjectType: 'invoice',
                subjectId: $invoice->id,
                subjectLabel: 'draft',
                after: [
                    'customer' => $partyId,
                    'gross' => (string) $breakdown->gross()->amount,
                    'currency' => $currency,
                    'lines' => \count($resolved),
                ],
                reason: 'drafted from lines typed by a person; every figure was computed, none accepted',
            );

            /*
             | And where the supply is placed, now rather than at issue - PF-078.
             |
             | `Issuer` asks again when the document is issued, because a
             | registration can lapse and the rule book can change between
             | drafting and issuing, and what counts is the rule on the day.
             | Asked here as well so the draft can be VALIDATED: the
             | validator refuses a line whose place of supply is unresolved,
             | and a draft nobody has tried to place would fail that check
             | for a reason the person reading it cannot act on.
             */
            app(\App\Tenant\Sales\Services\SupplyDecider::class)->decide($invoice, $entity);

            return $invoice->refresh();
        });
    }

    /** The currency's minor unit, from ISO 4217 - SAR 2, JOD 3, JPY 0. */
    private static function exponentOf(string $currency): int
    {
        $wire = Money::of('0', $currency)->jsonSerialize();
        $exponent = $wire['presentation_exponent'] ?? 2;

        return \is_int($exponent) ? $exponent : 2;
    }
}
