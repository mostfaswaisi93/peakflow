<?php

declare(strict_types=1);

namespace App\Exchange\Inbound;

use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Semantics\SemanticNode;
use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Purchasing\Services\Bills;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Services\TaxCodes;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A validated document, as a draft bill - PF-141.
 *
 * ## It lives in `Exchange`, which may depend on `Purchasing`
 *
 * Not the other way round. `deptrac.yaml` lets `Exchange` import
 * `Purchasing` and refuses the reverse, and that direction is the right one:
 * the purchasing module knows nothing about XML, Peppol or business terms,
 * and a `Purchasing` class that imported a semantic model would make the
 * payable side unusable without the exchange plane.
 *
 * ## Per-line tax is derived, and reconciled to the document's own breakdown
 *
 * EN 16931 has **no per-line tax amount**. A line carries its category and
 * its rate (BT-151, BT-152) and the tax lives in the VAT breakdown (BG-23)
 * per rate group. So each line's tax is computed from its own rate, and the
 * total of a group's computed lines is then reconciled to the amount the
 * document states for that group - any remainder landing on the group's
 * largest line.
 *
 * Without that reconciliation a bill built from a valid document would
 * disagree with it by a cent or two, and the disagreement would be found by
 * whoever reconciles the input tax to the return. With it, the bill's
 * breakdown is the supplier's breakdown.
 *
 * ## The expense account is ours and is never guessed
 *
 * EN 16931 has no term for it, because which account a purchase lands in is
 * our decision about our own chart. The supplier's configured default is
 * used where there is one; otherwise the line carries none and the draft
 * cannot post until a person codes it. Guessing from the item description
 * would be a guess, and PF-141 says nothing is guessed.
 */
final class BillFromDocument
{
    public function __construct(
        private readonly Bills $bills,
        private readonly InboundDocuments $documents,
        private readonly TaxCodes $taxCodes,
    ) {}

    /**
     * Convert a validated document into a draft bill.
     *
     * @throws InboundRefused
     */
    public function convert(
        InboundDocument $document,
        Entity $entity,
        SemanticInvoice $invoice,
        ?Carbon $postingDate = null,
        ?string $userId = null,
    ): Bill {
        $document = $document->refresh();

        if ($document->isConverted()) {
            throw InboundRefused::because(sprintf(
                'Document %s was already converted into a bill. A second bill from one delivery is the '.
                'duplicate-payment error arriving through a door the duplicate detector does not '.
                'watch, because both would carry the same supplier number.',
                $document->label(),
            ));
        }

        if (! $document->isReadyToConvert()) {
            throw InboundRefused::because(sprintf(
                'Document %s is %s%s. A document becomes a bill once it has passed every rule and '.
                'somebody knows who sent it.',
                $document->label(),
                (string) $document->status,
                $document->supplier_party_id === null ? ' and has no supplier' : '',
            ));
        }

        $party = Party::query()->findOrFail((string) $document->supplier_party_id);
        $currency = (string) ($invoice->get(BT::InvoiceCurrencyCode) ?? $document->currency);

        $documentDate = Carbon::parse((string) $invoice->get(BT::IssueDate));
        $taxPoint = $invoice->get(BT::TaxPointDate) === null
            ? $documentDate->copy()
            : Carbon::parse((string) $invoice->get(BT::TaxPointDate));

        /*
         | The posting date is OURS, and defaults to today rather than to
         | the supplier's date.
         |
         | A document that arrives on the 3rd of April for a supply in March
         | belongs to our April: back-dating it to the supplier's date would
         | post into a period that may be closed, and a system that quietly
         | reopened one to accept an inbound document would have no closed
         | periods at all.
         */
        $postingDate ??= Carbon::now();

        if ($taxPoint->gt($postingDate)) {
            $postingDate = $taxPoint->copy();
        }

        $type = (string) ($invoice->get(BT::InvoiceTypeCode) ?? '380');
        $lines = $this->lines($invoice, $currency, $party, $taxPoint);

        if ($lines === []) {
            throw InboundRefused::because(
                'The document carries no lines. A validated EN 16931 document always has at least one '.
                '(BR-16), so this is a reader that lost them rather than a supplier who omitted them.',
            );
        }

        return DB::connection('tenant')->transaction(function () use (
            $document, $entity, $party, $invoice, $currency,
            $documentDate, $taxPoint, $postingDate, $lines, $userId, $type,
        ): Bill {
            $bill = $this->bills->enter(
                entity: $entity,
                partyId: (string) $party->getKey(),
                supplierDocumentNumber: (string) $invoice->get(BT::InvoiceNumber),
                supplierDocumentDate: $documentDate,
                taxPoint: $taxPoint,
                postingDate: $postingDate,
                currency: $currency,
                lines: $lines,
                createdBy: $userId,
                /*
                 | The source says how it arrived.
                 |
                 | Which matters at approval: a bill somebody typed and a
                 | bill a network delivered carry different evidence, and
                 | "where did this come from" should not need a join to
                 | answer.
                 */
                source: $document->channel === 'peppol' ? 'peppol' : 'inbox',
                paymentTermId: $party->payment_term_id === null ? null : (string) $party->payment_term_id,
                note: sprintf(
                    'Received by %s on %s and matched to this supplier by %s.',
                    (string) $document->channel,
                    $document->received_at->toDateTimeString(),
                    (string) $document->match_method,
                ),
                documentType: $type,
                /*
                 | The preceding reference travels only on a credit note.
                 |
                 | `bills_credit_fields_belong_to_a_credit_note` refuses it
                 | on an invoice, and rightly: a corrected invoice (UNCL
                 | 1001 384) also carries BT-25, and putting it in the credit
                 | columns would make a replacement look like a credit
                 | against the document it replaces.
                 */
                creditReference: $type === '381'
                    ? $invoice->group(BG::PrecedingInvoiceReference)?->get(BT::PrecedingInvoiceReference)
                    : null,
            );

            $this->documents->markConverted($document, (string) $bill->getKey(), $userId);

            return $bill;
        });
    }

