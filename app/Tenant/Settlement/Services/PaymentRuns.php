<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Authorization\RequiresStepUp;
use App\Support\Money\Money;
use App\Tenant\Banking\Models\BankAccount;
use App\Tenant\Identity\Services\SodEngine;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Settlement\Exceptions\PaymentRefused;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The payment run - PF-174.
 *
 * ## Three states, and exactly three transitions
 *
 *   draft → prepared     somebody selected what to pay
 *   prepared → draft     the selection was wrong, and is being redone
 *   prepared → released  somebody else sent it
 *
 * There is no way out of `released`. A mistake is corrected by a new run
 * and a recall request: the money has left, and a system that let the
 * instruction be edited afterwards would describe a payment the bank
 * never made. The database enforces all of it, because this is the table
 * where money leaves the business and a service is one code path.
 *
 * ## Discounts are evaluated at preparation and then left alone
 *
 * Taking early-settlement discounts is usually the run's whole purpose,
 * so the figures are frozen when the run is prepared. A run prepared on
 * Tuesday and released on Thursday pays what Tuesday's evaluation said;
 * recomputing on release would quietly change the amount the releaser
 * approved, which is the one thing maker-checker exists to prevent.
 *
 * A discount whose window has closed by the pay date is **not taken**,
 * and the item says so. Deducting it anyway is the mistake that turns a
 * supplier relationship into a dispute over a figure nobody can explain.
 *
 * ## Grouping and splitting are different answers to different questions
 *
 * Grouping: four invoices to one supplier become one transfer, because
 * four transfers cost four fees and arrive as four lines the supplier has
 * to match. Splitting: one transfer above a limit becomes several,
 * because the limit is the bank's or the beneficiary's and a rejected
 * file is worse than two transfers.
 *
 * Both are recorded. A supplier who received three transfers instead of
 * one is asking a question the run should answer without anybody reading
 * the code.
 */
