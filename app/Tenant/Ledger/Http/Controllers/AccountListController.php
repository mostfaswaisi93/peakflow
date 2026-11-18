<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Http\Controllers;

use App\Tenant\Ledger\Models\Account;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The chart of accounts, as the tree it is - PF-037, PF-038.
 *
 * ## Read-only, and that is a boundary rather than an unfinished state
 *
 * Nothing here creates, renames, reparents or blocks an account. Each of
 * those rewrites the materialized path of a subtree, and a reparent that
 * went wrong would roll a branch into a subtotal that still balances - so it
 * belongs behind its own write path with its own permission, not behind one
 * more branch of a page that was already authorized.
 *
 * ## Why the whole chart is read, and why the search does not filter the query
 *
 * A tree needs its interior nodes. An `ILIKE` in the database returns the
 * matches alone, and a tree rebuilt from those reparents a matched leaf onto
 * the root - where its code means nothing, because the meaning of `1.1.4` is
 * its position. So the chart is read once, in one query, and the term
 * narrows it in memory while every ancestor of a match is kept.
 *
 * Reading all of it is affordable because the chart is bounded by the
 * entity's own design rather than by its trading volume: the largest one
 * measured in this codebase is 5,055 accounts, and pagination is not an
 * option here - a page boundary through a tree cuts a branch in half and
 * leaves the reader looking at children with no parent.
 *
 * ## The control role is a column, not a footnote
 *
 * A role is a POSITION in the posting rules - it is how a posting rule
 * resolves a receivable without knowing what an invoice is - and exactly one
 * account may hold each role per entity. An account wired to the wrong role
 * posts correctly, balances, and puts the money somewhere nobody looks; the
 * only way to find it is to read which account holds which role, so the view
 * prints the role beside the account and prints its machine key with it,
 * because the key is what the posting rules resolve.
 */
final class AccountListController
{
    /**
     * Requires `account.manage`, asserted below.
     *
     * The same permission that puts "Chart of accounts" in the navigation,
     * so the menu and the page agree about who may look. The route carries
     * it too; this is the half that holds when somebody adds a second route
     * to the group and forgets it. There is deliberately no
     * `@no-permission-required` escape here - the chart names every control
     * account in the entity, which is a map of where money lands.
     */
    public function __invoke(Request $request, EntityContext $context): View
    {
        Gate::authorize('account.manage');

        // `?q[]=x` arrives as an array, and casting one to a string is a 500
        // on a URL anybody can type.
        $parameter = $request->query('q');
        $term = \is_string($parameter) ? trim($parameter) : '';

        /*
         | The roles are eager-loaded, because they are the point of the page.
         |
         | One account in a few dozen holds a role, so the relation looks
         | cheap to read per row - and a lazy read here is one query per
         | account on a chart that is deliberately read whole. Nothing on the
         | page would be WRONG, which is exactly why it would survive review.
         */
        $accounts = Account::query()->with('roles')->orderBy('code')->get();

        $byId = $accounts->keyBy('id');

        /*
         | The accounts to render: every match, and every ancestor of one.
         |
         | `null` means no search, which is not the same as "everything
         | matched" - an empty result set and an unfiltered chart have to be
         | distinguishable, because one of them is an empty chart and the
         | other is a term nobody has an account for.
         */
        $keep = null;

        if ($term !== '') {
            $keep = [];

            foreach ($accounts as $account) {
                $hit = false;

                /*
                 | Both names, and the code.
                 |
                 | The chart is maintained in Arabic and English, and
                 | searching only the language the reader is in finds nothing
                 | while looking exactly like an account that does not exist
                 | - after which somebody creates a second one.
                 */
                foreach ([$account->code, $account->name_ar, $account->name_en] as $field) {
                    if (mb_stripos((string) $field, $term) !== false) {
                        $hit = true;

                        break;
                    }
                }

                if (! $hit) {
                    continue;
                }

                /*
                 | Climb to the root, stopping at the first ancestor already
                 | kept - its own ancestors were kept on the climb that added
                 | it, so a chart where everything matches is still one pass.
                 */
                $node = $account;

                while ($node !== null && ! isset($keep[(string) $node->getKey()])) {
                    $keep[(string) $node->getKey()] = true;

                    $node = $node->parent_id === null
                        ? null
                        : $byId->get((string) $node->parent_id);
                }
            }
        }

        /*
         | Children grouped by parent, with the orphans treated as roots.
         |
         | `parent_id` can point at a soft-deleted account, which is absent
         | from this read. Nesting such a row under a key nothing renders
         | would drop it from the only page that lists accounts - and a
         | postable account that is invisible here still receives postings.
         | So it is shown at the top level instead of being lost.
         */
        $children = [];

        foreach ($accounts as $account) {
            if ($keep !== null && ! isset($keep[(string) $account->getKey()])) {
                continue;
            }

            $parent = $account->parent_id !== null && $byId->has((string) $account->parent_id)
                ? (string) $account->parent_id
                : '';

            $children[$parent][] = $account;
        }

        /*
         | The nesting is built here rather than in the template, because a
         | Blade view cannot call itself: the alternative is a copy of the row
         | markup per level, and the copy nobody updates is the one four
         | levels down that nobody scrolls to.
         */
        /** @var callable(string): list<array{account: Account, children: mixed}> $build */
        $build = static function (string $parent) use (&$build, $children): array {
            $nodes = [];

            foreach ($children[$parent] ?? [] as $account) {
                $nodes[] = [
                    'account' => $account,
                    'children' => $build((string) $account->getKey()),
                ];
            }

            return $nodes;
        };

        return view('app.ledger.accounts', [
            'entity' => $context->entity(),
            'term' => $term,
            'tree' => $build(''),
            'shown' => $keep === null ? $accounts->count() : \count($keep),
            'total' => $accounts->count(),
        ]);
    }
}
