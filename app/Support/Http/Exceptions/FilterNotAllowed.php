<?php

declare(strict_types=1);

namespace App\Support\Http\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A field a client may not filter or sort by - PF-224, and PF-221 needs it.
 *
 * ## The refusal names what IS permitted
 *
 * "Invalid sort field" is a refusal somebody answers by reading our
 * documentation, or by guessing. Listing the three fields that work answers
 * it in the response, which is where they are already looking.
 *
 * ## An allowlist is not a defence against injection alone
 *
 * It is that - nothing from the query string ever reaches a column name - but
 * the reason it is an allowlist rather than an escaping routine is
 * performance and shape. A sortable field needs an index to be sortable at
 * all, and a client sorting a journal by `narration` would ask the database
 * for a full sort of every line it has. The allowlist is the set somebody
 * decided to support, which means the set that has an index.
 */
final class FilterNotAllowed extends RuntimeException
{
    /**
     * @param  list<string>  $permitted
     */
    private function __construct(
        string $message,
        public readonly string $pointer,
        public readonly string $asked,
        public readonly array $permitted,
    ) {
        parent::__construct($message);
    }

    /** @param list<string> $permitted */
    public static function sort(string $asked, array $permitted): self
    {
        return new self(
            sprintf(
                '"%s" is not a sortable field here. The permitted fields are %s, each optionally prefixed '.
                'with "-" for descending. The list is an allowlist rather than a validation rule: a field '.
                'is sortable when it has an index, and sorting by one that has not would ask the database '.
                'for a full sort of the table.',
                $asked,
                implode(', ', $permitted),
            ),
            '/sort',
            $asked,
            $permitted,
        );
    }

    /** @param list<string> $permitted */
    public static function filter(string $asked, array $permitted): self
    {
        return new self(
            sprintf(
                '"%s" is not a filterable field here. The permitted filters are %s.',
                $asked,
                implode(', ', $permitted),
            ),
            sprintf('/filter/%s', $asked),
            $asked,
            $permitted,
        );
    }

    public function render(Request $request): ?Response
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        // The registry's localized sentence; this exception's message is for
        // the log. The permitted list is what a client acts on, so it is an
        // extension rather than something to be read out of prose.
        return Problem::of(ProblemType::FilterNotAllowed)
            ->withFieldError($this->pointer, 'not_allowed', $this->asked)
            ->withExtensions(['permitted' => $this->permitted])
            ->toResponse($request);
    }
}
