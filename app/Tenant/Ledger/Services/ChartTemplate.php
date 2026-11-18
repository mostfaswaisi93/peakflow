<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Tenant\Ledger\Charts\ChartTemplateDefinition;
use App\Tenant\Ledger\Charts\ChartTemplateLibrary;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Ledger\Models\Journal;
use Illuminate\Support\Facades\DB;

/**
 * Opens a new entity with a usable chart of accounts - PF-039.
 *
 * ## An empty chart is not a neutral starting point
 *
 * A tenant handed an empty tree has to invent a chart before they can record
 * anything, and what they invent will be shaped by the first three
 * transactions they happen to think of. They also cannot post at all until
 * the control-account roles are assigned, and nothing tells them that - the
 * first posting simply fails with a message about a role.
 *
 * So provisioning seeds a statutory-shaped chart with the roles already
 * assigned, and the tenant edits from something that works.
 *
 * ## The templates are data; this class only applies them
 *
 * They live in `database/charts` as JSON, one file per template, each with a
 * version and a documented source. See {@see ChartTemplateDefinition} for why
 * data rather than code, and {@see ChartTemplateLibrary} for how one is
 * chosen.
 *
 * What is left here is the application: build the tree in one pass, assign
 * the roles, create the journals, and record on the entity which template at
 * which version it came from - because "why does this subsidiary have an
 * account the other one does not" is otherwise answered by archaeology.
 */
final class ChartTemplate
{
    /**
     * Kept as a constant because callers and tests name it.
     *
     * @deprecated Use {@see ChartTemplateLibrary::DEFAULT}. Retained so an
     *             existing reference does not silently become a null key.
     */
    public const GENERIC = ChartTemplateLibrary::DEFAULT;

    public function __construct(private readonly ChartTemplateLibrary $library) {}

    /**
     * Apply a template to an entity.
     *
     * Idempotent: an entity that already has accounts is left alone, because
     * re-running this over a live chart would either duplicate codes or
     * silently rewrite names somebody chose.
     *
     * With no key, the template is chosen from the entity's country, falling
     * back to the generic IFRS chart. Never the nearest-looking national
     * chart - a chart that is almost right is harder to correct than one that
     * is plainly a starting point.
     *
     * @return array{template: string, version: int, accounts: int, journals: int, roles: int}
     */
    public function apply(string $entityId, ?string $templateKey = null): array
    {
        $template = $templateKey !== null
            ? $this->library->get($templateKey)
            : $this->library->forCountry($this->countryOf($entityId));

        if (Account::query()->where('entity_id', $entityId)->exists()) {
            return [
                'template' => $template->key,
                'version' => $template->version,
                'accounts' => 0,
                'journals' => 0,
                'roles' => 0,
            ];
        }

        return DB::connection('tenant')->transaction(function () use ($entityId, $template): array {
            $created = [];
            $paths = [];
            $roles = 0;

            foreach ($template->accounts as $account) {
                $parent = $account->parent === null ? null : $created[$account->parent];

                /*
                 | The path is the chain of ancestors, and each link is the
                 | node's own SEGMENT rather than its whole code.
                 |
                 | The shipped templates all code hierarchically, so for them
                 | the segment of `1.1.3` under `1.1` is `3` and the path
                 | comes out equal to the code - which is what it has always
                 | been. Appending the whole code instead repeats the
                 | parent's prefix: the first version of this gave `1.1.3`
                 | the path `1.1.1.1.3` at depth 5, measured on a Saudi
                 | entity before anything else noticed.
                 |
                 | The segment is what makes a NON-nesting template work.
                 | Numbered 1000, 1100, 1110, nothing can be stripped, so the
                 | segment is the whole code and the path becomes
                 | `1000.1100.1110`. Deriving the path from the code alone
                 | would flatten that tree and leave every grouped report
                 | wrong with nothing in the data looking unusual.
                 */
                $parentCode = $account->parent;

                $segment = $parentCode !== null && str_starts_with($account->code, $parentCode.'.')
                    ? substr($account->code, strlen($parentCode) + 1)
                    : $account->code;

                $path = $parentCode === null
                    ? $segment
                    : $paths[$parentCode].'.'.$segment;

                $paths[$account->code] = $path;

                $created[$account->code] = Account::create([
                    'entity_id' => $entityId,
                    'code' => $account->code,
                    'name_ar' => $account->nameAr,
                    'name_en' => $account->nameEn,
                    'parent_id' => $parent?->getKey(),
                    'path' => $path,
                    'depth' => substr_count($path, '.'),
                    'type' => $account->type->value,
                    /*
                     | The IAS 1 line the template declares - PF-184.
                     |
                     | This used to be `$account->type->statement()`, which
                     | answers a different question: "balance sheet" or
                     | "profit and loss", the same for every account of a
                     | type. It was never a line, so a statement built from
                     | it would have had two rows, and the column's own
                     | comment in the migration says what it is for.
                     */
                    'statement_line' => $account->statementLine?->value,
                    'postable' => $account->postable,
                    'monetary' => $account->monetary,
                ]);

                if ($account->role !== null) {
                    /*
                     | The VAT roles are stamped with the entity's own
                     | country - PF-084.
                     |
                     | A template cannot know it: `ifrs-generic` is used by
                     | entities in any country, and even the Saudi template
                     | is used by a Saudi entity that may later register
                     | abroad. What is true at provisioning is that the
                     | entity is registered where it is incorporated, and a
                     | second jurisdiction is a row an operator adds when the
                     | registration happens.
                     */
                    AccountRole::create([
                        'entity_id' => $entityId,
                        'role' => $account->role->value,
                        'account_id' => $created[$account->code]->getKey(),
                        'jurisdiction' => $account->role->isPerJurisdiction()
                            ? $this->countryOf($entityId)
                            : null,
                    ]);

                    $roles++;
                }
            }

            foreach ($template->journals as $journal) {
                Journal::create([
                    'entity_id' => $entityId,
                    'code' => $journal->code,
                    'type' => $journal->type,
                    'name_ar' => $journal->nameAr,
                    'name_en' => $journal->nameEn,
                    'reserved' => $journal->reserved,
                    'posting_rule' => $journal->postingRule,
                ]);
            }

            /*
             | Written with the chart, in the same transaction.
             |
             | A chart that exists with no record of where it came from is
             | the state this column exists to make unrepresentable, and a
             | separate write is a state that occurs whenever the second one
             | fails.
             */
            DB::connection('tenant')->table('entities')
                ->where('id', $entityId)
                ->update([
                    'chart_template' => $template->key,
                    'chart_template_version' => $template->version,
                    'chart_applied_at' => now(),
                    'updated_at' => now(),
                ]);

            return [
                'template' => $template->key,
                'version' => $template->version,
                'accounts' => count($created),
                'journals' => count($template->journals),
                'roles' => $roles,
            ];
        });
    }

