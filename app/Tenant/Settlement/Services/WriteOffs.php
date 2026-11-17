<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Identity\Services\SodEngine;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Settlement\Enums\PaymentDirection;
use App\Tenant\Settlement\Enums\PaymentMethod;
use App\Tenant\Settlement\Exceptions\PaymentRefused;
use App\Tenant\Settlement\Posting\Rules\RecoveryPostedRule;
use App\Tenant\Settlement\Posting\Rules\WriteOffPostedRule;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Concluding that a debt will not be paid - PF-172.
 *
 * ## The one act in this module that destroys value on purpose
 *
 * Every other path moves money between accounts or names a difference.
 * This one says a receivable is not going to be collected and the money
 * is gone - which is why it is permissioned, reasoned by code, approved
 * above a threshold and separated by duty from both invoicing and
 * reconciliation.
 *
 * Not because writing off is rare. It is ordinary. It is because a person
 * who can raise an invoice and then write it off can move money out of a
 * business with two clicks and leave nothing for a reader to find.
 *
 * ## A code and a sentence, not one or the other
 *
 * A sentence is read once, by the person who approved it. A code is
 * countable: "how much did we write off after liquidation this year, and
 * how does that compare with last" is the question a provision matrix is
 * calibrated from, and prose cannot answer it.
 *
 * The sentence is kept because a code cannot say which customer and which
 * conversation. Without both, the report is either uncountable or
 * unexplained.
 *
 * ## And a recovery is a new receipt
 *
 * Money that arrives afterwards credits a recovery account. Reversing the
 * write-off would say the conclusion had been wrong, and it was not: on
 * the day it was made, with what was known, it was right. Netting the two
 * also destroys the only question that improves those decisions - whether
 * the business writes off too eagerly or too late.
 */
