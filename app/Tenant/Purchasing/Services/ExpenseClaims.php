<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Purchasing\Exceptions\BillRefused;
use App\Tenant\Purchasing\Models\ApprovalRequest;
use App\Tenant\Purchasing\Models\ExpenseClaim;
use App\Tenant\Purchasing\Models\ExpenseClaimLine;
use App\Tenant\Purchasing\Models\ExpensePolicy;
use App\Tenant\Purchasing\Posting\Rules\ExpenseClaimPostedRule;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Employee expense claims - PF-143.
 *
 * ## Input tax follows the receipt, and the category, and the day
 *
 * Three conditions, and they fail differently:
 *
 * - **No receipt**: no evidence. The authority asks for receipts rather than
 *   for a spreadsheet, and a return whose input box rests on an employee's
 *   word cannot be supported.
 * - **The category does not permit it**: no entitlement, however good the
 *   evidence. Client entertainment is the usual example.
 * - **The policy on the day the expense was incurred**: a rate or a rule
 *   that has since changed is not the rule that applied.
 *
 * The decision and its reason are written onto the line, because "why was
 * this claimed" has to be answerable a year later - when the policy has
 * changed and the return has been filed.
 *
 * ## Per-diem and mileage rates are frozen onto the line
 *
 * 240 kilometres at the rate that applied in November, not at today's. A
 * claim re-derived at the current rate pays the wrong amount and cannot be
 * reconciled to what was approved - the same reasoning as a bill's payment
 * terms, for the same reason.
 *
 * ## A policy breach is flagged, not refused
 *
 * A hotel over the ceiling because the conference hotel was the only one
 * left is a real claim, and refusing it sends somebody to a spreadsheet and
 * an email. So the line is flagged with the figures, an explanation is
 * required, and the approver decides - which is what the approval step is
 * for.
 *
 * ## The claimant cannot approve their own claim
 *
 * Through the same engine as a bill, which refuses `requested_by`. A claim
 * approval mechanism of its own would be a second set of limits to keep in
 * step, and the one that drifts is the one nobody is looking at.
 */
