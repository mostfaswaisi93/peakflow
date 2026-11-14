<?php

declare(strict_types=1);

namespace App\Support\Money\Exceptions;

final class CurrencyMismatch extends MoneyException
{
    public static function between(string $left, string $right): self
    {
        return new self(sprintf(
            'Cannot combine %s with %s. Convert one of them through an explicit FX rate first - an implicit conversion would hide which rate was used, and the rate used is part of the audit trail.',
            $left,
            $right,
        ));
    }
}
