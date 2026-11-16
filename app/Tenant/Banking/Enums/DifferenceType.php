<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Enums;

use App\Tenant\Ledger\Enums\ControlRole;

/**
 * Why a payment and a document do not agree - PF-164.
 *
 * ## Classified before it is written off, and that is the whole task
 *
 * A customer pays 9,985 against an invoice for 10,000. The fifteen is a
 * bank charge, or a rounding difference, or an FX movement, or withholding
 * their finance department deducted at source, or they are short-paying and
 * disputing part of the bill. Those are five different facts about the
 * business and they post to five different accounts.
 *
 * An engine that absorbed all five into "difference written off" would be
 * right about the receivable and wrong about everything else. The symptoms
 * arrive slowly:
 *
 * - **Withholding treated as a short payment.** The tax credit is never
 *   claimed, and it is a credit against the entity's own liability - real
 *   money, thrown away quietly, at a rate of a few percent of revenue.
 * - **Bank charges in bad debt.** The customer looks like a poor payer and
 *   the cost of a bank relationship is invisible.
 * - **A genuine short payment in rounding.** The one difference somebody
 *   should have chased is the one nobody sees.
 *
 * ## Each type has its own tolerance, and that is not symmetry
 *
 * A rounding tolerance of 0.05 is arithmetic. A short-payment tolerance of
 * 0.05 is a policy that nothing is ever written off without a decision. One
 * number for all of them would have to be the largest, and the largest is
 * the one that absorbs theft.
 *
 * What a tolerance means is the same for every type: below it the
 * difference is recorded automatically, above it somebody's name is on it.
 * There is deliberately no type exempt from that. An earlier version let
 * bank charges and rounding through at any size - the reasoning being that
 * an approval step on 0.02 trains people to approve without reading, which
 * is true - and it put the enum at odds with the constraint on the table,
 * which refuses an above-tolerance row with no approver.
 *
 * Two rules that disagree is worse than either of them. The concern was
 * real and the answer is the tolerance itself: a bank-charge ceiling of 250
 * is what keeps an ordinary fee from ever reaching an approver, and a
 * charge above it is a bank that repriced, which somebody should see.
 *
 * ## Withholding is not settled here
 *
 * It resolves against PF-083, which holds the rates, the certificates and
 * the claim. This enum names it so that it can be *recognised* and routed;
 * recording it as a difference and stopping would leave the credit in a
 * control account with no certificate behind it, which is how it fails an
 * audit later.
 */
enum DifferenceType: string
{
    case BankCharge = 'bank_charge';
    case FxDifference = 'fx_difference';
    case ShortPayment = 'short_payment';
    case Overpayment = 'overpayment';
    case Rounding = 'rounding';
    case Withholding = 'withholding';

    /**
     * The tenant setting that bounds this type.
     *
     * Each is bounded separately by a platform ceiling, because they absorb
     * different things and a single figure would have to be the largest.
     */
    public function toleranceSetting(): string
    {
        return match ($this) {
            self::BankCharge => 'banking.tolerance_bank_charge',
            self::FxDifference => 'banking.tolerance_fx_difference',
            self::ShortPayment => 'banking.tolerance_short_payment',
            self::Overpayment => 'banking.tolerance_overpayment',
            self::Rounding => 'banking.tolerance_rounding',
            self::Withholding => 'banking.tolerance_withholding',
        };
    }

    /** The posting rule that writes this difference. */
    public function postingRule(): string
    {
        return match ($this) {
            self::BankCharge => 'banking.difference_bank_charge',
            self::FxDifference => 'banking.difference_fx',
            self::ShortPayment => 'banking.difference_short_payment',
            self::Overpayment => 'banking.difference_overpayment',
            self::Rounding => 'banking.difference_rounding',
            self::Withholding => 'banking.difference_withholding',
        };
    }

    /** Where the difference lands. */
    public function account(): ControlRole
    {
        return match ($this) {
            self::BankCharge => ControlRole::BankCharges,
            self::FxDifference => ControlRole::FxGainLoss,

            /*
             | A short payment is a write-off against bad debt.
             |
             | Not an adjustment to revenue. The sale happened at the
             | invoiced figure and the customer did not pay all of it,
             | which is a credit loss - and restating revenue instead
             | would make the margin on that sale look correct while the
             | cash never arrived.
             */
            self::ShortPayment => ControlRole::BadDebt,

            /*
             | An overpayment is owed back, so it sits on the customer.
             |
             | Against accounts receivable with the party, which makes
             | their balance negative - which is what an overpayment is.
             | Posting it to income would recognise revenue for a sale
             | nobody made, and the customer would still be owed it.
             */
            self::Overpayment => ControlRole::AccountsReceivable,

            self::Rounding => ControlRole::RoundingDifference,
            self::Withholding => ControlRole::WithholdingReceivable,
        };
    }

    /**
     * Whether this type is resolved somewhere other than here.
     *
     * Withholding is. Recording it as a difference and stopping would leave
     * a tax credit in a control account with no certificate behind it, and
     * a credit without its certificate is one an authority disallows.
     */
    public function resolvesElsewhere(): ?string
    {
        return match ($this) {
            self::Withholding => 'PF-083: the rate, the certificate and the claim live with withholding tax, '.
                'and a credit recorded here without a certificate is one the authority disallows',
            default => null,
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::BankCharge => 'bank charge',
            self::FxDifference => 'exchange difference',
            self::ShortPayment => 'short payment',
            self::Overpayment => 'overpayment',
            self::Rounding => 'rounding',
            self::Withholding => 'withholding deducted at source',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::BankCharge => 'رسوم بنكية',
            self::FxDifference => 'فرق صرف',
            self::ShortPayment => 'سداد ناقص',
            self::Overpayment => 'سداد زائد',
            self::Rounding => 'فرق تقريب',
            self::Withholding => 'استقطاع ضريبي من المنبع',
        };
    }
}
