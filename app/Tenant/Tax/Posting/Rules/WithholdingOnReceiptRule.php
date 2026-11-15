<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Posting\Rules;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;
use App\Tenant\Tax\Exceptions\TaxPostingRefused;

/**
 * A customer pays us short, having withheld tax - PF-084, PF-083.
 *
 * ## Dr/Cr
 *
 *   Dr  bank                        what arrived
 *   Dr  withholding_receivable      the credit we can claim, with the certificate
 *   Cr  accounts_receivable         the invoice, closed IN FULL
 *
 * ## An invoice total that does not equal the cash received is CORRECT
 *
 * This is the rule that makes that true in the books. The receivable closes
 * for the whole invoice; the shortfall becomes an asset, because the
 * certificate makes it claimable against our own liability.
 *
 * The two tempting alternatives are both wrong in ways that look like
 * housekeeping. Treating the shortfall as a short payment leaves the invoice
 * part-open forever, and the collections report chases a customer who paid
 * everything it owed. Writing it off as bad debt closes the invoice and
 * destroys the credit: the certificate is still in a drawer, the money is
 * gone from the books, and nobody claims it.
 */
final class WithholdingOnReceiptRule implements PostingRule
{
    public const KEY = 'tax.withholding_on_receipt';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'تحصيل من عميل بعد استقطاعه ضريبة',
            'A receipt from a customer that withheld tax',
            [
                ['Dr', 'bank', 'what arrived'],
                ['Dr', 'withholding_receivable', 'the credit the certificate lets us claim'],
                ['Cr', 'accounts_receivable', 'the invoice, closed in full'],
            ],
        );
    }

    /**
     * @param  array{
     *     gross_amount: string,
     *     withheld_amount: string,
     *     party_id?: string|null,
     *     certificate_number?: string|null,
     *     narration?: string|null,
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $currency = $context->functionalCurrency;
        $gross = Money::of((string) $event['gross_amount'], $currency);
        $withheld = Money::of((string) $event['withheld_amount'], $currency);
        $party = $event['party_id'] ?? null;

        if ($withheld->abs()->isGreaterThan($gross->abs())) {
            throw TaxPostingRefused::withholdingExceedsThePayment((string) $withheld, (string) $gross);
        }

        /*
         | The certificate number goes in the narration on the credit line.
         |
         | It is what somebody looks for when the authority queries the claim,
         | and a narration is the only place a ledger line can carry it until
         | PF-120 gives the payment a record of its own.
         */
        $certificate = $event['certificate_number'] ?? null;
        $narration = $event['narration'] ?? null;
        $withheldNarration = $certificate === null
            ? $narration
            : trim(sprintf('%s certificate %s', (string) $narration, (string) $certificate));

        $received = $gross->minus($withheld);

        return [
            PostingLine::toRole(ControlRole::Bank, $received, $party, $narration),
            PostingLine::toRole(
                ControlRole::WithholdingReceivable, $withheld, $party, $withheldNarration,
            ),
            PostingLine::toRole(ControlRole::AccountsReceivable, $gross->negated(), $party, $narration),
        ];
    }
}
