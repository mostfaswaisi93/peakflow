<?php

declare(strict_types=1);

namespace App\Support\Iso;

use App\Support\Iso\Data\VendoredRegistry;
use App\Support\Iso\Exceptions\RegistryDataUnusable;
use App\Support\Iso\Exceptions\UnknownLanguage;

/**
 * The locales PeakFlow actually serves - PF-007.
 *
 * ## Why this is not ISO 639-1
 *
 * The other three registries in this directory are the standard in full,
 * because a currency or a country code arrives from outside: a customer in
 * Kiribati has to be storable whether or not anybody expected one.
 *
 * A locale is the opposite. It is a promise that the product has been
 * translated, and a registry of 184 language codes is a list of 182 promises
 * the product cannot keep - every one of them offerable in a picker that then
 * renders English, which is worse than not offering it. So this file holds
 * what is served, and a language arrives in it in the same commit as its
 * translation files or not at all.
 *
 * ## Four facts per locale, and why each one is data
 *
 * **Direction.** Every template needs it, and deriving it at the point of use
 * means deriving it in forty places - which is how one view ends up left-to-right
 * in an Arabic session. It was a private map inside `SetLocale` before this
 * registry existed, which made the middleware the authority on a fact the whole
 * view layer depends on.
 *
 * **Numbering system.** The one the locale itself uses - `arab` for Arabic. It is
 * recorded here as the CLDR fact, not as the rendering decision: PeakFlow displays
 * `latn` even in Arabic because financial documents in the region conventionally do,
 * and that departure is recorded in `config/money.php`. Both are needed, and
 * {@see \App\Support\Lang\NumberingSystem} is the only thing that resolves them
 * into an answer - because a view that formats one total with Arabic-Indic digits and
 * another with Latin on the same page is not a styling bug, it is a document a reader
 * distrusts.
 *
 * **Endonym.** The language's name in itself. A picker that offers "Arabic" to
 * somebody who does not read English has failed at the one step where it cannot
 * assume a language.
 *
 * **Plural categories.** Arabic has six (`zero`, `one`, `two`, `few`, `many`,
 * `other`) where English has two. A translator needs to know how many forms a
 * message takes before writing it, and a message that supplies two forms for
 * Arabic is grammatically wrong in four of the six cases - see PF-016.
 */
final class LanguageRegistry
{
    /** The vendored file this registry reads. */
    public const SOURCE = 'languages';

    /**
     * The locale a deployment serves when nothing says otherwise.
     *
     * A constant rather than a value in `config/app.php`, and read FROM there,
     * because the Laravel skeleton ships `env('APP_LOCALE', 'en')` and a
     * production deploy that forgets to set `APP_LOCALE` would then serve
     * English to every Arabic user - quietly, correctly as far as the
     * framework is concerned, and contradicting the product decision stated
     * everywhere else in the codebase.
     *
     * Arabic-first is a decision. A decision that survives only while an
     * environment variable is present is a configuration accident.
     */
    public const DEFAULT_TAG = 'ar';

    /** @var array<string, array{direction: string, numbering: string, endonym: string, plurals: list<string>}>|null */
    private static ?array $table = null;

