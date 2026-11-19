<?php

declare(strict_types=1);

namespace App\Support\Http\Api;

use App\Support\Http\Exceptions\FilterNotAllowed;
use Illuminate\Http\Request;

/**
 * `?sort=field` or `?sort=-field`, against an allowlist - PF-221.
 *
 * The rule every listing in this API follows, in one place: nothing from the
 * query string ever reaches a column name. The public name maps to a column
 * through the allowlist, and anything else is a 400 naming the fields that
 * work - `?sort=1;DROP` is refused, never interpolated.
 *
 * Every column in an allowlist must be NOT NULL. A keyset cursor over a
 * nullable column silently loses the null rows; see JournalEntryApiController.
 */
final readonly class SortOrder
{
    private function __construct(
        public string $field,
        public string $column,
        public string $direction,
    ) {}

    /**
     * @param  array<string, string>  $sortable  public name => column
     *
     * @throws FilterNotAllowed
     */
    public static function from(Request $request, array $sortable, string $default, string $defaultDirection): self
    {
        $asked = $request->query('sort');

        if ($asked === null || $asked === '') {
            return new self($default, $sortable[$default] ?? $default, $defaultDirection);
        }

        if (! \is_string($asked)) {
            throw FilterNotAllowed::sort('(not a string)', array_keys($sortable));
        }

        $descending = str_starts_with($asked, '-');
        $field = $descending ? substr($asked, 1) : $asked;

        if (! \array_key_exists($field, $sortable)) {
            throw FilterNotAllowed::sort($asked, array_keys($sortable));
        }

        return new self($field, $sortable[$field], $descending ? 'desc' : 'asc');
    }

    /** The scope a cursor is bound to, so a cursor from one order cannot page another. */
    public function scope(string $operation): string
    {
        return $operation.':'.$this->field.':'.$this->direction;
    }

    /**
     * A filter value from `?filter[name]=value`, against its allowlist.
     *
     * @param  list<string>  $permitted
     *
     * @throws FilterNotAllowed
     */
    public static function filter(Request $request, string $name, array $permitted): ?string
    {
        $filters = $request->query('filter');

        if (! \is_array($filters) || ! isset($filters[$name])) {
            return null;
        }

        $value = $filters[$name];

        if (! \is_string($value) || ! \in_array($value, $permitted, true)) {
            throw FilterNotAllowed::filter($name, $permitted);
        }

        return $value;
    }
}
