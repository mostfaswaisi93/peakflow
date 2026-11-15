<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Iso\LanguageRegistry;
use App\Support\Lang\ArabicCount;
use App\Support\Lang\Exceptions\MessageCannotBeFormatted;
use App\Support\Lang\Message;
use MessageFormatter;
use Throwable;

/**
 * ICU MessageFormat, and the sentences Arabic will not let you assemble - PF-016.
 *
 * ## What is actually being claimed
 *
 * That a sentence with a number in it is grammatical in both locales. Not
 * "translated": a translated sentence assembled out of fragments is wrong in
 * Arabic in a way only an Arabic reader notices, and the reader is an
 * accountant looking at a figure beside it.
 *
 * ## Why the mechanism had to change
 *
 * Laravel's `trans_choice()` takes a pipe-separated line with ranges:
 *
 *     {0} لا قيود|[1,1] قيد واحد|[2,2] قيدان|[3,10] قيود|[11,*] قيدًا
 *
 * which looks like it covers Arabic and does not, because the Arabic rule is
 * on the LAST TWO DIGITS. No range expression can say `n % 100 = 3..10`, so
 * 103 gets the form that belongs to 11 and nothing anywhere says so.
 *
 * CLDR defines the categories, ICU implements them, ICU ships with PHP. So the
 * categories are asked of ICU and the pipe syntax is forbidden.
 */
