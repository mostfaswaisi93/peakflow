<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Posting\Rules;

use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
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
        $functional = $context->functionalCurrency;
        $party = (string) $event['party_id'];
        $narration = $event['narration'] ?? null;

        /*
         | The DOCUMENT's currency, not the entity's - PF-082.
         |
         | This read `$context->functionalCurrency` and applied it to the
         | document's own figures, so a 1,000 EUR invoice credited 1,000
         | riyals to revenue and debited 1,150 to the receivable. The entry
         | BALANCED, because every line was wrong by the same factor - which
         | is what made it invisible to the trial balance, to `ledger:verify`
         | and to the `journal_lines` trigger that refuses a foreign line
         | with no rate, since every line claimed to be functional and gave
         | it nothing to object to.
         |
         | Absent means the document is in the functional currency: an event
         | built before this field existed could only have been.
         */
        $currency = (string) ($event['currency'] ?? $functional);
        $rate = $event['rate'] ?? null;
        $policy = RoundingPolicy::halfUp(Scale::Amount);

        if ($currency !== $functional && ! $rate instanceof Rate) {
            throw IssueRefused::because(sprintf(
                'This document is in %s and the books are kept in %s, and it carries no rate. It '.
                'cannot be posted at its face value: that would put %s of the entity\'s own currency '.
                'into the ledger and balance, so nothing afterwards could tell it was wrong.',
                $currency,
                $functional,
                $currency,
            ));
        }

        /*
         | And a rate on a document already in the functional currency is
         | refused rather than ignored.
         |
         | It asserts a translation that did not happen, and `journal_lines`
         | refuses that on every line; catching it here names the document
         | rather than a line id.
         */
        if ($currency === $functional && $rate instanceof Rate) {
            throw IssueRefused::because(sprintf(
                'This document is in %s, which is the currency the books are kept in, and it carries '.
                'a rate of %s. A rate asserts that a translation happened and came out even, which is '.
                'a different claim from no translation at all - and afterwards the two cannot be told '.
                'apart.',
                $currency,
                (string) $rate->value,
            ));
        }

        $gross = Money::of((string) $event['gross'], $currency);

        if ($gross->isZero()) {
            throw IssueRefused::aDocumentOfNothing();
        }

        $lines = [
            $rate instanceof Rate
                ? PostingLine::translatedToRole(
                    role: ControlRole::AccountsReceivable,
                    original: $gross,
                    rate: $rate,
                    policy: $policy,
                    partyId: $party,
                    narration: $narration,
                )
                : PostingLine::toRole(ControlRole::AccountsReceivable, $gross, $party, $narration),
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
                $deferred = Money::of((string) $revenue['amount'], $currency)->negated();

                $lines[] = $rate instanceof Rate
                    ? PostingLine::translatedToRole(
                        role: ControlRole::DeferredRevenue,
                        original: $deferred,
                        rate: $rate,
                        policy: $policy,
                        partyId: $party,
                        narration: $narration,
                    )
                    : PostingLine::toRole(
                    ControlRole::DeferredRevenue,
                    $deferred,
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

            $net = Money::of((string) $revenue['amount'], $currency)->negated();

            $lines[] = $rate instanceof Rate
                ? PostingLine::translated(
                    accountId: $account,
                    original: $net,
                    rate: $rate,
                    policy: $policy,
                    partyId: $party,
                    narration: $narration,
                    taxCodeId: $revenue['tax_code_id'] ?? null,
                    dimensions: $revenue['dimensions'] ?? [],
                    taxPoint: $revenue['tax_point'] ?? null,
                )
                : PostingLine::toAccount(
                    accountId: $account,
                    amount: $net,
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

            $lines[] = $rate instanceof Rate
                ? PostingLine::translatedToRoleIn(
                    role: ControlRole::VatOutput,
                    jurisdiction: (string) $tax['jurisdiction'],
                    original: $amount->negated(),
                    rate: $rate,
                    policy: $policy,
                    partyId: $party,
                    narration: $narration,
                    taxCodeId: $tax['tax_code_id'] ?? null,
                    taxPoint: $tax['tax_point'] ?? null,
                )
                : PostingLine::toRoleIn(
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
