<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

/**
 * A token's scopes ARE the permission keys - PF-218.
 *
 * ## Why this class is so thin
 *
 * Because the alternative is a second list. The obvious implementation of API
 * scopes is an enum of them - `invoices:read`, `invoices:write`,
 * `ledger:post` - and it is wrong in a way that only shows up later: it is a
 * second authorization model, coarser than the first, and the two drift the
 * moment a permission is added to one of them.
 *
 * The drift is never symmetrical. A permission added with no scope is simply
 * unreachable by machines, which somebody notices. A SCOPE that maps to two
 * permissions grants the second one to every token holding it - and nobody
 * notices, because the token works.
 *
 * So there is no second list. A scope is a permission key, spelled the same,
 * and this class exists to say that in one place and to refuse anything else.
 *
 * ## The wildcard is not a scope
 *
 * Sanctum's convention is that `*` means every ability. It cannot mirror a
 * permission because it names none, and it widens itself with every
 * deployment that adds one - a token issued today for reading invoices would
 * silently acquire `payment.release` the day that permission ships.
 *
 * @see \App\Tenant\Identity\Models\ApiToken::can()  which refuses it again at the point of use
 */
final class ApiScopes
{
    public const WILDCARD = '*';

    public function __construct(private readonly PermissionCatalogue $catalogue) {}

    /**
     * Every scope that exists, which is every permission that exists.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $keys = $this->catalogue->all()->keys()->map(static fn (mixed $key): string => (string) $key)->all();

        sort($keys, SORT_STRING);

        return array_values($keys);
    }

    public function exists(string $scope): bool
    {
        return $scope !== self::WILDCARD && $this->catalogue->has($scope);
    }

    /**
     * The scopes in a request that are not permissions.
     *
     * Returned as a list rather than reported one at a time, so that somebody
     * setting up an integration with four misspelled scopes learns all four
     * at once instead of discovering them over four attempts.
     *
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public function unknown(array $scopes): array
    {
        return array_values(array_filter(
            $scopes,
            fn (string $scope): bool => ! $this->exists($scope),
        ));
    }

    /**
     * Does a permission need a second factor on the person who holds it?
     *
     * Asked when a token is issued, because the token outlives the session
     * that created it. A permission marked `requires_mfa` is one the product
     * will not grant to a user with no enrolled factor; a token carrying it
     * would be that grant arriving by another route, and a long-lived one.
     */
    public function requiresMfa(string $scope): bool
    {
        return (bool) $this->catalogue->get($scope)?->requires_mfa;
    }

    /**
     * Does a permission demand re-authentication at the moment of the act?
     *
     * These can never be token scopes, and the reason is in the definition.
     * A step-up assertion proves that the person is at the keyboard NOW -
     * releasing a payment run, reopening a closed period. A bearer token in a
     * configuration file proves the opposite: that nobody is. Issuing one
     * would turn "re-authenticate to release this payment" into "store a
     * credential that can release payments".
     */
    public function requiresStepUp(string $scope): bool
    {
        return (bool) $this->catalogue->get($scope)?->requires_step_up;
    }

    /**
     * The scopes a token may never carry, whatever the user holds.
     *
     * @return list<string>
     */
    public function neverIssuable(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (string $scope): bool => $this->requiresStepUp($scope),
        ));
    }
}
