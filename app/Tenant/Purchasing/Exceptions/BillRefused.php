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
