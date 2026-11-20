<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Hashing\Canonicalizer;
use App\Tenant\Identity\Models\Permission;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Tax\Enums\FilingFrequency;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Exceptions\FilingRefused;
use App\Tenant\Tax\Models\ReturnFiling;
use App\Tenant\Tax\Models\ReturnPeriod;
use App\Tenant\Tax\Models\TaxCode;
use App\Tenant\Tax\PreFilingFinding;
use App\Tenant\Tax\Services\PreFilingChecklist;
use App\Tenant\Tax\Services\ReturnFiler;
use App\Tenant\Tax\Services\ReturnGenerator;
use App\Tenant\Tax\Services\ReturnPeriods;
use App\Tenant\Tax\TaxReturn;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Models\TaxRegistration;
use App\Tenant\Tenancy\Services\EntityContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Return generation, the pre-filing checklist, and filing - PF-086.
 *
 * ## The scenario these are built around
 *
 * A Saudi entity with one invoice in May: 1,000 net, 150 of VAT, delivered on
 * the 18th and booked on the 20th. The return for May has to show 1,000 in the
 * standard sales box with 150 of tax, and the two journal lines behind it -
 * and it has to keep showing exactly that after the period is adjusted,
 * reversed and re-reported, because a filed return is a statement the business
 * made rather than a query over data that moved on.
 *
 * ## The failures worth the most
 *
 * A **draft entry included**: tax reported on a document nobody committed, and
 * the figure changes when somebody edits the draft after the review.
 *
 * A **box with tax and no taxable amount**: the authority's own arithmetic is
 * amount times rate, so a figure with no base is one nobody can verify.
 *
 * A **filing that can be edited**: then there is no evidence of what was
 * submitted, and the business cannot answer a query about its own return.
 */
final class ReturnFilingRules
{
    use \Tests\Support\Concerns\ProvesTheLedger;

    private ?Connection $connection = null;

    private string $entityId = '';

    private string $otherEntityId = '';

    private ?Entity $entity = null;

    private ?TaxCode $code = null;

    private ?ReturnPeriod $may = null;

    private string $receivableId = '';

    private string $outputId = '';

    private string $revenueId = '';

    private string $suspenseId = '';

    private string $journalId = '';

