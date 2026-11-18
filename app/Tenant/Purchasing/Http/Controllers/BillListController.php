<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Http\Controllers;

use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The supplier bills of the company being worked in - PF-133.
 *
 * ## The page is open and the figures are not
 *
 * The same split the Overview makes, for the same reason. Anyone holding a
 * role in the entity may see that this screen exists and what it is for;
 * `report.view` is what shows the amounts. A 403 served on a menu item the
 * user was just shown is a dead end, where a sentence naming what is missing
 * is something they can take to whoever grants it.
 *
 * ## The open balance is asked of the database, not computed here
 *
 * `peakflow_document_open_balance` is the one definition of what a document
 * still owes: gross, less allocations, less what a reconciliation difference
 * relieved, less what was written off. Subtracting `paid_amount` from
 * `gross_amount` here would be a second definition, and the two part company
 * the first time a payment is reversed or a difference is booked - at which
 * point this list and the payment run that asks the same question disagree
 * about which bills are still open, and both look right.
 *
 * ## The due date is derived, because a bill has no due-date column
 *
 * Payment terms are frozen onto the document at entry and can carry several
 * instalments, so the due date is the LAST of them: the day the bill is
 * finally clear. `Settlement\Services\PaymentRuns` reads it out of the same
 * snapshot the same way, which is what keeps "due" meaning one thing on the
 * screen somebody reads and in the run that pays it.
 */
final class BillListController
{
    /**
     * Twenty-five, matching the operator console's lists.
     *
     * Small enough that one page is about one screen at the comfortable
     * density, which is what makes the unapproved count above the table a
     * statement about rows the reader can actually see.
     */
    private const PER_PAGE = 25;

    /**
     * @no-permission-required: the list of a company's own purchase documents,
     * open to anybody holding a role in that company. Its amounts are gated on
     * `report.view` in the body below, exactly as the Overview's figures are.
     */
    public function __invoke(Request $request, EntityContext $context): View
    {
        $entity = $context->entity();
        $user = $request->user();

        $bills = null;

        if ($entity !== null && $user !== null && $user->can('report.view')) {
            $bills = Bill::query()
                /*
                 | The supplier, eager, for the fallback inside
                 | `Bill::supplierName()`.
                 |
                 | The frozen snapshot answers first, so the relation is never
                 | touched for most rows - but a bill entered before the
                 | snapshot carried a name falls through to the party, and on
                 | a page of twenty-five that is twenty-five more queries.
                 | Nobody notices until the list is slow for one customer and
                 | for nobody else.
                 */
                ->with('supplier')
                /*
                 | `bills.*` is stated because `selectRaw` REPLACES the select
                 | list rather than adding to it. Without it a Bill is
                 | hydrated from the two computed columns alone - no id, no
                 | status, no amounts - which renders as a document with every
                 | field empty rather than as an error.
                 */
                ->select('bills.*')
                /*
                 | `::text`, so the amount arrives as a string.
                 |
                 | A `numeric(21,4)` that the driver hands over as a float has
                 | already lost the exactness this system exists to keep, and
                 | the loss is small enough to pass for a rounding difference
                 | for months.
                 */
                ->selectRaw("peakflow_document_open_balance('bill', bills.id)::text AS open_balance")
                ->selectRaw(<<<'SQL'
                    coalesce((
                        SELECT max((d->>'date')::date)
                        FROM jsonb_array_elements(bills.terms_snapshot->'due_dates') d
                    ), bills.supplier_document_date)::text AS due_on
                SQL)
                /*
                 | Newest first by the date that is OURS.
                 |
                 | The posting date, not the supplier's. A bill dated the 28th
                 | of March that reached us on the 3rd of April belongs at the
                 | top of April's list rather than in the middle of March's,
                 | and the supplier's own date has a column of its own for
                 | whoever is working from their statement.
                 |
                 | The id after it, because a date is not a total order. With
                 | no tiebreak PostgreSQL returns rows sharing a posting date
                 | in whatever order the plan produces and is free to change
                 | it between two queries - so page two of a paginated list
                 | repeats rows page one already showed and silently skips
                 | others.
                 */
                ->orderByDesc('bills.posting_date')
                ->orderByDesc('bills.id')
                ->paginate(self::PER_PAGE);
        }

        return view('app.purchasing.bills.index', [
            'entity' => $entity,
            'bills' => $bills,
        ]);
    }
}
