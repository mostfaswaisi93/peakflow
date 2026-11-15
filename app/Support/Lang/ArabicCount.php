<?php

declare(strict_types=1);

namespace App\Support\Lang;

use MessageFormatter;

/**
 * Counting things in Arabic - PF-051, PF-055, PF-016.
 *
 * ## Six forms, not four, and not two
 *
 * Arabic counts 1 as a singular, 2 as a dual, 3 to 10 with a broken plural in
 * the genitive, 11 to 99 with a singular in the accusative, and 100, 101, 102,
 * 200, 1000 with a singular in the GENITIVE - which is a different word form
 * again. And the rule for 3 to 10 is on the LAST TWO DIGITS, so 103 counts the
 * way 3 does and 111 counts the way 11 does.
 *
 * The shortcut every system takes is to pick one plural and append the number,
 * which gives `4 قيد` and `15 قيود` - both wrong, in opposite directions.
 *
 * This class took a longer shortcut and was still wrong. It offered four forms
 * and selected between them with hand-written ranges on the raw count, so:
 *
 *     ArabicCount::entries(103)  ->  103 قيدًا     and CLDR says `few`:  103 قيود
 *     ArabicCount::entries(100)  ->  100 قيدًا     and CLDR says `other`: 100 قيد
 *
 * Both measured. Both are the large numbers an accountant actually sees - a
 * statement line saying "103 قيدًا" reads to a native reader the way "103
 * entry" reads in English, and the first thought is that the software was
 * translated badly. Which is a reasonable conclusion and the wrong one to draw
 * about the figures beside it.
 *
 * ## ICU picks the category; the caller supplies the words
 *
 * The category is a fact about the locale and belongs to CLDR, which ICU
 * implements and which ships with PHP. The WORD FORMS are a fact about the
 * noun and only the caller knows them. Hand-written ranges were an attempt to
 * own both, and they got the half that was already solved wrong.
 *
 * So {@see category()} asks ICU, and there is no range arithmetic here at all.
 *
 * ## One implementation, because there were two
 *
 * The rules were written inline in {@see \App\Tenant\Ledger\Reporting\StatementBasis}
 * first, and the second place that needed them got them wrong. A grammar rule
 * duplicated is a grammar rule that is right in one place.
 */
final class ArabicCount
{
    /**
     * The six CLDR plural categories, in the order a translator meets them.
     *
     * @var list<string>
     */
    public const CATEGORIES = ['zero', 'one', 'two', 'few', 'many', 'other'];

    /**
     * Which CLDR plural category a count falls into.
     *
     * Asked of ICU rather than computed. The Arabic rules are `n = 0` → zero,
     * `n = 1` → one, `n = 2` → two, `n % 100 = 3..10` → few,
     * `n % 100 = 11..99` → many, and everything else → other - and the `% 100`
     * is the part hand-written code gets wrong, because 103 and 3 behave the
     * same way and nothing about 103 looks like 3.
     */
    public static function category(int $count, string $locale = 'ar'): string
    {
        $selected = MessageFormatter::formatMessage(
            $locale,
            '{n, plural, zero{zero} one{one} two{two} few{few} many{many} other{other}}',
            ['n' => $count],
        );

        /*
         | `other` on failure, not an exception.
         |
         | `other` is the category every locale has and the one a message
         | falls back to, so a formatter that could not answer produces the
         | reading that is merely imprecise rather than one that is missing.
         | A count is a label beside a figure; refusing to render it would
         | hide the figure.
         */
        return \is_string($selected) && \in_array($selected, self::CATEGORIES, true)
            ? $selected
            : 'other';
    }

    /**
     * The phrase for a count, given all six forms.
     *
     * `zero`, `one` and `two` are complete phrases and carry no numeral -
     * Arabic says `لا قيود`, `قيد واحد`, `قيدان`, never `0 قيود`. The other
     * three take the numeral, which is what `%d` in them is for; a form
     * without one is rendered as-is, so a caller that wants `مئة قيد` spelled
     * out can have it.
     *
     * @param  string  $zero  the phrase for none: `لا قيود`
     * @param  string  $one  the phrase for one: `قيد واحد`
     * @param  string  $two  the dual: `قيدان`
     * @param  string  $few  3-10 and 103-110: the broken plural, `قيود`
     * @param  string  $many  11-99 and 111-199: the accusative singular, `قيدًا`
     * @param  string  $other  100, 101, 102, 200, 1000: the genitive singular, `قيد`
     */
    public static function of(
        int $count,
        string $zero,
        string $one,
        string $two,
        string $few,
        string $many,
        string $other,
    ): string {
        $form = match (self::category($count)) {
            'zero' => $zero,
            'one' => $one,
            'two' => $two,
            'few' => $few,
            'many' => $many,
            default => $other,
        };

        return match (self::category($count)) {
            'zero', 'one', 'two' => $form,
            default => str_contains($form, '%d') ? sprintf($form, $count) : sprintf('%d %s', $count, $form),
        };
    }

    /** Lines of a journal entry or a file: سطر / سطران / أسطر. */
    public static function lines(int $count): string
    {
        return self::of($count, 'لا أسطر', 'سطر واحد', 'سطران', 'أسطر', 'سطرًا', 'سطر');
    }

    /** Journal entries: قيد / قيدان / قيود. */
    public static function entries(int $count): string
    {
        return self::of($count, 'لا قيود', 'قيد واحد', 'قيدان', 'قيود', 'قيدًا', 'قيد');
    }

    /** Adjusting entries, which name what they are: قيد تسوية. */
    public static function adjustingEntries(int $count): string
    {
        return self::of(
            $count,
            'لا قيود تسوية',
            'قيد تسوية واحد',
            'قيدَي تسوية',
            'قيود تسوية',
            'قيد تسوية',
            'قيد تسوية',
        );
    }

    /** Rows of a spreadsheet: صف / صفان / صفوف. */
    public static function rows(int $count): string
    {
        return self::of($count, 'لا صفوف', 'صف واحد', 'صفان', 'صفوف', 'صفًا', 'صف');
    }
}
