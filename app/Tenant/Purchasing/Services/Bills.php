<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Catalog\Services\DueDateCalculator;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Purchasing\Exceptions\BillRefused;
use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Purchasing\Models\BillLine;
use App\Tenant\Purchasing\Models\BillTaxBreakdownRow;
use App\Tenant\Purchasing\Posting\Rules\BillPostedRule;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Entering, checking and posting a supplier's bill - PF-133.
 *
 * ## The three dates, kept apart
 *
 * The supplier's document date is theirs and is what their statement says.
 * The tax point is the jurisdiction's and decides which return the input tax
 * falls in and which FX rate applies. The posting date is ours and decides
 * the accounting period. A bill dated the 28th of March that arrives on the
 * 3rd of April belongs to the supplier's March and our April, and the input
 * tax belongs to whichever period the tax point names.
 *
 * ## The FX rate comes from the tax point, not from today
 *
 * IAS 21 books a transaction at the rate on the date of the transaction, and
 * for a purchase that date is the tax point. Using today's rate would value
 * a January bill entered in March at March's rate, which moves the expense
 * and leaves an FX difference nobody can explain from the document.
 *
 * ## The duplicate check runs before the insert and the constraint catches
 * what it misses
 *
 * {@see DuplicateBillDetector::existingWithNumber()} is asked first so the
 * form can say "that invoice is already recorded as PF-00412" rather than
 * showing somebody a database error. The unique index is still the
 * guarantee: an import, a second worker or a retried job does not go through
 * the form.
 */
