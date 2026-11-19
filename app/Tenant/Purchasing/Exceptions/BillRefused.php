<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Exceptions;

/**
 * A bill the system will not accept or post - PF-133, PF-139.
 */
final class BillRefused extends \RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function aDocumentOfNothing(): self
    {
        return new self(
            'A bill for nothing cannot be posted. An entry with no amount is not a record of a '.
            'purchase, and the supplier is owed nothing by it.',
        );
    }

    public static function aLineWithNoAccount(): self
    {
        return new self(
            'A bill line has no expense or asset account. A free-text line still has to post '.
            'somewhere, and letting the posting rule choose is how a purchase ends up in a suspense '.
            'account nobody reconciles.',
        );
    }

    /**
     * A bill whose three-way match has unanswered exceptions - PF-135.
     *
     * The discrepancies are named in the message rather than counted,
     * because "3 match exceptions" sends somebody to a queue while "billed
     * 120 units, received 100" tells them what to do. The person who posts
     * is frequently not the person who can answer, and the message is what
     * they will forward.
     *
     * @param  list<string>  $details
     */
    public static function openMatchExceptions(array $details): self
    {
        return new self(sprintf(
            "This bill does not match its order and receipt, so it cannot be posted:\n- %s\n".
            'Each of these needs an answer that is kept with the bill. A match exception cleared by '.
            'posting through it is a control that exists only on paper.',
            implode("\n- ", $details),
        ));
    }

    public static function duplicate(string $number, string $supplier, string $existing): self
    {
        return new self(sprintf(
            'Bill %s from %s is already recorded as %s. Paying the same invoice twice is the most '.
            'expensive error in accounts payable and is usually found by the supplier rather than by '.
            'the payer, so the second one is refused rather than flagged.',
            $number,
            $supplier,
            $existing,
        ));
    }

    public static function unacknowledgedDuplicates(int $count): self
    {
        return new self(sprintf(
            '%d suspected duplicate%s has not been looked at. A near-match is a question for a person: '.
            'either it is the same invoice, in which case paying it twice is the error this exists to '.
            'prevent, or it is not, in which case somebody should say so and be answerable for it.',
            $count,
            $count === 1 ? '' : 's',
        ));
    }

    /**
     * A foreign-currency bill with no rate on its tax point - PF-133.
     *
     * Refused at entry rather than at posting, and refused rather than
     * booked at a rate chosen here. The alternatives are both worse than a
     * refusal: a rate of 1 asserts that two currencies are worth the same
     * and balances perfectly while doing it, and today's rate values a
     * January purchase at March's figure and leaves an exchange difference
     * nobody can derive from the document.
     *
     * The tax point is named because that is the date a rate has to be
     * found for - IAS 21 books a transaction at the rate on the date of the
     * transaction - and it is the date whoever fixes this has to load.
     */
    public static function noRateOnTheTaxPoint(
        string $currency,
        string $functional,
        string $taxPoint,
        string $underlying,
    ): self {
        return new self(sprintf(
            'This bill is in %s, the books are kept in %s, and there is no %s rate on or before its '.
            'tax point (%s). A purchase is booked at the rate on the date of the transaction, and for '.
            'a bill that date is the tax point - so the rate is loaded first rather than chosen here: '.
            'a rate of 1 would assert the two currencies are worth the same and balance while doing '.
            'it, and today\'s rate would value this purchase at today\'s figure and leave a '.
            'difference nobody can derive from the document. (%s)',
            $currency,
            $functional,
            $currency,
            $taxPoint,
            $underlying,
        ));
    }

    /**
     * A bill the posting rule was asked to translate without a rate - PF-133.
     *
     * The service fetches the rate at entry and freezes it on the document,
     * so reaching this means the stored rate went missing between entry and
     * posting. It refuses rather than falling back to the document's own
     * figures: those are the foreign-currency amounts, and posting them
     * under the functional currency's name balances and misstates the books
     * by the whole exchange difference.
     */
    public static function noRateToPostAt(string $currency, string $functional): self
    {
        return new self(sprintf(
            'This bill is in %s and the books are in %s, and the rate it was entered at is no longer '.
            'on the document. Posting the figures as they stand would record %s amounts as though '.
            'they were %s - which balances, so nothing would look wrong.',
            $currency,
            $functional,
            $currency,
            $functional,
        ));
    }

    /**
     * A residual too large to be rounding - PF-079.
     *
     * The rounding account exists for the hundredth of a unit that is left
     * when every line of a translated bill is rounded separately. A figure
     * far above that is not rounding: it is the arithmetic above it being
     * wrong by exactly that much, and plugging it would make the entry
     * balance while the books carried the error.
     *
     * The message names both figures, because the ratio is the diagnosis. A
     * residual of twice the ceiling is a rate or a scale; a residual of a
     * thousand times it is a whole amount in the wrong place.
     */
    public static function aResidualTooLargeToBeRounding(
        string $residual,
        string $ceiling,
        int $lines,
    ): self {
        return new self(sprintf(
            'This bill does not balance by %s, and the most that rounding %d line%s could leave is '.
            '%s. The difference is not rounding - something above it is wrong by that amount, and '.
            'putting it in the rounding account would balance the entry while leaving the error in '.
            'the books. Nothing has been posted.',
            $residual,
            $lines,
            $lines === 1 ? '' : 's',
            $ceiling,
        ));
    }

    public static function taxDoesNotTie(string $stated, string $computed): self
    {
        return new self(sprintf(
            'The bill states %s of tax and its lines come to %s. The supplier\'s own total is what '.
            'they will chase, and the breakdown is what the return is filed from: a difference means '.
            'one of the two is wrong and neither can be assumed.',
            $stated,
            $computed,
        ));
    }
}
