<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Exceptions\BudgetRefused;
use App\Tenant\Ledger\Models\FiscalYear;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a department was told it may spend - PF-193.
 *
 * ## Why this is in `Ledger` and not in `Reporting`
 *
 * A budget is written, and `Reporting` may not write - a report is a
 * query over the journal and never a second source of truth. A budget is
 * also keyed exactly the way the journal is keyed: an account, a period
 * and the same eight dimension columns at the same axis numbers. Anywhere
 * else it would need a translation of those three, and the first one to
 * drift would make a variance report compare a cost centre against
 * another cost centre's budget with every figure looking plausible.
 *
 * ## The axis NUMBER, as `PostingLine` takes it
 *
 * `dimensions` here is `axis number => dimension value id`, which is what
 * {@see \App\Tenant\Ledger\Posting\PostingLine} takes and what
 * `journal_lines` stores. Taking an axis KEY instead would mean a second
 * copy of the key-to-column resolution that already exists for filtering
 * a register, and two of those is two opinions about which column
 * "project" is.
 *
 * What is checked here is the part a column cannot check: that the value
 * sits on that axis, that the axis is this entity's, and that the value
 * is this entity's - because posting one entity's cost centre onto
 * another's line produces a report where a department appears in a
 * company it does not belong to, and both ids are valid ULIDs.
 *
 * ## Approving locks it, and the next forecast is a new version
 *
 * The lock is in the database, so an import and a console session are
 * held to it too. What this service adds is the refusal with a sentence,
 * the supersession of the version that was the budget until now, and the
 * audit record - because "the budget changed in March" is a question, and
 * the answer is a document somebody approved rather than an edit somebody
 * made.
 */
