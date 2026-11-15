<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Tenant\Ledger\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reading the chart as a tree, without recursion - PF-037.
 *
 * ## One idea: a subtree is a half-open range
 *
 * The path is the chain of ancestor segments joined by `.`, and the segments
 * are byte-ordered (the column is collated `C`, see the migration). So
 * everything under `1.1` is exactly:
 *
 *     path >= '1.1.'  AND  path < '1.1/'
 *
 * because `/` is the byte immediately after `.`. Both bounds land in the
 * index condition, the scan reads the subtree and nothing else, and its cost
 * grows with the subtree rather than with the chart.
 *
 * ## Why not `LIKE 'path.%'`, which reads better
 *
 * Because it is a different plan, measured at 5,055 accounts:
 *
 *   | form  | cost   | time    | rows examined |
 *   |-------|--------|---------|---------------|
 *   | LIKE  | 249.52 | 0.41 ms | 5,055, then 4,955 discarded |
 *   | range |   7.21 | 0.06 ms | 100 |
 *
 * The LIKE reads every account in the entity. On a chart of 50,000 it reads
 * 50,000 to return ten, and every roll-up in every report pays it.
 *
 * ## And why not a recursive CTE
 *
 * A `WITH RECURSIVE` walk is correct and is one index lookup per LEVEL, with
 * the row count of each level as the driver. It is the right answer when
 * there is no path to maintain. There is one, and it is maintained at write
 * time by the one piece of code that builds a chart - so the read side gets
 * to be a range scan, which is the cheapest thing a btree does.
 *
 * The cost of that choice is real and belongs here: moving a subtree rewrites
 * its descendants' paths. Moving an account is rare, a chart is small, and
 * {@see move()} does it in one UPDATE.
 */
final class AccountTree
{
    /**
     * The separator, and the byte after it.
     *
     * Named rather than inlined because the two are a pair: `/` is only the
     * right upper bound while `.` is the separator, and anybody changing one
     * has to change the other. A code containing either is refused by the
     * template loader for exactly this reason.
     */
    public const SEPARATOR = '.';

    private const AFTER_SEPARATOR = '/';

    /**
     * The exclusive upper bound of a path's subtree.
     *
     * `subtreeEnd('1.1')` is `'1.1/'`. Everything with the prefix `1.1.`
     * sorts below it and nothing else does.
     */
    public static function subtreeEnd(string $path): string
    {
        if ($path === '') {
            throw new InvalidArgumentException('A subtree bound needs a path; the empty path is the whole chart.');
        }

        if (str_contains($path, self::AFTER_SEPARATOR)) {
            /*
             | Refused rather than escaped.
             |
             | A path containing `/` would make the upper bound ambiguous and
             | the range wrong - silently, in whichever direction the bytes
             | fell. The template loader refuses such a code, so reaching
             | here means a path was written by something that bypassed it.
             */
            throw new InvalidArgumentException(sprintf(
                'The path "%s" contains "%s", which is the sentinel that bounds a subtree range. A code '.
                'may not contain it.',
                $path,
                self::AFTER_SEPARATOR,
            ));
        }

        return $path.self::AFTER_SEPARATOR;
    }

    /**
     * Apply the subtree range to an existing query.
     *
     * Exposed so {@see \App\Tenant\Ledger\Models\Account} can offer the
     * same bounds from its own relations without the model depending on this
     * service for anything but the arithmetic - and, more to the point, so
     * there is exactly one place that knows the sentinel. Two places that
     * each build the upper bound is one place that gets it wrong.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function rangeOf(Builder $query, string $path, bool $inclusive): Builder
    {
        return $query
            ->where('path', '>=', $inclusive ? $path : $path.self::SEPARATOR)
            ->where('path', '<', self::subtreeEnd($path))
            ->orderBy('path');
    }

    /**
     * Every account under a path, the path itself excluded.
     *
     * @return Builder<Account>
     */
    public function descendantsOf(string $entityId, string $path): Builder
    {
        return self::rangeOf(
            Account::query()->where('entity_id', $entityId),
            $path,
            false,
        );
    }

