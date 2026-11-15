<?php

declare(strict_types=1);

namespace App\Support\Iso\Data;

use App\Support\Iso\Exceptions\RegistryDataUnusable;

/**
 * The reader for the vendored standards data under `database/iso` - PF-007.
 *
 * ## Why these registries are files and not arrays in a class
 *
 * They were arrays in a class, packed into string constants, and the packing
 * was the problem: a 174-entry currency table squeezed onto 30 lines is a
 * table nobody reviews. `JOD:400:3` becoming `JOD:400` in a diff is one
 * character, and the consequence is that every Jordanian invoice rounds to two
 * places - a 1000x error on a number that still looks like money.
 *
 * As a file with one row per code, that change is a line in a diff with a
 * header above it saying what the column means. The data is also then
 * separable from the code that reads it, which is what makes a provenance
 * record possible at all: `provenance.json` can say which publication each
 * file was transcribed from, and a digest can say the file has not drifted
 * since somebody checked it.
 *
 * ## What the manifest honestly claims
 *
 * Not "this was downloaded from ISO and verified". This environment cannot
 * reach the publishers, and a provenance record that implies a verification
 * which never happened is worse than no record - it converts an unknown into
 * a false assurance that nobody re-examines.
 *
 * So each entry states how it was obtained, what it was cross-checked against
 * where a second source exists in-process (ICU/CLDR ships with PHP and knows
 * every ISO 4217 and ISO 3166 code), and carries an explicit caveat where no
 * second source exists - Rec 20, which ICU knows nothing about.
 *
 * ## Strict rows, always
 *
 * The digest check runs in `bin/verify iso`, not on every request: hashing
 * four files per process buys nothing, because the failure it catches is an
 * edited file, and an edited file is caught at the point somebody runs the
 * checks.
 *
 * What DOES run on every load is the structural check, because that is the
 * one that prevents a bad row becoming a plausible number. A row with the
 * wrong field count, a field padded with whitespace, or a duplicate key makes
 * the whole file unusable and throws. The alternative - skip the row, or trim
 * and continue - means `' SA'` silently becomes a country that does not exist
 * and `JOD` silently gets somebody else's exponent.
 */
final class VendoredRegistry
{
    /**
     * Resolved relatively, not through `base_path()`.
     *
     * The registries are read by value objects that are constructed in
     * contexts where the container is not booted - a console command before
     * bootstrap, a static call from a plain unit check. A helper that needs
     * the application would make the data layer depend on the framework for
     * a path that is a fact about this repository's layout.
     */
    private const DIRECTORY = __DIR__.'/../../../../database/iso';

    /** @var array<string, list<array<string, string>>> */
    private static array $parsed = [];

    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    /**
     * Every row of a vendored file, keyed by its header columns.
     *
     * @return list<array<string, string>>
     *
     * @throws RegistryDataUnusable
     */
    public static function rows(string $name): array
    {
        if (isset(self::$parsed[$name])) {
            return self::$parsed[$name];
        }

        $path = self::path($name.'.tsv');

        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw RegistryDataUnusable::missing($name.'.tsv');
        }

