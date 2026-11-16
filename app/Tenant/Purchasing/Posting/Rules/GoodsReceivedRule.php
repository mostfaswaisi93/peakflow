<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Posting\Rules;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;
use App\Tenant\Purchasing\Exceptions\BillRefused;

/**
 * The accrual a goods receipt produces - PF-132.
 *
 * ## Dr/Cr
 *
 * ```
 *   Dr  the order line's expense or asset account    net of what arrived
 *   Cr  goods_received_not_invoiced                  the same total
 * ```
 *
 * ## No tax
 *
 * A receipt is not a tax document. The supply may have happened but the tax
 * point has not necessarily arrived, no tax invoice exists, and nothing can
 * be claimed from an authority on the strength of a delivery note. A system
 * that accrued the tax too would put input tax into a return before the
 * document that supports it exists, which is the one thing a tax authority
 * reliably notices.
 *
 * ## At the order's price, which is the only price there is
 *
 * The invoice has not arrived, so the accrual is worth what was agreed. If
 * the bill differs, the difference lands in the period the bill is posted
 * in, which is correct: it is new information and it belongs to the month it
 * arrived. The three-way match decides whether it is small enough to accept
 * or large enough to be somebody's problem.
 *
 * ## It is in the ledger as a control account, not as a journal somebody
 * posts each month
 *
 * A manual accrual is reversed in full on the 1st whether or not the invoice
 * ever came. This one is reversed by the bill that matches it, which means
 * a balance that does not clear is a receipt nobody was ever billed for -
 * and that is a question worth asking rather than a number that disappears.
 */
final class GoodsReceivedRule implements PostingRule
{
    public const KEY = 'purchasing.goods_received';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'استحقاق أصناف مستلمة ولم تُفتَّر',
            'The accrual a goods receipt produces',
            [
                ['Dr', 'the order line\'s expense or asset account', 'the net of what arrived, at the order\'s price'],
                ['Cr', 'goods_received_not_invoiced', 'the same total - a liability for what has arrived and not been billed'],
            ],
        );
    }

    /**
     * @param  array{
     *     goods_receipt_id: string,
     *     party_id: string,
     *     narration?: string|null,
     *     accrual: list<array{account_id: string, amount: string, dimensions?: array<int, string|null>}>,
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $currency = $context->functionalCurrency;
        $party = (string) $event['party_id'];
        $narration = $event['narration'] ?? null;

        $lines = [];
        $total = Money::zero($currency);

        foreach ($event['accrual'] as $accrual) {
            $account = (string) ($accrual['account_id'] ?? '');

            if ($account === '') {
                throw BillRefused::aLineWithNoAccount();
            }

            $amount = Money::of((string) $accrual['amount'], $currency);

            if ($amount->isZero()) {
                continue;
            }

            $lines[] = PostingLine::toAccount(
                accountId: $account,
                amount: $amount,
                partyId: $party,
                narration: $narration,
                dimensions: $accrual['dimensions'] ?? [],
            );

            $total = $total->plus($amount);
        }

        if ($total->isZero()) {
            throw BillRefused::aDocumentOfNothing();
        }

        $lines[] = PostingLine::toRole(
            ControlRole::GoodsReceivedNotInvoiced,
            $total->negated(),
            $party,
            $narration,
        );

        return $lines;
    }
}
