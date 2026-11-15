<?php

declare(strict_types=1);

namespace App\Support\Money;

/**
 * Where tax is rounded: on each line, or once per rate group.
 *
 * ## The third element of a rounding policy, and the one that gets forgotten
 *
 * A mode and a scale are not enough to make two systems agree on a document's
 * tax. Three lines of 10.03 at 15% are 4.51 rounded once on their 30.09 total,
 * and 4.50 rounded line by line - 1.50 + 1.50 + 1.50. Same mode, same scale,
 * a different answer, and the difference lands on a return.
 *
 * EN 16931 settles it for a conformant invoice: `BR-CO-14` makes the VAT
 * category tax amount the category's taxable amount multiplied by its rate -
 * once per rate group, not per line. A system that sums rounded line tax
 * produces a document that fails validation at the recipient.
 *
 * ## Per-line is not a mistake everywhere
 *
 * Some regimes compute and show tax per line, and some invoice layouts have to
 * display it that way even where the total is computed per group. So this is
 * configuration rather than a constant, and a jurisdiction that differs is a
 * row.
 *
 * @see EN 16931 BR-CO-14
 */
enum RoundingBasis: string
{
    /**
     * Once per rate group. EN 16931's answer, and the default.
     *
     * The taxable amounts of every line sharing a rate are summed first, and
     * the tax is computed and rounded once on that sum.
     */
    case PerRateGroup = 'per_rate_group';

    /**
     * On each line, then summed.
     *
     * Produces a total that can differ from the per-group figure by a cent per
     * line, in either direction, and the drift is not systematic - which is
     * why it cannot be corrected afterwards by a rounding adjustment.
     */
    case PerLine = 'per_line';

    public function label(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::PerRateGroup => $arabic ? 'لكل مجموعة نسبة' : 'once per rate group',
            self::PerLine => $arabic ? 'لكل سطر' : 'on each line',
        };
    }

    public static function fromName(string $name): self
    {
        return self::tryFrom(strtolower(trim($name)))
            ?? throw Exceptions\UnknownRoundingBasis::name($name);
    }
}
