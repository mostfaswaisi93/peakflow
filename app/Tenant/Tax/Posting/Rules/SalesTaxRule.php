<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Posting\Rules;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * The tax on a sales document - PF-084.
 *
 * ## Dr/Cr
 *
 *   Dr  accounts_receivable            the customer owes the tax too
 *   Cr  vat_output @ the jurisdiction  we owe it to that authority
 *
 * ## The jurisdiction is on the line, not derived
 *
 * An entity registered in two jurisdictions has two output accounts, and which
 * one this posting belongs to comes from the place-of-supply decision rather
 * than from where the entity happens to be incorporated. A posting that
 * defaulted to the entity's country would put one authority's VAT in the
 * other's return, with a figure that looks plausible in both.
 *
 * ## Only the tax
 *
 * The net and the revenue are the sales document's own rule, which arrives in
 * Phase 05. Splitting them this way keeps the tax posting reviewable on its
 * own: it is the leg an auditor reconciles to the return, and the leg whose
 * jurisdiction can be wrong without anything else looking wrong.
 */
final class SalesTaxRule implements PostingRule
{
    public const KEY = 'tax.sales_tax';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'ضريبة المخرجات على مستند بيع',
            'The output tax on a sales document',
            [
                ['Dr', 'accounts_receivable', 'the tax the customer owes with the invoice'],
                ['Cr', 'vat_output (per jurisdiction)', 'the liability to that authority'],
            ],
        );
    }

    /**
     * @param  array{
     *     tax_amount: string,
     *     jurisdiction: string,
     *     party_id?: string|null,
     *     tax_code_id?: string|null,
     *     narration?: string|null,
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $tax = Money::of((string) $event['tax_amount'], $context->functionalCurrency);
        $jurisdiction = (string) $event['jurisdiction'];
        $party = $event['party_id'] ?? null;
        $taxCode = $event['tax_code_id'] ?? null;
        $narration = $event['narration'] ?? null;

        return [
            PostingLine::toRole(ControlRole::AccountsReceivable, $tax, $party, $narration, $taxCode),
            PostingLine::toRoleIn(
                ControlRole::VatOutput, $jurisdiction, $tax->negated(), $party, $narration, $taxCode,
            ),
        ];
    }
}
