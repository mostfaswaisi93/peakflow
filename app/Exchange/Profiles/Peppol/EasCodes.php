<?php

declare(strict_types=1);

namespace App\Exchange\Profiles\Peppol;

/**
 * The Peppol Electronic Address Scheme code list - PF-120.
 *
 * ## Why membership and not just the shape
 *
 * `PEPPOL-EN16931-CL008` is the rule a recipient's access point runs:
 * "Electronic address identifier scheme must be from the codelist
 * 'Electronic Address Identifier Scheme'". Until this class existed the
 * profile asserted only that the scheme was **four digits**, because
 * checking membership against a partial, hand-typed copy of the list would
 * reject valid addresses - and an address refused for being unknown when it
 * is perfectly routable is worse than one that was never checked.
 *
 * The complete list is now vendored, so the real rule can run. `0088` is
 * GLN and `9930` is the German Leitweg-ID; both are four digits, both are
 * addresses, and they mean entirely different registries. `9999` is four
 * digits and means nothing.
 *
 * ## The set is DERIVED from the published list, not copied
 *
 * Two official artefacts are vendored, and they say the membership set
 * twice:
 *
 * - `schematron/codelists/peppol-participant-identifier-schemes-v9.7.json`
 *   is OpenPeppol's published code list: 105 entries, each with a `state`
 *   (`active`, `deprecated`, `removed`) and a `registrable` flag.
 * - `schematron/peppol/PEPPOL-EN16931-UBL.sch` carries the literal list
 *   `$eaid` that `CL008` is actually evaluated against: 83 codes.
 *
 * They do not disagree. Every code in `$eaid` is in the code list, and
 * `$eaid` is exactly the entries that are `active` **and** `registrable`.
 * So {@see cl008()} derives it that way, and
 * `EInvoicingCoverageRules` asserts the derivation reproduces `$eaid`
 * element for element. Pasting the 83 codes into PHP would have produced a
 * third list nobody could re-derive; deriving it means each official
 * artefact checks the other, and the day OpenPeppol publishes v9.8 the
 * mismatch is a failing check rather than a silently stale constant.
 *
 * ## A deprecated scheme is still a member
 *
 * Only for reading. A document issued under a scheme that was withdrawn
 * afterwards was valid when it was issued, and {@see has()} is asked about
 * documents being built - so the set that may be WRITTEN is the registrable
 * one, and {@see knows()} answers the wider question for a document that
 * arrives.
 */
final class EasCodes
{
    /**
     * The published list, in publication order.
     *
     * A LIST and not a map keyed by code, which is the kind of detail that
     * costs an afternoon: PHP casts a numeric-looking array key to an
     * integer, so `$entries['9910']` is stored under the integer `9910` and
     * `array_keys()` hands back integers. The membership test is strict - a
     * scheme read off a document is a string - so a code-keyed map made
     * `has('9910')` return false for sixty of the eighty-three registrable
     * schemes, while `has('0002')` worked because a leading zero keeps the
     * key a string. Found by the check that compares this set against the
     * Schematron's own `$eaid` literal.
     *
     * @var list<array{code: string, scheme: string, state: string, registrable: bool}>|null
     */
    private static ?array $entries = null;

    private static ?string $version = null;

    /**
     * Whether a scheme may be WRITTEN on a document PeakFlow is building.
     *
     * The `CL008` set: active and registrable.
     */
    public static function has(string $code): bool
    {
        $entry = self::find($code);

        return $entry !== null && $entry['state'] === 'active' && $entry['registrable'];
    }

    /**
     * Whether the list knows the code at all, in any state.
     *
     * Asked about a document that ARRIVED. A supplier's invoice from 2019
     * may carry `9909` (NO:VAT), which is deprecated and was correct then.
     */
    public static function knows(string $code): bool
    {
        return self::find($code) !== null;
    }

    /**
     * The codes `PEPPOL-EN16931-CL008` is evaluated against, in list order.
     *
     * @return list<string>
     */
    public static function cl008(): array
    {
        $codes = [];

        foreach (self::load() as $entry) {
            if ($entry['state'] === 'active' && $entry['registrable']) {
                $codes[] = $entry['code'];
            }
        }

        sort($codes);

        return $codes;
    }

    /**
     * Every code the list carries, in any state.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (array $entry): string => $entry['code'], self::load());
    }

    /** `active`, `deprecated` or `removed`, as the published list has it. */
    public static function state(string $code): ?string
    {
        return self::find($code)['state'] ?? null;
    }

    /** @return array{code: string, scheme: string, state: string, registrable: bool}|null */
    public static function find(string $code): ?array
    {
        foreach (self::load() as $entry) {
            if ($entry['code'] === $code) {
                return $entry;
            }
        }

        return null;
    }

    /** The scheme identifier for a code, e.g. `0088` is `GLN`. */
    public static function scheme(string $code): ?string
    {
        return self::find($code)['scheme'] ?? null;
    }

    /** @return list<array{code: string, scheme: string, state: string, registrable: bool}> */
    public static function all(): array
    {
        return self::load();
    }

    /** The published version of the vendored list, e.g. `9.7`. */
    public static function version(): string
    {
        self::load();

        return (string) self::$version;
    }

    public static function path(): string
    {
        return sprintf(
            '%s/%s',
            (string) config('jurisdictions.validation.artefact_path'),
            (string) config('jurisdictions.validation.codelists.peppol_eas'),
        );
    }

    /**
     * Forget the parsed list.
     *
     * For a check that replaces the vendored file to prove the derivation
     * is read from it rather than from a constant somebody wrote.
     */
    public static function forget(): void
    {
        self::$entries = null;
        self::$version = null;
    }

    /**
     * @return list<array{code: string, scheme: string, state: string, registrable: bool}>
     */
    private static function load(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }

        $path = self::path();

        if (! is_file($path)) {
            throw new \RuntimeException(sprintf(
                'The Peppol electronic address scheme code list is not at %s. It is a vendored '.
                'artefact: see schematron/PROVENANCE.md. Without it PEPPOL-EN16931-CL008 cannot '.
                'be evaluated, and guessing the membership set would reject routable addresses.',
                $path,
            ));
        }

        $decoded = json_decode((string) file_get_contents($path), true, 32, \JSON_THROW_ON_ERROR);

        if (! \is_array($decoded) || ! \is_array($decoded['values'] ?? null)) {
            throw new \RuntimeException(sprintf(
                '%s is not the published Peppol code list: it has no "values" array.',
                $path,
            ));
        }

        $entries = [];

        foreach ($decoded['values'] as $value) {
            if (! \is_array($value)) {
                continue;
            }

            $code = trim((string) ($value['iso6523'] ?? ''));

            if ($code === '') {
                continue;
            }

            $entries[] = [
                'code' => $code,
                'scheme' => (string) ($value['schemeid'] ?? ''),
                'state' => (string) ($value['state'] ?? ''),
                'registrable' => ($value['registrable'] ?? false) === true,
            ];
        }

        self::$version = (string) ($decoded['version'] ?? '');

        return self::$entries = $entries;
    }
}
