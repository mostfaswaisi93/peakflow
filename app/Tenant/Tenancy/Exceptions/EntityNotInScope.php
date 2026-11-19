<?php

declare(strict_types=1);

namespace App\Tenant\Tenancy\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A financial model was queried with no entity established.
 *
 * In a request this means the `entity.scope` middleware did not run, which is
 * a routing mistake. In a console command or a queued job it means the entity
 * was never set, which is a programming mistake - and the message says which
 * to check, because the two have completely different fixes.
 */
final class EntityNotInScope extends RuntimeException
{
    /**
     * True when no entity was established at all, false when one was and is
     * not this person's.
     *
     * A property rather than two exception classes, because every catch site
     * in the product wants both - "this query had no entity" and "this
     * entity is not yours" are the same thing to go wrong at a call site and
     * two different things to answer with.
     */
    private bool $permitted = true;

    private function __construct(string $message, public readonly ?string $model = null)
    {
        parent::__construct($message);
    }

    public static function forQueryOn(string $model): self
    {
        return new self(
            sprintf(
                'Refused to query %s with no entity in scope. Returning every entity\'s rows here would look '.
                'like data rather than an error, so the query is refused instead. In a request, the `entity.scope` '.
                'middleware is missing from the route. In a command or job, set the entity first with '.
                'EntityContext::use() or EntityContext::during(). If crossing entities is genuinely intended, '.
                'say so explicitly with withoutEntityScope().',
                class_basename($model),
            ),
            $model,
        );
    }

    public static function notPermitted(string $entityId): self
    {
        $refusal = new self(sprintf(
            'You hold no role in entity %s. Access is granted per entity: having a role in one company of a '.
            'group does not grant anything in another.',
            $entityId,
        ));

        $refusal->permitted = false;

        return $refusal;
    }

    /**
     * Which refusal this is, because the two are not the same answer.
     *
     * `forQueryOn()` is "no entity was established at all" - a routing or a
     * programming mistake, and a 400: the request did not say which company
     * it was about.
     *
     * `notPermitted()` is "you hold no role in THAT company" - a 403. The
     * request said exactly which company and was understood; the answer is
     * that this person may not see it, and the remedy is somebody granting
     * them a role rather than anybody changing a header.
     *
     * Both rendered as `entity-required`, a 400, so the second arrived as
     * the first: "you did not say which entity" to somebody who had, which
     * sends them to check a header that was correct.
     */
    public function render(Request $request): Response
    {
        return Problem::of($this->permitted ? ProblemType::EntityRequired : ProblemType::EntityNotYours)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
