<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Http\Controllers;

use App\Tenant\Parties\Models\Party;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Customers and suppliers, as a list - PF-059.
 *
 * ## The list is NOT filtered by entity, and that is the schema talking
 *
 * `parties` carries no `entity_id`: the same company sells to you and buys
 * from you across every subsidiary in the group, and duplicating the record
 * per entity is what makes the duplicates diverge - an address corrected on
 * one copy, a bank account on the other, and nothing to say which is current.
 * What a party owes lives in `party_entity_settings`, which is per entity and
 * is scoped by {@see \App\Tenant\Tenancy\Concerns\BelongsToEntity}.
 *
 * So a filter on the current entity here would not be a tighter control; it
 * would be a list that silently omits the customer somebody is looking for,
 * and the next thing they do is create a second record for them.
 *
 * The entity is still read, because the page says which company's figures the
 * detail pages will show.
 */
final class PartyListController
{
    public function __invoke(Request $request, EntityContext $context): View
    {
        /*
         | The same permission that puts the item in the navigation.
         |
         | `Navigation` hides "Customers and suppliers" from anybody without
         | `party.manage`, and a page that is open where its own menu entry is
         | hidden is the arrangement where the menu looks like the control. The
         | route carries the permission too; this is the half that holds when
         | somebody adds a second route to the group and forgets it.
         */
        Gate::authorize('party.manage');

        /*
         | A query parameter can arrive as an array - `?q[]=x` - and casting
         | one to a string is a 500 on a URL anybody can type.
         */
        $parameter = $request->query('q');
        $term = \is_string($parameter) ? trim($parameter) : '';

        /*
         | `%` and `_` are ILIKE's own wildcards, so they are escaped rather
         | than passed through: searching for the code `AC_1` otherwise
         | matches `AC-1` as well, and a row that is not the one you typed
         | looks exactly like a row that is.
         */
        $like = '%'.addcslashes($term, '%_\\').'%';

        return view('app.masters.parties.index', [
            'entity' => $context->entity(),
            'term' => $term,
            'parties' => Party::query()
                ->when($term !== '', fn ($query) => $query->where(
                    fn ($match) => $match->where('code', 'ilike', $like)
                        ->orWhere('legal_name', 'ilike', $like)
                        ->orWhere('trading_name', 'ilike', $like),
                ))
                /*
                 | By legal name, which is the name on the document.
                 |
                 | Ordering by `code` would put the list in the order parties
                 | were created in, because the codes are issued in sequence -
                 | and somebody scanning for a name would be reading a list
                 | sorted by something invisible to them.
                 */
                ->orderBy('legal_name')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }
}
