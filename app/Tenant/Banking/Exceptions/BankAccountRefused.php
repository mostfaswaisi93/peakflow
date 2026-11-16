<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Exceptions;

/**
 * A bank account the schema will not accept - PF-145.
 *
 * The messages name the structure rather than the field, because the refusals
 * that matter here are structural: an account shared across legal entities,
 * a GL link that is not a bank account, an opening balance changed after the
 * fact. Each of those is somebody modelling a real arrangement in the only
 * way the software appeared to allow, and the message has to say what to do
 * instead.
 */
final class BankAccountRefused extends \RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }

    public static function invalidIban(string $value): self
    {
        return new self(sprintf(
            '"%s" is not a valid IBAN. The check digits exist so that a typo is caught before the '.
            'money moves, and this one fails them - which means either a transposed pair of '.
            'characters or a length the country does not use.',
            $value,
        ));
    }
}

