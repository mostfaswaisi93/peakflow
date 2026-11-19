<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Database\DatabaseRefusal;
use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Purchasing\Exceptions\BillRefused;
use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Purchasing\Models\BillCreditAllocation;
use App\Tenant\Purchasing\Models\BillLine;
use App\Tenant\Purchasing\Posting\Rules\SupplierCreditNotePostedRule;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A supplier's credit note, and what becomes of it - PF-134.
 *
 * ## Three ways to raise one, because three things happen
 *
 * {@see full()} for a bill credited in its entirety - a return, a
 * cancellation, a duplicate the supplier has accepted. {@see forLines()} for
 * goods that came back, which credits the lines they came from and the tax on
 * those lines. {@see forAnAmount()} for a settlement or a rebate, which has
 * no lines behind it and carries the bill's own tax treatment apportioned
 * across its rate groups.
 *
 * A system that offers only the first makes people raise a full credit and a
 * new bill for the difference, which breaks the link to the order. One that
 * offers only the last loses what came back.
 *
 * ## An amount-only credit is apportioned, never assigned one rate
 *
 * A 1,000 settlement on a bill with 15% and 5% lines is not a 15% credit.
 * Choosing one rate for the whole thing would misstate both boxes of the
 * return, and the error is in the input tax rather than in the expense, so
 * the accounts still balance and nothing looks wrong until the authority
 * compares the figures.
 *
 * ## The ceiling is the bill's remaining uncredited value
 *
 * Checked here so the person gets a sentence, and enforced by
 * `bills_are_not_over_credited` so an import, a second worker or a retried
 * job cannot get past it. Two credit notes entered at the same moment
 * against one bill would both pass a check that read the total before either
 * was written.
 *
 * ## Allocation posts nothing
 *
 * {@see allocate()} writes a row and no journal entry. The credit note and
 * the bill are both in `accounts_payable` against the same party, so netting
 * them changes no balance: the allocation is what makes the open-item
 * statement right. The alternative - a journal that debits and credits the
 * same account for the same party - doubles the ledger's line count and
 * explains nothing.
 */
