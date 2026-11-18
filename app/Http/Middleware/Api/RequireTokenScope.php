<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Tenant\Identity\Models\ApiToken;
use App\Tenant\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The scope a route needs, which is a permission key - PF-218.
 *
 * ## Two questions, two answers, and that is the point
 *
 * A machine request is refused for one of two quite different reasons, and an
 * integrator can fix only one of them:
 *
 *   - the TOKEN was issued without this scope. The fix is to issue a new
 *     token, in their own settings. 403 `token-scope-missing`.
 *   - the USER behind the token does not hold this permission in this entity.
 *     The fix is a role grant, by somebody else. 403 `forbidden`.
 *
 * A single "403 Forbidden" for both is the refusal that generates a support
 * ticket, because the person reading it cannot tell which of the two it is -
 * and the first thing they try is re-issuing the token, which for the second
 * case cannot work and looks like the API is broken.
 *
 * ## The scope IS the permission, so there is nothing to map
 *
 * `api.scope:journal.post` names `journal.post`, the permission. There is no
 * table of scopes to permissions, because a mapping is a second
 * authorization model and the two drift: a scope covering two permissions
 * grants the second one to every token holding it, and nobody notices,
 * because the token works.
 *
 * `ApiContractRules` asserts that every scope named on a route is a key in
 * the permission catalogue, so a typo is a failed build rather than a route
 * nobody can reach.
 *
 * ## It is not the only line of defence
 *
 * The same narrowing is applied inside the gate, so anything a HANDLER
 * authorizes - a service calling `Gate::denies()` three layers down - is
 * narrowed by the token too. This middleware exists for the precise refusal;
 * the gate exists so that a route somebody forgets to annotate is still
 * answered by the token's scopes rather than by the user's full permissions.
 */
final class RequireTokenScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if (! $user instanceof User || ! $token instanceof ApiToken) {
            // EstablishApiActor runs first and answers this.
            return $next($request);
        }

        foreach ($scopes as $scope) {
            if (! $token->can($scope)) {
                return Problem::of(ProblemType::TokenScopeMissing)
                    ->withLocalizedDetail(
                        sprintf(
                            'يحتاج هذا المسار النطاق %s، والرمز يحمل %s. الرمز القائم لا يُوسَّع أبدًا - '.
                            'فذلك لا يترك أثرًا لما كان يستطيعه أمس - فأصدر رمزًا جديدًا يحمل النطاق وألغِ هذا.',
                            $scope,
                            implode('، ', $token->scopes()),
                        ),
                        sprintf(
                            'This route needs the scope %s and the token carries %s. A live token is never '.
                            'widened - that would leave no record of what it could do yesterday - so issue '.
                            'a new one with the scope and revoke this one.',
                            $scope,
                            implode(', ', $token->scopes()),
                        ),
                    )
                    ->withFieldError('/scopes', 'scope_missing', $scope)
                    /*
                     | The facts, machine-readable beside the sentence.
                     |
                     | A client should never have to read prose to find out
                     | what its token carries - and the prose is localized,
                     | so parsing it would mean parsing Arabic half the time.
                     */
                    ->withExtensions([
                        'required_scope' => $scope,
                        'token_scopes' => $token->scopes(),
                    ])
                    ->toResponse($request);
            }

            /*
             | And the person behind it, in THIS entity.
             |
             | Through the gate rather than through `holds()` directly, so
             | that this check and every `can:` in the application answer from
             | the same place. `Gate::denies` here reports the user's own
             | permission: the token's scope was settled above, so a refusal
             | at this point is a grant that is missing and not a credential
             | that is too narrow.
             */
            if (Gate::denies($scope)) {
                return Problem::of(ProblemType::Forbidden)
                    ->withLocalizedDetail(
                        sprintf(
                            'المستخدم صاحب هذا الرمز لا يملك %s في هذا الكيان. الرمز تفويض لما يستطيعه '.
                            'صاحبه ولا يتجاوزه، فإعادة إصدار الرمز لا تنفع: الصلاحية هي ما يجب منحه.',
                            $scope,
                        ),
                        sprintf(
                            'The user behind this token does not hold %s in this entity. A token delegates '.
                            'what its owner can do and cannot exceed it, so re-issuing the token will not '.
                            'help: the permission has to be granted.',
                            $scope,
                        ),
                    )
                    ->withExtensions(['required_permission' => $scope])
                    ->toResponse($request);
            }
        }

        return $next($request);
    }
}
