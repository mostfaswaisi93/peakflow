<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Identity\Models\User;
use App\Tenant\Purchasing\Models\ApprovalRule;
use App\Tenant\Purchasing\Models\ApprovalRuleStep;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A bill approval chain in the demo workspace, and the people to run it - PF-265.
 *
 *     php artisan db:seed --class=Database\\Seeders\\DemoApprovalSeeder
 *
 * Two rules and two people, so the chain can be walked end to end:
 *
 * - `BILL-ROUTINE`: a bill up to 20,000 needs the controller.
 * - `BILL-LARGE`: anything above needs the controller, then the owner.
 * - `ap@demo.test` (AP clerk) enters and submits bills and may not approve;
 *   `controller@demo.test` approves. Both sign in with `peakflow`.
 *
 * The clerk is the point: the engine refuses an approval by the person who
 * submitted, so a demo with only the owner could never get a bill through.
 *
 * Local and testing only, and idempotent - a second run changes nothing.
 */
final class DemoApprovalSeeder extends Seeder
{
    private const LIMIT = '20000.0000';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The demo approval chain is for local development, not the '.app()->environment().' environment.');
        }

        $tenant = Tenant::query()->where('slug', DevelopmentSeeder::TENANT_SLUG)->first()
            ?? throw new RuntimeException('No demo workspace - run the DevelopmentSeeder first.');

        app(TenantConnectionResolver::class)->point($tenant);

        /** @var Entity $entity */
        $entity = Entity::query()->where('code', 'DEMO')->firstOrFail();
        app(EntityContext::class)->use($entity);

        DB::connection('tenant')->transaction(function () use ($entity): void {
            $this->person($entity, 'ap@demo.test', 'Demo AP Clerk', 'ap_clerk');
            $this->person($entity, 'controller@demo.test', 'Demo Controller', 'controller');

            $this->rule($entity, 'BILL-LARGE', 'اعتماد الفواتير الكبيرة', 'Large bills', self::LIMIT, null, 10, ['controller', 'owner']);
            $this->rule($entity, 'BILL-ROUTINE', 'اعتماد الفواتير الاعتيادية', 'Routine bills', null, self::LIMIT, 20, ['controller']);
        });

        $this->command?->info('Bill approval chain ready: ap@demo.test submits, controller@demo.test approves (password: peakflow).');
    }

    private function person(Entity $entity, string $email, string $name, string $role): void
    {
        $user = User::query()->where('email', $email)->first()
            ?? User::create(['name' => $name, 'email' => $email, 'password' => 'peakflow', 'active' => true]);

        $roleId = DB::connection('tenant')->table('roles')->where('key', $role)->value('id');

        if (! \is_string($roleId)) {
            throw new RuntimeException(sprintf('The demo workspace has no "%s" role.', $role));
        }

        DB::connection('tenant')->table('user_entity_roles')->insertOrIgnore([
            'id' => strtolower((string) Str::ulid()),
            'user_id' => $user->id,
            'entity_id' => $entity->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<string> $roles one step per role, in order */
    private function rule(Entity $entity, string $code, string $nameAr, string $nameEn, ?string $from, ?string $to, int $priority, array $roles): void
    {
        if (ApprovalRule::query()->where('code', $code)->exists()) {
            return;
        }

        $rule = ApprovalRule::create([
            'entity_id' => $entity->id,
            'code' => $code,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'document_type' => 'bill',
            'amount_from' => $from,
            'amount_to' => $to,
            'priority' => $priority,
            'stops_here' => true,
            'active' => true,
        ]);

        foreach (array_values($roles) as $index => $role) {
            ApprovalRuleStep::create([
                'entity_id' => $entity->id,
                'approval_rule_id' => $rule->id,
                'sequence' => $index + 1,
                'quorum' => 1,
                'role' => $role,
            ]);
        }
    }
}
