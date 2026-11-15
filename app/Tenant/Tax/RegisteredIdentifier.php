<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One tax registration, as the tax engine needs to see it - PF-079.
 *
 * A jurisdiction, a scheme, an identifier and the period it applied for. No
 * model, no relation, no party: the engine has to judge whether a counterparty
 * could account for tax on a date, and that question needs four facts.
 *
 * The period is half-open, `[from,until)`, the same shape the database stores:
 * `$until` is the first day the registration no longer applies, so a
 * successor's `from` is its predecessor's `until` and nothing anywhere does
 * arithmetic on "the day before".
 */
final readonly class RegisteredIdentifier
{
    public function __construct(
        public string $jurisdiction,
        public string $scheme,
        public string $identifier,
        public CarbonImmutable $from,
        public ?CarbonImmutable $until = null,
    ) {}

    public function isEffectiveOn(CarbonInterface $date): bool
    {
        $day = CarbonImmutable::parse($date->toDateString());

        return $day->greaterThanOrEqualTo($this->from)
            && ($this->until === null || $day->lessThan($this->until));
    }

    /** The last day it DOES apply, for a message somebody has to read. */
    public function lastDay(): ?CarbonImmutable
    {
        return $this->until?->subDay();
    }

    public function isOpenEnded(): bool
    {
        return $this->until === null;
    }

    public function qualified(): string
    {
        return sprintf('%s:%s:%s', $this->jurisdiction, $this->scheme, $this->identifier);
    }
}
