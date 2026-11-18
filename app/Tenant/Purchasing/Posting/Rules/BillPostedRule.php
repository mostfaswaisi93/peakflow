<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Posting\Rules;

use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;
use App\Tenant\Purchasing\Exceptions\BillRefused;

/**
 * The posting a bill produces - PF-133.
 *
 * ## Dr/Cr
 *
 * ```
 *   Dr  the line's expense or asset account    each line's net
 *   Dr  vat_input @ the jurisdiction           the RECOVERABLE tax
 *   Dr  the line's expense account             the NON-recoverable tax
 *   Cr  accounts_payable                       the gross the supplier is owed
 * ```
 *
 * And under the reverse charge, additionally:
 *
 * ```
 *   Dr  vat_input @ the jurisdiction           the self-assessed tax
 *   Cr  vat_output @ the jurisdiction          the same amount
 * ```
 *
 * ## The reverse charge posts BOTH sides, and this is the half most systems
 * forget
 *
 * Under the reverse charge the supplier charges no tax and the recipient
 * accounts for it as if they had charged themselves. That means **two**
 * postings of the same amount: an output, because the recipient owes the tax,
 * and an input, because they are also entitled to claim it.
 *
 * A system that posts only the input side produces a return whose input box
 * is right and whose output box understates the liability by exactly the
 * reverse-charge tax - and because the two sides net to nothing in the
 * accounts, nothing looks wrong until the authority compares the return
 * against the supplier's own declaration.
 *
 * It also means the reverse-charge tax is **not** part of the payable. The
 * supplier is owed the net; the tax is owed to the authority and claimed
 * back from them in the same breath.
 *
 * ## Non-recoverable tax goes to expense
 *
 * Not to the input account. Tax that will never be claimed sitting in the
 * input account is tax that will not tie to any box on any form, and the
 * difference is found during an audit by somebody who did not put it there.
 * The expense it belongs to is the line's own, because the whole point is
 * that the cost of the purchase includes tax that cannot be recovered.
 *
 * ## A foreign-currency bill is translated line by line, at one rate
 *
 * The event's figures are the supplier's, and the rate is the one the bill
 * froze on its tax point. Every line is translated through
 * {@see PostingLine::translated()}, which converts here rather than
 * accepting a functional amount somebody worked out elsewhere - so the rate
 * and the figure on each journal line cannot disagree, which is what a wrong
 * rate looks like in the books.
 *
 * The alternative, and what this rule did before PF-133's rate arrived, is
 * to label the document's own amounts with the entity's currency. That posts
 * a 150,000 JPY bill as 150,000 of the functional currency: the entry
 * balances, the database's translation trigger sees a functional-currency
 * line and has nothing to object to, and the expense is wrong by the whole
 * exchange difference with nothing anywhere recording that a currency was
 * involved.
 *
 * ## And the rounding residual is a line of its own
 *
 * Rounding each line to the functional scale does not generally add up to
 * the rounded total: three lines of 1.03 at 0.2750 are 0.28 each where the
 * 3.09 they come to is 0.85. The difference cannot be hidden inside a
 * translated line, because `journal_lines` re-derives every functional
 * amount from its own rate and refuses a line that does not follow from it -
 * deliberately, since a fudged line is a rate nobody can reproduce.
 *
 * So it posts to `rounding_difference`, in the functional currency and with
 * no rate, which is what it is: an amount that exists only because the books
 * are kept to four places. Keeping it visible in one reconcilable account is
 * also the only way anybody notices if it stops being pennies - which is how
 * a wrong rate announces itself.
 */
