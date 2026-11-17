<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Overview;

use Brick\Math\BigDecimal;

/**
 * Open receivables by days past due - UX-032.
 *
 * The buckets are the ones ExpectedCreditLoss and PayablesAging already use
 * (current, 1-30, 31-60, 61-90, 90+), so the tile, the provision and the
 * payables report agree on what "61-90" means.
 */
final readonly class Ageing
{
    public const array BUCKETS = ['current', '1-30', '31-60', '61-90', '90+'];

    /**
     * @param  array{current: string, '1-30': string, '31-60': string, '61-90': string, '90+': string}  $buckets  decimal strings, in BUCKETS order
     */
    public function __construct(
        public array $buckets,
        public int $documents,
        public int $otherCurrencyDocuments,
    ) {}

    public function total(): string
    {
        $total = BigDecimal::zero();

        foreach ($this->buckets as $amount) {
            $total = $total->plus($amount);
        }

        return (string) $total->toScale(4);
    }

    /** Everything past its due date. */
    public function overdue(): string
    {
        return (string) BigDecimal::of($this->total())->minus($this->buckets['current'])->toScale(4);
    }

    /**
     * Each bucket's share of the total in whole percent, for the bar lengths
     * only - an integer, so no float comes near an amount.
     *
     * @return array<string, int>
     */
    public function shares(): array
    {
        $total = BigDecimal::of($this->total());
        $shares = [];

        foreach ($this->buckets as $bucket => $amount) {
            $shares[$bucket] = $total->isZero()
                ? 0
                : BigDecimal::of($amount)->multipliedBy(100)->dividedBy($total, 0, \Brick\Math\RoundingMode::HalfUp)->toInt();
        }

        return $shares;
    }
}
