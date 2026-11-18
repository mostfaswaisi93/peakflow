<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Http\Controllers;

use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One invoice, as it was issued.
 *
 * ## A document from another entity is absent, not forbidden
 *
 * The lookup goes through the entity scope, so an id belonging to a sister
 * company resolves to nothing and the page is a 404 - the same answer as an
 * id that was never issued at all. A 403 would be worse than returning the
 * row: it would confirm that the id exists somewhere in the workspace, which
 * is enough to walk a numbering series and learn how much another company in
 * the group invoices.
 *
 * ## The amounts are gated, the identity is not
 *
 * Without `report.view` the header still says which invoice this is and to
 * whom, because a user who followed a link needs to know they are in the
 * right place before they ask somebody for access. The lines, the breakdown
 * and the totals are replaced by one sentence.
 *
 * ## Allocations are read as rows, not through Settlement
 *
 * Settling a document is Settlement's work, and `Sales` may not depend on it:
 * the dependency runs the other way round, and inverting it here would make a
 * sales screen a second place that decides what "paid" means. So the payments
 * are read as rows, and the figure that matters is asked of
 * `peakflow_document_open_balance` - the one definition of an open balance,
 * which is also the one the allocation trigger enforces.
 */
final class InvoiceDetailController
{
    /**
     * @no-permission-required: a document anybody with a role in the entity
     * may open, scoped to that entity by the Eloquent scope and again by
     * row-level security. The amounts are gated on `report.view` below.
     */
    public function __invoke(Request $request, string $invoice, EntityContext $context): View
    {
        $user = $request->user();

        /*
         | `findOrFail` under the entity scope, deliberately.
         |
         | The scope adds `entity_id = <current>` and the row-level security
         | policy adds it again on the connection, so an id from another
         | entity matches no row and this throws the same 404 as an id that
         | never existed. Neither mechanism trusts the other, and nothing in
         | the response tells the two cases apart.
         |
         | `lines()` imposes its own line order, so nothing here adds one: a
         | second order clause would be appended rather than replace it, and
         | the first one wins.
         */
        $document = Invoice::query()
            ->with(['party', 'lines'])
            ->findOrFail($invoice);

        /*
         | The breakdown is ordered here, because the relation imposes no
         | order of its own.
         |
         | Unordered, the VAT rows print in whatever sequence they were
         | written, which is stable until somebody reissues or corrects the
         | document. A reader comparing two prints of the same invoice would
         | see the rows move and reasonably wonder what else had.
         */
        $taxBreakdown = $document->taxBreakdown()
            ->orderBy('category')
            ->orderBy('rate')
            ->get();

        $mayReadAmounts = $user !== null && $user->can('report.view');

        /*
         | The entity is bound explicitly, even though row-level security
         | already constrains the connection.
         |
         | A raw statement is precisely what bypasses the Eloquent scope, so
         | the one place in this module that reads `allocations` states the
         | entity itself rather than leaning on a session variable it did not
         | set. The two mechanisms are meant to be independent; a query that
         | relies on only one of them makes them a single mechanism with two
         | names.
         */
        $entityId = (string) $context->current();
        $documentId = (string) $document->getKey();

        $openBalance = $mayReadAmounts
            ? DB::connection('tenant')->selectOne(
                "SELECT peakflow_document_open_balance('invoice', ?::char(26))::text AS open_balance",
                [$documentId],
            )
            : null;

        /*
         | Reversed allocations are listed, not hidden.
         |
         | A reversal is a row here rather than a delete, so that "it was
         | against this invoice and somebody changed it" can be answered -
         | which is the question asked the moment a customer disputes a
         | statement. They are shown with the word, and they are outside the
         | open balance, because the function excludes them.
         */
        $allocations = $mayReadAmounts
            ? DB::connection('tenant')->select(<<<'SQL'
                SELECT p.number AS payment_number,
                       p.value_date,
                       a.allocated_on,
                       a.amount::text AS amount,
                       a.currency,
                       a.reversed_at
                FROM allocations a
                JOIN payments p ON p.id = a.payment_id
                WHERE a.entity_id = ?
                  AND a.document_type = 'invoice'
                  AND a.document_id = ?
                ORDER BY a.allocated_on, a.id
            SQL, [$entityId, $documentId])
            : [];

        return view('app.sales.invoices.show', [
            'invoice' => $document,
            'taxBreakdown' => $taxBreakdown,
            'mayReadAmounts' => $mayReadAmounts,
            'openBalance' => $openBalance === null ? null : (string) $openBalance->open_balance,
            'allocations' => $allocations,
        ]);
    }
}