final class Bills
{
    public function __construct(
        private readonly Poster $poster,
        private readonly DueDateCalculator $dueDates,
        private readonly DuplicateBillDetector $duplicates,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Enter a bill, with its lines and its tax breakdown computed.
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws BillRefused
     */
    public function enter(
        Entity $entity,
        string $partyId,
        string $supplierDocumentNumber,
        Carbon $supplierDocumentDate,
        Carbon $taxPoint,
        Carbon $postingDate,
        string $currency,
        array $lines,
        ?string $createdBy = null,
        string $source = 'manual',
        ?string $paymentTermId = null,
        ?string $note = null,
        string $documentType = '380',
    ): Bill {
        if ($lines === []) {
            throw BillRefused::because('A bill with no lines records no purchase.');
        }

        if ($taxPoint->gt($postingDate)) {
            throw BillRefused::because(sprintf(
                'The tax point (%s) is after the posting date (%s), which would claim input tax in a '.
                'return that has not opened. A supply before the posting date is normal and is why the '.
                'two dates exist; the other direction is not.',
                $taxPoint->toDateString(),
                $postingDate->toDateString(),
            ));
        }

        $entityId = (string) $entity->getKey();
        $key = Bill::normaliseDocumentNumber($supplierDocumentNumber);

        $existing = $this->duplicates->existingWithNumber($entityId, $partyId, $supplierDocumentNumber);

        if ($existing !== null) {
            throw BillRefused::duplicate(
                $supplierDocumentNumber,
                (string) ($existing->supplierName() ?? $partyId),
                (string) $existing->getKey(),
            );
        }

        return DB::connection('tenant')->transaction(function () use (
            $entity, $entityId, $partyId, $supplierDocumentNumber, $key,
            $supplierDocumentDate, $taxPoint, $postingDate, $currency, $lines,
            $createdBy, $source, $paymentTermId, $note, $documentType,
        ): Bill {
            $computed = $this->compute($lines, $currency);

            try {
                $bill = Bill::create([
                    'entity_id' => $entityId,
                    'document_type' => $documentType,
                    'status' => 'draft',
                    'party_id' => $partyId,
                    'supplier_document_number' => trim($supplierDocumentNumber),
                    'supplier_document_key' => $key,
                    'supplier_document_date' => $supplierDocumentDate->toDateString(),
                    'tax_point' => $taxPoint->toDateString(),
                    'posting_date' => $postingDate->toDateString(),
                    'currency' => $currency,
                    'net_amount' => (string) $computed['net']->amount,
                    'tax_amount' => (string) $computed['tax']->amount,
                    'gross_amount' => (string) $computed['gross']->amount,
                    'recoverable_tax_amount' => (string) $computed['recoverable']->amount,
                    'non_recoverable_tax_amount' => (string) $computed['non_recoverable']->amount,
                    'payable_amount' => (string) $computed['gross']->amount,
                    'payment_term_id' => $paymentTermId,
                    'created_by' => $createdBy,
                    'source' => $source,
                    'note' => $note,
                ]);
            } catch (UniqueConstraintViolationException) {
                /*
                 | The constraint, not the check.
                 |
                 | The check above ran and found nothing, and the row still
                 | collided - which means another worker inserted the same
                 | bill in between. That is exactly the case the index exists
                 | for, and the message is the same one the check would have
                 | given.
                 */
                throw BillRefused::duplicate($supplierDocumentNumber, $partyId, 'another entry made at the same moment');
            }

            $this->writeLines($bill, $lines, $currency);
            $this->writeBreakdown($bill, $computed['groups']);
            $this->freezeTerms($bill, $entity, $paymentTermId, $computed['gross']);

            /*
             | The near-match search, at entry.
             |
             | It runs again at payment time, because the duplicate
             | frequently arrives after the first was approved and by then
             | nobody is looking at this screen.
             */
            $this->duplicates->examine($bill, 'entry');

            $this->audit->record(
                category: 'purchasing',
                action: 'bill.entered',
                subjectType: 'bill',
                subjectId: (string) $bill->getKey(),
                subjectLabel: trim($supplierDocumentNumber),
                after: [
                    'supplier' => $partyId,
                    'gross' => (string) $computed['gross']->amount,
                    'currency' => $currency,
                    'tax_point' => $taxPoint->toDateString(),
                    'posting_date' => $postingDate->toDateString(),
                    'source' => $source,
                ],
                entityId: $entityId,
                partyId: $partyId,
            );

            return $bill->refresh();
        });
    }

    /**
     * Post an approved bill.
     *
     * @throws BillRefused
     */
    public function post(Bill $bill, Entity $entity, ?string $userId = null): Bill
    {
        $bill = $bill->refresh();

        if ($bill->isPosted()) {
            return $bill;
        }

        $unanswered = $this->duplicates->unacknowledged($bill);

        if ($unanswered->isNotEmpty()) {
            throw BillRefused::unacknowledgedDuplicates($unanswered->count());
        }

        return DB::connection('tenant')->transaction(function () use ($bill, $entity, $userId): Bill {
            $event = $this->event($bill);

            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: BillPostedRule::KEY,
                event: $event,
                accountingDate: \Illuminate\Support\Carbon::parse($bill->posting_date->toDateString()),
                journalCode: 'PUR',
                sourceType: 'bill',
                sourceId: (string) $bill->getKey(),
                narration: sprintf(
                    'Bill %s from %s',
                    (string) $bill->supplier_document_number,
                    (string) ($bill->supplierName() ?? $bill->party_id),
                ),
            );

            $bill->update([
                'status' => 'posted',
                'posted_at' => now(),
                'journal_entry_id' => (string) $entry->getKey(),
            ]);

            $this->audit->record(
                category: 'purchasing',
                action: 'bill.posted',
                subjectType: 'bill',
                subjectId: (string) $bill->getKey(),
                subjectLabel: (string) $bill->supplier_document_number,
                after: [
                    'journal_entry_id' => (string) $entry->getKey(),
                    'gross' => (string) $bill->gross_amount,
                    'recoverable_tax' => (string) $bill->recoverable_tax_amount,
                    'non_recoverable_tax' => (string) $bill->non_recoverable_tax_amount,
                ],
                entityId: (string) $bill->entity_id,
                partyId: (string) $bill->party_id,
            );

            return $bill->refresh();
        });
    }

