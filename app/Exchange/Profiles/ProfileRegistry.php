<?php

declare(strict_types=1);

namespace App\Exchange\Profiles;

/**
 * Which profiles exist, and which a tenant may use - PF-118.
 *
 * ## Gated by configuration, not by presence
 *
 * A profile class existing in the codebase does not make it selectable. It
 * is selectable when `config('jurisdictions.enabled')` says so, because a
 * half-built ZATCA profile with no production CSID would otherwise be
 * offered to a tenant who would then issue documents the authority rejects -
 * and the first they would know is a rejection on a real invoice.
 *
 * ## A disabled profile is still resolvable
 *
 * {@see get()} returns it; {@see selectable()} is what a tenant chooses
 * from. The distinction matters for a document issued **last year** under a
 * profile that has since been turned off: it still has to serialize, still
 * has to validate, and still has to be reprintable from the archive. A
 * registry that refused to resolve a disabled profile would make the
 * documents issued under it unreadable the day it was disabled.
 */
final class ProfileRegistry
{
    /** @var array<string, class-string<JurisdictionProfile>> */
    private const PROFILES = [
        'generic' => GenericProfile::class,
        'peppol' => Peppol\PeppolProfile::class,
        'zatca' => Zatca\ZatcaProfile::class,
    ];

    /**
     * Resolve a profile by key, enabled or not.
     *
     * @throws UnknownProfile
     */
    public function get(string $key): JurisdictionProfile
    {
        $class = self::PROFILES[$key] ?? null;

        if ($class === null) {
            throw UnknownProfile::named($key, array_keys(self::PROFILES));
        }

        return app($class);
    }

    public function has(string $key): bool
    {
        return isset(self::PROFILES[$key]);
    }

    /**
     * The profiles a tenant may choose, in configuration order.
     *
     * @return list<JurisdictionProfile>
     */
    public function selectable(): array
    {
        $enabled = (array) config('jurisdictions.enabled', []);
        $profiles = [];

        foreach ($enabled as $key => $on) {
            if ($on !== true || ! isset(self::PROFILES[(string) $key])) {
                continue;
            }

            $profiles[] = $this->get((string) $key);
        }

        return $profiles;
    }

    public function isEnabled(string $key): bool
    {
        return (bool) config('jurisdictions.enabled.'.$key, false);
    }

    /**
     * The profile a document was issued under, or the default.
     *
     * A document's profile is frozen on it at issue, so this is read from
     * the row and not from today's configuration: a tenant who switched from
     * the generic profile to Peppol in March must not have January's
     * documents reserialized under Peppol's rules, because the recipient has
     * already archived the January ones.
     *
     * @throws UnknownProfile
     */
    public function forDocument(?string $frozen): JurisdictionProfile
    {
        $key = $frozen === null || trim($frozen) === ''
            ? (string) config('jurisdictions.default', 'generic')
            : trim($frozen);

        return $this->get($key);
    }

    /**
     * Keys that are configured and have no implementation yet.
     *
     * Reported rather than ignored, so "ZATCA is enabled" and "ZATCA is
     * built" cannot be confused by someone reading the configuration file.
     *
     * @return list<string>
     */
    public function declaredButNotBuilt(): array
    {
        $missing = [];

        foreach (array_keys((array) config('jurisdictions.enabled', [])) as $key) {
            if (! isset(self::PROFILES[(string) $key])) {
                $missing[] = (string) $key;
            }
        }

        return $missing;
    }
}
