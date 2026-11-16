<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Purchasing\Exceptions\ApprovalRefused;
use App\Tenant\Purchasing\Exceptions\BillRefused;
use App\Tenant\Purchasing\Models\ApprovalRequest;
use App\Tenant\Purchasing\Models\ApprovalRule;
use App\Tenant\Purchasing\Models\ApprovalRuleStep;
use App\Tenant\Purchasing\Models\ExpenseClaim;
use App\Tenant\Purchasing\Models\ExpensePolicy;
use App\Tenant\Purchasing\Services\ApprovalEngine;
use App\Tenant\Purchasing\Services\ExpenseClaims;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Employee expense claims - PF-143.
 *
 * ## The three things worth checking
 *
 * **Where the payable goes.** An employee is not a supplier, and a claim
 * credited to trade payables puts a colleague's taxi fare in the list a
 * payment run reads and the aging report ages.
 *
 * **Whether the input tax is supported.** A return whose input box rests on
 * an employee's word cannot be defended: the authority asks for the receipt
 * rather than for the spreadsheet.
 *
 * **Whether the rate was the rate.** A mileage claim for last November
 * re-derived at today's rate pays the wrong amount and cannot be reconciled
 * to what was approved.
 */
final class ExpenseClaimRules
{
    use \Tests\Support\Concerns\EntersBills;
    use \Tests\Support\Concerns\ProvesTheLedger;

    private string $claimant = '';

