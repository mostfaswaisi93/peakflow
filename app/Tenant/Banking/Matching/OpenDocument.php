<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching;

use App\Support\Money\Money;
use Illuminate\Support\Carbon;

/**
 * A document with money still on it - PF-156.
 *
 * ## The outstanding figure is recomputed, never read off a column
 *
 * PF-156 asks for this by name, and the reason is that a stored
 * `paid_amount` is a second place the truth lives. It drifts the first time
 * a match is reversed, a credit note is cancelled, or two transactions are
 * matched to one invoice in the same second - and the symptom is an invoice
 * that reports itself settled while the customer is still being chased, or
 * one that keeps accepting payments after it is paid.
 *
 * So: gross, less credit notes, less every applied match. Three joins and
 * an honest number.
 */
final readonly class OpenDocument
{
    public function __construct(
        public string $type,
        public string $id,
        public string $number,
        public ?string $partyId,
        public ?string $partyName,
        public string $currency,
        public Money $gross,
        public Money $outstanding,
        public Carbon $documentDate,
        public ?Carbon $dueDate,
    ) {}

    /**
     * Whether an amount settles this document exactly.
     *
     * Compared on the outstanding figure rather than the gross: a customer
     * paying the second half of an invoice is paying it exactly, and an
     * engine that only recognised the full amount would send every
     * instalment to the fuzzy passes.
     */
    public function isSettledBy(Money $amount): bool
    {
        return $amount->abs()->amount->isEqualTo($this->outstanding->abs()->amount);
    }

    /** How far from settling it an amount is. */
    public function shortfallAgainst(Money $amount): Money
    {
        return $this->outstanding->abs()->minus($amount->abs());
    }

    /**
     * Whether an amount nearly settles this document - PF-158.
     *
     * Nearly, meaning the gap is real but small enough to be one of the
     * things PF-164 classifies: a bank charge the payer's bank deducted,
     * an exchange movement, tax withheld at source, a rounding unit.
     *
     * The tolerance decides what counts as **near**. It does not decide
     * that the gap is acceptable, and nothing here absorbs it: the
     * proposal matches what actually arrived and leaves the document
     * short by the difference, which is then classified and posted. A
     * pass that quietly settled the whole document for a smaller payment
     * would be writing the difference off by arithmetic, with nobody
     * having named it or decided it.
     */
    public function isNearlySettledBy(Money $amount, Money $tolerance): bool
    {
        $gap = $this->shortfallAgainst($amount)->abs();

        return ! $gap->amount->isZero()
            && ! $gap->amount->isGreaterThan($tolerance->abs()->amount);
    }

    /**
     * Which direction a transaction settling this document must run.
     *
     * An invoice is money coming in; a bill is money going out. Matching a
     * receipt against a bill is arithmetically possible and always wrong,
     * and it is the mistake an amount-only pass makes on the day a customer
     * and a supplier are owed the same figure.
     */
    public function expectsACredit(): bool
    {
        return $this->type === 'invoice';
    }

    public function label(): string
    {
        return sprintf('%s %s', $this->type === 'invoice' ? 'invoice' : 'bill', $this->number);
    }
}
