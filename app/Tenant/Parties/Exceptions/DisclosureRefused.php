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

    /**
     * Refused for a payment instruction - PF-176.
     *
     * A separate message because it names a different permission, and
     * naming the wrong one here is worse than saying nothing: somebody
     * told to get `bank.manage` so they can produce a payment file would
     * acquire the permission that lets them CHANGE a supplier's bank
     * details - which is the conflict `beneficiary_then_release` exists
     * to prevent. The refusal would create the hole.
     */
    public static function notPermittedForAnInstruction(string $masked): self
    {
        return new self(sprintf(
            'Putting %s into a payment instruction needs the `payment.release` permission - the duty '.
            'that governs money leaving. Deliberately not `bank.manage`: that is what lets somebody '.
            'change a supplier\'s bank details, and whoever can do both can redirect a payment and '.
            'then send it.',
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
