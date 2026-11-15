<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Charts;

use App\Tenant\Ledger\Charts\Exceptions\InvalidChartTemplate;
use App\Tenant\Ledger\Enums\ControlRole;

/**
 * A chart template, loaded from its data file and checked - PF-039.
 *
 * ## Why a template is data rather than code
 *
 * Three reasons, and the third is the one that decided it.
 *
 * A chart is argued about. Which expense accounts a Jordanian company needs
 * is a question for an accountant, and an accountant can read and correct a
 * data file. The second: a template has a version and a source, and a diff of
 * a data file is a readable history of a statutory structure changing. The
 * third: a tenant will eventually want to supply their own, and a template
 * that is already a document needs no second format invented for them.
 *
 * ## Every template is checked at load, not at use
 *
 * A parent that does not exist, a role assigned to two accounts, a role on a
 * group account nothing can post to: each produces a chart that is wrong in a
 * way the tenant meets weeks later, on a posting, with nothing pointing back
 * at the template. So the load refuses, and a check suite loads every shipped
 * template on every run.
 *
 * ## The source is not decoration
 *
 * "The accountant who set up the first customer" is not a source. A chart
 * nobody can trace is one nobody can defend when an auditor asks why revenue
 * is split the way it is - and for the jurisdiction templates the honest
 * source says what IS and IS NOT mandated, because overstating that is worse
 * than having no template.
 */