    private string $manager = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['expense claims (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedThePayableSide();

            $this->claimant = (string) Str::ulid();
            $this->manager = (string) Str::ulid();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'a claim is entered with its lines totalled' => fn (): array => $this->entry(),
                'the payable is to the employee, not to trade payables' => fn (): array => $this->employeePayable(),
                'input tax is recovered where a receipt supports it' => fn (): array => $this->recoveryWithAReceipt(),
                'and not without one' => fn (): array => $this->noReceiptNoRecovery(),
                'nor where the category forbids it' => fn (): array => $this->categoryForbidsRecovery(),
                'nor where no policy has decided' => fn (): array => $this->noPolicyNoRecovery(),
                'the database refuses recovery with no receipt' => fn (): array => $this->recoveryNeedsAReceiptInTheDatabase(),
                'a mileage claim uses the rate in force on the day' => fn (): array => $this->mileageRate(),
                'a per-unit claim with no rate in force is refused' => fn (): array => $this->noRate(),
                'a line over the ceiling is flagged, not refused' => fn (): array => $this->ceilingIsFlagged(),
                'an unexplained breach does not reach an approver' => fn (): array => $this->unexplainedBreach(),
                'and the database refuses it too, which an import cannot bypass' => fn (): array => $this->unexplainedBreachInTheDatabase(),
                'a partial recovery is the percentage, not a second flag' => fn (): array => $this->partialRecovery(),
                'the claimant cannot approve their own claim' => fn (): array => $this->ownClaim(),
                'editing an explanation restarts the chain' => fn (): array => $this->editedExplanation(),
                'an unapproved claim cannot be posted' => fn (): array => $this->unapprovedPosting(),
                'posting debits the expense and credits the employee' => fn (): array => $this->posting(),
                'non-recoverable tax rides with the expense' => fn (): array => $this->nonRecoverableTax(),
                'what is owed to employees is reportable per person' => fn (): array => $this->owed(),
                'a rejection needs a reason' => fn (): array => $this->rejection(),
                'and the ledger still proves its own invariants' => fn (): array => [],
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function entry(): array
    {
        $violations = [];
        $this->policy('travel', recoverable: true);

        $claim = $this->claim([
            $this->line('travel', '400.0000', '60.0000', receipt: true),
            $this->line('travel', '100.0000', '15.0000', receipt: true),
        ]);

        if (! BigDecimal::of((string) $claim->gross_amount)->isEqualTo('575.0000')) {
            $violations[] = sprintf('the claim grosses %s and 500 plus 75 is 575', (string) $claim->gross_amount);
        }

        if (! BigDecimal::of((string) $claim->recoverable_tax_amount)->isEqualTo('75.0000')) {
            $violations[] = sprintf(
                'the recoverable tax is %s and both lines carry receipts under a policy that permits '.
                'recovery',
                (string) $claim->recoverable_tax_amount,
            );
        }

        if ($claim->number === null) {
            $violations[] = 'the claim has no number, so nothing can reference it';
        }

        return $violations;
    }

    /** @return list<string> */
    private function employeePayable(): array
    {
        $violations = [];
        $this->policy('travel');

        $claim = $this->posted();
        $lines = $this->linesOfEntry((string) $claim->journal_entry_id);

        $employee = $this->amountOnRole($lines, ControlRole::EmployeePayable->value);
        $trade = $this->amountOnRole($lines, ControlRole::AccountsPayable->value);

        if (! BigDecimal::of($employee)->isEqualTo('-460.0000')) {
            $violations[] = sprintf(
                'the employee payable is credited %s and the claim is 460',
                $employee,
            );
        }

        if (! BigDecimal::of($trade)->isZero()) {
            $violations[] = sprintf(
                'the claim credited %s to TRADE payables. An employee is not a supplier: the balance is '.
                'reported differently, it is frequently settled through payroll, and an aging report '.
                'that mixed the two would show a colleague\'s taxi fare beside a supplier on '.
                'thirty-day terms',
                $trade,
            );
        }

        /*
         | And no party on the lines.
         |
         | The claimant is a user. Giving the posting a party id would put a
         | colleague in the list every party-scoped control reads, the
         | supplier bank-detail rule included - which is the highest-value
         | fraud in the product.
         */
        $withParty = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', (string) $claim->journal_entry_id)
            ->whereNotNull('party_id')
            ->count();

        if ($withParty > 0) {
            $violations[] = sprintf(
                '%d of the claim\'s journal lines name a party. The claimant is a user, not a party, '.
                'and a colleague in the party tables is a colleague every party-scoped control treats '.
                'as a supplier',
                $withParty,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function recoveryWithAReceipt(): array
    {
        $this->policy('travel', recoverable: true);

        $claim = $this->claim([$this->line('travel', '400.0000', '60.0000', receipt: true)]);
        $line = $claim->lines->first();

        if (! BigDecimal::of((string) $line->recoverable_percent)->isEqualTo('100')) {
            return [sprintf(
                'a receipted travel line under a policy that permits full recovery recovers %s%%',
                (string) $line->recoverable_percent,
            )];
        }

        if ($line->tax_decision === null) {
            return ['the line does not record WHY the tax was claimed. "Why was this claimed" has to be '.
                'answerable a year later, when the policy has changed and the return has been filed'];
        }

        return [];
    }

    /** @return list<string> */
    private function noReceiptNoRecovery(): array
    {
        $this->policy('travel', recoverable: true, receiptRequiredAbove: '1000');

        $claim = $this->claim([$this->line('travel', '400.0000', '60.0000', receipt: false)]);
        $line = $claim->lines->first();

        if (! BigDecimal::of((string) $line->recoverable_percent)->isZero()) {
            return [sprintf(
                'a line with no receipt recovers %s%% of its tax. The authority asks for the receipt '.
                'rather than for the spreadsheet, and a return whose input box rests on an employee\'s '.
                'word cannot be defended',
                (string) $line->recoverable_percent,
            )];
        }

        if (! BigDecimal::of((string) $claim->recoverable_tax_amount)->isZero()) {
            return ['the claim still reports recoverable tax with no receipt behind it'];
        }

        return [];
    }

    /** @return list<string> */
    private function categoryForbidsRecovery(): array
    {
        $this->policy('entertainment', recoverable: false);

        $claim = $this->claim([$this->line('entertainment', '400.0000', '60.0000', receipt: true)]);
        $line = $claim->lines->first();

        if (! BigDecimal::of((string) $line->recoverable_percent)->isZero()) {
            return [sprintf(
                'client entertainment recovered %s%% of its tax with a receipt attached. The receipt is '.
                'evidence and the category is entitlement - they fail differently, and this category '.
                'has no entitlement however good the evidence',
                (string) $line->recoverable_percent,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function noPolicyNoRecovery(): array
    {
        // No policy for this category at all.
        $claim = $this->claim([$this->line('supplies', '400.0000', '60.0000', receipt: true)]);
        $line = $claim->lines->first();

        if (! BigDecimal::of((string) $line->recoverable_percent)->isZero()) {
            return [
                'tax was recovered on a category nobody has written a policy for. The safe reading of '.
                '"nobody decided" on a tax return is "do not claim"',
            ];
        }

        if ($line->policy_exception === null) {
            return ['a line with no policy behind it is not flagged for the approver'];
        }

        return [];
    }

    /** @return list<string> */
    private function recoveryNeedsAReceiptInTheDatabase(): array
    {
        $this->policy('travel', recoverable: true);
        $claim = $this->claim([$this->line('travel', '400.0000', '60.0000', receipt: false)]);
        $line = $claim->lines->first();

        /*
         | Straight at the table, as an import would.
         |
         | A service check is bypassed by an import, and this is the row an
         | authority asks about.
         */
        try {
            DB::connection('tenant')->table('expense_claim_lines')
                ->where('id', (string) $line->getKey())
                ->update(['recoverable_percent' => '100']);

            return [
                'the database accepted full recovery on a line with no receipt and no stated decision, '.
                'so an import can produce a return whose input box cannot be supported',
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'recovery_needs_a_receipt')) {
                return ['the recovery was refused by something other than its constraint: '.$e->getMessage()];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function mileageRate(): array
    {
        $violations = [];

        /*
         | Two rates, one superseding the other.
         |
         | A claim for a journey in the earlier window is paid at the
         | earlier rate, which is the whole point of dating the policy.
         */
        $this->policy(
            'mileage',
            rate: '0.80000000',
            rateUnit: 'KMT',
            from: '2026-01-01',
            to: '2026-03-31',
        );

        $this->policy('mileage', rate: '1.00000000', rateUnit: 'KMT', from: '2026-04-01');

        $claim = $this->claim([[
            'category' => 'mileage',
            'description' => '240 km to the Jubail site',
            'incurred_on' => '2026-03-15',
            'quantity' => '240',
            'expense_account_id' => $this->expenseAccountId,
        ]]);

        $line = $claim->lines->first();

        if (! BigDecimal::of((string) $line->net_amount)->isEqualTo('192.0000')) {
            $violations[] = sprintf(
                'a 240 km journey in March is priced %s, and 240 at the March rate of 0.80 is 192. A '.
                'claim re-derived at the current rate pays the wrong amount and cannot be reconciled '.
                'to what was approved',
                (string) $line->net_amount,
            );
        }

        if ($line->rate === null || ! BigDecimal::of((string) $line->rate)->isEqualTo('0.8')) {
            $violations[] = sprintf(
                'the line records the rate as %s rather than freezing March\'s 0.80 onto it',
                (string) ($line->rate ?? 'nothing'),
            );
        }

        if ($line->policy_id === null) {
            $violations[] = 'the line does not name the policy its rate came from';
        }

        return $violations;
    }

    /** @return list<string> */
    private function noRate(): array
    {
        try {
            $this->claim([[
                'category' => 'mileage',
                'description' => '240 km with no rate in force',
                'quantity' => '240',
                'expense_account_id' => $this->expenseAccountId,
            ]]);

            return [
                'a per-unit claim was priced with no policy rate in force. A per-unit claim without a '.
                'rate is a figure somebody typed',
            ];
        } catch (BillRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function ceilingIsFlagged(): array
    {
        $violations = [];
        $this->policy('accommodation', ceilingPerLine: '500.0000');

        $claim = $this->claim([$this->line(
            'accommodation',
            '900.0000',
            '135.0000',
            receipt: true,
            explanation: 'the conference hotel was the only one left within reach of the venue',
        )]);

        $line = $claim->lines->first();

        if ($line->policy_exception === null) {
            $violations[] = '900 against a 500 ceiling was not flagged, so the approver is not told '.
                'what they are agreeing to';
        }

        if ($line->policy_exception !== null && ! str_contains((string) $line->policy_exception, '500')) {
            $violations[] = sprintf(
                'the flag does not name the ceiling: "%s"',
                (string) $line->policy_exception,
            );
        }

        if ((string) $claim->status !== 'draft') {
            $violations[] = sprintf(
                'the claim is %s. A hotel over the ceiling because the conference hotel was the only '.
                'one left is a real claim, and refusing it sends somebody to a spreadsheet and an '.
                'email',
                (string) $claim->status,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function unexplainedBreach(): array
    {
        $this->policy('accommodation', ceilingPerLine: '500.0000');
        $this->rule('EXP-BREACH');

        $claim = $this->claim([$this->line('accommodation', '900.0000', '135.0000', receipt: true)]);

        try {
            app(ExpenseClaims::class)->submit($claim, $this->claimant);

            return [
                'a claim with an unexplained policy breach was submitted for approval. The approver is '.
                'the person the policy exists to inform, and a flag with no text is a line somebody '.
                'approves without knowing what is wrong with it',
            ];
        } catch (BillRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function unexplainedBreachInTheDatabase(): array
    {
        $this->policy('accommodation', ceilingPerLine: '500.0000');

        $claim = $this->claim([$this->line('accommodation', '900.0000', '135.0000', receipt: true)]);

        /*
         | Straight at the table, as an import would.
         |
         | The service refuses this first, which is right - but a service
         | check is bypassed by an import, a script or a second worker, and
         | the state it would leave behind is a claim an approver is asked
         | to agree to without being told what is wrong with it.
         |
         | Measured: with only the service check, disabling the trigger
         | changed nothing in this suite - which is a guarantee nothing was
         | checking.
         */
        try {
            DB::connection('tenant')->table('expense_claims')
                ->where('id', (string) $claim->getKey())
                ->update(['status' => 'awaiting_approval', 'submitted_at' => now()]);

            return [
                'the database accepted a claim for approval with an unexplained policy breach on it, '.
                'so an import can put a line in front of an approver without telling them what is '.
                'wrong with it',
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'carries no explanation')) {
                return ['the submission was refused by something other than the breach trigger: '.$e->getMessage()];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function partialRecovery(): array
    {
        $violations = [];

        /*
         | Entertainment at 50%, which is the real case in several
         | jurisdictions - and the reason recovery is a percentage rather
         | than a flag.
         */
        ExpensePolicy::create([
            'entity_id' => $this->entityId,
            'category' => 'entertainment',
            'currency' => 'SAR',
            'receipt_required_above' => '0',
            'recoverable_percent' => '50',
            'expense_account_id' => $this->expenseAccountId,
            'effective_from' => '2026-01-01',
            'active' => true,
        ]);

        $claim = $this->claim([$this->line('entertainment', '400.0000', '60.0000', receipt: true)]);
        $line = $claim->lines->first();

        if (! BigDecimal::of((string) $line->recoverable_percent)->isEqualTo('50')) {
            $violations[] = sprintf(
                'a 50%% policy produced %s%% recovery. Partial recovery is the normal case for '.
                'entertainment, fuel and mixed-use assets, and a boolean would force the business to '.
                'split a line nobody split',
                (string) $line->recoverable_percent,
            );
        }

        if (! BigDecimal::of((string) $claim->recoverable_tax_amount)->isEqualTo('30.0000')) {
            $violations[] = sprintf(
                'the claim recovers %s of 60 at 50%%',
                (string) $claim->recoverable_tax_amount,
            );
        }

        if (! BigDecimal::of((string) $claim->non_recoverable_tax_amount)->isEqualTo('30.0000')) {
            $violations[] = sprintf(
                'the claim carries %s of non-recoverable tax, and the two halves have to add back to '.
                'the 60 charged - a cent that belongs to neither is a cent in the input account that '.
                'will not tie to the return',
                (string) $claim->non_recoverable_tax_amount,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function ownClaim(): array
    {
        $this->policy('travel');
        $this->rule('EXP-OWN');

        $claim = $this->claim([$this->line('travel', '400.0000', '60.0000', receipt: true)]);
        $request = app(ExpenseClaims::class)->submit($claim, $this->claimant);

        try {
            app(ApprovalEngine::class)->decide(
                request: $request,
                userId: $this->claimant,
                decision: 'approved',
                currentFields: app(ExpenseClaims::class)->fieldsOf($claim->refresh()->load('lines')),
                userRoles: ['purchasing.manager'],
            );

            return [
                'the claimant approved their own claim. The engine asks the same question it asks '.
                'about a bill, which is the reason a claim goes through it rather than through a '.
                'workflow of its own',
            ];
        } catch (ApprovalRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function editedExplanation(): array
    {
        $this->policy('accommodation', ceilingPerLine: '500.0000');
        $this->rule('EXP-EDIT');

        $claim = $this->claim([$this->line(
            'accommodation',
            '900.0000',
            '135.0000',
            receipt: true,
            explanation: 'the conference hotel was the only one left within reach of the venue',
        )]);

        $request = app(ExpenseClaims::class)->submit($claim, $this->claimant);

        $claim->lines->first()->update([
            'policy_explanation' => 'actually it was a nicer hotel and I stayed an extra night',
        ]);

        try {
            app(ApprovalEngine::class)->decide(
                request: $request,
                userId: $this->manager,
                decision: 'approved',
                currentFields: app(ExpenseClaims::class)->fieldsOf($claim->refresh()->load('lines')),
                userRoles: ['purchasing.manager'],
            );

            return [
                'the explanation behind a policy breach changed after submission and the claim was '.
                'approved against the new text. The approver agreed to a breach on the strength of a '.
                'sentence, so changing the sentence is a different claim',
            ];
        } catch (ApprovalRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function unapprovedPosting(): array
    {
        $this->policy('travel');
        $claim = $this->claim([$this->line('travel', '400.0000', '60.0000', receipt: true)]);

        try {
            app(ExpenseClaims::class)->post($claim, $this->entity(), $this->date->copy(), $this->manager);

            return [
                'an unapproved claim was posted, which owes somebody money for expenses nobody agreed '.
                'to and leaves a balance in the employee payable account with no decision behind it',
            ];
        } catch (BillRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function posting(): array
    {
        $violations = [];
        $this->policy('travel');

        $claim = $this->posted();
        $lines = $this->linesOfEntry((string) $claim->journal_entry_id);

        $expense = $this->amountOnAccount($lines, $this->expenseAccountId);
        $input = $this->amountOnRole($lines, ControlRole::VatInput->value);

        if (! BigDecimal::of($expense)->isEqualTo('400.0000')) {
            $violations[] = sprintf('the expense is debited %s and the claim is 400 net', $expense);
        }

        if (! BigDecimal::of($input)->isEqualTo('60.0000')) {
            $violations[] = sprintf('the input tax is %s and the receipt supports 60', $input);
        }

        if ($claim->posting_date === null) {
            $violations[] = 'a posted claim does not record the date it was posted into';
        }

        return $violations;
    }

    /** @return list<string> */
    private function nonRecoverableTax(): array
    {
        $this->policy('entertainment', recoverable: false);
        $this->rule('EXP-NR');

        $claim = $this->claim([$this->line('entertainment', '400.0000', '60.0000', receipt: true)]);
        $claim = $this->approveAndPost($claim);

        $lines = $this->linesOfEntry((string) $claim->journal_entry_id);

        $expense = $this->amountOnAccount($lines, $this->expenseAccountId);
        $input = $this->amountOnRole($lines, ControlRole::VatInput->value);

        if (! BigDecimal::of($input)->isZero()) {
            return [sprintf(
                'tax that cannot be claimed reached the input account (%s). Tax sitting there that will '.
                'never be claimed is tax that ties to no box on any form, and the difference is found '.
                'during an audit by somebody who did not put it there',
                $input,
            )];
        }

        if (! BigDecimal::of($expense)->isEqualTo('460.0000')) {
            return [sprintf(
                'the expense is %s and should carry the net plus the tax that cannot be recovered, '.
                'which is 460. The cost of the purchase includes the tax nobody can claim back',
                $expense,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function owed(): array
    {
        $this->policy('travel');
        $claim = $this->posted();

        $owed = app(ExpenseClaims::class)->owedToEmployees($this->entityId, $this->date->copy());

        $mine = array_values(array_filter(
            $owed,
            fn (array $row): bool => (string) $row['claimant_user_id'] === $this->claimant,
        ));

        if ($mine === []) {
            return ['a posted and unpaid claim does not appear in what is owed to employees, which is '.
                'the report the control account exists for'];
        }

        if (! BigDecimal::of((string) $mine[0]['outstanding'])->isEqualTo((string) $claim->gross_amount)) {
            return [sprintf(
                'the claimant is owed %s and the claim is %s',
                (string) $mine[0]['outstanding'],
                (string) $claim->gross_amount,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function rejection(): array
    {
        $violations = [];
        $this->policy('travel');
        $claim = $this->claim([$this->line('travel', '400.0000', '60.0000', receipt: true)]);

        try {
            app(ExpenseClaims::class)->reject($claim, $this->manager, 'no');

            $violations[] = 'a claim was rejected with "no". The claimant is out of pocket and is going '.
                'to ask';
        } catch (BillRefused) {
            // Expected.
        }

        $rejected = app(ExpenseClaims::class)->reject(
            $claim,
            $this->manager,
            'the hotel receipt is for two rooms and only one of them is yours',
        );

        if ((string) $rejected->status !== 'rejected') {
            $violations[] = 'a rejected claim does not say so';
        }

        return $violations;
    }

    // ---- Helpers -------------------------------------------------------

    /** @param list<array<string, mixed>> $lines */
    private function claim(array $lines): ExpenseClaim
    {
        return app(ExpenseClaims::class)->enter(
            entity: $this->entity(),
            claimantUserId: $this->claimant,
            claimedOn: $this->date->copy(),
            currency: 'SAR',
            lines: $lines,
        );
    }

    /** @return array<string, mixed> */
    private function line(
        string $category,
        string $net,
        string $tax,
        bool $receipt = false,
        ?string $explanation = null,
    ): array {
        return [
            'category' => $category,
            'description' => sprintf('A %s expense', $category),
            'incurred_on' => $this->date->copy()->subDays(3)->toDateString(),
            'net_amount' => $net,
            'tax_amount' => $tax,
            'tax_code_id' => $this->taxCodeId,
            'tax_category' => 'S',
            'tax_rate' => '15.000000',
            'expense_account_id' => $this->expenseAccountId,
            'receipt_attachment_id' => $receipt ? (string) Str::ulid() : null,
            'policy_explanation' => $explanation,
        ];
    }

    private function policy(
        string $category,
        bool $recoverable = true,
        ?string $rate = null,
        ?string $rateUnit = null,
        ?string $ceilingPerLine = null,
        string $receiptRequiredAbove = '0',
        string $from = '2026-01-01',
        ?string $to = null,
    ): ExpensePolicy {
        return ExpensePolicy::create([
            'entity_id' => $this->entityId,
            'category' => $category,
            'currency' => 'SAR',
            'rate' => $rate,
            'rate_unit' => $rateUnit,
            'ceiling_per_line' => $ceilingPerLine,
            'receipt_required_above' => $receiptRequiredAbove,
            'recoverable_percent' => $recoverable ? '100' : '0',
            'expense_account_id' => $this->expenseAccountId,
            'effective_from' => CarbonImmutable::parse($from)->toDateString(),
            'effective_to' => $to === null ? null : CarbonImmutable::parse($to)->toDateString(),
            'active' => true,
        ]);
    }

    private function rule(string $code): ApprovalRule
    {
        $rule = ApprovalRule::create([
            'entity_id' => $this->entityId,
            'code' => $code,
            'name_ar' => 'قاعدة اعتماد المصروفات',
            'name_en' => 'An expense claim approval rule',
            'priority' => 100,
            'active' => true,
        ]);

        ApprovalRuleStep::create([
            'entity_id' => $this->entityId,
            'approval_rule_id' => (string) $rule->getKey(),
            'sequence' => 1,
            'quorum' => 1,
            'role' => 'purchasing.manager',
        ]);

        return $rule;
    }

    /** A claim entered, approved and posted - the ordinary path. */
    private function posted(): ExpenseClaim
    {
        $this->rule('EXP-'.Str::upper(Str::random(5)));

        return $this->approveAndPost(
            $this->claim([$this->line('travel', '400.0000', '60.0000', receipt: true)]),
        );
    }

    private function approveAndPost(ExpenseClaim $claim): ExpenseClaim
    {
        if (ApprovalRule::query()->where('entity_id', $this->entityId)->count() === 0) {
            $this->rule('EXP-'.Str::upper(Str::random(5)));
        }

        $request = app(ExpenseClaims::class)->submit($claim, $this->claimant);

        app(ApprovalEngine::class)->decide(
            request: $request,
            userId: $this->manager,
            decision: 'approved',
            currentFields: app(ExpenseClaims::class)->fieldsOf($claim->refresh()->load('lines')),
            userRoles: ['purchasing.manager'],
        );

        $claim = app(ExpenseClaims::class)->recordApproval($claim, $request->refresh());

        return app(ExpenseClaims::class)->post(
            $claim,
            $this->entity(),
            $this->date->copy(),
            $this->manager,
        );
    }

    /** @return list<array<string, mixed>> */
    private function linesOfEntry(string $entryId): array
    {
        return DB::connection('tenant')->table('journal_lines as l')
            ->leftJoin('accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('account_roles as r', 'r.account_id', '=', 'a.id')
            ->where('l.journal_entry_id', $entryId)
            ->select(['l.account_id', 'l.amount', 'r.role'])
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /** @param list<array<string, mixed>> $lines */
    private function amountOnRole(array $lines, string $role): string
    {
        $total = BigDecimal::zero();

        foreach ($lines as $line) {
            if ((string) ($line['role'] ?? '') === $role) {
                $total = $total->plus((string) $line['amount']);
            }
        }

        return (string) $total->toScale(4);
    }

    /** @param list<array<string, mixed>> $lines */
    private function amountOnAccount(array $lines, string $accountId): string
    {
        $total = BigDecimal::zero();

        foreach ($lines as $line) {
            if ((string) ($line['account_id'] ?? '') === $accountId) {
                $total = $total->plus((string) $line['amount']);
            }
        }

        return (string) $total->toScale(4);
    }
}
