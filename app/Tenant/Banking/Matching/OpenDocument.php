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
