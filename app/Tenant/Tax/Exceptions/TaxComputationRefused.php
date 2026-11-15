<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Exceptions;

use RuntimeException;

/**
 * A document whose tax cannot be computed - PF-082.
 *
 * Each of these is a document that would otherwise be issued with a number
 * that looks reasonable. That is the test for whether something belongs here:
 * not "is this unusual" but "would the wrong answer be visible".
 */
final class TaxComputationRefused extends RuntimeException
{
    public static function withNoLines(): self
    {
        return new self(
            'A document with no lines has no tax. Refused rather than returning an empty breakdown, '.
            'because an empty breakdown is a valid-looking object: it satisfies BR-CO-14, totals zero, '.
            'and would be filed.',
        );
    }

    public static function mixedCurrencies(string $first, string $second): self
    {
        return new self(sprintf(
            'This document mixes %s and %s lines. One document is in one currency - the tax is reported '.
            'to one authority in one currency, and a breakdown summing two of them is a number with no '.
            'meaning. A supply in another currency is another document.',
            $first,
            $second,
        ));
    }

    public static function adjustmentInAnotherCurrency(string $document, string $adjustment): self
    {
        return new self(sprintf(
            'A document-level adjustment is in %s and the document is in %s.',
            $adjustment,
            $document,
        ));
    }

    public static function nothingToApportionAcross(string $adjustment): self
    {
        return new self(sprintf(
            'A document-level adjustment of %s cannot be apportioned: no rate group has a positive '.
            'taxable amount. Apportioning by absolute value instead would spread a discount onto credit '.
            'lines and make the credit larger, which is the opposite of what was asked for. A document '.
            'that is entirely credits needs its adjustment on the lines.',
            $adjustment,
        ));
    }

    public static function aZeroCategoryCarriesARate(string $category, string $rate): self
    {
        return new self(sprintf(
            'A line is in category %s at %s%%. The six zero categories cannot carry a rate - the '.
            'database refuses one on a tax code, and accepting one here would compute tax on a supply '.
            'the document declares as bearing none.',
            $category,
            $rate,
        ));
    }

    public static function aTaxableCategoryCarriesNoRate(string $category): self
    {
        return new self(sprintf(
            'A line is in category %s with a rate of zero. A standard-rated line at 0%% is either a '.
            'zero-rated supply wearing the wrong category - which reports a taxable supply at the wrong '.
            'rate - or a rate nobody resolved. Both are worth refusing.',
            $category,
        ));
    }
}
