<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Exceptions;

use RuntimeException;

/**
 * Why a bank account was not disclosed - PF-035.
 *
 * Every message names the MASKED account, never the number. An exception
 * message reaches a log, a bug report and sometimes a screenshot.
 */
final class DisclosureRefused extends RuntimeException
{
    public static function notPermitted(string $masked): self
    {
        return new self(sprintf(
            'Reading the full number of %s needs the `bank.manage` permission. There is no weaker '.
            '"may view" permission on purpose: somebody who can see a supplier\'s account can use it '.
            'anywhere, and the change log would show nothing because nothing changed.',
            $masked,
        ));
    }

    public static function needsAReason(string $masked): self
    {
        return new self(sprintf(
            'Reading the full number of %s needs a reason of at least ten characters. The question an '.
            'investigation asks is not whether it was read but why, and a reason nobody typed is a '.
            'question nobody can answer.',
            $masked,
        ));
    }

    public static function noIban(string $masked): self
    {
        return new self(sprintf(
            'Account %s holds no IBAN. It is a domestic account number, which is read with '.
            'revealAccountNumber() - the two are different fields and an audit entry that named the '.
            'wrong one would be wrong in the record that matters most.',
            $masked,
        ));
    }

    public static function noAccountNumber(string $masked): self
    {
        return new self(sprintf('Account %s holds no domestic account number; it holds an IBAN.', $masked));
    }
}