final class ExpenseClaims
{
    public function __construct(
        private readonly Poster $poster,
        private readonly NumberAllocator $numbers,
        private readonly ApprovalEngine $approvals,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Enter a claim, with its lines priced, policy-checked and tax-decided.
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws BillRefused
     */
    public function enter(
        Entity $entity,
        string $claimantUserId,
        Carbon $claimedOn,
        string $currency,
        array $lines,
        ?Carbon $periodFrom = null,
        ?Carbon $periodTo = null,
        ?string $note = null,
    ): ExpenseClaim {
        if ($lines === []) {
            throw BillRefused::because('A claim with no lines claims nothing.');
        }

        $entityId = (string) $entity->getKey();

        return DB::connection('tenant')->transaction(function () use (
            $entity, $entityId, $claimantUserId, $claimedOn, $currency, $lines,
            $periodFrom, $periodTo, $note,
        ): ExpenseClaim {
            $claim = ExpenseClaim::create([
                'entity_id' => $entityId,
                'number' => $this->numbers->next($entityId, 'expense_claim', 'EXP', $claimedOn),
                'status' => 'draft',
                'claimant_user_id' => $claimantUserId,
                'claimed_on' => $claimedOn->toDateString(),
                'period_from' => $periodFrom?->toDateString(),
                'period_to' => $periodTo?->toDateString(),
                'currency' => $currency,
            ]);

            $net = Money::zero($currency);
            $tax = Money::zero($currency);
            $recoverable = Money::zero($currency);

            foreach ($lines as $index => $input) {
                $line = $this->writeLine($claim, $input, $index, $currency);

                $net = $net->plus($line->net($currency));
                $tax = $tax->plus(Money::of((string) $line->tax_amount, $currency));
                $recoverable = $recoverable->plus($line->recoverableTax($currency));
            }

            $claim->update([
                'net_amount' => (string) $net->amount,
                'tax_amount' => (string) $tax->amount,
                'gross_amount' => (string) $net->plus($tax)->amount,
                'recoverable_tax_amount' => (string) $recoverable->amount,
                'non_recoverable_tax_amount' => (string) $tax->minus($recoverable)->amount,
                'note' => $note,
            ]);

            $claim = $claim->refresh()->load('lines');

            $this->audit->record(
                category: 'purchasing',
                action: 'expense_claim.submitted',
                subjectType: 'expense_claim',
                subjectId: (string) $claim->getKey(),
                subjectLabel: $claim->label(),
                after: [
                    'claimant' => $claimantUserId,
                    'gross' => (string) $claim->gross_amount,
                    'currency' => $currency,
                    'recoverable_tax' => (string) $claim->recoverable_tax_amount,
                    'lines' => \count($lines),
                    'policy_exceptions' => \count($claim->exceptions()),
                ],
                entityId: $entityId,
            );

            return $claim;
        });
    }

    /**
     * Submit it for approval, through the bill engine.
     *
     * @throws BillRefused
     */
    public function submit(ExpenseClaim $claim, ?string $userId = null): ApprovalRequest
    {
        $claim = $claim->refresh()->load('lines');

        if (! $claim->isDraft()) {
            throw BillRefused::because(sprintf(
                'Claim %s is %s, so it is not waiting to be submitted.',
                $claim->label(),
                (string) $claim->status,
            ));
        }

        /*
         | An unexplained policy breach does not reach an approver.
         |
         | The approver is the person the policy exists to inform, and a
         | flag with no text is a line somebody approves without knowing
         | what is wrong with it. The database agrees, so this is the
         | message rather than the guarantee.
         */
        foreach ($claim->lines as $line) {
            if ($line->policy_exception !== null
                && \strlen(trim((string) $line->policy_explanation)) <= 5) {
                throw BillRefused::because(sprintf(
                    'Line %d breaches the %s policy (%s) and carries no explanation. The approver is '.
                    'the person the policy exists to inform, and a flag with no text is a line '.
                    'somebody approves without knowing what is wrong with it.',
                    (int) $line->line_no,
                    (string) $line->category,
                    (string) $line->policy_exception,
                ));
            }
        }

        $request = $this->approvals->request(
            entityId: (string) $claim->entity_id,
            subjectType: ExpenseClaim::SUBJECT_TYPE,
            subjectId: (string) $claim->getKey(),
            currency: (string) $claim->currency,
            amount: (string) $claim->gross_amount,
            fingerprintFields: $this->fieldsOf($claim),
            requestedBy: $userId ?? (string) $claim->claimant_user_id,
        );

        $claim->update(['status' => 'awaiting_approval', 'submitted_at' => now()]);

        return $request;
    }

    /**
     * Record that the chain approved it.
     *
     * @throws BillRefused
     */
    public function recordApproval(ExpenseClaim $claim, ApprovalRequest $request): ExpenseClaim
    {
        $claim = $claim->refresh();
        $request = $request->refresh();

        if ((string) $request->subject_id !== (string) $claim->getKey()) {
            throw BillRefused::because(
                'That approval belongs to another document. Marking a claim approved from somebody '.
                "else's chain is an approval nobody gave.",
            );
        }

        if (! $request->isApproved()) {
            throw BillRefused::because(sprintf(
                'The approval chain for %s is %s, not approved.',
                $claim->label(),
                (string) $request->status,
            ));
        }

        $decider = DB::connection('tenant')->table('approval_decisions as d')
            ->join('approval_steps as s', 's.id', '=', 'd.approval_step_id')
            ->where('s.approval_request_id', (string) $request->getKey())
            ->where('d.decision', 'approved')
            ->orderByDesc('d.decided_at')
            ->value('d.user_id');

        $claim->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $decider === null ? null : (string) $decider,
        ]);

        $this->audit->record(
            category: 'purchasing',
            action: 'expense_claim.approved',
            subjectType: 'expense_claim',
            subjectId: (string) $claim->getKey(),
            subjectLabel: $claim->label(),
            after: [
                'approval_request_id' => (string) $request->getKey(),
                'gross' => (string) $claim->gross_amount,
            ],
            entityId: (string) $claim->entity_id,
        );