final class LocalizationRules
{
    /**
     * The expected Arabic phrase at each category boundary.
     *
     * Written out, from the grammar, rather than derived from the code. The
     * interesting rows are 100 and 103: 100 takes the genitive singular and
     * 103 takes the broken plural, because the rule is on the last two digits.
     *
     * @var array<int, string>
     */
    private const EXPECTED_ENTRIES = [
        0 => 'لا قيود',
        1 => 'قيد واحد',
        2 => 'قيدان',
        3 => '3 قيود',
        10 => '10 قيود',
        11 => '11 قيدًا',
        99 => '99 قيدًا',
        100 => '100 قيد',
        101 => '101 قيد',
        102 => '102 قيد',
        103 => '103 قيود',
        110 => '110 قيود',
        111 => '111 قيدًا',
        200 => '200 قيد',
        203 => '203 قيود',
        1003 => '1003 قيود',
    ];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'Arabic uses all six forms, including the ones past 100' => $this->arabicUsesAllSixForms(),
            'the plural category is asked of ICU, not computed' => $this->theCategoryComesFromIcu(),
            'a message\'s arguments are parsed, not pattern-matched' => $this->argumentsAreParsed(),
            'a message that cannot render is refused rather than shown' => $this->aBrokenMessageIsRefused(),
            'every ICU message in the catalogs renders in every locale' => $this->catalogMessagesRender(),
            'the catalogs carry the same ICU keys with each locale\'s categories' => $this->catalogsAgree(),
            'nothing uses the pipe-syntax pluraliser' => $this->nothingUsesTransChoice(),
            'no translated string is concatenated with a value' => $this->noTranslationIsConcatenated(),
        ];
    }

    /** @return list<string> */
    private function arabicUsesAllSixForms(): array
    {
        $problems = [];

        foreach (self::EXPECTED_ENTRIES as $count => $expected) {
            $actual = ArabicCount::entries($count);

            if ($actual !== $expected) {
                $problems[] = sprintf(
                    'ArabicCount::entries(%d) gave "%s" and Arabic wants "%s". %s',
                    $count,
                    $actual,
                    $expected,
                    $count >= 100
                        ? 'The rule is on the LAST TWO DIGITS, which is the part hand-written ranges get '.
                          'wrong - nothing about 103 looks like 3.'
                        : '',
                );
            }
        }

        /*
         | Six distinct forms must actually be reachable.
         |
         | A helper that returned the same word for `many` and `other` would
         | pass every row above if the rows happened to agree, and would be
         | back to four forms. These are the two that the previous version
         | conflated.
         */
        if (ArabicCount::entries(11) === ArabicCount::entries(100)) {
            $problems[] = sprintf(
                '11 and 100 render the same way ("%s"). 11 takes the accusative singular and 100 takes '.
                'the genitive - a different word form - and conflating them is how this helper was wrong '.
                'for every number from 100 up.',
                ArabicCount::entries(11),
            );
        }

        if (\count(array_unique(array_map(ArabicCount::entries(...), [0, 1, 2, 3, 11, 100]))) !== 6) {
            $problems[] = 'The six category boundaries do not produce six distinct phrases, so at least '.
                'two categories share a word form.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theCategoryComesFromIcu(): array
    {
        $problems = [];
        $pattern = '{n, plural, zero{zero} one{one} two{two} few{few} many{many} other{other}}';

        for ($n = 0; $n <= 1200; $n++) {
            $icu = MessageFormatter::formatMessage('ar', $pattern, ['n' => $n]);
            $ours = ArabicCount::category($n);

            if ($icu !== $ours) {
                $problems[] = sprintf('%d: ICU says %s and the helper says %s.', $n, (string) $icu, $ours);

                if (\count($problems) > 4) {
                    break;
                }
            }
        }

        /*
         | And the hand-written rule this replaced must still disagree.
         |
         | If a naive `<= 10` range happened to match CLDR, asking ICU would
         | be ceremony and this rule would be asserting nothing. It does not
         | match: at 103 the range says "11 and up" and CLDR says `few`.
         */
        $naive = static fn (int $n): string => match (true) {
            $n === 0 => 'zero',
            $n === 1 => 'one',
            $n === 2 => 'two',
            $n <= 10 => 'few',
            default => 'many',
        };

        $disagreements = 0;

        foreach ([100, 103, 110, 111, 203, 1003] as $n) {
            if ($naive($n) !== ArabicCount::category($n)) {
                $disagreements++;
            }
        }

        if ($disagreements === 0) {
            $problems[] = 'A hand-written range rule now agrees with CLDR at every boundary tested, which '.
                'would make asking ICU unnecessary - and this rule a test of nothing.';
        }

        return $problems;
    }

    /**
     * The argument scanner, against the case that broke the first one.
     *
     * `{bad, plural, one{has} other{have}}` - the branch bodies look exactly
     * like placeholders, and a regular expression demanded arguments called
     * `has` and `have`. The message could then not be rendered at all.
     *
     * @return list<string>
     */
    private function argumentsAreParsed(): array
    {
        $problems = [];

        $cases = [
            '{count, plural, zero{لا} one{واحد} few{# قيود} other{# قيد}}' => ['count'],
            '{bad, plural, one{has} other{have}}' => ['bad'],
            '{count, plural, one{{name} واحد} other{# لـ {name}}}' => ['count', 'name'],
            'مرحبا {name}' => ['name'],
            '{a} و {b} و {c, number}' => ['a', 'b', 'c'],
            "JSON مثل '{'count'}' حرفي" => [],
            '{a, select, x{{b, plural, one{{c}} other{#}}} other{-}}' => ['a', 'b', 'c'],
            'لا وسائط هنا' => [],
        ];

        foreach ($cases as $pattern => $expected) {
            $actual = Message::argumentsIn($pattern);

            if ($actual !== $expected) {
                $problems[] = sprintf(
                    'The arguments of "%s" were read as [%s] and are [%s]. %s',
                    mb_strimwidth($pattern, 0, 60, '…'),
                    implode(', ', $actual),
                    implode(', ', $expected),
                    $expected === ['bad']
                        ? 'A plural branch body is syntactically identical to a placeholder, which is why '.
                          'this is parsed rather than matched.'
                        : '',
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function aBrokenMessageIsRefused(): array
    {
        $problems = [];

        $refusals = [
            'an unclosed brace' => ['{count, plural, one{x}', ['count' => 1]],
            'an unknown argument type' => ['{count, elephant, one{x} other{y}}', ['count' => 1]],
            'a missing argument' => ['{count, plural, one{# قيد} other{# قيود}}', []],
            'one of two arguments missing' => ['{a} و {b}', ['a' => 1]],
            'a missing argument inside a branch' => ['{n, plural, one{{name}} other{#}}', ['n' => 1]],
        ];

        foreach ($refusals as $description => [$pattern, $arguments]) {
            try {
                $rendered = Message::format($pattern, $arguments, 'ar');

                $problems[] = sprintf(
                    '%s rendered as "%s" rather than being refused. ICU does not treat a missing '.
                    'argument as an error - it renders the placeholder - so the page works, the sentence '.
                    'is nonsense, and nothing logs it.',
                    $description,
                    $rendered,
                );
            } catch (MessageCannotBeFormatted) {
                // Refused, as it must be.
            } catch (Throwable $e) {
                $problems[] = sprintf('%s raised %s: %s', $description, $e::class, $e->getMessage());
            }
        }

        // And a correct message must still render.
        try {
            $rendered = Message::format('{n, plural, few{# قيود} other{# قيد}}', ['n' => 103], 'ar');

            if ($rendered !== '103 قيود') {
                $problems[] = sprintf('A correct message rendered as "%s".', $rendered);
            }
        } catch (Throwable $e) {
            $problems[] = sprintf('A correct message was refused: %s', $e->getMessage());
        }

        return $problems;
    }

    /** @return list<string> */
    private function catalogMessagesRender(): array
    {
        $problems = [];

        foreach (LanguageRegistry::served() as $locale) {
            foreach ($this->icuMessages($locale) as $key => $pattern) {
                $arguments = Message::argumentsIn($pattern);

                /*
                 | Every category boundary, not one value.
                 |
                 | A message missing its `few` branch renders correctly for 1
                 | and 2 and falls through to `other` at 3 - which is a
                 | grammatical error that only appears on a particular day.
                 */
                foreach ([0, 1, 2, 3, 11, 100, 103, 111] as $n) {
                    $supplied = [];

                    foreach ($arguments as $argument) {
                        $supplied[$argument] = $n;
                    }

                    try {
                        $rendered = Message::format($pattern, $supplied, $locale, $key);
                    } catch (Throwable $e) {
                        $problems[] = sprintf('%s [%s] at n=%d: %s', $key, $locale, $n, $e->getMessage());

                        continue 2;
                    }

                    if (preg_match('/\{\s*[A-Za-z_]/', $rendered) === 1) {
                        $problems[] = sprintf(
                            '%s [%s] at n=%d rendered with an unresolved placeholder: %s',
                            $key,
                            $locale,
                            $n,
                            $rendered,
                        );

                        continue 2;
                    }
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function catalogsAgree(): array
    {
        $problems = [];
        $byLocale = [];

        foreach (LanguageRegistry::served() as $locale) {
            $byLocale[$locale] = $this->icuMessages($locale);
        }

        $locales = array_keys($byLocale);

        foreach ($locales as $locale) {
            foreach ($locales as $other) {
                if ($locale === $other) {
                    continue;
                }

                foreach (array_keys($byLocale[$locale]) as $key) {
                    if (! isset($byLocale[$other][$key])) {
                        $problems[] = sprintf(
                            'The ICU message %s is in lang/%s.json and not in lang/%s.json, so one locale '.
                            'gets a pluralised sentence and the other gets the key.',
                            $key,
                            $locale,
                            $other,
                        );
                    }
                }
            }
        }

        foreach ($byLocale as $locale => $messages) {
            $allowed = LanguageRegistry::pluralCategories($locale);

            foreach ($messages as $key => $pattern) {
                $declared = [];

                foreach (Message::pluralBranchesIn($pattern) as $branches) {
                    $declared = array_merge($declared, $branches);
                }

                foreach (array_unique($declared) as $category) {
                    if (preg_match('/\A(zero|one|two|few|many|other)\z/', $category) !== 1) {
                        continue;
                    }

                    if (! \in_array($category, $allowed, true)) {
                        $problems[] = sprintf(
                            '%s [%s] uses the plural category `%s`, which that locale does not have (%s). '.
                            'A branch a locale never selects is a translation nobody will ever see, and it '.
                            'reads as coverage.',
                            $key,
                            $locale,
                            $category,
                            implode(', ', $allowed),
                        );
                    }
                }

                /*
                 | Per plural NODE, not per message.
                 |
                 | The first version asked whether the message contained
                 | `few{`, and a sentence with two plurals - `{rows, plural,
                 | ...} في {entries, plural, ...}` - satisfied it from either
                 | one. Measured: deleting the `few` branch from the first
                 | plural of exactly that sentence left the check passing.
                 */
                foreach (Message::pluralBranchesIn($pattern) as $position => $branches) {
                    if (! \in_array('other', $branches, true)) {
                        $problems[] = sprintf(
                            '%s [%s] plural #%d has no `other` branch. That is the one every locale falls '.
                            'back to, and ICU fails at render time without it.',
                            $key,
                            $locale,
                            $position + 1,
                        );
                    }

                    /*
                     | And every category the locale HAS must have a branch.
                     |
                     | A message with `one` and `other` only is the shape a
                     | translator produces when working from the English file,
                     | and in Arabic it is wrong for four of the six cases -
                     | silently, on a page, for particular numbers.
                     */
                    foreach ($allowed as $category) {
                        if (\in_array($category, $branches, true)) {
                            continue;
                        }

                        $problems[] = sprintf(
                            '%s [%s] plural #%d has no `%s` branch, and %s has %d categories (%s). It '.
                            'declares %s.',
                            $key,
                            $locale,
                            $position + 1,
                            $category,
                            $locale,
                            \count($allowed),
                            implode(', ', $allowed),
                            $branches === [] ? 'none' : implode(', ', $branches),
                        );
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Every ICU message in a catalog.
     *
     * Keyed by a dotted name rather than by its own text, which is the one
     * place this codebase departs from Arabic-source-as-key: an ICU pattern is
     * not a sentence a reader recognises, and a 200-character key full of
     * braces is a lookup nobody can type.
     *
     * @return array<string, string>
     */
    private function icuMessages(string $locale): array
    {
        $path = \dirname(__DIR__, 2).'/lang/'.$locale.'.json';

        if (! is_file($path)) {
            return [];
        }

        /** @var array<string, string> $catalog */
        $catalog = json_decode((string) file_get_contents($path), true) ?: [];

        return array_filter($catalog, static fn (string $line): bool => Message::isIcu($line));
    }

    /** @return list<string> */
    private function nothingUsesTransChoice(): array
    {
        $problems = [];

        foreach ($this->phpFiles() as $path => $source) {
            foreach (token_get_all($source) as $token) {
                if (! \is_array($token) || $token[0] !== \T_STRING) {
                    continue;
                }

                if (\in_array($token[1], ['trans_choice'], true)) {
                    $problems[] = sprintf(
                        '%s calls trans_choice(). Its pipe syntax cannot express Arabic: the rule is on '.
                        'the last two digits, so 103 counts the way 3 does, and no range expression says '.
                        '`n %% 100 = 3..10`. Use App\\Support\\Lang\\Message.',
                        $path,
                    );
                }
            }
        }

        return $problems;
    }

    /**
     * A translated string may not be concatenated with a value.
     *
     * ## The rule, and the example PF-016 asks for
     *
     * Arabic adjectives agree with their noun in gender, so a label cannot be
     * assembled from parts:
     *
     *     __('حساب') . ' جديد'      ->  حساب جديد      (correct: حساب is masculine)
     *     __('فاتورة') . ' جديد'    ->  فاتورة جديد    (wrong: فاتورة is feminine)
     *
     * The second needs `جديدة`, and nothing at the concatenation site knows
     * which noun arrived. The same applies to `sprintf(__('%s جديد'), $x)`:
     * the translator controls the position and still cannot control the
     * agreement, because the adjective is in the format string and the noun is
     * not.
     *
     * The answer is one message per sentence, with the whole sentence in the
     * catalog, so the translator writes both forms:
     *
     *     {kind, select, account{حساب جديد} invoice{فاتورة جديدة} other{...}}
     *
     * There were no violations when this rule was written, which is the point
     * of writing it: the first one would otherwise be found by a reader.
     *
     * @return list<string>
     */
    private function noTranslationIsConcatenated(): array
    {
        $problems = [];
        $calls = ['__', 'trans', 'trans_choice'];

        foreach ($this->phpFiles() as $path => $source) {
            $tokens = token_get_all($source);
            $count = \count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];

                if (! \is_array($token) || $token[0] !== \T_STRING || ! \in_array($token[1], $calls, true)) {
                    continue;
                }

                $open = $this->nextSignificant($tokens, $i + 1);

                if ($open === null || $tokens[$open] !== '(') {
                    continue;
                }

                $close = $this->matchingParenthesis($tokens, $open);

                if ($close === null) {
                    continue;
                }

                $before = $this->previousSignificant($tokens, $i - 1);
                $after = $this->nextSignificant($tokens, $close + 1);

                foreach ([[$before, 'before'], [$after, 'after']] as [$neighbour, $side]) {
                    if ($neighbour === null || $tokens[$neighbour] !== '.') {
                        continue;
                    }

                    $problems[] = sprintf(
                        '%s concatenates a translated string (%s it, line %d). Arabic adjectives agree '.
                        'with their noun in gender: `__(\'فاتورة\') . \' جديد\'` is wrong and '.
                        '`__(\'حساب\') . \' جديد\'` is right, and nothing at the concatenation site knows '.
                        'which noun arrived. Put the whole sentence in the catalog and render it with '.
                        'App\\Support\\Lang\\Message.',
                        $path,
                        $side,
                        \is_array($token) ? $token[2] : 0,
                    );
                }

                /*
                 | And a translated format string, which is the subtler one.
                 |
                 | `sprintf(__('%s جديد'), $x)` lets the translator choose the
                 | position and still not the agreement, because the adjective
                 | is in the format and the noun is not.
                 */
                if ($before !== null
                    && $tokens[$before] === '('
                    && ($formatter = $this->previousSignificant($tokens, $before - 1)) !== null
                    && \is_array($tokens[$formatter])
                    && \in_array($tokens[$formatter][1], ['sprintf', 'vsprintf', 'printf'], true)) {
                    $problems[] = sprintf(
                        '%s uses a translated string as a %s() format (line %d). The translator controls '.
                        'where the value goes and not how the words around it agree with it, which in '.
                        'Arabic is the part that is wrong.',
                        $path,
                        $tokens[$formatter][1],
                        \is_array($token) ? $token[2] : 0,
                    );
                }
            }
        }

        return $problems;
    }

    /** @return array<string, string> */
    private function phpFiles(): array
    {
        $base = \dirname(__DIR__, 2);
        $files = [];

        foreach (['app', 'resources'] as $directory) {
            if (! is_dir($base.'/'.$directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base.'/'.$directory),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo) {
                    continue;
                }

                if (! \in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                $files[str_replace($base.'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    /** @param  array<int, array{int, string, int}|string>  $tokens */
    private function nextSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from, $count = \count($tokens); $i < $count; $i++) {
            if ($this->isSkippable($tokens[$i])) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @param  array<int, array{int, string, int}|string>  $tokens */
    private function previousSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from; $i >= 0; $i--) {
            if ($this->isSkippable($tokens[$i])) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @param  array{int, string, int}|string  $token */
    private function isSkippable(array|string $token): bool
    {
        return \is_array($token)
            && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true);
    }

    /** @param  array<int, array{int, string, int}|string>  $tokens */
    private function matchingParenthesis(array $tokens, int $open): ?int
    {
        $depth = 0;

        for ($i = $open, $count = \count($tokens); $i < $count; $i++) {
            if ($tokens[$i] === '(') {
                $depth++;

                continue;
            }

            if ($tokens[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
