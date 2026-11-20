<?php

declare(strict_types=1);

namespace App\Tenant\Sales;

use Illuminate\Support\Facades\DB;

/**
 * Where a line's supply type comes from - PF-078.
 *
 * ## One lookup, because there were going to be seven
 *
 * Six draft paths create invoice lines and each needs the same answer: what
 * kind of supply is this, so the rule book can place it. Written out six
 * times, that is six chances for one of them to read a column that does not
 * exist, or to default where the others do not - and the defect this closes
 * was six copies of the literal `'domestic'`, so repeating the shape would
 * be repeating the mistake with a different value in it.
 *
 * ## Copied onto the line, never read through to the item
 *
 * `items.type` is editable and a document is not. An item retyped from
 * `good` to `service` next year must not change what an issued invoice says
 * the supply was, because an authority asking about that invoice is asking
 * about the supply as it was made.
 *
 * ## Null is an answer
 *
 * A free-text line with no item states nothing, and nothing is what this
 * returns. The decider records the line as undecided with that reason, and
 * `InvoiceValidator` refuses to issue it - `place_of_supply_unresolved`,
 * which has been in the validator since PF-091 and was satisfied for years
 * by the literal that caused the problem.
 */
final readonly class SupplyTypes
{
    /**
     * The line's own statement, else its item's type, else nothing.
     *
     * @param  ?string  $stated  what the caller said this line is
     * @param  ?string  $itemId  the catalogue item, if the line has one
     */
    public static function of(?string $stated, ?string $itemId): ?string
    {
        if (\is_string($stated) && $stated !== '') {
            return self::known($stated);
        }

        if ($itemId === null || $itemId === '') {
            return null;
        }

        $type = DB::connection('tenant')->table('items')->where('id', $itemId)->value('type');

        return \is_string($type) ? self::known($type) : null;
    }

    /**
     * The three the column's CHECK constraint permits, and nothing else.
     *
     * A value the constraint would refuse is turned into null rather than
     * passed through: the alternative is an insert that fails on a line
     * somebody is typing, with a constraint name for a message.
     */
    private static function known(string $type): ?string
    {
        return \in_array($type, ['good', 'service', 'digital_service'], true) ? $type : null;
    }
}