    private string $periodId = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['return filing (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            /*
             | Nobody is signed in while these run.
             |
             | `tax_return.file` demands a second factor, and that is correct:
             | the guard is silent for a console command because a queue has
             | no factor to prove, and it refuses a signed-in user who has not
             | enrolled one. An earlier suite in the same process leaves a
             | user on the guard, so without this every filing rule failed
             | with "set up a second factor" - the guard working, on somebody
             | else's session.
             |
             | What the guard does with a real user is PF-012's business and is
             | checked there. What belongs here is that the filer ASKS, which
             | the source check below asserts, and that the permission demands
             | MFA, which the catalogue check asserts.
             */
            $this->signEverybodyOut();

            return $this->withTheLedgerProof($this->runEach([
                'a box carries its taxable amount, its tax and the lines behind it' => fn (): array => $this->theBoxes(),
                'the return is assembled by tax point, not by accounting date' => fn (): array => $this->byTaxPoint(),
                'a draft entry is excluded, and the checklist says so' => fn (): array => $this->draftsAreExcluded(),
                'the net due is output less input, computed rather than mapped' => fn (): array => $this->theNetDue(),
                'the checklist reports every item, pending ones included' => fn (): array => $this->theChecklist(),
                'a suspense balance blocks filing' => fn (): array => $this->suspenseBlocks(),
                'filing freezes the figures, the line ids and a hash' => fn (): array => $this->filingFreezes(),
                'a filed return reprints byte-identically' => fn (): array => $this->reprintIsIdentical(),
                'a filing cannot be changed or deleted' => fn (): array => $this->filingsAreImmutable(),
                'filing over a blocking checklist needs a written reason' => fn (): array => $this->overridesNeedAReason(),
                'a tax-bearing line must carry a tax point' => fn (): array => $this->taxPointIsPaired(),
                'filing demands a second factor and is audited' => fn (): array => $this->filingIsGuarded(),
                'another entity cannot see these filings' => fn (): array => $this->rowLevelSecurityApplies(),
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * Sign out of every guard, so the step-up guard sees a console run.
     *
     * The same helper as `ErrorResponseRules`, for the same reason: guard
     * state is process-wide and a suite that depends on it has to set it
     * rather than inherit whatever ran first.
     */
    private function signEverybodyOut(): void
    {
        /** @var array<string, mixed> $guards */
        $guards = (array) config('auth.guards');

        foreach (array_keys($guards) as $guard) {
            try {
                $instance = auth()->guard((string) $guard);

                if (method_exists($instance, 'forgetUser')) {
                    $instance->forgetUser();
                }
            } catch (Throwable) {
                // A guard that cannot be built has nobody signed in to it.
            }
        }
    }

    /** @return list<string> */
    public function theBoxes(): array
    {
        $violations = [];
        $this->postADocument('2026-05-18', '2026-05-20');

        $return = $this->generate();
        $box = $return->boxFor('standard_sales');

        if ($box === null) {
            return ['the return has no standard_sales box'];
        }

        foreach ([
            'net' => [(string) $box->net->toPresentation(), '1000.00'],
            'tax' => [(string) $box->tax->toPresentation(), '150.00'],
        ] as $what => [$actual, $expected]) {
            if ($actual !== $expected) {
                $violations[] = sprintf('the standard sales box %s is %s and should be %s', $what, $actual, $expected);
            }
        }

        if ($box->lineCount() !== 2) {
            $violations[] = sprintf(
                'the box carries %d line ids and should carry 2 - the revenue leg and the tax leg. The '.
                'drill-down is what answers a query from an authority years later, when the period has '.
                'been adjusted and re-reported',
                $box->lineCount(),
            );
        }

        if ($box->hasTaxWithoutABase()) {
            $violations[] = 'the box reports tax with no taxable amount';
        }

        /*
         | The receivable leg carries the code and must NOT be a box figure.
         |
         | It is the gross - the net plus the tax - so adding it would
         | double-count both. The check is the box total rather than the line
         | count, because a wrong leg included would show up as 2,150.
         */
        if ((string) $box->net->toPresentation() === '2150.00') {
            $violations[] = 'the receivable leg was added to the taxable amount, which double-counts '.
                'the net and the tax at once';
        }

        return $violations;
    }

    /** @return list<string> */
    public function byTaxPoint(): array
    {
        $violations = [];

        /*
         | A supply delivered on 30 April and booked on 5 May.
         |
         | It belongs to APRIL's return and May's accounting period. A
         | generator that selected on the accounting date would put it in May,
         | which is the error PF-085 exists to prevent and the one that is
         | invisible once the return is filed.
         */
        $this->postADocument('2026-04-30', '2026-05-05');

        $may = $this->generate();

        if (! $may->outputTax()->isZero()) {
            $violations[] = sprintf(
                'a supply with an April tax point appeared in May\'s return as %s of output tax. The '.
                'return is assembled by tax point; the accounting date decides which period the entry '.
                'was booked in, and the two differ whenever a month end falls near a weekend',
                (string) $may->outputTax()->toPresentation(),
            );
        }

        $april = app(ReturnPeriods::class)->forTaxPoint(
            $this->entityId, 'SA', CarbonImmutable::parse('2026-04-30'),
        );

        if ($april === null) {
            return [...$violations, 'April did not generate'];
        }

        \assert($this->entity !== null);
        $return = app(ReturnGenerator::class)->generate($this->entity, $april);

        if ((string) $return->outputTax()->toPresentation() !== '150.00') {
            $violations[] = sprintf(
                'April\'s return shows %s of output tax and should show 150.00',
                (string) $return->outputTax()->toPresentation(),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function draftsAreExcluded(): array
    {
        $violations = [];
        $this->postADocument('2026-05-18', '2026-05-20', 'draft');

        $return = $this->generate();

        if (! $return->outputTax()->isZero()) {
            $violations[] = sprintf(
                'a DRAFT entry contributed %s of output tax. A draft is not a liability, and the figure '.
                'changes when somebody edits it after the return was reviewed',
                (string) $return->outputTax()->toPresentation(),
            );
        }

        $findings = $this->checklist($return);
        $draft = $this->finding($findings, 'draft entries carrying tax in this period');

        if ($draft === null || ! $draft->isBlocking()) {
            $violations[] = 'the checklist does not block on a draft entry carrying tax in the period. '.
                'It is excluded from the return and will walk into the next period when somebody posts '.
                'it, after this return was filed';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theNetDue(): array
    {
        $violations = [];
        $this->postADocument('2026-05-18', '2026-05-20');
        $this->postAPurchase('2026-05-19', '2026-05-21', '40.0000');

        $return = $this->generate();

        foreach ([
            'output' => [(string) $return->outputTax()->toPresentation(), '150.00'],
            'input' => [(string) $return->inputTax()->toPresentation(), '40.00'],
            'net due' => [(string) $return->netDue()->toPresentation(), '110.00'],
        ] as $what => [$actual, $expected]) {
            if ($actual !== $expected) {
                $violations[] = sprintf('the %s is %s and should be %s', $what, $actual, $expected);
            }
        }

        /*
         | And the net box is not something a tax code can post into.
         |
         | A `net` box a code mapped to would be a return whose bottom line
         | disagreed with its own boxes.
         */
        $net = $return->boxFor('net');

        if ($net !== null && ! $net->isEmpty()) {
            $violations[] = 'the net box carries figures of its own, so the bottom line can disagree '.
                'with the boxes above it';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theChecklist(): array
    {
        $violations = [];
        $this->postADocument('2026-05-18', '2026-05-20');

        $findings = $this->checklist($this->generate());

        $expected = [
            'the suspense balance',
            'draft entries carrying tax in this period',
            'every tax code maps to a box',
            'every box with tax has a taxable amount',
            'every tax-bearing line reached a box',
            'unposted documents in the period',
            'unmatched bank lines',
            'documents missing the evidence their category requires',
        ];

        foreach ($expected as $check) {
            if ($this->finding($findings, $check) === null) {
                $violations[] = sprintf('the checklist has no item for "%s"', $check);
            }
        }

        /*
         | Every item is now ASKED, and that is the thing to assert - PF-086.
         |
         | Three of these were `pending` until the phases they named
         | arrived: documents with Phase 05, bank matching with Phase 08,
         | evidence with a real document to record it against. All three
         | did, so a pending item here is now a check that regressed into
         | a placeholder rather than one honestly waiting.
         |
         | The old form of this check asserted there were AT LEAST three
         | pending. Inverting it is the point: the list no longer has a
         | gap, and a test that still demanded one would have to be
         | satisfied by putting a gap back.
         */
        $pending = array_values(array_filter(
            $findings,
            static fn (PreFilingFinding $f): bool => $f->isPending(),
        ));

        foreach ($pending as $finding) {
            if (! preg_match('/Phase \d\d|PF-\d\d\d/', $finding->detail)) {
                $violations[] = sprintf(
                    'the pending item "%s" does not say which task brings it, so the gap is an absence '.
                    'somebody has to notice rather than a sentence they can act on', $finding->check,
                );

                continue;
            }

            $violations[] = sprintf(
                'the checklist still reports "%s" as pending. Every phase this list was waiting on has '.
                'arrived, so an item that is still a placeholder is a question nobody is asking at '.
                'filing time - and the person filing is told it was looked at',
                $finding->check,
            );
        }

        if ($this->checklistBlocking($findings) !== []) {
            $violations[] = 'a clean period has a blocking finding: '.
                $this->checklistBlocking($findings)[0]->detail;
        }

        return $violations;
    }

    /** @return list<string> */
    public function suspenseBlocks(): array
    {
        $violations = [];
        $this->postADocument('2026-05-18', '2026-05-20');
        $this->postToSuspense('2026-05-22', '500.0000');

        $findings = $this->checklist($this->generate());
        $suspense = $this->finding($findings, 'the suspense balance');

        if ($suspense === null || ! $suspense->isBlocking()) {
            $violations[] = 'a non-zero suspense balance does not block. Whatever is in there is a '.
                'transaction nobody has explained, and if any of it is a sale the output tax is missing '.
                'from this return';
        }

        \assert($this->entity !== null && $this->may !== null);

        try {
            app(ReturnFiler::class)->file($this->entity, $this->may, str_repeat('0', 26));

            $violations[] = 'the return was filed with a blocking checklist and no override. Filing is '.
                'a statement to an authority that becomes immutable the moment it is made';
        } catch (FilingRefused $e) {
            if (! str_contains($e->getMessage(), 'suspense')) {
                $violations[] = 'the refusal does not name the blocking item: '.$e->getMessage();
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function filingFreezes(): array
    {
        $violations = [];
        $this->postADocument('2026-05-18', '2026-05-20');

        \assert($this->entity !== null && $this->may !== null);

        $return = $this->generate();
        $filing = app(ReturnFiler::class)->file(
            $this->entity, $this->may, str_repeat('0', 26), 'ZATCA-SUB-77',
        );

        if ($filing->hash !== $return->hash()) {
            $violations[] = 'the stored hash is not the hash of the return that was generated';
        }

        if (! $filing->isIntact()) {
            $violations[] = 'the stored document does not hash to what was recorded';
        }

        $frozen = DB::connection('tenant')->table('return_filing_lines')
            ->where('return_filing_id', $filing->getKey())
            ->count();

        if ($frozen !== $return->lineCount() || $frozen === 0) {
            $violations[] = sprintf(
                'the filing froze %d supporting lines and the return had %d. A query from an authority '.
                'asks why a box says what it says, years later, and the ids are what makes the answer '.
                'possible', $frozen, $return->lineCount(),
            );
        }

        if (! $this->may->refresh()->isFiled()) {
            $violations[] = 'the period is not marked filed, so the next document with a tax point in '.
                'it would be accepted';
        }

        /*
         | And the figures do not move when the ledger does.
         |
         | The whole reason for a snapshot: a credit note posted next week
         | changes what the ledger says about May and must not change what was
         | filed.
         */
        $this->postADocument('2026-05-25', '2026-05-26');

        $regenerated = $this->generate();
        $stored = $filing->refresh();

        if ($regenerated->hash() === $stored->hash) {
            $violations[] = 'posting more tax into the period did not change the regenerated return, so '.
                'this check is not actually comparing a snapshot against live data';
        }

        if (! $stored->isIntact() || $stored->hash !== $return->hash()) {
            $violations[] = 'the filed figures moved when the ledger did. The business can no longer '.
                'answer a query about its own filing';
        }

        return $violations;
    }

    /** @return list<string> */
    public function reprintIsIdentical(): array
    {
        $violations = [];
        $this->postADocument('2026-05-18', '2026-05-20');

        \assert($this->entity !== null && $this->may !== null);

        $return = $this->generate();
        $filing = app(ReturnFiler::class)->file($this->entity, $this->may, str_repeat('0', 26));

        if ($filing->reprint() !== $return->canonical()) {
            $violations[] = 'the reprint is not the bytes that were filed';
        }

        if ($filing->reprint() !== $filing->refresh()->reprint()) {
            $violations[] = 'two reprints of the same filing differ';
        }

        /*
         | Canonical means canonical: re-encoding the decoded document gives
         | the same bytes back. That is RFC 8785's property, and it is what
         | makes the hash meaningful rather than a hash of one PHP process's
         | key ordering.
         */
        $decoded = json_decode($filing->reprint(), true);

        if (! \is_array($decoded) || Canonicalizer::encode($decoded) !== $filing->reprint()) {
            $violations[] = 'the stored bytes are not canonical, so a reprint produced anywhere else '.
                'would differ';
        }

        if (hash('sha256', $filing->reprint()) !== $filing->hash) {
            $violations[] = 'the hash is not a digest of the stored bytes';
        }

        return $violations;
    }

    /** @return list<string> */
    public function filingsAreImmutable(): array
    {
        $violations = [];
        $this->postADocument('2026-05-18', '2026-05-20');

        \assert($this->entity !== null && $this->may !== null);
        $filing = app(ReturnFiler::class)->file($this->entity, $this->may, str_repeat('0', 26), 'SUB-1');

        $attempts = [
            'changing a figure' => fn (): mixed => ReturnFiling::query()
                ->whereKey($filing->getKey())->update(['hash' => str_repeat('a', 64)]),
            'changing the submission reference' => fn (): mixed => ReturnFiling::query()
                ->whereKey($filing->getKey())->update(['submission_reference' => 'SUB-2']),
            'deleting it' => fn (): mixed => ReturnFiling::query()->whereKey($filing->getKey())->delete(),
        ];

        foreach ($attempts as $what => $attempt) {
            $savepoint = 'fil_'.bin2hex(random_bytes(4));
            $this->connection?->statement('SAVEPOINT '.$savepoint);

            try {
                $attempt();

                $violations[] = sprintf(
                    '%s succeeded. A filing is a statement the business made to an authority: it is '.
                    'superseded by a new submission, never edited - and once one column can move, the '.
                    'argument for the next one is already made',
                    $what,
                );
            } catch (Throwable) {
                // Correct.
            } finally {
                $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function overridesNeedAReason(): array
    {
        $violations = [];
        $this->postADocument('2026-05-18', '2026-05-20');
        $this->postToSuspense('2026-05-22', '500.0000');

        \assert($this->entity !== null && $this->may !== null);
        $filer = app(ReturnFiler::class);

        try {
            $filer->file($this->entity, $this->may, str_repeat('0', 26), null, '   ');

            $violations[] = 'a blank override was accepted. Somebody reads the reason during an audit, '.
                'and whitespace is not one';
        } catch (FilingRefused) {
            // Correct.
        }

        /*
         | And a real reason is accepted, because refusing outright would be
         | the wrong call: a controller sometimes has to file with a known
         | problem because the deadline is today and a penalty is worse than a
         | disclosed error.
         */
        try {
            $filing = $filer->file(
                $this->entity, $this->may, str_repeat('0', 26), null,
                'Filing with 500.00 in suspense: the deadline is today and the item is an unidentified '.
                'receipt being traced with the bank.',
            );

            if ($filing->hash === '') {
                $violations[] = 'the override produced no filing';
            }
        } catch (Throwable $e) {
            $violations[] = 'a reasoned override was refused: '.$e->getMessage();
        }

        return $violations;
    }

    /** @return list<string> */
    public function taxPointIsPaired(): array
    {
        $violations = [];
        $date = Carbon::parse('2026-05-20');

        // A tax code with no tax point.
        $savepoint = 'fil_'.bin2hex(random_bytes(4));
        $this->connection?->statement('SAVEPOINT '.$savepoint);

        try {
            $this->insertEntry([
                [$this->revenueId, '-100.0000', (string) $this->code?->getKey(), null],
                [$this->receivableId, '100.0000', null, null],
            ], $date, 'posted');

            $this->connection?->statement('SET CONSTRAINTS ALL IMMEDIATE');

            $violations[] = 'a line carrying a tax code with no tax point was stored. The tax point is '.
                'what decides which return the line falls into, so the line is invisible to every '.
                'return - and the tax simply never appears';
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'tax_point')) {
                $violations[] = 'an unpaired tax code was refused by something else: '.$e->getMessage();
            }
        } finally {
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            $this->connection?->statement('SET CONSTRAINTS ALL DEFERRED');
        }

        // A tax point with no tax code.
        $savepoint = 'fil_'.bin2hex(random_bytes(4));
        $this->connection?->statement('SAVEPOINT '.$savepoint);

        try {
            $this->insertEntry([
                [$this->revenueId, '-100.0000', null, '2026-05-18'],
                [$this->receivableId, '100.0000', null, null],
            ], $date, 'posted');

            $this->connection?->statement('SET CONSTRAINTS ALL IMMEDIATE');

            $violations[] = 'a tax point on a line with no tax code was stored, which would put it in a '.
                'return\'s drill-down with no box to belong to';
        } catch (Throwable) {
            // Correct.
        } finally {
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            $this->connection?->statement('SET CONSTRAINTS ALL DEFERRED');
        }

        return $violations;
    }

    /** @return list<string> */
    public function filingIsGuarded(): array
    {
        $violations = [];

        $permission = Permission::query()->where('key', 'tax_return.file')->first();

        if ($permission === null) {
            $violations[] = 'there is no tax_return.file permission, so filing is not permissioned at all';
        } elseif (! (bool) $permission->requires_mfa) {
            $violations[] = 'tax_return.file does not require MFA. A session authenticated this morning '.
                'could file a statutory return this afternoon on a laptop somebody walked away from';
        }

        /*
         | And the filer actually asks.
         |
         | A permission declared and never checked is a permission that does
         | nothing, and the source is where that is visible - there is no
         | authenticated user in a check run, so the guard is silent here by
         | design.
         */
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/app/Tenant/Tax/Services/ReturnFiler.php');

        foreach ([
            'the step-up guard' => 'stepUp->assert(',
            'the permission key' => "'tax_return.file'",
            'the audit trail' => 'audit->record(',
        ] as $what => $needle) {
            if (! str_contains($source, $needle)) {
                $violations[] = sprintf('the filer does not call %s', $what);
            }
        }

        // And the audit record carries the checklist that was accepted.
        $this->postADocument('2026-05-18', '2026-05-20');

        \assert($this->entity !== null && $this->may !== null);
        app(ReturnFiler::class)->file($this->entity, $this->may, str_repeat('0', 26), 'SUB-9');

        $record = DB::connection('tenant')->table('audit_log')
            ->where('action', 'return.filed')
            ->orderByDesc('created_at')
            ->first();

        if ($record === null) {
            $violations[] = 'filing recorded nothing in the audit trail';

            return $violations;
        }

        $after = json_decode((string) ($record->after_state ?? '{}'), true);

        foreach (['hash', 'net_due', 'checklist', 'lines'] as $key) {
            if (! \is_array($after) || ! array_key_exists($key, $after)) {
                $violations[] = sprintf(
                    'the audit record does not carry "%s". What the checklist said at the moment of '.
                    'filing is the evidence that it was looked at', $key,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function rowLevelSecurityApplies(): array
    {
        $violations = [];
        $this->postADocument('2026-05-18', '2026-05-20');

        \assert($this->entity !== null && $this->may !== null);
        app(ReturnFiler::class)->file($this->entity, $this->may, str_repeat('0', 26));

        $context = app(EntityContext::class);
        $context->use(Entity::query()->findOrFail($this->otherEntityId));

        try {
            $leaked = ReturnFiling::withoutEntityScope()->where('entity_id', $this->entityId)->count();

            if ($leaked !== 0) {
                $violations[] = sprintf(
                    'another entity read %d of this entity\'s filings with the global scope removed', $leaked,
                );
            }
        } finally {
            $context->use(Entity::query()->findOrFail($this->entityId));
        }

        return $violations;
    }

    private function generate(): TaxReturn
    {
        \assert($this->entity !== null && $this->may !== null);

        return app(ReturnGenerator::class)->generate($this->entity, $this->may);
    }

    /** @return list<PreFilingFinding> */
    private function checklist(TaxReturn $return): array
    {
        \assert($this->entity !== null && $this->may !== null);

        return app(PreFilingChecklist::class)->for($this->entity, $this->may, $return);
    }

    /**
     * @param  list<PreFilingFinding>  $findings
     * @return list<PreFilingFinding>
     */
    private function checklistBlocking(array $findings): array
    {
        return app(PreFilingChecklist::class)->blocking($findings);
    }

    /** @param  list<PreFilingFinding>  $findings */
    private function finding(array $findings, string $check): ?PreFilingFinding
    {
        foreach ($findings as $finding) {
            if ($finding->check === $check) {
                return $finding;
            }
        }

        return null;
    }

    private function postADocument(string $taxPoint, string $accountingDate, string $status = 'posted'): void
    {
        $this->insertEntry([
            [$this->receivableId, '1150.0000', null, null],
            [$this->revenueId, '-1000.0000', (string) $this->code?->getKey(), $taxPoint],
            [$this->outputId, '-150.0000', (string) $this->code?->getKey(), $taxPoint],
        ], Carbon::parse($accountingDate), $status);
    }

    private function postAPurchase(string $taxPoint, string $accountingDate, string $tax): void
    {
        $input = AccountRole::query()
            ->where('entity_id', $this->entityId)
            ->where('role', 'vat_input')
            ->where('jurisdiction', 'SA')
            ->value('account_id');

        $expense = Account::query()
            ->where('entity_id', $this->entityId)
            ->where('type', 'expense')
            ->where('postable', true)
            ->whereNotIn('code', ['5.3', '5.4', '5.5', '5.6'])
            ->orderBy('code')
            ->value('id');

        $payable = AccountRole::query()
            ->where('entity_id', $this->entityId)
            ->where('role', 'accounts_payable')
            ->value('account_id');

        $net = (string) \Brick\Math\BigDecimal::of($tax)->multipliedBy('4')->toScale(4);
        $gross = (string) \Brick\Math\BigDecimal::of($tax)->plus($net)->toScale(4)->negated();

        $this->insertEntry([
            [(string) $expense, $net, (string) $this->code?->getKey(), $taxPoint],
            [(string) $input, $tax, (string) $this->code?->getKey(), $taxPoint],
            [(string) $payable, $gross, null, null],
        ], Carbon::parse($accountingDate), 'posted');
    }

    private function postToSuspense(string $accountingDate, string $amount): void
    {
        $bank = AccountRole::query()
            ->where('entity_id', $this->entityId)
            ->where('role', 'bank')
            ->value('account_id');

        $this->insertEntry([
            [(string) $bank, $amount, null, null],
            [$this->suspenseId, (string) \Brick\Math\BigDecimal::of($amount)->negated(), null, null],
        ], Carbon::parse($accountingDate), 'posted');
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string|null, 3: string|null}>  $lines
     */
    private function insertEntry(array $lines, Carbon $date, string $status): void
    {
        $connection = $this->connection;

        \assert($connection !== null);

        /*
         | A posting rule is set for the transaction.
         |
         | The control-account trigger refuses a raw write to a receivable or
         | a VAT account, which is PF-047 doing its job. Phase 05's document
         | rule will be the real name here; the check needs a document-shaped
         | entry before documents exist.
         */
        $connection->statement("SELECT set_config('peakflow.posting_rule', 'check.document', true)");

        $entryId = (string) Str::ulid();

        $connection->table('journal_entries')->insert([
            'id' => $entryId,
            'entity_id' => $this->entityId,
            'journal_id' => $this->journalId,
            'period_id' => $this->periodId,
            'number' => 'DOC-'.Str::ulid(),
            'accounting_date' => $date->toDateString(),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lines as [$account, $amount, $taxCode, $taxPoint]) {
            $connection->table('journal_lines')->insert([
                'id' => (string) Str::ulid(),
                'entity_id' => $this->entityId,
                'journal_entry_id' => $entryId,
                'accounting_date' => $date->toDateString(),
                'period_id' => $this->periodId,
                'account_id' => $account,
                'amount' => $amount,
                'currency' => 'SAR',
                'functional_amount' => $amount,
                'tax_code_id' => $taxCode === '' ? null : $taxCode,
                'tax_point' => $taxPoint,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'FIL'.random_int(100, 999),
            'legal_name' => 'Return filing check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $other = Entity::create([
            'code' => 'FLO'.random_int(100, 999),
            'legal_name' => 'Return filing check, other entity',
            'country' => 'JO',
            'functional_currency' => 'JOD',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $this->otherEntityId = (string) $other->getKey();
        $this->entity = $entity;
        $context->use($entity);

        TaxRegistration::create([
            'entity_id' => $this->entityId,
            'jurisdiction' => 'SA',
            'scheme' => 'VAT',
            'identifier' => '310000000000003',
            'effective_from' => '2020-01-01',
            'is_primary' => true,
            'filing_frequency' => FilingFrequency::Monthly->value,
        ]);

        $this->code = TaxCode::create([
            'entity_id' => $this->entityId,
            'code' => 'SA-S-15',
            'category' => TaxCategory::Standard->value,
            'jurisdiction' => 'SA',
            'label_ar' => 'النسبة الأساسية',
            'label_en' => 'Standard rate',
            'reporting_boxes' => ['sa' => ['output' => 'standard_sales', 'input' => 'standard_purchases']],
            'active' => true,
        ]);

        app(ReturnPeriods::class)->generateYear($entity, 'SA', 2026);

        $this->may = app(ReturnPeriods::class)->forTaxPoint(
            $this->entityId, 'SA', CarbonImmutable::parse('2026-05-15'),
        );

        foreach (['2026-04-15', '2026-05-20'] as $date) {
            $period = app(FiscalCalendarGenerator::class)->periodFor($this->entityId, Carbon::parse($date));
            app(PeriodStateMachine::class)->transition($period, PeriodState::Open, 'opened for the filing check');
            $this->periodId = (string) $period->getKey();
        }

        $this->journalId = (string) DB::connection('tenant')->table('journals')
            ->where('entity_id', $this->entityId)->where('code', 'GEN')->value('id');

        $this->receivableId = (string) AccountRole::query()
            ->where('entity_id', $this->entityId)->where('role', 'accounts_receivable')->value('account_id');

        $this->outputId = (string) AccountRole::query()
            ->where('entity_id', $this->entityId)->where('role', 'vat_output')
            ->where('jurisdiction', 'SA')->value('account_id');

        $this->suspenseId = (string) AccountRole::query()
            ->where('entity_id', $this->entityId)->where('role', 'suspense')->value('account_id');

        $this->revenueId = (string) Account::query()
            ->where('entity_id', $this->entityId)->where('type', 'revenue')
            ->where('postable', true)->orderBy('code')->value('id');
    }

    /**
     * @param  array<string, callable(): list<string>>  $rules
     * @return array<string, list<string>>
     */
    private function runEach(array $rules): array
    {
        $results = [];

        foreach ($rules as $name => $rule) {
            $savepoint = 'filr_'.bin2hex(random_bytes(4));
            $this->connection?->statement('SAVEPOINT '.$savepoint);

            try {
                /*
                 | Re-read the period from the database, per rule.
                 |
                 | The savepoint rolls the ROWS back and leaves this object's
                 | in-memory state where the previous rule left it - so a rule
                 | that filed the period made every rule after it refuse with
                 | "already filed". Found that way, which is the useful kind
                 | of failure: the checks were right and the fixture was stale.
                 */
                $this->may = $this->may?->fresh();

                $results[$name] = $rule();
            } catch (Throwable $e) {
                $results[$name] = ['the check could not run: '.$e->getMessage()];
            } finally {
                $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }
        }

        return $results;
    }
}
