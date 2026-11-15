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
 * We pay a supplier and withhold part of it for the authority - PF-084, PF-083.
 *
 * ## Dr/Cr
 *
 *   Dr  accounts_payable         the supplier's invoice, settled IN FULL
 *   Cr  bank                     what we actually transferred
 *   Cr  withholding_payable      what we kept, and now owe the authority
 *
 * ## The payable is cleared for the whole amount
 *
 * That is the point of the rule. The supplier is not owed the withheld part -
 * it is owed the full invoice, of which part goes to the authority on its
 * behalf. A system that credits the bank for the full invoice is wrong about
 * the cash; one that clears the payable only for what was transferred leaves
 * the supplier permanently showing a balance it will chase, and somebody
 * eventually writes it off.
 *
 * ## The withholding is a liability to the authority, not to the supplier
 *
 * Its own control account, because it is remitted on its own cycle and
 * reconciled against the certificates. Netting it against the payable would
 * hide it in a figure that is supposed to mean "owed to suppliers".
 */
final class WithholdingOnPaymentRule implements PostingRule
{
    public const KEY = 'tax.withholding_on_payment';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'سداد لمورد مع استقطاع ضريبي',
            'A payment to a supplier with tax withheld',
            [
                ['Dr', 'accounts_payable', 'the invoice, settled in full'],
                ['Cr', 'bank', 'what was actually transferred'],
                ['Cr', 'withholding_payable', 'what was kept, and is owed to the authority'],
            ],
        );
    }

    /**
     * @param  array{
     *     gross_amount: string,
     *     withheld_amount: string,
     *     party_id?: string|null,
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
        $narration = $event['narration'] ?? null;

        if ($withheld->abs()->isGreaterThan($gross->abs())) {
            throw TaxPostingRefused::withholdingExceedsThePayment((string) $withheld, (string) $gross);
        }

        $transferred = $gross->minus($withheld);

        return [
            PostingLine::toRole(ControlRole::AccountsPayable, $gross, $party, $narration),
            PostingLine::toRole(ControlRole::Bank, $transferred->negated(), $party, $narration),
            PostingLine::toRole(ControlRole::WithholdingPayable, $withheld->negated(), $party, $narration),
        ];
    }
}
