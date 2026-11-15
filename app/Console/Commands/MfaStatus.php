<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Services\MfaService;
use Illuminate\Console\Command;

/**
 * Who holds a money-moving permission, and do they have a factor - PF-031.
 *
 * The report that makes the control auditable. A requirement that MFA protect
 * `payment.release` is worth nothing if nobody can answer "and does everyone
 * who holds it actually have a factor" without writing a query.
 *
 * Exits non-zero when anyone is missing one, so it can be scheduled.
 */
final class MfaStatus extends Command
{
    protected $signature = 'mfa:status
        {tenant : A tenant slug or id}
        {--missing-only : List only users without a factor}';

    protected $description = 'List users holding permissions that require a second factor, and whether they have one';

    public function handle(TenantConnectionResolver $resolver, MfaService $mfa): int
    {
        $tenant = Tenant::query()
            ->with('connection')
            ->whereHas('connection')
            ->where(fn ($q) => $q->where('slug', $this->argument('tenant'))->orWhere('id', $this->argument('tenant')))
            ->first();

        if ($tenant === null) {
            $this->components->error('No such tenant.');

            return self::FAILURE;
        }

        $resolver->point($tenant);

        $rows = [];
        $missing = 0;

        foreach (User::query()->orderBy('name')->cursor() as $user) {
            $guarded = $mfa->moneyMovingPermissionsHeldBy($user);

            if ($guarded->isEmpty()) {
                continue;
            }

            $hasFactor = $mfa->hasConfirmedFactor($user);

            if (! $hasFactor) {
                $missing++;
            }

            if ($this->option('missing-only') && $hasFactor) {
                continue;
            }

            $rows[] = [
                $user->name,
                $user->active ? 'active' : 'inactive',
                $hasFactor ? 'yes' : 'NO',
                (string) $guarded->count(),
                $guarded->take(3)->implode(', ').($guarded->count() > 3 ? ', …' : ''),
            ];
        }

        if ($rows === []) {
            $this->components->info('Nobody holds a permission that requires a second factor.');

            return $missing > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->table(['User', 'Status', 'Factor', 'Guarded', 'Examples'], $rows);

        if ($missing > 0) {
            $this->components->error(sprintf(
                '%d user(s) hold permissions that require a second factor and do not have one. Each is a '.
                'password away from being able to move money.',
                $missing,
            ));

            return self::FAILURE;
        }

        $this->components->info('Everyone holding a guarded permission has a confirmed second factor.');

        return self::SUCCESS;
    }
}
