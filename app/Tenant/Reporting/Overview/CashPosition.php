<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Overview;

use Brick\Math\BigDecimal;

/**
 * Cash at bank: the posted balance of the `bank` control account and its
 * subtree, with one closing balance per day for the window - UX-032.
 *
 * "At bank" and not "cash": petty cash has no control role in the shipped
 * charts, and a tile that guessed which accounts count as cash would show a
 * figure nobody could reconcile.
 */
final readonly class CashPosition
{
    /**
     * @param  string  $balance  today's closing balance, a decimal string
     * @param  list<string>  $series  closing balance per day, oldest first
     * @param  ?string  $balanceThen  the closing balance `$days` ago, for the trend
     */
    public function __construct(
        public string $balance,
        public array $series,
        public ?string $balanceThen,
        public int $days,
        public bool $hasActivity,
    ) {}

    /**
     * The change since `$days` ago, as a percentage with one decimal - or
     * null when there is nothing to compare with (a zero base has no
     * percentage, and inventing one is how "∞%" ends up on a dashboard).
     */
    public function changePercent(): ?string
    {
        if ($this->balanceThen === null || BigDecimal::of($this->balanceThen)->isZero()) {
            return null;
        }

        $then = BigDecimal::of($this->balanceThen);

        return (string) BigDecimal::of($this->balance)
            ->minus($then)
            ->multipliedBy(100)
            ->dividedBy($then->abs(), 1, \Brick\Math\RoundingMode::HalfUp);
    }

    /** up, down or flat - the arrow, never a colour. */
    public function direction(): string
    {
        $change = $this->changePercent();

        if ($change === null) {
            return 'flat';
        }

        $sign = BigDecimal::of($change)->getSign();

        return $sign > 0 ? 'up' : ($sign < 0 ? 'down' : 'flat');
    }
}
