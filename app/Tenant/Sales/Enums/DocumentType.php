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
