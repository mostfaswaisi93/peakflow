<?php

declare(strict_types=1);

namespace App\Support\Iso\Domestic;

use App\Support\Iso\Exceptions\InvalidDomesticIdentifier;
use JsonSerializable;
use Stringable;

/**
 * An Australian Bank State Branch number - PF-008.
 *
 * Six digits, conventionally written `NNN-NNN`: the first two are the bank,
 * the third the state, the last three the branch. There is no check digit -
 * APCA's register is the only authority on whether a BSB exists - so this
 * validates the shape and the bank segment being allocated at all, and says
 * plainly that it cannot tell a real branch from a plausible one.
 */
final readonly class AuBsb implements JsonSerializable, Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = (string) preg_replace('/[\s\-]+/', '', $value);

        if (preg_match('/\A[0-9]{6}\z/', $normalized) !== 1) {
            throw InvalidDomesticIdentifier::shape('BSB', $value, 'exactly six digits, written NNN-NNN');
        }

        /*
         | `00` is not an allocated bank.
         |
         | The same reasoning as the ABA prefix: without this, `000000` is a
         | valid BSB, and `000000` is what somebody types to get past a
         | required field.
         */
        if (substr($normalized, 0, 2) === '00') {
            throw InvalidDomesticIdentifier::shape(
                'BSB',
                $value,
                'a bank segment other than 00, which is not allocated',
            );
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

    public function forDisplay(): string
    {
        return substr($this->value, 0, 3).'-'.substr($this->value, 3);
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
