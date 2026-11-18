<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Http\Controllers;

use App\Tenant\Parties\Models\Party;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * One party, and what it means to the company you are in - PF-059, PF-062.
 *
 * ## Three scopes on one page, deliberately kept apart
 *
 * The party's own fields are tenant-wide. Its credit limit and its hold are
 * per entity, and `party_entity_settings` is scoped by
 * {@see \App\Tenant\Tenancy\Concerns\BelongsToEntity}, so what this page
 * renders is this company's row and never another's - a customer billed by
 * two subsidiaries owes each of them separately, and showing one company's
 * limit on the other's screen is cross-company leakage that looks like data.
 * Its open documents are per entity for the same reason.
 *
 * ## The bank accounts are masked, and nothing here can unmask them
 *
 * `PartyBankAccount::masked()` is the only account reader this controller
 * knows about. The full number goes through
 * {@see \App\Tenant\Parties\Services\BankDetailDisclosure}, which demands
 * `bank.manage`, demands a written reason and records the read before it
 * returns anything - because the fraud this guards is somebody reading a
 * supplier's account to redirect a payment, and the change log would show
 * nothing, since nothing changed. A screen that printed the IBAN would be a
 * disclosure with no record and no reason, repeated on every page load.
 *
 * ## Why the documents are read as SQL and not as models
 *
 * `Parties` depends on `Support` and `Tenancy` and nothing else: an invoice
 * belongs to `Sales` and a bill to `Purchasing`, and reaching for either
 * model here would dissolve the boundary Deptrac compiles. The open balance
 * is asked of `peakflow_document_open_balance()`, which is the one definition
 * of what a document still owes - gross, less credit notes, less allocations,
 * less what a difference relieved, less what was written off. Re-deriving it
 * here would make this screen the fourth copy of a formula that had already
 * drifted once by PF-171.
 */
final class PartyDetailController
{
    public function __invoke(Request $request, string $party, EntityContext $context): View
    {
        Gate::authorize('party.manage');

        $found = Party::query()->findOrFail($party);
        $entity = $context->entity();

        return view('app.masters.parties.show', [
            'party' => $found,
            'entity' => $entity,
            /*
             | At most one row, because the global scope narrows it to the
             | entity this request is acting in.
             */
            'setting' => $found->entitySettings()->first(),
            'bankAccounts' => $found->bankAccounts()
                /*
                 | An account that needs re-verification first, because it is
                 | the one somebody has to act on: a changed account is
                 | unusable for payment until a second person has confirmed
                 | it against something that is not the email that asked for
                 | the change.
                 */
                ->orderByDesc('requires_reverification')
                ->orderByDesc('active')
                ->orderBy('created_at')
                ->get(),
            'documents' => $entity === null ? [] : $this->openDocuments(
                (string) $entity->getKey(),
                (string) $found->getKey(),
            ),
        ]);
    }

    /**
     * What this party still owes this company, or is still owed by it.
     *
     * Oldest first, which is the order the question is asked in: the oldest
     * open item is the one being chased, and a list sorted newest-first
     * buries it under this month's traffic.
     *
     * @return list<object>
     */
    private function openDocuments(string $entityId, string $partyId): array
    {
        return DB::connection('tenant')->select(<<<'SQL'
            SELECT 'invoice' AS kind,
                   coalesce(i.number, '') AS reference,
                   i.issue_date AS document_date,
                   i.currency AS currency,
                   i.gross_amount::text AS gross_amount,
                   peakflow_document_open_balance('invoice', i.id)::text AS open_balance
            FROM invoices i
            WHERE i.entity_id = cast(? AS char(26))
              AND i.party_id = cast(? AS char(26))
              AND i.status = 'issued'
              AND peakflow_document_open_balance('invoice', i.id) <> 0

            UNION ALL

            SELECT 'bill' AS kind,
                   b.supplier_document_number AS reference,
                   b.supplier_document_date AS document_date,
                   b.currency AS currency,
                   b.gross_amount::text AS gross_amount,
                   peakflow_document_open_balance('bill', b.id)::text AS open_balance
            FROM bills b
            WHERE b.entity_id = cast(? AS char(26))
              AND b.party_id = cast(? AS char(26))
              /*
               | Approved, not merely entered.
               |
               | A bill somebody is still typing is not a liability, and
               | counting it as one on a supplier's screen is how a payment
               | run gets argued about.
               */
              AND b.approved_at IS NOT NULL
              AND peakflow_document_open_balance('bill', b.id) <> 0

            ORDER BY document_date, reference
        SQL, [$entityId, $partyId, $entityId, $partyId]);
    }
}
