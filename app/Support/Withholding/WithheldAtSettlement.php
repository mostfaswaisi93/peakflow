<?php

declare(strict_types=1);

namespace App\Support\Withholding;

use App\Support\Money\Money;
use Carbon\CarbonInterface;

/**
 * What settlement needs to know about tax the payer kept back - PF-083, PF-120.
 *
 * ## Why this interface exists in Support
 *
 * The same decision as `ClosingCheck` and `LedgerCheck`, and for a reason
 * that is specific here.
 *
 * Withholding is **deducted by the payer from the payment**, which is the
 * first criterion PF-083 states and the one the whole task is arranged
 * around: a document total that can see withholding is a document somebody
 * eventually nets it off, and an invoice that nets it off understates the
 * receivable and the VAT base at once. So the rate, the base and the
 * certificate are Tax's, and the act of closing a document against short
 * cash is Settlement's.
 *
 * Neither module may depend on the other. Tax depends on `Support`,
 * `Tenancy` and `Ledger` only, which is what keeps a jurisdiction's
 * treatment changeable without touching anything that posts; Settlement
 * does not list Tax, and adding it would make the module that moves money
 * depend on the module that decides treatments.
 *
 * `CounterpartyRegistrations` solved the mirror of this by having Tax
 * declare what it needed and Sales supply it - which works because Sales
 * may see Tax. Here the implementer is Tax, so the declaration has to sit
 * where Tax can see it. That is here.
 *
 * ## Why one port and not two calls
 *
 * Because the rate, the deduction and the certificate are one decision made
 * at one date, and a caller that could ask for a rate without producing a
 * certificate would produce exactly the state nobody wants: a closed
 * document, a relieved receivable and no document the payee can claim with.
 * `settle()` either does all of it or refuses.
 */
interface WithheldAtSettlement
{
    /**
     * What a payer in this jurisdiction keeps back from this party, if anything.
     *
     * Null means **no rate is configured**, which is not a rate of zero: a
     * zero rate is how an exemption is recorded, dated and sourced, and a
     * payer that withholds nothing because nobody set a rate up is liable
     * for what it should have withheld. The caller has to tell those apart,
     * so the null is not collapsed here.
     *
     * `$base` is the document's net and `$tax` its VAT, because whether the
     * withholding is computed on the net or on the gross is the regime's
     * choice and the difference is the whole VAT amount rather than a
     * rounding difference.
     */
    public function on(
        string $entityId,
        string $partyId,
        Money $base,
        Money $tax,
        CarbonInterface $on,
        ?string $itemType = null,
    ): ?WithheldAmount;

    /**
     * Record the certificate for a deduction taken at settlement.
     *
     * Returns its number, which the posting carries into the ledger: it is
     * what somebody looks for when the authority queries the claim.
     *
     * The period is the DEDUCTION's, taken from the settlement date rather
     * than from the document - an invoice raised in March and settled in May
     * produces a May certificate, and reporting it in March gives the payee
     * a credit the authority will not match.
     */
    public function certify(
        string $entityId,
        string $partyId,
        WithheldAmount $amount,
        CarbonInterface $settledOn,
        string $paymentReference,
    ): string;

    /**
     * The posting rule that relieves the document against the deduction.
     *
     * A key rather than a class, because the rule is Tax's and the caller
     * may not name it. Asked per document type: relieving a receivable and
     * relieving a payable are mirror entries and a single rule reading a
     * flag would be one branch away from crediting the account it should
     * debit, which is the defect PF-120 found in the allocation path.
     */
    public function postingRuleKey(string $documentType): string;
}
