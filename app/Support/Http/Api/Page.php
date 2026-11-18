<?php

declare(strict_types=1);

namespace App\Support\Http\Api;

use App\Support\Http\Exceptions\PageNotAvailable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One page of a collection, cursor-first - PF-221.
 *
 * ## Cursor by default, offset on request, and the difference is not taste
 *
 * `OFFSET 50000` reads fifty thousand rows in order to throw them away, so
 * the last page of a long list costs the most and the cost grows with the
 * table. That is the part everybody knows.
 *
 * The part that bites is DRIFT. A journal is appended to while somebody pages
 * through it: one insert between request five and request six shifts every
 * later row by one, so the client sees one entry twice and never sees
 * another. Nothing errors. The totals a client computes by paging are simply
 * wrong, by one entry, and the entry it missed is unknowable afterwards.
 *
 * A keyset seek has neither problem: `WHERE (sort_key, id) > (?, ?)` is
 * answered by the index and names the exact row it continues from, so an
 * insert elsewhere in the table cannot move it.
 *
 * Offset pagination is still offered, because a screen with numbered pages is
 * a real requirement and telling people to reimplement it is how they end up
 * asking for `limit=100000`. It is capped, and the cap is documented.
 *
 * ## The sort key and the id, together, always
 *
 * A sort on `accounting_date` alone is not a total order: forty entries share
 * a date, the database may return them in any order, and a cursor pointing at
 * "after 2026-03-01" cannot say which of the forty it meant. So every sort
 * carries `id` as its last term, in the same direction, and the cursor holds
 * both halves.
 *
 * Same direction, not merely both present: `(a, b) > (?, ?)` is a row-value
 * comparison, which PostgreSQL can answer from one index, and it only
 * expresses one direction. A mixed sort would need the predicate written out
 * by hand as nested ORs, which is where people write a bug.
 *
 * ## Link headers, because that is where a generic client looks
 *
 * RFC 8288. A library that already knows how to follow `rel="next"` needs no
 * code written for this API; the same URLs are in the body for the client
 * that is reading a logged response. Both, for the same reason `Retry-After`
 * is a header and a body member.
 */
