<?php

declare(strict_types=1);

namespace App\Support\Lang;

use App\Support\Iso\LanguageRegistry;

/**
 * The one place a locale gets its digit shape - PF-007.
 *
 * ## Why this is a class and not two lines inlined where they are needed
 *
 * Because there were two lines inlined where they were needed, and a second
 * copy was about to be written. `Money` appended `-u-nu-arab` from
 * `config('money.display.digits')`; the language registry knew that Arabic's
 * own numbering system is `arab`. Both are true and they are different
 * questions, and a quantity formatter written later would have picked whichever
 * one its author found first.
 *
 * The failure that produces is a document showing a quantity as `١٢` and its
 * line total as `123.45` - on the same row. Nobody reads that as a styling
 * inconsistency. They read it as a document that was assembled by something
 * that does not know what it is doing, and they are not wrong.
 *
 * ## Which question this answers
 *
 * "What digits should this locale's numbers be rendered in?" - once, for
 * money, quantities, dates, percentages and document fields alike.
 *
 * The answer is NOT the locale's natural numbering system. Financial documents
 * across the region conventionally use Western digits even in Arabic text, so
 * the configured default is `latn` and the deliberate departure from CLDR is
 * recorded in `config/money.php`. The locale's natural system
 * ({@see LanguageRegistry::naturalNumbering()}) is what that setting departs
 * FROM, which is why both exist and why only one of them reaches a formatter.
 *
 * An architecture rule asserts that `-u-nu-` appears in exactly one source
 * file. That rule is the control: the next formatter has to come here, and
 * coming here is what keeps one page's numbers written one way.
 */
final class NumberingSystem
{
    /**
     * The three numbering systems, and why `arab` and `arabext` are not one.
     *
     *   latn     0123456789   Latin, what most of the world reads
     *   arab     ٠١٢٣٤٥٦٧٨٩   Arabic-Indic, used across the Gulf and the Levant
     *   arabext  ۰۱۲۳۴۵۶۷۸۹   Extended Arabic-Indic (Persian, Urdu)
     *
     * The last two are routinely treated as the same thing and are not. A
     * Saudi statement rendered in `arabext` is wrong in a way that reads as a
     * font problem, so nobody files it as a data bug and it is never fixed.
     *
     * @var list<string>
     */
    public const SYSTEMS = ['latn', 'arab', 'arabext'];

    /** The names a tenant's own digit-shape setting uses, mapped to ICU's. */
    private const SHAPES = [
        'western' => 'latn',
        'latn' => 'latn',
        'arabic_indic' => 'arab',
        'arab' => 'arab',
        'eastern_arabic_indic' => 'arabext',
        'arabext' => 'arabext',
    ];

    /**
     * The ICU locale to hand a formatter: the tag plus its numbering system.
     *
     * ## `latn` is appended, not skipped
     *
     * This is the whole reason the class exists, and it was got wrong here
     * first. Skipping the subtag when the answer is `latn` looks like an
     * obvious simplification - `latn` is "the normal digits", so why say it -
     * and it is wrong for every locale whose CLDR default is something else.
     * Measured: `NumberFormatter('ar')` renders 1234.56 as `١٬٢٣٤٫٥٦`, so a
     * configuration that says `latn` and a formatter handed a bare `ar`
     * produce Arabic-Indic digits on an invoice that asked for Western ones.
     *
     * The subtag is therefore always written out. An explicit no-op is the
     * difference between a setting that works and a setting that appears to.
     *
     * A tag that already carries a Unicode extension or a legacy `@keyword`
     * is returned untouched: a caller that spelled out `ar-u-nu-arab` has
     * answered the question itself, and overriding it would make the explicit
     * form the one thing that does not work.
     */
    public static function apply(string $locale, ?string $shape = null): string
    {
        if (str_contains($locale, '-u-') || str_contains($locale, '@')) {
            return $locale;
        }

        $digits = $shape !== null ? self::normalise($shape) : self::configured($locale);

        return $digits === '' ? $locale : $locale.'-u-nu-'.$digits;
    }

    /**
     * A caller's own name for a digit shape, resolved to ICU's.
     *
     * An unrecognised name resolves to `latn` rather than throwing. The
     * callers are a tenant setting and a configuration file, and the choice
     * is between a document rendered in Western digits and a document that
     * does not render - and the first is both recoverable and the one the
     * platform default asks for anyway.
     */
    public static function normalise(string $shape): string
    {
        return self::SHAPES[strtolower(trim($shape))] ?? 'latn';
    }

    /**
     * The configured digit shape, falling back to the locale's own.
     *
     * The fallback matters in a console process or a unit check where no
     * configuration is loaded: without it the answer would be `latn` by
     * accident rather than by decision, and a test asserting Arabic-Indic
     * output would pass or fail depending on how it was run.
     */
    public static function configured(string $locale): string
    {
        $configured = \function_exists('config') ? config('money.display.digits') : null;

        if (\is_string($configured) && $configured !== '') {
            return self::normalise($configured);
        }

        return LanguageRegistry::naturalNumbering(self::baseTag($locale));
    }

    /** `ar-JO` and `ar_JO` both have the base tag `ar`. */
    public static function baseTag(string $locale): string
    {
        $base = preg_split('/[-_@.]/', $locale);

        return \is_array($base) && $base[0] !== '' ? $base[0] : $locale;
    }
}