final class SupplierCredits
{
    public function __construct(
        private readonly Poster $poster,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Credit a bill in full.
     *
     * @throws BillRefused
     */
    public function full(
        Entity $entity,
        Bill $bill,
        string $supplierDocumentNumber,
        Carbon $supplierDocumentDate,
        ?Carbon $postingDate = null,
        ?string $createdBy = null,
        ?string $reason = null,
    ): Bill {
        $selections = [];

        foreach ($bill->lines as $line) {
            $selections[] = [
                'line_id' => (string) $line->getKey(),
                'net' => (string) $line->net_amount,
                'quantity' => (string) $line->quantity,
            ];
        }

        return $this->forLines(
            $entity,
            $bill,
            $selections,
            $supplierDocumentNumber,
            $supplierDocumentDate,
            $postingDate,
            $createdBy,
            $reason,
        );
    }

    /**
     * Credit specific lines, in whole or in part.
     *
     * @param  list<array{line_id: string, net: string, quantity?: string}>  $selections
     *
     * @throws BillRefused
     */
    public function forLines(
        Entity $entity,
        Bill $bill,
        array $selections,
        string $supplierDocumentNumber,
        Carbon $supplierDocumentDate,
        ?Carbon $postingDate = null,
        ?string $createdBy = null,
        ?string $reason = null,
    ): Bill {
        if ($selections === []) {
            throw BillRefused::because(
                'A credit note for no lines credits nothing. If the supplier has credited an amount '.
                'rather than specific goods, that is an amount-only credit and it carries the bill\'s '.
                'own tax treatment apportioned across its rate groups.',
            );
        }

        $currency = (string) $bill->currency;
        $lines = [];

        foreach ($selections as $index => $selection) {
            $line = $bill->lines->first(
                static fn (BillLine $l): bool => (string) $l->getKey() === (string) $selection['line_id'],
            );

            if ($line === null) {
                throw BillRefused::because(
                    'A credit note line points at a line of another bill. Crediting across bills would '.
                    'reduce a balance the credit was never issued against.',
                );
            }

            $net = Money::of((string) $selection['net'], $currency);
            $lineNet = Money::of((string) $line->net_amount, $currency);

            if ($net->isGreaterThan($lineNet)) {
                throw BillRefused::because(sprintf(
                    'Line %d was charged %s and the credit is for %s. A line credited beyond what it '.
                    'charged is a refund on goods that were never invoiced.',
                    (int) $line->line_no,
                    (string) $lineNet->toPresentation(),
                    (string) $net->toPresentation(),
                ));
            }

            /*
             | The tax follows the line's own treatment and its own rate.
             |
             | Proportionally, because a partial credit of a line is a
             | partial credit of its tax - and the recoverable percentage
             | travels with it, so a half-credit of an entertainment line
             | gives back half of what was claimed and half of what was not.
             */
            $share = $lineNet->isZero()
                ? BigDecimal::zero()
                : BigDecimal::of((string) $net->amount)
                    ->dividedBy((string) $lineNet->amount, 12, RoundingMode::HalfUp);

            $tax = Money::of((string) $line->tax_amount, $currency)->multipliedBy(
                (string) $share,
                RoundingPolicy::halfUp(Scale::Amount),
            );

            $lines[] = [
                'line_no' => $index + 1,
                'description' => (string) $line->description,
                'quantity' => (string) ($selection['quantity'] ?? $line->quantity),
                'unit_price' => (string) $line->unit_price,
                'net_amount' => (string) $net->amount,
                'expense_account_id' => (string) $line->expense_account_id,
                'tax_code_id' => $line->tax_code_id === null ? null : (string) $line->tax_code_id,
                'tax_category' => $line->tax_category->value,
                'tax_rate' => (string) $line->tax_rate,
                'tax_amount' => (string) $tax->amount,
                'recoverable_percent' => (string) $line->recoverable_percent,
                'reverse_charge' => (bool) $line->reverse_charge,
                'credits_line_id' => (string) $line->getKey(),
                'dimension_1' => $line->dimension_1 === null ? null : (string) $line->dimension_1,
                'dimension_2' => $line->dimension_2 === null ? null : (string) $line->dimension_2,
                'dimension_3' => $line->dimension_3 === null ? null : (string) $line->dimension_3,
                'dimension_4' => $line->dimension_4 === null ? null : (string) $line->dimension_4,
            ];
        }

        return $this->raise(
            $entity,
            $bill,
            $lines,
            $supplierDocumentNumber,
            $supplierDocumentDate,
            $postingDate,
            $createdBy,
            $reason,
        );
    }

    /**
     * Credit an amount, apportioned across the bill's rate groups.
     *
     * @throws BillRefused
     */
    public function forAnAmount(
        Entity $entity,
        Bill $bill,
        string $gross,
        string $supplierDocumentNumber,
        Carbon $supplierDocumentDate,
        ?Carbon $postingDate = null,
        ?string $createdBy = null,
        ?string $reason = null,
    ): Bill {
        $currency = (string) $bill->currency;
        $asked = Money::of($gross, $currency);

        if ($asked->isZero() || $asked->isNegative()) {
            throw BillRefused::because('A credit note for nothing credits nothing.');
        }

        $billGross = $bill->gross();

        if ($billGross->isZero()) {
            throw BillRefused::because(
                'The bill charged nothing, so there is nothing to apportion a credit across.',
            );
        }

        /*
         | Apportioned by the share each line carries of the bill's gross.
         |
         | Each line keeps its own rate, category and recoverable
         | percentage, which is what makes the credit's tax breakdown mirror
         | the bill's rather than guess at it. The remainder from the
         | division lands on the largest line, because somewhere it must:
         | distributing it evenly would put fractions of a cent into groups
         | the supplier never mentioned.
         */
        $weights = [];

        foreach ($bill->lines as $line) {
            $weights[] = BigDecimal::of((string) $line->net_amount)
                ->plus((string) $line->tax_amount);
        }

        $shares = $asked->allocate($weights, RoundingPolicy::halfUp(Scale::Amount));
        $lines = [];

        foreach ($bill->lines as $index => $line) {
            $shareGross = $shares[$index] ?? Money::zero($currency);

            if ($shareGross->isZero()) {
                continue;
            }

            /*
             | Split the share back into net and tax at the line's own rate.
             |
             | `gross = net x (1 + rate)`, so `net = gross / (1 + rate)` -
             | and the tax is the difference rather than a second rounded
             | multiplication, so the two always add back to the share
             | exactly. A cent that belongs to neither is a cent in the
             | input account that will not tie to the return.
             */
            $rate = BigDecimal::of((string) $line->tax_rate)
                ->dividedBy('100', 12, RoundingMode::HalfUp);

            $divisor = BigDecimal::one()->plus($rate);

            $net = $divisor->isEqualTo(BigDecimal::one())
                ? $shareGross
                : Money::ofRounded(
                    (string) BigDecimal::of((string) $shareGross->amount)
                        ->dividedBy($divisor, 12, RoundingMode::HalfUp),
                    $currency,
                    RoundingPolicy::halfUp(Scale::Amount),
                );

            $tax = $shareGross->minus($net);

            $lines[] = [
                'line_no' => \count($lines) + 1,
                'description' => sprintf(
                    'Credit against "%s"%s',
                    (string) $line->description,
                    $reason === null ? '' : ' - '.$reason,
                ),
                'quantity' => '1',
                'unit_price' => (string) $net->amount,
                'net_amount' => (string) $net->amount,
                'expense_account_id' => (string) $line->expense_account_id,
                'tax_code_id' => $line->tax_code_id === null ? null : (string) $line->tax_code_id,
                'tax_category' => $line->tax_category->value,
                'tax_rate' => (string) $line->tax_rate,
                'tax_amount' => (string) $tax->amount,
                'recoverable_percent' => (string) $line->recoverable_percent,
                'reverse_charge' => (bool) $line->reverse_charge,
                'credits_line_id' => (string) $line->getKey(),
                'dimension_1' => $line->dimension_1 === null ? null : (string) $line->dimension_1,
                'dimension_2' => $line->dimension_2 === null ? null : (string) $line->dimension_2,
                'dimension_3' => $line->dimension_3 === null ? null : (string) $line->dimension_3,
                'dimension_4' => $line->dimension_4 === null ? null : (string) $line->dimension_4,
            ];
        }

        return $this->raise(
            $entity,
            $bill,
            $lines,
            $supplierDocumentNumber,
            $supplierDocumentDate,
            $postingDate,
            $createdBy,
            $reason,
        );
    }

    /**
     * A credit note that names no bill of ours - a volume rebate, typically.
     *
     * Allowed, because it happens: a quarterly rebate is a credit against a
     * relationship rather than a document. The reference is still mandatory
     * and the database agrees - a credit against nothing is a figure nobody
     * can tie to a purchase, and it is the shape a supplier's balance takes
     * when somebody has keyed a rebate twice.
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws BillRefused
     */
    public function standalone(
        Entity $entity,
        string $partyId,
        array $lines,
        string $supplierDocumentNumber,
        Carbon $supplierDocumentDate,
        string $reference,
        string $currency = 'SAR',
        ?Carbon $postingDate = null,
        ?string $createdBy = null,
    ): Bill {
        if (\strlen(trim($reference)) <= 3) {
            throw BillRefused::because(
                'A credit note that names no bill has to say what it is for - the supplier\'s own '.
                'reference, the rebate period, the agreement. A credit against nothing is a figure '.
                'nobody can tie to a purchase.',
            );
        }

        return $this->write(
            entity: $entity,
            partyId: $partyId,
            creditsBillId: null,
            reference: trim($reference),
            lines: $lines,
            currency: $currency,
            supplierDocumentNumber: $supplierDocumentNumber,
            supplierDocumentDate: $supplierDocumentDate,
            postingDate: $postingDate ?? $supplierDocumentDate,
            createdBy: $createdBy,
        );
    }

    /**
     * Post a credit note.
     *
     * @throws BillRefused
     */
    public function post(Bill $creditNote, Entity $entity, ?string $userId = null): Bill
    {
        $creditNote = $creditNote->refresh();

        if (! $creditNote->isCreditNote()) {
            throw BillRefused::because(
                'That document is not a credit note, and posting it through the credit rule would '.
                'reverse a purchase that was never credited.',
            );
        }

        if ($creditNote->isPosted()) {
            return $creditNote;
        }

        return DB::connection('tenant')->transaction(function () use ($creditNote, $entity, $userId): Bill {
            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: SupplierCreditNotePostedRule::KEY,
                event: $this->event($creditNote),
                accountingDate: Carbon::parse($creditNote->posting_date->toDateString()),
                journalCode: 'PUR',
                sourceType: 'supplier_credit_note',
                sourceId: (string) $creditNote->getKey(),
                narration: sprintf(
                    'Credit note %s from %s',
                    (string) $creditNote->supplier_document_number,
                    (string) ($creditNote->supplierName() ?? $creditNote->party_id),
                ),
            );

            $creditNote->update([
                'status' => 'posted',
                'posted_at' => now(),
                'journal_entry_id' => (string) $entry->getKey(),
            ]);

            $this->audit->record(
                category: 'purchasing',
                action: 'supplier_credit_note.posted',
                subjectType: 'bill',
                subjectId: (string) $creditNote->getKey(),
                subjectLabel: (string) $creditNote->supplier_document_number,
                after: [
                    'journal_entry_id' => (string) $entry->getKey(),
                    'gross' => (string) $creditNote->gross_amount,
                    'credits' => $creditNote->credits_bill_id === null
                        ? (string) $creditNote->credit_reference
                        : (string) $creditNote->credits_bill_id,
                ],
                entityId: (string) $creditNote->entity_id,
                partyId: (string) $creditNote->party_id,
            );

            return $creditNote->refresh();
        });
    }