final class Budgets
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Start a version nobody has approved.
     *
     * @throws BudgetRefused
     */
    public function draft(
        string $entityId,
        string $fiscalYearId,
        string $name,
        string $userId,
        ?string $note = null,
    ): object {
        $entity = Entity::query()->find($entityId);

        if ($entity === null) {
            throw BudgetRefused::because('There is no entity with that id, so there is nothing to budget for.');
        }

        $year = FiscalYear::query()
            ->where('entity_id', $entityId)
            ->whereKey($fiscalYearId)
            ->first();

        if ($year === null) {
            throw BudgetRefused::because(
                'That fiscal year does not belong to this entity. A budget is set against the '.
                'calendar the accounts are kept on, so a year from another entity would give it '.
                'periods no actual could ever be compared to.',
            );
        }

        if (trim($name) === '') {
            throw BudgetRefused::because(
                'A budget version needs a name. There will be three or four of them by March - what '.
                'was approved in December, the reforecast after the first quarter - and "version 2" '.
                'tells nobody which is which.',
            );
        }

        $id = (string) Str::ulid();

        /*
         | The next number, inside the transaction that writes the row.
         |
         | The unique index on (entity, year, version_no) is what actually
         | decides, so two people drafting at once get a refusal rather
         | than two version 3s.
         */
        return DB::connection('tenant')->transaction(function () use (
            $id,
            $entityId,
            $fiscalYearId,
            $name,
            $userId,
            $note,
            $entity,
            $year,
        ): object {
            $next = 1 + (int) DB::connection('tenant')->table('budget_versions')
                ->where('entity_id', $entityId)
                ->where('fiscal_year_id', $fiscalYearId)
                ->max('version_no');

            DB::connection('tenant')->table('budget_versions')->insert([
                'id' => $id,
                'entity_id' => $entityId,
                'fiscal_year_id' => $fiscalYearId,
                'name' => trim($name),
                'version_no' => $next,
                'currency' => (string) $entity->functional_currency,
                'status' => 'draft',
                'created_by' => $userId,
                'note' => $note,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'ledger',
                action: 'budget.drafted',
                subjectType: 'budget_version',
                subjectId: $id,
                subjectLabel: sprintf('%s v%d for %s', trim($name), $next, (string) $year->label),
                after: ['version_no' => $next, 'fiscal_year' => (string) $year->label],
                reason: 'a budget version was started',
                entityId: $entityId,
            );

            return $this->version($id);
        });
    }

    /**
     * Set one budgeted amount, for one period, account and dimension set.
     *
     * @param  array<int, string>  $dimensions  axis number => dimension value id
     *
     * @throws BudgetRefused
     */
    public function set(
        string $versionId,
        string $periodId,
        string $accountCode,
        string $amount,
        array $dimensions = [],
    ): object {
        $version = $this->version($versionId);

        if ((string) $version->status !== 'draft') {
            throw BudgetRefused::because(sprintf(
                'Budget version "%s" is %s, so its lines cannot be changed. A budget is what a '.
                'department was told it may spend: an approved figure that can still be edited '.
                'makes every variance report a report against whatever the budget was most recently '.
                'made to say. Draft a new version, which supersedes this one and leaves both in the '.
                'record.',
                (string) $version->name,
                (string) $version->status,
            ));
        }

        $period = Period::query()
            ->where('entity_id', (string) $version->entity_id)
            ->whereKey($periodId)
            ->first();

        if ($period === null || (string) $period->fiscal_year_id !== (string) $version->fiscal_year_id) {
            throw BudgetRefused::because(
                'That period is not in this version\'s fiscal year. A budget line in a period the '.
                'version does not cover would be compared to an actual the report never asked for, '.
                'and the annual total would not be the sum of its own lines.',
            );
        }

        $account = DB::connection('tenant')->table('accounts')
            ->where('entity_id', (string) $version->entity_id)
            ->where('code', $accountCode)
            ->first(['id', 'code', 'postable']);

        if ($account === null) {
            throw BudgetRefused::because(sprintf(
                'This entity has no account "%s". A budget against an account that does not exist is '.
                'a figure no actual can ever be compared to, and it would read on the report as an '.
                'unspent budget for ever.',
                $accountCode,
            ));
        }

        if ((bool) $account->postable !== true) {
            throw BudgetRefused::because(sprintf(
                'Account "%s" is a roll-up and nothing posts to it, so nothing can ever be spent '.
                'against a budget set there. Budget the accounts underneath it, which is where the '.
                'actuals will land.',
                $accountCode,
            ));
        }

        $columns = $this->dimensionColumns((string) $version->entity_id, $dimensions);

        $money = Money::ofRounded(
            $amount,
            (string) $version->currency,
            RoundingPolicy::halfUp(Scale::Amount),
        );

        $id = (string) Str::ulid();

        $row = [
            'id' => $id,
            'entity_id' => (string) $version->entity_id,
            'budget_version_id' => $versionId,
            'period_id' => $periodId,
            'account_id' => (string) $account->id,
            'amount' => (string) $money->amount,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        /*
         | Upserted on the dimension set, which is what the index is on.
         |
         | Setting the same account, period and cost centre twice is
         | somebody correcting a figure before the version is approved,
         | not a second budget - and inserting both would double it with
         | nothing on the report saying so.
         */
        $existing = $this->lineFor($versionId, $periodId, (string) $account->id, $columns);

        if ($existing !== null) {
            DB::connection('tenant')->table('budget_lines')
                ->where('id', (string) $existing->id)
                ->update(['amount' => (string) $money->amount, 'updated_at' => now()]);

            return (object) ((array) $existing + ['amount' => (string) $money->amount]);
        }

        DB::connection('tenant')->table('budget_lines')->insert($row + $columns);

        $line = DB::connection('tenant')->table('budget_lines')->where('id', $id)->first();

        \assert($line !== null);

        return $line;
    }

    /**
     * Approve a version, lock it, and supersede the one it replaces.
     *
     * @throws BudgetRefused
     */
    public function approve(string $versionId, string $userId): object
    {
        $version = $this->version($versionId);

        if ((string) $version->status !== 'draft') {
            throw BudgetRefused::because(sprintf(
                'Budget version "%s" is already %s. Approving it again would move the lock and the '.
                'attribution onto a second decision nobody made.',
                (string) $version->name,
                (string) $version->status,
            ));
        }

        if ((string) $version->created_by === $userId) {
            /*
             | The author may not approve their own budget.
             |
             | A budget somebody set for themselves and approved for
             | themselves is the control not having operated at all - and
             | unlike a payment, nothing downstream would ever notice,
             | because the figure is only ever compared against itself.
             */
            throw BudgetRefused::because(
                'A budget may not be approved by the person who drafted it. What is being approved '.
                'is what a department may spend, and a budget somebody set for themselves and '.
                'signed for themselves is a limit nobody imposed.',
            );
        }

        $lines = (int) DB::connection('tenant')->table('budget_lines')
            ->where('budget_version_id', $versionId)
            ->count();

        if ($lines === 0) {
            throw BudgetRefused::because(
                'This version has no lines, so approving it would lock a budget of nothing. On the '.
                'variance report that is indistinguishable from a budget of zero deliberately set, '.
                'and every actual would appear as an overspend.',
            );
        }

        return DB::connection('tenant')->transaction(function () use ($version, $versionId, $userId, $lines): object {
            $standing = DB::connection('tenant')->table('budget_versions')
                ->where('entity_id', (string) $version->entity_id)
                ->where('fiscal_year_id', (string) $version->fiscal_year_id)
                ->where('status', 'approved')
                ->first();

            if ($standing !== null) {
                /*
                 | The version it replaces points FORWARD at it.
                 |
                 | So "what was the budget before this, and when did it
                 | stop being the budget" reads in the direction somebody
                 | asks it in. Writing the column is the whole point of
                 | having it: a chain nothing sets returns null for every
                 | row and reads as "this was always the budget".
                 */
                DB::connection('tenant')->table('budget_versions')
                    ->where('id', (string) $standing->id)
                    ->update([
                        'status' => 'superseded',
                        'superseded_by_id' => $versionId,
                        'superseded_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            DB::connection('tenant')->table('budget_versions')
                ->where('id', $versionId)
                ->update([
                    'status' => 'approved',
                    'approved_by' => $userId,
                    'approved_at' => now(),
                    'locked_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->audit->record(
                category: 'ledger',
                action: 'budget.approved',
                subjectType: 'budget_version',
                subjectId: $versionId,
                subjectLabel: sprintf('%s v%d', (string) $version->name, (int) $version->version_no),
                before: $standing === null
                    ? null
                    : ['superseded' => (string) $standing->name, 'superseded_id' => (string) $standing->id],
                after: ['lines' => $lines, 'locked' => true],
                reason: 'the budget was approved and locked; the lines cannot be edited afterwards',
                entityId: (string) $version->entity_id,
            );

            return $this->version($versionId);
        });
    }

    /** The version that IS the budget for a year, if one has been approved. */
    public function approved(string $entityId, string $fiscalYearId): ?object
    {
        return DB::connection('tenant')->table('budget_versions')
            ->where('entity_id', $entityId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('status', 'approved')
            ->first();
    }

    /**
     * @throws BudgetRefused
     */
    public function version(string $versionId): object
    {
        $version = DB::connection('tenant')->table('budget_versions')->where('id', $versionId)->first();

        if ($version === null) {
            throw BudgetRefused::because('There is no budget version with that id.');
        }

        return $version;
    }

    /**
     * The dimension columns for a set of axis numbers, checked.
     *
     * @param  array<int, string>  $dimensions
     * @return array<string, string>
     *
     * @throws BudgetRefused
     */
    private function dimensionColumns(string $entityId, array $dimensions): array
    {
        $columns = [];

        foreach ($dimensions as $axis => $valueId) {
            $axis = (int) $axis;

            if ($axis < 1 || $axis > 8) {
                throw BudgetRefused::because(sprintf(
                    'A journal line carries eight dimensions and this names position %d. A budget '.
                    'keyed to a column the journal does not have could never be compared to '.
                    'anything.',
                    $axis,
                ));
            }

            $value = DB::connection('tenant')->selectOne(<<<'SQL'
                SELECT v.id, x.axis, x.entity_id
                FROM dimension_values v
                JOIN dimension_axes x ON x.id = v.dimension_axis_id
                WHERE v.id = cast(? AS char(26))
            SQL, [(string) $valueId]);

            if ($value === null) {
                throw BudgetRefused::because(
                    'No dimension value answers to that id, so this budget line would be keyed to a '.
                    'cost centre that does not exist - and would show as an unspent budget for ever.',
                );
            }

            if ((string) $value->entity_id !== $entityId) {
                /*
                 | A value belongs to one entity, and this is the check
                 | the dimensions migration names as the one that matters
                 | most.
                 |
                 | Budgeting entity A's cost centre in entity B produces a
                 | report where a department appears in a company it does
                 | not belong to, and because both ids are valid ULIDs
                 | nothing else notices.
                 */
                throw BudgetRefused::because(
                    'That dimension value belongs to another entity. A budget keyed to it would put '.
                    'a department in a company it is not part of, and every figure on the report '.
                    'would look plausible.',
                );
            }

            if ((int) $value->axis !== $axis) {
                throw BudgetRefused::because(sprintf(
                    'That dimension value sits on axis %d and the budget names axis %d. The axis '.
                    'number IS the column, so the line would be written against another '.
                    'classification entirely - the project column holding a cost centre.',
                    (int) $value->axis,
                    $axis,
                ));
            }

            $columns['dimension_'.$axis] = (string) $valueId;
        }

        return $columns;
    }

    /**
     * @param  array<string, string>  $columns
     */
    private function lineFor(
        string $versionId,
        string $periodId,
        string $accountId,
        array $columns,
    ): ?object {
        $query = DB::connection('tenant')->table('budget_lines')
            ->where('budget_version_id', $versionId)
            ->where('period_id', $periodId)
            ->where('account_id', $accountId);

        for ($axis = 1; $axis <= 8; $axis++) {
            $column = 'dimension_'.$axis;
            $value = $columns[$column] ?? null;

            $value === null
                ? $query->whereNull($column)
                : $query->where($column, $value);
        }

        return $query->first();
    }
}
