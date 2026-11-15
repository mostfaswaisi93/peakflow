<?php

declare(strict_types=1);

namespace App\Support\Iso\Domestic;

use App\Support\Iso\Exceptions\InvalidDomesticIdentifier;
use JsonSerializable;
use Stringable;

/**
 * A UK sort code - PF-008.
 *
 * ## Shape only, and the reason is stated rather than hidden
 *
 * The UK has a real check: VocaLink's modulus checking, which validates the
 * sort code together with the account number using per-sort-code weights from
 * the published `valacdos` table, with its own exception rules.
 *
 * PeakFlow does not implement it, because implementing it means shipping that
 * weight table, and shipping a weight table reconstructed from memory would
 * refuse genuine accounts. A refused genuine account stops a customer being
 * paid; an accepted wrong one costs a returned payment with a reason attached.
 * The second is recoverable.
 *
 * So this validates six digits and says so. The honest version of a missing
 * check is a documented absence, not a regular expression dressed up as one -
 * because a caller who believes the sort code was validated will not
 * double-check it, and a caller who knows it was not will.
 */
final readonly class UkSortCode implements JsonSerializable, Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = (string) preg_replace('/[\s\-]+/', '', $value);

        if (preg_match('/\A[0-9]{6}\z/', $normalized) !== 1) {
            throw InvalidDomesticIdentifier::shape('UK sort code', $value, 'exactly six digits');
        }

        $this->value = $normalized;
    }

    public static function tryFrom(string $value): ?self
    {
        try {
            return new self($value);
        } catch (InvalidDomesticIdentifier) {
            return null;
        }
    }

    /** `12-34-56`, which is how it is printed on a cheque and read aloud. */
    public function forDisplay(): string
    {
        return implode('-', str_split($this->value, 2));
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
