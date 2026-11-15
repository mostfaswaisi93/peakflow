<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use App\Support\Money\Money;
use JsonSerializable;

/**
 * One box of a return, with the lines behind it - PF-086.
 *
 * ## Two figures, because a return box has two
 *
 * Every real form asks for the taxable amount AND the tax, per box. A box
 * carrying only the tax cannot be checked against anything: the authority's
 * own arithmetic is amount times rate, and a figure with no base is a figure
 * nobody can verify - including us.
 *
 * ## The line ids are the drill-down, and they are part of the filing
 *
 * Not a convenience for a screen. A query from an authority asks why a box
 * says what it says, years later, by which time the period has been adjusted,
 * reversed and re-reported. The ids are what makes the answer possible, and
 * they are frozen with the figures rather than re-derived from a ledger that
 * has moved on.
 */
final readonly class TaxReturnBox implements JsonSerializable
{
    /** @param  list<array{id: string, tax_point: string, amount: string}>  $lines */
    public function __construct(
        public string $box,
        public string $side,
        public string $label,
        public Money $net,
        public Money $tax,
        public array $lines = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->net->isZero() && $this->tax->isZero();
    }

    public function lineCount(): int
    {
        return \count($this->lines);
    }

    /**
     * A box with tax and no taxable amount.
     *
     * Reported rather than hidden: it means the tax legs of a document were
     * posted and the revenue or expense leg was not, which is a document
     * half-posted - and the return would be arithmetically indefensible while
     * looking complete.
     */
    public function hasTaxWithoutABase(): bool
    {
        return ! $this->tax->isZero() && $this->net->isZero();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'box' => $this->box,
            'side' => $this->side,
            'net' => (string) $this->net,
            'tax' => (string) $this->tax,
            'lines' => $this->lineCount(),
        ];
    }
}