    /**
     * The bill's lines, with each one's tax derived and reconciled.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(
        SemanticInvoice $invoice,
        string $currency,
        Party $party,
        Carbon $taxPoint,
    ): array {
        $account = $party->default_expense_account_id === null
            ? null
            : (string) $party->default_expense_account_id;

        $lines = [];
        $byGroup = [];

        foreach ($invoice->lines() as $index => $line) {
            $net = Money::of((string) ($line->get(BT::LineNetAmount) ?? '0'), $currency);
            $vat = $line->first(BG::LineVatInformation);
            $category = (string) ($vat?->get(BT::LineVatCategoryCode) ?? 'S');
            $rate = (string) ($vat?->get(BT::LineVatRate) ?? '0');

            $tax = $net->multipliedBy(
                (string) BigDecimal::of($rate)->dividedBy('100', 12, RoundingMode::HalfUp),
                RoundingPolicy::halfUp(Scale::Amount),
            );

            $item = $line->first(BG::ItemInformation);
            $price = $line->first(BG::PriceDetails);

            $lines[] = [
                'line_no' => $index + 1,
                'description' => (string) (
                    $item?->get(BT::ItemName)
                    ?? $item?->get(BT::ItemDescription)
                    ?? $line->get(BT::LineNote)
                    ?? 'An unnamed line'
                ),
                'item_code' => $item?->get(BT::ItemSellerIdentifier),
                'unit_code' => (string) ($line->qualifier(BT::InvoicedQuantity)
                    ?? $line->get(BT::InvoicedQuantityUnitCode)
                    ?? 'C62'),
                'quantity' => (string) ($line->get(BT::InvoicedQuantity) ?? '1'),
                'unit_price' => (string) ($price?->get(BT::ItemNetPrice) ?? $net->amount),
                'net_amount' => (string) $net->amount,
                'expense_account_id' => $account,
                'tax_code_id' => $this->taxCodeFor($category, $rate, $taxPoint),
                'tax_category' => $category,
                'tax_rate' => $rate,
                'tax_amount' => (string) $tax->amount,
                /*
                 | The reverse charge is read from the CATEGORY, not guessed
                 | from the rate.
                 |
                 | `AE` is the UNCL 5305 code for "VAT reverse charge", and a
                 | zero rate on its own means nothing: `Z` is zero-rated, `E`
                 | is exempt, `O` is outside scope, and all three are also
                 | zero. Treating "rate is zero" as a reverse charge would
                 | self-assess tax on an exempt supply.
                 */
                'reverse_charge' => $category === 'AE',
            ];

            $key = $category.'|'.$rate;
            $byGroup[$key][] = \count($lines) - 1;
        }

        return $this->reconcileToBreakdown($invoice, $lines, $byGroup, $currency);
    }

    /**
     * Push the document's stated group tax onto its lines.
     *
     * The document is the authority: BT-117 is what the supplier charged for
     * a rate group, and the sum of per-line arithmetic can differ from it by
     * a cent where the supplier rounded per group and we rounded per line.
     * The difference lands on the group's largest line, because somewhere it
     * must - spreading it would put fractions into every line, and dropping
     * it would make our breakdown disagree with the document a person is
     * holding.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, list<int>>  $byGroup
     * @return list<array<string, mixed>>
     */
    private function reconcileToBreakdown(
        SemanticInvoice $invoice,
        array $lines,
        array $byGroup,
        string $currency,
    ): array {
        foreach ($invoice->vatBreakdown() as $group) {
            $category = (string) ($group->get(BT::VatCategoryCode) ?? '');
            $rate = (string) ($group->get(BT::VatCategoryRate) ?? '0');
            $stated = Money::of((string) ($group->get(BT::VatCategoryTaxAmount) ?? '0'), $currency);

            $indexes = $byGroup[$category.'|'.$rate] ?? [];

            if ($indexes === []) {
                continue;
            }

            $computed = Money::zero($currency);

            foreach ($indexes as $index) {
                $computed = $computed->plus(Money::of((string) $lines[$index]['tax_amount'], $currency));
            }

            $difference = $stated->minus($computed);

            if ($difference->isZero()) {
                continue;
            }

            $largest = $indexes[0];

            foreach ($indexes as $index) {
                if (BigDecimal::of((string) $lines[$index]['net_amount'])
                    ->isGreaterThan((string) $lines[$largest]['net_amount'])) {
                    $largest = $index;
                }
            }

            $lines[$largest]['tax_amount'] = (string) Money::of(
                (string) $lines[$largest]['tax_amount'],
                $currency,
            )->plus($difference)->amount;
        }

        return $lines;
    }

    /**
     * Our tax code for a category and rate, where one exists.
     *
     * Matched, never created. A code is a tenant's decision about reporting
     * boxes and evidence rules, and inventing one from an inbound document
     * would put a code in the chart that nobody chose and that no return
     * maps - which is found when the return is filed.
     *
     * Matched against the rate **in force on the tax point**, not against a
     * column: the rate lives in `tax_rates` as a dated range, because a code
     * is what a supply MEANS and a rate is what it was on a day. A document
     * from before a rate change has to find the code that carried the old
     * rate then, not the one carrying the new rate now.
     *
     * Null where nothing matches, and the draft is then a bill a person has
     * to code - `journal_lines` refuses a tax point with no code, so the
     * posting path cannot quietly accept it either.
     */
    private function taxCodeFor(string $category, string $rate, Carbon $taxPoint): ?string
    {
        $wanted = BigDecimal::of($rate);
        $enum = TaxCategory::tryFrom($category);

        if ($enum === null) {
            return null;
        }

        foreach ($this->taxCodes->availableOn($taxPoint) as $code) {
            if ($code->category !== $enum) {
                continue;
            }

            if ($this->taxCodes->rateOn((string) $code->code, $taxPoint)->isEqualTo($wanted)) {
                return (string) $code->getKey();
            }
        }

        return null;
    }
}