        return $claim->refresh();
    }

    /**
     * Post an approved claim.
     *
     * @throws BillRefused
     */
    public function post(ExpenseClaim $claim, Entity $entity, Carbon $postingDate, ?string $userId = null): ExpenseClaim
    {
        $claim = $claim->refresh()->load('lines');

        if ($claim->isPosted()) {
            return $claim;
        }

        if (! $claim->isApproved()) {
            throw BillRefused::because(sprintf(
                'Claim %s is %s. Posting an unapproved claim owes somebody money for expenses nobody '.
                'agreed to, and the balance sits in the employee payable account with no decision '.
                'behind it.',
                $claim->label(),
                (string) $claim->status,
            ));
        }

        return DB::connection('tenant')->transaction(function () use ($claim, $entity, $postingDate, $userId): ExpenseClaim {
            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: ExpenseClaimPostedRule::KEY,
                event: $this->event($claim),
                accountingDate: $postingDate,
                journalCode: 'PUR',
                sourceType: 'expense_claim',
                sourceId: (string) $claim->getKey(),
                narration: sprintf('Expense claim %s', $claim->label()),
            );

            $claim->update([
                'status' => 'posted',
                'posting_date' => $postingDate->toDateString(),
                'posted_at' => now(),
                'journal_entry_id' => (string) $entry->getKey(),
            ]);

            $this->audit->record(
                category: 'purchasing',
                action: 'expense_claim.posted',
                subjectType: 'expense_claim',
                subjectId: (string) $claim->getKey(),
                subjectLabel: $claim->label(),
                after: [
                    'journal_entry_id' => (string) $entry->getKey(),
                    'gross' => (string) $claim->gross_amount,
                    'recoverable_tax' => (string) $claim->recoverable_tax_amount,
                    'posted_by' => $userId,
                ],
                entityId: (string) $claim->entity_id,
            );

            return $claim->refresh();
        });
    }

    /**
     * Reject it, with a reason the claimant can act on.
     *
     * @throws BillRefused
     */
    public function reject(ExpenseClaim $claim, string $userId, string $reason): ExpenseClaim
    {
        if (\strlen(trim($reason)) <= 5) {
            throw BillRefused::because(
                'A rejection needs a reason. The claimant is out of pocket and is going to ask, and '.
                '"rejected" with nothing behind it is what makes them re-submit the same claim.',
            );
        }

        $claim->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejected_by' => $userId,
            'rejection_reason' => trim($reason),
        ]);

        $this->audit->record(
            category: 'purchasing',
            action: 'expense_claim.rejected',
            subjectType: 'expense_claim',
            subjectId: (string) $claim->getKey(),
            subjectLabel: $claim->label(),
            after: ['rejected_by' => $userId],
            reason: trim($reason),
            entityId: (string) $claim->entity_id,
        );

        return $claim->refresh();
    }

    /**
     * What is owed to employees, by person - the control account's detail.
     *
     * The report that makes `employee_payable` reconcilable, and the reason
     * it is a control account of its own: "what do we owe our own people" is
     * a question somebody asks at a month end, and it cannot be answered
     * from a trade payables balance with colleagues mixed into it.
     *
     * @return list<array<string, mixed>>
     */
    public function owedToEmployees(string $entityId, ?Carbon $asOf = null): array
    {
        $day = ($asOf ?? Carbon::now())->copy()->startOfDay();

        return DB::connection('tenant')->table('expense_claims')
            ->where('entity_id', $entityId)
            ->where('status', 'posted')
            ->where('posting_date', '<=', $day->toDateString())
            ->whereRaw('gross_amount > paid_amount')
            ->groupBy('claimant_user_id', 'currency')
            ->selectRaw(
                'claimant_user_id, currency, count(*) AS claims, '.
                'sum(gross_amount - paid_amount) AS outstanding'
            )
            ->orderByRaw('sum(gross_amount - paid_amount) DESC')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * The policy in force for a category on a date.
     *
     * The most recently effective window that covers the day. Where several
     * do - which the table permits, because a policy can be superseded
     * mid-period - the latest `effective_from` wins, because that is the
     * decision somebody made most recently.
     */
    public function policyFor(string $entityId, string $category, Carbon $on): ?ExpensePolicy
    {
        return ExpensePolicy::query()
            ->where('entity_id', $entityId)
            ->where('category', $category)
            ->get()
            ->filter(static fn (ExpensePolicy $policy): bool => $policy->appliesOn($on))
            ->sortByDesc(static fn (ExpensePolicy $policy): string => $policy->effective_from->toDateString())
            ->first();
    }

    /**
     * The fields the approval fingerprint covers.
     *
     * The explanations are in it: an approver agreed to a breach on the
     * strength of a sentence, and changing the sentence afterwards is a
     * different claim. The claim's own note is not - it is correspondence.
     *
     * @return array<string, mixed>
     */
    public function fieldsOf(ExpenseClaim $claim): array
    {
        return [
            'currency' => (string) $claim->currency,
            'gross_amount' => (string) $claim->gross_amount,
            'recoverable_tax_amount' => (string) $claim->recoverable_tax_amount,
            'lines' => $claim->lines->map(static fn (ExpenseClaimLine $line): array => [
                'line_no' => (int) $line->line_no,
                'category' => (string) $line->category,
                'incurred_on' => $line->incurred_on->toDateString(),
                'description' => (string) $line->description,
                'net_amount' => (string) $line->net_amount,
                'tax_amount' => (string) $line->tax_amount,
                'recoverable_percent' => (string) $line->recoverable_percent,
                'receipt' => $line->receipt_attachment_id === null ? null : (string) $line->receipt_attachment_id,
                'policy_exception' => $line->policy_exception,
                'policy_explanation' => $line->policy_explanation,
            ])->all(),
        ];
    }

    /**
     * The posting event, built from the stored claim.
     *
     * @return array<string, mixed>
     */
    public function event(ExpenseClaim $claim): array
    {
        $currency = (string) $claim->currency;
        $expense = [];
        $recoverable = [];

        foreach ($claim->lines as $line) {
            $expense[] = [
                'account_id' => (string) $line->expense_account_id,
                'amount' => (string) $line->net($currency)->plus($line->nonRecoverableTax($currency))->amount,
                'tax_code_id' => $line->tax_code_id === null ? null : (string) $line->tax_code_id,
                'tax_point' => $line->tax_code_id === null ? null : $line->incurred_on->toDateString(),
                'dimensions' => $line->dimensions(),
            ];

            $claimable = $line->recoverableTax($currency);

            if ($claimable->isZero()) {
                continue;
            }

            $recoverable[] = [
                'jurisdiction' => 'SA',
                'amount' => (string) $claimable->amount,
                'tax_code_id' => $line->tax_code_id === null ? null : (string) $line->tax_code_id,
                'tax_point' => $line->tax_code_id === null ? null : $line->incurred_on->toDateString(),
            ];
        }

        return [
            'claim_id' => (string) $claim->getKey(),
            'claimant_user_id' => (string) $claim->claimant_user_id,
            'payable' => (string) $claim->gross_amount,
            'narration' => null,
            'expense' => $expense,
            'recoverable_tax' => $recoverable,
        ];
    }

    /**
     * Price a line, decide its tax, and check it against the policy.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws BillRefused
     */
    private function writeLine(ExpenseClaim $claim, array $input, int $index, string $currency): ExpenseClaimLine
    {
        $entityId = (string) $claim->entity_id;
        $category = (string) ($input['category'] ?? 'other');
        $incurredOn = Carbon::parse((string) ($input['incurred_on'] ?? $claim->claimed_on->toDateString()));
        $policy = $this->policyFor($entityId, $category, $incurredOn);

        $quantity = isset($input['quantity']) ? BigDecimal::of((string) $input['quantity']) : null;
        $rate = null;

        if ($quantity !== null) {
            /*
             | The rate from the policy on the day, frozen onto the line.
             |
             | A mileage rate changes annually. A claim for last November
             | re-derived at today's rate pays the wrong amount and cannot
             | be reconciled to what was approved.
             */
            if ($policy === null || ! $policy->hasRate()) {
                throw BillRefused::because(sprintf(
                    'Line %d claims %s units of %s and no policy in force on %s gives that category a '.
                    'rate. A per-unit claim without a rate is a figure somebody typed.',
                    $index + 1,
                    (string) $quantity,
                    $category,
                    $incurredOn->toDateString(),
                ));
            }

            $rate = BigDecimal::of((string) $policy->rate);
        }

        $net = $rate === null
            ? Money::of((string) ($input['net_amount'] ?? '0'), $currency)
            : Money::ofRounded(
                (string) $quantity->multipliedBy($rate),
                $currency,
                RoundingPolicy::halfUp(Scale::Amount),
            );

        $tax = Money::of((string) ($input['tax_amount'] ?? '0'), $currency);
        $receipt = $input['receipt_attachment_id'] ?? null;

        [$recoverablePercent, $decision] = $this->taxDecision($policy, $receipt !== null, $tax, $category);
        $exception = $this->policyException($policy, $net, $receipt !== null, $category);

        return ExpenseClaimLine::create([
            'entity_id' => $entityId,
            'expense_claim_id' => (string) $claim->getKey(),
            'line_no' => (int) ($input['line_no'] ?? $index + 1),
            'category' => $category,
            'incurred_on' => $incurredOn->toDateString(),
            'description' => (string) $input['description'],
            'quantity' => $quantity === null ? null : (string) $quantity,
            'rate' => $rate === null ? null : (string) $rate,
            'rate_unit' => $rate === null ? null : (string) $policy->rate_unit,
            'policy_id' => $policy === null ? null : (string) $policy->getKey(),
            'net_amount' => (string) $net->amount,
            'tax_code_id' => $input['tax_code_id'] ?? null,
            'tax_category' => $input['tax_category'] ?? null,
            'tax_rate' => (string) ($input['tax_rate'] ?? '0'),
            'tax_amount' => (string) $tax->amount,
            'recoverable_percent' => $recoverablePercent,
            'expense_account_id' => (string) ($input['expense_account_id']
                ?? $policy?->expense_account_id
                ?? throw BillRefused::aLineWithNoAccount()),
            'receipt_attachment_id' => $receipt,
            'tax_decision' => $decision,
            'policy_exception' => $exception,
            'policy_explanation' => $input['policy_explanation'] ?? null,
            'dimension_1' => $input['dimension_1'] ?? null,
            'dimension_2' => $input['dimension_2'] ?? null,
            'dimension_3' => $input['dimension_3'] ?? null,
            'dimension_4' => $input['dimension_4'] ?? null,
        ]);
    }

    /**
     * How much of a line's tax may be claimed, and why.
     *
     * @return array{0: string, 1: ?string}
     */
    private function taxDecision(?ExpensePolicy $policy, bool $hasReceipt, Money $tax, string $category): array
    {
        /*
         | One place the percentage comes from, and three ways to get zero.
         |
         | Measured: this was four branches each returning `['0', null]`
         | separately, and a bite that removed the entitlement branch
         | changed nothing - the policy's percentage was zero anyway, so the
         | branch was cosmetic rather than a control. Collapsing them leaves
         | one line that decides the figure, which is a line a check can
         | hold to account.
         */
        $percent = match (true) {
            // Nothing charged, nothing to recover.
            $tax->isZero() => '0',

            /*
             | No receipt, no recovery - whatever the policy says.
             |
             | The authority asks for the receipt rather than the
             | spreadsheet, and a return whose input box rests on an
             | employee's word cannot be supported.
             */
            ! $hasReceipt => '0',

            /*
             | No policy, no recovery either.
             |
             | A category nobody has decided about is not a category
             | somebody decided tax could be claimed on, and the safe
             | reading of "nobody decided" on a tax return is "do not
             | claim".
             */
            $policy === null => '0',

            /*
             | And otherwise the category's own entitlement, which is zero
             | for the ones that have none. There is no second column
             | saying "not recoverable": it would carry no information the
             | percentage does not, and a pair kept consistent by a
             | constraint is a pair somebody will eventually read only one
             | half of.
             */
            default => (string) $policy->recoverable_percent,
        };

        if (BigDecimal::of($percent)->isZero()) {
            return ['0', null];
        }

        return [
            $percent,
            sprintf(
                'Receipt attached and the %s policy in force from %s permits %s%% recovery.',
                $category,
                $policy->effective_from->toDateString(),
                rtrim(rtrim($percent, '0'), '.'),
            ),
        ];
    }

    /** What a person has to look at before approving this line. */
    private function policyException(?ExpensePolicy $policy, Money $net, bool $hasReceipt, string $category): ?string
    {
        if ($policy === null) {
            return sprintf('no %s policy is in force for the date this was incurred', $category);
        }

        if ($policy->receiptRequiredFor($net) && ! $hasReceipt) {
            return sprintf(
                'a receipt is required above %s and none is attached',
                (string) $policy->receipt_required_above,
            );
        }

        if ($policy->exceedsLineCeiling($net)) {
            return sprintf(
                'claimed %s against a %s ceiling of %s',
                (string) $net->amount,
                $category,
                (string) $policy->ceiling_per_line,
            );
        }

        return null;
    }
}