    /**
     * Which required roles this entity is still missing.
     *
     * Asked before the first posting, so the answer is a list of roles rather
     * than a constraint violation naming one of them.
     *
     * @return list<ControlRole>
     */
    public function missingRequiredRoles(string $entityId): array
    {
        /*
         | `->pluck('role')` returns ControlRole INSTANCES, not strings,
         | because the model casts the column. Comparing those against
         | `$role->value` with a strict `in_array` never matches, so every
         | required role reported as missing on an entity that had them all.
         |
         | Mapped back to values explicitly rather than relying on how a cast
         | behaves through pluck, which is the kind of thing that changes in a
         | framework minor version.
         */
        $assigned = AccountRole::query()
            ->where('entity_id', $entityId)
            ->get()
            ->map(static fn (AccountRole $assignment): string => $assignment->role->value)
            ->all();

        return array_values(array_filter(
            ControlRole::cases(),
            static fn (ControlRole $role): bool => $role->isRequiredToPost()
                && ! in_array($role->value, $assigned, true),
        ));
    }

    /** What this entity's chart was built from, if anybody recorded it. */
    public function provenanceOf(string $entityId): ?array
    {
        $row = DB::connection('tenant')->table('entities')
            ->where('id', $entityId)
            ->first(['chart_template', 'chart_template_version', 'chart_applied_at']);

        if ($row === null || $row->chart_template === null) {
            return null;
        }

        return [
            'template' => (string) $row->chart_template,
            'version' => (int) $row->chart_template_version,
            'applied_at' => (string) $row->chart_applied_at,
        ];
    }

    private function countryOf(string $entityId): ?string
    {
        $country = DB::connection('tenant')->table('entities')
            ->where('id', $entityId)
            ->value('country');

        return $country === null ? null : (string) $country;
    }
}
