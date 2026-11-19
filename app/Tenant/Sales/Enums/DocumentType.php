<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Enums;

/**
 * What kind of document this is - UNCL 1001, PF-091.
 *
 * ## Stored as the code, not as a name
 *
 * `380` rather than `commercial_invoice`, because the code is what goes on the
 * wire and what a receiving system validates. A name in the column means a
 * mapping at the boundary, and a mapping is a place for a document to acquire
 * the wrong type on the way out.
 *
 * ## Which types are legal is a jurisdiction decision, not a user choice
 *
 * A corrected invoice (`384`) is how some regimes require a mistake to be
 * fixed and is not permitted in others, where a credit note and a reissue is
 * the only route. PF-098 reads that from the profile; this enum knows the
 * shapes, not the permissions.
 */
enum DocumentType: string
{
    /** A commercial invoice. The ordinary case. */
    case Invoice = '380';

    /** A credit note - PF-097. Carries a mandatory reference to what it credits. */
    case CreditNote = '381';

    /** A debit note: an additional charge against an existing invoice - PF-098. */
    case DebitNote = '383';

    /**
     * A corrected invoice, replacing the original rather than adjusting it.
     *
     * Where a regime requires this, issuing a credit note instead leaves the
     * original in force; where a regime forbids it, issuing one replaces a
     * document the authority still holds.
     */
    case CorrectedInvoice = '384';

    /** A prepayment invoice - PF-099. Posts to a liability, never to revenue. */
    case PrepaymentInvoice = '386';

    /** Self-billed: the buyer issues on the supplier's behalf - PF-100. */
    case SelfBilledInvoice = '389';

    /** A partial invoice against an order - PF-090. */
    case PartialInvoice = '326';

    /** Does this type reduce what the customer owes rather than increase it? */
    public function isCredit(): bool
    {
        return $this === self::CreditNote;
    }

    /** Must this type reference another document? */
    public function requiresAReference(): bool
    {
        return match ($this) {
            self::CreditNote, self::DebitNote, self::CorrectedInvoice => true,
            default => false,
        };
    }

    /**
     * Does this type post to revenue?
     *
     * A prepayment does not: cash received before performance is a liability,
     * not revenue, and posting it to revenue overstates income and the tax
     * base at once - IFRS 15.
     */
    public function postsToRevenue(): bool
    {
        return match ($this) {
            self::PrepaymentInvoice => false,
            default => true,
        };
    }

    /** A self-billed document posts as a purchase, which is easy to get backwards. */
    public function isPurchaseSide(): bool
    {
        return $this === self::SelfBilledInvoice;
    }

    /**
     * Does this type leave the customer owing money?
     *
     * ## One definition, because there were three copies of a stale list
     *
     * `OverviewQuery::receivables()`, `ExpectedCreditLoss::openReceivables()`
     * and `Banking\Matching\OpenDocuments` each hard-coded
     * `IN ('380', '383', '386')`. All three were written before PF-090 added
     * the partial invoice and before the corrected invoice existed, and none
     * of them learned about either.
     *
     * The consequences were not the same in the three places and the third
     * is the worst. A partial invoice was invisible to the Overview tile, so
     * the figure on the first screen anybody opens understated what was
     * owed. It carried no IFRS 9 loss allowance at all, because it was not
     * in the provision base. And it could not be MATCHED to a bank line,
     * because it was not a candidate - so a customer's payment against a
     * partial invoice had nothing to match and landed in the unexplained
     * column of a reconciliation.
     *
     * ## What it excludes, and why each is a decision rather than an omission
     *
     * A credit note is excluded because it REDUCES what is owed rather than
     * creating it, and one that references an invoice is already subtracted
     * by `peakflow_document_open_balance`. A standalone credit is a credit
     * balance on the account: `AgingReport` includes it deliberately, as a
     * negative bucket, which is a different and broader question from this
     * one.
     *
     * A self-billed invoice is excluded because it posts as a purchase.
     *
     * A prepayment invoice is INCLUDED. It does not post to revenue - cash
     * before performance is a liability under IFRS 15 - but the customer
     * still owes it, and what is owed is what this asks.
     *
     * Written as a match over every case with no `default`, so adding a
     * type to this enum will not compile until somebody has decided which
     * side of this question it falls on. A `default` is how the three lists
     * above went stale.
     */
    public function createsAReceivable(): bool
    {
        return match ($this) {
            self::Invoice,
            self::DebitNote,
            self::CorrectedInvoice,
            self::PrepaymentInvoice,
            self::PartialInvoice => true,
            self::CreditNote,
            self::SelfBilledInvoice => false,
        };
    }

