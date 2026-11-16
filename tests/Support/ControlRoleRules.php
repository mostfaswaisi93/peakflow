<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Ledger\Charts\ChartTemplateLibrary;
use App\Tenant\Ledger\Enums\ControlRole;
use Illuminate\Support\Facades\DB;

/**
 * Every control role, through every method that claims to handle it.
 *
 * ## Why this exists
 *
 * `ControlRole::expectedType()` is a `match` with no `default`, which is the
 * right way to write it: adding a role should be a compile-time question
 * rather than a silent fall-through to something plausible. But PHP only
 * raises that question when the method is **called** with the new case, and
 * a `match` with no caller raises nothing at all.
 *
 * Measured: `goods_received_not_invoiced` was added for PF-132 and was not
 * listed in `expectedType()`. Nothing in the product called that method, so
 * nothing failed - the first caller would have been whoever validated a
 * chart template a month later, and the error would have read
 * `UnhandledMatchError` with no indication of which role or why.
 *
 * So this walks every case through every method, and asserts the roles that
 * need an account in the shipped charts have one of the right type. A match
 * with no `default` is a promise that every case is listed; the promise is
 * only kept if something checks it.
 */
final class ControlRoleRules
{
    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'every role answers every question asked of it' => $this->everyMethodAnswers(),
            'the required roles are the ones nothing can post without' => $this->requiredRoles(),
            'only the VAT pair is per jurisdiction' => $this->perJurisdiction(),
            'every shipped chart gives each role an account of the right type' => $this->chartsAgree(),
            'the database allows exactly the roles the enum declares' => $this->databaseAgrees(),
        ];
    }

    /** @return list<string> */
    private function everyMethodAnswers(): array
    {
        $violations = [];

        foreach (ControlRole::cases() as $role) {
            foreach (['expectedType', 'isRequiredToPost', 'isPerJurisdiction'] as $method) {
                try {
                    $role->{$method}();
                } catch (\UnhandledMatchError) {
                    $violations[] = sprintf(
                        '%s::%s() has no arm for "%s". A match with no default is a promise that every '.
                        'case is listed, and PHP only asks the question when the method is called - so '.
                        'a role added without an arm fails at whatever call site reaches it first, with '.
                        'an UnhandledMatchError that names neither the role nor the reason',
                        'ControlRole',
                        $method,
                        $role->value,
                    );
                } catch (\Throwable $e) {
                    $violations[] = sprintf(
                        'ControlRole::%s() threw for "%s": %s',
                        $method,
                        $role->value,
                        $e->getMessage(),
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * A role's expected type, or null where the enum does not name one.
     *
     * Every check in this class is called eagerly as the suite is built, so
     * an `UnhandledMatchError` escaping any of them is reported as "the suite
     * could not be built" - which names neither the role nor the method, and
     * replaces the diagnosis with a shrug. Measured: that is exactly what the
     * first bite of this suite produced.
     */
    private function expectedTypeOf(ControlRole $role): ?\App\Tenant\Ledger\Enums\AccountType
    {
        try {
            return $role->expectedType();
        } catch (\UnhandledMatchError) {
            return null;
        }
    }

    /** @return list<string> */
    private function requiredRoles(): array
    {
        $required = array_map(
            static fn (ControlRole $role): string => $role->value,
            array_values(array_filter(
                ControlRole::cases(),
                static fn (ControlRole $role): bool => $role->isRequiredToPost(),
            )),
        );

        sort($required);

        /*
         | The list, written out.
         |
         | A test that recomputed it from the enum would pass whatever the
         | enum said, which is the shape of a test that enforces nothing.
         | Changing what an entity cannot post without is a decision, and a
         | decision belongs in a diff.
         */
        $expected = [
            'accounts_payable',
            'accounts_receivable',
            'bank',
            'retained_earnings',
            'rounding_difference',
            'suspense',
        ];

        if ($required !== $expected) {
            return [sprintf(
                'the roles required before an entity can post are [%s] and the list this check holds '.
                'is [%s]. Changing that list is a decision about what a tenant must configure before '.
                'they can do anything at all, and it belongs in a diff',
                implode(', ', $required),
                implode(', ', $expected),
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function perJurisdiction(): array
    {
        $violations = [];

        foreach (ControlRole::cases() as $role) {
            $expected = \in_array($role, [ControlRole::VatInput, ControlRole::VatOutput], true);

            if ($role->isPerJurisdiction() !== $expected) {
                $violations[] = sprintf(
                    '"%s" is %s per jurisdiction. An entity registered in two jurisdictions files two '.
                    'returns and each has to reconcile to an account, which is why the VAT pair is '.
                    'split - and a bank account is a bank account wherever the entity is registered, so '.
                    'giving it a jurisdiction would be a column somebody fills in because it is there',
                    $role->value,
                    $role->isPerJurisdiction() ? '' : 'not',
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function chartsAgree(): array
    {
        $violations = [];

        foreach (ChartTemplateLibrary::shipped()->all() as $template) {
            $byRole = [];

            foreach ($template->accounts as $account) {
                if ($account->role === null) {
                    continue;
                }

                $byRole[$account->role->value][] = $account;
            }

            foreach (ControlRole::cases() as $role) {
                $expected = $this->expectedTypeOf($role);

                if ($expected === null) {
                    /*
                     | Already reported, with its own explanation.
                     |
                     | `everyMethodAnswers()` names the role and the method,
                     | and every check in this class is called eagerly when
                     | the suite is built - so letting the error escape here
                     | would report "the suite could not be built" instead,
                     | which names neither.
                     */
                    continue;
                }

                $accounts = $byRole[$role->value] ?? [];

                if ($accounts === [] && $role->isRequiredToPost()) {
                    $violations[] = sprintf(
                        'the %s chart has no account for "%s", which an entity cannot post without',
                        $template->key,
                        $role->value,
                    );

                    continue;
                }

                foreach ($accounts as $account) {
                    if ($account->type !== $expected) {
                        $violations[] = sprintf(
                            'in the %s chart, account %s carries the role "%s" and is typed %s, and the '.
                            'role expects %s. A control account of the wrong type puts the balance on '.
                            'the wrong side of the statements, and every report that groups by type '.
                            'repeats the error',
                            $template->key,
                            $account->code,
                            $role->value,
                            $account->type->value,
                            $expected->value,
                        );
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * The database's allowlist and the enum, compared.
     *
     * `account_roles_known` enumerates the roles a row may carry. A role in
     * the enum and not in the constraint is a role nothing can be assigned;
     * one in the constraint and not in the enum is a role nothing resolves,
     * which is how a typo becomes a column value.
     *
     * @return list<string>
     */
    private function databaseAgrees(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return [];
        }

        app(TenantConnectionResolver::class)->point($tenant);

        $definition = (string) (DB::connection('tenant')->selectOne(
            "SELECT pg_get_constraintdef(oid) AS def
               FROM pg_constraint
              WHERE conname = 'account_roles_known'"
        )->def ?? '');

        if ($definition === '') {
            return ['the `account_roles_known` constraint does not exist, so any string can be a role'];
        }

        $violations = [];

        foreach (ControlRole::cases() as $role) {
            if (! str_contains($definition, "'".$role->value."'")) {
                $violations[] = sprintf(
                    'the role "%s" is declared in the enum and missing from `account_roles_known`, so '.
                    'no account can be given it - which is found by whoever imports a chart that uses '.
                    'it, as a constraint violation naming neither the role nor the reason',
                    $role->value,
                );
            }
        }

        if (preg_match_all("/'([a-z_]+)'/", $definition, $matches) === false) {
            return $violations;
        }

        $declared = array_map(static fn (ControlRole $role): string => $role->value, ControlRole::cases());

        foreach (array_unique($matches[1]) as $inConstraint) {
            if (! \in_array($inConstraint, $declared, true)) {
                $violations[] = sprintf(
                    'the constraint allows the role "%s" and the enum does not declare it, so a row can '.
                    'carry a role nothing resolves - which is how a typo becomes a column value',
                    $inConstraint,
                );
            }
        }

        return $violations;
    }
}
