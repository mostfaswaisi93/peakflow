<?php

declare(strict_types=1);

namespace App\Support\Text;

/**
 * The character set a payment scheme permits, and how to get there - PF-176.
 *
 * ## The EPC restricted Latin set
 *
 * Letters `a-z A-Z`, digits `0-9`, space, and `/ - ? : ( ) . , ' +`. That
 * is all. No diacritics, no ampersand, no quotation marks, no Arabic.
 *
 * A file containing anything else is rejected as a whole, hours later, by
 * an email naming an XPath. So the conversion happens before the file is
 * built, and **what will be sent is shown** - because a supplier whose
 * name arrives on their statement as `Mller` has been mangled by us, and
 * they will ask about it.
 *
 * ## Transliteration is lossy, and says so
 *
 * `Ü` becomes `UE`, `ø` becomes `OE`, `ß` becomes `SS`. Those are the
 * conventional expansions and they are what a German or Danish
 * counterparty expects to see.
 *
 * Arabic is harder and the loss is real: Arabic script does not write
 * short vowels, so `محمد` can only become `MHMD` by a strict letter
 * mapping or `MUHAMMAD` by guessing the vowels. This class does the
 * first and never the second. A guessed vowel is a different name, and
 * the beneficiary bank matching a name against their records is the one
 * who decides whether it is close enough - so the honest output is the
 * consonant skeleton, declared as lossy, with the original shown beside
 * it so a person can override it.
 *
 * ## Why not `iconv('ASCII//TRANSLIT')`
 *
 * Its output depends on the locale and the libc build: the same name
 * transliterates differently on a developer's machine and in production,
 * which for a payment file means the bytes that were hashed are not the
 * bytes that go out. It also drops what it cannot map, silently.
 *
 * The table below is explicit, has no locale, and is the same on every
 * machine - which is what a hashed file requires.
 */
final class RestrictedLatin
{
    /** The EPC set: letters, digits, space, and these. */
    public const PERMITTED = "/-?:().,'+";

    /**
     * Latin expansions, before any letter is stripped.
     *
     * Ordered by what a counterparty expects to see on their statement
     * rather than by what is shortest: a German supplier reads `UE` as
     * their own name and `U` as a typo.
     *
     * @var array<string, string>
     */
    private const LATIN = [
        'Æ' => 'AE', 'æ' => 'ae',
        'Ø' => 'OE', 'ø' => 'oe',
        'Å' => 'AA', 'å' => 'aa',
        'Ä' => 'AE', 'ä' => 'ae',
        'Ö' => 'OE', 'ö' => 'oe',
        'Ü' => 'UE', 'ü' => 'ue',
        'ß' => 'ss',
        'Þ' => 'TH', 'þ' => 'th',
        'Ð' => 'D', 'ð' => 'd',
        'Œ' => 'OE', 'œ' => 'oe',
        'Ł' => 'L', 'ł' => 'l',
        'Đ' => 'D', 'đ' => 'd',
        'Ħ' => 'H', 'ħ' => 'h',
        'ı' => 'i', 'İ' => 'I',
        'Ŋ' => 'N', 'ŋ' => 'n',
    ];