    /**
     * The posting event, built from the stored bill.
     *
     * ## Non-recoverable tax rides with its own line's expense
     *
     * Not with the total, and not in the input account. The cost of a
     * purchase includes tax that cannot be claimed, and it belongs to the
     * expense the purchase was for - so a cost centre that bought
     * entertainment carries the whole cost of it.
     *
     * @return array<string, mixed>
     */
    public function event(Bill $bill): array
    {
        $currency = (string) $bill->currency;
        $expense = [];
        $recoverable = [];
        $reverseCharge = [];

        foreach ($bill->lines as $line) {
            $amount = $line->net($currency)->plus($line->nonRecoverableTax($currency));

            $expense[] = [
                'account_id' => (string) $line->expense_account_id,
                'amount' => (string) $amount->amount,
                'tax_code_id' => $line->tax_code_id === null ? null : (string) $line->tax_code_id,
                'tax_point' => $bill->tax_point->toDateString(),
                'dimensions' => $line->dimensions(),
            ];
        }

        foreach ($bill->taxBreakdown as $group) {
            $jurisdiction = (string) ($bill->tax_decision_snapshot['jurisdiction'] ?? 'SA');
            $recoverableAmount = Money::of((string) $group->recoverable_amount, $currency);

            if ($recoverableAmount->isZero()) {
                continue;
            }

            $entry = [
                'jurisdiction' => $jurisdiction,
                'amount' => (string) $recoverableAmount->amount,
                'tax_code_id' => $group->tax_code_id === null ? null : (string) $group->tax_code_id,
                /*
                 | The tax point travels with the code, or not at all.
                 |
                 | `journal_lines` refuses one without the other, and
                 | rightly: a tax point that cannot be attributed to a code
                 | is a figure in a return nobody can trace back.
                 */
                'tax_point' => $group->tax_code_id === null ? null : $bill->tax_point->toDateString(),
            ];

            if ($group->reverse_charge) {
                /*
                 | Both sides, and not in the payable.
                 |
                 | The supplier charged nothing: they are owed the net. The
                 | tax is owed to the authority and claimed back from them in
                 | the same breath, which is two postings of the same amount
                 | in two different boxes of the return.
                 */
                $reverseCharge[] = $entry;

                continue;
            }

            $recoverable[] = $entry;
        }

        return [
            'bill_id' => (string) $bill->getKey(),
            'party_id' => (string) $bill->party_id,
            'payable' => (string) $bill->payable_amount,
            'narration' => null,
            'expense' => $expense,
            'recoverable_tax' => $recoverable,
            'reverse_charge' => $reverseCharge,
        ];
    }

    /**
     * Total the lines, group the tax, and split what is recoverable.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function compute(array $lines, string $currency): array
    {
        $net = Money::zero($currency);
        $tax = Money::zero($currency);
        $recoverable = Money::zero($currency);
        $groups = [];

        foreach ($lines as $line) {
            $lineNet = Money::of((string) $line['net_amount'], $currency);
            $lineTax = Money::of((string) ($line['tax_amount'] ?? '0'), $currency);
            $percent = (string) ($line['recoverable_percent'] ?? '100');
            $reverse = (bool) ($line['reverse_charge'] ?? false);
            $category = (string) ($line['tax_category'] ?? TaxCategory::Standard->value);
            $rate = (string) ($line['tax_rate'] ?? '0');

            $lineRecoverable = $percent === '100' || $percent === '100.000000'
                ? $lineTax
                : $lineTax->multipliedBy(
                    (string) \Brick\Math\BigDecimal::of($percent)
                        ->dividedBy('100', 12, \Brick\Math\RoundingMode::HalfUp),
                    \App\Support\Money\RoundingPolicy::halfUp(\App\Support\Money\Scale::Amount),
                );

            $net = $net->plus($lineNet);
            $tax = $tax->plus($lineTax);
            $recoverable = $recoverable->plus($lineRecoverable);

            /*
             | Grouped by category, rate AND the reverse-charge flag.
             |
             | The same rate under the reverse charge is a different box on
             | the return - an input and an output - and merging the two
             | would make the return's two sides indistinguishable.
             */
            $groupKey = sprintf('%s|%s|%s', $category, $rate, $reverse ? '1' : '0');

            $groups[$groupKey] ??= [
                'category' => $category,
                'rate' => $rate,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'reverse_charge' => $reverse,
                'taxable' => Money::zero($currency),
                'tax' => Money::zero($currency),
                'recoverable' => Money::zero($currency),
            ];

