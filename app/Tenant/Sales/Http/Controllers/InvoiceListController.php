<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Http\Controllers;

use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The invoice list.
 *
 * ## The page is open and the amounts are not
 *
 * The same split as the Overview: anyone with a role in the entity may reach
 * this page, and the amounts on it need `report.view`. A user without it gets
 * a page that says which company they are in and why the list is absent,
 * rather than a 403 from a menu item their own sidebar offered them.
 *
 * ## Read-only, and that is a boundary rather than an unfinished state
 *
 * Nothing is drafted, issued or credited from here. Each of those is a
 * numbering decision and a posting, each needs its own permission and its own
 * segregation-of-duties check, and a GET controller that later grew a POST is
 * exactly where those checks are forgotten - the write looks like one more
 * branch of a page that was already authorized.
 */
final class InvoiceListController
{
    /**
     * @no-permission-required: the list is reachable by anyone with a role in
     * the entity, because the sidebar offers it to them. The amounts are
     * gated on `report.view` in the body below; without it the page shows the
     * entity the user is already authorized to act in, and a sentence.
     */
    public function __invoke(Request $request, EntityContext $context): View
    {
        $entity = $context->entity();
        $user = $request->user();

        $mayReadAmounts = $entity !== null && $user !== null && $user->can('report.view');

        /*
         | The open balance is asked of the database function, never restated
         | here - PF-172.
         |
         | `peakflow_document_open_balance` is gross, less credit notes, less
         | allocations, less what a reconciliation difference relieved, less
         | what was written off. Those are the same five terms the allocation
         | trigger enforces, and the function exists because three copies of
         | that formula kept in step by hand had already drifted once. A list
         | that restated it would eventually show a customer as owing money
         | the ledger says arrived.
         |
         | `::text` so the amount comes back as a string. Read through a
         | float it would be wrong by a fraction that grows with the row
         | count - which is exactly the kind of difference somebody then
         | spends a week looking for.
         */
        $invoices = $mayReadAmounts
            ? Invoice::query()
                ->with('party')
                ->select('invoices.*')
                ->selectRaw("peakflow_document_open_balance('invoice', invoices.id)::text AS open_balance")
                ->orderByDesc('issue_date')
                /*
                 | And then by id, because `issue_date` is not unique.
                 |
                 | Where a page boundary falls inside a group of invoices
                 | sharing an issue date, the database is free to break the
                 | tie however it likes - and free to break it differently on
                 | the query that fetches page two. One invoice is then
                 | listed twice and another never appears at all, on a list
                 | somebody is reconciling against their own records. The id
                 | is a ULID, so descending is still newest-first inside the
                 | day.
                 */
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString()
            : null;

        return view('app.sales.invoices.index', [
            'entity' => $entity,
            'invoices' => $invoices,
        ]);
    }
}
