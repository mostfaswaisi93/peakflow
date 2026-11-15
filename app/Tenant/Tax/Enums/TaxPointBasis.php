<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Enums;

/**
 * How a jurisdiction decides a document's tax point - PF-077.
 *
 * ## Why this is an enum and the MAPPING is data
 *
 * The shapes a rule can take are finite and are a property of VAT regimes in
 * general: a tax point is the invoice date, the delivery date, the payment
 * date, or the earlier of a pair. Which shape a jurisdiction uses is a fact
 * about that jurisdiction, and it lives in `config/jurisdictions.php` where a
 * new country is a row.
 *
 * So the engine knows five shapes and nothing about any country.
 *
 * ## "The earlier of" is the one that surprises people
 *
 * Several regimes make the tax point the earlier of the invoice date and the
 * payment date, which means a prepayment received in March fixes the tax point
 * in March even though the invoice is raised in May. The supply belongs to
 * March's return. A system that uses the invoice date files it in May, and the
 * difference is found by the authority rather than by the business.
 *
 * @see EN 16931 BT-7 (value added tax point date)
 * @see EN 16931 BT-8 (value added tax point date code)
 */
enum TaxPointBasis: string
{
    /** The invoice date. The simplest rule and the most common default. */
    case InvoiceDate = 'invoice_date';

    /**
     * The date of the supply itself.
     *
     * Used where the regime taxes the supply rather than the paperwork, and
     * the reason a document issued on the 1st for a supply on the 30th of the
     * previous month belongs to the earlier return.
     */
    case DeliveryDate = 'delivery_date';

    /** The date the money arrived. Cash-basis regimes and some services. */
    case PaymentDate = 'payment_date';

    /** The earlier of the invoice date and the delivery date. */
    case EarlierOfInvoiceAndDelivery = 'earlier_of_invoice_and_delivery';

    /**
     * The earlier of the invoice date and the payment date.
     *
     * The prepayment rule: money received before invoicing fixes the tax
     * point at receipt.
     */
    case EarlierOfInvoiceAndPayment = 'earlier_of_invoice_and_payment';

    /**
     * Which dates this basis needs to produce an answer.
     *
     * Asked rather than assumed, because a basis whose input is missing must
     * REFUSE rather than fall back. A delivery-date rule applied to a document
     * with no delivery date and quietly answered with the invoice date is a
     * document filed in the wrong return period, and nothing says so.
     *
     * @return list<string>
     */
    public function requires(): array
    {
        return match ($this) {
            self::InvoiceDate => ['invoice'],
            self::DeliveryDate => ['delivery'],
            self::PaymentDate => ['payment'],
            self::EarlierOfInvoiceAndDelivery => ['invoice', 'delivery'],
            self::EarlierOfInvoiceAndPayment => ['invoice', 'payment'],
        };
    }

    /**
     * The EN 16931 `BT-8` code, where the basis maps onto one.
     *
     * UNCL 2005 has three values an invoice can carry: `3` for the invoice
     * date, `35` for the delivery date and `432` for the payment date. The
     * two "earlier of" bases have no code of their own, because the standard
     * carries the DATE and the code for what that date represents - so an
     * "earlier of" rule reports whichever of the pair actually won, which is
     * why the decision records it.
     */
    public function documentCode(): ?string
    {
        return match ($this) {
            self::InvoiceDate => '3',
            self::DeliveryDate => '35',
            self::PaymentDate => '432',
            self::EarlierOfInvoiceAndDelivery, self::EarlierOfInvoiceAndPayment => null,
        };
    }

    /** The bilingual explanation, for the invoice's tax panel. */
    public function explanation(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::InvoiceDate => $arabic ? 'تاريخ الفاتورة' : 'the invoice date',
            self::DeliveryDate => $arabic ? 'تاريخ التوريد' : 'the delivery date',
            self::PaymentDate => $arabic ? 'تاريخ السداد' : 'the payment date',
            self::EarlierOfInvoiceAndDelivery => $arabic
                ? 'الأسبق من تاريخ الفاتورة وتاريخ التوريد'
                : 'the earlier of the invoice date and the delivery date',
            self::EarlierOfInvoiceAndPayment => $arabic
                ? 'الأسبق من تاريخ الفاتورة وتاريخ السداد'
                : 'the earlier of the invoice date and the payment date',
        };
    }
}
