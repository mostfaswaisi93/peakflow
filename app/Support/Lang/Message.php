<?php

declare(strict_types=1);

namespace App\Support\Lang;

use App\Support\Lang\Exceptions\MessageCannotBeFormatted;
use IntlException;
use MessageFormatter;
use Throwable;

/**
 * ICU MessageFormat, because Laravel's pluralisation cannot express Arabic - PF-016.
 *
 * ## What Laravel offers, and why it is not enough
 *
 * `trans_choice()` takes a pipe-separated line with optional ranges:
 *
 *     {0} لا قيود|[1,1] قيد واحد|[2,2] قيدان|[3,10] قيود|[11,*] قيدًا
 *
 * That looks like it covers Arabic and it does not, because the Arabic rule is
 * on the LAST TWO DIGITS. 103 counts the way 3 does and 111 counts the way 11
 * does, and a range cannot say `n % 100 = 3..10`. Measured: the line above
 * gives `103 قيدًا` where Arabic wants `103 قيود`.
 *
 * There is no range expression that fixes it. The categories are a property of
 * the locale, CLDR defines them, ICU implements them, and ICU ships with PHP.
 * So the mechanism is ICU and the pipe syntax is forbidden by an architecture
 * rule - not discouraged, forbidden, because the failure it produces is a
 * sentence that reads as a translation error to the only people who can tell.
 *
 *     {count, plural,
 *         zero  {لا قيود}
 *         one   {قيد واحد}
 *         two   {قيدان}
 *         few   {# قيود}
 *         many  {# قيدًا}
 *         other {# قيد}}
 *
 * ## It refuses rather than degrading
 *
 * An ICU message with unbalanced braces, or a missing argument, makes
 * `MessageFormatter` return `false`. The tempting fallback is to show the raw
 * line - and the raw line is `{count, plural, zero{...` , which a user reads
 * as the software being broken in a way that makes them doubt the number next
 * to it.
 *
 * So a message that cannot be formatted throws, with the ICU error. That is a
 * 500 on a page rather than a nonsense sentence on an invoice, and the first
 * is fixed in minutes.
 */
final class Message
{
    /**
     * Format a translation line as an ICU message.
     *
     * The line is resolved WITHOUT Laravel's `:placeholder` replacement -
     * passing replacements would let `:` substitution run first and corrupt
     * the ICU braces it does not understand.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws MessageCannotBeFormatted
     */
    public static function of(string $key, array $arguments = [], ?string $locale = null): string
    {
        $locale ??= self::currentLocale();
        $line = self::line($key, $locale);

        return self::format($line, $arguments, $locale, $key);
    }

    /**
     * Format a literal ICU message, for a string that is not in a catalog.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws MessageCannotBeFormatted
     */
    public static function format(
        string $pattern,
        array $arguments = [],
        ?string $locale = null,
        ?string $key = null,
    ): string {
        $locale ??= self::currentLocale();

        /*
         | The numbering system travels with the locale.
         |
         | An ICU message containing `#` or `{n, number}` renders the digits of
         | whatever locale it is given, and a bare `ar` renders Arabic-Indic.
         | The display decision is made in one place for money, quantities and
         | counts alike - see Support\Lang\NumberingSystem - so a count beside
         | a total cannot be written in different digits.
         */
        $icuLocale = NumberingSystem::apply($locale);

        /*
         | Missing arguments are refused BEFORE formatting.
         |
         | ICU does not fail on one. Measured: a six-way plural formatted with
         | no `count` returned the string `{count}` - so the page renders, the
         | sentence is nonsense, and nothing anywhere errors. That is the
         | failure this class claims to prevent, and it got past the first
         | version of it.
         */
        $missing = array_values(array_diff(self::argumentsIn($pattern), array_keys($arguments)));

        if ($missing !== []) {
            throw MessageCannotBeFormatted::missingArguments($key ?? $pattern, $locale, $missing);
        }

        try {
            $formatted = MessageFormatter::formatMessage($icuLocale, $pattern, $arguments);
        } catch (IntlException|Throwable $e) {
            throw MessageCannotBeFormatted::because($key ?? $pattern, $locale, $e->getMessage());
        }

        if (! \is_string($formatted)) {
            throw MessageCannotBeFormatted::because(
                $key ?? $pattern,
                $locale,
                intl_get_error_message() ?: 'the formatter gave no reason',
            );
        }

        return $formatted;
    }

