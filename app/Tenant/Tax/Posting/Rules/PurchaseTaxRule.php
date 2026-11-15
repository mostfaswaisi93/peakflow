<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Posting\Rules;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;
use App\Tenant\Tax\Posting\PairsTheTaxPointWithTheCode;
use App\Tenant\Tax\Exceptions\TaxPostingRefused;

/**
 * The tax on a purchase document, recoverable and not - PF-084.
 *
 * ## Dr/Cr
 *
 *   Dr  vat_input @ the jurisdiction   the recoverable part
 *   Dr  the named expense account      the part that is NOT recoverable
 *   Cr  accounts_payable               the whole tax the supplier charged
 *
 * ## Why the non-recoverable part goes to expense
 *
 * Because that is what makes the input account reconcilable to the return.
 *
 * The input VAT account is supposed to equal what the business is claiming
 * back. Post an unrecoverable 40% of a restaurant bill's VAT into it and the
 * account overstates the claim by exactly that, every month, in an amount
 * nobody can derive without re-reading the bills. The usual response is a
 * manual journal at quarter end that moves an estimated figure to expense -
 * which is the reconciliation the account was supposed to provide, done by
 * hand and approximately.
 *
 * It is also the economically correct answer: tax a business cannot reclaim is
 * a cost of the thing it bought.
 *
 * ## The split arrives as an amount, not a percentage
 *
 * A partial exemption method produces a recoverable PROPORTION, and applying
 * it here would round it a second time - the proportion is applied to a
 * period's worth of input tax by the method, not to one document by the
 * poster. So the caller passes both amounts and this rule checks they add up.
 */
final class PurchaseTaxRule implements PostingRule
{
    use PairsTheTaxPointWithTheCode;

    public const KEY = 'tax.purchase_tax';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'ضريبة المدخلات على مستند شراء، القابل للخصم وغير القابل',
            'The input tax on a purchase document, recoverable and not',
            [
                ['Dr', 'vat_input (per jurisdiction)', 'the part the business can reclaim'],
                ['Dr', 'the named expense account', 'the part it cannot - a cost of what it bought'],
                ['Cr', 'accounts_payable', 'the whole tax the supplier charged'],
            ],
        );
    }

    /**
     * @param  array{
     *     tax_amount: string,
     *     recoverable_amount: string,
     *     jurisdiction: string,
     *     irrecoverable_account_id?: string|null,
     *     party_id?: string|null,
     *     tax_code_id?: string|null,
     *     narration?: string|null,
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $currency = $context->functionalCurrency;
        $tax = Money::of((string) $event['tax_amount'], $currency);
        $recoverable = Money::of((string) $event['recoverable_amount'], $currency);
        $jurisdiction = (string) $event['jurisdiction'];
        $party = $event['party_id'] ?? null;
        [$taxCode, $taxPoint] = $this->taxCodeAndPoint($event, self::KEY);
        $narration = $event['narration'] ?? null;

        if ($recoverable->abs()->isGreaterThan($tax->abs())) {
            throw TaxPostingRefused::recoverableExceedsTheTax((string) $recoverable, (string) $tax);
        }

        $irrecoverable = $tax->minus($recoverable);

        $lines = [
            PostingLine::toRoleIn(
                ControlRole::VatInput, $jurisdiction, $recoverable, $party, $narration, $taxCode, $taxPoint,
            ),
            PostingLine::toRole(ControlRole::AccountsPayable, $tax->negated(), $party, $narration, $taxCode, $taxPoint),
        ];

        if ($irrecoverable->isZero()) {
            return $lines;
        }

        $account = $event['irrecoverable_account_id'] ?? null;

        if (! \is_string($account) || $account === '') {
            throw TaxPostingRefused::irrecoverableTaxNeedsAnAccount((string) $irrecoverable);
        }

        /*
         | Inserted BEFORE the payable, so the entry reads the way an
         | accountant would write it: the two debits, then the credit.
         */
        array_splice($lines, 1, 0, [PostingLine::toAccount(
            accountId: $account,
            amount: $irrecoverable,
            partyId: $party,
            narration: $narration,
            taxCodeId: $taxCode,
            taxPoint: $taxPoint,
        )]);

        return $lines;
    }
}
