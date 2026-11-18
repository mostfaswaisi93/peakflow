<?php

declare(strict_types=1);

namespace App\Support\Http\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page that will not be served, and what to ask for instead - PF-221.
 *
 * ## The refusal names the permitted values
 *
 * `limit=5000` answered with a silent 100 is the implementation everybody
 * writes first, and it is a quiet reinterpretation of what was asked: the
 * client believes it has every row and iterates no further. A refusal naming
 * the four sizes that work costs one round trip during development and
 * nothing afterwards.
 *
 * This is also why the page size is an ALLOWLIST and not a maximum. A maximum
 * answers `limit=99999` with a page of 100 and no indication that anything
 * was changed.
 */
final class PageNotAvailable extends RuntimeException
{
    /**
     * @param  list<array{pointer: string, code: string, detail: string}>  $errors
     * @param  array<string, mixed>  $extensions
     */
    private function __construct(
        public readonly ProblemType $problemType,
        string $message,
        public readonly array $errors = [],
        public readonly array $extensions = [],
    ) {
        parent::__construct($message);
    }

    /** @param list<int> $permitted */
    public static function sizeNotAllowed(string $asked, array $permitted): self
    {
        return new self(
            ProblemType::PageSizeNotAllowed,
            sprintf(
                'A page size of "%s" was asked for and the permitted sizes are %s. An unbounded page is a '.
                'query a client can make expensive for everybody else, so the sizes are an allowlist rather '.
                'than a ceiling - a ceiling would answer this with a page of %d and say nothing.',
                $asked,
                implode(', ', $permitted),
                end($permitted) ?: 0,
            ),
            [[
                'pointer' => '/limit',
                'code' => 'limit_not_allowed',
                'detail' => sprintf('Ask for one of: %s.', implode(', ', $permitted)),
            ]],
            ['permitted_page_sizes' => $permitted],
        );
    }

    /**
     * `page=latest`, `page=-1`, `page=1.5`.
     *
     * Refused rather than coerced. `(int) 'latest'` is 0 and `(int) '-1'` is
     * -1, and both reach `forPage()`, which answers with page one - so a
     * client paging by a value it computed wrongly is served the first page
     * for ever and looks like it is working.
     */
    public static function pageIsNotAPageNumber(string $asked): self
    {
        return new self(
            ProblemType::PageNotReadable,
            sprintf(
                '"%s" is not a page number. Coercing it would answer with page one, and a client paging by '.
                'a value it computed wrongly would read the first page for ever while appearing to work.',
                $asked,
            ),
            [[
                'pointer' => '/page',
                'code' => 'page_not_a_number',
                'detail' => 'A page number is a whole number of 1 or more.',
            ]],
        );
    }

    public static function offsetTooDeep(int $page, int $cap): self
    {
        return new self(
            ProblemType::OffsetTooDeep,
            sprintf(
                'Page %d is past the offset limit of %d. Offset pagination is the slower path and it is '.
                'capped: reaching page %d means reading every row before it in order to discard them, and '.
                'over a journal that is still being written to the pages also drift. Use the cursor, which '.
                'seeks instead of counting.',
                $page,
                $cap,
                $page,
            ),
            [[
                'pointer' => '/page',
                'code' => 'page_too_deep',
                'detail' => sprintf('The last page offset pagination serves is %d.', $cap),
            ]],
            ['maximum_offset_page' => $cap],
        );
    }

    public function render(Request $request): ?Response
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        /*
         | The registry's localized sentence, not this exception's message.
         |
         | The message is for the log: English, long, and naming the
         | reasoning. The permitted values - which are the part a client acts
         | on - travel as extensions, so nothing has to be parsed out of
         | prose that is Arabic half the time.
         */
        $problem = Problem::of($this->problemType)
            ->withExtensions($this->extensions);

        foreach ($this->errors as $error) {
            $problem->withFieldError($error['pointer'], $error['code'], $error['detail']);
        }

        return $problem->toResponse($request);
    }
}
