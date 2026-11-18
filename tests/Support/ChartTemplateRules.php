<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Ledger\Charts\ChartTemplateLibrary;
use App\Tenant\Ledger\Charts\Exceptions\InvalidChartTemplate;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Ledger\Models\Journal;
use App\Tenant\Ledger\Services\ChartTemplate;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Chart templates, and the charts they produce - PF-039.
 *
 * ## What is actually being claimed
 *
 * That an entity provisioned from any shipped template can post immediately.
 * Not "has accounts": a chart with a required control role unassigned is a
 * chart whose first posting fails with a message naming one role at a time,
 * weeks after provisioning, with nothing pointing at the template.
 *
 * So each template is applied to a real entity and the result is interrogated
 * - roles assigned, roles postable, tree shaped, provenance recorded.
 *
 * ## And that the loader refuses an incoherent one
 *
 * Nine ways a template can be wrong, each built as a data file and each
 * expected to be refused at LOAD. A template is data, and data gets edited by
 * whoever understands the accounting rather than the code, so the only useful
 * place to catch a mistake is the moment the file is read.
 *
 * ## The non-nesting template
 *
 * The shipped templates all code hierarchically, which means the materialized
 * path comes out equal to the code and a path bug is invisible. So one check
 * builds a template numbered 1000, 1100, 1110 - the other convention, and the
 * common one outside IFRS - and asserts the tree still nests.
 */
final class ChartTemplateRules
{
    private ?Connection $connection = null;

    private string $temporary = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $this->temporary = sys_get_temp_dir().'/peakflow-charts-'.Str::random(8);
        mkdir($this->temporary, 0o700, true);

        $results = [
            'every shipped template loads and is coherent' => $this->shippedTemplatesLoad(),
            'an incoherent template is refused at load' => $this->incoherenceIsRefused(),
            'a template that does not nest its codes still nests its tree' => $this->pathsNestWithoutNestedCodes(),
            'a chart that changed says so in its version' => $this->versionsMoveWithContents(),
        ];

        $tenant = $this->tenant();