    /**
     * Letters that carry a mark, mapped to the letter.
     *
     * Separate from {@see LATIN} because these are unambiguous: `é` is an
     * `e` with an accent and every scheme's answer is `E`. The expansions
     * above are conventions about pairs of letters, which is a different
     * kind of decision.
     *
     * @var array<string, string>
     */
    private const MARKS = [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ā' => 'A', 'Ă' => 'A', 'Ą' => 'A',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'Ç' => 'C', 'Ć' => 'C', 'Č' => 'C', 'Ĉ' => 'C', 'Ċ' => 'C',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c', 'ċ' => 'c',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ē' => 'E', 'Ė' => 'E', 'Ę' => 'E', 'Ě' => 'E',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'Ĝ' => 'G', 'Ğ' => 'G', 'Ġ' => 'G', 'Ģ' => 'G',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'Ĥ' => 'H', 'ĥ' => 'h',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ĩ' => 'I', 'Ī' => 'I', 'Į' => 'I',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'į' => 'i',
        'Ĵ' => 'J', 'ĵ' => 'j',
        'Ķ' => 'K', 'ķ' => 'k',
        'Ĺ' => 'L', 'Ļ' => 'L', 'Ľ' => 'L',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l',
        'Ñ' => 'N', 'Ń' => 'N', 'Ņ' => 'N', 'Ň' => 'N',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ō' => 'O', 'Ŏ' => 'O', 'Ő' => 'O',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
        'Ŕ' => 'R', 'Ŗ' => 'R', 'Ř' => 'R',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
        'Ś' => 'S', 'Ŝ' => 'S', 'Ş' => 'S', 'Š' => 'S',
        'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's',
        'Ţ' => 'T', 'Ť' => 'T', 'Ŧ' => 'T',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ũ' => 'U', 'Ū' => 'U', 'Ŭ' => 'U', 'Ů' => 'U', 'Ű' => 'U', 'Ų' => 'U',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'Ŵ' => 'W', 'ŵ' => 'w',
        'Ý' => 'Y', 'Ŷ' => 'Y', 'Ÿ' => 'Y',
        'ý' => 'y', 'ŷ' => 'y', 'ÿ' => 'y',
        'Ź' => 'Z', 'Ż' => 'Z', 'Ž' => 'Z',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    /**
     * Arabic letters to Latin consonants.
     *
     * A strict letter mapping and nothing more. Arabic script does not
     * write short vowels, so a reading that inserts them is a guess about
     * which name this is - and the beneficiary bank comparing the name
     * against their own records is who decides whether it matches. A
     * wrong guess is a different name; a consonant skeleton is the same
     * name, written badly.
     *
     * The emphatic and non-emphatic pairs collapse (`س` and `ص` both to
     * `S`), because the distinction cannot be written in the permitted
     * set without diacritics and `S'` would read as an apostrophe to
     * every system downstream.
     *
     * @var array<string, string>
     */
    private const ARABIC = [
        'ء' => '', 'آ' => 'A', 'أ' => 'A', 'ؤ' => 'W', 'إ' => 'A', 'ئ' => 'Y', 'ا' => 'A',
        'ب' => 'B', 'ة' => 'H', 'ت' => 'T', 'ث' => 'TH', 'ج' => 'J', 'ح' => 'H', 'خ' => 'KH',
        'د' => 'D', 'ذ' => 'DH', 'ر' => 'R', 'ز' => 'Z', 'س' => 'S', 'ش' => 'SH', 'ص' => 'S',
        'ض' => 'D', 'ط' => 'T', 'ظ' => 'Z', 'ع' => 'A', 'غ' => 'GH', 'ف' => 'F', 'ق' => 'Q',
        'ك' => 'K', 'ل' => 'L', 'م' => 'M', 'ن' => 'N', 'ه' => 'H', 'و' => 'W', 'ى' => 'A',
        'ي' => 'Y',
        // Persian and Urdu letters that appear in Gulf trade names.
        'پ' => 'P', 'چ' => 'CH', 'ژ' => 'ZH', 'ک' => 'K', 'گ' => 'G', 'ی' => 'Y',
        // Arabic-Indic digits, which a scheme's digit class does not accept.
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * Characters with a conventional replacement rather than a letter.
     *
     * `&` is the one that matters: it is forbidden, it is extremely
     * common in trade names, and dropping it turns "Smith & Sons" into
     * "Smith  Sons". `AND` is what the counterparty expects.
     *
     * @var array<string, string>
     */
    private const PUNCTUATION = [
        '&' => ' AND ',
        '@' => ' AT ',
        '"' => "'",
        '«' => "'", '»' => "'",
        '“' => "'", '”' => "'", '‘' => "'", '’' => "'",
        '–' => '-', '—' => '-', '‑' => '-',
        '…' => '.',
        '€' => 'EUR', '£' => 'GBP', '$' => 'USD', '﷼' => 'SAR',
        '%' => ' PCT ',
        '*' => '-', '#' => '-', '_' => '-', '|' => '-', '\\' => '/',
        '[' => '(', ']' => ')', '{' => '(', '}' => ')',
        '<' => '(', '>' => ')',
        '!' => '.', ';' => ',', '=' => '-', '~' => '-', '^' => '-',
        '¡' => '.', '¿' => '?',
        '°' => ' DEG ',
    ];

    /**
     * Convert a value to what will actually be sent.
     *
     * @return array{
     *     sent: string,
     *     original: string,
     *     changed: bool,
     *     lossy: bool,
     *     dropped: list<string>,
     *     why: string,
     * }
     */
    public static function convert(string $value, ?int $limit = null): array
    {
        $original = $value;
        $lossy = false;

        $out = strtr($value, self::PUNCTUATION);
        $out = strtr($out, self::LATIN);
        $out = strtr($out, self::MARKS);

        if (self::holdsArabic($out)) {
            /*
             | Lossy, and declared as such.
             |
             | Arabic script does not write short vowels, so the result is
             | the consonant skeleton of the name rather than a reading of
             | it. Somebody has to be able to see that and override it.
             */
            $lossy = true;
            $out = strtr($out, self::ARABIC);
        }

        /*
         | Whatever is left and not permitted is dropped, and named.
         |
         | Named, because a character this table does not know is a gap in
         | the table - and silently dropping it is how a product comes to
         | send a name nobody recognises for two years.
         */
        $dropped = [];
        $kept = '';

        foreach (preg_split('//u', $out, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if (self::isPermitted($character)) {
                $kept .= $character;

                continue;
            }

            $dropped[] = $character;
            $lossy = true;
        }

        /*
         | Collapse the runs the replacements left behind.
         |
         | ' AND ' between two words that already had spaces produces
         | three, and a name with three spaces in it reads as a mistake on
         | a bank statement.
         */
        $kept = trim((string) preg_replace('/ {2,}/', ' ', $kept));
        $truncated = false;

        if ($limit !== null && mb_strlen($kept) > $limit) {
            $kept = rtrim(mb_substr($kept, 0, $limit));
            $truncated = true;
            $lossy = true;
        }

        return [
            'sent' => $kept,
            'original' => $original,
            'changed' => $kept !== $original,
            'lossy' => $lossy,
            'dropped' => array_values(array_unique($dropped)),
            'why' => self::explain($original, $kept, $lossy, $dropped, $truncated, $limit),
        ];
    }

    /** Whether a value is already inside the permitted set. */
    public static function isClean(string $value): bool
    {
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if (! self::isPermitted($character)) {
                return false;
            }
        }

        return true;
    }

    private static function isPermitted(string $character): bool
    {
        return $character === ' '
            || (strlen($character) === 1 && (ctype_alnum($character) || str_contains(self::PERMITTED, $character)));
    }

    private static function holdsArabic(string $value): bool
    {
        return preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $value) === 1;
    }

    /** @param list<string> $dropped */
    private static function explain(
        string $original,
        string $sent,
        bool $lossy,
        array $dropped,
        bool $truncated,
        ?int $limit,
    ): string {
        if ($sent === $original) {
            return 'this is sent exactly as it is written';
        }

        $parts = [sprintf('"%s" will be sent as "%s"', $original, $sent)];

        if (self::holdsArabic($original)) {
            $parts[] = 'Arabic script does not write short vowels, so this is the name\'s consonants and '.
                'not a reading of it - check it against what the beneficiary\'s bank holds, and override '.
                'it if they expect something else';
        }

        if ($dropped !== []) {
            $parts[] = sprintf(
                'these characters have no equivalent in the scheme\'s character set and were removed: %s',
                implode(' ', array_map(static fn (string $c): string => '"'.$c.'"', $dropped)),
            );
        }

        if ($truncated) {
            $parts[] = sprintf('it was cut to the %d characters the scheme allows', (int) $limit);
        }

        if (! $lossy) {
            $parts[] = 'nothing was lost - the substitutions are the conventional ones and read as the '.
                'original to the counterparty';
        }

        return implode('. ', $parts).'.';
    }
}
