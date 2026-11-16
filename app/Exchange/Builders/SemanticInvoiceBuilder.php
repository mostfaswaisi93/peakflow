<?php

declare(strict_types=1);

namespace App\Exchange\Builders;

use App\Exchange\Profiles\ProfileRegistry;
use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Semantics\SemanticNode;
use App\Support\Money\Money;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceAllowanceCharge;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Models\InvoiceTaxBreakdownRow;

/**
 * The stored document, read into EN 16931 business terms - PF-110.
 *
 * ## Every value comes from the row or from a frozen snapshot
 *
 * Not from the live party, not from today's tax rate, not from the item
 * master. A model built from live data serializes something other than what
 * was issued, and what was issued is the document with legal effect: a
 * customer renamed in March must not change the XML of January's invoice,
 * because the recipient has already archived the January one and the two
 * copies would no longer hash the same.
 *
 * This is also why the builder refuses a draft. A draft has no number, no
 * frozen supplier and no hash, and serializing one produces a document that
 * looks issuable and is not.
 *
 * ## Amounts are emitted at the currency's own exponent
 *
 * Stored at `numeric(21,4)` so a 3-decimal dinar and a 0-decimal yen share
 * one column, and emitted at 2 for SAR, 3 for JOD and 0 for JPY - because
 * `1150.0000` in an XML amount is not wrong but is not what the recipient's
 * schema says, and `BR-DEC-*` rejects an amount with more decimals than the
 * currency has.
 */
final class SemanticInvoiceBuilder
{
    public function build(Invoice $invoice): SemanticInvoice
    {
        if (! $invoice->isIssued()) {
            throw new \LogicException(sprintf(
                'Invoice %s is a %s. A semantic model is built from the stored document, because what '.
                'is serialized has to be what was issued.',
                (string) ($invoice->number ?? $invoice->getKey()),
                (string) $invoice->status,
            ));
        }

        $profile = (string) ($invoice->profile ?? config('jurisdictions.default', 'generic'));
        $root = SemanticNode::root();

        $this->documentLevel($root, $invoice, $profile);
        $this->processControl($root, $profile);
        $this->references($root, $invoice);
        $this->seller($root, $invoice);
        $this->buyer($root, $invoice);
        $this->delivery($root, $invoice);
        $this->paymentInstructions($root, $invoice);
        $this->documentAllowancesAndCharges($root, $invoice);
        $this->totals($root, $invoice);
        $this->vatBreakdown($root, $invoice);
        $this->lines($root, $invoice);

        $model = SemanticInvoice::build($root, $profile, (string) $invoice->document_type->value);

        /*
         | The values a national regime needs and EN 16931 does not model.
         |
         | Read from the stored row like everything else, and only the keys
         | `SemanticInvoice::jurisdictionKeys()` declares - which is what
         | keeps this from becoming the bag that ends the model.
         */
        return $model
            ->withJurisdictionData('chain.previous_hash', $invoice->previous_hash)
            ->withJurisdictionData('chain.index', $invoice->chain_index)
            ->withJurisdictionData('document.uuid', (string) $invoice->getKey())
            ->withJurisdictionData('document.issued_at', $invoice->issued_at?->toIso8601String())
            ->withJurisdictionData('document.simplified', $invoice->simplified === true ? '1' : null);
    }