    /**
     * An account and everything under it.
     *
     * One range, with the account's own path as the lower bound - rather than
     * a union of the account and its descendants, which is two scans and a
     * sort for a result the index already has in order.
     *
     * @return Builder<Account>
     */
    public function subtreeOf(string $entityId, string $path): Builder
    {
        return self::rangeOf(
            Account::query()->where('entity_id', $entityId),
            $path,
            true,
        );
    }

    /**
     * The direct children of a path.
     *
     * The range narrows to one level by depth, which is a column rather than
     * a count of separators in a string - the same reason the depth column
     * exists at all.
     *
     * @return Builder<Account>
     */
    public function childrenOf(string $entityId, string $path, int $depth): Builder
    {
        return $this->descendantsOf($entityId, $path)->where('depth', $depth + 1);
    }

    /**
     * The ancestors of a path, outermost first.
     *
     * Derived from the path in PHP and fetched with one `whereIn`. A chart is
     * four or five levels deep, so this is one index lookup per level against
     * a unique index - and it needs no recursion and no second table.
     *
     * @return Builder<Account>
     */
    public function ancestorsOf(string $entityId, string $path): Builder
    {
        $segments = explode(self::SEPARATOR, $path);
        array_pop($segments);

        $paths = [];
        $prefix = '';

        foreach ($segments as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix.self::SEPARATOR.$segment;
            $paths[] = $prefix;
        }

        return Account::query()
            ->where('entity_id', $entityId)
            ->whereIn('path', $paths === [] ? [''] : $paths)
            ->orderBy('depth');
    }

    /**
     * The roll-up of a subtree's balances, in one query.
     *
     * ## This is the query the whole design is for
     *
     * A statement line is the sum of a subtree, and a statement is twenty of
     * them. Twenty range scans joined to the balance read model is twenty
     * index scans; the recursive alternative is twenty recursive walks, each
     * one issuing a query per level.
     *
     * The sum comes from `account_balances` rather than `journal_lines`
     * because that is what the read model is for - and the read model is
     * checked against the journal by {@see BalanceRebuilder}, so this does
     * not become a third opinion about a balance.
     *
     * @param  list<string>  $periodIds  empty for every period
     * @return array{net: string, debits: string, credits: string, accounts: int, lines: int}
     */
    public function rollUp(string $entityId, string $path, array $periodIds = []): array
    {
        $bindings = [$entityId, $path, self::subtreeEnd($path)];
        $periodFilter = '';

        if ($periodIds !== []) {
            $periodFilter = ' AND b.period_id IN ('.implode(',', array_fill(0, count($periodIds), '?')).')';
            $bindings = array_merge($bindings, $periodIds);
        }

        $row = DB::connection('tenant')->selectOne(<<<SQL
            SELECT
                coalesce(sum(b.net), 0)        AS net,
                coalesce(sum(b.debits), 0)     AS debits,
                coalesce(sum(b.credits), 0)    AS credits,
                count(DISTINCT b.account_id)   AS accounts,
                coalesce(sum(b.line_count), 0) AS lines
            FROM accounts a
            JOIN account_balances b ON b.account_id = a.id
            WHERE a.entity_id = cast(? AS char(26))
              AND a.path >= ?
              AND a.path < ?
              {$periodFilter}
        SQL, $bindings);

        return [
            'net' => (string) ($row->net ?? '0'),
            'debits' => (string) ($row->debits ?? '0'),
            'credits' => (string) ($row->credits ?? '0'),
            'accounts' => (int) ($row->accounts ?? 0),
            'lines' => (int) ($row->lines ?? 0),
        ];
    }

