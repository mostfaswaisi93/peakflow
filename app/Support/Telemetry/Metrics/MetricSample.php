<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Metrics;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * One labelled figure - PF-241.
 *
 * ## The value is an exact decimal STRING, never a float
 *
 * Because half of these figures are money. A suspense balance of 1,234.56
 * read into a float and summed across four entities disagrees with the
 * general ledger at the fourth decimal, and a dashboard that disagrees with
 * the ledger is worse than no dashboard: somebody reconciles to it.
 *
 * The same rule as a posting, for the same reason, and `bin/verify arch`
 * scans for the float that would break it.
 *
 * ## Labels are classifications, never identifiers of a person or a document
 *
 * A metric labelled by customer id is a customer list on whoever's dashboard,
 * retained for weeks, on infrastructure with none of the controls customer
 * data needs. It is also unusable: a gauge with one series per party is a
 * chart a browser cannot draw.
 *
 * `Metrics::register()` enforces both halves - the name must be on the log
 * allowlist in `config/telemetry.php`, and it must not be one of the
 * per-record identifiers that allowlist legitimately carries for a LOG line.
 */
final readonly class MetricSample
{
    /**
     * @param  array<string, string|int>  $labels
     */
    private function __construct(
        public array $labels,
        public string $value,
    ) {}

    /** @param array<string, string|int> $labels */
    public static function count(array $labels, int $count): self
    {
        return new self($labels, (string) $count);
    }

    /**
     * A money figure, at the scale every other amount in this product uses.
     *
     * `toScale(4, RoundingMode::HalfUp)` and not a cast: the figure arrives
     * from a `numeric` column as a string and stays one, so the dashboard's
     * total and the trial balance's are the same number rather than two
     * roundings of it.
     *
     * @param  array<string, string|int>  $labels
     */
    public static function amount(array $labels, BigDecimal|string $amount): self
    {
        return new self(
            $labels,
            (string) BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp),
        );
    }

    /** @param array<string, string|int> $labels */
    public static function days(array $labels, int $days): self
    {
        return new self($labels, (string) $days);
    }

    public function labelled(): string
    {
        if ($this->labels === []) {
            return '-';
        }

        $parts = [];

        foreach ($this->labels as $name => $value) {
            $parts[] = $name.'='.$value;
        }

        return implode(' ', $parts);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['labels' => $this->labels, 'observed' => $this->value];
    }
}