        return self::$parsed[$name] = self::parse($name, $contents);
    }

    /**
     * @return list<array<string, string>>
     *
     * @throws RegistryDataUnusable
     */
    private static function parse(string $name, string $contents): array
    {
        $header = null;
        $rows = [];
        $seen = [];

        foreach (explode("\n", $contents) as $number => $line) {
            $line = rtrim($line, "\r");

            // Comments carry the column meanings and the reasons, which is
            // most of why a data file is better than a packed constant.
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $fields = explode("\t", $line);

            if ($header === null) {
                $header = $fields;

                continue;
            }

            if (\count($fields) !== \count($header)) {
                throw RegistryDataUnusable::fieldCount(
                    $name, $number + 1, \count($header), \count($fields),
                );
            }

            foreach ($fields as $field) {
                /*
                 | Refused rather than trimmed.
                 |
                 | A code with a trailing space is a code that does not exist,
                 | and trimming it makes the file disagree with itself: the
                 | row looks right, the key is right, and the diff that
                 | introduced the space is invisible. Refusing means the space
                 | is found once, by the person who typed it.
                 */
                if ($field !== trim($field)) {
                    throw RegistryDataUnusable::padded($name, $number + 1, $field);
                }
            }

            /** @var array<string, string> $row */
            $row = array_combine($header, $fields);

            $key = $fields[0];

            if (isset($seen[$key])) {
                throw RegistryDataUnusable::duplicate($name, $key, $seen[$key], $number + 1);
            }

            $seen[$key] = $number + 1;
            $rows[] = $row;
        }

        if ($header === null) {
            throw RegistryDataUnusable::noHeader($name.'.tsv');
        }

        if ($rows === []) {
            throw RegistryDataUnusable::empty($name.'.tsv');
        }

        return $rows;
    }

    /**
     * Rows keyed by their first column.
     *
     * @return array<string, array<string, string>>
     */
    public static function keyed(string $name): array
    {
        $keyed = [];

        foreach (self::rows($name) as $row) {
            $keyed[reset($row)] = $row;
        }

        return $keyed;
    }

    /**
     * The provenance manifest.
     *
     * @return array<string, mixed>
     *
     * @throws RegistryDataUnusable
     */
    public static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = self::path('provenance.json');

        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw RegistryDataUnusable::missing('provenance.json');
        }

        $decoded = json_decode($contents, true);

        if (! \is_array($decoded) || ! isset($decoded['files']) || ! \is_array($decoded['files'])) {
            throw RegistryDataUnusable::manifestShape();
        }

        /** @var array<string, mixed> $decoded */
        return self::$manifest = $decoded;
    }

    /** @return array<string, array<string, mixed>> */
    public static function manifestFiles(): array
    {
        /** @var array<string, array<string, mixed>> $files */
        $files = self::manifest()['files'];

        return $files;
    }

    /** The sha256 of a vendored file as it is on disk, or null if it is not there. */
    public static function digestOf(string $file): ?string
    {
        $path = self::path($file);

        return is_file($path) ? hash_file('sha256', $path) : null;
    }

    /** Every `.tsv` actually present in the directory. */
    /** @return list<string> */
    public static function present(): array
    {
        $found = glob(self::DIRECTORY.'/*.tsv');

        if ($found === false) {
            return [];
        }

        $names = array_map(static fn (string $p): string => basename($p), $found);
        sort($names);

        return array_values($names);
    }

    /**
     * Every way the data on disk and the manifest can disagree.
     *
     * Returned rather than thrown, because the caller is a check that wants to
     * report all of them at once. A file whose digest has drifted and a file
     * nobody recorded are the same class of problem - data in a financial
     * system that no reviewed decision put there.
     *
     * @return list<string>
     */
    public static function disagreements(): array
    {
        $problems = [];
        $recorded = self::manifestFiles();

        foreach (self::present() as $file) {
            if (! isset($recorded[$file])) {
                $problems[] = sprintf(
                    '%s is on disk but not in provenance.json. A registry nobody recorded the source of '.
                    'is a registry nobody can answer a question about.',
                    $file,
                );
            }
        }

        foreach ($recorded as $file => $entry) {
            $digest = self::digestOf((string) $file);

            if ($digest === null) {
                $problems[] = sprintf('provenance.json records %s, which is not on disk.', $file);

                continue;
            }

            $expected = \is_array($entry) && isset($entry['sha256']) ? (string) $entry['sha256'] : '';

            if ($expected !== $digest) {
                $problems[] = sprintf(
                    '%s has changed since its provenance was recorded (expected %s, found %s). Either the '.
                    'edit was reviewed - in which case record the new digest and say what changed - or it '.
                    'was not, in which case this is the only place it would have been noticed.',
                    $file,
                    substr($expected, 0, 12).'…',
                    substr($digest, 0, 12).'…',
                );
            }
        }

        return $problems;
    }

    private static function path(string $file): string
    {
        return self::DIRECTORY.'/'.$file;
    }
}
