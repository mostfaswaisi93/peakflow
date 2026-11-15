<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Exceptions\TaxCodeRefused;
use App\Tenant\Tax\Models\TaxCode;
use App\Tenant\Tax\Models\TaxRate;
use App\Tenant\Tax\Services\TaxCodes;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tax categories and effective-dated rates - PF-075, PF-076.
 *
 * ## The scenario these are built around
 *
 * Saudi Arabia went from 5% to 15% on 1 July 2020. A system holding one rate
 * per code reprints every pre-July invoice at 15%, and reprinting an invoice is
 * what you do when a customer disputes it or an authority asks for it. So the
 * rate change is the check: introduce 5%, supersede to 15%, and prove that
 * documents either side of the boundary resolve differently.
 *
 * ## What is asserted of the DATABASE rather than the code
 *
 * PF-076 asks that overlapping validity be refused by an exclusion constraint
 * and not by application validation alone. So the overlap is attempted through
 * the model, bypassing the service entirely - because the service's own
 * refusal would pass this check while the constraint was missing, and the
 * constraint is what holds when somebody writes an UPDATE.
 *
 * The same reasoning covers the zero-rate invariant and the mandatory
 * exemption reason: both are CHECK constraints, and both are attempted
 * directly.
 */
final class TaxCodeRules
{
    private ?Connection $connection = null;

    private string $entityId = '';

    private string $otherEntityId = '';

