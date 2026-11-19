<?php

declare(strict_types=1);

namespace App\Support\Ui;

/**
 * How tightly the interface is packed - PF-203.
 *
 * ## A user setting, not a breakpoint
 *
 * An accountant on a 27-inch monitor wants forty rows on screen; somebody
 * entering a document on a laptop wants room to see what they are typing.
 * Those are different people on the same screen size, so a media query
 * answers the wrong question: it asks how big the display is and the person
 * is telling you how they work.
 *
 * Which is also why there are three and not two. Two modes make the middle
 * one a compromise nobody chose; three let the default be the one most
 * people want and the other two be real positions.
 *
 * ## `compact` is the default, deliberately
 *
 * The brief for a financial application is legibility and velocity, and the
 * screens are tables. 40px rows put about twenty-five rows of a list on a
 * laptop, which is the density the product was designed at - `comfortable`
 * is the departure for somebody who wants the room, not the starting point.
 *
 * ## What bounds `dense`
 *
 * WCAG 2.2 (2.5.8): a target stays within 24x24 CSS pixels in every mode.
 * `dense` is 32px rows and a 28px control, and it cannot go lower without
 * breaking that - which is the constraint that decides how dense dense is,
 * rather than taste. `bin/verify design-system` measures every mode's
 * tokens against the 24px floor.
 *
 * The values themselves live in `resources/css/app.css` under
 * `[data-density='...']`, as tokens: row height, the two paddings, the
 * control height and the font step. Nothing else changes, and nothing
 * outside those blocks knows which mode is on.
 */
enum Density: string
{
    case Comfortable = 'comfortable';
    case Compact = 'compact';
    case Dense = 'dense';

    public static function default(): self
    {
        return self::Compact;
    }

    /**
     * Anything unknown, missing or malformed is the default.
     *
     * A display preference must never be able to stop a page from
     * rendering, and a mode that was removed between two releases is
     * exactly the value that would.
     */
    public static function fromPreference(mixed $value): self
    {
        return \is_string($value) ? (self::tryFrom($value) ?? self::default()) : self::default();
    }

    /** The Arabic source string of the mode's name; translate it with `__()`. */
    public function label(): string
    {
        return match ($this) {
            self::Comfortable => 'مريح',
            self::Compact => 'مدمج',
            self::Dense => 'كثيف',
        };
    }

    /**
     * How many rows of a list this mode shows in a 900px-high viewport,
     * rounded down - which is the only description of a density that means
     * anything to the person choosing one. "Compact" is a word; "about 25
     * rows" is the decision they are making.
     */
    public function rowsPerScreen(): int
    {
        return match ($this) {
            self::Comfortable => 18,
            self::Compact => 22,
            self::Dense => 28,
        };
    }
}
