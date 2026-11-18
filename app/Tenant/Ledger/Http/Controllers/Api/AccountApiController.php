<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Http\Controllers\Api;

use App\Support\Http\Api\Page;
use App\Support\Http\Exceptions\FilterNotAllowed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The chart of accounts, over the wire - PF-221.
 *
 * ## It is a page, where the screen reads the whole tree
 *
 * The chart screen reads every account in one query and says why: a tree
 * needs its interior nodes, and a page boundary through a tree cuts a branch
 * in half and leaves the reader looking at children with no parent.
 *
 * A client is not a reader. It is building a lookup from account code to id,
 * or reconciling its own chart against ours, and for that the order matters
 * and the tree does not. So this paginates by code - which is the order the
 * tree is in anyway, because the code IS the path - and a client that wants
 * the tree reassembles it from `parent_id`, which every row carries.
 *
 * ## The query is built here and not handed a filter from the query string
 *
 * The sort column comes from an allowlist in this file. Nothing from the
 * request reaches a column name, ever: `?sort=1;DROP` is a 400 naming the
 * three fields that work rather than a string interpolated into an ORDER BY.
 */
final class AccountApiController
{
    /**
     * The fields a client may sort by, and the column each one means.
     *
     * A map and not a list, so the public name and the physical column are
     * free to differ - which is what lets a column be renamed without
     * breaking a contract, and what stops a client discovering the schema by
     * guessing sort fields.
     *
     * @var array<string, string>
     */
    private const SORTABLE = [
        'code' => 'code',
        'created_at' => 'created_at',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        /*
         | The permission, stated here as well as on the route.
         |
         | Both, because this is the half that still holds when somebody adds
         | a second route to the group and forgets the middleware - and the
         | chart names every control account in the entity, which is a map of
         | where money lands.
         */
        Gate::authorize('account.manage');

        [$field, $direction] = $this->sort($request);

        $query = DB::connection('tenant')->table('accounts')
            ->whereNull('deleted_at')
            ->select([
                'id', 'code', 'name_ar', 'name_en', 'parent_id', 'depth', 'type',
                'currency', 'postable', 'blocked', 'monetary', 'created_at',
            ]);

        return Page::asked($request, 'listAccounts:'.$field.':'.$direction)
            ->fill(
                query: $query,
                sortColumn: self::SORTABLE[$field],
                direction: $direction,
                shape: static fn (object $row): array => [
                    'resource' => 'account',
                    'id' => (string) $row->id,
                    'code' => (string) $row->code,
                    'name' => ['ar' => (string) $row->name_ar, 'en' => (string) $row->name_en],
                    'parent_id' => $row->parent_id === null ? null : (string) $row->parent_id,
                    'depth' => (int) $row->depth,
                    'type' => (string) $row->type,
                    /*
                     | A currency-restricted account, where there is one.
                     |
                     | Null means "any enabled currency", which is a different
                     | fact from "the entity's functional currency" - and a
                     | client that filled the null in with the functional one
                     | would refuse to post a legitimate EUR line to a
                     | general account.
                     */
                    'currency' => $row->currency === null ? null : (string) $row->currency,
                    'postable' => (bool) $row->postable,
                    'blocked' => (bool) $row->blocked,
                    'monetary' => (bool) $row->monetary,
                ],
                sortValue: static fn (object $row): string => (string) $row->{self::SORTABLE[$field]},
                idValue: static fn (object $row): string => (string) $row->id,
            )
            ->toResponse();
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws FilterNotAllowed
     */
    private function sort(Request $request): array
    {
        $asked = $request->query('sort');

        if ($asked === null || $asked === '') {
            return ['code', 'asc'];
        }

        if (! \is_string($asked)) {
            throw FilterNotAllowed::sort('(not a string)', array_keys(self::SORTABLE));
        }

        $descending = str_starts_with($asked, '-');
        $field = $descending ? substr($asked, 1) : $asked;

        if (! \array_key_exists($field, self::SORTABLE)) {
            throw FilterNotAllowed::sort($asked, array_keys(self::SORTABLE));
        }

        return [$field, $descending ? 'desc' : 'asc'];
    }
}
