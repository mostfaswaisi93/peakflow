<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Tax is DATA, not code
    |---------------------------------------------------------------------------
    |
    | A rate that changes on a date is a row. A jurisdiction's rounding rule is
    | a row. A place-of-supply decision is a rule evaluated from rows. Nothing
    | about a specific country is compiled into the sales path.
    |
    | What lives here is the small set of ceilings and defaults the operator
    | owns. Everything else is in the database, versioned and effective-dated.
    |
    */

    /*
    |---------------------------------------------------------------------------
    | Categories (UNCL 5305)
    |---------------------------------------------------------------------------
    |
    | Z, E and O are THREE DIFFERENT THINGS and must never be modelled as
    | "0% with a note":
    |
    |   S   Standard rate.          Taxable.      Input recoverable.
    |   Z   Zero-rated.             TAXABLE at 0%. Input RECOVERABLE.
    |   E   Exempt.                 NOT taxable.  Input RESTRICTED.
    |   AE  Reverse charge.         Recipient accounts for it, BOTH sides.
    |   O   Out of scope.           Outside the regime entirely.
    |   K   Intra-community supply. Needs both registrations.
    |   G   Export.                 Needs proof of export.
    |
    | Collapsing them produces a return that is wrong in the INPUT TAX boxes -
    | the half nobody checks until an audit.
    |
    */

    'categories' => [
        'S' => ['taxable' => true, 'input_recoverable' => true, 'requires_reason' => false],
        'Z' => ['taxable' => true, 'input_recoverable' => true, 'requires_reason' => true],
        'E' => ['taxable' => false, 'input_recoverable' => false, 'requires_reason' => true],
        'AE' => ['taxable' => true, 'input_recoverable' => true, 'requires_reason' => true, 'reverse_charge' => true],
        'O' => ['taxable' => false, 'input_recoverable' => false, 'requires_reason' => true],
        'K' => ['taxable' => true, 'input_recoverable' => true, 'requires_reason' => true],
        'G' => ['taxable' => true, 'input_recoverable' => true, 'requires_reason' => true, 'requires_evidence' => 'export_proof'],
    ],

    /*
    |---------------------------------------------------------------------------
    | Tax point derivation
    |---------------------------------------------------------------------------
    |
    | The tax point is ITS OWN DATE, distinct from the invoice date and the
    | delivery date, and it determines which return period the tax falls in.
    |
    | The derived value is STORED ON THE DOCUMENT, not recomputed: a document
    | issued on the 1st for a supply on the 30th belongs to the earlier return
    | in many jurisdictions, and that decision must be frozen at issue.
    |
    | This is the FALLBACK for a profile that does not specify one.
    |
    | 'invoice_date' | 'delivery_date' | 'payment_date' | 'earlier_of_invoice_delivery'
    |
    */

    'default_tax_point' => 'invoice_date',

    /*
    |---------------------------------------------------------------------------
    | Place of supply
    |---------------------------------------------------------------------------
    |
    | The fallback when no rule matches. 'refuse' is the only safe default: a
    | guess produces an invoice with the wrong jurisdiction's tax on it, and
    | nobody finds out until a return is filed.
    |
    */

    'place_of_supply_fallback' => 'refuse',

    /*
    |---------------------------------------------------------------------------
    | Ceilings
    |---------------------------------------------------------------------------
    |
    | A tenant may LOWER any of these and can never raise one. "Write off
    | anything under 50" is a policy decision, not a developer's constant - and
    | a generous tolerance quietly absorbs real losses.
    |
    */

    'ceilings' => [
        'writeoff_tolerance' => env('TAX_WRITEOFF_TOLERANCE_CEILING', '50.0000'),
        'rounding_tolerance' => env('TAX_ROUNDING_TOLERANCE_CEILING', '1.0000'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Return filing
    |---------------------------------------------------------------------------
    |
    | Filing freezes a snapshot - the figures, the supporting line ids and a
    | hash - which cannot change afterwards even if the underlying data is
    | later adjusted. A filed return is re-printable BYTE-IDENTICALLY, which is
    | what gets attached to a query from the authority.
    |
    | A non-zero unexplained difference between the tax control accounts and
    | the return BLOCKS the period close unless a controller signs it off with
    | a reason - and that sign-off appears on the compliance evidence pack.
    |
    */

    'returns' => [
        'freeze_on_file' => true,
        'require_mfa_to_file' => true,
        'block_close_on_unexplained_difference' => true,
        'post_filing_adjustment' => 'next_open_period',
    ],

];