final class PaymentRuns
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly NumberAllocator $numbers,
        private readonly SodEngine $sod,
        private readonly ReleaseReview $review,
        private readonly RequiresStepUp $stepUp,
        private readonly DuplicatePaymentDetector $duplicates,
        private readonly \App\Tenant\Ledger\Services\Poster $poster,
    ) {}

    /**
     * What a run would pay, without creating one.
     *
     * PF-174 asks for a preview of the cash impact, and the reason is
     * that a payment run is the largest single movement of money most
     * businesses make in a week. Somebody has to see the figure before
     * they commit to it, and seeing it afterwards is not the same thing.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function preview(string $entityId, string $bankAccountId, Carbon $payOn, array $criteria = []): array
    {
        $account = $this->accountOr404($entityId, $bankAccountId);
        $currency = (string) $account->currency;

        $candidates = $this->selectPayables($entityId, $currency, $payOn, $criteria);

        $gross = Money::zero($currency);
        $discount = Money::zero($currency);
        $items = [];
        $blocked = [];

        foreach ($candidates as $bill) {
            $beneficiary = $this->beneficiaryFor($entityId, (string) $bill->party_id, $currency);

            if ($beneficiary['account_id'] === null) {
                /*
                 | Reported, not silently dropped.
                 |
                 | A supplier with no verified account is the commonest
                 | reason a payable does not get paid, and a run that
                 | left them out without saying so is a run whose author
                 | discovers the omission when the supplier chases.
                 */
                $blocked[] = [
                    'bill_id' => (string) $bill->id,
                    'party_id' => (string) $bill->party_id,
                    'why' => $beneficiary['why'],
                ];

                continue;
            }

            $evaluated = $this->evaluateDiscount($bill, $payOn, $currency);

            $gross = $gross->plus($evaluated['gross']);
            $discount = $discount->plus($evaluated['discount']);

            $items[] = [
                'bill_id' => (string) $bill->id,
                'party_id' => (string) $bill->party_id,
                'party_name' => $bill->party_name === null ? null : (string) $bill->party_name,
                'party_bank_account_id' => $beneficiary['account_id'],
                'account_fingerprint' => $beneficiary['fingerprint'],
                'groups' => $beneficiary['groups'],
                'limit' => $beneficiary['limit'],
                'due_on' => $evaluated['due_on'],
                'gross' => $evaluated['gross'],
                'discount' => $evaluated['discount'],
                'net' => $evaluated['net'],
                'discount_percent' => $evaluated['percent'],
                'discount_until' => $evaluated['until'],
                'why' => $evaluated['why'],
            ];
        }

        $instructions = $this->planInstructions($items, $currency);
        $net = $gross->minus($discount);

        return [
            'currency' => $currency,
            'pay_on' => $payOn->toDateString(),
            'items' => $items,
            'instructions' => $instructions,
            'blocked' => $blocked,
            'gross' => $gross,
            'discount' => $discount,
            'net' => $net,
            'why' => $items === []
                ? sprintf(
                    'Nothing to pay on %s under these criteria%s.',
                    $payOn->toDateString(),
                    $blocked === []
                        ? ''
                        : sprintf(', and %d payable(s) were left out for want of a verified account', \count($blocked)),
                )
                : sprintf(
                    '%s leaves the account on %s: %s of payables less %s of early-settlement '.
                    'discount, across %d instruction(s) to %d beneficiar%s.%s',
                    (string) $net->toPresentation(),
                    $payOn->toDateString(),
                    (string) $gross->toPresentation(),
                    (string) $discount->toPresentation(),
                    \count($instructions),
                    \count(array_unique(array_column($instructions, 'party_id'))),
                    \count(array_unique(array_column($instructions, 'party_id'))) === 1 ? 'y' : 'ies',
                    $blocked === []
                        ? ''
                        : sprintf(
                            ' %d payable(s) were left out for want of a verified account.',
                            \count($blocked),
                        ),
                ),
        ];
    }

    /**
     * Prepare a run: freeze the selection and the discounts.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function prepare(
        string $entityId,
        string $bankAccountId,
        Carbon $payOn,
        string $userId,
        array $criteria = [],
        ?string $note = null,
    ): array {
        $preview = $this->preview($entityId, $bankAccountId, $payOn, $criteria);

        if ($preview['items'] === []) {
            throw PaymentRefused::because(sprintf(
                'There is nothing to pay on %s under these criteria, so there is no run to '.
                'prepare. An empty run would be a prepared instruction for nothing, and somebody '.
                'would release it.',
                $payOn->toDateString(),
            ));
        }

        return DB::connection('tenant')->transaction(function () use (
            $entityId,
            $bankAccountId,
            $payOn,
            $userId,
            $criteria,
            $note,
            $preview,
        ): array {
            $number = $this->numbers->next($entityId, 'payment_run', 'RUN', $payOn);
            $runId = (string) Str::ulid();

            DB::connection('tenant')->table('payment_runs')->insert([
                'id' => $runId,
                'entity_id' => $entityId,
                'number' => $number,
                'state' => 'draft',
                'bank_account_id' => $bankAccountId,
                'currency' => (string) $preview['currency'],
                'pay_on' => $payOn->toDateString(),
                'criteria' => json_encode($criteria, JSON_THROW_ON_ERROR),
                'gross_total' => (string) $preview['gross']->amount,
                'discount_total' => (string) $preview['discount']->amount,
                'net_total' => (string) $preview['net']->amount,
                'item_count' => \count($preview['items']),
                'instruction_count' => \count($preview['instructions']),
                'note' => $note,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $instructionIds = [];

            foreach ($preview['instructions'] as $index => $instruction) {
                $instructionId = (string) Str::ulid();
                $instructionIds[$index] = $instructionId;

                DB::connection('tenant')->table('payment_run_instructions')->insert([
                    'id' => $instructionId,
                    'entity_id' => $entityId,
                    'run_id' => $runId,
                    'party_id' => (string) $instruction['party_id'],
                    'party_bank_account_id' => $instruction['party_bank_account_id'],
                    'amount' => (string) $instruction['amount']->amount,
                    'currency' => (string) $preview['currency'],
                    'sequence' => (int) $instruction['sequence'],
                    'of' => (int) $instruction['of'],
                    'split_reason' => $instruction['split_reason'],
                    /*
                     | What the destination looked like, now - PF-175.
                     |
                     | So the releaser can be told whether it has moved
                     | since, by content rather than by a clock that only
                     | counts seconds.
                     */
                    'account_fingerprint' => $instruction['account_fingerprint'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($preview['items'] as $item) {
                DB::connection('tenant')->table('payment_run_items')->insert([
                    'id' => (string) Str::ulid(),
                    'entity_id' => $entityId,
                    'run_id' => $runId,
                    'instruction_id' => $instructionIds[$this->instructionIndexFor($preview['instructions'], $item)] ?? null,
                    'bill_id' => (string) $item['bill_id'],
                    'party_id' => (string) $item['party_id'],
                    'gross_amount' => (string) $item['gross']->amount,
                    'discount_amount' => (string) $item['discount']->amount,
                    'net_amount' => (string) $item['net']->amount,
                    'currency' => (string) $preview['currency'],
                    'due_on' => $item['due_on'],
                    'discount_percent' => $item['discount_percent'],
                    'discount_until' => $item['discount_until'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            /*
             | The state moves after the lines exist.
             |
             | A run that was `prepared` for an instant with no lines is a
             | run somebody could have released, and the window is however
             | long the inserts take. Draft first, then prepared, inside
             | one transaction.
             */
            DB::connection('tenant')->table('payment_runs')->where('id', $runId)->update([
                'state' => 'prepared',
                'prepared_by' => $userId,
                'prepared_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'settlement',
                action: 'payment_run.prepared',
                subjectType: 'payment_run',
                subjectId: $runId,
                subjectLabel: sprintf('%s %s', $number, (string) $preview['net']->toPresentation()),
                after: [
                    'gross' => (string) $preview['gross']->amount,
                    'discount' => (string) $preview['discount']->amount,
                    'net' => (string) $preview['net']->amount,
                    'items' => \count($preview['items']),
                    'instructions' => \count($preview['instructions']),
                    'blocked' => \count($preview['blocked']),
                    'pay_on' => $payOn->toDateString(),
                ],
                reason: $note,
                entityId: $entityId,
            );

            /*
             | Duplicates, raised NOW as well as at release - PF-180.
             |
             | Somebody has to be able to act on a warning before the
             | day the money leaves: finding out at release that a
             | sister company is paying the same invoice means either
             | releasing late or accepting it under time pressure, and
             | the second is what happens.
             |
             | `release()` looks again, because the world moves in
             | between - and that look is the one that blocks.
             */
            $this->duplicates->examine($runId);

            return [
                'id' => $runId,
                'number' => $number,
                'state' => 'prepared',
                'gross' => $preview['gross'],
                'discount' => $preview['discount'],
                'net' => $preview['net'],
                'instructions' => \count($preview['instructions']),
                'blocked' => $preview['blocked'],
                'why' => sprintf(
                    '%s prepared: %s to leave the account on %s. The discounts are frozen as they '.
                    'were evaluated today - releasing it tomorrow pays what was approved rather '.
                    'than what a recomputation would say.',
                    $number,
                    (string) $preview['net']->toPresentation(),
                    $payOn->toDateString(),
                ),
            ];
        });
    }

    /**
     * Send the run - the highest-consequence act in the product - PF-175.
     *
     * Five controls, in the order they are cheapest to explain:
     *
     * 1. The run is prepared. A draft has nothing anybody checked.
     * 2. The releaser is not the preparer - by the column AND against the
     *    audit trail, because a column is one row somebody can repair.
     * 3. The duty pairs, which can never be overridden - per
     *    beneficiary, because one of them is scoped to the party.
     * 4. A fresh factor, now, for this run. A stolen session passes every
     *    check that looks at the session and fails this one.
     * 5. Nothing has moved since preparation. What the releaser approves
     *    has to be what was prepared, or maker-checker is two people
     *    clicking the same button a day apart.
     *
     * The order is not arbitrary. Everything that answers "you may not do
     * this at all" comes before the factor, because asking somebody to
     * re-prove themselves for an act they can never perform wastes a code
     * and reads as the system not knowing its own rules. The review comes
     * after, because it names what the run is paying and whoever holds
     * the session has not yet shown they are the account's owner.
     *
     * Then the hash of what was authorised, so the file PF-176 generates
     * can be held against it.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function release(string $runId, string $userId, ?string $note = null): array
    {
        /*
         | `payment.release` is the one trace you needed - PF-240.
         |
         | The first name on `telemetry.sampling.always_sample`, and the
         | reason that list exists: a sampled-away payment release is found
         | to have been sampled away at the moment somebody asks what
         | happened to the money. The span wraps the whole act, including the
         | maker-checker refusal, because "it was refused" is the answer as
         | often as "it took four minutes".
         */
        return app(\App\Support\Telemetry\Tracer::class)->measure(
            'payment.release',
            \App\Support\Telemetry\SpanKind::Internal,
            ['reference' => $runId],
            fn (): array => $this->releaseNow($runId, $userId, $note),
        );
    }

    /** @return array<string, mixed> */
    private function releaseNow(string $runId, string $userId, ?string $note = null): array
    {
        $run = $this->runOr404($runId);

        if ((string) $run->state !== 'prepared') {
            throw PaymentRefused::because(sprintf(
                'Run %s is %s. Only a prepared run can be released: a draft has nothing anybody '.
                'has checked, and a released one has already gone.',
                (string) $run->number,
                (string) $run->state,
            ));
        }

        if ((string) $run->prepared_by === $userId) {
            throw PaymentRefused::because(
                'The person who prepared a run cannot release it. Maker-checker on outgoing funds '.
                'is two people, not one - and this is the pair that can never be overridden, '.
                'because arranging where money goes and then sending it is the highest-value fraud '.
                'in the product.',
            );
        }

        /*
         | And against the audit trail, which the column is not - PF-175.
         |
         | `prepared_by` is one column on one row. A repaired row, a
         | restored backup or an import that cleared it would leave the
         | check above comparing against null and passing - and the trail
         | is the record that says what actually happened, in a hash
         | chain nobody can quietly edit.
         |
         | Scoped to this run. Preparing one run does not bar somebody
         | from releasing another, and a control that did would stop a
         | two-person finance team working at all.
         */
        $prepared = DB::connection('tenant')->table('audit_log')
            ->where('actor_user_id', $userId)
            ->where('action', 'payment_run.prepared')
            ->where('subject_type', 'payment_run')
            ->where('subject_id', $runId)
            ->where('category', '!=', 'sod')
            ->orderByDesc('created_at')
            ->first();

        if ($prepared !== null) {
            throw PaymentRefused::because(sprintf(
                'The audit trail records you preparing run %s on %s, so you cannot be the one to '.
                'release it. Maker-checker on outgoing funds is two people, not one - and this is '.
                'the pair that can never be overridden, because arranging where money goes and then '.
                'sending it is the highest-value fraud in the product.',
                (string) $run->number,
                (string) $prepared->created_at,
            ));
        }

        /*
         | Duplicates, re-examined NOW - PF-180.
         |
         | Not at preparation only. The world moves between preparing a
         | run and releasing it: another entity prepares its own run,
         | somebody records a payment by hand, a second copy of the same
         | invoice is entered. The look that matters is the last one
         | before the money leaves.
         |
         | Warnings somebody already accepted survive the re-examination,
         | because they signed for those - asking again would lose the
         | signature and train people to click through.
         */
        $this->duplicates->examine($runId);
        $unacknowledged = $this->duplicates->unacknowledged($runId);

        if ($unacknowledged !== []) {
            throw PaymentRefused::aRunWithUnacceptedDuplicates(
                (string) $run->number,
                array_map(static fn (array $w): string => (string) $w['detail'], $unacknowledged),
            );
        }

        /*
         | The duties - once per beneficiary, not once for the run.
         |
         | Two rules sit on `payment.release` and they are scoped
         | differently. `prepare_then_release` is scoped to the OBJECT:
         | did you prepare this run. `beneficiary_then_release` is scoped
         | to the PARTY: did you change this supplier's bank details -
         | which is the pair PF-061 exists for, and the other one that can
         | never be overridden.
         |
         | A run pays many beneficiaries, so there is no single party id
         | for it. Asserting once with none left the engine unable to
         | evaluate the party-scoped rule at all: it refused outright
         | rather than skipping, which is the right behaviour and is how
         | this was found - by the first check that signed a real user in.
         |
         | So the assertion runs per distinct beneficiary, carrying both
         | keys, and both rules can see what they are scoped to.
         */
        $beneficiaries = DB::connection('tenant')->table('payment_run_instructions')
            ->where('run_id', $runId)
            ->distinct()
            ->pluck('party_id');

        foreach ($beneficiaries as $partyId) {
            $this->sod->assert(
                duty: 'payment.release',
                subjectType: 'payment_run',
                subjectId: $runId,
                partyId: (string) $partyId,
                subjectLabel: (string) $run->number,
            );
        }

        /*
         | A fresh factor, now, for this run - PF-032, PF-175.
         |
         | Asserted here rather than on a route, because this method is
         | reached from a console command, a queued job and an API
         | endpoint that does not exist yet - each of which is a route a
         | middleware does not protect. Object-scoped, so re-proving
         | yourself to release Tuesday's run does not authorise releasing
         | Thursday's in the same five minutes.
         */
        $this->stepUp->assert('payment.release', $runId, (string) $run->number);

        /*
         | And nothing has moved since it was prepared - PF-175.
         |
         | After step-up, deliberately: a stale run is a thing the
         | releaser needs to be told about, and telling them before they
         | have proved who they are would leak what a run is paying to
         | whoever holds the session.
         */
        $review = $this->review->of($runId);

        if ($review['blocks']) {
            throw PaymentRefused::because($review['why']);
        }

        return DB::connection('tenant')->transaction(function () use ($run, $runId, $userId, $note, $review): array {
            /*
             | The payables leave AP and sit in transit - PF-179.
             |
             | Posted per instruction, because that is the granularity a
             | rejection arrives at: the bank refuses one beneficiary and
             | accepts the rest, and reversing a single release entry
             | would unwind the ones that are going to clear.
             |
             | Inside the release transaction, so there is no instant in
             | which a released run has no postings - a reconciliation run
             | in that window would report the whole batch as an
             | unexplained difference.
             */
            $entity = \App\Tenant\Tenancy\Models\Entity::query()->findOrFail((string) $run->entity_id);
            $posted = 0;

            foreach (DB::connection('tenant')->table('payment_run_instructions')
                ->where('run_id', $runId)
                ->orderBy('id')
                ->get() as $instruction) {
                $entry = $this->poster->post(
                    entity: $entity,
                    ruleKey: \App\Tenant\Settlement\Posting\Rules\RunReleasedRule::KEY,
                    event: [
                        'amount' => (string) $instruction->amount,
                        'party_id' => (string) $instruction->party_id,
                        'narration' => sprintf('Released in run %s', (string) $run->number),
                    ],
                    /*
                     | Dated on the pay date, which is the date the run is
                     | for and the date on every document it produces.
                     |
                     | Which means a run whose pay date falls in a period
                     | nobody has opened cannot be released - and that
                     | refusal is correct rather than inconvenient: money
                     | committed into a period that is not open is an
                     | entry the ledger would have to accept into
                     | somewhere nobody looks. The refusal names the
                     | period, so the answer is to open it or move the
                     | date.
                     */
                    accountingDate: \Illuminate\Support\Carbon::parse((string) $run->pay_on),
                    journalCode: 'CSH',
                    sourceType: 'payment_run_instruction',
                    sourceId: (string) $instruction->id,
                );

                DB::connection('tenant')->table('payment_run_instructions')
                    ->where('id', (string) $instruction->id)
                    ->update(['transit_entry_id' => (string) $entry->getKey(), 'updated_at' => now()]);

                $posted++;
            }

            DB::connection('tenant')->table('payment_runs')->where('id', $runId)->update([
                'state' => 'released',
                'released_by' => $userId,
                'released_at' => now(),
                /*
                 | What was authorised, hashed - PF-175.
                 |
                 | Written in the same statement as the release, so there
                 | is no instant in which a released run has no hash. The
                 | run is frozen immediately afterwards, which makes this
                 | the last moment the figure can be recorded at all.
                 */
                'released_hash' => $review['hash'],
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'settlement',
                action: 'payment_run.released',
                subjectType: 'payment_run',
                subjectId: $runId,
                subjectLabel: sprintf('%s %s %s', (string) $run->number, (string) $run->currency, (string) $run->net_total),
                before: ['state' => 'prepared', 'prepared_by' => (string) $run->prepared_by],
                after: [
                    'state' => 'released',
                    'net' => (string) $run->net_total,
                    'instructions' => (int) $run->instruction_count,
                    'released_hash' => $review['hash'],
                ],
                reason: $note,
                entityId: (string) $run->entity_id,
            );

            return [
                'id' => $runId,
                'number' => (string) $run->number,
                'state' => 'released',
                'released_hash' => $review['hash'],
                'why' => sprintf(
                    '%s released, and recorded under %s - the hash of what was authorised, so the '.
                    'file generated from it can be held against this. It is frozen now, lines and '.
                    'all: a mistake is corrected by a new run and a recall request, because the '.
                    'money has left and an edited instruction would describe a payment the bank '.
                    'never made.',
                    (string) $run->number,
                    mb_substr($review['hash'], 0, 12),
                ),
            ];
        });
    }

    /**
     * Send a prepared run back to draft, invalidating the preparation.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function unprepare(string $runId, string $userId, string $reason): array
    {
        if (mb_strlen(trim($reason)) <= 10) {
            throw PaymentRefused::because(
                'Unpreparing a run needs a reason. It withdraws a selection somebody was about to '.
                'release, and the next preparation may differ - which is exactly what a releaser '.
                'needs to be told.',
            );
        }

        $run = $this->runOr404($runId);

        if ((string) $run->state !== 'prepared') {
            throw PaymentRefused::because(sprintf(
                'Run %s is %s, so there is no preparation to withdraw.',
                (string) $run->number,
                (string) $run->state,
            ));
        }

        return DB::connection('tenant')->transaction(function () use ($run, $runId, $userId, $reason): array {
            DB::connection('tenant')->table('payment_runs')->where('id', $runId)->update([
                'state' => 'draft',
                'prepared_by' => null,
                'prepared_at' => null,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'settlement',
                action: 'payment_run.unprepared',
                subjectType: 'payment_run',
                subjectId: $runId,
                subjectLabel: (string) $run->number,
                before: ['state' => 'prepared', 'prepared_by' => (string) $run->prepared_by],
                after: ['state' => 'draft'],
                reason: $reason,
                entityId: (string) $run->entity_id,
            );

            return [
                'id' => $runId,
                'state' => 'draft',
                'why' => 'The preparation is withdrawn, and nobody can release what was prepared. '.
                    'Preparing it again is an ordinary thing to do - and the releaser will be '.
                    'shown a preparation nobody has touched since.',
            ];
        });
    }

    /**
     * What a releaser is about to approve - PF-175.
     *
     * The run, what changed since it was prepared, and whether a factor
     * is needed before the button does anything. For a screen that asks
     * the question before somebody commits rather than after: a release
     * refused at the last step is a release somebody has already decided
     * to make, and the diff arriving then reads as an obstacle rather
     * than as information.
     *
     * @return array<string, mixed>
     */
    public function beforeRelease(string $runId, string $userId): array
    {
        $run = $this->runOr404($runId);
        $review = $this->review->of($runId);

        $prepared = DB::connection('tenant')->table('audit_log')
            ->where('actor_user_id', $userId)
            ->where('action', 'payment_run.prepared')
            ->where('subject_type', 'payment_run')
            ->where('subject_id', $runId)
            ->where('category', '!=', 'sod')
            ->exists();

        $isThePreparer = $prepared || (string) $run->prepared_by === $userId;

        return [
            ...$this->show($runId),
            'changes' => $review['changes'],
            'blocks' => $review['blocks'],
            'hash' => $review['hash'],
            'is_the_preparer' => $isThePreparer,
            'needs_a_factor' => ! $this->stepUp->isSatisfied('payment.release', $runId),
            'releasable' => ! $review['blocks'] && ! $isThePreparer && (string) $run->state === 'prepared',
            'why' => $isThePreparer
                ? sprintf(
                    'You prepared %s, so you cannot release it. Somebody else has to look at what '.
                    'you selected - which is the whole of the control.',
                    (string) $run->number,
                )
                : $review['why'],
        ];
    }

    /** A run and what it will pay. @return array<string, mixed> */
    public function show(string $runId): array
    {
        $run = $this->runOr404($runId);
        $currency = (string) $run->currency;

        $instructions = DB::connection('tenant')->table('payment_run_instructions as i')
            ->leftJoin('parties as p', 'p.id', '=', 'i.party_id')
            ->where('i.run_id', $runId)
            ->orderBy('p.legal_name')
            ->orderBy('i.sequence')
            ->get(['i.id', 'i.party_id', 'i.amount', 'i.sequence', 'i.of', 'i.split_reason', 'p.legal_name']);

        $items = DB::connection('tenant')->table('payment_run_items')
            ->where('run_id', $runId)
            ->orderBy('due_on')
            ->get();

        return [
            'id' => $runId,
            'number' => (string) $run->number,
            'state' => (string) $run->state,
            'pay_on' => (string) $run->pay_on,
            'currency' => $currency,
            'gross' => Money::of((string) $run->gross_total, $currency),
            'discount' => Money::of((string) $run->discount_total, $currency),
            'net' => Money::of((string) $run->net_total, $currency),
            'instructions' => $instructions->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'party' => $row->legal_name === null ? null : (string) $row->legal_name,
                'amount' => Money::of((string) $row->amount, $currency),
                'sequence' => (int) $row->sequence,
                'of' => (int) $row->of,
                'split_reason' => $row->split_reason === null ? null : (string) $row->split_reason,
            ])->all(),
            'items' => $items->map(static fn (object $row): array => [
                'bill_id' => (string) $row->bill_id,
                'gross' => Money::of((string) $row->gross_amount, $currency),
                'discount' => Money::of((string) $row->discount_amount, $currency),
                'net' => Money::of((string) $row->net_amount, $currency),
                'due_on' => $row->due_on === null ? null : (string) $row->due_on,
                'discount_percent' => $row->discount_percent === null ? null : (string) $row->discount_percent,
                'discount_until' => $row->discount_until === null ? null : (string) $row->discount_until,
            ])->all(),
            'prepared_by' => $run->prepared_by === null ? null : (string) $run->prepared_by,
            'released_by' => $run->released_by === null ? null : (string) $run->released_by,
        ];
    }

    /**
     * Released runs a bank line might be settling - PF-156's second pass.
     *
     * @return list<array<string, mixed>>
     */
    public function releasedOn(string $entityId, string $currency, Carbon $from, Carbon $until): array
    {
        $rows = DB::connection('tenant')->table('payment_runs')
            ->where('entity_id', $entityId)
            ->where('currency', $currency)
            ->where('state', 'released')
            ->whereBetween('pay_on', [$from->toDateString(), $until->toDateString()])
            ->orderBy('pay_on')
            ->get();

        return $rows->map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'number' => (string) $row->number,
            'pay_on' => (string) $row->pay_on,
            'net' => Money::of((string) $row->net_total, $currency),
            'instruction_count' => (int) $row->instruction_count,
        ])->all();
    }

    // ---- Selection, discounts, grouping -----------------------------------

    /**
     * Approved payables this run could settle.
     *
     * @param  array<string, mixed>  $criteria
     * @return list<object>
     */
    private function selectPayables(string $entityId, string $currency, Carbon $payOn, array $criteria): array
    {
        $horizon = isset($criteria['due_by'])
            ? Carbon::parse((string) $criteria['due_by'])
            : $payOn->copy()->addDays((int) ($criteria['horizon_days'] ?? 0));

        $parties = $criteria['party_ids'] ?? null;
        $chasingDiscounts = ($criteria['include_discount_opportunities'] ?? true) === true;

        return DB::connection('tenant')->select(<<<'SQL'
            SELECT b.id,
                   b.party_id,
                   p.legal_name AS party_name,
                   b.terms_snapshot,
                   peakflow_document_open_balance('bill', b.id)::text AS open_balance,
                   coalesce((
                       SELECT max((d->>'date')::date)
                       FROM jsonb_array_elements(b.terms_snapshot->'due_dates') d
                   ), b.supplier_document_date) AS due_on,
                   (b.terms_snapshot->'discount'->>'until')::date AS discount_until
            FROM bills b
            LEFT JOIN parties p ON p.id = b.party_id
            WHERE b.entity_id = ?
              AND b.currency = ?
              /*
               | Approved only.
               |
               | A run that could select an unapproved bill is a run that
               | pays what nobody checked, and the approval is the whole
               | control on the purchasing side.
               */
              AND b.approved_at IS NOT NULL
              AND peakflow_document_open_balance('bill', b.id) > 0
              /*
               | And not already in a run that is going out or has gone.
               |
               | A payment run does not reduce a bill's open balance -
               | the payment does, and that is PF-176's business - so
               | without this a bill selected into Monday's released run
               | is selected again by Tuesday's, and both can be
               | released. The supplier is paid twice and the second
               | debit looks as ordinary as the first.
               |
               | Prepared runs count too. A bill sitting in a prepared
               | run is a bill somebody is about to release, and two runs
               | that both hold it is the same duplicate arriving a day
               | earlier.
               |
               | A draft does not: nothing has been checked and nothing
               | can be released, so blocking on one would let an
               | abandoned draft hold payables hostage with nobody able
               | to see why they are missing.
               */
              AND NOT EXISTS (
                  SELECT 1
                  FROM payment_run_items pri
                  JOIN payment_runs pr ON pr.id = pri.run_id
                  WHERE pri.bill_id = b.id
                    AND pr.state IN ('prepared', 'released')
              )
              AND (
                  /*
                   | Due by the horizon, OR offering a discount that
                   | expires by the pay date.
                   |
                   | The second is why a run exists at all in most
                   | businesses: paying early to take two percent is the
                   | highest-return decision a finance team makes in a
                   | week, and a selection that only looked at due dates
                   | would miss every one of them.
                   */
                  coalesce((
                      SELECT max((d->>'date')::date)
                      FROM jsonb_array_elements(b.terms_snapshot->'due_dates') d
                  ), b.supplier_document_date) <= ?::date
                  OR (
                      ?::boolean
                      AND (b.terms_snapshot->'discount'->>'until')::date >= ?::date
                  )
              )
              AND (?::jsonb IS NULL OR b.party_id = ANY (
                  SELECT jsonb_array_elements_text(?::jsonb)
              ))
            ORDER BY coalesce((
                SELECT max((d->>'date')::date)
                FROM jsonb_array_elements(b.terms_snapshot->'due_dates') d
            ), b.supplier_document_date), b.id
        SQL, [
            $entityId,
            $currency,
            $horizon->toDateString(),
            $chasingDiscounts ? 'true' : 'false',
            $payOn->toDateString(),
            $parties === null ? null : json_encode($parties, JSON_THROW_ON_ERROR),
            $parties === null ? null : json_encode($parties, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * The discount this bill offers, if the pay date is inside its window.
     *
     * @return array<string, mixed>
     */
    private function evaluateDiscount(object $bill, Carbon $payOn, string $currency): array
    {
        $open = Money::of((string) $bill->open_balance, $currency);
        $terms = json_decode((string) ($bill->terms_snapshot ?? '{}'), true, 512, JSON_THROW_ON_ERROR) ?: [];
        $discount = $terms['discount'] ?? null;

        $none = [
            'gross' => $open,
            'discount' => Money::zero($currency),
            'net' => $open,
            'percent' => null,
            'until' => null,
            'due_on' => $bill->due_on === null ? null : (string) $bill->due_on,
        ];

        if (! \is_array($discount) || ($discount['percent'] ?? null) === null || ($discount['until'] ?? null) === null) {
            return [...$none, 'why' => 'these terms offer no early-settlement discount'];
        }

        $until = Carbon::parse((string) $discount['until']);

        if ($payOn->gt($until)) {
            /*
             | The window has closed, so the discount is not taken.
             |
             | Deducting it anyway is the mistake that turns a supplier
             | relationship into a dispute over a figure nobody can
             | explain - and the supplier is right.
             */
            return [...$none, 'why' => sprintf(
                'the %s%% discount expired on %s and this run pays on %s, so it is not taken',
                (string) $discount['percent'],
                $until->toDateString(),
                $payOn->toDateString(),
            )];
        }

        $taken = BigDecimal::of($open->amount)
            ->multipliedBy((string) $discount['percent'])
            ->dividedBy('100', 4, RoundingMode::HalfUp);

        $amount = Money::of((string) $taken, $currency);

        return [
            'gross' => $open,
            'discount' => $amount,
            'net' => $open->minus($amount),
            'percent' => (string) $discount['percent'],
            'until' => $until->toDateString(),
            'due_on' => $bill->due_on === null ? null : (string) $bill->due_on,
            'why' => sprintf(
                '%s%% is available until %s and this run pays on %s, so %s is taken - which is why '.
                'the run exists',
                (string) $discount['percent'],
                $until->toDateString(),
                $payOn->toDateString(),
                (string) $amount->toPresentation(),
            ),
        ];
    }

    /**
     * Who to pay, and how the beneficiary wants it.
     *
     * @return array{account_id: string|null, fingerprint: string|null, groups: bool, limit: string|null, why: string}
     */
    private function beneficiaryFor(string $entityId, string $partyId, string $currency): array
    {
        /*
         | Only an active, verified account in the run's currency.
         |
         | `requires_reverification` is set when the details changed, and
         | paying into an account whose change nobody confirmed is the
         | fraud this whole area is built against - so it is not a
         | warning, it is an exclusion.
         |
         | The flag alone, not the flag and `verified_at`: the table holds
         | the two to a single meaning
         | (`party_bank_accounts_verification_state_is_coherent`), so a
         | second condition here would be a guard against a state the
         | database refuses - which is a guard nobody can test. A bite
         | sweep found exactly that: removing either condition changed
         | nothing.
         */
        $account = DB::connection('tenant')->table('party_bank_accounts')
            ->where('party_id', $partyId)
            ->where('active', true)
            ->whereNull('deleted_at')
            ->where('requires_reverification', false)
            ->where(function ($query) use ($currency): void {
                $query->where('currency', $currency)->orWhereNull('currency');
            })
            ->orderByDesc('verified_at')
            ->first();

        if ($account === null) {
            $any = DB::connection('tenant')->table('party_bank_accounts')
                ->where('party_id', $partyId)
                ->whereNull('deleted_at')
                ->first();

            return [
                'account_id' => null,
                'fingerprint' => null,
                'groups' => false,
                'limit' => null,
                'why' => $any === null
                    ? 'this supplier has no bank account on file, so there is nowhere to pay them'
                    : ($any->requires_reverification
                        ? 'this supplier\'s bank details changed and nobody has re-verified them - '.
                          'paying into an account whose change was never confirmed is the fraud '.
                          'this is built against'
                        : 'this supplier has no active, verified account in '.$currency),
            ];
        }

        $settings = DB::connection('tenant')->table('party_entity_settings')
            ->where('entity_id', $entityId)
            ->where('party_id', $partyId)
            ->first();

        return [
            'account_id' => (string) $account->id,
            'fingerprint' => ReleaseReview::fingerprintOf($account),
            /*
             | Grouping is the beneficiary's preference, not ours.
             |
             | Default on, because four transfers cost four fees and
             | arrive as four lines the supplier has to match. A supplier
             | who reconciles per invoice says so, and then they get one
             | transfer each.
             */
            'groups' => ($settings->group_payments ?? true) !== false,
            'limit' => $settings->payment_limit ?? null,
            'why' => sprintf('paying into the account ending %s', (string) $account->last_four),
        ];
    }

    /**
     * One instruction per beneficiary, split where a limit requires it.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function planInstructions(array $items, string $currency): array
    {
        $byBeneficiary = [];

        foreach ($items as $item) {
            /*
             | A supplier who does not group gets one instruction per bill.
             |
             | Keyed on the bill rather than the party, so the grouping
             | decision is made here once rather than branched on twice.
             */
            $key = $item['groups'] === true
                ? $item['party_id'].'|'.$item['party_bank_account_id']
                : $item['party_id'].'|'.$item['party_bank_account_id'].'|'.$item['bill_id'];

            $byBeneficiary[$key] ??= [
                'party_id' => $item['party_id'],
                'party_bank_account_id' => $item['party_bank_account_id'],
                'account_fingerprint' => $item['account_fingerprint'],
                'limit' => $item['limit'],
                'amount' => Money::zero($currency),
                'bill_ids' => [],
            ];

            $byBeneficiary[$key]['amount'] = $byBeneficiary[$key]['amount']->plus($item['net']);
            $byBeneficiary[$key]['bill_ids'][] = $item['bill_id'];
        }

        $instructions = [];

        foreach ($byBeneficiary as $group) {
            $limit = $group['limit'] === null ? null : BigDecimal::of((string) $group['limit']);

            if ($limit === null || ! $limit->isPositive() || ! $group['amount']->amount->isGreaterThan($limit)) {
                $instructions[] = [
                    ...$group,
                    'sequence' => 1,
                    'of' => 1,
                    'split_reason' => null,
                ];

                continue;
            }

            /*
             | Split into whole instructions at the limit.
             |
             | The last one carries the remainder. A rejected file is
             | worse than two transfers: the bank returns the whole batch
             | and the supplier is paid nothing, which is discovered when
             | they chase.
             */
            $parts = [];
            $left = $group['amount']->amount;

            while ($left->isPositive()) {
                $slice = $left->isGreaterThan($limit) ? $limit : $left;
                $parts[] = $slice;
                $left = $left->minus($slice);
            }

            foreach ($parts as $index => $slice) {
                $instructions[] = [
                    ...$group,
                    'amount' => Money::of((string) $slice, $currency),
                    'sequence' => $index + 1,
                    'of' => \count($parts),
                    'split_reason' => sprintf('beneficiary payment limit of %s', (string) $limit),
                ];
            }
        }

        return $instructions;
    }

    /**
     * Which instruction an item belongs to.
     *
     * @param  list<array<string, mixed>>  $instructions
     * @param  array<string, mixed>  $item
     */
    private function instructionIndexFor(array $instructions, array $item): int
    {
        foreach ($instructions as $index => $instruction) {
            if (\in_array($item['bill_id'], $instruction['bill_ids'], true)) {
                return $index;
            }
        }

        return 0;
    }

    /** @throws PaymentRefused */
    private function accountOr404(string $entityId, string $bankAccountId): BankAccount
    {
        $account = BankAccount::query()
            ->where('entity_id', $entityId)
            ->where('id', $bankAccountId)
            ->first();

        if ($account === null) {
            throw PaymentRefused::because('That bank account does not belong to this entity.');
        }

        return $account;
    }

    /** @throws PaymentRefused */
    private function runOr404(string $runId): object
    {
        $run = DB::connection('tenant')->table('payment_runs')->where('id', $runId)->first();

        if ($run === null) {
            throw PaymentRefused::because('There is no payment run with that id.');
        }

        return $run;
    }
}
