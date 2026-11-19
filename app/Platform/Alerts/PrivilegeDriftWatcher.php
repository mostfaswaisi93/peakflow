<?php

declare(strict_types=1);

namespace App\Platform\Alerts;

use App\Platform\Services\PrivilegeMatrix;
use App\Support\Telemetry\Alerts\Alert;
use App\Support\Telemetry\Alerts\InvariantWatcher;
use App\Support\Telemetry\Alerts\WatchScope;
use Illuminate\Support\Facades\DB;

/**
 * `privileges:verify`, watched - PF-242, PF-233.
 *
 * ## This closes a criterion that was deliberately left open
 *
 * PF-233 asks for "a drift check on every deploy, because a manual grant made
 * during an incident is exactly how this regresses", and its note says the
 * check is built and nothing invokes it: "there is no deploy pipeline in this
 * repository yet, so there is nowhere to put the one line. Deliberately left
 * open rather than ticked with a note, because a check nobody invokes is
 * exactly the failure mode this criterion was written against."
 *
 * A schedule is not a deploy, and it is strictly better for the failure the
 * criterion names. A manual grant is made during an incident - which is to
 * say between deploys, at 02:00, by somebody who then goes to bed. A check
 * that ran on the next deploy would find it days later; this one finds it
 * within the hour.
 *
 * ## Tenant-scoped, not per entity
 *
 * A privilege is a property of the database and its roles. Running this per
 * entity would raise the same alert once per company in the group, which is
 * how an alert channel becomes something people mute.
 *
 * ## Row-level security is asked about here too
 *
 * Because a policy that has been dropped is the same class of fault as a
 * grant that has been added, and `PrivilegeMatrix` answers both from the
 * same connection in the same pass. The entity scope in the application is
 * not the only line of defence, and the hour it becomes the only one is
 * worth knowing about.
 */
final class PrivilegeDriftWatcher implements InvariantWatcher
{
    /**
     * @param  string  $connection  the connection to ASK ON
     * @param  string|null  $role  the role to ask ABOUT; the application's when null
     *
     * ## Why the role is asked about explicitly and not read as `current_user`
     *
     * Getting this wrong is not a check that fails; it is a check that
     * passes. `PrivilegeMatrix` says so in its own docblock: asked about
     * `current_user` on a privileged connection, every answer is "yes, it
     * holds everything", so a deliberately planted grant is duly reported
     * and the report is about the wrong role.
     *
     * Naming the application's role means the watch answers about the role
     * the application uses whatever connection the watch itself happens to
     * be running on - which is also the only way the game day can prove this
     * alert fires: a GRANT has to be made by the owner, and it has to be
     * asked about from inside the transaction that made it so it can be
     * rolled back.
     */
    public function __construct(
        private readonly PrivilegeMatrix $matrix,
        private readonly string $connection = 'tenant',
        private readonly ?string $role = null,
    ) {}

    /** @return list<string> */
    public function keys(): array
    {
        return ['privilege_drift'];
    }

    public function scope(): WatchScope
    {
        return WatchScope::Tenant;
    }

    /** @return list<Alert> */
    public function watch(string $tenant, ?string $entityId): array
    {
        unset($entityId);

        $connection = DB::connection($this->connection);

        $role = $this->role ?? $this->applicationRole($connection);

        $drift = [
            ...$this->matrix->tenantDrift($connection, $role),
            ...$this->matrix->rowSecurityDrift($connection),
        ];

        if ($drift === []) {
            return [];
        }

        return [Alert::critical(
            key: 'privilege_drift',
            tenant: $tenant,
            /*
             | No entity, said deliberately.
             |
             | A grant is held by a role against a table; it is not dated
             | into a period and it does not belong to one company in the
             | group. Naming an entity here would be inventing a scope, and
             | the reader would go looking at that company's books for
             | something that is true of all of them.
             */
            entityId: Alert::NO_ENTITY,
            period: Alert::NO_PERIOD,
            firstDivergence: $drift[0],
            messageAr: sprintf(
                'انحرفت صلاحيات قاعدة البيانات عن المصفوفة المعلنة في %d موضع، أوّلها: %s. المنح '.
                'اليدوي أثناء حادثة هو الطريق المعتاد لهذا الانحراف. أعِد الوضع إلى ما تعلنه المصفوفة '.
                'وسجّل من منح وماذا ولماذا - ولا تُصلِح قبل تسجيل ذلك.',
                count($drift),
                $drift[0],
            ),
            messageEn: sprintf(
                'The live database privileges have drifted from the declared matrix in %d place(s), the '.
                'first being: %s. A manual grant made during an incident is exactly how this regresses. '.
                'Record who granted what and why BEFORE reverting: the grant is the evidence, and '.
                '`privileges:verify --repair` erases it.',
                count($drift),
                $drift[0],
            ),
            observed: (string) count($drift),
            threshold: '0',
        )];
    }

    /**
     * The role the application connects as.
     *
     * Read from the `tenant` connection's own configuration rather than from
     * whichever connection this watch is running on, because that is the
     * role whose privileges matter - and it is deliberately not
     * `current_user`, which on a privileged connection answers yes to
     * everything.
     */
    private function applicationRole(\Illuminate\Database\Connection $connection): string
    {
        $configured = config('database.connections.tenant.username');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return (string) $connection->selectOne('SELECT current_user AS role')->role;
    }
}