    /**
     * The UNCL 5305 behaviour matrix, written out.
     *
     * From the code list, not from the enum. A check that derived this from
     * the thing it is checking would assert the enum equals itself - and the
     * whole point of these columns is that `Z`, `E` and `O` are three
     * different things.
     *
     * code => [taxable supply, restricts input recovery, needs a reason,
     *          is reverse charge, needs evidence, must be zero-rated]
     *
     * @var array<string, array{bool, bool, bool, bool, bool, bool}>
     */
    private const MATRIX = [
        'S' => [true, false, false, false, false, false],
        'Z' => [true, false, true, false, false, true],
        'E' => [false, true, true, false, false, true],
        'AE' => [true, false, true, true, false, true],
        'O' => [false, true, true, false, false, true],
        'K' => [true, false, true, false, true, true],
        'G' => [true, false, true, false, true, true],
        'L' => [true, false, true, false, false, false],
        'M' => [true, false, true, false, false, false],
    ];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['tax codes (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return $this->runEach([
                'the nine UNCL 5305 categories behave as the code list says' => fn (): array => $this->theCategoryMatrixHolds(),
                'zero-rated, exempt and out of scope are three different things' => fn (): array => $this->theThreeAreDistinct(),
                'a rate change is a new version, and history keeps its rate' => fn (): array => $this->aRateChangeIsANewVersion(),
                'overlapping validity is refused by the database' => fn (): array => $this->overlapsAreRefusedByTheDatabase(),
                'a zero category cannot carry a rate' => fn (): array => $this->zeroCategoriesCarryZero(),
                'a non-standard category cannot omit its reason' => fn (): array => $this->reasonsAreMandatory(),
                'a rate with no documented source is refused' => fn (): array => $this->sourcesAreMandatory(),
                'superseding refuses what would rewrite history' => fn (): array => $this->supersedingRefusesTheWrongThings(),
                'another entity cannot see these codes' => fn (): array => $this->rowLevelSecurityApplies(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'TXC'.random_int(100, 999),
            'legal_name' => 'Tax code check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $other = Entity::create([
            'code' => 'TXO'.random_int(100, 999),
            'legal_name' => 'Tax code check, other entity',
            'country' => 'JO',
            'functional_currency' => 'JOD',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $this->otherEntityId = (string) $other->getKey();

        $context->use($entity);
    }

    /**
     * @param  array<string, callable(): list<string>>  $checks
     * @return array<string, list<string>>
     */
    private function runEach(array $checks): array
    {
        $results = [];

        foreach ($checks as $name => $check) {
            try {
                $this->connection->statement('SAVEPOINT tax_boundary');
                $results[$name] = $check();
                $this->connection->statement('RELEASE SAVEPOINT tax_boundary');
            } catch (Throwable $e) {
                $this->connection->statement('ROLLBACK TO SAVEPOINT tax_boundary');

                $results[$name] = [sprintf(
                    'the check could not run: %s',
                    Str::limit((string) preg_replace('/\s+/', ' ', $e->getMessage()), 220),
                )];
            }
        }

        return $results;
    }

    /** @return list<string> */
    private function theCategoryMatrixHolds(): array
    {
        $problems = [];

        foreach (self::MATRIX as $code => [$taxable, $restricts, $reason, $reverse, $evidence, $zero]) {
            $category = TaxCategory::tryFrom($code);

            if ($category === null) {
                $problems[] = sprintf(
                    'UNCL 5305 category %s is not in the enum. EN 16931 validates BT-118 against the '.
                    'list, so a missing category is an invoice a receiving system rejects.',
                    $code,
                );

                continue;
            }

            $actual = [
                $category->isTaxableSupply(),
                $category->restrictsInputRecovery(),
                $category->requiresExemptionReason(),
                $category->isReverseCharge(),
                $category->requiresEvidence(),
                $category->requiresZeroRate(),
            ];

            $expected = [$taxable, $restricts, $reason, $reverse, $evidence, $zero];
            $names = ['taxable supply', 'restricts input recovery', 'requires a reason', 'is reverse charge', 'requires evidence', 'must be zero-rated'];

            foreach ($expected as $i => $want) {
                if ($actual[$i] === $want) {
                    continue;
                }

                $problems[] = sprintf(
                    'Category %s: "%s" is %s and the code list says %s.',
                    $code,
                    $names[$i],
                    $actual[$i] ? 'true' : 'false',
                    $want ? 'true' : 'false',
                );
            }

            if ($category->label('ar') === $category->label('en')) {
                $problems[] = sprintf('Category %s has no Arabic label distinct from its English one.', $code);
            }
        }

        if (\count(TaxCategory::cases()) !== \count(self::MATRIX)) {
            $problems[] = sprintf(
                'The enum has %d categories and this check knows %d. A category added without a row '.
                'here is a category whose behaviour nothing asserts.',
                \count(TaxCategory::cases()),
                \count(self::MATRIX),
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function theThreeAreDistinct(): array
    {
        $problems = [];

        $zero = TaxCategory::ZeroRated;
        $exempt = TaxCategory::Exempt;
        $outOfScope = TaxCategory::OutOfScope;

        /*
         | The distinction that matters most, and the one "0% with a note"
         | destroys: a zero-rated supply counts towards TAXABLE TURNOVER and an
         | exempt one does not. Registration thresholds are computed from
         | taxable turnover, so collapsing them can make a business appear not
         | to need a registration it does need.
         */
        if ($zero->isTaxableSupply() === $exempt->isTaxableSupply()) {
            $problems[] = 'Zero-rated and exempt agree about whether they are a taxable supply. They do '.
                'not: zero-rated counts towards taxable turnover at 0% and exempt does not count at all, '.
                'and registration thresholds are computed from taxable turnover.';
        }

        if ($zero->restrictsInputRecovery() === $exempt->restrictsInputRecovery()) {
            $problems[] = 'Zero-rated and exempt agree about input recovery. Zero-rated leaves it '.
                'intact and exempt restricts it, which is the difference that makes the input tax box '.
                'wrong - the half nobody checks until an audit.';
        }

        if ($exempt->isTaxableSupply() !== $outOfScope->isTaxableSupply()
            && $exempt->restrictsInputRecovery() !== $outOfScope->restrictsInputRecovery()) {
            // They agree on both by design; this branch would mean one moved.
            $problems[] = 'Exempt and out of scope now disagree on both flags, which is not the '.
                'modelled distinction - they differ in whether the supply appears on the return at all, '.
                'not in these two.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function aRateChangeIsANewVersion(): array
    {
        $problems = [];
        $codes = app(TaxCodes::class);
        $code = 'CHK-VAT-'.Str::upper(Str::random(4));

        $taxCode = $codes->introduce(
            $this->entityId, $code, TaxCategory::Standard, 'SA', '5.000000',
            CarbonImmutable::parse('2018-01-01'),
            'ضريبة فحص', 'Check tax',
            'The check suite, standing in for a published rate.',
            ['chk' => ['output' => '1a', 'input' => '7']],
        );

        $first = $taxCode->rates()->first();

        $second = $codes->supersede(
            $code, '15.000000', CarbonImmutable::parse('2020-07-01'),
            'The check suite, standing in for a rate change order.',
        );

        /*
         | The old version's RATE is untouched. That is what "a rate change
         | never edits the old version" means precisely: the validity window
         | changes, because an open-ended row has to stop being open-ended for
         | a successor to exist, and the rate does not.
         */
        $first->refresh();

        if ($first->rate !== '5.000000') {
            $problems[] = sprintf(
                'The superseded version\'s rate is now %s. A rate change creates a version and never '.
                'edits the old one - a reprinted historical invoice resolves this row.',
                $first->rate,
            );
        }

        /*
         | The ranges ABUT: the successor's start is the predecessor's end,
         | because the bounds are half-open. So the assertion is on the
         | exclusive end being 2020-07-01, and the last day it applies -
         | 2020-06-30 - is derived for display rather than stored.
         */
        if ($first->endsBefore()?->toDateString() !== '2020-07-01') {
            $problems[] = sprintf(
                'The superseded version ends before %s and should end before 2020-07-01, the day the '.
                'new one starts. A gap is a date on which no rate was in force; an overlap is a '.
                'document with two possible rates.',
                $first->endsBefore()?->toDateString() ?? 'nothing',
            );
        }

        if ($first->lastDay()?->toDateString() !== '2020-06-30') {
            $problems[] = sprintf(
                'The superseded version\'s last day reads as %s rather than 2020-06-30, which is what '.
                'a human sees on a rate history.',
                $first->lastDay()?->toDateString() ?? 'nothing',
            );
        }

        if (! $second->isOpenEnded()) {
            $problems[] = 'The new version is not open-ended.';
        }

        // The point of the whole exercise.
        $expected = [
            '2018-06-30' => '5.000000000000',
            '2020-06-30' => '5.000000000000',
            '2020-07-01' => '15.000000000000',
            '2026-11-15' => '15.000000000000',
        ];

        foreach ($expected as $date => $rate) {
            $resolved = (string) $codes->rateOn($code, CarbonImmutable::parse($date));

            if ($resolved !== $rate) {
                $problems[] = sprintf(
                    'A tax point of %s resolves %s%% and should resolve %s%%. A document resolves its '.
                    'rate from its TAX POINT, not from today, which is the only reason a historical '.
                    'invoice reprints correctly.',
                    $date,
                    $resolved,
                    $rate,
                );
            }
        }

        // And the fraction, because multiplying a net amount by 15 is the
        // mistake this method exists to prevent.
        [, $version] = $codes->resolve($code, CarbonImmutable::parse('2026-11-15'));
        $fraction = (string) $version->fraction();

        if ($fraction !== '0.150000000000') {
            $problems[] = sprintf(
                'A 15%% rate yields the fraction %s rather than 0.150000000000. The column holds a '.
                'percentage because that is how authorities publish it, so the division has to happen '.
                'somewhere - and "somewhere" has to be one place.',
                $fraction,
            );
        }

        // A date before the code existed is refused, not guessed.
        try {
            $codes->rateOn($code, CarbonImmutable::parse('2017-12-31'));

            $problems[] = 'A tax point before the code\'s first version resolved a rate. Guessing the '.
                'nearest version would silently apply a rate that was not law on the day of the supply.';
        } catch (TaxCodeRefused) {
            // Refused, as it must be.
        }

        return $problems;
    }

    /**
     * The overlap, attempted through the MODEL rather than the service.
     *
     * PF-076 asks for a database exclusion constraint and not application
     * validation alone. The service's own refusal would pass this check while
     * the constraint was missing, and the constraint is what holds when
     * somebody writes an UPDATE.
     *
     * @return list<string>
     */
    private function overlapsAreRefusedByTheDatabase(): array
    {
        $problems = [];
        $code = 'CHK-OVL-'.Str::upper(Str::random(4));

        app(TaxCodes::class)->introduce(
            $this->entityId, $code, TaxCategory::Standard, 'SA', '5.000000',
            CarbonImmutable::parse('2020-01-01'),
            'فحص', 'Check', 'The check suite.',
        );

        $taxCode = TaxCode::query()->where('code', $code)->firstOrFail();

        $overlaps = [
            'a second open-ended version' => TaxRate::range(CarbonImmutable::parse('2021-01-01')),
            'a version starting inside the open one' => TaxRate::range(CarbonImmutable::parse('2020-06-01'), CarbonImmutable::parse('2021-01-01')),
            'a version covering the same day' => TaxRate::range(CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2020-01-02')),
            'a version enclosing the open one' => TaxRate::range(CarbonImmutable::parse('2019-01-01')),
        ];

        foreach ($overlaps as $description => $validity) {
            $refused = $this->refusalFor(fn (): mixed => TaxRate::query()->create([
                'entity_id' => $this->entityId,
                'tax_code_id' => $taxCode->getKey(),
                'rate' => '10.000000',
                'source' => 'The check suite.',
                'validity' => $validity,
            ]));

            if ($refused === null) {
                $problems[] = sprintf(
                    '%s was accepted. Two rows covering the same day means a document has two possible '.
                    'rates and which one it gets depends on row order - so the same invoice computed '.
                    'twice can produce two different tax amounts.',
                    $description,
                );

                continue;
            }

            if (! str_contains($refused, 'tax_rates_no_overlap')) {
                $problems[] = sprintf(
                    '%s was refused by something other than the exclusion constraint: %s',
                    $description,
                    Str::limit($refused, 120),
                );
            }
        }

        /*
         | And a NON-overlapping version must still be accepted.
         |
         | A constraint that refused everything would pass every case above
         | and make the table unusable, which is the failure mode of a rule
         | tested only in one direction.
         */
        $accepted = $this->refusalFor(fn (): mixed => app(TaxCodes::class)->introduce(
            $this->entityId, $code.'-OTHER', TaxCategory::Standard, 'SA', '10.000000',
            CarbonImmutable::parse('2020-01-01'), 'فحص', 'Check', 'The check suite.',
        ));

        if ($accepted !== null) {
            $problems[] = sprintf(
                'A different code with the same dates was refused: %s. The constraint is per code, or '.
                'one jurisdiction could hold only one rate at a time.',
                Str::limit($accepted, 120),
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function zeroCategoriesCarryZero(): array
    {
        $problems = [];

        foreach (TaxCategory::cases() as $category) {
            if (! $category->requiresZeroRate()) {
                continue;
            }

            $refused = $this->refusalFor(fn (): mixed => app(TaxCodes::class)->introduce(
                $this->entityId,
                'CHK-'.$category->value.'-'.Str::upper(Str::random(4)),
                $category,
                'SA',
                '5.000000',
                CarbonImmutable::parse('2020-01-01'),
                'فحص',
                'Check',
                'The check suite.',
                [],
                'VATEX-SA-OOS',
                'سبب',
                'Reason',
            ));

            if ($refused === null) {
                $problems[] = sprintf(
                    'A %s code was accepted at 5%%. A `Z` code at 5%% is not a zero rate and an `E` '.
                    'code at 5%% is a taxable supply wearing an exempt label - both produce a return '.
                    'whose output tax looks plausible and whose turnover boxes are wrong.',
                    $category->value,
                );
            }
        }

        // And a standard-rate code at 5% must be accepted.
        $accepted = $this->refusalFor(fn (): mixed => app(TaxCodes::class)->introduce(
            $this->entityId, 'CHK-S-'.Str::upper(Str::random(4)), TaxCategory::Standard, 'SA',
            '5.000000', CarbonImmutable::parse('2020-01-01'), 'فحص', 'Check', 'The check suite.',
        ));

        if ($accepted !== null) {
            $problems[] = sprintf('A standard-rate code at 5%% was refused: %s', Str::limit($accepted, 120));
        }

        return $problems;
    }

    /** @return list<string> */
    private function reasonsAreMandatory(): array
    {
        $problems = [];

        $refused = $this->refusalFor(fn (): mixed => app(TaxCodes::class)->introduce(
            $this->entityId, 'CHK-NOREASON-'.Str::upper(Str::random(4)), TaxCategory::Exempt, 'SA',
            '0.000000', CarbonImmutable::parse('2020-01-01'), 'فحص', 'Check', 'The check suite.',
        ));

        if ($refused === null) {
            $problems[] = 'An exempt code with no exemption reason was accepted. EN 16931 BT-120/BT-121 '.
                'require one, and an invoice that says 0% with no reason is an invoice that cannot be '.
                'defended - so the code carries the default and a document cannot omit it.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function sourcesAreMandatory(): array
    {
        $problems = [];

        foreach (['an empty source' => '', 'a source of spaces' => '   '] as $description => $source) {
            $refused = $this->refusalFor(fn (): mixed => app(TaxCodes::class)->introduce(
                $this->entityId, 'CHK-NOSRC-'.Str::upper(Str::random(4)), TaxCategory::Standard, 'SA',
                '15.000000', CarbonImmutable::parse('2020-01-01'), 'فحص', 'Check', $source,
            ));

            if ($refused === null) {
                $problems[] = sprintf(
                    '%s was accepted. The question asked of a tax code six months later is "where did '.
                    '15%% come from", and the answer has to be in the row - a rate with no citation is '.
                    'a rate nobody will dare change and nobody can defend.',
                    $description,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function supersedingRefusesTheWrongThings(): array
    {
        $problems = [];
        $codes = app(TaxCodes::class);
        $code = 'CHK-SUP-'.Str::upper(Str::random(4));

        $codes->introduce(
            $this->entityId, $code, TaxCategory::Standard, 'SA', '5.000000',
            CarbonImmutable::parse('2020-01-01'),
            'فحص', 'Check', 'The check suite.',
        );

        $cases = [
            'a new version starting on the same day as the current one' => fn (): mixed => $codes->supersede(
                $code, '15.000000', CarbonImmutable::parse('2020-01-01'), 'The check suite.',
            ),
            'a new version starting before the current one' => fn (): mixed => $codes->supersede(
                $code, '15.000000', CarbonImmutable::parse('2019-06-01'), 'The check suite.',
            ),
            'superseding a code that does not exist' => fn (): mixed => $codes->supersede(
                'CHK-NOPE', '15.000000', CarbonImmutable::parse('2021-01-01'), 'The check suite.',
            ),
            /*
             | There is no "changes the category" case any more, and its
             | absence is the point: the category lives on the code and not on
             | the rate version, so `supersede()` has no parameter that could
             | change it. The first version of this engine versioned them
             | together and needed an application refusal; structure that
             | cannot express the mistake beats a refusal that has to remember
             | to fire.
             */
        ];

        foreach ($cases as $description => $attempt) {
            if ($this->refusalFor($attempt) === null) {
                $problems[] = sprintf('%s was accepted.', $description);
            }
        }

        /*
         | And superseding twice: the second change must close the first
         | successor, not the original.
         */
        $codes->supersede($code, '15.000000', CarbonImmutable::parse('2020-07-01'), 'The check suite.');
        $codes->supersede($code, '16.000000', CarbonImmutable::parse('2024-01-01'), 'The check suite.');

        $versions = $codes->versionsOf($code);

        if ($versions->count() !== 3) {
            $problems[] = sprintf('Two rate changes produced %d version(s) rather than 3.', $versions->count());
        }

        $windows = $versions->map(fn (TaxRate $v): string => sprintf(
            '%s..%s@%s',
            $v->startsOn()->toDateString(),
            $v->lastDay()?->toDateString() ?? 'open',
            rtrim(rtrim($v->rate, '0'), '.'),
        ))->all();

        $expected = ['2020-01-01..2020-06-30@5', '2020-07-01..2023-12-31@15', '2024-01-01..open@16'];

        if ($windows !== $expected) {
            $problems[] = sprintf(
                "Two rate changes produced the windows:\n       %s\n       and should produce:\n       %s",
                implode(', ', $windows),
                implode(', ', $expected),
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function rowLevelSecurityApplies(): array
    {
        $problems = [];
        $context = app(EntityContext::class);
        $code = 'CHK-RLS-'.Str::upper(Str::random(4));

        app(TaxCodes::class)->introduce(
            $this->entityId, $code, TaxCategory::Standard, 'SA', '15.000000',
            CarbonImmutable::parse('2020-01-01'),
            'فحص', 'Check', 'The check suite.',
        );

        /*
         | `Entity::query()`, not `withoutEntityScope()`.
         |
         | Entity does not use the BelongsToEntity trait, and correctly so: an
         | entity IS the scope, it is not scoped by one. The first version of
         | this check called a trait method the model does not have.
         */
        $otherEntity = Entity::query()->findOrFail($this->otherEntityId);

        /*
         | The APPLICATION scope bypassed, deliberately.
         |
         | `TaxCode::query()` is already filtered by the BelongsToEntity
         | global scope, so it returns nothing whether or not the database has
         | a policy - which is how the first version of this check passed with
         | row-level security removed from the migration entirely.
         |
         | The whole point of PF-026 is that the application scope is not the
         | only line of defence: a developer who forgets a scope, a console
         | command, a raw query. So the scope is bypassed and the DATABASE is
         | asked.
         */
        $visibleToTheApplication = $context->during(
            $otherEntity,
            static fn (): int => TaxCode::query()->where('code', $code)->count(),
        );

        if ($visibleToTheApplication !== 0) {
            $problems[] = sprintf(
                'A second entity can see %d of the first entity\'s tax codes through the ordinary query '.
                'path, so the application scope is not applied at all.',
                $visibleToTheApplication,
            );
        }

        $visibleToTheDatabase = $context->during(
            $otherEntity,
            static fn (): int => TaxCode::withoutEntityScope()->where('code', $code)->count(),
        );

        if ($visibleToTheDatabase !== 0) {
            $problems[] = sprintf(
                'With the application scope bypassed, a second entity can see %d of the first entity\'s '.
                'tax codes - so row-level security is not protecting the table. A group with two '.
                'companies in different jurisdictions would compute one\'s tax at the other\'s rate, and '.
                'the figures would look plausible.',
                $visibleToTheDatabase,
            );
        }

        // And the owning entity must still see it.
        $own = TaxCode::query()->where('code', $code)->count();

        if ($own !== 1) {
            $problems[] = sprintf(
                'The owning entity sees %d of its own tax codes rather than 1, so the policy refuses '.
                'everything rather than refusing the wrong things.',
                $own,
            );
        }

        return $problems;
    }

    /**
     * The refusal message for an attempt, or null if it was accepted.
     *
     * @param  callable(): mixed  $attempt
     */
    private function refusalFor(callable $attempt): ?string
    {
        $this->connection->statement('SAVEPOINT attempt');

        try {
            $attempt();
            $this->connection->statement('RELEASE SAVEPOINT attempt');

            return null;
        } catch (Throwable $e) {
            $this->connection->statement('ROLLBACK TO SAVEPOINT attempt');

            return (string) preg_replace('/\s+/', ' ', $e->getMessage());
        }
    }
}
