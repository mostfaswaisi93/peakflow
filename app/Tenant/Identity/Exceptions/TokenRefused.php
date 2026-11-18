<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An API token could not be issued, rotated or revoked - PF-218.
 *
 * ## Each reason says what to change
 *
 * A token that cannot be issued is almost always a configuration mistake
 * somebody is making right now, with the scopes in front of them. "Invalid
 * request" sends them to our documentation; "entity.manage is not a scope you
 * hold in 01JD3X" sends them to the role grant, which is where the fix is.
 *
 * So the unknown scopes are listed, the entities are named, and the refusal
 * renders as a problem document carrying them in `errors` rather than as a
 * sentence a client has to parse.
 */
final class TokenRefused extends RuntimeException
{
    /**
     * @param  list<array{pointer: string, code: string, detail: string}>  $errors
     */
    private function __construct(
        public readonly ProblemType $problemType,
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /** @param list<string> $scopes */
    public static function unknownScopes(array $scopes): self
    {
        return new self(
            ProblemType::ValidationFailed,
            sprintf(
                'These are not permissions: %s. A token scope is a permission key spelled exactly the same '.
                'way, because a second vocabulary for machines is a second authorization model and the two '.
                'drift the moment one of them gains an entry.',
                implode(', ', $scopes),
            ),
            array_map(static fn (string $scope): array => [
                'pointer' => '/scopes',
                'code' => 'scope_unknown',
                'detail' => sprintf('%s is not a permission key.', $scope),
            ], $scopes),
        );
    }

    /** @param list<string> $scopes */
    public static function scopesTheUserDoesNotHold(array $scopes, string $entityId): self
    {
        return new self(
            ProblemType::Forbidden,
            sprintf(
                'You do not hold %s in entity %s, so a token of yours cannot carry it. A token is a '.
                'delegation of what its owner can already do; one that carried more would be a way to '.
                'grant yourself a permission by issuing a credential.',
                implode(', ', $scopes),
                $entityId,
            ),
            array_map(static fn (string $scope): array => [
                'pointer' => '/scopes',
                'code' => 'scope_not_held',
                'detail' => sprintf('%s is not held in entity %s.', $scope, $entityId),
            ], $scopes),
        );
    }

    /** @param list<string> $scopes */
    public static function scopesThatNeedSomebodyPresent(array $scopes): self
    {
        return new self(
            ProblemType::Forbidden,
            sprintf(
                'These permissions require re-authentication at the moment of the act and can never be token '.
                'scopes: %s. A step-up assertion proves somebody is at the keyboard; a token in a '.
                'configuration file proves the opposite.',
                implode(', ', $scopes),
            ),
            array_map(static fn (string $scope): array => [
                'pointer' => '/scopes',
                'code' => 'scope_needs_step_up',
                'detail' => sprintf('%s requires step-up re-authentication, which a token cannot provide.', $scope),
            ], $scopes),
        );
    }

    /**
     * @param  list<string>  $wider
     * @param  list<string>  $issuer
     */
    public static function widerThanTheIssuer(array $wider, array $issuer): self
    {
        return new self(
            ProblemType::Forbidden,
            sprintf(
                'The token making this request carries %s, so it cannot issue one carrying %s. Delegation '.
                'only ever narrows: otherwise a token issued to read invoices, holding user.manage so it '.
                'can rotate its own credential, could mint itself a token that releases payments.',
                implode(', ', $issuer),
                implode(', ', $wider),
            ),
            array_map(static fn (string $scope): array => [
                'pointer' => '/scopes',
                'code' => 'scope_wider_than_issuer',
                'detail' => sprintf('%s is not carried by the token making this request.', $scope),
            ], $wider),
        );
    }

    /**
     * @param  list<string>  $elsewhere
     * @param  list<string>  $issuer
     */
    public static function entitiesOutsideTheIssuer(array $elsewhere, array $issuer): self
    {
        return new self(
            ProblemType::Forbidden,
            sprintf(
                'The token making this request may act in %s, so it cannot issue one for %s. A token '.
                'scoped to one subsidiary must not be able to mint one for the parent.',
                implode(', ', $issuer),
                implode(', ', $elsewhere),
            ),
            array_map(static fn (string $id): array => [
                'pointer' => '/entity_ids',
                'code' => 'entity_outside_issuer',
                'detail' => sprintf('Entity %s is outside the issuing token\'s scope.', $id),
            ], $elsewhere),
        );
    }

    public static function noEntities(): self
    {
        return new self(
            ProblemType::ValidationFailed,
            'A token names the entities it may act in, and this one names none. An empty list reads as "no '.
            'restriction" to anybody writing the check in a hurry, which is the inversion that turns a scoped '.
            'token into a tenant-wide one.',
            [['pointer' => '/entity_ids', 'code' => 'entity_ids_empty', 'detail' => 'Name at least one entity.']],
        );
    }

    /** @param list<string> $entityIds */
    public static function entitiesTheUserCannotReach(array $entityIds): self
    {
        return new self(
            ProblemType::Forbidden,
            sprintf(
                'You hold no role in %s, so a token of yours cannot act there either.',
                implode(', ', $entityIds),
            ),
            array_map(static fn (string $id): array => [
                'pointer' => '/entity_ids',
                'code' => 'entity_not_held',
                'detail' => sprintf('No role is held in entity %s.', $id),
            ], $entityIds),
        );
    }

    public static function noScopes(): self
    {
        return new self(
            ProblemType::ValidationFailed,
            'A token with no scopes can authenticate and do nothing, which presents as a 403 nobody can '.
            'explain. Name the permissions it needs.',
            [['pointer' => '/scopes', 'code' => 'scopes_empty', 'detail' => 'Name at least one scope.']],
        );
    }

    public static function alreadyRevoked(string $name): self
    {
        return new self(
            ProblemType::StateTransitionNotAllowed,
            sprintf(
                'Token "%s" is already revoked. Revoking it again would write a second record of something '.
                'that happened once, and rotating it would mint a successor to a credential that is already '.
                'dead.',
                $name,
            ),
        );
    }

    public static function needsAReason(): self
    {
        return new self(
            ProblemType::ValidationFailed,
            'A revocation says why. "Revoked" with no reason cannot distinguish a routine rotation from a '.
            'response to a leak, and those are the two cases somebody will later need to tell apart.',
            [['pointer' => '/reason', 'code' => 'reason_too_short', 'detail' => 'Give a reason of at least ten characters.']],
        );
    }

    public function render(Request $request): Response
    {
        /*
         | The registry's localized sentence, and the addressed errors.
         |
         | This exception's message is the long English explanation, and it
         | belongs in the log - it is what somebody reads when they ask why a
         | token was refused. The client gets the condition in its own
         | language plus one error per thing to change, each naming a scope or
         | an entity rather than describing it.
         */
        $problem = Problem::of($this->problemType);

        foreach ($this->errors as $error) {
            $problem->withFieldError($error['pointer'], $error['code'], $error['detail']);
        }

        return $problem->toResponse($request);
    }
}
