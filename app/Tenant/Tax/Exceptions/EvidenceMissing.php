<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Exceptions;

use App\Tenant\Tax\Enums\EvidenceKind;
use App\Tenant\Tax\Enums\TaxCategory;
use RuntimeException;

/**
 * A treatment claimed without what it needs - PF-080.
 *
 * ## Why at issue, not at audit
 *
 * Because at audit it is too late in a way that nothing can undo. An export
 * zero rate with no proof of exit is assessed with the tax, interest and a
 * penalty, and the supply happened years earlier - the goods are gone, the
 * forwarder has changed systems, and the customer has no reason to help.
 *
 * At issue the document is still in the business's hands. It can be held for
 * a day while somebody finds the customs declaration, and the refusals below
 * say which document to look for.
 */
final class EvidenceMissing extends RuntimeException
{
    /** @param  list<EvidenceKind>  $missing */
    public static function beforeIssuing(string $rule, TaxCategory $category, array $missing): self
    {
        $needed = implode('; ', array_map(
            static fn (EvidenceKind $kind): string => sprintf(
                '%s (%s)', $kind->label('en'), $kind->examples('en'),
            ),
            $missing,
        ));

        return new self(sprintf(
            'This supply takes category %s by rule "%s", which cannot be claimed without %s. Refused at '.
            'issue rather than found at audit: an export rate with no proof of exit is assessed with the '.
            'tax, interest and a penalty, years later, when the goods are gone and the forwarder has '.
            'changed systems. Today the document can be held while somebody finds the paperwork.',
            $category->value,
            $rule,
            $needed,
        ));
    }

    public static function withABlankReference(EvidenceKind $kind): self
    {
        return new self(sprintf(
            'The %s is recorded with no reference. A ticked box with nothing behind it is worse than '.
            'nothing: the system says the evidence exists, so nobody looks for it, and the assessment is '.
            'unanswerable. Record the document number.',
            $kind->label('en'),
        ));
    }

    public static function noReasonCode(TaxCategory $category): self
    {
        return new self(sprintf(
            'Category %s requires an exemption reason - EN 16931 BT-121 coded and BT-120 as text - and '.
            'none was given. An invoice that says 0%% with no reason is an invoice that cannot be '.
            'defended.',
            $category->value,
        ));
    }

    public static function unknownReasonCode(string $code, TaxCategory $category): self
    {
        return new self(sprintf(
            'The exemption reason code "%s" is not in the list this engine knows. Refused rather than '.
            'passed through: a code is the first thing a receiving system validates, and an invented one '.
            'fails a document that was otherwise correct - after it was filed. The list in '.
            'config/tax_evidence.php is an explicit subset, and adding %s\'s code to it is a row.',
            $code,
            $category->value,
        ));
    }

    /** @param  list<string>  $expected */
    public static function reasonCodeForTheWrongCategory(
        string $code,
        TaxCategory $belongsTo,
        TaxCategory $used,
        array $expected,
    ): self {
        return new self(sprintf(
            'The reason code "%s" belongs to category %s and was used on a %s supply. %s A code that '.
            'contradicts its category reports something that did not happen: "intra-Community supply" on '.
            'an exempt line puts a figure in a box the other member state will look for and not find.',
            $code,
            $belongsTo->value,
            $used->value,
            $expected === []
                ? sprintf('No code is shipped for %s; see config/tax_evidence.php.', $used->value)
                : sprintf('The codes for %s are %s.', $used->value, implode(', ', $expected)),
        ));
    }

    public static function noCodeShippedFor(TaxCategory $category): self
    {
        return new self(sprintf(
            'Category %s requires an exemption reason and no code for it is shipped. Inventing one would '.
            'put a code on a document that the receiving system rejects, so this refuses and names the '.
            'gap: config/tax_evidence.php lists the categories it has no codes for, and filling one in '.
            'is a row with its article.',
            $category->value,
        ));
    }
}