    /**
     * The codes of every type that leaves the customer owing money.
     *
     * For a SQL `IN` list, so the three queries that ask this question share
     * the one answer rather than each carrying a literal.
     *
     * @return list<string>
     */
    public static function receivableCodes(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type->createsAReceivable()),
        ));
    }

    public function label(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::Invoice => $arabic ? 'فاتورة' : 'invoice',
            self::CreditNote => $arabic ? 'إشعار دائن' : 'credit note',
            self::DebitNote => $arabic ? 'إشعار مدين' : 'debit note',
            self::CorrectedInvoice => $arabic ? 'فاتورة مصححة' : 'corrected invoice',
            self::PrepaymentInvoice => $arabic ? 'فاتورة دفعة مقدمة' : 'prepayment invoice',
            self::SelfBilledInvoice => $arabic ? 'فاتورة ذاتية' : 'self-billed invoice',
            self::PartialInvoice => $arabic ? 'فاتورة جزئية' : 'partial invoice',
        };
    }

    /**
     * The permission key issuing this type exercises - PF-033.
     *
     * Distinct per type because the segregation rules are: whoever issued an
     * invoice must not credit it, which is only a rule if the two acts are
     * two duties.
     */
    public function duty(): string
    {
        return match ($this) {
            self::CreditNote => 'credit_note.issue',
            self::DebitNote => 'debit_note.issue',
            self::CorrectedInvoice => 'corrected_invoice.issue',
            default => 'invoice.issue',
        };
    }

    /**
     * How the audit trail records issuing this type.
     *
     * In the past tense, because the audit trail says what happened and is
     * read by people. `config/sod.php` maps the duty above to this, and a
     * check fails the build if a duty maps to an action nothing writes - the
     * engine used to match permission keys against this column and found
     * nothing, which made every object-scoped control unable to fire.
     */
    public function auditAction(): string
    {
        return match ($this) {
            self::CreditNote => 'credit_note.issued',
            self::DebitNote => 'debit_note.issued',
            self::CorrectedInvoice => 'corrected_invoice.issued',
            self::PrepaymentInvoice => 'prepayment_invoice.issued',
            self::SelfBilledInvoice => 'self_billed_invoice.issued',
            default => 'invoice.issued',
        };
    }

    /**
     * The posting rule that posts this type.
     *
     * A credit note is NOT the invoice rule with negated amounts: it is its
     * own rule, because "reverse the original posting" and "post the mirror
     * of these lines" differ the moment a partial credit exists, and
     * negating rows produces an entry that reads as a correction to the
     * original rather than as a document of its own.
     */
    public function postingRuleKey(): string
    {
        return match ($this) {
            self::CreditNote => 'sales.credit_note_issued',
            self::CorrectedInvoice => 'sales.corrected_invoice_issued',
            self::PrepaymentInvoice => 'sales.prepayment_invoice_issued',
            self::SelfBilledInvoice => 'sales.self_billed_invoice_issued',
            default => 'sales.invoice_issued',
        };
    }

    /** The reference kind this type must carry - EN 16931 BT-25. */
    public function referenceKind(): ?string
    {
        return match ($this) {
            self::CreditNote => 'credits',
            self::DebitNote => 'charges',
            self::CorrectedInvoice => 'corrects',
            default => null,
        };
    }

    /** The numbering series a type draws from, so two types never share one. */
    public function seriesKey(): string
    {
        return match ($this) {
            self::Invoice, self::PartialInvoice => 'INV',
            self::CreditNote => 'CRN',
            self::DebitNote => 'DBN',
            self::CorrectedInvoice => 'COR',
            self::PrepaymentInvoice => 'PRE',
            self::SelfBilledInvoice => 'SLF',
        };
    }
}