    /**
     * Allocate a credit note against an open bill from the same supplier.
     *
     * No posting, for the reason in the class docblock. The refusals come
     * from the database, which checks both ends: a credit cannot be used
     * beyond its own value, and a bill cannot have more allocated to it than
     * it still owes.
     *
     * @throws BillRefused
     */
    public function allocate(
        Bill $creditNote,
        Bill $bill,
        string $amount,
        ?string $userId = null,
        ?string $note = null,
    ): BillCreditAllocation {
        $creditNote = $creditNote->refresh();
        $bill = $bill->refresh();

        if ($creditNote->credits_bill_id !== null
            && (string) $creditNote->credits_bill_id === (string) $bill->getKey()) {
            throw BillRefused::because(sprintf(
                'Credit note %s already credits that bill by reference, so it has already reduced what '.
                'the bill owes. Allocating it to the same bill would reduce it twice, and the supplier '.
                'would be owed a balance no document supports.',
                (string) $creditNote->supplier_document_number,
            ));
        }

        if ((string) $creditNote->credit_disposition === 'refund') {
            throw BillRefused::because(sprintf(
                'Credit note %s is marked for refund, so the supplier is sending the money back. '.
                'Allocating it as well would reduce what we owe and collect the cash for the same '.
                'credit.',
                (string) $creditNote->supplier_document_number,
            ));
        }

        try {
            $allocation = BillCreditAllocation::query()->updateOrCreate(
                [
                    'credit_note_id' => (string) $creditNote->getKey(),
                    'bill_id' => (string) $bill->getKey(),
                ],
                [
                    'entity_id' => (string) $creditNote->entity_id,
                    'currency' => (string) $creditNote->currency,
                    'amount' => $amount,
                    'allocated_at' => now(),
                    'allocated_by' => $userId,
                    'note' => $note,
                ],
            );
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'bill_credit_allocations')
                || str_contains($e->getMessage(), 'restrict_violation')) {
                throw BillRefused::because(DatabaseRefusal::sentence($e->getMessage()));
            }