        if ($tenant === null) {
            return $results + ['chart application (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            return $results + [
                'each template produces a chart that can post' => $this->everyTemplateProducesAPostableChart(),
                'a path is its parent plus one segment, in every chart' => $this->everyPathIsItsParentPlusOneSegment(),
                'the country chooses the template, and never a near miss' => $this->countryChoosesTheTemplate(),
                'the chart records which template it came from' => $this->provenanceIsRecorded(),
            ];
        } catch (Throwable $e) {
            return $results + ['chart application could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 220),
            ]];
        } finally {
            $this->connection->rollBack();
            $this->cleanUp();
        }
    }

    /** @return list<string> */
    /**
     * A chart that changed has a version that moved.
     *
     * ## Why a manifest and not a convention
     *
     * `ChartTemplateDefinition` refuses a template with no version, with the
     * reasoning that "a chart that changed and did not say so is a chart two
     * entities disagree about" - an entity provisioned last month and one
     * provisioned today both record `version: 2` while holding different
     * accounts, and nothing afterwards can tell them apart.
     *
     * But nothing checked that the version moved **when** the chart changed.
     *
     * Measured: the goods-received-not-invoiced account was added to all
     * three shipped charts for PF-132 and every one of them stayed at
     * version 2. The chart suite passed, because every check it had was
     * about whether a template is coherent rather than whether it is the
     * same template it was yesterday.
     *
     * So `database/chart-versions.json` records the hash of each version,
     * and this asserts two things: the file on disk is the last entry, and
     * no two entries of one chart share a version. The second is what forces
     * the bump - keeping the version and changing the content means adding
     * an entry that collides with the one before it.
     *
     * Adding an account therefore means adding a line to the manifest, which
     * is the moment somebody notices whether they bumped the version.
     *
     * @return list<string>
     */
    public function versionsMoveWithContents(): array
    {
        $directory = dirname(__DIR__, 2).'/database/charts';

        /*
         | The manifest sits OUTSIDE the template directory.
         |
         | `ChartTemplateLibrary` loads every `*.json` in that directory as a
         | template, so a manifest beside them is refused at load for having
         | no `key` - which breaks provisioning rather than the check that
         | wanted it. Measured: that is exactly what happened on the first
         | attempt.
         */
        $manifestPath = dirname(__DIR__, 2).'/database/chart-versions.json';

        if (! is_file($manifestPath)) {
            return ['`database/chart-versions.json` is missing, so a chart can change without saying so'];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $recorded = $manifest['charts'] ?? [];
        $violations = [];

        foreach (glob($directory.'/*.json') ?: [] as $file) {
            $contents = (string) file_get_contents($file);
            $hash = hash('sha256', $contents);
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            $key = (string) ($decoded['key'] ?? basename($file, '.json'));
            $version = (int) ($decoded['version'] ?? 0);

            $history = $recorded[$key] ?? null;

            if ($history === null || $history === []) {
                $violations[] = sprintf(
                    'the %s chart has no history in chart-versions.json, so nothing can tell whether it '.
                    'changed since it was last released',
                    $key,
                );

                continue;
            }

            $last = $history[count($history) - 1];

            if ((string) $last['sha256'] !== $hash) {
                $violations[] = sprintf(
                    'the %s chart on disk does not match the last entry in chart-versions.json. It changed '.
                    'without saying so: an entity provisioned before the change and one provisioned '.
                    'after both record version %d while holding different accounts, and nothing '.
                    'afterwards can tell them apart. Add an entry with the new version and hash',
                    $key,
                    $version,
                );
            }

            if ((int) $last['version'] !== $version) {
                $violations[] = sprintf(
                    'the %s chart says version %d and the last manifest entry says %d',
                    $key,
                    $version,
                    (int) $last['version'],
                );
            }

            $versions = array_map(static fn (array $entry): int => (int) $entry['version'], $history);

            if (count($versions) !== count(array_unique($versions))) {
                $violations[] = sprintf(
                    'the %s chart has two manifest entries sharing a version. The contents changed and '.
                    'the version did not, which is the thing this check exists to refuse',
                    $key,
                );
            }

            foreach ($history as $index => $entry) {
                if ($index > 0 && (int) $entry['version'] <= (int) $history[$index - 1]['version']) {
                    $violations[] = sprintf(
                        'the %s chart\'s manifest is not in ascending version order',
                        $key,
                    );

                    break;
                }
            }
        }

        foreach (array_keys($recorded) as $key) {
            if (! is_file($directory.'/'.$key.'.json')) {
                $violations[] = sprintf(
                    'the manifest records the %s chart and no such file ships, so the manifest and '.
                    'the library disagree about what exists',
                    (string) $key,
                );
            }
        }

        return $violations;
    }

    public function shippedTemplatesLoad(): array
    {
        $violations = [];

        try {
            $templates = ChartTemplateLibrary::shipped()->all();
        } catch (Throwable $e) {
            return [sprintf('a shipped template is not loadable: %s', $e->getMessage())];
        }

        if (! isset($templates[ChartTemplateLibrary::DEFAULT])) {
            $violations[] = sprintf(
                'the default template "%s" is not shipped, so an entity in a country with no template of '.
                'its own has nothing to be provisioned from',
                ChartTemplateLibrary::DEFAULT,
            );
        }

        /*
         | The jurisdictions the task names, by name.
         |
         | PF-039 asks for a generic IFRS chart, a Saudi chart and a
         | Jordanian chart at minimum. Asserting the count instead would pass
         | on three copies of the generic one.
         */
        foreach (['ifrs-generic', 'saudi-arabia', 'jordan'] as $required) {
            if (! isset($templates[$required])) {
                $violations[] = sprintf('the template "%s" is not shipped', $required);
            }
        }

        foreach ($templates as $key => $template) {
            if (mb_strlen($template->sourceNote) < 120) {
                $violations[] = sprintf(
                    '"%s" has a source note of %d characters. A jurisdiction template has to say what is '.
                    'and is NOT mandated, because overstating that is worse than shipping no template',
                    $key,
                    mb_strlen($template->sourceNote),
                );
            }

            if ($key !== ChartTemplateLibrary::DEFAULT && $template->countries === []) {
                $violations[] = sprintf(
                    '"%s" serves no country, so nothing will ever select it',
                    $key,
                );
            }

            if ($key === ChartTemplateLibrary::DEFAULT && $template->countries !== []) {
                $violations[] = sprintf(
                    'the default template claims the countries %s. It is the fallback for every country '.
                    'with no template of its own, and claiming some would make it a near miss for those',
                    implode(',', $template->countries),
                );
            }
        }

        return $violations;
    }

    /**
     * Fifteen incoherent templates, each expected to be refused.
     *
     * @return list<string>
     */
    public function incoherenceIsRefused(): array
    {
        $violations = [];

        $cases = [
            'a parent that is not in the template' => function (array $t): array {
                $t['accounts'][] = $this->account('9.9', '7', 'asset', true, false);

                return $t;
            },
            'a child written before its parent' => function (array $t): array {
                array_unshift($t['accounts'], $this->account('1.9.1', '1.9', 'asset', true, true));
                $t['accounts'][] = $this->group('1.9', '1', 'asset');

                return $t;
            },
            'the same code twice' => function (array $t): array {
                $t['accounts'][] = $this->account('1.1', null, 'asset', true, true);

                return $t;
            },
            'one role on two accounts' => function (array $t): array {
                $t['accounts'][] = $this->account('1.9', '1', 'asset', true, true, ControlRole::Bank->value);

                return $t;
            },
            'a role on an account nothing can post to' => function (array $t): array {
                foreach ($t['accounts'] as $i => $row) {
                    if ($row['code'] === '1.1') {
                        $t['accounts'][$i]['role'] = ControlRole::InterEntity->value;
                    }
                }

                return $t;
            },
            'an account that does not say whether it is monetary' => function (array $t): array {
                foreach ($t['accounts'] as $i => $row) {
                    if ($row['code'] === '1.1.1') {
                        unset($t['accounts'][$i]['monetary']);
                    }
                }

                return $t;
            },
            'a required role left unassigned' => function (array $t): array {
                foreach ($t['accounts'] as $i => $row) {
                    if (($row['role'] ?? null) === ControlRole::Bank->value) {
                        $t['accounts'][$i]['role'] = null;
                    }
                }

                return $t;
            },
            'a reserved journal that names no rule' => function (array $t): array {
                $t['journals'][] = [
                    'code' => 'XXX', 'type' => 'other', 'name_ar' => 'س', 'name_en' => 'Other',
                    'reserved' => true, 'posting_rule' => null,
                ];

                return $t;
            },
            'a subtree that changes statement' => function (array $t): array {
                $t['accounts'][] = $this->account('1.9', '1', 'revenue', true, false);

                return $t;
            },
            /*
             | The three ways a statement classification goes wrong - PF-184.
             |
             | Each of them produces a statement that is wrong in a way the
             | totals do not reveal, which is why they are refused at load
             | rather than reported by whoever reads the statement.
             */
            'a postable account that names no statement line' => function (array $t): array {
                $row = $this->account('1.1.4', '1.1', 'asset', true, false);
                unset($row['statement_line']);
                $t['accounts'][] = $row;

                return $t;
            },
            'a statement line that is not an IAS 1 line' => function (array $t): array {
                $t['accounts'][] = $this->account(
                    '1.1.5', '1.1', 'asset', true, false, null, 'sundry_bits_and_pieces',
                );

                return $t;
            },
            'an asset presented on a liability line' => function (array $t): array {
                $t['accounts'][] = $this->account(
                    '1.1.6', '1.1', 'asset', true, true, null, 'trade_and_other_payables',
                );

                return $t;
            },
            'a group account that claims a statement line' => function (array $t): array {
                $t['accounts'][] = $this->account(
                    '1.2', '1', 'asset', false, false, null, 'other_current_assets',
                );

                return $t;
            },
            /*
             | The two ways a nature goes wrong - PF-185.
             |
             | Both produce a by-nature statement that is wrong in a way the
             | by-function one does not reveal, so both are refused at load.
             */
            'an expense account that names no nature' => function (array $t): array {
                $row = $this->account('5.9', '5', 'expense', true, false);
                unset($row['expense_nature']);
                $t['accounts'][] = $row;

                return $t;
            },
            'a nature on something that is not an expense' => function (array $t): array {
                $t['accounts'][] = $this->account(
                    '1.1.7', '1.1', 'asset', true, false, null, 'inventories', 'employee_benefits',
                );

                return $t;
            },
            'no version' => function (array $t): array {
                unset($t['version']);

                return $t;
            },
            'a source with no note' => function (array $t): array {
                unset($t['source']['note']);

                return $t;
            },
        ];

        foreach ($cases as $label => $break) {
            $template = $break($this->minimal('broken-'.Str::random(6)));

            try {
                $this->loadOne($template);

                $violations[] = sprintf(
                    '%s was accepted. The template is data edited by whoever understands the accounting, '.
                    'so the load is the only place this can be caught',
                    $label,
                );
            } catch (InvalidChartTemplate) {
                // Refused, which is the whole point.
            } catch (Throwable $e) {
                $violations[] = sprintf(
                    '%s was refused with %s rather than InvalidChartTemplate, so the message will not say '.
                    'which template or which row: %s',
                    $label,
                    get_class($e),
                    Str::limit($e->getMessage(), 90),
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function pathsNestWithoutNestedCodes(): array
    {
        $template = $this->minimal('flat-'.Str::random(6));

        /*
         | 1000 / 1100 / 1110 - the other common convention.
         |
         | Nothing can be stripped from `1110` to get its segment under
         | `1100`, so the path has to be assembled from the ancestors. A path
         | derived from the code would give three roots and a flat tree.
         */
        $template['accounts'] = [
            $this->group('1000', null, 'asset'),
            $this->group('1100', '1000', 'asset'),
            $this->account('1110', '1100', 'asset', true, true, ControlRole::Bank->value),
            $this->account('1120', '1100', 'asset', true, true, ControlRole::AccountsReceivable->value),
            $this->account('1190', '1100', 'asset', true, false, ControlRole::Suspense->value),
            $this->group('2000', null, 'liability'),
            $this->account('2100', '2000', 'liability', true, true, ControlRole::AccountsPayable->value),
            $this->group('3000', null, 'equity'),
            $this->account('3100', '3000', 'equity', true, false, ControlRole::RetainedEarnings->value),
            $this->group('5000', null, 'expense'),
            $this->account('5100', '5000', 'expense', true, false, ControlRole::RoundingDifference->value),
        ];

        $loaded = $this->loadOne($template);
        $violations = [];

        if ($this->connection === null) {
            // Loading it is still worth asserting even with no database.
            return $violations;
        }

        $entity = $this->blankEntity('FLT');

        $applied = (new ChartTemplate($this->libraryFor($loaded->key)))->apply((string) $entity->getKey(), $loaded->key);

        if ($applied['accounts'] !== 11) {
            $violations[] = sprintf('%d of 11 accounts were created', $applied['accounts']);
        }

        $expected = [
            '1000' => ['1000', 0],
            '1100' => ['1000.1100', 1],
            '1110' => ['1000.1100.1110', 2],
            '2100' => ['2000.2100', 1],
        ];

        foreach ($expected as $code => [$path, $depth]) {
            $account = Account::query()
                ->where('entity_id', $entity->getKey())
                ->where('code', $code)
                ->first(['path', 'depth', 'parent_id']);

            if ($account === null) {
                $violations[] = sprintf('account %s was not created', $code);

                continue;
            }

            if ((string) $account->path !== $path) {
                $violations[] = sprintf(
                    'account %s has the path "%s" where "%s" belongs. A path derived from the code '.
                    'flattens a chart that does not nest its codes, and every grouped report is then '.
                    'wrong with nothing in the data looking unusual',
                    $code,
                    (string) $account->path,
                    $path,
                );
            }

            if ((int) $account->depth !== $depth) {
                $violations[] = sprintf(
                    'account %s is at depth %d rather than %d',
                    $code,
                    (int) $account->depth,
                    $depth,
                );
            }
        }

        return $violations;
    }

    /**
     * Each template, applied the way provisioning applies it.
     *
     * Through `Entity::create` and the listener, not by calling `apply()`
     * directly. The first version of this called the service and measured
     * zero accounts created every time - because the listener had already
     * built the chart, and `apply()` is idempotent. The service was right and
     * the check was testing a path nothing uses.
     *
     * @return list<string>
     */
    public function everyTemplateProducesAPostableChart(): array
    {
        $violations = [];
        $library = ChartTemplateLibrary::shipped();
        $chart = app(ChartTemplate::class);

        foreach ($library->all() as $key => $template) {
            /*
             | The country is how a template gets chosen, so the country is
             | what the fixture varies. `ZZ` is unassigned in ISO 3166-1 and
             | selects the default, which is the generic template's own case.
             */
            $country = $template->countries[0] ?? 'ZZ';
            $entity = $this->entity(mb_substr(mb_strtoupper(str_replace('-', '', $key)), 0, 5), $country);
            $entityId = (string) $entity->getKey();

            $accounts = Account::query()->where('entity_id', $entityId)->count();

            if ($accounts !== count($template->accounts)) {
                $violations[] = sprintf(
                    'an entity in %s was provisioned with %d accounts where "%s" declares %d',
                    $country,
                    $accounts,
                    $key,
                    count($template->accounts),
                );
            }

            $missing = $chart->missingRequiredRoles($entityId);

            if ($missing !== []) {
                $violations[] = sprintf(
                    '"%s" leaves the required role(s) %s unassigned, so the entity cannot post at all - '.
                    'and the way it says so is a posting failure naming one role at a time',
                    $key,
                    implode(',', array_map(static fn (ControlRole $r): string => $r->value, $missing)),
                );
            }

            /*
             | Every assigned role has to resolve to a POSTABLE account.
             |
             | The template check already refuses a role on a group account.
             | This asserts the same thing on the other side of the write,
             | because the thing that breaks a posting is the row in the
             | database rather than the row in the file.
             */
            $unpostable = AccountRole::query()
                ->where('account_roles.entity_id', $entityId)
                ->join('accounts', 'accounts.id', '=', 'account_roles.account_id')
                ->where('accounts.postable', false)
                ->pluck('account_roles.role')
                ->all();

            if ($unpostable !== []) {
                $violations[] = sprintf(
                    '"%s" assigns %s to an account nothing can post to',
                    $key,
                    implode(',', array_map(
                        static fn (mixed $r): string => is_object($r) ? (string) $r->value : (string) $r,
                        $unpostable,
                    )),
                );
            }

            $reserved = Journal::query()
                ->where('entity_id', $entityId)
                ->where('reserved', true)
                ->whereNull('posting_rule')
                ->pluck('code')
                ->all();

            if ($reserved !== []) {
                $violations[] = sprintf(
                    '"%s" created the reserved journal(s) %s with no posting rule, so anything may post '.
                    'into them and "reserved" is a label',
                    $key,
                    implode(',', $reserved),
                );
            }

            /*
             | And the tree is a tree.
             |
             | Every account but a root has a parent id, and a group account
             | has at least one child. A chart that flattened would still
             | have the right number of rows.
             */
            $orphans = Account::query()
                ->where('entity_id', $entityId)
                ->whereNull('parent_id')
                ->where('depth', '>', 0)
                ->pluck('code')
                ->all();

            if ($orphans !== []) {
                $violations[] = sprintf(
                    '"%s" created %s at a depth below the root with no parent',
                    $key,
                    implode(',', $orphans),
                );
            }
        }

        return $violations;
    }

    /**
     * The materialized path invariant, on every chart provisioned above.
     *
     * A child's path is its parent's path plus exactly one segment, and its
     * depth is its parent's depth plus one. That is the whole contract of a
     * materialized path, and it is what every `LIKE 'path.%'` subtree query
     * depends on.
     *
     * ## Why this check exists and the flat-template one was not enough
     *
     * The flat-template check was written to catch a path built from the code
     * rather than from the ancestors - and it does not. Its codes do not
     * nest, so stripping the parent's prefix is a no-op and both the right
     * implementation and the wrong one produce the same answer. Reintroducing
     * the bug left all six checks green.
     *
     * The bug only shows on a chart that DOES nest its codes, where appending
     * the whole code repeats the parent's prefix: `1.1.3` under `1.1` becomes
     * `1.1.1.1.3`. So the invariant is asserted against the real charts, where
     * both conventions are covered - the shipped templates nest, the fixture
     * does not, and this holds for both.
     *
     * @return list<string>
     */
    public function everyPathIsItsParentPlusOneSegment(): array
    {
        $violations = [];

        $rows = $this->connection?->select(<<<'SQL'
            SELECT
                child.entity_id,
                child.code,
                child.path   AS child_path,
                child.depth  AS child_depth,
                parent.code  AS parent_code,
                parent.path  AS parent_path,
                parent.depth AS parent_depth
            FROM accounts child
            JOIN accounts parent ON parent.id = child.parent_id
            ORDER BY child.entity_id, child.code
        SQL) ?? [];

        if ($rows === []) {
            return ['no chart has a parented account at all, so nothing was checked'];
        }

        foreach ($rows as $row) {
            $childPath = (string) $row->child_path;
            $parentPath = (string) $row->parent_path;

            if (! str_starts_with($childPath, $parentPath.'.')) {
                $violations[] = sprintf(
                    'account %s has the path "%s", which does not begin with its parent %s\'s path "%s". '.
                    'Every subtree query is a prefix match, so this account is invisible under its own '.
                    'parent',
                    (string) $row->code,
                    $childPath,
                    (string) $row->parent_code,
                    $parentPath,
                );

                continue;
            }

            $remainder = substr($childPath, strlen($parentPath) + 1);

            if (str_contains($remainder, '.')) {
                $violations[] = sprintf(
                    'account %s hangs %d levels below its parent %s in the path ("%s" under "%s") while '.
                    'being its direct child. A path built by appending the whole code repeats the '.
                    'parent\'s prefix, and that is what this looks like',
                    (string) $row->code,
                    substr_count($remainder, '.') + 1,
                    (string) $row->parent_code,
                    $childPath,
                    $parentPath,
                );
            }

            if ((int) $row->child_depth !== (int) $row->parent_depth + 1) {
                $violations[] = sprintf(
                    'account %s is at depth %d under a parent at depth %d',
                    (string) $row->code,
                    (int) $row->child_depth,
                    (int) $row->parent_depth,
                );
            }
        }

        /*
         | And the shipped templates code hierarchically, so for them the
         | path and the code are the same string. Asserted separately,
         | because it is the specific claim the implementation relies on and
         | the generic invariant above would hold for a chart that silently
         | renumbered everything.
         */
        $mismatched = $this->connection?->select(
            "SELECT code, path FROM accounts WHERE code LIKE '%.%' AND path <> code LIMIT 5",
        ) ?? [];

        foreach ($mismatched as $row) {
            $violations[] = sprintf(
                'account %s has the path "%s". Every shipped template numbers its accounts '.
                'hierarchically, so the two are the same string and a difference means the path was '.
                'assembled wrongly',
                (string) $row->code,
                (string) $row->path,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function countryChoosesTheTemplate(): array
    {
        $library = ChartTemplateLibrary::shipped();
        $violations = [];

        $expected = [
            'SA' => 'saudi-arabia',
            'sa' => 'saudi-arabia',
            'JO' => 'jordan',
            'AE' => ChartTemplateLibrary::DEFAULT,
            'GB' => ChartTemplateLibrary::DEFAULT,
            '' => ChartTemplateLibrary::DEFAULT,
        ];

        foreach ($expected as $country => $key) {
            $chosen = $library->forCountry($country === '' ? null : $country)->key;

            if ($chosen !== $key) {
                $violations[] = sprintf(
                    'an entity in %s is offered "%s" where "%s" belongs',
                    $country === '' ? 'no country' : $country,
                    $chosen,
                    $key,
                );
            }
        }

        /*
         | And the suggestion is a suggestion.
         |
         | A Saudi subsidiary keeping its books on a group's generic chart is
         | an ordinary arrangement, so every template stays selectable. A
         | screen that offered only the national one would be wrong for the
         | group and unarguable for the tenant.
         */
        $choices = $library->choicesFor('SA');

        if (count($choices) !== count($library->all())) {
            $violations[] = sprintf(
                'a Saudi entity is offered %d of %d templates. The national one is suggested, not '.
                'imposed: a subsidiary on a group chart is ordinary',
                count($choices),
                count($library->all()),
            );
        }

        if (($choices[0]['key'] ?? '') !== 'saudi-arabia' || ($choices[0]['suggested'] ?? false) !== true) {
            $violations[] = 'the Saudi template is not the suggested first choice for a Saudi entity';
        }

        return $violations;
    }

    /** @return list<string> */
    public function provenanceIsRecorded(): array
    {
        $violations = [];
        $chart = app(ChartTemplate::class);

        $entity = $this->entity('PRV', 'SA');
        $entityId = (string) $entity->getKey();
        $provenance = $chart->provenanceOf($entityId);

        if (($provenance['template'] ?? null) !== 'saudi-arabia') {
            $violations[] = sprintf(
                'a Saudi entity was provisioned and records %s as its template. "Why does this subsidiary '.
                'have an account the other one does not" is then answered by archaeology',
                var_export($provenance['template'] ?? null, true),
            );
        }

        if (($provenance['version'] ?? 0) < 1) {
            $violations[] = 'no template version was recorded, so two entities provisioned a month apart '.
                'cannot be told from one that somebody edited';
        }

        if (($provenance['applied_at'] ?? '') === '') {
            $violations[] = 'no time was recorded, so the template version cannot be placed against the '.
                'release that shipped it';
        }

        /*
         | And the three columns move together.
         |
         | A template with no version cannot answer the question the version
         | exists for; a version naming no template describes nothing. The
         | CHECK is NOT VALID, which still enforces it on every write from
         | here on - which is what this asserts.
         */
        $this->connection?->statement('SAVEPOINT partial_provenance');

        try {
            $this->connection?->table('entities')->where('id', $entityId)->update([
                'chart_template_version' => null,
            ]);

            $violations[] = 'a chart template was recorded with no version, which is a provenance that '.
                'cannot answer the question it exists for';
        } catch (Throwable) {
            // Refused by the CHECK, which is correct.
        } finally {
            $this->connection?->statement('ROLLBACK TO SAVEPOINT partial_provenance');
        }

        return $violations;
    }

    // ---------------------------------------------------------------------
    //  Fixtures
    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function minimal(string $key): array
    {
        return [
            'key' => $key,
            'version' => 1,
            'name_ar' => 'قالب',
            'name_en' => 'Template',
            'countries' => ['ZZ'],
            'source' => [
                'title' => 'A fixture',
                'note' => 'Built by the check suite. Not shipped, not a statement about any jurisdiction.',
            ],
            'accounts' => [
                $this->group('1', null, 'asset'),
                $this->group('1.1', '1', 'asset'),
                $this->account('1.1.1', '1.1', 'asset', true, true, ControlRole::Bank->value),
                $this->account('1.1.2', '1.1', 'asset', true, true, ControlRole::AccountsReceivable->value),
                $this->account('1.1.3', '1.1', 'asset', true, false, ControlRole::Suspense->value),
                $this->group('2', null, 'liability'),
                $this->account('2.1', '2', 'liability', true, true, ControlRole::AccountsPayable->value),
                $this->group('3', null, 'equity'),
                $this->account('3.1', '3', 'equity', true, false, ControlRole::RetainedEarnings->value),
                $this->group('5', null, 'expense'),
                $this->account('5.1', '5', 'expense', true, false, ControlRole::RoundingDifference->value),
            ],
            'journals' => [
                ['code' => 'GEN', 'type' => 'general', 'name_ar' => 'عام', 'name_en' => 'General',
                    'reserved' => false, 'posting_rule' => null],
                ['code' => 'OPN', 'type' => 'opening', 'name_ar' => 'افتتاحي', 'name_en' => 'Opening',
                    'reserved' => true, 'posting_rule' => 'ledger.opening_balance'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function group(string $code, ?string $parent, string $type): array
    {
        return [
            'code' => $code, 'parent' => $parent, 'type' => $type,
            'name_ar' => 'مجموعة '.$code, 'name_en' => 'Group '.$code,
            'postable' => false, 'role' => null, 'monetary' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function account(
        string $code,
        ?string $parent,
        string $type,
        bool $postable,
        bool $monetary,
        ?string $role = null,
        ?string $statementLine = null,
        ?string $expenseNature = null,
    ): array {
        $row = [
            'code' => $code, 'parent' => $parent, 'type' => $type,
            'name_ar' => 'حساب '.$code, 'name_en' => 'Account '.$code,
            'postable' => $postable, 'role' => $role, 'monetary' => $monetary,
        ];

        /*
         | A postable fixture account gets a plausible line for its type.
         |
         | The loader refuses a postable account with none - PF-184 - and a
         | fixture that had to name one per call would make every unrelated
         | case in this file carry a classification it is not about. The
         | default is the catch-all line for the type, which is a real line
         | and never the interesting one, so a case that cares passes its own.
         */
        if ($postable) {
            $row['statement_line'] = $statementLine ?? match ($type) {
                'asset' => 'other_current_assets',
                'liability' => 'other_current_liabilities',
                'equity' => 'retained_earnings',
                'revenue' => 'other_income',
                default => 'other_expenses',
            };
        } elseif ($statementLine !== null) {
            $row['statement_line'] = $statementLine;
        }

        /*
         | A postable expense fixture gets a nature, for the same reason.
         |
         | The loader refuses one without - IAS 1.104 - and a fixture that
         | named one per call would make every unrelated case in this file
         | carry a classification it is not about.
         */
        if ($postable && $type === 'expense') {
            $row['expense_nature'] = $expenseNature ?? 'other_expenses';
        } elseif ($expenseNature !== null) {
            $row['expense_nature'] = $expenseNature;
        }

        return $row;
    }

    /** @param array<string, mixed> $template */
    private function loadOne(array $template): \App\Tenant\Ledger\Charts\ChartTemplateDefinition
    {
        $directory = $this->temporary.'/'.Str::random(8);
        mkdir($directory, 0o700, true);

        file_put_contents(
            $directory.'/'.(string) ($template['key'] ?? 'unnamed').'.json',
            json_encode($template, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );

        $library = new ChartTemplateLibrary($directory);
        $all = $library->all();

        return $all[(string) $template['key']] ?? throw new \RuntimeException('the fixture did not load');
    }

    private function libraryFor(string $key): ChartTemplateLibrary
    {
        foreach (glob($this->temporary.'/*/'.$key.'.json') ?: [] as $file) {
            return new ChartTemplateLibrary(dirname($file));
        }

        throw new \RuntimeException('no fixture directory holds '.$key);
    }

    private function entity(string $prefix, string $country = 'ZZ'): Entity
    {
        app(EntityContext::class)->forget();

        $entity = Entity::create([
            'code' => $prefix.random_int(100, 999),
            'legal_name' => $prefix.' chart check',
            'country' => $country,
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        app(EntityContext::class)->use($entity);

        return $entity;
    }

    /**
     * An entity whose auto-provisioned chart has been cleared away.
     *
     * Creating an entity opens its ledger, which is the product behaving
     * correctly and is in the way of applying a FIXTURE template - one that
     * belongs to no country and so can never be selected. The rows are
     * removed here rather than the listener being suppressed, because
     * suppressing it would also skip the fiscal calendar and leave an entity
     * that cannot be posted to for an unrelated reason.
     *
     * The whole suite runs inside a transaction that is rolled back.
     */
    private function blankEntity(string $prefix): Entity
    {
        $entity = $this->entity($prefix);
        $entityId = (string) $entity->getKey();

        AccountRole::query()->where('entity_id', $entityId)->delete();
        Journal::query()->where('entity_id', $entityId)->delete();

        /*
         | Children before parents.
         |
         | `accounts.parent_id` is a foreign key to the same table, so a
         | blanket delete fails on whichever row the planner reaches first.
         | Deepest first is the one order that always works.
         */
        $codes = Account::query()
            ->where('entity_id', $entityId)
            ->orderByDesc('depth')
            ->pluck('id')
            ->all();

        foreach ($codes as $id) {
            Account::query()->where('id', $id)->delete();
        }

        $this->connection?->table('entities')->where('id', $entityId)->update([
            'chart_template' => null,
            'chart_template_version' => null,
            'chart_applied_at' => null,
        ]);

        return $entity;
    }

    private function cleanUp(): void
    {
        foreach (glob($this->temporary.'/*/*.json') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->temporary.'/*') ?: [] as $directory) {
            @rmdir($directory);
        }

        @rmdir($this->temporary);
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
