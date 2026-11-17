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

    /**
     * Goods received and not yet invoiced - PF-132.
     *
     * A liability for things that have arrived and whose bill has not. It is
     * what makes a period-end cutoff honest: the goods are on the shelf and
     * in the expense, and the supplier's invoice is in the post.
     *
     * A control account rather than an accrual journal somebody remembers to
     * post, because the thing that has to be reconcilable is "what have we
     * received and not been billed for" - and that is a balance, not a note.
     * The bill that matches a receipt reverses it, so a balance that does not
     * clear is a receipt nobody was ever billed for, which is exactly what an
     * aged accrual report is looking for.
     */
    case GoodsReceivedNotInvoiced = 'goods_received_not_invoiced';

    /**
     * Owed to an employee, not to a supplier - PF-143.
     *
     * An employee is not a supplier. The balance is reported differently in
     * most frameworks, it is frequently settled through payroll rather than
     * through the payment run, and an aging report that mixed the two would
     * show a colleague's taxi fare beside a supplier whose terms are thirty
     * days.
     *
     * It also matters for the fraud this module is about: "who do we owe
     * money to" with employees mixed into it is a list nobody reads closely,
     * and the expense claim is the cheapest place in a business to take money
     * out of it. Separating the control account separates the two questions.
     */
    case EmployeePayable = 'employee_payable';

    case BadDebt = 'bad_debt';

    /**
     * Money that arrived after it had been written off - PF-172.
     *
     * Its own account, because a recovery is **not** an un-write-off. The
     * conclusion that a debt would not be collected was right on the day
     * it was made, with what was known then; crediting bad debt back would
     * say otherwise and would make the two figures net to something that
     * describes neither.
     *
     * Kept apart, "we wrote off 400,000 and recovered 30,000" is a
     * sentence about how good the write-off decisions were - which is the
     * only question that improves them.
     */
    case BadDebtRecovery = 'bad_debt_recovery';

    /**
     * Cash received that no document has claimed yet - PF-167.
     *
     * A **liability**, and that is the whole point of naming it. Money a
     * customer sent that nothing has been allocated against is money we
     * are holding for them, not revenue and not a reduction of what they
     * owe. Typing it as anything else makes an unallocated receipt look
     * like performance.
     *
     * It is also why a payment never posts against a party balance
     * directly: the balance is a consequence of allocation, and a receipt
     * that moved it before anybody said which invoice it was for would be
     * a balance nobody can take apart.
     */
    case UnappliedCash = 'unapplied_cash';

    /**
     * What the bank charged us, found on the statement - PF-164.
     *
     * An expense, and its own account rather than a line in administrative
     * costs: a rising bank-charge figure is information. A customer who
     * short-pays every invoice by the transfer fee is telling you something
     * about your payment terms, and that only shows when the charges are
     * somewhere they can be added up.
     */
    case BankCharges = 'bank_charges';
    case EclProvision = 'ecl_provision';

    /**
     * The impairment charge, which is where an estimate lands - PF-173.
     *
     * Its own expense account, apart from `bad_debt`. The two are the
     * difference between an **estimate** and a **conclusion**: a provision
     * says a portion of what is owed probably will not arrive, and a
     * write-off says a particular debt will not. One is revised every
     * period; the other is final.
     *
     * Sharing an account would make the P&L line a mixture of the two and
     * destroy the figure anybody actually wants - how much of last year's
     * impairment charge turned out to be real. That figure is what
     * calibrates the matrix, and a matrix nobody can calibrate is a
     * matrix somebody picked once.
     */
    case EclCharge = 'ecl_charge';

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
            self::InterEntity, self::WithholdingReceivable,

            /*
             | Suspense is a balance-sheet account, not a cost.
             |
             | An unmatched bank receipt sits in it as a BALANCE until
             | somebody identifies it. Typing it as an expense would put
             | every unidentified item straight into the income statement,
             | so a month with a large unmatched receipt would report a loss
             | that disappears when the receipt is identified.
             |
             | Measured: this said `Expense` - grouped with FX and rounding,
             | which are costs - while all three shipped charts type the
             | account as an asset. Nothing called this method, so the
             | disagreement sat there until `ControlRoleRules` compared the
             | two.
             */
            self::Suspense,

            /*
             | And an expected-credit-loss provision is a CONTRA-asset.
             |
             | IFRS 9's loss allowance reduces the carrying amount of the
             | financial asset it is held against, so it is presented as a
             | deduction from receivables rather than as a liability of its
             | own: the business does not owe the allowance to anybody.
             |
             | Measured the same way: this said `Liability` and the charts
             | type it as an asset with a credit balance, which is what a
             | contra-asset is.
             */
            self::EclProvision => AccountType::Asset,

            self::AccountsPayable, self::VatOutput, self::DeferredRevenue,
            self::WithholdingPayable,
            /*
             | Both of these are liabilities for something already incurred.
             |
             | Measured: `goods_received_not_invoiced` was added in PF-132
             | and NOT listed here, and a match with no `default` throws on
             | a case it does not name. Nothing called this method, so
             | nothing noticed - which is why `ControlRoleRules` now walks
             | every case of this enum through every method on it.
             */
            self::GoodsReceivedNotInvoiced, self::EmployeePayable,
            self::UnappliedCash => AccountType::Liability,

            self::RetainedEarnings => AccountType::Equity,

            // VAT input is an asset in most jurisdictions - money the
            // authority owes back - and modelled as one here.
            self::VatInput => AccountType::Asset,

            self::FxGainLoss, self::FxRevaluation, self::RoundingDifference,
            self::BadDebt, self::BankCharges, self::EclCharge => AccountType::Expense,

            /*
             | A recovery is income, not a negative expense.
             |
             | Typing it as an expense would let it net against bad debt
             | in every report that groups by type - which is exactly the
             | netting this role exists to prevent.
             */
            self::BadDebtRecovery => AccountType::Revenue,
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
