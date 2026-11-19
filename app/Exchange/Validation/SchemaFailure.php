<?php

declare(strict_types=1);

namespace App\Exchange\Validation;

use App\Exchange\Semantics\BusinessTerm as BT;

/**
 * An XSD validation error, addressed by business term - PF-114, PF-117.
 *
 * ## What libxml hands over, and why it is not enough
 *
 * ```
 * Element '{urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2}TaxInclusiveAmount':
 * 'nine' is not a valid value of the atomic type 'xsd:decimal'.
 * ```
 *
 * That sentence contains the three things a reader needs and none of them in
 * a form they can use. The **element** is wrapped in a namespace URI that
 * nobody types. The **rule** is prose with a numeric code beside it that the
 * caller throws away. And the **business term** - `BT-112`, the invoice
 * total with VAT - is not in it at all, although it is the only part a
 * bookkeeper can act on.
 *
 * Before this class, stage 2 reported `line 41: <that sentence>`. The
 * element and the rule were technically in the string, in the sense that a
 * person could read them out of it; nothing addressed the failure and
 * nothing could be grouped, counted or translated.
 *
 * ## The map is small on purpose, and it is verified
 *
 * An XSD failure names an ELEMENT, and most UBL element names are ambiguous
 * on their own: `cbc:LineExtensionAmount` is `BT-106` at document level and
 * `BT-131` on a line, and `cbc:ID` is forty different terms. Mapping an
 * ambiguous name would address the failure to the wrong term, which is worse
 * than leaving it unaddressed - so the table holds only names that mean one
 * business term wherever they appear, and an unmapped element still reports
 * the element and the rule with no term, which is what "mapped back to a
 * business term **where possible**" means.
 *
 * `EInvoicingCoverageRules` serializes a real document and asserts that
 * every entry here appears in it carrying that term's value. A table whose
 * entries were plausible and wrong would otherwise be the most convincing
 * thing in the pipeline.
 */
final class SchemaFailure
{
    /** The prefix each syntax's namespace URIs are written with. */
    private const PREFIXES = [
        'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2' => 'cbc',
        'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2' => 'cac',
        'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2' => 'ext',
        'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2' => 'ubl',
        'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2' => 'ubl',
        'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100' => 'rsm',
        'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100' => 'ram',
        'urn:un:unece:uncefact:data:standard:QualifiedDataType:100' => 'qdt',
        'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100' => 'udt',
    ];

    /**
     * Element local name to business term, per syntax.
     *
     * Only names that are unambiguous within the syntax. See the class
     * docblock for why the list is not longer.
     *
     * @var array<string, array<string, string>>
     */
    private const TERMS = [
        'ubl' => [
            'CustomizationID' => 'BT-24',
            'ProfileID' => 'BT-23',
            'DueDate' => 'BT-9',
            'TaxPointDate' => 'BT-7',
            'DocumentCurrencyCode' => 'BT-5',
            'TaxCurrencyCode' => 'BT-6',
            'BuyerReference' => 'BT-10',
            'TaxExclusiveAmount' => 'BT-109',
            'TaxInclusiveAmount' => 'BT-112',
            'AllowanceTotalAmount' => 'BT-107',
            'ChargeTotalAmount' => 'BT-108',
            'PrepaidAmount' => 'BT-113',
            'PayableRoundingAmount' => 'BT-114',
            'PayableAmount' => 'BT-115',
        ],
        'cii' => [
            'TaxCurrencyCode' => 'BT-6',
            'InvoiceCurrencyCode' => 'BT-5',
            'TaxBasisTotalAmount' => 'BT-109',
            'GrandTotalAmount' => 'BT-112',
            'AllowanceTotalAmount' => 'BT-107',
            'ChargeTotalAmount' => 'BT-108',
            'TotalPrepaidAmount' => 'BT-113',
            'RoundingAmount' => 'BT-114',
            'DuePayableAmount' => 'BT-115',
        ],
    ];

    /**
     * The element-to-term table for a syntax, for the coverage check.
     *
     * @return array<string, string>
     */
    public static function termsFor(string $syntax): array
    {
        return self::TERMS[$syntax] ?? [];
    }

    /** The element an error names, as `prefix:LocalName`, or null. */
    public static function elementIn(string $message): ?string
    {
        if (preg_match("/Element\s+'(?:\{([^}]*)\})?([^'\/]+)'/", $message, $found) !== 1) {
            return null;
        }

        $local = trim($found[2]);
        $prefix = self::PREFIXES[trim($found[1] ?? '')] ?? null;

        return $prefix === null ? $local : $prefix.':'.$local;
    }

    /**
     * One libxml schema error as a violation that names all three things.
     *
     * Reported as `internal`: a document that does not match the schema was
     * produced by our own serializer from figures the ledger has posted, so
     * it is a serializer defect and not a message for whoever filled the
     * invoice in.
     */
    public static function from(\LibXMLError $error, string $syntax): SemanticViolation
    {
        $message = trim(preg_replace('/\s+/', ' ', $error->message) ?? $error->message);
        $element = self::elementIn($message);
        $term = self::term($syntax, $element);

        return SemanticViolation::internal(
            'EN16931-SCHEMA',
            term: $term,
            detail: sprintf(
                '%s does not match the %s at line %d, column %d: %s%s',
                $element === null ? 'The document' : 'Element <'.$element.'>',
                $syntax === '' ? 'schema' : strtoupper($syntax).' schema',
                $error->line,
                $error->column,
                $message,
                // libxml's own code, so one failure is the same failure
                // across versions even when the prose is reworded.
                sprintf(' [XML Schema rule, libxml %d]', $error->code),
            ),
        )->withPath($element ?? 'document');
    }

    private static function term(string $syntax, ?string $element): ?BT
    {
        if ($element === null) {
            return null;
        }

        $local = str_contains($element, ':') ? substr($element, strpos($element, ':') + 1) : $element;
        $id = self::TERMS[$syntax][$local] ?? null;

        return $id === null ? null : BT::tryFrom($id);
    }
}