    private function documentLevel(SemanticNode $root, Invoice $invoice, string $profile): void
    {
        $root->set(BT::InvoiceNumber, $invoice->number)
            ->set(BT::IssueDate, $invoice->issue_date?->toDateString())
            /*
             | BT-3 as the PROFILE names it.
             |
             | The internal type is 380 and the Saudi regime's tax invoice is
             | 388. Asking the profile means the document says what its
             | recipient's code list says, and a tenant under two profiles
             | issues one invoice that reads correctly to both.
             */
            ->set(BT::InvoiceTypeCode, app(ProfileRegistry::class)
                ->forDocument($profile)
                ->documentTypeCode((string) $invoice->document_type->value))
            ->set(BT::InvoiceCurrencyCode, $invoice->currency);

        /*
         | BT-7, the tax point - and never BT-8 beside it.
         |
         | BR-CO-3 makes them mutually exclusive, and the profile decides
         | which is used. Every profile built so far states the date, because
         | a code ("the date of the supply") moves the decision to the
         | reader and the tax period the transaction lands in should not
         | depend on who opened the file.
         */
        if ($invoice->tax_point !== null && $invoice->tax_point->toDateString() !== $invoice->issue_date?->toDateString()) {
            $root->set(BT::TaxPointDate, $invoice->tax_point->toDateString());
        }

        $terms = (array) ($invoice->terms_snapshot ?? []);
        $dueDates = \is_array($terms['due_dates'] ?? null) ? $terms['due_dates'] : [];

        /*
         | BT-9 is the LAST instalment's date.
         |
         | A document with four instalments has one payment due date in
         | EN 16931, and the schedule lives in BT-20. Emitting the first
         | date would tell the recipient's system the whole amount is due
         | then, which is the figure their ageing report would use.
         */
        if ($dueDates !== []) {
            $last = end($dueDates);
            $root->set(BT::PaymentDueDate, \is_array($last) ? ($last['date'] ?? null) : null);
        }

        $root->set(BT::PaymentTerms, $this->renderTerms($terms, $dueDates));

        if ($invoice->note !== null) {
            $note = SemanticNode::of(BG::InvoiceNote);
            $note->set(BT::InvoiceNote, (string) $invoice->note);
            $root->nest($note);
        }

        foreach ($this->legalNotes($invoice, $profile) as $text) {
            $note = SemanticNode::of(BG::InvoiceNote);
            $note->set(BT::InvoiceNote, $text);
            $root->nest($note);
        }
    }

    private function processControl(SemanticNode $root, string $profile): void
    {
        $control = SemanticNode::of(BG::ProcessControl);

        $control->set(
            BT::SpecificationIdentifier,
            (string) config(
                'jurisdictions.customization.'.$profile,
                'urn:cen.eu:en16931:2017',
            ),
        );

        $process = config('jurisdictions.business_process.'.$profile);

        if (\is_string($process) && $process !== '') {
            $control->set(BT::BusinessProcessType, $process);
        }

        $root->nest($control);
    }

    private function references(SemanticNode $root, Invoice $invoice): void
    {
        foreach ($invoice->references()->get() as $reference) {
            $kind = (string) $reference->kind;

            if ($kind === 'credits' || $kind === 'corrects' || $kind === 'replaces') {
                $preceding = SemanticNode::of(BG::PrecedingInvoiceReference);
                $preceding->set(BT::PrecedingInvoiceReference, $reference->reference)
                    ->set(BT::PrecedingInvoiceIssueDate, $reference->reference_date?->toDateString());
                $root->nest($preceding);

                continue;
            }

            if ($kind === 'order') {
                $root->set(BT::PurchaseOrderReference, $reference->reference);
            }

            if ($kind === 'contract') {
                $root->set(BT::ContractReference, $reference->reference);
            }

            if ($kind === 'project') {
                $root->set(BT::ProjectReference, $reference->reference);
            }
        }
    }

    private function seller(SemanticNode $root, Invoice $invoice): void
    {
        $snapshot = (array) ($invoice->supplier_snapshot ?? []);
        $seller = SemanticNode::of(BG::Seller);

        $seller->set(BT::SellerName, $snapshot['legal_name'] ?? null)
            ->set(BT::SellerTradingName, $snapshot['trading_name'] ?? null)
            ->set(BT::SellerLegalRegistrationIdentifier, $snapshot['registration_number'] ?? null);

        $registration = (array) ($snapshot['registration'] ?? []);

        if (($registration['scheme'] ?? null) === 'VAT') {
            $seller->set(BT::SellerVatIdentifier, $this->vatIdentifier($registration));
        } elseif (($registration['identifier'] ?? null) !== null) {
            $seller->set(BT::SellerTaxRegistrationIdentifier, $registration['identifier']);
        }

        /*
         | BT-34 with its EAS scheme - PF-120.
         |
         | The participant identifier the Peppol network resolves to an
         | access point. `PEPPOL-EN16931-R020` refuses a document without
         | one, and `BR-62` refuses one with no scheme: `0088` and `9930` are
         | both electronic addresses and mean entirely different registries.
         */
        if (($registration['eas_code'] ?? null) !== null && ($registration['identifier'] ?? null) !== null) {
            $seller->set(BT::SellerElectronicAddress, $registration['identifier'])
                ->qualify(BT::SellerElectronicAddress, (string) $registration['eas_code']);
        }

        $seller->nest($this->address(
            BG::SellerPostalAddress,
            (array) ($snapshot['address'] ?? []),
            (string) ($snapshot['country'] ?? ''),
            [
                BT::SellerAddressLine1, BT::SellerAddressLine2, BT::SellerCity,
                BT::SellerPostCode, BT::SellerCountrySubdivision, BT::SellerCountryCode,
            ],
        ));

        $root->nest($seller);
    }

