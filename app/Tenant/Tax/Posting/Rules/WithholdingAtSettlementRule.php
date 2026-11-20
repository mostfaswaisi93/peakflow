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
 * The shortfall on an already-allocated document, relieved - PF-083, PF-120.
 *
 * ## Dr/Cr
 *
 *   Dr  withholding_receivable      the credit the certificate lets us claim
 *   Cr  accounts_receivable         what is left of the invoice
 *
 * ## Why this is not `WithholdingOnReceiptRule`
 *
 * That rule posts three legs - the cash, the credit and the whole invoice -
 * because it is the single-entry case where the money and the certificate
 * arrive together and nothing has been allocated yet.
 *
 * The allocation path has already moved the cash. A 900 receipt against a
 * 1,000 invoice is recorded, allocated and posted; the bank is debited and
 * 900 of the receivable is relieved. What is left is 100 of receivable and
 * no cash to relieve it with. Posting the three-leg rule here would debit
 * the bank a second time for 900 that never arrived twice.
 *
 * So this is two legs: the asset the certificate creates, against what is
 * left of the document.
 *
 * ## Why the two directions are two rules and not a flag
 *
 * A receipt relieves `accounts_receivable` against `withholding_receivable`;
 * a payment relieves `accounts_payable` against `withholding_payable`. One
 * rule reading a flag is one branch away from crediting the account it
 * should debit, and the figure balances either way - which is how the
 * allocation path came to post a payable relief as a receivable one and
 * nothing looked wrong until a supplier's statement was compared.
 *
 * The flag is in the KEY, so the caller chooses a rule rather than passing a
 * boolean into one.
 */
final class WithholdingAtSettlementRule implements PostingRule
{
    public const KEY = 'tax.withholding_at_settlement';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'إغلاق ما استقطعه العميل من فاتورة مخصّصة جزئياً',
            'The withheld remainder of an allocated invoice, relieved against the certificate',
            [
                ['Dr', 'withholding_receivable', 'the credit the certificate lets us claim'],
                ['Cr', 'accounts_receivable', 'what is left of the invoice'],
            ],
        );
    }

    /**
     * @param  array{
     *     withheld_amount: string,
     *     party_id?: string|null,
     *     certificate_number?: string|null,
     *     narration?: string|null,
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $withheld = Money::of((string) $event['withheld_amount'], $context->functionalCurrency);

        if (! $withheld->isPositive()) {
            throw TaxPostingRefused::because(
                'A withholding relief of nothing, or of a negative amount, has no entry to make. '.
                'A deduction of zero is an exemption and belongs on the rate table, not in the '.
                'ledger - and a negative one would relieve a receivable upwards.',
            );
        }

        $party = $event['party_id'] ?? null;
        $narration = $event['narration'] ?? null;

        /*
         | The certificate number is REQUIRED on this rule.
         |
         | `WithholdingOnReceiptRule` treats it as optional, which is right
         | there: that rule is also reached by a historical import where the
         | certificate arrived separately. This path creates the certificate
         | in the same transaction, so a missing number means the caller
         | relieved a receivable against an asset nobody can claim.
         */
        $certificate = trim((string) ($event['certificate_number'] ?? ''));

        if ($certificate === '') {
            throw TaxPostingRefused::because(
                'This relief carries no certificate number. The shortfall becomes an asset only '.
                'because a certificate makes it claimable; without one the entry turns an '.
                'uncollected receivable into an asset nobody can claim, which is a write-off '.
                'wearing the wrong label.',
            );
        }

        return [
            PostingLine::toRole(
                ControlRole::WithholdingReceivable,
                $withheld,
                $party,
                trim(sprintf('%s certificate %s', (string) $narration, $certificate)),
            ),
            PostingLine::toRole(ControlRole::AccountsReceivable, $withheld->negated(), $party, $narration),
        ];
    }
}