final class WriteOffs
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Poster $poster,
        private readonly Settings $settings,
        private readonly SodEngine $sod,
        private readonly NumberAllocator $numbers,
        private readonly Allocations $allocations,
        private readonly Payments $payments,
    ) {}

    /** What one person may write off without a second name on it. */
    public function threshold(string $entityId): Money
    {
        $entity = Entity::query()->findOrFail($entityId);

        return Money::of(
            (string) $this->settings->get('settlement.write_off_second_approver_above', $entityId),
            (string) $entity->functional_currency,
        );
    }

    /**
     * Write a document off.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function record(
        string $entityId,
        string $documentType,
        string $documentId,
        Money $amount,
        string $reasonCode,
        string $reason,
        string $userId,
        ?string $approvedBy = null,
        ?string $partyId = null,
        ?Carbon $on = null,
    ): array {
        if (mb_strlen(trim($reason)) < 40) {
            throw PaymentRefused::because(
                'A write-off needs a reason beside its code, at the length of a sentence. The code '.
                'is what the report counts; the sentence is what says which customer and which '.
                'conversation, and it is read during an audit of money the business decided to '.
                'lose.',
            );
        }

        if (! $amount->isPositive()) {
            throw PaymentRefused::because('A write-off is a positive amount of money being given up.');
        }

        $code = $this->codeOr404($reasonCode);
        $open = $this->allocations->openBalance($documentType, $documentId, $amount->currency);

        if ($amount->amount->isGreaterThan($open->amount)) {
            throw PaymentRefused::because(sprintf(
                'This document has %s left on it and the write-off is for %s. Writing off more '.
                'than is owed would take the document below zero - a credit nobody granted, on a '.
                'customer nobody sold anything more to.',
                (string) $open->toPresentation(),
                (string) $amount->toPresentation(),
            ));
        }

        $threshold = $this->threshold($entityId);
        $needsASecond = $amount->amount->isGreaterThan($threshold->amount);

        if ($needsASecond && $approvedBy === null) {
            throw PaymentRefused::because(sprintf(
                'This write-off is %s and one person may write off up to %s on their own. Above '.
                'that it takes a second name, because writing off a debt is the one act here that '.
                'destroys value on purpose - and a threshold that applied to everything would be '.
                'an approval step people click through.',
                (string) $amount->toPresentation(),
                (string) $threshold->toPresentation(),
            ));
        }

        if ($needsASecond && $approvedBy === $userId) {
            throw PaymentRefused::because(
                'The approver cannot be the person who recorded it. An approval somebody gave '.
                'themselves is the control performed by the person it exists to check.',
            );
        }

        /*
         | The duty, asserted here rather than on a route.
         |
         | The same argument as every other duty in this codebase: this
         | method is reached from a controller, a console command and
         | whatever calls it next, and a middleware protects only the
         | first of those.
         |
         | Scoped to the document, because the pair that matters is on one
         | invoice: a person may reconcile a thousand lines and still
         | write off an invoice they never touched.
         */
        $this->sod->assert(
            duty: 'writeoff.record',
            subjectType: $documentType,
            subjectId: $documentId,
            subjectLabel: sprintf('%s of %s', $code->code, (string) $amount->toPresentation()),
            partyId: $partyId,
        );

        $entity = Entity::query()->findOrFail($entityId);
        $on ??= Carbon::now();

        return DB::connection('tenant')->transaction(function () use (
            $entityId,
            $entity,
            $documentType,
            $documentId,
            $amount,
            $code,
            $reason,
            $userId,
            $approvedBy,
            $needsASecond,
            $partyId,
            $threshold,
            $on,
        ): array {
            $number = $this->numbers->next($entityId, 'write_off', 'WOF', $on);

            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: WriteOffPostedRule::KEY,
                event: [
                    'amount' => (string) $amount->amount,
                    'party_id' => $partyId,
                    'payable' => $documentType === 'bill',
                    'narration' => sprintf('%s (%s): %s', $number, $code->code, $reason),
                ],
                accountingDate: $on,
                journalCode: 'GEN',
                sourceType: $documentType,
                sourceId: $documentId,
                narration: $reason,
            );

            $id = (string) Str::ulid();

            DB::connection('tenant')->table('write_offs')->insert([
                'id' => $id,
                'entity_id' => $entityId,
                'number' => $number,
                'document_type' => $documentType,
                'document_id' => $documentId,
                'party_id' => $partyId,
                'amount' => (string) $amount->amount,
                'currency' => $amount->currency,
                'written_off_on' => $on->toDateString(),
                'reason_code_id' => (string) $code->id,
                'reason' => $reason,
                'recorded_by' => $userId,
                'recorded_at' => now(),
                /*
                 | The threshold as it stood, stored beside the decision.
                 |
                 | The same reasoning as the suspense sign-off and the
                 | difference tolerance: a tenant who raises it next week
                 | would otherwise make every past write-off look as
                 | though it had always been within one signature.
                 */
                'threshold' => (string) $threshold->amount,
                'approved_by' => $needsASecond ? $approvedBy : null,
                'approved_at' => $needsASecond ? now() : null,
                'journal_entry_id' => (string) $entry->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'settlement',
                action: 'writeoff.recorded',
                subjectType: $documentType,
                subjectId: $documentId,
                subjectLabel: sprintf('%s %s', $number, (string) $amount->toPresentation()),
                after: [
                    'amount' => (string) $amount->amount,
                    'currency' => $amount->currency,
                    'reason_code' => (string) $code->code,
                    'threshold' => (string) $threshold->amount,
                    'approved_by' => $needsASecond ? $approvedBy : null,
                    'journal_entry_id' => (string) $entry->getKey(),
                ],
                reason: $reason,
                entityId: $entityId,
                partyId: $partyId,
            );

            return [
                'id' => $id,
                'number' => $number,
                'journal_entry_id' => (string) $entry->getKey(),
                'why' => sprintf(
                    '%s written off as %s. The document is closed, not deleted - and if the money '.
                    'ever arrives it is a new receipt against a recovery account, because the '.
                    'conclusion was right on the day it was made.',
                    (string) $amount->toPresentation(),
                    $code->label_en,
                ),
            ];
        });
    }

    /**
     * Money that arrived after a write-off.
     *
     * A new receipt against a recovery account, never a reversal.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function recover(
        string $writeOffId,
        Money $amount,
        string $bankAccountId,
        string $userId,
        ?string $note = null,
        ?Carbon $on = null,
    ): array {
        $writeOff = DB::connection('tenant')->table('write_offs')->where('id', $writeOffId)->first();

        if ($writeOff === null) {
            throw PaymentRefused::because('There is no write-off with that id.');
        }

        if (! $amount->isPositive()) {
            throw PaymentRefused::because('A recovery is a positive amount of money arriving.');
        }

        if ($amount->currency !== (string) $writeOff->currency) {
            throw PaymentRefused::because(sprintf(
                'This recovery is in %s and the write-off was in %s. Comparing them would be '.
                'arithmetic between two different units.',
                $amount->currency,
                (string) $writeOff->currency,
            ));
        }

        $alreadyRecovered = $this->recoveredSoFar($writeOffId, $amount->currency);
        $left = Money::of((string) $writeOff->amount, $amount->currency)->minus($alreadyRecovered);

        if ($amount->amount->isGreaterThan($left->amount)) {
            /*
             | A recovery cannot exceed what was written off.
             |
             | Money beyond it is not a recovery - it is a payment on
             | account, or a payment against a different document, and
             | recording it here would credit income for money the
             | business may owe back.
             */
            throw PaymentRefused::because(sprintf(
                '%s was written off and %s of it has already been recovered, so %s is left to '.
                'recover and this is for %s. Money beyond that is not a recovery: it is a payment '.
                'on account, and recording it here would credit income for money that may be owed '.
                'back.',
                (string) Money::of((string) $writeOff->amount, $amount->currency)->toPresentation(),
                (string) $alreadyRecovered->toPresentation(),
                (string) $left->toPresentation(),
                (string) $amount->toPresentation(),
            ));
        }

        $entityId = (string) $writeOff->entity_id;
        $entity = Entity::query()->findOrFail($entityId);
        $on ??= Carbon::now();
        $partyId = $writeOff->party_id === null ? null : (string) $writeOff->party_id;

        return DB::connection('tenant')->transaction(function () use (
            $writeOff,
            $writeOffId,
            $entityId,
            $entity,
            $amount,
            $bankAccountId,
            $userId,
            $note,
            $on,
            $partyId,
            $left,
        ): array {
            /*
             | An ordinary receipt first.
             |
             | The money really did arrive in a bank account, and that is
             | the same fact as any other receipt - so it goes through the
             | same path, with the same immutability and the same
             | reconciliation behaviour. What makes it a recovery is the
             | second entry, which moves it out of unapplied cash into
             | income rather than against a document.
             */
            $payment = $this->payments->record(
                entityId: $entityId,
                direction: PaymentDirection::Receipt,
                method: PaymentMethod::CreditTransfer,
                amount: $amount,
                valueDate: $on,
                userId: $userId,
                bankAccountId: $bankAccountId,
                partyId: $partyId,
                note: sprintf('Recovery of written-off debt %s', (string) $writeOff->number),
            );

            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: RecoveryPostedRule::KEY,
                event: [
                    'amount' => (string) $amount->amount,
                    'party_id' => $partyId,
                    'narration' => sprintf('Recovery of %s', (string) $writeOff->number),
                ],
                accountingDate: $on,
                journalCode: 'GEN',
                sourceType: 'write_off',
                sourceId: $writeOffId,
                narration: $note,
            );

            $number = $this->numbers->next($entityId, 'recovery', 'REC', $on);
            $id = (string) Str::ulid();

            DB::connection('tenant')->table('recoveries')->insert([
                'id' => $id,
                'entity_id' => $entityId,
                'number' => $number,
                'write_off_id' => $writeOffId,
                'party_id' => $partyId,
                'amount' => (string) $amount->amount,
                'currency' => $amount->currency,
                'recovered_on' => $on->toDateString(),
                'payment_id' => (string) $payment['id'],
                'note' => $note,
                'recorded_by' => $userId,
                'recorded_at' => now(),
                'journal_entry_id' => (string) $entry->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'settlement',
                action: 'writeoff.recovered',
                subjectType: 'write_off',
                subjectId: $writeOffId,
                subjectLabel: sprintf('%s %s', $number, (string) $amount->toPresentation()),
                after: [
                    'amount' => (string) $amount->amount,
                    'payment_id' => (string) $payment['id'],
                    'journal_entry_id' => (string) $entry->getKey(),
                ],
                reason: $note,
                entityId: $entityId,
                partyId: $partyId,
            );

            return [
                'id' => $id,
                'number' => $number,
                'payment_id' => (string) $payment['id'],
                'journal_entry_id' => (string) $entry->getKey(),
                'left_to_recover' => $left->minus($amount),
                'why' => sprintf(
                    '%s recovered as income, against the recovery account. The write-off stands: '.
                    'it was right on the day it was made, and keeping the two apart is what makes '.
                    '"we wrote off X and recovered Y" a sentence about how good those decisions '.
                    'were.',
                    (string) $amount->toPresentation(),
                ),
            ];
        });
    }

    /** What has already come back on a write-off. */
    public function recoveredSoFar(string $writeOffId, string $currency): Money
    {
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(amount), 0)::text AS recovered
            FROM recoveries
            WHERE write_off_id = ?
        SQL, [$writeOffId]);

        return Money::of((string) ($row->recovered ?? '0'), $currency);
    }

    /**
     * Write-offs by reason, by period and by customer - PF-172.
     *
     * All three, because each answers a different question and only the
     * three together are worth reading. By reason: which kinds of debt
     * fail, which is what a provision matrix is built from. By period:
     * whether it is getting worse. By customer: whether it is one
     * relationship or a pattern.
     *
     * @return array<string, mixed>
     */
    public function report(string $entityId, ?string $from = null, ?string $until = null): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT c.code AS reason_code,
                   c.label_en AS reason_label,
                   to_char(w.written_off_on, 'YYYY-MM') AS period,
                   w.party_id,
                   p.legal_name AS party_name,
                   count(*) AS items,
                   sum(w.amount)::text AS total,
                   max(w.currency) AS currency,
                   coalesce(sum((
                       SELECT sum(r.amount)
                       FROM recoveries r
                       WHERE r.write_off_id = w.id
                   )), 0)::text AS recovered
            FROM write_offs w
            JOIN write_off_reason_codes c ON c.id = w.reason_code_id
            LEFT JOIN parties p ON p.id = w.party_id
            WHERE w.entity_id = ?
              AND (?::date IS NULL OR w.written_off_on >= ?::date)
              AND (?::date IS NULL OR w.written_off_on < ?::date)
            GROUP BY c.code, c.label_en, to_char(w.written_off_on, 'YYYY-MM'), w.party_id, p.legal_name
            ORDER BY to_char(w.written_off_on, 'YYYY-MM'), c.code
        SQL, [$entityId, $from, $from, $until, $until]);

        $byReason = [];
        $byPeriod = [];
        $byCustomer = [];
        $total = null;
        $recovered = null;

        foreach ($rows as $row) {
            $currency = (string) $row->currency;
            $amount = Money::of((string) $row->total, $currency);
            $back = Money::of((string) $row->recovered, $currency);

            $total ??= Money::zero($currency);
            $recovered ??= Money::zero($currency);
            $total = $total->plus($amount);
            $recovered = $recovered->plus($back);

            $reason = (string) $row->reason_code;
            $byReason[$reason] ??= ['label' => (string) $row->reason_label, 'total' => Money::zero($currency), 'items' => 0];
            $byReason[$reason]['total'] = $byReason[$reason]['total']->plus($amount);
            $byReason[$reason]['items'] += (int) $row->items;

            $period = (string) $row->period;
            $byPeriod[$period] ??= Money::zero($currency);
            $byPeriod[$period] = $byPeriod[$period]->plus($amount);

            $customer = $row->party_name === null ? '(no customer)' : (string) $row->party_name;
            $byCustomer[$customer] ??= Money::zero($currency);
            $byCustomer[$customer] = $byCustomer[$customer]->plus($amount);
        }

        return [
            'by_reason' => $byReason,
            'by_period' => $byPeriod,
            'by_customer' => $byCustomer,
            'total' => $total,
            'recovered' => $recovered,
            'why' => $total === null
                ? 'Nothing was written off in this window.'
                : sprintf(
                    '%s written off across %d reason(s) and %d month(s), and %s of it has come '.
                    'back. The two are reported separately on purpose: netted, nobody can tell a '.
                    'business that writes off too eagerly from one that writes off too late.',
                    (string) $total->toPresentation(),
                    \count($byReason),
                    \count($byPeriod),
                    (string) ($recovered?->toPresentation() ?? '0'),
                ),
        ];
    }

    /** @throws PaymentRefused */
    private function codeOr404(string $code): object
    {
        $row = DB::connection('tenant')->table('write_off_reason_codes')
            ->where('code', $code)
            ->where('active', true)
            ->first();

        if ($row === null) {
            $known = DB::connection('tenant')->table('write_off_reason_codes')
                ->where('active', true)
                ->orderBy('code')
                ->pluck('code')
                ->all();

            throw PaymentRefused::because(sprintf(
                '"%s" is not a write-off reason code in use here. The known ones are %s - and a '.
                'free-text reason instead of a code is what makes "which kinds of debt fail" '.
                'unanswerable. Add a code if none of these fits.',
                $code,
                implode(', ', $known),
            ));
        }

        return $row;
    }
}
