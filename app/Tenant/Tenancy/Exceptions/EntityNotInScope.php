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
        return new self(sprintf(
            'You hold no role in entity %s. Access is granted per entity: having a role in one company of a '.
            'group does not grant anything in another.',
            $entityId,
        ));
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::EntityRequired)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
