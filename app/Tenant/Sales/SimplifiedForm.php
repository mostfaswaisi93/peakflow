<?php

declare(strict_types=1);

namespace App\Tenant\Sales;

use App\Support\Money\Money;

/**
 * What a jurisdiction's simplified invoice is - PF-101.
 *
 * A value object rather than an array, because three of its four fields mean
 * nothing without the others: a threshold with no currency is a number, and
 * `omits` on a profile that does not permit the form at all is a list nobody
 * should read.
 */
final readonly class SimplifiedForm
{
    /** @param list<string> $omits */
    public function __construct(
        public bool $permitted,
        public ?Money $threshold,
        public array $omits,
    ) {}

    public function hasNoValueLimit(): bool
    {
        return $this->permitted && $this->threshold === null;
    }

    public function omits(string $field): bool
    {
        return \in_array($field, $this->omits, true);
    }

    /**
     * Is a document of this gross within the form's limit?
     *
     * A document in another currency than the threshold's cannot be compared
     * without a rate, and a rate here would be this system deciding what the
     * regime's limit means in the document's currency. So it says no: the
     * standard form is always acceptable, and the simplified one is the
     * concession.
     */
    public function admits(Money $gross): bool
    {
        if (! $this->permitted) {
            return false;
        }

        if ($this->threshold === null) {
            return true;
        }

        if ($this->threshold->currency !== $gross->currency) {
            return false;
        }

        return ! $gross->isGreaterThan($this->threshold);
    }
}
