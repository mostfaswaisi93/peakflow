<?php

declare(strict_types=1);

namespace App\Support\Lang;

/**
 * Counting things in Arabic - PF-051, PF-055.
 *
 * ## Four forms, not two
 *
 * Arabic counts 1 as a singular, 2 as a dual, 3 to 10 with a broken plural in
 * the genitive, and 11 upwards with a singular again in the accusative. The
 * shortcut every system takes is to pick one plural and append the number,
 * which gives `4 قيد` and `15 قيود` - both wrong, in opposite ways.
 *
 * It matters because these sentences are read by accountants and auditors. A
 * statement that says `3 قيد تسوية` reads the way "3 entry" reads in English,
 * and the reader's first thought is that the software was translated badly -
 * which is a reasonable thing to conclude and the wrong thing to conclude
 * about the figures beside it.
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
     * @param  string  $singular  the form after 1: "قيد"
     * @param  string  $dual  the form for 2: "قيدان" or "قيدين" by position
     * @param  string  $plural  the broken plural for 3-10: "قيود"
     * @param  string|null  $accusative  the form after 11+, if it differs from
     *                                   the singular - usually it does not
     */
    public static function of(
        int $count,
        string $singular,
        string $dual,
        string $plural,
        ?string $accusative = null,
    ): string {
        return match (true) {
            $count === 0 => sprintf('لا %s', $plural),
            $count === 1 => $singular,
            $count === 2 => $dual,
            $count <= 10 => sprintf('%d %s', $count, $plural),
            default => sprintf('%d %s', $count, $accusative ?? $singular),
        };
    }

    /** Lines of a journal entry or a file: سطر / سطران / أسطر. */
    public static function lines(int $count): string
    {
        return self::of($count, 'سطر واحد', 'سطران', 'أسطر', 'سطرًا');
    }

    /** Journal entries: قيد / قيدان / قيود. */
    public static function entries(int $count): string
    {
        return self::of($count, 'قيد واحد', 'قيدان', 'قيود', 'قيدًا');
    }

    /** Adjusting entries, which name what they are: قيد تسوية. */
    public static function adjustingEntries(int $count): string
    {
        return self::of($count, 'قيد تسوية واحد', 'قيدَي تسوية', 'قيود تسوية', 'قيد تسوية');
    }

    /** Rows of a spreadsheet: صف / صفان / صفوف. */
    public static function rows(int $count): string
    {
        return self::of($count, 'صف واحد', 'صفان', 'صفوف', 'صفًا');
    }
}
