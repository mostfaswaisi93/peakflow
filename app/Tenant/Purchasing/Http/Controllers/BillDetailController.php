<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Http\Controllers;

use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One supplier bill, as the document and as the record of who accepted it -
 * PF-133, PF-136.
 *
 * ## A bill in another entity is a 404, not a 403
 *
 * The lookup goes through the ordinary entity scope, so a bill belonging to
 * another company in the same workspace is not found rather than refused.
 * That difference matters: a 403 confirms the document exists, and an id that
 * answers differently depending on whether it was issued is a way to
 * enumerate another subsidiary's purchases one request at a time. Row-level
 * security says the same thing a second time and in a different way, so
 * neither mechanism is trusted alone.
 *
 * ## Both numbers are shown, because they answer different questions
 *
 * The raw number is what the supplier wrote and what goes on a remittance
 * advice; the normalised key is what the unique index and the duplicate
 * search compare, so `INV-001`, `inv 001` and `INV001` are one document.
 * Showing only the raw one leaves somebody unable to work out why their
 * second entry was refused as a duplicate of a number that looks different.
 *
 * ## Three dates, never collapsed into one
 *
 * The supplier's document date is theirs, the tax point is the
 * jurisdiction's, and the posting date is ours. A screen that showed "date"
 * would be read as the date of the bill, and the three answer different
 * questions - which return the input tax falls in, which period the entry
 * lands in, and what the supplier's statement says.
 *
 * ## The approval record comes from the audit trail, not from a join
 *
 * `approved_by` is a user id, and `Purchasing` may not reach `Identity` - the
 * Deptrac ruleset says so, and the reason it says so is that a purchase
 * module able to read the user table is a purchase module that will grow its
 * own idea of who people are. The audit log carries `actor_name` denormalised
 * for exactly this, which is how `Parties` names the person who read a bank
 * detail, and it is the same table the person investigating six months later
 * would read.
 */
final class BillDetailController
{
    /**
     * @no-permission-required: one of the company's own purchase documents,
     * open to anybody holding a role in that company. Its amounts are gated on
     * `report.view` in the body below, exactly as the Overview's figures are.
     */
    public function __invoke(Request $request, string $bill, EntityContext $context): View
    {
        $entity = $context->entity();
        $user = $request->user();

        $document = Bill::query()
            ->with([
                'supplier',
                'lines',
                /*
                 | A deterministic order on the two relations that declare
                 | none.
                 |
                 | Without one PostgreSQL returns the rows in whatever order
                 | the plan produces and is free to change it between
                 | requests, so a tax breakdown reloaded after a refresh
                 | shuffles its rate groups. Nothing is wrong with the
                 | figures and the reader has to re-find their place every
                 | time, which is how a screen earns the reputation of being
                 | untrustworthy.
                 */
                'taxBreakdown' => static fn ($query) => $query->orderBy('category')->orderBy('rate'),
                'creditAllocations' => static fn ($query) => $query->orderBy('allocated_at')->orderBy('id'),
                'creditAllocations.creditNote',
                'creditNotes' => static fn ($query) => $query->orderBy('supplier_document_date')->orderBy('id'),
            ])
            /*
             | `bills.*` is stated because `selectRaw` REPLACES the select
             | list rather than adding to it, and a Bill hydrated from one
             | computed column has no id and no amounts - which renders as an
             | empty document rather than as an error.
             */
            ->select('bills.*')
            /*
             | The one definition of what is still owed, and `::text` so it
             | arrives as a string: a `numeric(21,4)` handed over as a float
             | has already lost the exactness this system exists to keep.
             */
            ->selectRaw("peakflow_document_open_balance('bill', bills.id)::text AS open_balance")
            ->findOrFail($bill);

        /*
         | The events chain only.
         |
         | The other chain holds refusals - an approval somebody was not
         | allowed to give, a limit they were over - and those are not
         | decisions. Listing them beside the real ones under the heading
         | "the approval record" would make a bill look approved by whoever
         | tried hardest.
         */
        $decisions = DB::connection('tenant')->select(<<<'SQL'
            SELECT created_at::text AS at, actor_name, action, reason
            FROM audit_log
            WHERE chain = 'events'
              AND category = 'purchasing'
              AND action IN ('approval.requested', 'approval.approved', 'approval.rejected')
              AND subject_type = 'bill'
              AND subject_id = cast(? AS char(26))
            ORDER BY created_at, id
        SQL, [(string) $document->getKey()]);

        return view('app.purchasing.bills.show', [
            'entity' => $entity,
            'bill' => $document,
            'decisions' => $decisions,
            'maySeeFigures' => $entity !== null && $user !== null && $user->can('report.view'),
        ]);
    }
}
