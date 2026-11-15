<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;
use App\Tenant\Parties\Exceptions\BankAccountNotVerified;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Services\BankDetailRegistry;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The controls around party bank details - PF-061.
 *
 * The highest-value fraud surface in the product: change where a supplier's
 * money goes, then release a payment to it. Every check here is one of the
 * layers that makes a single person acting alone insufficient.
 *
 * The encryption is the least of them. It protects the number from being
 * READ; it does nothing against an authorised user legitimately changing the
 * account to one they control. What stops that is the second person, and
 * these checks are mostly about whether that second person can be skipped.
 */
final class PartyEnforcementRules
{
    private ?Connection $connection = null;

    private string $entityId = '';

    private ?User $clerk = null;

    private ?User $approver = null;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return ['party controls (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return [
                'a bank account number never becomes an attribute' => $this->numberIsNotAnAttribute(),
                'a new or changed account is unusable until verified' => $this->changesRequireVerification(),
                'the person who changed an account cannot verify it' => $this->verifierIsNotTheAuthor(),
                'a bank number never reaches the audit log' => $this->numberNeverReachesTheLog(),
            ];
        } catch (Throwable $e) {
            return ['party controls could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function numberIsNotAnAttribute(): array
    {
        $violations = [];
        $account = $this->newAccount('SA0380000000608010167519');
        $serialised = $account->fresh()->toArray();

        foreach (['iban', 'iban_encrypted', 'account_number', 'account_number_encrypted'] as $key) {
            if (array_key_exists($key, $serialised)) {
                $violations[] = sprintf(
                    '"%s" is in the model\'s array form, so it reaches anything that serialises the model - a '.
                    'log line, a webhook payload, an exception report, a `dd()`',
                    $key,
                );
            }
        }

        if ($account->last_four !== '7519') {
            $violations[] = 'the last four digits are not recorded, so nobody can confirm the account on a '.
                'phone call without decrypting it - which is the control that actually catches substitution';
        }

        $stored = (string) $this->connection->table('party_bank_accounts')
            ->where('id', $account->getKey())
            ->value('iban_encrypted');

        if ($stored === '' || str_contains($stored, '6080')) {
            $violations[] = 'the account is stored in clear at the column, so disk encryption is the only '.
                'thing protecting it - and that protects a stolen disk, not a SELECT';
        }

        if ($account->fresh()->revealIban() !== 'SA0380000000608010167519') {
            $violations[] = 'the stored account does not decrypt back to what was entered';
        }

        return $violations;
    }

    /** @return list<string> */
    public function changesRequireVerification(): array
    {
        $registry = app(BankDetailRegistry::class);
        $account = $this->newAccount('SA0380000000608010167519');
        $violations = [];

        if (! $account->requires_reverification) {
            $violations[] = 'a NEW account is usable immediately, so an account added by an import or an API '.
                'client skips the second person entirely';
        }

        try {
            $registry->assertUsable($account->fresh());

            $violations[] = 'a payment to an unverified account was allowed';
        } catch (BankAccountNotVerified) {
            // Correct.
        }

        $registry->verify($account->fresh(), (string) $this->approver?->getKey(), 'phoned the published number');

        if ($account->fresh()->requires_reverification) {
            $violations[] = 'verifying an account did not make it usable';
        }

        /*
         | A raw UPDATE must re-arm the block.
         |
         | The attack is somebody reaching the column another way - a support
         | script, a bulk import, a console session - so the flag is set by a
         | database trigger rather than by the service.
         */
        $this->connection->table('party_bank_accounts')
            ->where('id', $account->getKey())
            ->update(['bic' => 'SABBSARI']);

        if (! $account->fresh()->requires_reverification) {
            $violations[] = 'changing the account with a direct UPDATE left it verified, so anything reaching '.
                'the column outside the service bypasses the second person';
        }

        return $violations;
    }

    /** @return list<string> */
    public function verifierIsNotTheAuthor(): array
    {
        $registry = app(BankDetailRegistry::class);
        $account = $this->newAccount('SA4420000001234567891234');

        try {
            $registry->verify($account->fresh(), (string) $this->clerk?->getKey(), 'confirmed it myself');

            return ['the person who changed an account was allowed to verify it; one person acting alone is '.
                'exactly the case the control exists to catch'];
        } catch (BankAccountNotVerified) {
            return [];
        }
    }

    /** @return list<string> */
    public function numberNeverReachesTheLog(): array
    {
        $this->newAccount('SA0380000000608010167519');

        $everything = $this->connection->table('audit_log')
            ->get()
            ->map(static fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))
            ->implode(' ');

        $violations = [];

        foreach (['608010167519', '0380000000', '1234567891234'] as $fragment) {
            if (str_contains($everything, $fragment)) {
                $violations[] = sprintf(
                    'the fragment "%s" of a bank account appears in the audit log, which is read by far more '.
                    'people than the account is',
                    $fragment,
                );
            }
        }

        return $violations;
    }

    private function newAccount(string $iban): \App\Tenant\Parties\Models\PartyBankAccount
    {
        auth()->guard('web')->setUser($this->clerk);

        $party = Party::create([
            'legal_name' => 'Probe Supplier '.bin2hex(random_bytes(3)),
            'country' => 'SA',
            'is_supplier' => true,
        ]);

        return app(BankDetailRegistry::class)->record($party, iban: $iban, bic: 'RIBLSARI');
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'PTY'.random_int(100, 999),
            'legal_name' => 'Party check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $context->use($entity);

        $role = Role::query()->where('key', 'controller')->firstOrFail();

        foreach (['clerk', 'approver'] as $which) {
            $user = User::create([
                'name' => 'Party '.$which,
                'email' => $which.'-'.bin2hex(random_bytes(5)).'@invalid.test',
                'password' => Str::password(32),
                'active' => true,
            ]);

            UserEntityRole::create([
                'user_id' => $user->getKey(),
                'entity_id' => $this->entityId,
                'role_id' => $role->getKey(),
            ]);

            $this->{$which} = $user;
        }
    }

    private function tenant(): ?Tenant
    {
        try {
            return Tenant::query()->with('connection')->whereHas('connection')->orderBy('slug')->first();
        } catch (Throwable) {
            return null;
        }
    }
}
