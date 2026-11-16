<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Services;

use App\Support\Money\Money;
use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Purchasing\Models\BillDuplicateWarning;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * What a unique constraint cannot catch - PF-139.
 *
 * ## The two halves of the problem
 *
 * The **hard** half is the same supplier sending the same number twice, and
 * that is a unique index on `(entity, supplier, normalised number)`. It is in
 * the database rather than here because an application check is bypassed by
 * an import, a second worker or a retried job, and because this is the most
 * expensive error in accounts payable: it is usually discovered by the
 * supplier, after the money has gone.
 *
 * The **soft** half is the same invoice arriving under a different number -
 * a re-issued document, a statement line keyed as an invoice, a scan of a
 * copy. No constraint can refuse those, because sometimes they are genuinely
 * two purchases. So they are warnings, shown side by side, and acknowledging
 * one is a decision somebody makes and is answerable for.
 *
 * ## Why a warning and not a refusal
 *
 * A false refusal is worse than a warning. The warning asks a person and the
 * refusal does not: a supplier who genuinely bills the same amount on the
 * same day twice - two deliveries, two dockets - cannot be told by a system
 * that they did not.
 *
 * ## It runs twice
 *
 * At entry, and again at payment time. The duplicate frequently arrives
 * *after* the first one was approved, and by payment time nobody is looking
 * at the entry screen. A detector that only ran once would find the second
 * copy of a January invoice in January and miss the one that arrives in
 * March.
 */
final class DuplicateBillDetector
{
    /** How far either side of a bill's date a near-match is looked for. */
    private const WINDOW_DAYS = 45;

    /** How close two amounts have to be to be worth asking about. */
    private const AMOUNT_TOLERANCE_PERCENT = '1.0';

    /**
     * Look for near-matches and record a warning for each.
     *
     * @param  string  $stage  `entry`, `approval` or `payment`
     * @return list<BillDuplicateWarning>
     */
    public function examine(Bill $bill, string $stage = 'entry'): array
    {
        $candidates = Bill::query()
            ->where('entity_id', (string) $bill->entity_id)
            ->where('party_id', (string) $bill->party_id)
            ->whereKeyNot($bill->getKey())
            ->whereIn('status', ['draft', 'awaiting_approval', 'approved', 'posted'])
            ->whereBetween('supplier_document_date', [
                $bill->supplier_document_date->copy()->subDays(self::WINDOW_DAYS)->toDateString(),
                $bill->supplier_document_date->copy()->addDays(self::WINDOW_DAYS)->toDateString(),
            ])
            ->get();

        $warnings = [];

        foreach ($candidates as $candidate) {
            foreach ($this->reasonsAgainst($bill, $candidate) as $reason => $detail) {
                $warnings[] = $this->record($bill, $candidate, (string) $reason, $detail, $stage);
            }
        }

        /*
         | And the same number from a DIFFERENT supplier.
         |
         | Outside the per-supplier window above, because the pattern is not
         | a duplicate purchase: it is a bill entered against the wrong
         | supplier, which pays the right amount to the wrong account. The
         | unique constraint cannot see it - the numbers are unique per
         | supplier - and it is the error that is hardest to recover from,
         | because the money has gone somewhere it was never owed.
         */
        foreach ($this->sameNumberElsewhere($bill) as $candidate) {
            $warnings[] = $this->record(
                $bill,
                $candidate,
                'same_number_other_supplier',
                sprintf(
                    'Document %s is also recorded against %s. A bill entered under the wrong supplier '.
                    'pays the right amount to the wrong account, which is the error that is hardest to '.
                    'get back.',
                    (string) $bill->supplier_document_number,
                    (string) ($candidate->supplierName() ?? $candidate->party_id),
                ),
                $stage,
            );
        }

        return array_values(array_filter($warnings));
    }

    /**
     * Why these two bills might be the same document.
     *
     * @return array<string, string>
     */
    private function reasonsAgainst(Bill $bill, Bill $candidate): array
    {
        $reasons = [];

        if ((string) $candidate->currency !== (string) $bill->currency) {
            /*
             | Different currencies are not compared.
             |
             | 1,000 USD and 1,000 EUR are not a near-match, and converting
             | them to compare would make every pair of bills from a
             | multi-currency supplier look suspicious.
             */
            return $reasons;
        }

        $mine = BigDecimal::of((string) $bill->gross_amount);
        $theirs = BigDecimal::of((string) $candidate->gross_amount);
        $sameDate = $bill->supplier_document_date->isSameDay($candidate->supplier_document_date);

        if ($mine->isEqualTo($theirs) && $sameDate) {
            $reasons['same_amount_and_date'] = sprintf(
                'Same supplier, same date (%s) and the same total (%s %s), under a different number '.
                '(%s against %s). This is the shape a re-issued invoice takes.',
                $bill->supplier_document_date->toDateString(),
                (string) $bill->currency,
                (string) Money::of((string) $bill->gross_amount, (string) $bill->currency)->toPresentation(),
                (string) $bill->supplier_document_number,
                (string) $candidate->supplier_document_number,
            );

            return $reasons;
        }

        if ($mine->isEqualTo($theirs)) {
            $reasons['same_total_different_number'] = sprintf(
                'Same supplier and the same total (%s %s) %d days apart, under a different number (%s '.
                'against %s).',
                (string) $bill->currency,
                (string) Money::of((string) $bill->gross_amount, (string) $bill->currency)->toPresentation(),
                abs($bill->supplier_document_date->diffInDays($candidate->supplier_document_date)),
                (string) $bill->supplier_document_number,
                (string) $candidate->supplier_document_number,
            );

            return $reasons;
        }

        if ($mine->isZero()) {
            return $reasons;
        }

        $difference = $mine->minus($theirs)->abs();
        $percent = $difference->dividedBy($mine->abs(), 6, RoundingMode::HalfUp)->multipliedBy('100');

        if ($percent->isLessThanOrEqualTo(BigDecimal::of(self::AMOUNT_TOLERANCE_PERCENT))) {
            $reasons['similar_amount_in_window'] = sprintf(
                'Same supplier and nearly the same total: %s against %s, a difference of %s (%s%%), '.
                '%d days apart. A re-issued invoice with a corrected rounding looks exactly like this.',
                (string) Money::of((string) $bill->gross_amount, (string) $bill->currency)->toPresentation(),
                (string) Money::of((string) $candidate->gross_amount, (string) $candidate->currency)->toPresentation(),
                (string) $difference,
                (string) $percent->toScale(2, RoundingMode::HalfUp),
                abs($bill->supplier_document_date->diffInDays($candidate->supplier_document_date)),
            );
        }

        return $reasons;
    }