            $groups[$groupKey]['taxable'] = $groups[$groupKey]['taxable']->plus($lineNet);
            $groups[$groupKey]['tax'] = $groups[$groupKey]['tax']->plus($lineTax);
            $groups[$groupKey]['recoverable'] = $groups[$groupKey]['recoverable']->plus($lineRecoverable);
        }

        /*
         | The gross EXCLUDES reverse-charge tax.
         |
         | The supplier charged nothing under the reverse charge, so they are
         | owed the net. A gross that included it would overpay them by the
         | tax and leave the authority's side unpaid.
         */
        $reverseChargeTax = Money::zero($currency);

        foreach ($groups as $group) {
            if ($group['reverse_charge'] === true) {
                $reverseChargeTax = $reverseChargeTax->plus($group['tax']);
            }
        }

        $payableTax = $tax->minus($reverseChargeTax);

        return [
            'net' => $net,
            'tax' => $payableTax,
            'gross' => $net->plus($payableTax),
            'recoverable' => $recoverable->minus($reverseChargeTax),
            'non_recoverable' => $payableTax->minus($recoverable->minus($reverseChargeTax)),
            'groups' => $groups,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(Bill $bill, array $lines, string $currency): void
    {
        foreach ($lines as $index => $line) {
            BillLine::create([
                'entity_id' => (string) $bill->entity_id,
                'bill_id' => (string) $bill->getKey(),
                'line_no' => (int) ($line['line_no'] ?? $index + 1),
                'item_id' => $line['item_id'] ?? null,
                'item_code' => $line['item_code'] ?? null,
                'description' => (string) $line['description'],
                'unit_code' => (string) ($line['unit_code'] ?? 'C62'),
                'quantity' => (string) ($line['quantity'] ?? '1'),
                'unit_price' => (string) ($line['unit_price'] ?? $line['net_amount']),
                'net_amount' => (string) $line['net_amount'],
                'expense_account_id' => $line['expense_account_id'] ?? null,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'tax_category' => (string) ($line['tax_category'] ?? TaxCategory::Standard->value),
                'tax_rate' => (string) ($line['tax_rate'] ?? '0'),
                'tax_amount' => (string) ($line['tax_amount'] ?? '0'),
                'recoverable_percent' => (string) ($line['recoverable_percent'] ?? '100'),
                'reverse_charge' => (bool) ($line['reverse_charge'] ?? false),
                'dimension_1' => $line['dimension_1'] ?? null,
                'dimension_2' => $line['dimension_2'] ?? null,
                'dimension_3' => $line['dimension_3'] ?? null,
                'dimension_4' => $line['dimension_4'] ?? null,
            ]);
        }
    }

    /** @param array<string, array<string, mixed>> $groups */
    private function writeBreakdown(Bill $bill, array $groups): void
    {
        foreach ($groups as $group) {
            BillTaxBreakdownRow::create([
                'entity_id' => (string) $bill->entity_id,
                'bill_id' => (string) $bill->getKey(),
                'category' => (string) $group['category'],
                'rate' => (string) $group['rate'],
                'tax_code_id' => $group['tax_code_id'] ?? null,
                'taxable_amount' => (string) $group['taxable']->amount,
                'tax_amount' => (string) $group['tax']->amount,
                'recoverable_amount' => (string) $group['recoverable']->amount,
                'reverse_charge' => (bool) $group['reverse_charge'],
            ]);
        }
    }

    /**
     * Freeze the payment terms, so the due date does not move.
     *
     * The supplier's terms can change; what this bill is due on cannot. An
     * aging report that re-derived the due date would age a bill differently
     * next month from how it aged it this month, and nobody could reconcile
     * the two.
     */
    private function freezeTerms(Bill $bill, Entity $entity, ?string $paymentTermId, Money $gross): void
    {
        if ($paymentTermId === null) {
            return;
        }

        $term = \App\Tenant\Catalog\Models\PaymentTerm::query()->find($paymentTermId);

        if ($term === null) {
            return;
        }

        $resolved = $this->dueDates->for(
            $term,
            \Illuminate\Support\Carbon::parse($bill->supplier_document_date->toDateString()),
            (string) $entity->getKey(),
            $gross,
        );

        $bill->update([
            'terms_snapshot' => [
                'payment_term_id' => (string) $term->getKey(),
                'code' => (string) $term->code,
                'label_en' => (string) $term->name_en,
                'label_ar' => (string) $term->name_ar,
                'due_dates' => $resolved['due_dates'],
                'discount' => $resolved['discount'],
                /*
                 | From the SUPPLIER's date, not ours.
                 |
                 | "Thirty days" means thirty days from their invoice date,
                 | which is what they will chase on. Counting from our
                 | posting date would quietly extend every bill that arrived
                 | late, and the supplier would be right and we would be
                 | wrong.
                 */
                'counted_from' => $bill->supplier_document_date->toDateString(),
                'frozen_at' => now()->toDateTimeString(),
            ],
        ]);
    }
}