    /**
     * Move a subtree, rewriting its descendants' paths in one statement.
     *
     * The cost of a materialized path, paid here and nowhere else. One UPDATE
     * over the range, so the work is proportional to the subtree rather than
     * to the chart, and `depth` is corrected by the same arithmetic.
     *
     * Refuses to move an account into its own subtree, which would detach the
     * whole branch from the tree and leave rows whose path no longer begins
     * with any root.
     *
     * @return int how many accounts moved, the account itself included
     */
    public function move(string $entityId, Account $account, ?Account $newParent): int
    {
        $oldPath = (string) $account->path;
        $segment = $this->lastSegment($oldPath);

        $newPath = $newParent === null
            ? $segment
            : $newParent->path.self::SEPARATOR.$segment;

        if ($newPath === $oldPath) {
            return 0;
        }

        if ($newParent !== null && str_starts_with((string) $newParent->path, $oldPath.self::SEPARATOR)) {
            throw new InvalidArgumentException(sprintf(
                'Account %s cannot be moved under %s, which is one of its own descendants. The branch '.
                'would be detached from the tree and its rows would have a path under no root at all.',
                (string) $account->code,
                (string) $newParent->code,
            ));
        }

        /*
         | The destination path has to be free.
         |
         | A path identifies a node, so two accounts sharing one makes every
         | subtree query return both branches and every roll-up double-count
         | one of them. The code is unique per entity and does NOT change on
         | a move, so a collision is entirely possible: moving `1.1` under
         | `1.2` wants the path `1.2.1`, which the sub-group `1.2.1` already
         | has.
         |
         | The unique index refuses it too. This is the message, because
         | "duplicate key value violates unique constraint" does not tell
         | anybody that the answer is to renumber the account first.
         */
        $taken = Account::query()
            ->where('entity_id', $entityId)
            ->where('path', $newPath)
            ->whereKeyNot($account->getKey())
            ->value('code');

        if ($taken !== null) {
            throw new InvalidArgumentException(sprintf(
                'Account %s cannot be moved under %s: the path it would take, "%s", already belongs to '.
                'account %s. A path identifies a node, so two accounts sharing one makes every subtree '.
                'query return both branches. Renumber one of them first.',
                (string) $account->code,
                (string) $newParent?->code,
                $newPath,
                (string) $taken,
            ));
        }

        $newDepth = substr_count($newPath, self::SEPARATOR);
        $shift = $newDepth - (int) $account->depth;

        return DB::connection('tenant')->transaction(function () use (
            $entityId,
            $account,
            $newParent,
            $oldPath,
            $newPath,
            $shift,
        ): int {
            /*
             | The descendants first, then the account.
             |
             | In that order because the descendants are found BY the old
             | path: updating the account first would move it out of the
             | range that identifies its own children.
             */
            /*
             | `cast(? AS integer)`, and it is not decoration.
             |
             | `substring(text FROM text)` is the POSIX-REGEX form of the
             | function, and a bound parameter arrives with no type - so
             | PostgreSQL resolved `substring(path FROM ?)` as a regex match
             | against the pattern "4", which matched nothing and returned
             | NULL. Every descendant's path was then `'1.2' || NULL`, which
             | is NULL, and the only reason this was a visible failure rather
             | than a silent one is that `path` is NOT NULL.
             */
            $moved = DB::connection('tenant')->update(<<<'SQL'
                UPDATE accounts
                SET path  = ? || substring(path FROM cast(? AS integer)),
                    depth = depth + cast(? AS integer),
                    updated_at = now()
                WHERE entity_id = cast(? AS char(26))
                  AND path >= ?
                  AND path <  ?
            SQL, [
                $newPath,
                strlen($oldPath) + 1,
                $shift,
                $entityId,
                $oldPath.self::SEPARATOR,
                self::subtreeEnd($oldPath),
            ]);

            $account->update([
                'parent_id' => $newParent?->getKey(),
                'path' => $newPath,
                'depth' => substr_count($newPath, self::SEPARATOR),
            ]);

            return $moved + 1;
        });
    }

    private function lastSegment(string $path): string
    {
        $position = strrpos($path, self::SEPARATOR);

        return $position === false ? $path : substr($path, $position + 1);
    }
}