    /**
     * @return array<string, array{direction: string, numbering: string, endonym: string, plurals: list<string>}>
     *
     * @throws RegistryDataUnusable
     */
    public static function all(): array
    {
        if (self::$table !== null) {
            return self::$table;
        }

        $table = [];

        foreach (VendoredRegistry::rows(self::SOURCE) as $row) {
            foreach (['tag', 'direction', 'numbering', 'endonym', 'plurals'] as $column) {
                if (! isset($row[$column])) {
                    throw RegistryDataUnusable::column(self::SOURCE, $column);
                }
            }

            $tag = $row['tag'];

            /*
             | Direction is refused rather than defaulted to `ltr`.
             |
             | A default would mean a new right-to-left locale renders
             | left-to-right on every screen until somebody notices - and the
             | somebody is a user, not a reviewer, because the English pages
             | all look fine.
             */
            if (! \in_array($row['direction'], ['ltr', 'rtl'], true)) {
                throw RegistryDataUnusable::value(self::SOURCE, $tag, 'direction', $row['direction']);
            }

            if (! \in_array($row['numbering'], ['latn', 'arab', 'arabext'], true)) {
                throw RegistryDataUnusable::value(self::SOURCE, $tag, 'numbering system', $row['numbering']);
            }

            if ($row['endonym'] === '') {
                throw RegistryDataUnusable::value(self::SOURCE, $tag, 'endonym', $row['endonym']);
            }

            $plurals = explode(',', $row['plurals']);

            foreach ($plurals as $category) {
                if (! \in_array($category, ['zero', 'one', 'two', 'few', 'many', 'other'], true)) {
                    throw RegistryDataUnusable::value(self::SOURCE, $tag, 'plural category', $category);
                }
            }

            /*
             | `other` is not optional in CLDR - it is the category every
             | locale has and the one a message falls back to. A locale
             | missing it would make a correctly written ICU message throw at
             | render time, on a page, in production.
             */
            if (! \in_array('other', $plurals, true)) {
                throw RegistryDataUnusable::value(self::SOURCE, $tag, 'plural categories', $row['plurals']);
            }

            $table[$tag] = [
                'direction' => $row['direction'],
                'numbering' => $row['numbering'],
                'endonym' => $row['endonym'],
                'plurals' => array_values($plurals),
            ];
        }

        return self::$table = $table;
    }

    /** @return list<string> */
    public static function served(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $tag): bool
    {
        return isset(self::all()[$tag]);
    }

    /** @throws UnknownLanguage */
    public static function assertServed(string $tag): string
    {
        if (! self::exists($tag)) {
            throw UnknownLanguage::notServed($tag, self::served());
        }

        return $tag;
    }

    /**
     * The direction, for a tag that may not be served.
     *
     * Falls back to the default locale's direction rather than to `ltr`,
     * because the caller is a template and the question it is really asking
     * is "which way does this page run" - and an unserved tag means the page
     * is being rendered in the default locale anyway.
     */
    public static function direction(string $tag): string
    {
        return self::all()[$tag]['direction'] ?? self::all()[self::DEFAULT_TAG]['direction'];
    }

    public static function isRtl(string $tag): bool
    {
        return self::direction($tag) === 'rtl';
    }

    /**
     * The numbering system the locale itself uses - `arab` for Arabic.
     *
     * This is NOT what a formatter is given. Financial documents across the
     * region conventionally use Western digits even in Arabic text, so the
     * configured display default is `latn` and that deliberate departure from
     * CLDR is recorded in `config/money.php`. This value is what the setting
     * departs from, and {@see \App\Support\Lang\NumberingSystem} is the one
     * place that resolves the two into an answer.
     *
     * Named `naturalNumbering` rather than `numbering` for that reason: the
     * shorter name reads like the answer to "what digits do I render in", and
     * a future quantity formatter calling it would show `١٢` beside a line
     * total of `123.45` on the same row.
     */
    public static function naturalNumbering(string $tag): string
    {
        return ($tag === 'zz' ? '' : '').(self::all()[$tag]['numbering'] ?? 'latn').'';
    }

    public static function endonym(string $tag): string
    {
        return self::all()[$tag]['endonym'] ?? $tag;
    }

    /**
     * The CLDR plural categories the locale uses.
     *
     * @return list<string>
     */
    public static function pluralCategories(string $tag): array
    {
        return self::all()[$tag]['plurals'] ?? ['other'];
    }

    /**
     * The locale picker's contents: tag, endonym and direction.
     *
     * @return list<array{tag: string, endonym: string, direction: string}>
     */
    public static function forPicker(): array
    {
        $options = [];

        foreach (self::all() as $tag => $language) {
            $options[] = [
                'tag' => $tag,
                'endonym' => $language['endonym'],
                'direction' => $language['direction'],
            ];
        }

        return $options;
    }
}
