<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Http\Controllers;

use App\Tenant\Catalog\Models\Item;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The catalogue - PF-064.
 *
 * ## One catalogue for the tenant, one set of accounts per company
 *
 * `items` carries no `entity_id`. The same item is sold by every company in
 * the group; which revenue account it lands in is a property of each
 * company's chart, and the charts differ - so the defaults live in
 * `item_entity_defaults` and the item itself does not.
 *
 * The entity is read all the same, because the page has to say which
 * company's defaults an item would pick up: a catalogue that looks global and
 * posts differently per company is the thing to state rather than leave
 * someone to discover.
 *
 * ## `type` is the column this screen exists to make visible
 *
 * `good`, `service`, `digital_service` decides PLACE OF SUPPLY, and with it
 * which country's VAT applies and at what rate. Getting it wrong produces no
 * error at all - it produces a return filed in the wrong country, and the
 * correction is a voluntary disclosure. So the view gives it a column of its
 * own with the consequence spelled out beside each row, rather than a tag in
 * the corner.
 */
final class ItemListController
{
    public function __invoke(Request $request, EntityContext $context): View
    {
        /*
         | The same permission that puts the item in the navigation, so the
         | menu and the page agree about who may look. The route carries it
         | too; this is the half that survives somebody adding a second route
         | to the group.
         */
        Gate::authorize('item.manage');

        // `?q[]=x` arrives as an array, and casting one to a string is a 500.
        $parameter = $request->query('q');
        $term = \is_string($parameter) ? trim($parameter) : '';

        /*
         | ILIKE's own wildcards are escaped rather than passed through: a
         | search for `BOX_1` otherwise matches `BOX-1` too, and a row that
         | is not the one you typed looks exactly like a row that is.
         */
        $like = '%'.addcslashes($term, '%_\\').'%';

        return view('app.masters.items.index', [
            'entity' => $context->entity(),
            'term' => $term,
            'items' => Item::query()
                /*
                 | Both names, because the catalogue is maintained in both
                 | and searching the one the user is not reading finds
                 | nothing while looking as though the item does not exist.
                 */
                ->when($term !== '', fn ($query) => $query->where(
                    fn ($match) => $match->where('code', 'ilike', $like)
                        ->orWhere('name_ar', 'ilike', $like)
                        ->orWhere('name_en', 'ilike', $like),
                ))
                ->orderBy('code')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }
}