    private function buyer(SemanticNode $root, Invoice $invoice): void
    {
        $snapshot = (array) ($invoice->customer_snapshot ?? []);
        $buyer = SemanticNode::of(BG::Buyer);

        $buyer->set(BT::BuyerName, $snapshot['legal_name'] ?? null)
            ->set(BT::BuyerTradingName, $snapshot['trading_name'] ?? null)
            ->set(BT::BuyerIdentifier, $snapshot['code'] ?? null)
            ->set(BT::BuyerLegalRegistrationIdentifier, $snapshot['registration_number'] ?? null);

        $registration = (array) ($snapshot['registration'] ?? []);

        if (($registration['scheme'] ?? null) === 'VAT') {
            $buyer->set(BT::BuyerVatIdentifier, $this->vatIdentifier($registration));
        }

        /*
         | BT-49 with its EAS scheme, which is what routing needs.
         |
         | Without the scheme the address is unroutable: `0088` and `9930`
         | are both electronic addresses and mean entirely different
         | registries, and BR-63 rejects a bare one.
         */
        if (($registration['eas_code'] ?? null) !== null && ($registration['identifier'] ?? null) !== null) {
            $buyer->set(BT::BuyerElectronicAddress, $registration['identifier'])
                ->qualify(BT::BuyerElectronicAddress, (string) $registration['eas_code']);
        }

        $buyer->nest($this->address(
            BG::BuyerPostalAddress,
            (array) ($snapshot['address'] ?? []),
            (string) ($snapshot['country'] ?? ''),
            [
                BT::BuyerAddressLine1, BT::BuyerAddressLine2, BT::BuyerCity,
                BT::BuyerPostCode, BT::BuyerCountrySubdivision, BT::BuyerCountryCode,
            ],
        ));

        $root->nest($buyer);
    }

    /**
     * BG-13 and BG-14, from the lines' own service periods.
     *
     * The invoicing period is the span the lines cover, which is also what
     * drives IFRS 15 recognition - one fact, read from one place.
     */
    private function delivery(SemanticNode $root, Invoice $invoice): void
    {
        $from = null;
        $until = null;

        foreach ($invoice->lines as $line) {
            if ($line->service_from !== null && ($from === null || $line->service_from->lt($from))) {
                $from = $line->service_from;
            }

            if ($line->service_until !== null && ($until === null || $line->service_until->gt($until))) {
                $until = $line->service_until;
            }
        }

        if ($from === null && $until === null) {
            return;
        }

        $period = SemanticNode::of(BG::InvoicingPeriod);
        $period->set(BT::InvoicingPeriodStartDate, $from?->toDateString())
            ->set(BT::InvoicingPeriodEndDate, $until?->toDateString());
        $root->nest($period);
    }

    private function paymentInstructions(SemanticNode $root, Invoice $invoice): void
    {
        $terms = (array) ($invoice->terms_snapshot ?? []);
        $means = (string) ($terms['payment_means'] ?? '30');

        $instructions = SemanticNode::of(BG::PaymentInstructions);
        $instructions->set(BT::PaymentMeansTypeCode, $means);

        /*
         | BT-83, the remittance reference - the document number.
         |
         | It is what the bank-reconciliation matcher looks for in pass 1,
         | and it is stable because it is the invoice number: a generated
         | reference that is not on the document is a reference the customer
         | cannot quote.
         */
        $instructions->set(BT::RemittanceInformation, $invoice->number);

        $root->nest($instructions);
    }