final readonly class ChartTemplateDefinition
{
    /**
     * @param  list<ChartAccount>  $accounts
     * @param  list<ChartJournal>  $journals
     * @param  list<string>  $countries  ISO 3166-1 alpha-2, the countries this template is offered for
     */
    private function __construct(
        public string $key,
        public int $version,
        public string $nameAr,
        public string $nameEn,
        public array $countries,
        public string $sourceTitle,
        public string $sourceNote,
        public ?string $sourceUrl,
        public array $accounts,
        public array $journals,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $key = (string) ($data['key'] ?? '');

        if ($key === '') {
            throw InvalidChartTemplate::because('(unnamed)', 'it has no key');
        }

        foreach (['name_ar', 'name_en'] as $required) {
            if (($data[$required] ?? '') === '') {
                throw InvalidChartTemplate::because($key, 'it has no '.$required);
            }
        }

        $version = (int) ($data['version'] ?? 0);

        if ($version < 1) {
            throw InvalidChartTemplate::because(
                $key,
                'it has no version. A chart that changed and did not say so is a chart two entities '.
                'provisioned a month apart disagree about, with nothing to show which is which',
            );
        }

        $source = (array) ($data['source'] ?? []);

        foreach (['title', 'note'] as $required) {
            if (($source[$required] ?? '') === '') {
                throw InvalidChartTemplate::because(
                    $key,
                    'its source has no '.$required.'. A chart nobody can trace is one nobody can defend',
                );
            }
        }

        $accounts = array_map(
            static fn (array $row): ChartAccount => ChartAccount::fromArray($key, $row),
            array_values((array) ($data['accounts'] ?? [])),
        );

        $journals = array_map(
            static fn (array $row): ChartJournal => ChartJournal::fromArray($key, $row),
            array_values((array) ($data['journals'] ?? [])),
        );

        $template = new self(
            key: $key,
            version: $version,
            nameAr: (string) $data['name_ar'],
            nameEn: (string) $data['name_en'],
            countries: array_map(
                static fn (mixed $c): string => mb_strtoupper((string) $c),
                array_values((array) ($data['countries'] ?? [])),
            ),
            sourceTitle: (string) $source['title'],
            sourceNote: (string) $source['note'],
            sourceUrl: ($source['url'] ?? null) === null ? null : (string) $source['url'],
            accounts: $accounts,
            journals: $journals,
        );

        $template->assertCoherent();

        return $template;
    }

    /**
     * Everything that can be wrong about a template as a whole.
     *
     * Per-row problems are caught by the row's own constructor; these are the
     * ones only visible with the whole chart in hand.
     */
    private function assertCoherent(): void
    {
        if ($this->accounts === []) {
            throw InvalidChartTemplate::because($this->key, 'it has no accounts');
        }

        if ($this->journals === []) {
            throw InvalidChartTemplate::because($this->key, 'it has no journals');
        }

        $codes = [];

        foreach ($this->accounts as $account) {
            if (isset($codes[$account->code])) {
                throw InvalidChartTemplate::because(
                    $this->key,
                    sprintf('the account code %s appears twice', $account->code),
                );
            }

            $codes[$account->code] = $account;
        }

        /*
         | A parent must exist AND come first.
         |
         | The ordering matters because `apply()` walks the list once,
         | resolving each parent from what it has already created. A forward
         | reference would be a null parent and a silently flattened tree -
         | which looks fine in a list and wrong in every report that groups.
         */
        $seen = [];

        foreach ($this->accounts as $account) {
            if ($account->parent === null) {
                $seen[$account->code] = true;

                continue;
            }

            if (! isset($codes[$account->parent])) {
                throw InvalidChartTemplate::because(
                    $this->key,
                    sprintf('account %s names the parent %s, which is not in the template',
                        $account->code, $account->parent),
                );
            }

            if (! isset($seen[$account->parent])) {
                throw InvalidChartTemplate::because(
                    $this->key,
                    sprintf(
                        'account %s comes before its parent %s. The chart is built in one pass, so a '.
                        'forward reference becomes a null parent and a flattened tree',
                        $account->code,
                        $account->parent,
                    ),
                );
            }

            $parent = $codes[$account->parent];

            if ($parent->postable) {
                throw InvalidChartTemplate::because(
                    $this->key,
                    sprintf(
                        'account %s is postable and has the child %s. A balance on a parent and a balance '.
                        'in its children are two different totals, and every report has to choose one',
                        $parent->code,
                        $account->code,
                    ),
                );
            }

            if ($parent->type !== $account->type) {
                throw InvalidChartTemplate::because(
                    $this->key,
                    sprintf(
                        'account %s is %s under a %s parent. A subtree that changes type puts a balance '.
                        'on the wrong statement',
                        $account->code,
                        $account->type->value,
                        $parent->type->value,
                    ),
                );
            }

            /*
             | The segment may not contain the separator or the sentinel.
             |
             | A subtree is read as the half-open range from `path || '.'` to
             | `path || '/'`, so a segment containing `.` would make an
             | account look like a grandchild of its own parent and bring
             | siblings into the wrong subtree, and a `/` anywhere would make
             | the upper bound ambiguous. Both silently, in a range query
             | that returns a plausible number of rows.
             */
            $segment = str_starts_with($account->code, $account->parent.'.')
                ? substr($account->code, strlen($account->parent) + 1)
                : $account->code;

            if (str_contains($segment, '.')) {
                throw InvalidChartTemplate::because(
                    $this->key,
                    sprintf(
                        'account %s contributes the path segment "%s", which contains the separator. '.
                        'Either code it under its parent (%s.something) or give it a code with no dot - '.
                        'a segment with a dot in it reads as another level and pulls the wrong accounts '.
                        'into a subtree',
                        $account->code,
                        $segment,
                        $account->parent,
                    ),
                );
            }

            $seen[$account->code] = true;
        }

        foreach ($this->accounts as $account) {
            if (str_contains($account->code, '/')) {
                throw InvalidChartTemplate::because(
                    $this->key,
                    sprintf(
                        'account %s has a code containing "/", which is the sentinel bounding a subtree '.
                        'range. Every subtree query would be wrong by an unpredictable amount',
                        $account->code,
                    ),
                );
            }
        }

        $roles = [];

        foreach ($this->accounts as $account) {
            if ($account->role === null) {
                continue;
            }

            if (isset($roles[$account->role->value])) {
                throw InvalidChartTemplate::because(
                    $this->key,
                    sprintf(
                        'the role "%s" is on both %s and %s. A role resolves to one account, so which '.
                        'one it resolves to would be whichever was written last',
                        $account->role->value,
                        $roles[$account->role->value],
                        $account->code,
                    ),
                );
            }

            $roles[$account->role->value] = $account->code;
        }

        $missing = array_values(array_filter(
            ControlRole::cases(),
            static fn (ControlRole $role): bool => $role->isRequiredToPost()
                && ! isset($roles[$role->value]),
        ));

        if ($missing !== []) {
            throw InvalidChartTemplate::because(
                $this->key,
                sprintf(
                    'it assigns no account to the required role(s) %s. An entity provisioned from it '.
                    'cannot post at all, and the first thing it says so is a posting failure naming one '.
                    'role at a time',
                    implode(', ', array_map(static fn (ControlRole $r): string => $r->value, $missing)),
                ),
            );
        }

        $journalCodes = [];

        foreach ($this->journals as $journal) {
            if (isset($journalCodes[$journal->code])) {
                throw InvalidChartTemplate::because(
                    $this->key,
                    sprintf('the journal code %s appears twice', $journal->code),
                );
            }

            $journalCodes[$journal->code] = true;
        }
    }

    /** @return array<string, ChartAccount> keyed by code */
    public function byCode(): array
    {
        $byCode = [];

        foreach ($this->accounts as $account) {
            $byCode[$account->code] = $account;
        }

        return $byCode;
    }

    /** How a template describes itself on the provisioning screen. */
    public function label(string $locale): string
    {
        return $locale === 'ar' ? $this->nameAr : $this->nameEn;
    }

    public function servesCountry(string $country): bool
    {
        return in_array(mb_strtoupper($country), $this->countries, true);
    }
}
