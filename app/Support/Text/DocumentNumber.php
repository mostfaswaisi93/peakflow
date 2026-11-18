<?php

declare(strict_types=1);

namespace App\Support\Text;

/**
 * A document number, in the form two of them are COMPARED in - PF-257.
 *
 * ## Why this moved out of `Bill`
 *
 * It was a static method on the Purchasing model, and payroll needed
 * it: a salary bill carries a reference we generate, and it goes into
 * the same unique index as every supplier's invoice number. Reaching
 * into another module's model is what the architecture rule forbids,
 * and for a good reason - but the answer is not a second
 * implementation. Two normalisers that drift produce one key that two
 * modules disagree about, and the symptom is a duplicate the index
 * accepts.
 *
 * So it lives in `Support`, where both may use it and neither owns it.
 * Found by the architecture check after payroll called the model
 * directly.
 *
 * ## What it does, and the one thing it deliberately does not
 *
 * `INV-001`, `inv 001` and `INV001` are the same document from the
 * supplier's point of view, and a unique constraint on the raw string
 * catches only the lazy half of the duplicate problem. Letters and
 * digits, upper-cased: everything a human would call punctuation goes.
 *
 * Leading zeros are KEPT. `INV-001` and `INV-1` are frequently
 * different documents from the same supplier, and collapsing them would
 * refuse a real invoice - a false refusal is worse than a near-match
 * warning, because the warning asks a person and the refusal does not.
 */
final class DocumentNumber
{
    public static function comparable(?string $number): string
    {
        if ($number === null) {
            return '';
        }

        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number));
    }
}