    /** @return \Illuminate\Support\Collection<int, Bill> */
    private function sameNumberElsewhere(Bill $bill): \Illuminate\Support\Collection
    {
        $key = (string) $bill->supplier_document_key;

        if (trim($key) === '') {
            return collect();
        }

        return Bill::query()
            ->where('entity_id', (string) $bill->entity_id)
            ->where('supplier_document_key', $key)
            ->where('party_id', '!=', (string) $bill->party_id)
            ->whereKeyNot($bill->getKey())
            ->get();
    }

    /**
     * Record a warning, once per pair and reason.
     *
     * Idempotent, because the detector runs at entry and again at payment:
     * a second run must not produce a second warning for a pair somebody has
     * already acknowledged, or the acknowledgement would have to be given
     * again every time anybody looked.
     */
    private function record(Bill $bill, Bill $candidate, string $reason, string $detail, string $stage): ?BillDuplicateWarning
    {
        $existing = BillDuplicateWarning::query()
            ->where('bill_id', (string) $bill->getKey())
            ->where('suspected_bill_id', (string) $candidate->getKey())
            ->where('reason', $reason)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return BillDuplicateWarning::create([
            'entity_id' => (string) $bill->entity_id,
            'bill_id' => (string) $bill->getKey(),
            'suspected_bill_id' => (string) $candidate->getKey(),
            'reason' => $reason,
            'detail' => $detail,
            'amount_difference' => (string) BigDecimal::of((string) $bill->gross_amount)
                ->minus((string) $candidate->gross_amount)
                ->toScale(4, RoundingMode::HalfUp),
            'days_apart' => (int) $bill->supplier_document_date->diffInDays($candidate->supplier_document_date),
            'raised_at_stage' => $stage,
        ]);
    }

    /**
     * Acknowledge a warning, with a reason that is kept.
     *
     * The text is required and has to say something: "not a duplicate" with
     * no explanation is the acknowledgement somebody clicks through at four
     * in the afternoon, and the person who has to defend the payment six
     * months later needs to know what was checked.
     *
     * @throws \InvalidArgumentException
     */
    public function acknowledge(BillDuplicateWarning $warning, string $userId, string $explanation): BillDuplicateWarning
    {
        if (\strlen(trim($explanation)) <= 10) {
            throw new \InvalidArgumentException(
                'An acknowledgement needs a reason. "Not a duplicate" with nothing behind it is what '.
                'somebody clicks at four in the afternoon, and whoever defends the payment six months '.
                'later needs to know what was actually checked.',
            );
        }

        $warning->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $userId,
            'acknowledgement' => trim($explanation),
        ]);

        return $warning->refresh();
    }

    /**
     * The warnings on a bill that nobody has answered.
     *
     * @return \Illuminate\Support\Collection<int, BillDuplicateWarning>
     */
    public function unacknowledged(Bill $bill): \Illuminate\Support\Collection
    {
        return BillDuplicateWarning::query()
            ->where('bill_id', (string) $bill->getKey())
            ->whereNull('acknowledged_at')
            ->get();
    }

    /**
     * Whether a supplier's number is already used - the hard half, asked
     * before the constraint answers it.
     *
     * The constraint is the guarantee. This exists so the form can say
     * "that invoice is already recorded as PF-00412" instead of showing
     * somebody a database error, which is the difference between a product
     * and a stack trace.
     */
    public function existingWithNumber(string $entityId, string $partyId, ?string $number): ?Bill
    {
        $key = Bill::normaliseDocumentNumber($number);

        if ($key === '') {
            return null;
        }

        return Bill::query()
            ->where('entity_id', $entityId)
            ->where('party_id', $partyId)
            ->where('supplier_document_key', $key)
            ->first();
    }

    /**
     * Every bill in the entity with an unanswered warning - the exception
     * queue.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(string $entityId): array
    {
        $rows = DB::connection('tenant')->table('bill_duplicate_warnings as w')
            ->join('bills as b', 'b.id', '=', 'w.bill_id')
            ->join('bills as s', 's.id', '=', 'w.suspected_bill_id')
            ->where('w.entity_id', $entityId)
            ->whereNull('w.acknowledged_at')
            ->orderByDesc('b.gross_amount')
            ->select([
                'w.id', 'w.reason', 'w.detail', 'w.days_apart', 'w.amount_difference', 'w.raised_at_stage',
                'b.supplier_document_number as number', 'b.gross_amount', 'b.currency', 'b.status',
                's.supplier_document_number as suspected_number',
                's.supplier_document_date as suspected_date',
                's.status as suspected_status',
            ])
            ->get();

        return $rows->map(static fn (object $row): array => (array) $row)->all();
    }
}
