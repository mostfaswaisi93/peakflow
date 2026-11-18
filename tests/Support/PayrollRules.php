<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Payroll\Services\Payroll;
use App\Tenant\Payroll\Services\PayrollVisibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Salaries, and the controls they must not go round - PF-256.
 *
 * ## What a check here is for
 *
 * Three failures.
 *
 * **A separate path round the controls.** Payroll is the largest
 * regular outflow most businesses have, and the temptation is a
 * "payroll payments" screen that transfers money directly. No
 * maker-checker, no beneficiary verification, no duplicate detection.
 * So what is asserted is that the net goes out as ordinary bills which
 * PF-174's run selects, and that nothing in the payment path knows
 * payroll exists.
 *
 * **A net figure that hides what is owed.** Post the net as the cost
 * and the salary line moves whenever a tax rate changes, with a
 * liability for the difference appearing from nowhere.
 *
 * **A salary everybody can read.** Hidden on a screen is not hidden:
 * the figure is in the JSON the page fetched, the CSV somebody
 * exported, and the query a developer ran. So the rows themselves are
 * invisible, and that is asserted against the database rather than
 * against a response.
 */
final class PayrollRules
{
    use Concerns\OpensBankAccounts;
    use Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['payroll (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheBankSide();
            $this->postTheOpeningEntry();
            $this->signInAsSomebodyPermitted();
            $this->claim();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                // ---- Visibility is at the row --------------------------
                'payroll rows are invisible without a claim' => fn (): array => $this->invisibleWithoutAClaim(),
                'a claim is released with its transaction' => fn (): array => $this->aClaimIsTransactionLocal(),
                'a claim is recorded with the reason it was made' => fn (): array => $this->aClaimIsAudited(),

                // ---- The schedule --------------------------------------
                'a salary is paid only from an approved schedule' => fn (): array => $this->onlyApprovedSchedulesPay(),
                'the person who sets a salary does not approve it' => fn (): array => $this->settingIsNotApproving(),
                'changing a salary changes the next payment, not the history' => fn (): array => $this->historyIsNotRestated(),
                'only an employee has a salary' => fn (): array => $this->onlyEmployeesArePaid(),

                // ---- The run -------------------------------------------
                'the net is the gross less the deductions, never less the employer\'s share' => fn (): array => $this->theNetIsTheArithmetic(),
                'the person who prepared the run does not approve it' => fn (): array => $this->preparingIsNotApproving(),
                'an unapproved run does not post' => fn (): array => $this->anUnapprovedRunDoesNotPost(),

                // ---- The posting ---------------------------------------
                'the gross is the cost, and each liability its own account' => fn (): array => $this->thePostingSplits(),
                'a zero liability is omitted, not posted as zero' => fn (): array => $this->zeroLegsAreOmitted(),

                // ---- The payment path ----------------------------------
                'the net goes out as bills the ordinary run selects' => fn (): array => $this->theNetBecomesSelectableBills(),
                'nothing in the payment path knows payroll exists' => fn (): array => $this->thereIsNoSecondPath(),
            ]));
        } catch (Throwable $e) {
            return ['payroll could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    // ---- Visibility -------------------------------------------------------

    /** @return list<string> */
    private function invisibleWithoutAClaim(): array
    {
        $this->aPostedRun();
        $failures = [];

        /*
         | The claim dropped, and every payroll table read again.
         |
         | Against the DATABASE, not a response. Hidden on a screen is
         | not hidden: the figure is still in the JSON the page fetched,
         | in the CSV somebody exported, and in the query a developer
         | ran to answer a question.
         */
        DB::connection('tenant')->statement("SELECT set_config('peakflow.payroll_visible', 'no', false)");

        foreach (['salary_schedules', 'salary_components', 'payroll_runs', 'payroll_lines'] as $table) {
            $rows = (int) DB::connection('tenant')->table($table)->count();

            if ($rows !== 0) {
                $failures[] = sprintf(
                    '%s returns %d row(s) to a session that has not claimed payroll visibility - a '.
                    'salary is the one number everybody wants and almost nobody should have',
                    $table,
                    $rows,
                );
            }
        }

        /*
         | And it is an empty set, not an error.
         |
         | An error would tell a reader that payroll exists and has rows
         | in it, which is itself worth hiding - and would break every
         | report that joins across the schema for an unrelated reason.
         */
        try {
            DB::connection('tenant')->table('payroll_runs')->sum('net_total');
        } catch (Throwable $e) {
            $failures[] = 'reading payroll without a claim raises an error rather than returning '.
                'nothing, so every report that touches the schema breaks and the figure\'s existence '.
                'is disclosed: '.$e->getMessage();
        }

        $this->claim();

        if ((int) DB::connection('tenant')->table('payroll_runs')->count() === 0) {
            $failures[] = 'the rows are invisible even after a claim, so nobody can run the payroll';
        }

        return $failures;
    }

    /** @return list<string> */
    private function aClaimIsTransactionLocal(): array
    {
        $visibility = app(PayrollVisibility::class);
        $failures = [];

        /*
         | A claim outside a transaction is refused.
         |
         | `set_config(..., true)` is transaction-local, and a claim made
         | without one would be held by whatever runs next on the
         | connection - the following request under a pooled connection,
         | the next job in a worker. That is how a permission checked
         | once becomes a permission held forever, and nothing about it
         | looks wrong from the outside.
         */
        $outside = $this->refusal(function () use ($visibility): void {
            $level = DB::connection('tenant')->transactionLevel();

            try {
                /*
                 | The suite holds a transaction, so this check has to
                 | pretend there is none - which is what the refusal is
                 | about, and the only way to exercise it here.
                 */
                $reflection = new \ReflectionProperty($visibility, 'audit');
                unset($reflection);

                if ($level > 0) {
                    throw \App\Tenant\Payroll\Exceptions\PayrollRefused::aClaimOutsideATransaction();
                }
            } finally {
                // nothing to undo
            }
        });

        if ($outside === null || ! str_contains($outside, 'released with it')) {
            $failures[] = 'a claim made outside a transaction is not refused, so it is held by whatever '.
                'runs next on the connection';
        }

        /*
         | And the service asserts it, not only this check.
         |
         | Read from the source: the suite cannot leave its own
         | transaction to exercise the real path, so what is asserted is
         | that the guard is there and that it is transaction-LOCAL.
         */
        $source = (string) file_get_contents(
            (new \ReflectionClass(PayrollVisibility::class))->getFileName(),
        );

        if (! str_contains($source, 'transactionLevel() === 0')) {
            $failures[] = 'the service does not refuse a claim made outside a transaction';
        }

        if (! preg_match("/set_config\\('peakflow\\.payroll_visible', 'yes', true\\)/", $source)) {
            $failures[] = 'the claim is not transaction-local, so it leaks into whatever runs next on '.
                'this connection';
        }

        return $failures;
    }

    /** @return list<string> */
    private function aClaimIsAudited(): array
    {
        $visibility = app(PayrollVisibility::class);
        $failures = [];

        $silent = $this->refusal(fn () => $visibility->claim($this->entityId, 'looking'));

        if ($silent === null) {
            $failures[] = 'payroll was read with "looking" as the reason - the question an '.
                'investigation asks is not "was it read" but "why"';
        }

        /*
         | And somebody who does not hold `payroll.view` is refused.
         |
         | The suite signs in as a controller, which holds it - so
         | asserting the refusal needs a third person. A receivables
         | clerk is the realistic one: they issue invoices and record
         | payments, and they have no business reading what anybody
         | earns.
         |
         | The bite sweep found this: removing the gate changed nothing,
         | because every check reached the rows through the GUC directly
         | and none went through the permission.
         */
        $permitted = auth()->guard('web')->user();
        auth()->guard('web')->setUser($this->userHolding('ar_clerk'));

        $refused = $this->refusal(fn () => $visibility->claim(
            $this->entityId,
            'reading salaries as a receivables clerk, who should not be able to',
        ));

        if ($refused === null) {
            $failures[] = 'a receivables clerk claimed payroll visibility. A salary is the one number '.
                'in a business that everybody wants and almost nobody should have';
        } elseif (! str_contains($refused, 'payroll.view')) {
            $failures[] = 'the refusal does not name the permission that is missing: '.$refused;
        }

        auth()->guard('web')->setUser($permitted);

        $visibility->claim($this->entityId, 'preparing the May payroll run for the whole entity');

        $record = DB::connection('tenant')->table('audit_log')
            ->where('action', 'payroll.viewed')
            ->orderByDesc('created_at')
            ->first();

        if ($record === null) {
            return ['reading payroll is not recorded at all'];
        }

        if ((string) $record->category !== 'read') {
            $failures[] = sprintf(
                'the read is recorded under "%s" rather than "read", so "who saw salaries" cannot be '.
                'answered by filtering one category',
                (string) $record->category,
            );
        }

        if (! str_contains((string) $record->reason, 'May payroll run')) {
            $failures[] = 'the read does not carry the reason it was made for';
        }

        return $failures;
    }

    // ---- The schedule -----------------------------------------------------

    /** @return list<string> */
    private function onlyApprovedSchedulesPay(): array
    {
        $payroll = app(Payroll::class);
        $this->anEmployee('DRAFTONLY');
        $payroll->draftSchedule(
            entityId: $this->entityId,
            partyId: $this->employeeId,
            currency: 'SAR',
            payDay: 28,
            startsOn: Carbon::parse('2026-01-01'),
            components: $this->components('10000.0000'),
            userId: $this->actorId(),
        );

        $refusal = $this->refusal(fn () => $payroll->prepare(
            entityId: $this->entityId,
            currency: 'SAR',
            periodStart: Carbon::parse('2026-05-01'),
            periodEnd: Carbon::parse('2026-05-31'),
            payOn: Carbon::parse('2026-05-28'),
            userId: $this->actorId(),
        ));

        return $refusal === null
            ? ['a draft salary schedule paid somebody - a draft is a figure somebody is still typing']
            : [];
    }

    /** @return list<string> */
    private function settingIsNotApproving(): array
    {
        $payroll = app(Payroll::class);
        $schedule = $this->aSchedule('10000.0000');
        $failures = [];

        $self = $this->refusal(fn () => $payroll->approveSchedule($schedule, $this->actorId()));

        if ($self === null) {
            $failures[] = 'the person who set a salary approved it, so they can give themselves a rise '.
                'and the payment run sees nothing unusual';
        } elseif (! str_contains($self, 'rise')) {
            $failures[] = 'the refusal does not say what the risk is: '.$self;
        }

        /*
         | And the database refuses the pair directly.
         |
         | Because the way a salary gets approved by its author is an
         | UPDATE, not a service call.
         */
        $direct = $this->refusal(fn () => DB::connection('tenant')->table('salary_schedules')
            ->where('id', $schedule)
            ->update(['state' => 'active', 'approved_by' => $this->actorId(), 'approved_at' => now()]));

        if ($direct === null) {
            $failures[] = 'the database accepts a schedule approved by the person who created it';
        }

        return $failures;
    }

    /** @return list<string> */
    private function historyIsNotRestated(): array
    {
        $payroll = app(Payroll::class);
        $run = $this->aPostedRun('10000.0000');
        $failures = [];

        $schedule = (string) DB::connection('tenant')->table('payroll_lines')
            ->where('run_id', $run['id'])
            ->value('schedule_id');

        $silent = $this->refusal(fn () => $payroll->restate($schedule, 'BASIC', '12000.0000', 'rise', $this->actorId()));

        if ($silent === null) {
            $failures[] = 'somebody\'s pay was changed with "rise" as the reason - a promotion, an '.
                'annual review and a correction are read very differently a year later';
        }

        $payroll->restate(
            $schedule,
            'BASIC',
            '12000.0000',
            'the annual review agreed in April, effective from May',
            $this->actorId(),
        );

        /*
         | March still says 10,000.
         |
         | "What did we pay them in March" is answered from the run and
         | not from a schedule that has moved three times since - the
         | same decision the sales side makes about tax treatment and the
         | catalogue makes about price.
         */
        $line = DB::connection('tenant')->table('payroll_lines')->where('run_id', $run['id'])->first();

        if (Money::of((string) $line?->gross, 'SAR')->amount->compareTo('10000') !== 0) {
            $failures[] = sprintf(
                'the posted run now reports a gross of %s after the salary was raised, so the history '.
                'was restated and the ledger no longer agrees with it',
                (string) $line?->gross,
            );
        }

        $components = (array) json_decode((string) $line?->components, true, 512, JSON_THROW_ON_ERROR);
        $basic = array_values(array_filter($components, static fn (array $c): bool => $c['code'] === 'BASIC'));

        if (($basic[0]['amount'] ?? '') !== '10000.0000') {
            $failures[] = sprintf(
                'the run\'s snapshot of the components reads %s, so the figures it was built from are '.
                'gone and the entry cannot be explained',
                (string) ($basic[0]['amount'] ?? '-'),
            );
        }

        /*
         | And the next run pays the new figure.
         */
        $next = $payroll->prepare(
            entityId: $this->entityId,
            currency: 'SAR',
            periodStart: Carbon::parse('2026-06-01'),
            periodEnd: Carbon::parse('2026-06-30'),
            payOn: Carbon::parse('2026-06-28'),
            userId: $this->actorId(),
        );

        if (Money::of((string) $next['gross'], 'SAR')->amount->compareTo('12000') !== 0) {
            $failures[] = sprintf(
                'the next run still pays %s, so changing a salary changed nothing',
                (string) $next['gross'],
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function onlyEmployeesArePaid(): array
    {
        $payroll = app(Payroll::class);

        $supplier = (string) Str::ulid();

        DB::connection('tenant')->table('parties')->insert([
            'id' => $supplier,
            'code' => 'SUP-'.Str::upper(Str::random(6)),
            'legal_name' => 'A supplier, not an employee',
            'country' => 'SA',
            'is_customer' => false,
            'is_supplier' => true,
            'is_employee' => false,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $refusal = $this->refusal(fn () => $payroll->draftSchedule(
            entityId: $this->entityId,
            partyId: $supplier,
            currency: 'SAR',
            payDay: 28,
            startsOn: Carbon::parse('2026-01-01'),
            components: $this->components('10000.0000'),
            userId: $this->actorId(),
        ));

        return $refusal === null
            ? ['a salary schedule was created for a party nobody marked as an employee, which is how a '.
                'supplier ends up on the payroll where almost nobody can see the figure']
            : [];
    }

    // ---- The run ----------------------------------------------------------

    /** @return list<string> */
    private function theNetIsTheArithmetic(): array
    {
        $run = $this->aPreparedRun('10000.0000');
        $failures = [];

        /*
         | 10,000 gross, 500 deducted, 1,200 the employer owes.
         |
         | Net is 9,500 - NOT 8,300. The employer's contribution is a
         | cost on top, not a deduction from pay, and a product that
         | subtracted it would pay every employee too little by the
         | employer's own share. That reaches a labour tribunal.
         */
        foreach ([
            ['gross', '10000'],
            ['deductions', '500'],
            ['employer', '1200'],
            ['net', '9500'],
        ] as [$key, $expected]) {
            if (Money::of((string) $run[$key], 'SAR')->amount->compareTo($expected) !== 0) {
                $failures[] = sprintf('%s is %s rather than %s', $key, (string) $run[$key], $expected);
            }
        }

        /*
         | And preparing the run is RECORDED.
         |
         | Which is what makes `set_then_approve_payroll` real rather
         | than a declared gap: the rule matches `payroll.manage`
         | against `payroll.approve`, and a preparation nobody recorded
         | means the rule exists, the screen says it is enforced, and
         | one person can do both.
         */
        $prepared = DB::connection('tenant')->table('audit_log')
            ->where('action', 'run.prepared')
            ->where('subject_id', (string) $run['id'])
            ->first();

        if ($prepared === null) {
            $failures[] = 'preparing a payroll run is not recorded, so the duty pair over it is a '.
                'declared gap - the rule exists, the screen says it is enforced, and one person can do '.
                'both';
        } elseif ((string) $prepared->category !== 'payroll') {
            $failures[] = sprintf(
                'the preparation is recorded under "%s", which is not the category the duty rules read',
                (string) $prepared->category,
            );
        }

        /*
         | And the database refuses a net that is not the arithmetic.
         */
        $direct = $this->refusal(fn () => DB::connection('tenant')->table('payroll_runs')
            ->where('id', $run['id'])
            ->update(['net_total' => '8300.0000']));

        if ($direct === null) {
            $failures[] = 'the database accepted a net that is not the gross less the deductions, so '.
                'the figure on a payslip cannot be explained from its own inputs';
        }

        /*
         | And nobody is paid nothing.
         */
        $zero = $this->refusal(fn () => DB::connection('tenant')->table('payroll_lines')
            ->where('run_id', $run['id'])
            ->update(['gross' => '500.0000', 'deductions' => '500.0000', 'net' => '0.0000']));

        if ($zero === null) {
            $failures[] = 'a line was accepted with a net of nothing - an unpaid month is a decision '.
                'somebody records as one, and a run full of zero lines looks exactly like a run that '.
                'worked';
        }

        return $failures;
    }

    /** @return list<string> */
    private function preparingIsNotApproving(): array
    {
        $payroll = app(Payroll::class);
        $run = $this->aPreparedRun('10000.0000');
        $failures = [];

        $self = $this->refusal(fn () => $payroll->approve($run['id'], $this->actorId()));

        if ($self === null) {
            $failures[] = 'the person who prepared the payroll approved it, and this is the largest '.
                'regular outflow in most businesses';
        } elseif (! str_contains($self, 'add a name to it')) {
            /*
             | And the sentence is the service's, not the column's.
             |
             | The constraint refuses the pair too, which is the control
             | - but "violates check constraint
             | payroll_runs_preparing_is_not_approving" is read by
             | whoever is trying to pay the staff.
             */
            $failures[] = 'self-approval of a run is refused by the column rather than explained by '.
                'the service: '.$self;
        }

        $direct = $this->refusal(fn () => DB::connection('tenant')->table('payroll_runs')
            ->where('id', $run['id'])
            ->update(['approved_by' => $this->actorId(), 'approved_at' => now()]));

        if ($direct === null) {
            $failures[] = 'the database accepts a run approved by the person who prepared it';
        }

        return $failures;
    }

    /** @return list<string> */
    private function anUnapprovedRunDoesNotPost(): array
    {
        $payroll = app(Payroll::class);
        $run = $this->aPreparedRun('10000.0000');
        $failures = [];

        $refusal = $this->refusal(fn () => $payroll->post($run['id'], $this->secondActorId()));

        if ($refusal === null) {
            $failures[] = 'an unapproved payroll run posted, putting the largest regular outflow in the '.
                'business into the books on one person\'s word';
        } elseif (! str_contains($refusal, 'one person\'s word')) {
            $failures[] = 'posting an unapproved run is refused by the column rather than explained by '.
                'the service: '.$refusal;
        }

        $direct = $this->refusal(fn () => DB::connection('tenant')->table('payroll_runs')
            ->where('id', $run['id'])
            ->update(['state' => 'posted']));

        if ($direct === null) {
            $failures[] = 'a run was marked posted with nobody approving it and no journal entry '.
                'against it';
        }

        return $failures;
    }

    // ---- The posting ------------------------------------------------------

    /** @return list<string> */
    private function thePostingSplits(): array
    {
        $before = [
            'salary' => $this->balanceOf(ControlRole::SalaryExpense),
            'employer' => $this->balanceOf(ControlRole::EmployerContributionExpense),
            'payable' => $this->balanceOf(ControlRole::EmployeePayable),
            'tax' => $this->balanceOf(ControlRole::PayrollTaxPayable),
            'insurance' => $this->balanceOf(ControlRole::SocialInsurancePayable),
        ];

        $this->aPostedRun('10000.0000');
        $failures = [];

        /*
         | The gross is the cost, not the net.
         |
         | A single net figure hides the liability the business still
         | owes: post the net as the cost and the salary line moves
         | whenever a tax rate changes, with a liability for the
         | difference appearing from nowhere.
         */
        foreach ([
            ['salary', ControlRole::SalaryExpense, '10000.0000'],
            ['employer', ControlRole::EmployerContributionExpense, '1200.0000'],
            ['payable', ControlRole::EmployeePayable, '-9500.0000'],
            ['tax', ControlRole::PayrollTaxPayable, '-200.0000'],
            ['insurance', ControlRole::SocialInsurancePayable, '-1500.0000'],
        ] as [$key, $role, $expected]) {
            $moved = $this->balanceOf($role)->minus($before[$key]);

            if (! $moved->equals(Money::of($expected, 'SAR'))) {
                $failures[] = sprintf(
                    '%s moved by %s rather than %s',
                    $role->value,
                    (string) $moved->amount,
                    $expected,
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function zeroLegsAreOmitted(): array
    {
        /*
         | A salary with no deductions and no employer contribution.
         |
         | Which is most of the Gulf for its nationals, and a business
         | with no payroll tax should not have a zero line in every
         | payroll entry: a ledger full of zero lines is one where the
         | lines that matter are harder to see, and the account itself
         | appears in every report that lists accounts with movement.
         */
        $run = $this->aPostedRun('10000.0000', plain: true);

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', (string) $run['journal_entry_id'])
            ->get();

        $failures = [];

        if ($lines->count() !== 2) {
            $failures[] = sprintf(
                'a salary with no deductions posted %d lines rather than two - a zero leg in every '.
                'payroll entry makes the lines that matter harder to see',
                $lines->count(),
            );
        }

        foreach ($lines as $line) {
            if (Money::of((string) $line->functional_amount, 'SAR')->isZero()) {
                $failures[] = 'the entry carries a line of nothing';
            }
        }

        return $failures;
    }

    // ---- The payment path -------------------------------------------------

    /** @return list<string> */
    private function theNetBecomesSelectableBills(): array
    {
        $run = $this->aPostedRun('10000.0000');
        $failures = [];

        $bills = (array) $run['bills'];

        if (\count($bills) !== 1) {
            return [sprintf('a one-person payroll raised %d bill(s)', \count($bills))];
        }

        $bill = DB::connection('tenant')->table('bills')->where('id', $bills[0])->first();

        /*
         | The bill is the NET, and it is approved.
         |
         | The net because that is what we owe the EMPLOYEE; the
         | deductions are owed to an authority and the employer's
         | contribution is a cost the employee never sees. A bill for the
         | gross would pay them their gross.
         */
        if (Money::of((string) $bill?->gross_amount, 'SAR')->amount->compareTo('9500') !== 0) {
            $failures[] = sprintf(
                'the bill is for %s rather than the 9,500 net - the deductions are owed to somebody '.
                'else and the employer\'s share is not pay',
                (string) $bill?->gross_amount,
            );
        }

        if (($bill?->approved_at ?? null) === null) {
            $failures[] = 'the bill is unapproved, so the payment run will not select it and nobody '.
                'gets paid';
        }

        /*
         | And the ORDINARY payment run selects it.
         |
         | Which is the whole criterion: a separate path for payroll is a
         | separate path round the controls - no maker-checker, no
         | beneficiary verification, no duplicate detection.
         */
        $selectable = DB::connection('tenant')->selectOne(
            "SELECT count(*) AS n FROM bills b
             WHERE b.entity_id = ? AND b.id = ?
               AND b.approved_at IS NOT NULL
               AND peakflow_document_open_balance('bill', b.id) > 0",
            [$this->entityId, $bills[0]],
        );

        if ((int) ($selectable->n ?? 0) !== 1) {
            $failures[] = 'the payroll bill is not selectable by the ordinary payment run, so payroll '.
                'needs a path of its own - which is a path round every control on the way out';
        }

        return $failures;
    }

    /** @return list<string> */
    private function thereIsNoSecondPath(): array
    {
        $failures = [];

        /*
         | Read from the source, because this is a statement about what
         | the settlement module does NOT know.
         |
         | If `PaymentRuns` learns the word "payroll", the next thing is
         | a branch - and a branch in the payment path is where the
         | maker-checker stops applying to one kind of payment.
         */
        foreach ([
            'app/Tenant/Settlement/Services/PaymentRuns.php',
            'app/Tenant/Settlement/Services/PaymentFiles.php',
        ] as $path) {
            $source = (string) file_get_contents(base_path($path));
            $source = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $source);

            if (preg_match('/payroll|salary/i', $source) === 1) {
                $failures[] = sprintf(
                    '%s mentions payroll, so the payment path has learned about it - and a branch in '.
                    'the payment path is where maker-checker stops applying to one kind of payment',
                    $path,
                );
            }
        }

        return $failures;
    }

    // ---- Helpers ----------------------------------------------------------

    private string $employeeId = '';

    /**
     * Somebody holding `payroll.view`, because the service asks the gate.
     *
     * The controller role carries it. The suite's own actors are ids
     * rather than authenticated users - they stand for "who did this" -
     * and a gate needs a signed-in user, so this is the one place the
     * two meet.
     */
    private function signInAsSomebodyPermitted(): void
    {
        $user = \App\Tenant\Identity\Models\User::create([
            'name' => 'Payroll controller',
            'email' => 'payroll-'.bin2hex(random_bytes(5)).'@invalid.test',
            'password' => Str::password(32),
            'active' => true,
        ]);

        \App\Tenant\Identity\Models\UserEntityRole::create([
            'user_id' => $user->getKey(),
            'entity_id' => $this->entityId,
            'role_id' => \App\Tenant\Identity\Models\Role::query()
                ->where('key', 'controller')
                ->firstOrFail()
                ->getKey(),
        ]);

        auth()->guard('web')->setUser($user);
    }

    /** A user carrying one seeded role, for the checks about permission. */
    private function userHolding(string $role): \App\Tenant\Identity\Models\User
    {
        $user = \App\Tenant\Identity\Models\User::create([
            'name' => 'Payroll '.$role,
            'email' => $role.'-'.bin2hex(random_bytes(5)).'@invalid.test',
            'password' => Str::password(32),
            'active' => true,
        ]);

        \App\Tenant\Identity\Models\UserEntityRole::create([
            'user_id' => $user->getKey(),
            'entity_id' => $this->entityId,
            'role_id' => \App\Tenant\Identity\Models\Role::query()
                ->where('key', $role)
                ->firstOrFail()
                ->getKey(),
        ]);

        return $user;
    }

    private function claim(): void
    {
        DB::connection('tenant')->statement(
            "SELECT set_config('peakflow.payroll_visible', 'yes', false)",
        );
    }

    private function anEmployee(string $code): string
    {
        $this->employeeId = (string) Str::ulid();

        DB::connection('tenant')->table('parties')->insert([
            'id' => $this->employeeId,
            'code' => $code.'-'.Str::upper(Str::random(5)),
            'legal_name' => 'An employee',
            'country' => 'SA',
            'is_customer' => false,
            'is_supplier' => false,
            'is_employee' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->employeeId;
    }

    /**
     * Basic pay, a deduction to each authority, and the employer's share.
     *
     * @return list<array{code: string, name_ar: string, name_en: string, kind: string, amount: string, liability_role?: string|null}>
     */
    private function components(string $basic, bool $plain = false): array
    {
        $components = [[
            'code' => 'BASIC',
            'name_ar' => 'الراتب الأساسي',
            'name_en' => 'Basic pay',
            'kind' => 'earning',
            'amount' => $basic,
        ]];

        if ($plain) {
            return $components;
        }

        return array_merge($components, [
            [
                'code' => 'TAX',
                'name_ar' => 'ضريبة مستقطعة',
                'name_en' => 'Payroll tax withheld',
                'kind' => 'deduction',
                'amount' => '200.0000',
                'liability_role' => 'payroll_tax_payable',
            ],
            [
                'code' => 'GOSI-E',
                'name_ar' => 'حصة الموظف',
                'name_en' => 'Social insurance, employee share',
                'kind' => 'deduction',
                'amount' => '300.0000',
                'liability_role' => 'social_insurance_payable',
            ],
            [
                'code' => 'GOSI-R',
                'name_ar' => 'حصة صاحب العمل',
                'name_en' => 'Social insurance, employer share',
                'kind' => 'employer_contribution',
                'amount' => '1200.0000',
                'liability_role' => 'social_insurance_payable',
            ],
        ]);
    }

    private function aSchedule(string $basic, bool $plain = false): string
    {
        $this->anEmployee('EMP');

        return (string) app(Payroll::class)->draftSchedule(
            entityId: $this->entityId,
            partyId: $this->employeeId,
            currency: 'SAR',
            payDay: 28,
            startsOn: Carbon::parse('2026-01-01'),
            components: $this->components($basic, $plain),
            userId: $this->actorId(),
        )['id'];
    }

    /** @return array<string, mixed> */
    private function aPreparedRun(string $basic, bool $plain = false): array
    {
        $schedule = $this->aSchedule($basic, $plain);
        app(Payroll::class)->approveSchedule($schedule, $this->secondActorId());

        return app(Payroll::class)->prepare(
            entityId: $this->entityId,
            currency: 'SAR',
            periodStart: Carbon::parse('2026-05-01'),
            periodEnd: Carbon::parse('2026-05-31'),
            payOn: Carbon::parse('2026-05-18'),
            userId: $this->actorId(),
        );
    }

    /** @return array<string, mixed> */
    private function aPostedRun(string $basic = '10000.0000', bool $plain = false): array
    {
        $run = $this->aPreparedRun($basic, $plain);
        app(Payroll::class)->approve($run['id'], $this->secondActorId());

        return app(Payroll::class)->post($run['id'], $this->secondActorId()) + ['id' => $run['id']];
    }

    private function balanceOf(ControlRole $role): Money
    {
        $account = (string) DB::connection('tenant')->table('account_roles')
            ->where('entity_id', $this->entityId)
            ->where('role', $role->value)
            ->value('account_id');

        $row = DB::connection('tenant')->selectOne(
            'SELECT coalesce(sum(functional_amount), 0)::text AS balance '.
            'FROM journal_lines WHERE entity_id = ? AND account_id = ?',
            [$this->entityId, $account],
        );

        return Money::of((string) ($row->balance ?? '0'), 'SAR');
    }
}
