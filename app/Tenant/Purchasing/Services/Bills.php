<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Tenant\Catalog\Exceptions\RateNotAvailable;
use App\Tenant\Catalog\Services\DueDateCalculator;
use App\Tenant\Catalog\Services\ExchangeRates;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Purchasing\Exceptions\BillRefused;
use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Purchasing\Models\BillLine;
use App\Tenant\Purchasing\Models\BillTaxBreakdownRow;
use App\Tenant\Purchasing\Models\ThreeWayMatchException;
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
        private readonly ThreeWayMatch $match,
        private readonly AuditTrail $audit,
        private readonly ExchangeRates $rates,
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
        ?string $creditsBillId = null,
        ?string $creditReference = null,
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

        /*
         | The rate, taken here and frozen on the document - PF-133.
         |
         | Frozen rather than looked up again at posting for the same reason
         | the payment terms are frozen: the rate on file is the one the
         | approver's limit was tested against and the one the document will
         | be explained by, and a second lookup days later can legitimately
         | find a different answer - a rate recorded late for a date in
         | between, or a correction - which would make the posting disagree
         | with the document it came from and with the approval that let it
         | through.
         |
         | And null where there is no rate yet, rather than a refusal.
         | Entering a supplier's invoice is recording something that has
         | already happened, and a feed that has not run is not a reason the
         | business cannot record it - the same reasoning as PF-069's
         | "a feed outage does not stop the business trading". The refusal
         | belongs at posting, where an untranslated line cannot be written
         | and the ledger says so itself.
         */
        $rate = $this->rateIfOnFile($entity, $currency, $taxPoint);

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
            $creditsBillId, $creditReference, $rate,
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
                    /*
                     | All three together, or all three null.
                     |
                     | The value alone cannot answer "was it current" or
                     | "which feed said so", and looking it up again later
                     | cannot either: a rate may since have been superseded,
                     | and two feeds can send the same figure for different
                     | days. `fx_rate_date` is the rate's OWN date rather
                     | than the tax point, because an effective-dated lookup
                     | legitimately answers a Saturday with Friday's rate
                     | and the document has to say which one it booked at.
                     */
                    'fx_rate' => $rate === null ? null : (string) $rate->value,
                    'fx_source' => $rate?->provider,
                    'fx_rate_date' => $rate?->date->format('Y-m-d'),
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
                    /*
                     | Written with the row, not updated onto it after.
                     |
                     | `bills_a_credit_note_says_what_it_credits` is checked
                     | on the insert, and rightly: a credit note that is
                     | momentarily referenceless is exactly the state the
                     | constraint exists to forbid, and a two-step write
                     | would leave that state on disk between the two steps
                     | for anything reading in another transaction.
                     */
                    'credits_bill_id' => $creditsBillId,
                    'credit_reference' => $creditReference,
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

            /*
             | And the three-way match, at entry - PF-135.
             |
             | Not to consume the order lines: that happens at posting. This
             | run exists to tell the person typing what is wrong while they
             | still have the supplier's document in front of them, which is
             | the only moment at which "billed 120, received 100" is cheap
             | to resolve.
             |
             | Invoices only. A credit note does not go through procurement:
             | nothing was ordered and nothing arrived, so there is nothing
             | for three documents to disagree about - and under
             | `purchasing.require_order` every credit note would otherwise
             | raise a `no_order` exception somebody has to clear.
             */
            if ($documentType === '380') {
                $this->match->match($bill->refresh());
            }

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
     * Post a bill to the ledger.
     *
     * ## It does not say "an approved bill", and it used to - PF-245
     *
     * Nothing approves a bill. `bills_status_is_known` permits
     * `awaiting_approval` and `approved`, and this service writes `draft`,
     * `posted` and now `cancelled`: the two approval states exist in the
     * constraint and in no row. `ApprovalEngine` routes requisitions and
     * expense claims; it has never been asked about a bill.
     *
     * So the gates here are the real ones - a credit note goes through its
     * own rule, an unacknowledged duplicate refuses, the three-way match
     * runs twice - and approval is not among them. The docblock said
     * otherwise, which is the worse kind of wrong: a reader checking whether
     * payables approval is enforced finds a sentence saying it is.
     *
     * Five read sites filter `status IN ('approved', 'posted')` - the AP
     * ageing in three places, bank matching and the overview - and the
     * `'approved'` half of each matches nothing. Whether an approved but
     * unposted bill BELONGS in an ageing that reconciles to the ledger is a
     * product decision, not a defect to quietly resolve, and it is recorded
     * as one in the roadmap register.
     *
     * @throws BillRefused
     */
    public function post(Bill $bill, Entity $entity, ?string $userId = null): Bill
    {
        $bill = $bill->refresh();

        if ($bill->isPosted()) {
            return $bill;
        }

        /*
         | A credit note is not posted here - PF-134.
         |
         | `BillPostedRule` would credit the payable and debit the expense,
         | which is the opposite of what a credit note does. The entry would
         | balance, so nothing would look wrong: the supplier's balance
         | would grow by the credit instead of shrinking, and the difference
         | is found by whoever reconciles their statement.
         */
        if ($bill->isCreditNote()) {
            throw BillRefused::because(sprintf(
                'Document %s is a credit note and is posted by its own rule, not this one. Posting it '.
                'here would credit the payable and debit the expense - the opposite of what it does - '.
                "and the entry would balance, so the supplier's balance would grow by the credit ".
                'instead of shrinking and nothing would look wrong.',
                (string) $bill->supplier_document_number,
            ));
        }

        $unanswered = $this->duplicates->unacknowledged($bill);

        if ($unanswered->isNotEmpty()) {
            throw BillRefused::unacknowledgedDuplicates($unanswered->count());
        }

        /*
         | The match, re-run at posting, and OUTSIDE the transaction.
         |
         | Re-run rather than trusting the entry-time result, because the
         | order, the receipt and the bill can all have moved since: a
         | receipt reversed, another bill posted against the same line, the
         | order closed. A match that was clean last week is not evidence
         | that it is clean now.
         |
         | Outside the transaction because a refusal has to LEAVE something
         | behind. The exceptions this run writes are the queue somebody
         | works tomorrow, and raising them inside the transaction that the
         | refusal rolls back would hand the caller a message and keep no
         | record of it - the one failure mode an exception queue cannot
         | have.
         */
        $mismatches = $this->match->match($bill);

        if ($mismatches !== []) {
            throw BillRefused::openMatchExceptions(array_map(
                static fn (ThreeWayMatchException $e): string => (string) $e->detail,
                $mismatches,
            ));
        }

        return DB::connection('tenant')->transaction(function () use ($bill, $entity, $userId): Bill {
            /*
             | And the run that consumes, inside the transaction.
             |
             | Advancing each order line's `quantity_billed` has to succeed
             | or fail with the posting. Outside it, a posting that then
             | failed - a closed period, an unbalanced entry - would leave
             | the order lines consumed against a bill that never posted,
             | and the next bill for those goods would match against a
             | quantity already spoken for.
             |
             | It is the same comparison a second time, which costs a few
             | queries and buys the two guarantees above. A mismatch here is
             | a race rather than a discrepancy - somebody posted against
             | the same line between the two runs - and it refuses, which is
             | the right answer to a race.
             */
            $raced = $this->match->match($bill, consume: true);

            if ($raced !== []) {
                throw BillRefused::openMatchExceptions(array_map(
                    static fn (ThreeWayMatchException $e): string => (string) $e->detail,
                    $raced,
                ));
            }

            $event = $this->event($bill, $entity);

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

            /*
             | And the accrual the receipt raised is released - PF-132.
             |
             | On this bill's posting date, because the accrual belongs to
             | the month the goods arrived in and its release belongs to the
             | month the invoice arrived. Only receipts now fully billed: a
             | receipt of a hundred against which sixty have been invoiced
             | still has forty units of goods accrued, and releasing the
             | whole entry would understate a liability that exists.
             */
            $released = $this->match->releaseAccruals($bill);

            $this->audit->record(
                category: 'purchasing',
                action: 'bill.posted',
                subjectType: 'bill',
                subjectId: (string) $bill->getKey(),
                subjectLabel: (string) $bill->supplier_document_number,
                after: [
                    'journal_entry_id' => (string) $entry->getKey(),
                    'accruals_released' => $released,
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
     * Say that a bill is not a bill - PF-245.
     *
     * ## The third exit, which did not exist
     *
     * `bills_status_is_known` has permitted `cancelled` since the table was
     * created. Nothing wrote it: this service writes `draft` on entry and
     * `posted` on posting, and had no other public method that changes a
     * status. So a bill keyed twice, or keyed against the wrong supplier,
     * stayed a draft for ever.
     *
     * Untidy, and that is not the cost. The erroneous bill goes on acting as
     * a bill: {@see DuplicateBillDetector::examine()} searches candidates in
     * `('draft', 'awaiting_approval', 'approved', 'posted')` and
     * {@see self::post()} refuses a bill carrying any unacknowledged
     * warning. So the CORRECT bill could not be posted until somebody
     * acknowledged a suspicion about a mistake everybody agreed was a
     * mistake - and acknowledging records, in the audit trail, that a
     * duplicate was reviewed and accepted. The only available answer was the
     * wrong one.
     *
     * ## A posted bill is not cancelled
     *
     * It is reversed, and the difference is the ledger. Cancelling a posted
     * bill would leave its journal entry standing with no document behind
     * it: the payable control account would carry a balance the payables
     * subledger cannot explain, which
     * {@see \App\Tenant\Reporting\Verification\SubledgersTieToTheirControls}
     * reports as a mismatch - correctly, and with no way to tell from the
     * bills table what caused it.
     *
     * `bills_cancelled_holds_no_entry` is the same rule in the database, so
     * a path that did not come through here cannot do it either.
     *
     * ## And the warnings that named it stop gating, without being rewritten
     *
     * The warning said "this looks like bill X". Once X is cancelled the
     * suspicion is answered - not accepted, answered - and
     * {@see DuplicateBillDetector::unacknowledged()} stops returning it.
     *
     * Nothing is written onto the warning row, deliberately. Marking it
     * acknowledged would say in the record that a person reviewed a
     * suspected duplicate and went ahead anyway, which nobody did. The row
     * stays exactly as it was, still readable and still true.
     *
     * @throws BillRefused
     */
    public function cancel(Bill $bill, string $reason, ?string $userId = null): Bill
    {
        $bill = $bill->refresh();

        if ($bill->isCancelled()) {
            return $bill;
        }

        if ($bill->isPosted()) {
            throw BillRefused::because(sprintf(
                'Bill %s is posted and cannot be cancelled. It has a journal entry, and cancelling it '.
                'would leave that entry standing with no document behind it - the payable control '.
                'account would carry a balance the payables subledger cannot explain. Reverse it, or '.
                'record the credit note the supplier issued.',
                (string) $bill->supplier_document_number,
            ));
        }

        $reason = trim($reason);

        /*
         | A reason, and a real one.
         |
         | The question asked about a cancelled bill is never "when". It is
         | "why is there a gap in this supplier's document numbers", six
         | months later, by somebody reconciling a statement - and the answer
         | has to be on the row, because the person who knew is not in the
         | conversation.
         |
         | Ten characters is not a quality bar and is not meant to be. It
         | refuses the empty string and the single keystroke, which is all a
         | length can honestly refuse.
         */
        if (mb_strlen($reason) < 10) {
            throw BillRefused::because(
                'Cancelling a bill needs a reason of at least ten characters. The question asked about '.
                'a cancelled bill is not when it was cancelled but why there is a gap in that '.
                "supplier's document numbers, and it is asked months later by somebody who was not ".
                'there.',
            );
        }

        return DB::connection('tenant')->transaction(function () use ($bill, $reason, $userId): Bill {
            $was = (string) $bill->status;

            $bill->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
            ]);

            $this->audit->record(
                category: 'purchasing',
                action: 'bill.cancelled',
                subjectType: 'bill',
                subjectId: (string) $bill->getKey(),
                subjectLabel: (string) $bill->supplier_document_number,
                before: ['status' => $was],
                after: ['status' => 'cancelled'],
                reason: $reason,
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
     * ## The figures are the DOCUMENT's, and the currency travels with them
     *
     * Every amount here is in the supplier's currency, because that is what
     * the document says and what the supplier will be paid. The posting rule
     * is told which currency that is and the rate to translate it at, rather
     * than being left to assume the entity's own: that assumption is what
     * posts a 150,000 JPY bill as 150,000 of the functional currency, which
     * balances and is wrong by the whole exchange difference.
     *
     * @return array<string, mixed>
     *
     * @throws BillRefused
     */
    public function event(Bill $bill, Entity $entity): array
    {
        $currency = (string) $bill->currency;
        $rate = $this->rateToPostAt($bill, $entity);
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
            $jurisdiction = $this->jurisdictionOf($bill);
            $recoverableAmount = Money::of((string) $group->recoverable_amount, $currency);
            $groupTax = Money::of((string) $group->tax_amount, $currency);

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
                 | Three legs, not two, and the third is why this was wrong.
                 |
                 | The recipient OWES the authority the whole tax and may
                 | CLAIM only the recoverable share of it. The difference is
                 | cost and rides with the line's expense, which the loop
                 | above already does through `nonRecoverableTax()`.
                 |
                 |   Dr expense       net + the irrecoverable share
                 |   Dr vat_input     the recoverable share
                 |   Cr vat_output    the WHOLE tax
                 |
                 | One figure used to be carried for both tax legs, which is
                 | right at 100% recovery - the only recovery percentage any
                 | fixture had ever combined with a reverse charge. At 50% it
                 | credited the output with the recoverable half, understating
                 | the liability by the other half. At 0% the zero-amount
                 | guard above skipped the group before it reached this
                 | branch, so NEITHER leg posted and the whole tax ended up in
                 | `rounding_difference` - an account whose own docblock is
                 | about sub-cent translation error.
                 |
                 | The guard is gone from the top of the loop for that
                 | reason: a reverse-charge group with nothing recoverable
                 | still carries a liability. A group with no tax at all is
                 | skipped here instead, where it means what it says.
                 */
                if ($groupTax->isZero()) {
                    continue;
                }

                $reverseCharge[] = $entry + [
                    'liability' => (string) $groupTax->amount,
                ];

                continue;
            }

            if ($recoverableAmount->isZero()) {
                continue;
            }

            $recoverable[] = $entry;
        }

        return [
            'bill_id' => (string) $bill->getKey(),
            'party_id' => (string) $bill->party_id,
            'payable' => (string) $bill->payable_amount,
            'currency' => $currency,
            'rate' => $rate,
            'narration' => null,
            'expense' => $expense,
            'recoverable_tax' => $recoverable,
            'reverse_charge' => $reverseCharge,
        ];
    }

    /**
     * The rate a bill in a foreign currency books at - PF-133, PF-068.
     *
     * Null when the bill is already in the entity's own currency, which is
     * the common case and is NOT a rate of 1: a stored 1 asserts that a
     * translation happened and came out even, and afterwards it cannot be
     * told from a document that never needed one. The database refuses both
     * halves of that mistake on `journal_lines`, and this is the service
     * side of the same rule.
     *
     * Null too when no rate is on file, so that entering the document is
     * never blocked by a feed; {@see rateToPostAt()} is where the absence
     * becomes a refusal.
     *
     * The tax point rather than today or the posting date. IAS 21 books a
     * transaction at the rate on the date of the transaction, and for a
     * purchase that date is the one the jurisdiction fixed: a bill dated 28
     * March that arrives on 3 April belongs to our April and to March's
     * rate, and using April's would move the expense and leave an exchange
     * difference that cannot be derived from the document.
     */
    private function rateIfOnFile(Entity $entity, string $currency, Carbon $taxPoint): ?Rate
    {
        $functional = (string) $entity->functional_currency;

        if ($currency === $functional) {
            return null;
        }

        try {
            return $this->rates->rateFor($currency, $functional, $taxPoint);
        } catch (RateNotAvailable) {
            return null;
        }
    }

    /**
     * The rate this bill posts at, and the refusal when there is none.
     *
     * The frozen one wins: rebuilt from the document's own columns rather
     * than fetched again, so the posting books at the rate the approval was
     * tested against. The set is the market one because that is the only
     * set {@see rateIfOnFile()} asks for - a purchase is a transaction at
     * the rate of the day, and the monthly-average and statutory sets exist
     * for revaluation and for returns.
     *
     * Where the document carries none - entered while the feed was behind -
     * the rate is fetched now and still **on the tax point**, never on
     * today, and written back so the document records what it booked at.
     *
     * @throws BillRefused
     */
    private function rateToPostAt(Bill $bill, Entity $entity): ?Rate
    {
        $functional = (string) $entity->functional_currency;
        $currency = (string) $bill->currency;

        if ($currency === $functional) {
            return null;
        }

        if ($bill->fx_rate !== null && $bill->fx_rate_date !== null) {
            return new Rate(
                from: $currency,
                to: $functional,
                value: (string) $bill->fx_rate,
                date: $bill->fx_rate_date->toDateTimeImmutable(),
                provider: (string) ($bill->fx_source ?? ExchangeRates::PROVIDER_MANUAL),
                rateSet: Rate::SET_MARKET,
            );
        }

        try {
            $rate = $this->rates->rateFor(
                $currency,
                $functional,
                Carbon::parse($bill->tax_point->toDateString()),
            );
        } catch (RateNotAvailable $e) {
            throw BillRefused::noRateOnTheTaxPoint(
                $currency,
                $functional,
                $bill->tax_point->toDateString(),
                $e->getMessage(),
            );
        }

        $bill->update([
            'fx_rate' => (string) $rate->value,
            'fx_source' => $rate->provider,
            'fx_rate_date' => $rate->date->format('Y-m-d'),
        ]);

        return $rate;
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

            /*
             | The bill-level recoverable accumulator counts NON-reverse-
             | charge lines only, and that is the whole of PF-079's defect.
             |
             | `bills` carries a database CHECK that `tax_amount =
             | recoverable_tax_amount + non_recoverable_tax_amount`, and
             | `tax_amount` is the tax payable TO THE SUPPLIER - which under
             | the reverse charge is nothing, because they charged nothing.
             | So the two columns are about the payable tax and a reverse-
             | charge line contributes to neither.
             |
             | It used to add every line here and then subtract the WHOLE
             | reverse-charge tax at the end, from an accumulator that had
             | only ever held the recoverable SHARE of it. On a 50% line the
             | columns came out as -150 and +150: wrong in opposite
             | directions by the same amount, so the CHECK was satisfied and
             | the row looked consistent. A negative recoverable tax is not
             | a smaller claim, it is a claim in the wrong direction.
             |
             | The GROUP's own `recoverable` below still counts it, because
             | that is what the recipient may claim back from the authority -
             | a different question from what the supplier is owed.
             */
            if (! $reverse) {
                $recoverable = $recoverable->plus($lineRecoverable);
            }

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
            'recoverable' => $recoverable,
            'non_recoverable' => $payableTax->minus($recoverable),
            'groups' => $groups,
        ];
    }

    /**
     * Which jurisdiction's tax accounts this document's tax belongs to.
     *
     * ## It used to be Saudi Arabia, always
     *
     * The read was `tax_decision_snapshot['jurisdiction'] ?? 'SA'`, and it
     * was wrong twice over. Nothing writes a bill's
     * `tax_decision_snapshot` at all, so the fallback was the only branch
     * that ever ran - and even once something does write it,
     * `PlaceOfSupplyDecision::jsonSerialize()` spells that key `country`,
     * so the lookup would have missed a correctly frozen decision and kept
     * falling back. Every bill's input and output tax posted to Saudi
     * control accounts, in every entity, in every country.
     *
     * The sales side reads `country` and has done since PF-089.
     *
     * The entity's own country is the fallback now rather than a literal,
     * because an entity that has not frozen a place-of-supply decision is
     * most likely buying at home - and if it is not, a tax account in the
     * wrong country is a figure in a return the entity does not file, which
     * is visible, rather than a figure in a Saudi return it does not file
     * either, which looks ordinary.
     */
    private function jurisdictionOf(Bill $document): string
    {
        $frozen = $document->tax_decision_snapshot['country'] ?? null;

        if (\is_string($frozen) && $frozen !== '') {
            return $frozen;
        }

        return (string) $document->entity->country;
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
                /*
                 | What this line claims to be against - PF-135.
                 |
                 | On the line rather than the bill, because one bill
                 | routinely spans several orders and a bill-level pointer
                 | could not say which line came from which.
                 */
                'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                'goods_receipt_line_id' => $line['goods_receipt_line_id'] ?? null,
                'credits_line_id' => $line['credits_line_id'] ?? null,
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