    private function documentAllowancesAndCharges(SemanticNode $root, Invoice $invoice): void
    {
        $rows = InvoiceAllowanceCharge::query()
            ->where('invoice_id', (string) $invoice->getKey())
            ->whereNull('invoice_line_id')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if ((bool) $row->is_charge) {
                $node = SemanticNode::of(BG::DocumentLevelCharges);
                $node->set(BT::DocumentChargeAmount, $this->amount($row->amount, $invoice))
                    ->set(BT::DocumentChargePercentage, $row->percentage)
                    ->set(BT::DocumentChargeVatCategoryCode, $row->tax_category)
                    ->set(BT::DocumentChargeVatRate, $row->tax_rate)
                    ->set(BT::DocumentChargeReason, $row->reason)
                    ->set(BT::DocumentChargeReasonCode, $row->reason_code);
                $root->nest($node);

                continue;
            }

            $node = SemanticNode::of(BG::DocumentLevelAllowances);
            $node->set(BT::DocumentAllowanceAmount, $this->amount($row->amount, $invoice))
                ->set(BT::DocumentAllowancePercentage, $row->percentage)
                ->set(BT::DocumentAllowanceVatCategoryCode, $row->tax_category)
                ->set(BT::DocumentAllowanceVatRate, $row->tax_rate)
                ->set(BT::DocumentAllowanceReason, $row->reason)
                ->set(BT::DocumentAllowanceReasonCode, $row->reason_code);
            $root->nest($node);
        }
    }

    private function totals(SemanticNode $root, Invoice $invoice): void
    {
        $lineNet = '0';

        foreach ($invoice->lines as $line) {
            $lineNet = (string) Money::of($lineNet, $invoice->currency)
                ->plus(Money::of((string) $line->net_amount, $invoice->currency))->amount;
        }

        $allowances = '0';
        $charges = '0';

        foreach ($root->children(BG::DocumentLevelAllowances) as $node) {
            $allowances = (string) Money::of($allowances, $invoice->currency)
                ->plus(Money::of((string) $node->get(BT::DocumentAllowanceAmount), $invoice->currency))->amount;
        }

        foreach ($root->children(BG::DocumentLevelCharges) as $node) {
            $charges = (string) Money::of($charges, $invoice->currency)
                ->plus(Money::of((string) $node->get(BT::DocumentChargeAmount), $invoice->currency))->amount;
        }

        $totals = SemanticNode::of(BG::DocumentTotals);

        $totals->set(BT::SumOfLineNetAmounts, $this->amount($lineNet, $invoice))
            ->set(BT::TotalWithoutVat, $this->amount($invoice->net_amount, $invoice))
            ->set(BT::TotalVatAmount, $this->amount($invoice->tax_amount, $invoice))
            ->set(BT::TotalWithVat, $this->amount($invoice->gross_amount, $invoice))
            ->set(BT::AmountDueForPayment, $this->amount($invoice->payable_amount, $invoice));

        /*
         | BT-107 and BT-108 are emitted only when there is one.
         |
         | An explicit zero is permitted and is noise: BR-CO-13 subtracts an
         | absent BT-107 as zero, and a recipient reading "allowances: 0.00"
         | on every invoice learns to ignore the field.
         */
        if (Money::of($allowances, $invoice->currency)->isPositive()) {
            $totals->set(BT::SumOfAllowances, $this->amount($allowances, $invoice));
        }

        if (Money::of($charges, $invoice->currency)->isPositive()) {
            $totals->set(BT::SumOfCharges, $this->amount($charges, $invoice));
        }

        if (Money::of((string) $invoice->prepaid_amount, $invoice->currency)->isPositive()) {
            $totals->set(BT::PaidAmount, $this->amount($invoice->prepaid_amount, $invoice));
        }

        $root->nest($totals);
    }

    private function vatBreakdown(SemanticNode $root, Invoice $invoice): void
    {
        $rows = InvoiceTaxBreakdownRow::query()
            ->where('invoice_id', (string) $invoice->getKey())
            ->orderBy('category')
            ->orderBy('rate')
            ->get();

        foreach ($rows as $row) {
            $group = SemanticNode::of(BG::VatBreakdown);

            $group->set(BT::VatCategoryTaxableAmount, $this->amount($row->taxable_amount, $invoice))
                ->set(BT::VatCategoryTaxAmount, $this->amount($row->tax_amount, $invoice))
                ->set(BT::VatCategoryCode, $row->category)
                ->set(BT::VatCategoryRate, $this->rate($row->rate))
                ->set(BT::VatExemptionReasonText, $row->exemption_reason_text)
                ->set(BT::VatExemptionReasonCode, $row->exemption_reason_code);

            $root->nest($group);
        }
    }

    private function lines(SemanticNode $root, Invoice $invoice): void
    {
        /** @var InvoiceLine $line */
        foreach ($invoice->lines as $line) {
            $node = SemanticNode::of(BG::InvoiceLine);

            $node->set(BT::LineIdentifier, (string) $line->line_no)
                ->set(BT::InvoicedQuantity, $this->quantity($line->quantity))
                ->set(BT::InvoicedQuantityUnitCode, $line->unit_code)
                ->set(BT::LineNetAmount, $this->amount($line->net_amount, $invoice));

            $node->qualify(BT::InvoicedQuantity, (string) $line->unit_code);

            if ($line->service_from !== null || $line->service_until !== null) {
                $period = SemanticNode::of(BG::InvoiceLinePeriod);
                $period->set(BT::LinePeriodStartDate, $line->service_from?->toDateString())
                    ->set(BT::LinePeriodEndDate, $line->service_until?->toDateString());
                $node->nest($period);
            }

            foreach ($this->lineAdjustments($line) as $adjustment) {
                $node->nest($adjustment);
            }

            $price = SemanticNode::of(BG::PriceDetails);
            $price->set(BT::ItemNetPrice, $this->price($line->unit_price));
            $node->nest($price);

            $vat = SemanticNode::of(BG::LineVatInformation);
            $vat->set(BT::LineVatCategoryCode, $line->tax_category)
                ->set(BT::LineVatRate, $this->rate($line->tax_rate));
            $node->nest($vat);

            $item = SemanticNode::of(BG::ItemInformation);
            $item->set(BT::ItemName, $line->description)
                ->set(BT::ItemSellerIdentifier, $line->item_code);
            $node->nest($item);

            $root->nest($node);
        }
    }

    /** @return list<SemanticNode> */
    private function lineAdjustments(InvoiceLine $line): array
    {
        $nodes = [];

        $rows = InvoiceAllowanceCharge::query()
            ->where('invoice_line_id', (string) $line->getKey())
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if ((bool) $row->is_charge) {
                $node = SemanticNode::of(BG::InvoiceLineCharges);
                $node->set(BT::LineChargeAmount, (string) $row->amount)
                    ->set(BT::LineChargePercentage, $row->percentage)
                    ->set(BT::LineChargeReason, $row->reason)
                    ->set(BT::LineChargeReasonCode, $row->reason_code);
                $nodes[] = $node;

                continue;
            }

            $node = SemanticNode::of(BG::InvoiceLineAllowances);
            $node->set(BT::LineAllowanceAmount, (string) $row->amount)
                ->set(BT::LineAllowancePercentage, $row->percentage)
                ->set(BT::LineAllowanceReason, $row->reason)
                ->set(BT::LineAllowanceReasonCode, $row->reason_code);
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * An address group, from a frozen address snapshot.
     *
     * @param  array{0: BT, 1: BT, 2: BT, 3: BT, 4: BT, 5: BT}  $terms
     */
    private function address(BG $group, array $address, string $fallbackCountry, array $terms): SemanticNode
    {
        [$line1, $line2, $city, $postCode, $subdivision, $country] = $terms;

        $node = SemanticNode::of($group);

        /*
         | Line 3 is folded into line 2, because EN 16931 has two.
         |
         | Dropping it would lose part of an address a courier uses; a third
         | element would be rejected by the schema. Folding keeps the
         | information in the document the recipient actually receives.
         */
        $second = trim(implode(', ', array_filter([
            $address['line_2'] ?? null,
            $address['line_3'] ?? null,
        ], static fn (?string $part): bool => $part !== null && trim($part) !== '')));

        $node->set($line1, $address['line_1'] ?? null)
            ->set($line2, $second === '' ? null : $second)
            ->set($city, $address['city'] ?? null)
            ->set($postCode, $address['postal_code'] ?? null)
            ->set($subdivision, $address['country_subdivision'] ?? null)
            ->set($country, $address['country'] ?? ($fallbackCountry === '' ? null : $fallbackCountry));

        return $node;
    }

    /** The payment terms as a sentence, in the document's own language. */
    private function renderTerms(array $terms, array $dueDates): ?string
    {
        $label = (string) ($terms['label_en'] ?? '');

        if ($label === '' && $dueDates === []) {
            return null;
        }

        if (\count($dueDates) <= 1) {
            return $label === '' ? null : $label;
        }

        $parts = [];

        foreach ($dueDates as $instalment) {
            if (! \is_array($instalment)) {
                continue;
            }

            $parts[] = sprintf(
                '%s: %s',
                (string) ($instalment['date'] ?? ''),
                (string) ($instalment['amount'] ?? ''),
            );
        }

        return trim($label.' - '.implode('; ', $parts));
    }

    /**
     * The legal notes a jurisdiction requires on the face of the document.
     *
     * @return list<string>
     */
    private function legalNotes(Invoice $invoice, string $profile): array
    {
        $decision = (array) ($invoice->tax_decision_snapshot ?? []);
        $notes = [];

        if (($decision['reverse_charge'] ?? false) === true) {
            $note = (array) config('jurisdictions.reverse_charge_note.'.$profile, []);
            $text = (string) ($note['en'] ?? '');

            if ($text !== '') {
                $notes[] = $text;
            }
        }

        return $notes;
    }

    /**
     * A VAT identifier in the form EN 16931 asks for - BT-31, BT-48, BR-CO-9.
     *
     * ## What was wrong before, measured
     *
     * The snapshot carries a `qualified` field, `SA:VAT:310000000000003`,
     * and the first version of this builder emitted it. It is an internal
     * reference - jurisdiction, scheme and number, so two registrations
     * under different schemes can be told apart - and it appears on no
     * document anywhere. A recipient reading
     * `<cbc:CompanyID>SA:VAT:310000000000003</cbc:CompanyID>` rejects it,
     * and `BR-CO-9` passed because the string does begin with `SA`.
     *
     * The rule is that the identifier carries the ISO 3166-1 alpha-2 code of
     * its issuing country, so the country of issue can be identified: an EU
     * number usually already does (`DE123456789`) and a Saudi one does not,
     * so the prefix is added only when it is absent.
     */
    private function vatIdentifier(array $registration): ?string
    {
        $identifier = trim((string) ($registration['identifier'] ?? ''));

        if ($identifier === '') {
            return null;
        }

        $country = strtoupper(trim((string) ($registration['jurisdiction'] ?? '')));

        if ($country === '' || str_starts_with(strtoupper($identifier), $country)) {
            return $identifier;
        }

        return $country.$identifier;
    }

    /** An amount at the currency's own exponent - EN 16931 BR-DEC-*. */
    private function amount(string|float|int|null $value, Invoice $invoice): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) Money::of((string) $value, $invoice->currency)->toPresentation();
    }

    /** A quantity with its trailing zeros trimmed, keeping at least one decimal. */
    private function quantity(string|float|int|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = rtrim(rtrim((string) $value, '0'), '.');

        return $text === '' ? '0' : $text;
    }

    /** A unit price keeps its eight decimals where it needs them. */
    private function price(string|float|int|null $value): ?string
    {
        return $this->quantity($value);
    }

    /** A rate as a percentage, trimmed: `15.000000` is `15`. */
    private function rate(string|float|int|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->quantity($value);
    }
}