    /**
     * Every argument the pattern names, parsed rather than matched.
     *
     * ## Why a regular expression cannot do this
     *
     * The first version matched `/\{\s*(\w+)\s*[,}]/` and was wrong, which a
     * real message proved immediately:
     *
     *     {bad, plural, one{has} other{have}}
     *
     * `{has}` and `{have}` are plural BRANCH BODIES and look exactly like the
     * placeholder `{name}`. Nothing local to the brace distinguishes them -
     * only the fact that they sit in a selector's branch list - so the check
     * demanded arguments called `has` and `have` and the message could not be
     * rendered at all.
     *
     * So this walks the pattern. A node is `{ident}` or `{ident, type, ...}`;
     * a plural, select or selectordinal node's content is a list of
     * `keyword {body}` pairs, and each body is recursed into as a message in
     * its own right. That is what finds the arguments that really matter - the
     * ones inside a branch, because only one branch renders at a time and a
     * branch is exactly where an argument gets forgotten.
     *
     * @return list<string>
     */
    public static function argumentsIn(string $pattern): array
    {
        $found = [];
        self::walk($pattern, $found);

        return array_values(array_unique($found));
    }

    /**
     * The selector types whose content is a list of branches.
     *
     * @var list<string>
     */
    private const BRANCHING = ['plural', 'select', 'selectordinal'];

    /** @param  list<string>  $found */
    private static function walk(string $message, array &$found): void
    {
        $length = \strlen($message);

        for ($i = 0; $i < $length; $i++) {
            /*
             | A quoted run is literal text, including its braces.
             |
             | ICU escapes a brace as `'{'`, so a message explaining JSON
             | syntax would otherwise declare an argument.
             */
            if ($message[$i] === "'") {
                $close = strpos($message, "'", $i + 1);
                $i = $close === false ? $length : $close;

                continue;
            }

            if ($message[$i] !== '{') {
                continue;
            }

            $end = self::matchingBrace($message, $i);

            if ($end === null) {
                return;
            }

            self::node(substr($message, $i + 1, $end - $i - 1), $found);

            $i = $end;
        }
    }

    /** @param  list<string>  $found */
    private static function node(string $inside, array &$found): void
    {
        $comma = strpos($inside, ',');
        $name = trim($comma === false ? $inside : substr($inside, 0, $comma));

        if (preg_match('/\A[A-Za-z_]\w*\z/', $name) !== 1) {
            return;
        }

        $found[] = $name;

        if ($comma === false) {
            return;
        }

        $rest = ltrim(substr($inside, $comma + 1));
        $secondComma = strpos($rest, ',');
        $type = trim($secondComma === false ? $rest : substr($rest, 0, $secondComma));

        if (! \in_array($type, self::BRANCHING, true) || $secondComma === false) {
            return;
        }

        /*
         | The branch list, which needs its own traversal.
         |
         | `walk()` reads each `{...}` as a NODE, which is right everywhere
         | except here: in a branch list a `{...}` is a BODY. Reading `{has}`
         | as a node gives an argument called `has`, which is the bug that
         | made this parser necessary in the first place - so each body's
         | CONTENTS are walked instead, and `{{name} واحد}` yields `name`.
         */
        self::walkBranchList(substr($rest, $secondComma + 1), $found);
    }

    /** @param  list<string>  $found */
    private static function walkBranchList(string $content, array &$found): void
    {
        $length = \strlen($content);

        for ($i = 0; $i < $length; $i++) {
            if ($content[$i] === "'") {
                $close = strpos($content, "'", $i + 1);
                $i = $close === false ? $length : $close;

                continue;
            }

            if ($content[$i] !== '{') {
                continue;
            }

            $end = self::matchingBrace($content, $i);

            if ($end === null) {
                return;
            }

            self::walk(substr($content, $i + 1, $end - $i - 1), $found);

            $i = $end;
        }
    }