            throw $e;
        }

        $creditNote->update(['credit_disposition' => 'allocate']);

        $this->audit->record(
            category: 'purchasing',
            action: 'supplier_credit_note.allocated',
            subjectType: 'bill',
            subjectId: (string) $creditNote->getKey(),
            subjectLabel: (string) $creditNote->supplier_document_number,
            after: [
                'against' => (string) $bill->supplier_document_number,
                'amount' => $amount,
                'currency' => (string) $creditNote->currency,
            ],
            reason: $note,
            entityId: (string) $creditNote->entity_id,
            partyId: (string) $creditNote->party_id,
        );

        return $allocation;
    }

    /**
     * Mark a credit note for refund instead of allocation.
     *
     * A decision rather than a default, because the two are different
     * events: an allocation reduces what we owe and a refund is cash coming
     * in. A credit with no disposition is a balance nobody has decided
     * about, which is how a supplier ends up holding money that was never
     * claimed.
     *
     * @throws BillRefused
     */
    public function markForRefund(Bill $creditNote, ?string $userId = null, ?string $reason = null): Bill
    {
        $creditNote = $creditNote->refresh();

        $allocated = BillCreditAllocation::query()
            ->where('credit_note_id', (string) $creditNote->getKey())
            ->count();

        if ($allocated > 0) {
            throw BillRefused::because(sprintf(
                'Credit note %s has already been allocated against %d bill(s). Claiming it back as '.
                'well would collect the cash for a credit that has already reduced what we owe.',
                (string) $creditNote->supplier_document_number,
                $allocated,
            ));
        }

        $creditNote->update(['credit_disposition' => 'refund']);

        $this->audit->record(
            category: 'purchasing',
            action: 'supplier_credit_note.marked_for_refund',
            subjectType: 'bill',
            subjectId: (string) $creditNote->getKey(),
            subjectLabel: (string) $creditNote->supplier_document_number,
            after: ['gross' => (string) $creditNote->gross_amount],
            reason: $reason,
            entityId: (string) $creditNote->entity_id,
            partyId: (string) $creditNote->party_id,
        );

        return $creditNote->refresh();
    }

    /**
     * What is left of a credit note after its allocations.
     *
     * The figure a payment run needs: a credit note with an unallocated
     * remainder is money the supplier still owes us.
     */
    public function unallocated(Bill $creditNote): Money
    {
        $currency = (string) $creditNote->currency;
        $used = Money::zero($currency);

        foreach (BillCreditAllocation::query()
            ->where('credit_note_id', (string) $creditNote->getKey())
            ->get() as $allocation) {
            $used = $used->plus(Money::of((string) $allocation->amount, $currency));
        }

        return $creditNote->gross()->minus($used);
    }

    /**
     * The posting event, built from the stored credit note.
     *
     * @return array<string, mixed>
     */
    public function event(Bill $creditNote): array
    {
        $currency = (string) $creditNote->currency;
        $expense = [];
        $recoverable = [];
        $reverseCharge = [];

        foreach ($creditNote->lines as $line) {
            $expense[] = [
                'account_id' => (string) $line->expense_account_id,
                'amount' => (string) $line->net($currency)->plus($line->nonRecoverableTax($currency))->amount,
                'tax_code_id' => $line->tax_code_id === null ? null : (string) $line->tax_code_id,
                'tax_point' => $creditNote->tax_point->toDateString(),
                'dimensions' => $line->dimensions(),
            ];
        }

        foreach ($creditNote->taxBreakdown as $group) {
            $jurisdiction = $this->jurisdictionOf($creditNote);
            $amount = Money::of((string) $group->recoverable_amount, $currency);

            if ($amount->isZero()) {
                continue;
            }

            $entry = [
                'jurisdiction' => $jurisdiction,
                'amount' => (string) $amount->amount,
                'tax_code_id' => $group->tax_code_id === null ? null : (string) $group->tax_code_id,
                'tax_point' => $group->tax_code_id === null ? null : $creditNote->tax_point->toDateString(),
            ];

            if ($group->reverse_charge) {
                $reverseCharge[] = $entry;

                continue;
            }

            $recoverable[] = $entry;
        }

        return [
            'bill_id' => (string) $creditNote->getKey(),
            'party_id' => (string) $creditNote->party_id,
            'payable' => (string) $creditNote->payable_amount,
            'narration' => null,
            'expense' => $expense,
            'recoverable_tax' => $recoverable,
            'reverse_charge' => $reverseCharge,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws BillRefused
     */
    /**
     * Which jurisdiction's tax accounts this credit note's tax belongs to.
     *
     * The same read as {@see Bills::jurisdictionOf()}, and the same defect:
     * it was `tax_decision_snapshot['jurisdiction'] ?? 'SA'`, nothing writes
     * a credit note's snapshot, and the frozen decision spells that key
     * `country` anyway - so every supplier credit's tax reversal posted to
     * Saudi control accounts whatever the entity's country.
     *
     * Duplicated rather than extracted, and that is a judgement: a shared
     * helper would have to live in `Support` or in `Tax`, and neither owns
     * "which of a purchasing document's accounts" - the first is a layer
     * this does not belong in and the second may not depend on Purchasing.
     * Two private methods with one docblock each is the smaller cost.
     */
    private function jurisdictionOf(Bill $document): string
    {
        $frozen = $document->tax_decision_snapshot['country'] ?? null;

        if (\is_string($frozen) && $frozen !== '') {
            return $frozen;
        }

        return (string) $document->entity->country;
    }

    private function raise(
        Entity $entity,
        Bill $bill,
        array $lines,
        string $supplierDocumentNumber,
        Carbon $supplierDocumentDate,
        ?Carbon $postingDate,
        ?string $createdBy,
        ?string $reason,
    ): Bill {
        $currency = (string) $bill->currency;
        $asked = Money::zero($currency);

        foreach ($lines as $line) {
            $asked = $asked->plus(Money::of((string) $line['net_amount'], $currency));

            /*
             | Reverse-charge tax is not part of the credit, as it was not
             | part of the bill.
             |
             | The supplier charged nothing: they were owed the net and they
             | are crediting the net. Counting the self-assessed tax here
             | would make a full credit look like 115% of the bill and
             | refuse it.
             */
            if (($line['reverse_charge'] ?? false) === true) {
                continue;
            }

            $asked = $asked->plus(Money::of((string) ($line['tax_amount'] ?? '0'), $currency));
        }

        $remaining = $bill->load(['lines', 'creditNotes'])->remainingUncredited();

        if ($asked->isGreaterThan($remaining)) {
            throw BillRefused::because(sprintf(
                'Bill %s was charged %s %s and %s has already been credited, leaving %s. A credit of '.
                '%s would take the total beyond what was charged, which is a refund the supplier has '.
                'not agreed to.',
                (string) $bill->supplier_document_number,
                $currency,
                (string) $bill->gross()->toPresentation(),
                (string) $bill->creditedAmount()->toPresentation(),
                (string) $remaining->toPresentation(),
                (string) $asked->toPresentation(),
            ));
        }

        return $this->write(
            entity: $entity,
            partyId: (string) $bill->party_id,
            creditsBillId: (string) $bill->getKey(),
            reference: $reason,
            lines: $lines,
            currency: $currency,
            supplierDocumentNumber: $supplierDocumentNumber,
            supplierDocumentDate: $supplierDocumentDate,
            postingDate: $postingDate ?? Carbon::parse($bill->posting_date->toDateString()),
            createdBy: $createdBy,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws BillRefused
     */
    private function write(
        Entity $entity,
        string $partyId,
        ?string $creditsBillId,
        ?string $reference,
        array $lines,
        string $currency,
        string $supplierDocumentNumber,
        Carbon $supplierDocumentDate,
        Carbon $postingDate,
        ?string $createdBy,
    ): Bill {
        /*
         | Through `Bills::enter()`, deliberately.
         |
         | Which is what gives a credit note the same duplicate detection,
         | the same frozen terms, the same three dates and the same audit
         | record - and the same unique index on the supplier's document
         | number, because a supplier re-sending a credit note twice is the
         | same error as re-sending an invoice twice, in the direction that
         | is harder to notice: it reduces what we owe.
         |
         | A crediting path with its own entry logic would be a second
         | implementation of all of that, and the second implementation is
         | the one that drifts.
         */
        try {
            $creditNote = app(Bills::class)->enter(
                entity: $entity,
                partyId: $partyId,
                supplierDocumentNumber: $supplierDocumentNumber,
                supplierDocumentDate: $supplierDocumentDate,
                taxPoint: $supplierDocumentDate,
                postingDate: $postingDate,
                currency: $currency,
                lines: $lines,
                createdBy: $createdBy,
                documentType: '381',
                creditsBillId: $creditsBillId,
                creditReference: $reference,
            );
        } catch (QueryException $e) {
            throw BillRefused::because(DatabaseRefusal::sentence($e->getMessage()));
        }

        $this->audit->record(
            category: 'purchasing',
            action: 'supplier_credit_note.entered',
            subjectType: 'bill',
            subjectId: (string) $creditNote->getKey(),
            subjectLabel: $supplierDocumentNumber,
            after: [
                'credits' => $creditsBillId ?? $reference,
                'gross' => (string) $creditNote->gross_amount,
                'currency' => $currency,
                'lines' => \count($lines),
            ],
            reason: $reference,
            entityId: (string) $entity->getKey(),
            partyId: $partyId,
        );

        return $creditNote->refresh();
    }
}
