<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Exceptions;

use RuntimeException;

/**
 * A withholding deduction or certificate that will not be made - PF-083.
 */
final class WithholdingRefused extends RuntimeException
{
    public static function noRate(string $jurisdiction, string $date): self
    {
        return new self(sprintf(
            'No withholding rate applies in %s on %s. Returning zero would be indistinguishable from a '.
            'rate of zero, and the two are different: a zero rate is an exemption somebody recorded, '.
            'and no rate at all means nobody has configured the jurisdiction. The payer that withholds '.
            'nothing because of the second is liable for what it should have withheld.',
            $jurisdiction,
            $date,
        ));
    }

    public static function mixedCurrencies(string $net, string $tax): self
    {
        return new self(sprintf(
            'The net is in %s and the tax in %s. A payment is in one currency.',
            $net,
            $tax,
        ));
    }

    public static function moreThanTheBase(string $withheld, string $base): self
    {
        return new self(sprintf(
            'The deduction of %s is larger than the %s it is withheld from. That is a rate stored as a '.
            'percentage where the regime meant a fraction, or a misplaced decimal point, and the '.
            'certificate would give the payee a credit it is not owed.',
            $withheld,
            $base,
        ));
    }

    public static function nothingToCertify(): self
    {
        return new self(
            'A certificate for a deduction of zero would tell the payee it may claim nothing, which it '.
            'can work out without a document. An exemption is recorded as a zero rate on the rate '.
            'table, where it is dated and sourced, not as a certificate for nothing.',
        );
    }

    public static function notAMonth(string $period): self
    {
        return new self(sprintf(
            'The period "%s" is not a month in YYYY-MM form. The credit is claimed in the period the '.
            'deduction was made, so the period is part of the certificate rather than derived from '.
            'whenever somebody runs a report.',
            $period,
        ));
    }
}