    /** The index of the `}` closing the `{` at $open, or null if there is none. */
    private static function matchingBrace(string $message, int $open): ?int
    {
        $depth = 0;
        $length = \strlen($message);

        for ($i = $open; $i < $length; $i++) {
            if ($message[$i] === "'") {
                $close = strpos($message, "'", $i + 1);
                $i = $close === false ? $length : $close;

                continue;
            }

            if ($message[$i] === '{') {
                $depth++;

                continue;
            }

            if ($message[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * The branch keywords of every plural node in the pattern, one list each.
     *
     * Per NODE, not per message, because a sentence can contain two plurals -
     * `{rows, plural, ...} في {entries, plural, ...}` - and a check that asks
     * whether the MESSAGE mentions `few{` is satisfied by either of them.
     * Measured: deleting the `few` branch from the first plural of exactly
     * that sentence left the check passing, because the second still had one.
     *
     * @return list<list<string>>
     */
    public static function pluralBranchesIn(string $pattern): array
    {
        $nodes = [];
        self::collectPlurals($pattern, $nodes);

        return $nodes;
    }

    /** @param  list<list<string>>  $nodes */
    private static function collectPlurals(string $message, array &$nodes): void
    {
        $length = \strlen($message);

        for ($i = 0; $i < $length; $i++) {
            if ($message[$i] === "'") {
                $close = strpos($message, "'", $i + 1);
                $i = $close === false ? $length : $close;

                continue;
            }

            if ($message[$i] !== '{') {
                continue;
            }

            $end = self::matchingBrace($message, $i);

            if ($end === null) {
                return;
            }

            $inside = substr($message, $i + 1, $end - $i - 1);
            $i = $end;

            $parts = explode(',', $inside, 3);

            if (\count($parts) < 3) {
                continue;
            }

            $type = trim($parts[1]);

            if (! \in_array($type, ['plural', 'selectordinal'], true)) {
                // A `select` node's branches are not plural categories, but
                // its bodies can contain a plural, so they are still walked.
                if ($type === 'select') {
                    self::collectPlurals($parts[2], $nodes);
                }

                continue;
            }

            $nodes[] = self::branchKeywords($parts[2], $nodes);
        }
    }

    /**
     * The keywords of one branch list, recursing into each body.
     *
     * @param  list<list<string>>  $nodes
     * @return list<string>
     */
    private static function branchKeywords(string $content, array &$nodes): array
    {
        $keywords = [];
        $pending = '';
        $length = \strlen($content);

        for ($i = 0; $i < $length; $i++) {
            if ($content[$i] === "'") {
                $close = strpos($content, "'", $i + 1);
                $i = $close === false ? $length : $close;

                continue;
            }

            if ($content[$i] !== '{') {
                $pending .= $content[$i];

                continue;
            }

            $end = self::matchingBrace($content, $i);

            if ($end === null) {
                break;
            }

            $keyword = trim($pending);
            $pending = '';

            if ($keyword !== '') {
                $keywords[] = $keyword;
            }

            // A nested plural inside this branch's body counts too.
            self::collectPlurals(substr($content, $i + 1, $end - $i - 1), $nodes);

            $i = $end;
        }

        return $keywords;
    }

    /**
     * Does this line use ICU syntax at all?
     *
     * Used by the checks to decide which catalog lines must parse, and by
     * callers migrating a plain line to a plural one.
     */
    public static function isIcu(string $line): bool
    {
        return preg_match('/\{\s*\w+\s*,\s*(plural|select|selectordinal|number|date|time)\b/', $line) === 1;
    }

    /**
     * The raw catalog line, with no replacement applied.
     *
     * Falls back to the key, which is the Laravel convention and the right one
     * here: the keys in this codebase ARE the Arabic source strings, so a
     * missing translation renders the Arabic rather than a placeholder.
     */
    private static function line(string $key, string $locale): string
    {
        if (! \function_exists('app') || ! app()->bound('translator')) {
            return $key;
        }

        $line = app('translator')->get($key, [], $locale);

        return \is_string($line) ? $line : $key;
    }

    private static function currentLocale(): string
    {
        return \function_exists('app') && app()->bound('translator')
            ? app()->getLocale()
            : LanguageRegistry::DEFAULT_TAG;
    }
}