final class BillPostedRule implements PostingRule
{
    public const KEY = 'purchasing.bill_posted';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'ترحيل فاتورة مورّد',
            'The posting a supplier bill produces',
            [
                ['Dr', 'the line\'s expense or asset account', 'each line\'s net, plus its non-recoverable tax'],
                ['Dr', 'vat_input (per jurisdiction)', 'the recoverable tax, and the reverse-charge input'],
                ['Cr', 'vat_output (per jurisdiction)', 'the reverse-charge output - the half most systems forget'],
                ['Cr', 'accounts_payable', 'the gross the supplier is owed'],
                ['Dr/Cr', 'rounding_difference', 'the residual from translating a foreign-currency bill'],
            ],
        );
    }

    /**
     * @param  array{
     *     bill_id: string,
     *     party_id: string,
     *     payable: string,
     *     currency?: string,
     *     rate?: Rate|null,
     *     narration?: string|null,
     *     expense: list<array{account_id: string, amount: string, tax_code_id?: string|null, tax_point?: string|null, dimensions?: array<int, string|null>}>,
     *     recoverable_tax: list<array{jurisdiction: string, amount: string, tax_code_id?: string|null, tax_point?: string|null}>,
     *     reverse_charge: list<array{jurisdiction: string, amount: string, tax_code_id?: string|null, tax_point?: string|null}>,
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $functional = $context->functionalCurrency;
        $party = (string) $event['party_id'];
        $narration = $event['narration'] ?? null;

        /*
         | The document's currency, not the entity's.
         |
         | Absent for a bill entered before the rate arrived, and the
         | functional currency is the right reading of that absence: those
         | documents were in it, because nothing else could be posted.
         */
        $currency = (string) ($event['currency'] ?? $functional);
        $rate = $event['rate'] ?? null;
        $policy = RoundingPolicy::halfUp(Scale::Amount);

        if ($currency !== $functional && ! $rate instanceof Rate) {
            throw BillRefused::noRateToPostAt($currency, $functional);
        }

        /*
         | And a rate on a bill already in the functional currency is
         | refused rather than ignored.
         |
         | It asserts a translation that did not happen, and `journal_lines`
         | refuses that on every line; catching it here says which document
         | carries the contradiction instead of naming a line id.
         */
        if ($currency === $functional && $rate instanceof Rate) {
            throw BillRefused::because(sprintf(
                'This bill is in %s, which is the currency the books are kept in, and it carries a '.
                'rate of %s. A rate asserts that a translation happened and came out even, which is '.
                'a different claim from no translation at all - and afterwards the two cannot be '.
                'told apart.',
                $currency,
                (string) $rate->value,
            ));
        }

        $payable = Money::of((string) $event['payable'], $currency);

        if ($payable->isZero() && ($event['reverse_charge'] ?? []) === []) {
            throw BillRefused::aDocumentOfNothing();
        }

        $lines = [];

        foreach ($event['expense'] as $expense) {
            $account = (string) ($expense['account_id'] ?? '');

            if ($account === '') {
                throw BillRefused::aLineWithNoAccount();
            }

            $amount = Money::of((string) $expense['amount'], $currency);

            $lines[] = $rate instanceof Rate
                ? PostingLine::translated(
                    accountId: $account,
                    original: $amount,
                    rate: $rate,
                    policy: $policy,
                    partyId: $party,
                    narration: $narration,
                    taxCodeId: $expense['tax_code_id'] ?? null,
                    dimensions: $expense['dimensions'] ?? [],
                    taxPoint: $expense['tax_point'] ?? null,
                )
                : PostingLine::toAccount(
                    accountId: $account,
                    amount: $amount,
                    partyId: $party,
                    narration: $narration,
                    taxCodeId: $expense['tax_code_id'] ?? null,
                    dimensions: $expense['dimensions'] ?? [],
                    taxPoint: $expense['tax_point'] ?? null,
                );
        }

        foreach ($event['recoverable_tax'] as $tax) {
            $amount = Money::of((string) $tax['amount'], $currency);

            if ($amount->isZero()) {
                // A zero-rated or exempt group claims nothing, and a zero
                // line is refused by the ledger - PF-041.
                continue;
            }

            $lines[] = $this->taxLine(
                ControlRole::VatInput,
                $tax,
                $amount,
                $party,
                $narration,
                $rate,
                $policy,
            );
        }

        /*
         | The reverse charge, both sides.
         |
         | The input because the recipient may claim it, the output because
         | the recipient owes it. Equal and opposite in the accounts, and
         | two different boxes on the return - which is why they cannot be
         | netted here even though the entry would still balance.
         */
        foreach ($event['reverse_charge'] ?? [] as $tax) {
            $amount = Money::of((string) $tax['amount'], $currency);

            if ($amount->isZero()) {
                continue;
            }

            /*
             | Translated from the SAME original on both sides.
             |
             | Negating the original rather than the translated figure would
             | round the two sides independently, and a 0.01 that lands on
             | the input and not on the output is an input box and an output
             | box that no longer agree - the exact discrepancy the
             | authority compares.
             */
            $input = $this->taxLine(
                ControlRole::VatInput,
                $tax,
                $amount,
                $party,
                $narration,
                $rate,
                $policy,
            );

            $lines[] = $input;

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

        if (! $payable->isZero()) {
            $lines[] = $rate instanceof Rate
                ? PostingLine::translatedToRole(
                    role: ControlRole::AccountsPayable,
                    original: $payable->negated(),
                    rate: $rate,
                    policy: $policy,
                    partyId: $party,
                    narration: $narration,
                )
                : PostingLine::toRole(
                    ControlRole::AccountsPayable,
                    $payable->negated(),
                    $party,
                    $narration,
                );
        }

        return $this->balanced($lines, $context);
    }

    /**
     * A tax control line, translated or not.
     *
     * @param  array{jurisdiction: string, amount: string, tax_code_id?: string|null, tax_point?: string|null}  $tax
     */
    private function taxLine(
        ControlRole $role,
        array $tax,
        Money $amount,
        string $party,
        ?string $narration,
        ?Rate $rate,
        RoundingPolicy $policy,
    ): PostingLine {
        if ($rate instanceof Rate) {
            return PostingLine::translatedToRoleIn(
                role: $role,
                jurisdiction: (string) $tax['jurisdiction'],
                original: $amount,
                rate: $rate,
                policy: $policy,
                partyId: $party,
                narration: $narration,
                taxCodeId: $tax['tax_code_id'] ?? null,
                taxPoint: $tax['tax_point'] ?? null,
            );
        }

        return PostingLine::toRoleIn(
            $role,
            (string) $tax['jurisdiction'],
            $amount,
            $party,
            $narration,
            $tax['tax_code_id'] ?? null,
            $tax['tax_point'] ?? null,
        );
    }

    /**
     * The translation residual, where rounding each line left one.
     *
     * Nothing is added in the single-currency case, where the figures came
     * from a document that already totals exactly. In the translated case
     * this is the only line the Poster's balance check can be satisfied by:
     * every other line's functional amount is re-derived from its own rate
     * by a database trigger, so the difference cannot be absorbed into one
     * of them, and an unbalanced entry is refused outright - which would
     * make a foreign-currency bill unpostable for the sake of a penny.
     *
     * @param  list<PostingLine>  $lines
     * @return list<PostingLine>
     */
    private function balanced(array $lines, PostingContext $context): array
    {
        $residual = $context->zero();

        foreach ($lines as $line) {
            $residual = $residual->plus($line->amount);
        }

        if ($residual->isZero()) {
            return $lines;
        }

        $lines[] = PostingLine::toRole(
            ControlRole::RoundingDifference,
            $residual->negated(),
            null,
            'Rounding on translating the bill at its tax-point rate',
        );

        return $lines;
    }
}
