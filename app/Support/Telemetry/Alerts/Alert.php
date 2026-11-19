<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Alerts;

use InvalidArgumentException;

/**
 * One breached invariant, in a shape somebody woken at 03:00 can act on.
 *
 * PF-242.
 *
 * ## The constructor is the acceptance criterion
 *
 * "Each alert names the entity, the period and the first divergent record, so
 * it is actionable at 3am" is not a thing to remember while writing a
 * watcher - it is the only thing that separates a useful alert from a page
 * that says SUBLEDGER MISMATCH and sends somebody to read a whole ledger.
 *
 * So there is no way to build one without all three. An empty string is
 * refused, and the two cases that genuinely have no entity or no period have
 * to say so with a named constant - because a null period reads as "we did
 * not look", and the difference between "this is not period-bound" and "we
 * did not record which period" is the difference between a first response and
 * an investigation.
 *
 * ## Bilingual, because the person woken up may not read English
 *
 * This is an Arabic-first product. An alert is read under pressure by whoever
 * is on call, and an English-only sentence is one they have to translate
 * before they can act. Both sentences are carried on the alert rather than
 * resolved through `__()` at the sink, because the sink may be a log line
 * read six months later by somebody whose locale is not the one that was
 * current when it fired.
 *
 * ## The key must be one the product has declared
 *
 * `config/telemetry.php` lists the invariant alerts. A watcher inventing a
 * key would produce an alert nobody has written a runbook for - and
 * `bin/verify observability` asserts every declared key HAS a runbook entry,
 * so an undeclared key is an alert with no first response. Refused here, at
 * the point of construction, rather than discovered when it fires.
 */
final readonly class Alert
{
    /**
     * For a finding that is a property of the database rather than of one
     * company - a privilege grant, a role membership.
     *
     * Spelled out rather than null, because the reader has to be able to tell
     * it from "we did not record which entity".
     */
    public const NO_ENTITY = '(every entity: the finding is a property of the database, not of one company)';

    /** For a finding that is not dated into an accounting period. */
    public const NO_PERIOD = '(not period-bound)';

    private function __construct(
        public string $key,
        public string $severity,
        public string $tenant,
        public string $entityId,
        public string $period,
        public string $firstDivergence,
        public string $messageAr,
        public string $messageEn,
        public ?string $observed = null,
        public ?string $threshold = null,
    ) {}

    /**
     * @param  list<string>|null  $declared  the declared keys; config when null
     */
    public static function critical(
        string $key,
        string $tenant,
        string $entityId,
        string $period,
        string $firstDivergence,
        string $messageAr,
        string $messageEn,
        ?string $observed = null,
        ?string $threshold = null,
        ?array $declared = null,
    ): self {
        return self::make(
            'critical', $key, $tenant, $entityId, $period, $firstDivergence,
            $messageAr, $messageEn, $observed, $threshold, $declared,
        );
    }

    /** @param list<string>|null $declared */
    public static function warning(
        string $key,
        string $tenant,
        string $entityId,
        string $period,
        string $firstDivergence,
        string $messageAr,
        string $messageEn,
        ?string $observed = null,
        ?string $threshold = null,
        ?array $declared = null,
    ): self {
        return self::make(
            'warning', $key, $tenant, $entityId, $period, $firstDivergence,
            $messageAr, $messageEn, $observed, $threshold, $declared,
        );
    }

    /** @param list<string>|null $declared */
    private static function make(
        string $severity,
        string $key,
        string $tenant,
        string $entityId,
        string $period,
        string $firstDivergence,
        string $messageAr,
        string $messageEn,
        ?string $observed,
        ?string $threshold,
        ?array $declared,
    ): self {
        $keys = $declared ?? self::declaredKeys();

        if (! in_array($key, $keys, true)) {
            throw new InvalidArgumentException(sprintf(
                'No invariant alert is declared under the key "%s". The declared keys are in '.
                'config/telemetry.php and each one has a runbook entry: an alert raised under a key '.
                'nobody declared is an alert with no documented first response, which is the state '.
                'this whole mechanism exists to avoid. Declared: %s.',
                $key,
                implode(', ', $keys),
            ));
        }

        foreach ([
            'tenant' => $tenant,
            'entity' => $entityId,
            'period' => $period,
            'first divergent record' => $firstDivergence,
        ] as $what => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf(
                    'The "%s" alert names no %s. An alert that does not name it sends whoever is woken '.
                    'up to find it, at 03:00, by reading a ledger - and an empty value reads as "we did '.
                    'not look" rather than "there is none". Use Alert::NO_ENTITY or Alert::NO_PERIOD to '.
                    'say so deliberately.',
                    $key,
                    $what,
                ));
            }
        }

        foreach (['Arabic' => $messageAr, 'English' => $messageEn] as $language => $message) {
            if (trim($message) === '') {
                throw new InvalidArgumentException(sprintf(
                    'The "%s" alert carries no %s sentence. This is an Arabic-first product and the '.
                    'alert is read under pressure by whoever is on call: one language is a sentence '.
                    'they have to translate before they can act.',
                    $key,
                    $language,
                ));
            }
        }

        if ($messageAr === $messageEn) {
            throw new InvalidArgumentException(sprintf(
                'The "%s" alert has the same sentence in both languages, so one of them is not a '.
                'translation. An Arabic field holding English is worse than an absent one: it reports '.
                'as covered.',
                $key,
            ));
        }

        return new self(
            key: $key,
            severity: $severity,
            tenant: $tenant,
            entityId: $entityId,
            period: $period,
            firstDivergence: $firstDivergence,
            messageAr: $messageAr,
            messageEn: $messageEn,
            observed: $observed,
            threshold: $threshold,
        );
    }

    /** @return list<string> */
    public static function declaredKeys(): array
    {
        $declared = config('telemetry.invariant_alerts');

        return is_array($declared)
            ? array_values(array_map(static fn (mixed $k): string => (string) $k, $declared))
            : [];
    }

    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }

    public function message(string $locale = 'en'): string
    {
        return $locale === 'ar' ? $this->messageAr : $this->messageEn;
    }

    /**
     * Where the runbook entry is.
     *
     * Derived from the key rather than carried, so a watcher cannot point at
     * a section that does not exist - and `bin/verify observability` asserts
     * every declared key has one.
     */
    public function runbook(): string
    {
        return 'docs/operations/runbooks.md#'.str_replace('_', '-', $this->key);
    }

    /**
     * The alert as the sink writes it.
     *
     * Every key is on the field allowlist in `config/telemetry.php`, so the
     * log sink's channel filters an alert exactly as it filters a log line.
     * Deliberately carrying NO timestamp: the sink adds one, and a time
     * inside the record would make two firings of the same breach look like
     * two different breaches to anything that de-duplicates.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'alert' => $this->key,
            'severity' => $this->severity,
            'tenant_id' => $this->tenant,
            'entity_id' => $this->entityId,
            'period_id' => $this->period,
            'first_divergence' => $this->firstDivergence,
            'observed' => $this->observed,
            'threshold' => $this->threshold,
            'runbook' => $this->runbook(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
