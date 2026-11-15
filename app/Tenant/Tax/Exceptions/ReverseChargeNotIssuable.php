<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Exceptions;

use RuntimeException;

/**
 * A reverse charge that will not be issued - PF-079.
 *
 * ## Why each of these refuses rather than warns
 *
 * A reverse charge is the one tax treatment where the supplier collects
 * nothing and is still liable if it was wrong. The customer accounts for the
 * tax; if the customer turns out not to have been able to - no registration,
 * or one that had lapsed - the supply was taxable all along, and the authority
 * collects it from the party that issued the invoice.
 *
 * So the refusals are at issue time, when there is still a document to fix,
 * rather than warnings in a log read after the return is filed.
 */
final class ReverseChargeNotIssuable extends RuntimeException
{
    public static function notAReverseCharge(string $category): self
    {
        return new self(sprintf(
            'This supply is in category %s, not AE, so there is no reverse charge to issue. Asked and '.
            'refused rather than quietly doing nothing: a caller that reaches here believes the customer '.
            'is accounting for the tax, and nobody would be.',
            $category,
        ));
    }

    public static function theSupplierIsNotRegistered(string $jurisdiction, string $taxPoint): self
    {
        return new self(sprintf(
            'The supplying entity has no tax registration in %s valid on %s, so it cannot issue a '.
            'reverse-charge invoice. An unregistered supplier has no standing to shift the liability, '.
            'and the document it issues leaves the tax uncollected by either party.',
            $jurisdiction,
            $taxPoint,
        ));
    }

    public static function theCustomerIsNotRegistered(string $jurisdiction, string $taxPoint): self
    {
        return new self(sprintf(
            'The customer has no tax registration in %s valid on %s, which is where this supply is '.
            'taxed - so there is nobody to account for the tax. A reverse charge issued anyway is a '.
            'taxable supply with no tax on it: the authority collects from the supplier, years later, '.
            'with penalties. If the customer genuinely cannot prove a registration it is a consumer for '.
            'this purpose, and the supply is taxed normally.',
            $jurisdiction,
            $taxPoint,
        ));
    }

    public static function theRegistrationHadLapsed(
        string $who,
        string $jurisdiction,
        string $identifier,
        string $taxPoint,
        string $lastDay,
    ): self {
        return new self(sprintf(
            'The %s holds registration %s in %s, but it stopped applying on %s and this supply\'s tax '.
            'point is %s. A cancelled registration is indistinguishable from a current one unless '.
            'somebody checks the date, which is the whole reason the validity is stored as a range.',
            $who,
            $identifier,
            $jurisdiction,
            $lastDay,
            $taxPoint,
        ));
    }

    public static function noNote(string $profile): self
    {
        return new self(sprintf(
            'The %s profile declares no reverse-charge note. The note is a mandatory legal mention - '.
            'Directive 2006/112/EC Art. 226(11a), carried as EN 16931 BT-120 - and it is the only thing '.
            'on a reverse-charge invoice that says why no tax is shown. Falling back to a generic phrase '.
            'would print a document that satisfies nobody\'s rules and looks complete.',
            $profile,
        ));
    }

    public static function noNoteInThatLanguage(string $profile, string $locale): self
    {
        return new self(sprintf(
            'The %s profile\'s reverse-charge note has no "%s" text. An invoice is read by whoever '.
            'receives it, and a note present in one language only is a note missing from half the '.
            'documents.',
            $profile,
            $locale,
        ));
    }
}
