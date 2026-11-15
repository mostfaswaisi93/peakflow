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
    case BadDebt = 'bad_debt';
    case EclProvision = 'ecl_provision';

    public function expectedType(): AccountType
    {
        return match ($this) {
            self::AccountsReceivable, self::Bank, self::PaymentsInTransit,
            self::InterEntity => AccountType::Asset,

            self::AccountsPayable, self::VatOutput, self::DeferredRevenue,
            self::EclProvision => AccountType::Liability,

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
