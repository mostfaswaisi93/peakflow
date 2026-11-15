<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Exceptions;

use App\Tenant\Tax\Enums\TaxPointBasis;
use RuntimeException;

/**
 * A tax point that will not be guessed - PF-077.
 *
 * The alternative to throwing is falling back to the invoice date, which is
 * always present. That is the whole failure this refuses: a delivery-date rule
 * applied to a document with no delivery date and quietly answered with the
 * invoice date is a document in the wrong return period, filed, with nothing
 * anywhere saying so.
 */
final class TaxPointUndeterminable extends RuntimeException
{
    /** @param  list<string>  $missing */
    public static function missingDates(string $profile, TaxPointBasis $basis, array $missing): self
    {
        $fields = [
            'invoice' => 'an invoice date',
            'delivery' => 'a delivery date (EN 16931 BT-72)',
            'payment' => 'a payment date',
        ];

        return new self(sprintf(
            'The tax point cannot be derived: the %s profile takes it from %s, and this document has no '.
            '%s. Falling back to the invoice date would put the supply in the wrong return period, which '.
            'is found by the authority rather than by the business - so the document needs the date '.
            'before it can be issued.',
            $profile,
            $basis->explanation('en'),
            implode(' and no ', array_map(static fn (string $f): string => $fields[$f] ?? $f, $missing)),
        ));
    }

    public static function unknownBasis(string $profile, string $basis): self
    {
        return new self(sprintf(
            'The %s profile declares the tax point basis "%s", which this engine does not implement. The '.
            'five it knows are the invoice date, the delivery date, the payment date, and the earlier of '.
            'either pair. Guessing would apply a rule nobody wrote.',
            $profile,
            $basis,
        ));
    }
}
