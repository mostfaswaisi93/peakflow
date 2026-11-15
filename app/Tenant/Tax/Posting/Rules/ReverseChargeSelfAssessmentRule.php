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
 * The recipient self-assesses a reverse charge - both sides - PF-084, PF-079.
 *
 * ## Dr/Cr
 *
 *   Dr  vat_input @ the jurisdiction   the part the recipient can reclaim
 *   Dr  the named expense account      the part it cannot
 *   Cr  vat_output @ the jurisdiction  the liability it has just assessed on itself
 *
 * ## This is the half most systems forget
 *
 * The supplier's invoice shows no tax, so there is nothing obvious to post -
 * and a system that posts nothing has recorded a purchase with no tax
 * anywhere, while the regime says the recipient owes the tax and may reclaim
 * it.
 *
 * For a fully recoverable business the two legs net to nothing, which is why
 * the omission is invisible on a trial balance: the P&L is right, the balance
 * sheet is right, and both VAT boxes are understated by the same amount. It
 * surfaces when an authority compares the acquisition the supplier declared
 * with the acquisition the recipient did not.
 *
 * For a partly exempt business it is not neutral at all - the irrecoverable
 * part is a real cost - which is the case where forgetting it understates the
 * expense as well.
 *
 * ## No payable line
 *
 * Deliberately. The supplier is owed the net and nothing more; the tax never
 * passes through them. A rule that credited the payable with the tax would
 * show the supplier as owed money the business owes the authority, which is
 * the mistake that produces an overpayment.
 */
final class ReverseChargeSelfAssessmentRule implements PostingRule
{
    use PairsTheTaxPointWithTheCode;

    public const KEY = 'tax.reverse_charge_self_assessment';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'احتساب المستلم للضريبة في التحصيل العكسي - الجانبان',
            'The recipient self-assesses a reverse charge, both sides',
            [
                ['Dr', 'vat_input (per jurisdiction)', 'the part the recipient can reclaim'],
                ['Dr', 'the named expense account', 'the part it cannot'],
                ['Cr', 'vat_output (per jurisdiction)', 'the liability it assessed on itself'],
            ],
        );
    }

    /**
     * @param  array{
     *     tax_amount: string,
     *     recoverable_amount?: string|null,
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

        if ($tax->isZero()) {
            throw TaxPostingRefused::reverseChargeNeedsBothSides();
        }

        $jurisdiction = (string) $event['jurisdiction'];
        $party = $event['party_id'] ?? null;
        [$taxCode, $taxPoint] = $this->taxCodeAndPoint($event, self::KEY);
        $narration = $event['narration'] ?? null;

        $declared = $event['recoverable_amount'] ?? null;
        $recoverable = $declared === null ? $tax : Money::of((string) $declared, $currency);

        if ($recoverable->abs()->isGreaterThan($tax->abs())) {
            throw TaxPostingRefused::recoverableExceedsTheTax((string) $recoverable, (string) $tax);
        }

        $irrecoverable = $tax->minus($recoverable);

        $lines = [PostingLine::toRoleIn(
            ControlRole::VatInput, $jurisdiction, $recoverable, $party, $narration, $taxCode, $taxPoint,
        )];

        if (! $irrecoverable->isZero()) {
            $account = $event['irrecoverable_account_id'] ?? null;

            if (! \is_string($account) || $account === '') {
                throw TaxPostingRefused::irrecoverableTaxNeedsAnAccount((string) $irrecoverable);
            }

            $lines[] = PostingLine::toAccount(
                accountId: $account,
                amount: $irrecoverable,
                partyId: $party,
                narration: $narration,
                taxCodeId: $taxCode,
                taxPoint: $taxPoint,
            );
        }

        $lines[] = PostingLine::toRoleIn(
            ControlRole::VatOutput, $jurisdiction, $tax->negated(), $party, $narration, $taxCode, $taxPoint,
        );

        return $lines;
    }
}
