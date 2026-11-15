<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use Carbon\CarbonInterface;

/**
 * What the tax engine knows about the other side's registrations - PF-079.
 *
 * ## Why this exists rather than a Party
 *
 * The layering ruleset does not let Tax depend on Parties, and that rule is
 * right: the tax engine decides treatments, and a module that reaches into
 * another module's models ends up deciding them from whatever happens to be
 * on a record. So Tax declares what it needs - registrations with their
 * periods - and whoever has a party builds it. That is `Sales` or
 * `Purchasing`, both of which may depend on both.
 *
 * ## The two sides are not symmetrical, deliberately
 *
 * `ReverseCharge::assertIssuable()` takes an `Entity` for the supplier and
 * this for the customer, which looks inconsistent and is accurate. An entity
 * is the tenant's own company and lives in `Tenancy`, which Tax may depend on
 * and does throughout - it is where the functional currency and the entity's
 * own registrations come from. A party is a counterparty, and the tax engine
 * has no business knowing what one is.
 */
final readonly class CounterpartyRegistrations
{
    /** @param  list<RegisteredIdentifier>  $registrations */
    public function __construct(private array $registrations = []) {}

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(RegisteredIdentifier ...$registrations): self
    {
        return new self(array_values($registrations));
    }

    /**
     * The registration that applied in a jurisdiction on a date.
     *
     * The date is required. There is no "current registration" method, for the
     * same reason there is no `TaxCodes::current()`: every caller here is
     * asking about a document, every document has a tax point, and a method
     * that answered for today would be the one everybody reached for.
     */
    public function validOn(string $jurisdiction, CarbonInterface $date): ?RegisteredIdentifier
    {
        foreach ($this->in($jurisdiction) as $registration) {
            if ($registration->isEffectiveOn($date)) {
                return $registration;
            }
        }

        return null;
    }

    /**
     * A registration in that jurisdiction that had ALREADY ENDED by the date.
     *
     * Asked only to produce a better refusal. "No registration" and "a
     * registration that expired in April" send somebody to two different
     * places - the first to the customer record, the second to the customer -
     * and a refusal that cannot tell them apart gets resolved by typing a
     * number in.
     */
    public function lapsedBefore(string $jurisdiction, CarbonInterface $date): ?RegisteredIdentifier
    {
        $day = $date->toDateString();
        $best = null;

        foreach ($this->in($jurisdiction) as $registration) {
            if ($registration->until === null || $registration->until->toDateString() > $day) {
                continue;
            }

            if ($best === null || $registration->until->greaterThan($best->until)) {
                $best = $registration;
            }
        }

        return $best;
    }

    /** @return list<RegisteredIdentifier> */
    public function in(string $jurisdiction): array
    {
        return array_values(array_filter(
            $this->registrations,
            static fn (RegisteredIdentifier $r): bool => $r->jurisdiction === $jurisdiction,
        ));
    }

    /** @return list<RegisteredIdentifier> */
    public function all(): array
    {
        return $this->registrations;
    }

    public function isEmpty(): bool
    {
        return $this->registrations === [];
    }
}
