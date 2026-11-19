<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Purchasing\Exceptions\BillRefused;
use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Purchasing\Models\BillCreditAllocation;
use App\Tenant\Purchasing\Services\Bills;
use App\Tenant\Purchasing\Services\PayablesAging;
use App\Tenant\Purchasing\Services\SupplierCredits;
use App\Tenant\Tenancy\Services\Settings;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Supplier credit notes, AP aging and statements - PF-134, PF-144.
 *
 * ## The two things a credit note gets wrong when it is a negative bill
 *
 * Every query for "what do we owe this supplier" becomes a sum somebody can
 * forget to write, and the first report that forgets shows a credit as a
 * payable. And the posting becomes a negation of the original's rows, which
 * is wrong the moment the credit is partial: a supplier who credits one line
 * of a four-line invoice credits one expense account and the tax on that
 * line, not a proportion of everything.
 *
 * ## The two things an aging report gets wrong
 *
 * Instalments, and its own reconciliation. A bill on 30/60/90 terms aged
 * from its last due date hides two months of overdue money, and an aging
 * total that disagrees with the control account is two numbers both used to
 * pay people.
 */
final class SupplierCreditRules
{
    use \Tests\Support\Concerns\EntersBills;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['supplier credits (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedThePayableSide();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                // ---- Credit notes - PF-134 -------------------------------
                'a full credit mirrors the bill' => fn (): array => $this->fullCredit(),
                'posting debits the payable and credits the expense' => fn (): array => $this->creditPosting(),
                'a partial credit carries only its lines\' tax' => fn (): array => $this->lineCredit(),
                'an amount-only credit is apportioned across the rate groups' => fn (): array => $this->amountCredit(),
                'a credit beyond the bill is refused by the service' => fn (): array => $this->overCreditService(),
                'and by the database, which an import cannot bypass' => fn (): array => $this->overCreditDatabase(),
                'two credits totalling more than the bill are refused' => fn (): array => $this->twoCredits(),
                'a credit note must say what it credits' => fn (): array => $this->mandatoryReference(),
                'a credit note is not posted by the bill rule' => fn (): array => $this->wrongRule(),
                'a credit note crossing suppliers is refused' => fn (): array => $this->crossSupplier(),
                'a credit note crossing currencies is refused' => fn (): array => $this->crossCurrency(),
                'allocation posts nothing and reduces the bill' => fn (): array => $this->allocation(),
                'allocation beyond the credit is refused' => fn (): array => $this->overAllocation(),
                'allocation beyond the bill is refused' => fn (): array => $this->overAllocationOnTheBill(),
                'allocating a credit to the bill it already credits is refused' => fn (): array => $this->doubleReduction(),
                'a credit marked for refund is not allocated as well' => fn (): array => $this->refundThenAllocate(),
                'and an allocated credit is not refunded as well' => fn (): array => $this->allocateThenRefund(),
                'a credit note carries the reverse charge both ways' => fn (): array => $this->reverseCharge(),

                // ---- Aging and statements - PF-144 -----------------------
                'the aging report states its basis' => fn (): array => $this->agingBasis(),
                'the basis changes which bucket a bill lands in' => fn (): array => $this->basisChangesBuckets(),
                'instalments are aged separately' => fn (): array => $this->instalments(),
                'the aging reconciles to the control account' => fn (): array => $this->reconciliation(),
                'a draft credit note changes neither the aging nor the ledger' => fn (): array => $this->draftCredit(),
                'and says so when it does not' => fn (): array => $this->reconciliationDisagrees(),
                'a credit note reduces the aging rather than adding to it' => fn (): array => $this->agingWithCredit(),
                'the aging keeps currencies apart' => fn (): array => $this->currenciesApart(),
                'DPO is null rather than zero with no purchases' => fn (): array => $this->dpoWithNoPurchases(),
                'DPO divides the payable by the window\'s purchases' => fn (): array => $this->dpo(),
                'an open-item statement lists only what is still owed' => fn (): array => $this->openItem(),
                'a balance-forward statement runs from an opening balance' => fn (): array => $this->balanceForward(),
                'and refuses to add two currencies into one balance' => fn (): array => $this->balanceForwardCurrencies(),
                'and the ledger still proves its own invariants' => fn (): array => [],
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function fullCredit(): array
    {
        $violations = [];
        $bill = $this->postedBill();
        $credit = $this->creditFull($bill);

        if ((string) $credit->document_type !== '381') {
            $violations[] = sprintf(
                'the credit note is document type %s. A credit note stored as a negative bill makes '.
                '"what do we owe" a sum somebody can forget to write, and the first report that '.
                'forgets shows a credit as a payable',
                (string) $credit->document_type,
            );
        }

        if (BigDecimal::of((string) $credit->gross_amount)->isNegative()) {
            $violations[] = 'the credit note carries negative figures, and the supplier\'s document does '.
                'not. The sign is the posting rule\'s business';
        }

        if (! BigDecimal::of((string) $credit->gross_amount)->isEqualTo((string) $bill->gross_amount)) {
            $violations[] = sprintf(
                'a full credit of a %s bill is %s',
                (string) $bill->gross_amount,
                (string) $credit->gross_amount,
            );
        }

        if (! $bill->refresh()->load('creditNotes')->remainingUncredited()->isZero()) {
            $violations[] = sprintf(
                'after a full credit the bill still has %s uncredited',
                (string) $bill->refresh()->load('creditNotes')->remainingUncredited()->amount,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function creditPosting(): array
    {
        $violations = [];
        $bill = $this->postedBill();
        $credit = $this->creditFull($bill);
        $credit = app(SupplierCredits::class)->post($credit, $this->entity(), $this->actorId());

        $lines = $this->linesOfEntry((string) $credit->journal_entry_id);

        $payable = $this->amountOnRole($lines, 'accounts_payable');
        $input = $this->amountOnRole($lines, 'vat_input');
        $expense = $this->amountOnAccount($lines, $this->expenseAccountId);

        if (! BigDecimal::of($payable)->isEqualTo('1150.0000')) {
            $violations[] = sprintf(
                'the credit note debits the payable %s and should reduce what we owe by 1,150',
                $payable,
            );
        }

        if (! BigDecimal::of($input)->isEqualTo('-150.0000')) {
            $violations[] = sprintf('the input tax given back is %s and the bill claimed 150', $input);
        }

        if (! BigDecimal::of($expense)->isEqualTo('-1000.0000')) {
            $violations[] = sprintf('the expense credited is %s and the purchase was 1,000', $expense);
        }

        return $violations;
    }

    /** @return list<string> */
    private function lineCredit(): array
    {
        $violations = [];

        /*
         | Three lines, and the document's tax is not any line's tax.
         |
         | Measured: with 1,000 at 15% and 400 at 0%, the bill's tax and the
         | first line's tax were both 150 - so a bite that took the share
         | from the DOCUMENT total instead of the line changed nothing and
         | walked past this check. A second standard-rated line makes the
         | two figures differ, which is what the check is about.
         */
        $bill = $this->postedBill(lines: [
            $this->billLine('Machined brackets', '1000.0000', '15.000000', '150.0000', $this->taxCodeId),
            $this->billLine('Fixings', '600.0000', '15.000000', '90.0000', $this->taxCodeId),
            $this->billLine('Export advisory', '400.0000', '0.000000', '0.0000', $this->zeroRatedTaxCodeId, 'Z'),
        ]);

        $standard = $bill->lines->first();

        $credit = app(SupplierCredits::class)->forLines(
            entity: $this->entity(),
            bill: $bill,
            selections: [[
                'line_id' => (string) $standard->getKey(),
                'net' => '500.0000',
            ]],
            supplierDocumentNumber: 'CN-'.Str::upper(Str::random(6)),
            supplierDocumentDate: $this->date->copy(),
            createdBy: $this->actorId(),
            reason: 'half the batch went back with cracked housings',
        );

        /*
         | Half of ONE line, so half of THAT line's tax.
         |
         | Not half the bill's tax: the zero-rated line was not credited and
         | a proportional split of the document total would give back tax
         | the supplier never charged on it.
         */
        if (! BigDecimal::of((string) $credit->tax_amount)->isEqualTo('75.0000')) {
            $violations[] = sprintf(
                'the credit\'s tax is %s and half of a 1,000 line at 15%% is 75. The document\'s own tax '.
                'is 240, so a credit that took a share of the document total would give back tax on '.
                'lines the supplier never credited - including the zero-rated one, where it charged '.
                'none at all',
                (string) $credit->tax_amount,
            );
        }

        if (! BigDecimal::of((string) $credit->net_amount)->isEqualTo('500.0000')) {
            $violations[] = sprintf('the credit nets %s and half the line is 500', (string) $credit->net_amount);
        }

        $creditLine = $credit->lines->first();

        if ($creditLine->credits_line_id === null) {
            $violations[] = 'the credit line does not record which line it credits, so the bill cannot '.
                'show which of its lines have been credited and by how much';
        }

        return $violations;
    }

    /** @return list<string> */
    private function amountCredit(): array
    {
        $violations = [];

        $bill = $this->postedBill(lines: [
            $this->billLine('Machined brackets', '1000.0000', '15.000000', '150.0000', $this->taxCodeId),
            $this->billLine('Export advisory', '400.0000', '0.000000', '0.0000', $this->zeroRatedTaxCodeId, 'Z'),
        ]);

        // A 500 settlement on a 1,550 bill - 1,150 standard-rated, 400 zero-rated.
        $credit = app(SupplierCredits::class)->forAnAmount(
            entity: $this->entity(),
            bill: $bill,
            gross: '500.0000',
            supplierDocumentNumber: 'CN-'.Str::upper(Str::random(6)),
            supplierDocumentDate: $this->date->copy(),
            createdBy: $this->actorId(),
            reason: 'a settlement of the quality dispute',
        );

        if (! BigDecimal::of((string) $credit->gross_amount)->isEqualTo('500.0000')) {
            $violations[] = sprintf(
                'the apportioned credit grosses %s and 500 was agreed. The net and the tax of every '.
                'share have to add back to what the supplier credited exactly',
                (string) $credit->gross_amount,
            );
        }

        $groups = $credit->taxBreakdown;

        if ($groups->count() < 2) {
            $violations[] = sprintf(
                'the apportioned credit has %d rate group(s) and the bill has two. A 500 settlement on '.
                'a bill with 15%% and 0%% lines is not a 15%% credit, and choosing one rate would '.
                'misstate both boxes of the return',
                $groups->count(),
            );
        }

        $tax = BigDecimal::of((string) $credit->tax_amount);

        /*
         | 1,150 of the 1,550 gross is standard-rated, so 371.00 of the 500
         | share carries tax at 15%: 371.00 / 1.15 = 322.61 net, 48.39 tax.
         | The figure is asserted as a range rather than a constant because
         | the allocation's remainder lands on one line, and which line is
         | the allocator's business rather than this check's.
         */
        if ($tax->isGreaterThan('49.00') || $tax->isLessThan('48.00')) {
            $violations[] = sprintf(
                'the apportioned tax is %s. Of a 500 credit on a bill that is 1,150 standard-rated and '.
                '400 zero-rated of 1,550, about 371 carries tax at 15%% - which is about 48.39',
                (string) $tax,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function overCreditService(): array
    {
        $bill = $this->postedBill();

        try {
            app(SupplierCredits::class)->forAnAmount(
                entity: $this->entity(),
                bill: $bill,
                gross: '2000.0000',
                supplierDocumentNumber: 'CN-'.Str::upper(Str::random(6)),
                supplierDocumentDate: $this->date->copy(),
                createdBy: $this->actorId(),
            );

            return [
                'a 1,150 bill was credited 2,000. A credit beyond what was charged is a refund the '.
                'supplier has not agreed to, and the difference is found by whoever reconciles their '.
                'statement',
            ];
        } catch (BillRefused $e) {
            /*
             | The SERVICE's words, not the database's.
             |
             | Measured: with the service check removed the trigger refuses
             | the same insert and `write()` rethrows it as a `BillRefused`,
             | so a check that only caught the exception type passed with the
             | service check gone. The two messages differ - this is the
             | service's - and the database's own refusal has a check of its
             | own below.
             */
            if (! str_contains($e->getMessage(), 'has already been credited, leaving')) {
                return [sprintf(
                    'the refusal did not come from the service, which is the one that can say what was '.
                    'charged and what is left: "%s"',
                    $e->getMessage(),
                )];
            }

            if (! str_contains($e->getMessage(), '1,150') && ! str_contains($e->getMessage(), '1150')) {
                return ['the refusal does not name what was charged: '.$e->getMessage()];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function overCreditDatabase(): array
    {
        $bill = $this->postedBill();
        $credit = $this->creditFull($bill);

        /*
         | Straight at the table, as an import would.
         |
         | The service check read the total before writing; this writes
         | without asking. If the database does not hold the line, two
         | credit notes entered at the same moment would both pass.
         */
        try {
            DB::connection('tenant')->table('bills')
                ->where('id', (string) $credit->getKey())
                ->update(['gross_amount' => '5000.0000']);

            return [
                'the database accepted a credit note worth 5,000 against a 1,150 bill, so an import, a '.
                'second worker or a retried job can credit a bill beyond what it charged',
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'beyond what was charged')) {
                return ['the over-credit was refused by something other than the trigger for it: '.$e->getMessage()];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function twoCredits(): array
    {
        $bill = $this->postedBill();

        app(SupplierCredits::class)->forAnAmount(
            entity: $this->entity(),
            bill: $bill,
            gross: '800.0000',
            supplierDocumentNumber: 'CN-'.Str::upper(Str::random(6)),
            supplierDocumentDate: $this->date->copy(),
            createdBy: $this->actorId(),
        );

        try {
            app(SupplierCredits::class)->forAnAmount(
                entity: $this->entity(),
                bill: $bill->refresh(),
                gross: '500.0000',
                supplierDocumentNumber: 'CN-'.Str::upper(Str::random(6)),
                supplierDocumentDate: $this->date->copy(),
                createdBy: $this->actorId(),
            );

            return [
                '800 and then 500 were credited against a 1,150 bill. The second credit has to be '.
                'measured against what is left, not against the whole bill',
            ];
        } catch (BillRefused $e) {
            if (! str_contains($e->getMessage(), '800') && ! str_contains($e->getMessage(), '350')) {
                return ['the refusal does not say what has already been credited: '.$e->getMessage()];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function mandatoryReference(): array
    {
        $violations = [];

        try {
            app(SupplierCredits::class)->standalone(
                entity: $this->entity(),
                partyId: (string) $this->supplier?->getKey(),
                lines: [$this->billLine('Quarterly rebate', '1000.0000', '15.000000', '150.0000', $this->taxCodeId)],
                supplierDocumentNumber: 'CN-'.Str::upper(Str::random(6)),
                supplierDocumentDate: $this->date->copy(),
                reference: 'x',
                createdBy: $this->actorId(),
            );

            $violations[] = 'a standalone credit note was accepted with "x" as its reference. A credit '.
                'against nothing is a figure nobody can tie to a purchase';
        } catch (BillRefused $e) {
            /*
             | And the service's own words, for the same reason as above.
             |
             | The constraint refuses the insert too, and `write()` rethrows
             | it as the same exception type - so without this the check
             | passed with the service's own refusal removed.
             */
            if (! str_contains($e->getMessage(), 'has to say what it is for')) {
                $violations[] = sprintf(
                    'the refusal came from the database rather than from the service, which is the one '.
                    'that can tell somebody what to type: "%s"',
                    $e->getMessage(),
                );
            }
        }

        // A rebate with a real reference is accepted - it genuinely has no one invoice behind it.
        $rebate = app(SupplierCredits::class)->standalone(
            entity: $this->entity(),
            partyId: (string) $this->supplier?->getKey(),
            lines: [$this->billLine('Quarterly rebate', '1000.0000', '15.000000', '150.0000', $this->taxCodeId)],
            supplierDocumentNumber: 'CN-'.Str::upper(Str::random(6)),
            supplierDocumentDate: $this->date->copy(),
            reference: 'Q1 volume rebate under agreement GC-2026-04',
            createdBy: $this->actorId(),
        );

        if ($rebate->credits_bill_id !== null) {
            $violations[] = 'a standalone rebate was attached to a bill it does not credit';
        }

        /*
         | And the database holds the same line.
         |
         | A credit note with neither a bill nor a reference is the state an
         | import produces, and it is the shape a supplier's balance takes
         | when somebody has keyed a rebate twice.
         */
        try {
            DB::connection('tenant')->table('bills')
                ->where('id', (string) $rebate->getKey())
                ->update(['credit_reference' => null]);

            $violations[] = 'the database accepted a credit note with no bill and no reference';
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'says_what_it_credits')) {
                $violations[] = 'the reference was refused by something other than its constraint: '.$e->getMessage();
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function wrongRule(): array
    {
        $bill = $this->postedBill();
        $credit = $this->creditFull($bill);

        /*
         | The near-match warning is answered first.
         |
         | A full credit note is the same supplier, the same date and the
         | same total as the bill, so the duplicate detector warns - and
         | `post()` refuses an unanswered warning before it reaches anything
         | else. Measured: without this, the check passed with the
         | credit-note guard removed, because the refusal it caught was the
         | duplicate detector's.
         */
        foreach (app(\App\Tenant\Purchasing\Services\DuplicateBillDetector::class)->unacknowledged($credit) as $warning) {
            app(\App\Tenant\Purchasing\Services\DuplicateBillDetector::class)->acknowledge(
                $warning,
                $this->actorId(),
                'it is the credit note for that invoice, which is why the figures match',
            );
        }

        try {
            app(Bills::class)->post($credit, $this->entity(), $this->actorId());

            return [
                'a credit note was posted through the bill rule, which credits the payable and debits '.
                'the expense - the opposite of what it does. The entry balances, so the supplier\'s '.
                'balance grows by the credit instead of shrinking and nothing looks wrong',
            ];
        } catch (BillRefused $e) {
            if (! str_contains($e->getMessage(), 'credit note')) {
                return [sprintf(
                    'the posting was refused for some other reason, so this check is not exercising '.
                    'the credit-note guard: "%s"',
                    $e->getMessage(),
                )];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function crossSupplier(): array
    {
        $bill = $this->postedBill();

        $other = \App\Tenant\Parties\Models\Party::create([
            'code' => 'SUP-'.Str::upper(Str::random(6)),
            'legal_name' => 'A different supplier',
            'is_supplier' => true,
            'country' => 'SA',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        $rebate = app(SupplierCredits::class)->standalone(
            entity: $this->entity(),
            partyId: (string) $other->getKey(),
            lines: [$this->billLine('Rebate', '100.0000', '15.000000', '15.0000', $this->taxCodeId)],
            supplierDocumentNumber: 'CN-'.Str::upper(Str::random(6)),
            supplierDocumentDate: $this->date->copy(),
            reference: 'a rebate from the other supplier',
            createdBy: $this->actorId(),
        );

        try {
            DB::connection('tenant')->table('bills')
                ->where('id', (string) $rebate->getKey())
                ->update(['credits_bill_id' => (string) $bill->getKey()]);

            return [
                'a credit note from one supplier was attached to another supplier\'s bill. The credit '.
                'would reduce a balance that was never owed to whoever issued it',
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'another supplier')) {
                return ['the cross-supplier credit was refused by something else: '.$e->getMessage()];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function crossCurrency(): array
    {
        $bill = $this->postedBill();

        $rebate = app(SupplierCredits::class)->standalone(
            entity: $this->entity(),
            partyId: (string) $this->supplier?->getKey(),
            lines: [$this->billLine('Rebate', '100.0000', '0.000000', '0.0000', null)],
            supplierDocumentNumber: 'CN-'.Str::upper(Str::random(6)),
            supplierDocumentDate: $this->date->copy(),
            reference: 'a rebate billed in dollars',
            currency: 'USD',
            createdBy: $this->actorId(),
        );

        try {
            DB::connection('tenant')->table('bills')
                ->where('id', (string) $rebate->getKey())
                ->update(['credits_bill_id' => (string) $bill->getKey()]);

            return [
                'a credit note in USD was attached to a bill in SAR. Converting one to compare them '.
                'would make the remaining uncredited value depend on the day somebody asked',
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'cannot credit a bill in')) {
                return ['the cross-currency credit was refused by something else: '.$e->getMessage()];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function allocation(): array
    {
        $violations = [];

        $first = $this->postedBill();
        $second = $this->postedBill(net: '2000.0000');

        $credit = $this->creditFull($first);
        $credit = app(SupplierCredits::class)->post($credit, $this->entity(), $this->actorId());

        $before = DB::connection('tenant')->table('journal_entries')->count();

        app(SupplierCredits::class)->allocate(
            $credit,
            $second,
            '1150.0000',
            $this->actorId(),
            'applied against the next invoice at the supplier\'s request',
        );

        $after = DB::connection('tenant')->table('journal_entries')->count();

        if ($after !== $before) {
            $violations[] = sprintf(
                'allocating a credit posted %d journal entries. Both documents are in accounts_payable '.
                'against the same party, so netting them changes no balance: the allocation makes the '.
                'open-item statement right, and a journal for it would write two entries that cancel',
                $after - $before,
            );
        }

        $outstanding = $second->refresh()->load(['creditNotes', 'creditAllocations'])->outstanding();

        // 2,000 net at 15% is 2,300 gross, less the 1,150 allocated.
        if (! BigDecimal::of((string) $outstanding->amount)->isEqualTo('1150.0000')) {
            $violations[] = sprintf(
                'the bill the credit was allocated to shows %s outstanding, and 2,300 less a 1,150 '.
                'allocation is 1,150',
                (string) $outstanding->amount,
            );
        }

        if (! app(SupplierCredits::class)->unallocated($credit)->isZero()) {
            $violations[] = 'the credit note still reads as unallocated after being used in full';
        }

        return $violations;
    }

    /** @return list<string> */
    private function overAllocation(): array
    {
        $first = $this->postedBill();
        $second = $this->postedBill(net: '3000.0000');
        $credit = $this->creditFull($first);

        try {
            app(SupplierCredits::class)->allocate($credit, $second, '2000.0000', $this->actorId());

            return ['a 1,150 credit was allocated 2,000 against a bill'];
        } catch (BillRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function overAllocationOnTheBill(): array
    {
        $violations = [];

        $first = $this->postedBill();

        /*
         | The same total, sixty days apart.
         |
         | Which keeps it outside the duplicate detector's 45-day window, so
         | this check exercises the allocation ceiling rather than the
         | near-match warning.
         */
        $target = $this->postedBill(documentDate: $this->date->copy()->subDays(60));

        $creditA = $this->creditFull($first);
        $rebate = app(SupplierCredits::class)->standalone(
            entity: $this->entity(),
            partyId: (string) $this->supplier?->getKey(),
            lines: [$this->billLine('Rebate', '1000.0000', '15.000000', '150.0000', $this->taxCodeId)],
            supplierDocumentNumber: 'CN-'.Str::upper(Str::random(6)),
            supplierDocumentDate: $this->date->copy(),
            reference: 'a second credit, from a rebate',
            createdBy: $this->actorId(),
        );

        app(SupplierCredits::class)->allocate($creditA, $target, '1150.0000', $this->actorId());

        try {
            app(SupplierCredits::class)->allocate($rebate, $target, '1150.0000', $this->actorId());

            $violations[] = 'two credits of 1,150 each were allocated to one 1,150 bill. A bill cannot '.
                'be settled twice, and the supplier would be owed a balance that no document supports';
        } catch (BillRefused) {
            // Expected.
        }

        return $violations;
    }

    /** @return list<string> */
    private function doubleReduction(): array
    {
        $bill = $this->postedBill();
        $credit = $this->creditFull($bill);

        try {
            app(SupplierCredits::class)->allocate($credit, $bill, '100.0000', $this->actorId());

            return [
                'a credit note was allocated to the very bill it already credits by reference, which '.
                'reduces that bill twice - and the supplier would be owed a balance no document '.
                'supports',
            ];
        } catch (BillRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function draftCredit(): array
    {
        $violations = [];
        $bill = $this->postedBill();

        $before = app(PayablesAging::class)->report($this->entityId, $this->date->copy());

        /*
         | Raised and NOT posted.
         |
         | A draft credit note has changed nothing in the ledger, so an
         | aging report that counted it would stop agreeing with the control
         | account - and the reconciliation would report a difference that
         | is a document nobody has acted on yet.
         */
        app(SupplierCredits::class)->forAnAmount(
            entity: $this->entity(),
            bill: $bill,
            gross: '500.0000',
            supplierDocumentNumber: 'CN-'.Str::upper(Str::random(6)),
            supplierDocumentDate: $this->date->copy(),
            createdBy: $this->actorId(),
            reason: 'a settlement nobody has approved yet',
        );

        $after = app(PayablesAging::class)->report($this->entityId, $this->date->copy());

        if ((string) ($after['totals']['SAR'] ?? '0') !== (string) ($before['totals']['SAR'] ?? '0')) {
            $violations[] = sprintf(
                'a DRAFT credit note moved the aging total from %s to %s. Nothing has been posted, so '.
                'the ledger has not moved and the report has stopped agreeing with it',
                (string) ($before['totals']['SAR'] ?? '0'),
                (string) ($after['totals']['SAR'] ?? '0'),
            );
        }

        if (($after['reconciliation']['agrees'] ?? null) !== true) {
            $violations[] = sprintf(
                'with a draft credit note outstanding the aging no longer reconciles: %s against %s',
                (string) ($after['reconciliation']['aging_total'] ?? '?'),
                (string) ($after['reconciliation']['control_account'] ?? '?'),
            );
        }

        /*
         | And the ceiling still counts it.
         |
         | Which is the other half: two drafts could otherwise each pass the
         | check and together exceed the bill.
         */
        $remaining = $bill->refresh()->load(['lines', 'creditNotes'])->remainingUncredited();

        if (! BigDecimal::of((string) $remaining->amount)->isEqualTo('650.0000')) {
            $violations[] = sprintf(
                'after a 500 draft credit the bill reports %s still creditable of 1,150. The ceiling '.
                'has to count a draft, or two drafts would each pass and together exceed the bill',
                (string) $remaining->amount,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function refundThenAllocate(): array
    {
        $first = $this->postedBill();
        $second = $this->postedBill(net: '2000.0000');
        $credit = $this->creditFull($first);

        app(SupplierCredits::class)->markForRefund(
            $credit,
            $this->actorId(),
            'the supplier is paying it back rather than setting it against the next invoice',
        );

        try {
            app(SupplierCredits::class)->allocate($credit, $second, '100.0000', $this->actorId());

            return [
                'a credit marked for refund was also allocated, which reduces what we owe AND collects '.
                'the cash for the same credit',
            ];
        } catch (BillRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function allocateThenRefund(): array
    {
        $first = $this->postedBill();
        $second = $this->postedBill(net: '2000.0000');
        $credit = $this->creditFull($first);

        app(SupplierCredits::class)->allocate($credit, $second, '100.0000', $this->actorId());

        try {
            app(SupplierCredits::class)->markForRefund($credit, $this->actorId(), 'claimed back as well');

            return [
                'an allocated credit was also claimed back as a refund, which collects cash for a '.
                'credit that has already reduced what we owe',
            ];
        } catch (BillRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function reverseCharge(): array
    {
        $violations = [];
        $bill = $this->postedBill(lines: [$this->reverseChargeLine()]);
        $credit = $this->creditFull($bill);
        $credit = app(SupplierCredits::class)->post($credit, $this->entity(), $this->actorId());

        $lines = $this->linesOfEntry((string) $credit->journal_entry_id);

        $input = $this->amountOnRole($lines, 'vat_input');
        $output = $this->amountOnRole($lines, 'vat_output');

        if (! BigDecimal::of($input)->isEqualTo('-150.0000')) {
            $violations[] = sprintf('the reverse-charge input given back is %s and the bill claimed 150', $input);
        }

        if (! BigDecimal::of($output)->isEqualTo('150.0000')) {
            $violations[] = sprintf(
                'the reverse-charge OUTPUT reversed is %s. Reversing only the input would leave the '.
                'return declaring output tax on a supply that was credited - the same defect as the '.
                'original rule\'s, in the opposite direction, and just as invisible because the two '.
                'sides still net to nothing',
                $output,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function agingBasis(): array
    {
        $violations = [];
        $this->postedBill();

        $report = app(PayablesAging::class)->report($this->entityId, $this->date->copy());

        if (($report['basis'] ?? null) === null || ($report['basis_label'] ?? null) === null) {
            $violations[] = 'the aging report does not state its basis. The due date answers "what is '.
                'overdue" and the document date answers "how old is this balance"; they disagree by the '.
                'payment terms, so a report that does not say which it used is read two ways';
        }

        if ($report['rows'] === []) {
            $violations[] = 'a posted bill does not appear in the aging';
        }

        foreach ($report['rows'] as $row) {
            if (($row['aged_from'] ?? null) === null) {
                $violations[] = 'an aging row does not carry the date it was aged from';

                break;
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function basisChangesBuckets(): array
    {
        $violations = [];

        /*
         | A bill dated 50 days ago on 30-day terms.
         |
         | From its document date it is 50 days old; from its due date it is
         | 20 days overdue. Two different buckets, and the difference is the
         | payment terms - which is exactly why the basis has to be stated.
         */
        $net30 = (string) \App\Tenant\Catalog\Models\PaymentTerm::query()
            ->where('code', 'NET30')
            ->value('id');

        if ($net30 === '') {
            return ['no NET30 payment term is seeded, so the two bases cannot be made to differ'];
        }

        $bill = $this->postedBill(
            documentDate: $this->date->copy()->subDays(50),
            paymentTermId: $net30,
        );

        $byDocument = app(PayablesAging::class)->report(
            $this->entityId,
            $this->date->copy(),
            basis: 'document_date',
        );

        $byDue = app(PayablesAging::class)->report(
            $this->entityId,
            $this->date->copy(),
            basis: 'due_date',
        );

        $documentRow = $this->rowFor($byDocument, $bill);
        $dueRow = $this->rowFor($byDue, $bill);

        if ($documentRow === null || $dueRow === null) {
            return ['the bill does not appear under both bases'];
        }

        if ($documentRow['age_days'] === $dueRow['age_days']) {
            $violations[] = sprintf(
                'both bases age the bill at %d days, so the setting changes nothing and the report\'s '.
                'statement of its basis is decoration',
                (int) $documentRow['age_days'],
            );
        }

        if ((int) $dueRow['age_days'] >= (int) $documentRow['age_days']) {
            $violations[] = sprintf(
                'the due-date basis ages the bill at %d days and the document basis at %d. On 30-day '.
                'terms the due date is 30 days later, so it must be the younger of the two',
                (int) $dueRow['age_days'],
                (int) $documentRow['age_days'],
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function instalments(): array
    {
        $violations = [];

        /*
         | Built here rather than taken from the seed.
         |
         | The shipped terms are all single-date, and a check that skipped
         | itself when none was seeded would be a check that reported
         | success for the thing it could not test.
         */
        $terms = \App\Tenant\Catalog\Models\PaymentTerm::create([
            'code' => 'AGING-30-60-90-'.Str::upper(Str::random(4)),
            'name_ar' => 'ثلاثة أقساط',
            'name_en' => 'Three instalments at 30, 60 and 90 days',
            'kind' => 'instalments',
            'days' => 0,
            'active' => true,
        ]);

        foreach ([[1, '30'], [2, '60'], [3, '90']] as [$sequence, $days]) {
            \App\Tenant\Catalog\Models\PaymentTermInstalment::create([
                'payment_term_id' => (string) $terms->getKey(),
                'sequence' => $sequence,
                'proportion' => '0.333333333333',
                'kind' => 'net',
                'days' => (int) $days,
            ]);
        }

        $terms = $terms->refresh();

        /*
         | Dated 45 days ago, posted today.
         |
         | The supplier's date is what the instalments count from, and the
         | posting date has to be in an open period - which is the three
         | dates of PF-133 earning their keep in a fixture: a bill that
         | arrived late belongs to the supplier's month and our own.
         */
        $bill = $this->postedBill(
            documentDate: $this->date->copy()->subDays(45),
            paymentTermId: (string) $terms->getKey(),
        );

        $report = app(PayablesAging::class)->report($this->entityId, $this->date->copy(), basis: 'due_date');

        $mine = array_values(array_filter(
            $report['rows'],
            static fn (array $row): bool => $row['bill_id'] === (string) $bill->getKey(),
        ));

        $frozen = \count($bill->terms_snapshot['due_dates'] ?? []);

        if ($frozen < 2) {
            return ['the seeded instalment term produced one due date, so this check cannot run'];
        }

        if (\count($mine) !== $frozen) {
            $violations[] = sprintf(
                'a bill with %d instalments produced %d aging row(s). Aging the whole bill from the '.
                'last due date hides the earlier instalments\' overdue money; from the first, it makes '.
                'money look overdue that is not due at all',
                $frozen,
                \count($mine),
            );
        }

        $buckets = array_unique(array_map(static fn (array $row): string => (string) $row['bucket'], $mine));

        if (\count($buckets) < 2) {
            $violations[] = sprintf(
                'every instalment of a 45-day-old bill on %s terms landed in the same bucket (%s), so '.
                'the split is not changing the answer',
                (string) $terms->code,
                implode(', ', $buckets),
            );
        }

        $total = BigDecimal::zero();

        foreach ($mine as $row) {
            $total = $total->plus((string) $row['outstanding']);
        }

        if (! $total->isEqualTo('1150.0000')) {
            $violations[] = sprintf(
                'the instalments total %s and the bill is 1,150. A split that does not add back to the '.
                'bill is a report that ages money the supplier never charged',
                (string) $total,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function reconciliation(): array
    {
        $violations = [];
        $this->postedBill();
        $this->postedBill(net: '2500.0000');

        $report = app(PayablesAging::class)->report($this->entityId, $this->date->copy());
        $reconciliation = $report['reconciliation'];

        if (($reconciliation['checked'] ?? false) !== true) {
            return [sprintf(
                'the reconciliation was not attempted: %s',
                (string) ($reconciliation['why'] ?? 'no reason given'),
            )];
        }

        if (($reconciliation['agrees'] ?? false) !== true) {
            $violations[] = sprintf(
                'the aging totals %s and the control account holds %s, a difference of %s. One of them '.
                'is wrong and both are used to pay people',
                (string) $reconciliation['aging_total'],
                (string) $reconciliation['control_account'],
                (string) $reconciliation['difference'],
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function reconciliationDisagrees(): array
    {
        $this->postedBill();

        /*
         | A bill approved and never posted.
         |
         | Which is one of the three things that make an aging report
         | disagree with the ledger, and the most common: the document is
         | approved, the aging counts it because somebody owes the money, and
         | the control account knows nothing about it. The report has to
         | notice - a report that hides its own disagreement with the ledger
         | is worse than no report, because somebody pays from it.
         |
         | Tampering with a journal line was the obvious way to produce the
         | difference and the ledger refuses it, which is the ledger doing
         | its job: `journal_lines` is immutable, so the disagreement has to
         | come from the document side.
         */
        $unposted = $this->enterBill(
            lines: [$this->billLine('Approved and not posted', '400.0000', '15.000000', '60.0000', $this->taxCodeId)],
        );

        DB::connection('tenant')->table('bills')
            ->where('id', (string) $unposted->getKey())
            ->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->actorId()]);

        $report = app(PayablesAging::class)->report($this->entityId, $this->date->copy());
        $reconciliation = $report['reconciliation'];

        if (($reconciliation['agrees'] ?? null) !== false) {
            return [sprintf(
                'a bill approved and never posted is in the aging at %s and not in the control account, '.
                'and the reconciliation still says the two agree. A difference is a bug, and the report '.
                'that hides it is the one somebody pays from',
                (string) $unposted->gross_amount,
            )];
        }

        if (! BigDecimal::of((string) $reconciliation['difference'])->isEqualTo('460.0000')) {
            return [sprintf(
                'the reported difference is %s and the unposted bill is 460',
                (string) $reconciliation['difference'],
            )];
        }

        if (($reconciliation['why'] ?? null) === null) {
            return ['the reconciliation reports a difference and says nothing about what causes one'];
        }

        return [];
    }

    /** @return list<string> */
    private function agingWithCredit(): array
    {
        $violations = [];
        $bill = $this->postedBill();

        $before = app(PayablesAging::class)->report($this->entityId, $this->date->copy());
        $beforeTotal = BigDecimal::of((string) ($before['totals']['SAR'] ?? '0'));

        $credit = app(SupplierCredits::class)->forAnAmount(
            entity: $this->entity(),
            bill: $bill,
            gross: '500.0000',
            supplierDocumentNumber: 'CN-'.Str::upper(Str::random(6)),
            supplierDocumentDate: $this->date->copy(),
            createdBy: $this->actorId(),
            reason: 'a settlement',
        );

        app(SupplierCredits::class)->post($credit, $this->entity(), $this->actorId());

        $after = app(PayablesAging::class)->report($this->entityId, $this->date->copy());
        $afterTotal = BigDecimal::of((string) ($after['totals']['SAR'] ?? '0'));

        if (! $afterTotal->isEqualTo($beforeTotal->minus('500.0000'))) {
            $violations[] = sprintf(
                'the aging total went from %s to %s after a 500 credit. A credit note shown as a '.
                'positive balance is a supplier the report says we owe money to when they owe it to us',
                (string) $beforeTotal,
                (string) $afterTotal,
            );
        }

        $reconciliation = $after['reconciliation'];

        if (($reconciliation['agrees'] ?? false) !== true) {
            $violations[] = sprintf(
                'after the credit the aging no longer reconciles: %s against %s',
                (string) ($reconciliation['aging_total'] ?? '?'),
                (string) ($reconciliation['control_account'] ?? '?'),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function currenciesApart(): array
    {
        $violations = [];
        $this->postedBill();

        app(Bills::class)->enter(
            entity: $this->entity(),
            partyId: (string) $this->supplier?->getKey(),
            supplierDocumentNumber: 'GC-'.Str::upper(Str::random(6)),
            supplierDocumentDate: $this->date->copy(),
            taxPoint: $this->date->copy(),
            postingDate: $this->date->copy(),
            currency: 'USD',
            lines: [$this->billLine('Imported tooling', '1000.0000', '0.000000', '0.0000', null)],
            createdBy: $this->actorId(),
        );

        DB::connection('tenant')->table('bills')
            ->where('currency', 'USD')
            ->update(['status' => 'approved']);

        $report = app(PayablesAging::class)->report($this->entityId, $this->date->copy());

        if (\count($report['totals']) < 2) {
            $violations[] = sprintf(
                'the aging reports %d currency total(s) with bills in SAR and USD. 1,000 USD and 1,000 '.
                'EUR are not 2,000 of anything, and a combined figure would be read as something '.
                'somebody could pay',
                \count($report['totals']),
            );
        }

        if (($report['reconciliation']['checked'] ?? null) !== false) {
            $violations[] = 'the reconciliation was attempted against a control account in the '.
                'functional currency while the report holds foreign documents, which reports an '.
                'exchange rate as a difference';
        }

        return $violations;
    }

    /** @return list<string> */
    private function dpoWithNoPurchases(): array
    {
        $result = app(PayablesAging::class)->daysPayableOutstanding(
            $this->entityId,
            $this->date->copy()->addDays(10),
            $this->date->copy()->addDays(20),
        );

        if (! \array_key_exists('dpo', $result) || $result['dpo'] !== null) {
            return [sprintf(
                'DPO over a window with no purchases is %s. Zero reads as "we pay immediately", which '.
                'is the opposite of what an empty window means',
                (string) $result['dpo'],
            )];
        }

        if (($result['why'] ?? null) === null) {
            return ['DPO is null and the report does not say why'];
        }

        return [];
    }

    /** @return list<string> */
    private function dpo(): array
    {
        $this->postedBill();

        $result = app(PayablesAging::class)->daysPayableOutstanding(
            $this->entityId,
            $this->date->copy()->subDays(29),
            $this->date->copy(),
        );

        if (($result['dpo'] ?? null) === null) {
            return [sprintf('DPO is null with a purchase in the window: %s', (string) ($result['why'] ?? ''))];
        }

        if (($result['days'] ?? null) !== 30) {
            return [sprintf(
                'the window is reported as %s days and it is 30. A ratio quoted without its window is '.
                'a ratio nobody can reproduce',
                (string) ($result['days'] ?? 'unset'),
            )];
        }

        /*
         | One purchase of 1,000 net, entirely unpaid, over 30 days.
         |
         | The payable is 1,150 gross, so DPO = 1,150 / 1,000 x 30 = 34.5.
         */
        if (! BigDecimal::of((string) $result['dpo'])->isEqualTo('34.5')) {
            return [sprintf(
                'DPO is %s and one unpaid purchase of 1,000 net with a 1,150 payable over 30 days is '.
                '34.5',
                (string) $result['dpo'],
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function openItem(): array
    {
        $violations = [];
        $settled = $this->postedBill();
        $open = $this->postedBill(net: '2000.0000');

        /*
         | Settled through a real credit note, not by writing a column.
         |
         | This fixture used to `update(['paid_amount' => ...])`, and
         | `bills.paid_amount` is written by NOTHING in the product:
         | Settlement records an allocation in its own table and may not
         | reach into Purchasing's models to denormalise it. So the check
         | passed against a state the product cannot reach, and reported
         | nothing when the open-item statement was in fact listing every
         | paid bill for ever - which is what a supplier reads when they
         | are chasing.
         |
         | A full credit note takes the open balance to zero through a
         | service, which is a state the product does produce. The paid
         | case belongs to the settlement suite, where
         | `allocationClosesThePayable()` posts a real disbursement and an
         | allocation against a bill and asserts both control accounts.
         */
        $credit = $this->creditFull($settled);
        app(SupplierCredits::class)->post($credit, $this->entity(), $this->actorId());

        $statement = app(PayablesAging::class)->statement(
            $this->entityId,
            (string) $this->supplier?->getKey(),
            'open_item',
            to: $this->date->copy(),
        );

        $documents = array_map(static fn (array $line): string => (string) $line['document'], $statement['lines']);

        if (\in_array((string) $settled->supplier_document_number, $documents, true)) {
            $violations[] = 'a bill with nothing left owing is on the open-item statement, which is what '.
                'a supplier reads when they are chasing';
        }

        if (! \in_array((string) $open->supplier_document_number, $documents, true)) {
            $violations[] = 'an unpaid bill is missing from the open-item statement';
        }

        foreach ($statement['lines'] as $line) {
            if (($line['due'] ?? null) === null) {
                $violations[] = 'an open item does not carry its due dates, which is what the supplier '.
                    'is chasing against';

                break;
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function balanceForward(): array
    {
        $violations = [];

        $old = $this->postedBill(documentDate: $this->date->copy()->subMonths(6));
        $recent = $this->postedBill(documentDate: $this->date->copy()->subDays(10), net: '2000.0000');

        $statement = app(PayablesAging::class)->statement(
            $this->entityId,
            (string) $this->supplier?->getKey(),
            'balance_forward',
            from: $this->date->copy()->subMonths(3),
            to: $this->date->copy(),
        );

        if (! BigDecimal::of((string) $statement['opening_balance'])->isEqualTo((string) $old->gross_amount)) {
            $violations[] = sprintf(
                'the opening balance is %s and the bill before the window is %s. A stored opening '.
                'balance is a number nobody can check: the first time it disagrees with the documents, '.
                'there is no way to tell which is wrong',
                (string) $statement['opening_balance'],
                (string) $old->gross_amount,
            );
        }

        $documents = array_map(static fn (array $m): string => (string) $m['document'], $statement['movements']);

        if (! \in_array((string) $recent->supplier_document_number, $documents, true)) {
            $violations[] = 'a bill inside the window is missing from the movements';
        }

        if (\in_array((string) $old->supplier_document_number, $documents, true)) {
            $violations[] = 'a bill before the window appears as a movement as well as in the opening '.
                'balance, so the statement counts it twice';
        }

        $expected = BigDecimal::of((string) $old->gross_amount)->plus((string) $recent->gross_amount);

        if (! BigDecimal::of((string) $statement['closing_balance'])->isEqualTo($expected)) {
            $violations[] = sprintf(
                'the closing balance is %s and the two bills total %s',
                (string) $statement['closing_balance'],
                (string) $expected,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function balanceForwardCurrencies(): array
    {
        $this->postedBill();

        app(Bills::class)->enter(
            entity: $this->entity(),
            partyId: (string) $this->supplier?->getKey(),
            supplierDocumentNumber: 'GC-'.Str::upper(Str::random(6)),
            supplierDocumentDate: $this->date->copy(),
            taxPoint: $this->date->copy(),
            postingDate: $this->date->copy(),
            currency: 'USD',
            lines: [$this->billLine('Imported tooling', '1000.0000', '0.000000', '0.0000', null)],
            createdBy: $this->actorId(),
        );

        DB::connection('tenant')->table('bills')->where('currency', 'USD')->update(['status' => 'approved']);

        try {
            app(PayablesAging::class)->statement(
                $this->entityId,
                (string) $this->supplier?->getKey(),
                'balance_forward',
                to: $this->date->copy(),
            );

            return [
                'a balance-forward statement added a SAR bill and a USD bill into one running balance, '.
                'which is a figure somebody reconciles against and nobody can reproduce',
            ];
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    // ---- Helpers -------------------------------------------------------

    /**
     * A posted bill.
     *
     * `$net` exists so a check that needs two bills can make them
     * different. Two bills from one supplier for the same total on the same
     * date raise a near-match duplicate warning - correctly - and an
     * unanswered warning refuses the posting, so a fixture that produced
     * identical bills would be testing the duplicate detector instead of
     * whatever it meant to test.
     *
     * @param  list<array<string, mixed>>|null  $lines
     */
    private function postedBill(
        ?array $lines = null,
        ?Carbon $documentDate = null,
        ?string $net = null,
        ?string $paymentTermId = null,
    ): Bill {
        if ($lines === null && $net !== null) {
            $tax = (string) BigDecimal::of($net)->multipliedBy('0.15')->toScale(4);
            $lines = [$this->billLine('Machined brackets', $net, '15.000000', $tax, $this->taxCodeId)];
        }

        $bill = $this->enterBill(
            lines: $lines,
            documentDate: $documentDate,
            paymentTermId: $paymentTermId,
        );

        return app(Bills::class)->post($bill, $this->entity(), $this->actorId());
    }

    private function creditFull(Bill $bill): Bill
    {
        return app(SupplierCredits::class)->full(
            entity: $this->entity(),
            bill: $bill,
            supplierDocumentNumber: 'CN-'.Str::upper(Str::random(6)),
            supplierDocumentDate: $this->date->copy(),
            createdBy: $this->actorId(),
            reason: 'the whole batch went back',
        );
    }

    /** @return array<string, mixed> */
    private function billLine(
        string $description,
        string $net,
        string $rate,
        string $tax,
        ?string $taxCodeId,
        string $category = 'S',
    ): array {
        return [
            'description' => $description,
            'quantity' => '1',
            'unit_price' => $net,
            'net_amount' => $net,
            'expense_account_id' => $this->expenseAccountId,
            'tax_code_id' => $taxCodeId,
            'tax_category' => $category,
            'tax_rate' => $rate,
            'tax_amount' => $tax,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>|null
     */
    private function rowFor(array $report, Bill $bill): ?array
    {
        foreach ($report['rows'] as $row) {
            if ((string) $row['bill_id'] === (string) $bill->getKey()) {
                return $row;
            }
        }

        return null;
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