final class Page
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private ?Cursor $next = null;

    private ?Cursor $previous = null;

    private bool $hasMore = false;

    private function __construct(
        private readonly Request $request,
        /** The operation and sort this page belongs to; it is inside the cursor's signature. */
        private readonly string $scope,
        private readonly int $limit,
        private readonly ?int $pageNumber,
    ) {}

    /**
     * Read what the client asked for, refusing anything outside the allowlist.
     *
     * @throws PageNotAvailable
     */
    public static function asked(Request $request, string $scope): self
    {
        return new self($request, $scope, self::limitFrom($request), self::pageNumberFrom($request));
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /** Offset mode is requested by sending `page`, and only then. */
    public function isOffsetMode(): bool
    {
        return $this->pageNumber !== null;
    }

    /**
     * Run the query for this page and keep the rows.
     *
     * @param  Builder  $query  not yet ordered or limited; this applies both
     * @param  string  $sortColumn  the allowlisted sort column, qualified if the query joins
     * @param  string  $direction  `asc` or `desc`
     * @param  callable(object): array<string, mixed>  $shape  how one row reaches the wire
     * @param  callable(object): string  $sortValue  the row's sort key, as the cursor stores it
     * @param  callable(object): string  $idValue  the row's id
     *
     * @throws \App\Support\Http\Exceptions\CursorNotReadable
     */
    public function fill(
        Builder $query,
        string $sortColumn,
        string $direction,
        callable $shape,
        callable $sortValue,
        callable $idValue,
        string $idColumn = 'id',
    ): self {
        /*
         | In offset mode the cursor is ignored, as the contract says.
         |
         | Not merely unused: applying both would put a keyset predicate AND
         | an OFFSET on the same query, which answers with a page from
         | somewhere neither parameter asked for. The links this class
         | produces never carry both, so this is about a hand-written request
         | - which is exactly the one nobody tested.
         */
        $cursor = $this->isOffsetMode() ? null : $this->cursor();
        $descending = $direction === 'desc';

        /*
         | A backward cursor runs the sort the other way and the rows are
         | reversed afterwards.
         |
         | The alternative - counting back from the cursor - is an offset in
         | disguise and drifts the same way. Reading forwards from the far
         | side and reversing in PHP costs one array_reverse on at most a
         | hundred rows.
         */
        $runningBackwards = $cursor !== null && ! $cursor->isForward();
        $ascending = $runningBackwards ? $descending : ! $descending;

        if ($cursor !== null) {
            $comparison = $ascending ? '>' : '<';

            /*
             | A row-value comparison, parameterised.
             |
             | `(a, b) > (?, ?)` is one index seek and is exactly the
             | semantics wanted: the next row in the sort, whatever ties the
             | sort key has. The column names come from the caller's
             | allowlist and never from the query string - see
             | FilterNotAllowed - and the values are bound.
             */
            $query->whereRaw(
                sprintf('(%s, %s) %s (?, ?)', $sortColumn, $idColumn, $comparison),
                [$cursor->key, $cursor->id],
            );
        }

        $order = $ascending ? 'asc' : 'desc';

        $query->orderBy($sortColumn, $order)->orderBy($idColumn, $order);

        if ($this->isOffsetMode()) {
            // One past the page, so "is there a next page" is answered
            // without a second COUNT over the whole table.
            $query->forPage((int) $this->pageNumber, $this->limit + 1);
        } else {
            $query->limit($this->limit + 1);
        }

        /** @var list<object> $rows */
        $rows = array_values($query->get()->all());

        $this->hasMore = \count($rows) > $this->limit;
        $rows = \array_slice($rows, 0, $this->limit);

        if ($runningBackwards) {
            $rows = array_reverse($rows);
        }

        $this->rows = array_map(static fn (object $row): array => $shape($row), $rows);

        if ($rows !== [] && ! $this->isOffsetMode()) {
            $first = $rows[0];
            $last = $rows[\count($rows) - 1];

            /*
             | `next` exists when there was a row beyond this page, and
             | `prev` whenever a cursor brought us here.
             |
             | Not "whenever there are rows": handing a client a `prev` on the
             | first page sends it to an empty page and a well-written client
             | then reports that it lost rows.
             */
            $forwardHasMore = $runningBackwards ? true : $this->hasMore;
            $backwardHasMore = $runningBackwards ? $this->hasMore : $cursor !== null;

            if ($forwardHasMore) {
                $this->next = Cursor::after($sortValue($last), $idValue($last));
            }

            if ($backwardHasMore) {
                $this->previous = Cursor::before($sortValue($first), $idValue($first));
            }
        }

        return $this;
    }

    /**
     * The response: the envelope, and the same links as RFC 8288 headers.
     */
    public function toResponse(): JsonResponse
    {
        $links = array_filter([
            'self' => $this->request->fullUrl(),
            'next' => $this->url($this->next, forward: true),
            'prev' => $this->url($this->previous, forward: false),
        ], static fn (?string $url): bool => $url !== null);

        $meta = [
            'limit' => $this->limit,
            'has_more' => $this->hasMore,
            /*
             | Which mode served this page, on the page.
             |
             | A client that asked for `page=3` and silently got a cursor
             | page, or the reverse, would page through the wrong thing while
             | everything looked fine. Saying which mode answered makes the
             | mix-up visible in the first response rather than in the totals.
             */
            'pagination' => $this->isOffsetMode() ? 'offset' : 'cursor',
        ];

        if ($this->isOffsetMode()) {
            $meta['page'] = $this->pageNumber;
        }

        $response = new JsonResponse([
            'data' => $this->rows,
            'meta' => $meta,
            'links' => $links,
        ]);

        $header = [];

        foreach ($links as $rel => $url) {
            if ($rel === 'self') {
                continue;
            }

            $header[] = sprintf('<%s>; rel="%s"', $url, $rel);
        }

        if ($header !== []) {
            $response->headers->set('Link', implode(', ', $header));
        }

        return $response;
    }

    /** @throws \App\Support\Http\Exceptions\CursorNotReadable */
    private function cursor(): ?Cursor
    {
        $raw = $this->request->query('cursor');

        if (! \is_string($raw) || $raw === '') {
            return null;
        }

        return Cursor::decode($raw, $this->scope);
    }

    private function url(?Cursor $cursor, bool $forward): ?string
    {
        if ($this->isOffsetMode()) {
            if (! $forward) {
                return (int) $this->pageNumber <= 1
                    ? null
                    : $this->withQuery(['page' => (int) $this->pageNumber - 1]);
            }

            return $this->hasMore ? $this->withQuery(['page' => (int) $this->pageNumber + 1]) : null;
        }

        return $cursor === null ? null : $this->withQuery(['cursor' => $cursor->encode($this->scope)]);
    }

    /** @param array<string, int|string> $replace */
    private function withQuery(array $replace): string
    {
        /** @var array<string, mixed> $query */
        $query = $this->request->query();

        foreach ($replace as $key => $value) {
            $query[$key] = $value;

            // The two modes are mutually exclusive, and a link that carried
            // both would be answered by whichever branch the code reached
            // first - which is a decision nobody made.
            unset($query[$key === 'page' ? 'cursor' : 'page']);
        }

        return $this->request->url().'?'.http_build_query($query);
    }

    /** @throws PageNotAvailable */
    private static function limitFrom(Request $request): int
    {
        /** @var list<int> $permitted */
        $permitted = array_values(array_map(
            static fn (mixed $size): int => (int) $size,
            (array) config('api.pagination.page_sizes', [25]),
        ));

        $asked = $request->query('limit');

        if ($asked === null || $asked === '') {
            return (int) config('api.pagination.default_page_size', 25);
        }

        if (! \is_string($asked) || ! \in_array((int) $asked, $permitted, true) || (string) (int) $asked !== $asked) {
            throw PageNotAvailable::sizeNotAllowed(
                \is_string($asked) ? $asked : '(not a number)',
                $permitted,
            );
        }

        return (int) $asked;
    }

    /** @throws PageNotAvailable */
    private static function pageNumberFrom(Request $request): ?int
    {
        $asked = $request->query('page');

        if ($asked === null || $asked === '') {
            return null;
        }

        $cap = (int) config('api.pagination.maximum_offset_page', 200);

        if (! \is_string($asked) || (string) (int) $asked !== $asked || (int) $asked < 1) {
            throw PageNotAvailable::pageIsNotAPageNumber(
                \is_string($asked) ? $asked : '(not a number)',
            );
        }

        if ((int) $asked > $cap) {
            throw PageNotAvailable::offsetTooDeep((int) $asked, $cap);
        }

        return (int) $asked;
    }
}
