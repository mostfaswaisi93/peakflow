<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Enums;

/**
 * A control-account role - PF-038.
 *
 * A POSITION in the posting rules, not a name a customer chooses. A tenant may
 * call the account whatever they like; the role is what a posting rule
 * resolves, which is how the ledger posts a receivable without knowing what an
 * invoice is.
 *
 * Exactly one account per role per entity, enforced by a unique index. Two
 * receivable control accounts means the subledger reconciles to neither.
 */
enum ControlRole: string
{
    case AccountsReceivable = 'accounts_receivable';
    case AccountsPayable = 'accounts_payable';
    case VatInput = 'vat_input';
    case VatOutput = 'vat_output';
    case Bank = 'bank';
    case PaymentsInTransit = 'payments_in_transit';
    case FxGainLoss = 'fx_gain_loss';
    case FxRevaluation = 'fx_revaluation';
    case RoundingDifference = 'rounding_difference';
    case Suspense = 'suspense';
    case DeferredRevenue = 'deferred_revenue';
    case RetainedEarnings = 'retained_earnings';
    case InterEntity = 'inter_entity';
    /**
     * Withholding deducted by our customers from payments to us - PF-083.
     *
     * An asset: a credit we claim against our own liability, and it sits here
     * until the return that claims it. The certificate is the evidence; this
     * is where the money is.
     */
    case WithholdingReceivable = 'withholding_receivable';

    /**
     * Withholding we deducted from a supplier and owe to the authority.
     *
     * A liability, and not the supplier's. The supplier's invoice is settled
     * in full - this is the part that goes to the authority instead of to
     * them, and a system that nets it off the payable leaves the supplier
     * looking unpaid.
     */
    case WithholdingPayable = 'withholding_payable';

    case BadDebt = 'bad_debt';
    case EclProvision = 'ecl_provision';

    /**
     * Is there one of these per jurisdiction rather than one per entity? - PF-084
     *
     * An entity registered in two jurisdictions files two returns, and each
     * has to reconcile to an account. One output account holding both makes
     * neither reconcilable: the figure in it is the sum of two authorities'
     * liabilities, and splitting it afterwards means re-reading every
     * document.
     *
     * Only the VAT pair. A bank account is a bank account wherever the entity
     * is registered, and giving it a jurisdiction would be a column somebody
     * fills in because it is there.
     */
    public function isPerJurisdiction(): bool
    {
        return match ($this) {
            self::VatInput, self::VatOutput => true,
            default => false,
        };
    }

    public function expectedType(): AccountType
    {
        return match ($this) {
            self::AccountsReceivable, self::Bank, self::PaymentsInTransit,
            self::InterEntity, self::WithholdingReceivable => AccountType::Asset,

            self::AccountsPayable, self::VatOutput, self::DeferredRevenue,
            self::EclProvision, self::WithholdingPayable => AccountType::Liability,

            self::RetainedEarnings => AccountType::Equity,

            // VAT input is an asset in most jurisdictions - money the
            // authority owes back - and modelled as one here.
            self::VatInput => AccountType::Asset,

            self::FxGainLoss, self::FxRevaluation, self::RoundingDifference,
            self::Suspense, self::BadDebt => AccountType::Expense,
        };
    }

    /**
     * Must this role be assigned before the entity can post at all?
     *
     * Suspense is required because an unmatched bank line has to go
     * somewhere: without it, the first unexplained receipt either stops the
     * import or silently disappears, and the second is worse.
     */
    public function isRequiredToPost(): bool
    {
        return match ($this) {
            self::AccountsReceivable, self::AccountsPayable, self::Bank,
            self::RetainedEarnings, self::Suspense, self::RoundingDifference => true,
            default => false,
        };
    }
}
