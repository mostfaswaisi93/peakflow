<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Posting\Rules;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;
use App\Tenant\Sales\Exceptions\IssueRefused;

/**
 * The posting an issued invoice produces - PF-095.
 *
 * ## Dr/Cr
 *
 *   Dr  accounts_receivable            the gross the customer owes
 *   Cr  the line's revenue account     each line's net
 *   Cr  vat_output @ the jurisdiction  each rate group's tax
 *
 * ## Why this rule and PF-084's `tax.sales_tax` both exist
 *
 * `tax.sales_tax` posts the tax legs of a document and nothing else, which is
 * what a tax-only correction needs - an adjustment that changes the tax
 * treatment of a supply whose revenue was posted correctly. This rule posts a
 * whole document.
 *
 * They are not alternatives for the same event. Using the tax-only rule to
 * issue an invoice would leave the revenue unposted; using this one for a tax
 * correction would re-post revenue that is already there.
 *
 * ## The event carries the figures, not the document
 *
 * A rule that loaded the invoice would make the ledger depend on `Sales`, and
 * the layering ruleset does not permit that - correctly: the posting rules for
 * a jurisdiction have to be changeable without touching the sales module. So
 * the issuer passes what it has already computed and frozen, which also means
 * the posting cannot disagree with the document.
 */
final class InvoiceIssuedRule implements PostingRule
{
    public const KEY = 'sales.invoice_issued';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'ترحيل فاتورة مبيعات صادرة',
            'The posting an issued sales invoice produces',
            [
                ['Dr', 'accounts_receivable', 'the gross the customer owes'],
                ['Cr', 'the line\'s revenue account', 'each line\'s net'],
                ['Cr', 'vat_output (per jurisdiction)', 'each rate group\'s tax'],
            ],
        );
    }

    /**
     * @param  array{
     *     invoice_id: string,
     *     party_id: string,
     *     gross: string,
     *     narration?: string|null,
     *     revenue: list<array{account_id: string, amount: string, tax_code_id?: string|null, tax_point?: string|null, dimensions?: array<int, string|null>}>,
     *     tax: list<array{jurisdiction: string, amount: string, tax_code_id?: string|null, tax_point?: string|null}>,
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $currency = $context->functionalCurrency;
        $party = (string) $event['party_id'];
        $narration = $event['narration'] ?? null;

        $gross = Money::of((string) $event['gross'], $currency);

        if ($gross->isZero()) {
            throw IssueRefused::aDocumentOfNothing();
        }

        $lines = [
            PostingLine::toRole(ControlRole::AccountsReceivable, $gross, $party, $narration),
        ];

        foreach ($event['revenue'] as $revenue) {
            /*
             | A deferred line credits the liability, not revenue - PF-107.
             |
             | Through the control role rather than the line's own account,
             | because the deferral is a control account that one report
             | rolls forward and one reconciliation ties out: spreading it
             | across every revenue account's mirror would make both
             | impossible.
             */
            if (($revenue['deferred'] ?? false) === true) {
                $lines[] = PostingLine::toRole(
                    ControlRole::DeferredRevenue,
                    Money::of((string) $revenue['amount'], $currency)->negated(),
                    $party,
                    $narration,
                    $revenue['tax_code_id'] ?? null,
                    $revenue['tax_point'] ?? null,
                );

                continue;
            }

            $account = (string) ($revenue['account_id'] ?? '');

            if ($account === '') {
                throw IssueRefused::aLineWithNoRevenueAccount();
            }

            $lines[] = PostingLine::toAccount(
                accountId: $account,
                amount: Money::of((string) $revenue['amount'], $currency)->negated(),
                partyId: $party,
                narration: $narration,
                taxCodeId: $revenue['tax_code_id'] ?? null,
                dimensions: $revenue['dimensions'] ?? [],
                taxPoint: $revenue['tax_point'] ?? null,
            );
        }

        foreach ($event['tax'] as $tax) {
            $amount = Money::of((string) $tax['amount'], $currency);

            if ($amount->isZero()) {
                /*
                 | A zero-rated group posts nothing, and must not post a zero
                 | line: `journal_lines_amount_not_zero` refuses one, and a
                 | zero line is not a posting - PF-041.
                 */
                continue;
            }

            $lines[] = PostingLine::toRoleIn(
                ControlRole::VatOutput,
                (string) $tax['jurisdiction'],
                $amount->negated(),
                $party,
                $narration,
                $tax['tax_code_id'] ?? null,
                $tax['tax_point'] ?? null,
            );
        }

        return $lines;
    }
}
