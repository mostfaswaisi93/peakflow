<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Enums\PrepaymentTaxPoint;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Services\Issuer;
use App\Tenant\Sales\Services\Prepayments;
use App\Tenant\Tax\Models\TaxCode;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prepayment invoices, and settling them - PF-099, IFRS 15.
 *
 * ## The claim that matters
 *
 * Take 460 before performance, invoice 1,150 for the supply, and the books
 * end with revenue 1,000, output tax 150, receivable 1,150 and the liability
 * back at zero. Every individual posting can look defensible while the set of
 * them is wrong - revenue recognised twice, tax declared twice, the customer
 * chased for one supply twice - so the headline check reads the account
 * balances after the whole cycle rather than the lines of either document.
 *
 * ## Why the prepayment credits a liability
 *
 * Nothing has been performed. Money taken before a supply is an obligation to
 * make the supply or give the money back, and crediting revenue instead
 * overstates income and the tax base in a period where the work has not
 * happened. It cannot be fixed by the final invoice posting less: the
 * overstatement is in a period that may be closed, reported and filed, and
 * the understatement is in a later one.
 */
final class PrepaymentRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['prepayments (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'a prepayment credits a liability, not revenue' => fn (): array => $this->aLiability(),
                'the whole cycle leaves every account correct' => fn (): array => $this->theCycle(),
                'the final invoice shows the deduction and what is left to pay' => fn (): array => $this->theDeduction(),
                'the tax point is the profile\'s decision' => fn (): array => $this->theTaxPoint(),
                'a profile that taxes later leaves the prepayment untaxed' => fn (): array => $this->taxedLater(),
                'a prepayment is applied once' => fn (): array => $this->onceOnly(),
                'what cannot be applied, and why' => fn (): array => $this->whatCannotBeApplied(),
                'a deduction that does not match its prepayment is refused' => fn (): array => $this->theDeductionMustMatch(),
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function aLiability(): array
    {
        $violations = [];
        $prepayment = $this->issuePrepayment('400.0000');

        if ($prepayment->document_type !== DocumentType::PrepaymentInvoice) {
            $violations[] = 'a prepayment invoice is not UNCL 1001 code 386';
        }

        if ((string) $prepayment->series_key !== 'PRE') {
            $violations[] = sprintf('a prepayment draws from the %s series', (string) $prepayment->series_key);
        }

        if ($prepayment->document_type->postsToRevenue()) {
            $violations[] = 'the document type says a prepayment posts to revenue';
        }

        if ($prepayment->lines()->value('revenue_account_id') !== null) {
            $violations[] = 'a prepayment line carries a revenue account, which is a number nobody '.
                'reads and somebody will eventually post to';
        }

        $entry = DB::connection('tenant')->table('journal_entries')
            ->where('source_id', (string) $prepayment->getKey())
            ->first();

        if ($entry === null) {
            return [...$violations, 'a prepayment invoice posted nothing'];
        }

        foreach ([
            'the receivable' => [$this->roleAccount('accounts_receivable'), '460.0000'],
            'the liability' => [$this->roleAccount('deferred_revenue'), '-400.0000'],
            'the output tax' => [$this->roleAccount('vat_output', 'SA'), '-60.0000'],
        ] as $what => [$account, $expected]) {
            $actual = $this->movementOn($entry->id, $account);

            if ($actual !== $expected) {
                $violations[] = sprintf('%s moves by %s and should move by %s', $what, $actual ?? 'nothing', $expected);
            }
        }

        $revenue = $this->movementOn($entry->id, $this->revenueAccountId);

        if ($revenue !== null) {
            $violations[] = sprintf(
                'a prepayment posted %s to revenue. Nothing has been performed: the credit is a '.
                'contract liability under IFRS 15, and revenue here overstates income and the tax '.
                'base in a period where the work has not happened',
                $revenue,
            );
        }

        /*
         | And the liability line carries the tax code and tax point.
         |
         | Because the prepayment IS the taxable event here, and a return
         | assembled from codes needs the taxable amount to have one -
         | otherwise the box shows tax with nothing behind it.
         */
        $line = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $entry->id)
            ->where('account_id', $this->roleAccount('deferred_revenue'))
            ->first();

        if ($line !== null && ($line->tax_code_id === null || $line->tax_point === null)) {
            $violations[] = 'the liability line carries no tax code and tax point, so the return would '.
                'show tax with no taxable amount behind it';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theCycle(): array
    {
        $violations = [];
        $prepayment = $this->issuePrepayment('400.0000');

        $final = app(Prepayments::class)->applyTo($this->draft(), $prepayment);
        $final = app(Issuer::class)->issue($final, $this->entity(), $this->actorId(), $this->decision);

        $entry = DB::connection('tenant')->table('journal_entries')
            ->where('source_id', (string) $final->getKey())
            ->first();

        if ($entry === null) {
            return ['the final invoice posted nothing'];
        }

        if ((string) $entry->posting_rule !== 'sales.final_invoice_issued') {
            $violations[] = sprintf(
                'the final invoice posted through "%s". An invoice carrying a prepayment is a '.
                'different entry: the receivable is only the balance, and posting the full gross '.
                'again shows the customer owing for one supply twice',
                (string) $entry->posting_rule,
            );
        }

        foreach ([
            'the receivable' => [$this->roleAccount('accounts_receivable'), '690.0000'],
            'the liability' => [$this->roleAccount('deferred_revenue'), '400.0000'],
            'the output tax' => [$this->roleAccount('vat_output', 'SA'), '-90.0000'],
            'the revenue' => [$this->revenueAccountId, '-1000.0000'],
        ] as $what => [$account, $expected]) {
            $actual = $this->movementOn($entry->id, $account);

            if ($actual !== $expected) {
                $violations[] = sprintf(
                    'on the final invoice %s moves by %s and should move by %s',
                    $what, $actual ?? 'nothing', $expected,
                );
            }
        }

        /*
         | And then the balances, which is the only claim worth making.
         |
         | Each posting above can be defensible while the pair of them is
         | wrong: revenue recognised twice, tax declared twice, the customer
         | chased for one supply twice. The balances say whether the cycle
         | ended where it should.
         */
        foreach ([
            'the liability' => [$this->roleAccount('deferred_revenue'), '0.0000'],
            'the receivable' => [$this->roleAccount('accounts_receivable'), '1150.0000'],
            'the output tax' => [$this->roleAccount('vat_output', 'SA'), '-150.0000'],
            'revenue' => [$this->revenueAccountId, '-1000.0000'],
        ] as $what => [$account, $expected]) {
            $actual = $this->balanceOn($account);

            if ($actual !== $expected) {
                $violations[] = sprintf(
                    'after taking 460 and invoicing 1,150, %s stands at %s and should stand at %s',
                    $what, $actual ?? 'nothing', $expected,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function theDeduction(): array
    {
        $violations = [];
        $prepayment = $this->issuePrepayment('400.0000');
        $final = app(Prepayments::class)->applyTo($this->draft(), $prepayment);

        if ((string) $final->prepaid_amount !== '460.0000') {
            $violations[] = sprintf(
                'the prepaid amount is %s and the prepayment was for 460.0000 - EN 16931 BT-113 is the '.
                'gross already paid, not the net',
                (string) $final->prepaid_amount,
            );
        }

        if ((string) $final->payable_amount !== '690.0000') {
            $violations[] = sprintf(
                'the invoice says %s is payable and 1,150.00 less 460.00 is 690.0000. The figure the '.
                'customer acts on is the one at the bottom',
                (string) $final->payable_amount,
            );
        }

        if ((string) $final->gross_amount !== '1150.0000') {
            $violations[] = 'the deduction changed the invoice total. The supply is still the supply: '.
                'what the prepayment changes is what is left to pay';
        }

        $reference = $final->references()->where('kind', 'prepayment')->first();

        if ($reference === null || (string) $reference->referenced_invoice_id !== (string) $prepayment->getKey()) {
            $violations[] = 'the final invoice does not reference the prepayment it deducts, so the '.
                'deduction cites nothing';
        }

        if ((string) ($reference->reference ?? '') !== (string) $prepayment->number) {
            $violations[] = 'the reference does not carry the prepayment\'s number, which is what a '.
                'customer matching the two documents reads';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theTaxPoint(): array
    {
        $violations = [];
        $prepayments = app(Prepayments::class);

        if ($prepayments->taxPointRule('zatca') !== PrepaymentTaxPoint::AtPrepayment) {
            $violations[] = 'zatca does not tax a prepayment, and the tax point in that regime is the '.
                'earlier of payment and invoice';
        }

        try {
            $prepayments->taxPointRule('a-profile-nobody-declared');

            $violations[] = 'an undeclared profile was given a prepayment tax point anyway. Guessing '.
                'is a quarter\'s cash either way';
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'config/jurisdictions.php')) {
                $violations[] = 'the refusal does not say where to declare it: '.$e->getMessage();
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function taxedLater(): array
    {
        $violations = [];

        /*
         | A regime that taxes only at the final invoice.
         |
         | None of the shipped profiles does, so the config is overridden
         | here rather than inventing a profile: the branch exists because
         | such regimes exist, and a branch with no check is a branch that
         | has never run.
         */
        config(['jurisdictions.prepayment_tax.generic' => 'at_final_invoice']);

        try {
            $prepayment = $this->issuePrepayment('400.0000');

            if ((string) $prepayment->tax_amount !== '0.0000') {
                $violations[] = sprintf(
                    'a prepayment under a regime that taxes at the final invoice carries %s of tax',
                    (string) $prepayment->tax_amount,
                );
            }

            $entry = DB::connection('tenant')->table('journal_entries')
                ->where('source_id', (string) $prepayment->getKey())
                ->first();

            if ($entry !== null && $this->movementOn($entry->id, $this->roleAccount('vat_output', 'SA')) !== null) {
                $violations[] = 'the prepayment declared output tax anyway, in a regime that does not '.
                    'want it yet';
            }

            if ($entry !== null && $this->movementOn($entry->id, $this->roleAccount('deferred_revenue')) !== '-400.0000') {
                $violations[] = 'the liability is not the whole amount taken. Whether the authority '.
                    'wants its money now or later has nothing to do with whether the business owes '.
                    'the customer a supply';
            }

            // And the final invoice then carries the full tax.
            $final = app(Issuer::class)->issue(
                app(Prepayments::class)->applyTo($this->draft(), $prepayment),
                $this->entity(), $this->actorId(), $this->decision,
            );

            $finalEntry = DB::connection('tenant')->table('journal_entries')
                ->where('source_id', (string) $final->getKey())
                ->first();

            if ($finalEntry !== null
                && $this->movementOn($finalEntry->id, $this->roleAccount('vat_output', 'SA')) !== '-150.0000') {
                $violations[] = 'the final invoice did not declare the whole supply\'s tax, which '.
                    'nothing has declared yet';
            }

            return $violations;
        } finally {
            config(['jurisdictions.prepayment_tax.generic' => 'at_prepayment']);
        }
    }

    /** @return list<string> */
    public function onceOnly(): array
    {
        $violations = [];
        $prepayments = app(Prepayments::class);
        $prepayment = $this->issuePrepayment('400.0000');

        app(Issuer::class)->issue(
            $prepayments->applyTo($this->draft(), $prepayment),
            $this->entity(), $this->actorId(), $this->decision,
        );

        try {
            $prepayments->applyTo($this->draft(), $prepayment->refresh());

            $violations[] = 'a prepayment was applied twice, so one liability is released twice and '.
                'the balance is collected from the customer twice';
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'already been applied')) {
                $violations[] = 'the refusal does not explain itself: '.$e->getMessage();
            }

            if (! str_contains($e->getMessage(), 'credit')) {
                $violations[] = 'the refusal does not say what to do instead. Splitting a prepayment '.
                    'across invoices is deliberately not modelled, and returning part of one is a '.
                    'credit note';
            }
        }

        /*
         | And the database refuses it when the service is bypassed, by
         | primary key - the cheapest correct enforcement there is.
         */
        $second = $this->draft();

        DB::connection('tenant')->table('invoices')
            ->where('id', (string) $second->getKey())
            ->update(['prepaid_amount' => '460.0000', 'payable_amount' => '690.0000']);

        DB::connection('tenant')->table('invoice_references')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'entity_id' => $this->entityId,
            'invoice_id' => (string) $second->getKey(),
            'kind' => 'prepayment',
            'referenced_invoice_id' => (string) $prepayment->getKey(),
            'reference' => (string) $prepayment->number,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(Issuer::class)->issue(
                $second->refresh(), $this->entity(), $this->actorId(), $this->decision,
            );

            $violations[] = 'the database issued a second invoice deducting one prepayment';
        } catch (Throwable) {
            // The key is the whole rule.
        }

        return $violations;
    }

    /** @return list<string> */
    public function whatCannotBeApplied(): array
    {
        $violations = [];
        $prepayments = app(Prepayments::class);
        $code = TaxCode::query()->find($this->taxCodeId);
        \assert($code !== null);

        $cases = [
            'a draft prepayment' => function () use ($prepayments, $code): void {
                $draft = $prepayments->invoice(
                    $this->entity(), $this->party, '100.0000', $code, $this->date, null, 'generic',
                );

                $prepayments->applyTo($this->draft(), $draft);
            },
            'an ordinary invoice as the prepayment' => function () use ($prepayments): void {
                $prepayments->applyTo($this->draft(), $this->issue());
            },
            'an already issued final invoice' => function () use ($prepayments): void {
                $prepayments->applyTo($this->issue(), $this->issuePrepayment('100.0000'));
            },
            'another customer\'s prepayment' => function () use ($prepayments, $code): void {
                $other = Party::create([
                    'code' => 'PRE-C-'.random_int(1000, 9999),
                    'legal_name' => 'Another Customer LLC',
                    'is_customer' => true,
                    'country' => 'SA',
                    'default_currency' => 'SAR',
                    'active' => true,
                ]);

                $theirs = app(Issuer::class)->issue(
                    $prepayments->invoice($this->entity(), $other, '100.0000', $code, $this->date, null, 'generic'),
                    $this->entity(), $this->actorId(), $this->decision,
                );

                $prepayments->applyTo($this->draft(), $theirs);
            },
            'a prepayment larger than the invoice' => function () use ($prepayments): void {
                $prepayments->applyTo($this->draft(), $this->issuePrepayment('2000.0000'));
            },
        ];

        foreach ($cases as $what => $attempt) {
            $savepoint = $this->savepoint();

            try {
                $attempt();

                $violations[] = sprintf('%s was applied', $what);
            } catch (Throwable) {
                // As it should be.
            } finally {
                $this->rollbackTo($savepoint);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function theDeductionMustMatch(): array
    {
        $prepayment = $this->issuePrepayment('400.0000');
        $final = app(Prepayments::class)->applyTo($this->draft(), $prepayment);

        /*
         | The deduction is edited behind the service's back.
         |
         | A document that deducts 300 while citing a 460 prepayment leaves
         | 160 in a liability nobody will ever clear - and both documents
         | look correct on their own, which is why the database checks the
         | pair rather than either one.
         */
        DB::connection('tenant')->table('invoices')
            ->where('id', (string) $final->getKey())
            ->update(['prepaid_amount' => '300.0000', 'payable_amount' => '850.0000']);

        try {
            app(Issuer::class)->issue(
                $final->refresh(), $this->entity(), $this->actorId(), $this->decision,
            );

            return ['an invoice deducting 300.00 while citing a 460.00 prepayment was issued'];
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'does not match')
                ? []
                : ['the refusal is about something else: '.
                    substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 160)];
        }
    }

    private function issuePrepayment(string $net): Invoice
    {
        $code = TaxCode::query()->find($this->taxCodeId);
        \assert($code !== null);

        return app(Issuer::class)->issue(
            app(Prepayments::class)->invoice(
                $this->entity(), $this->party, $net, $code, $this->date,
                'Deposit against the contract', 'generic',
            ),
            $this->entity(),
            $this->actorId(),
            $this->decision,
        );
    }

    private function roleAccount(string $role, ?string $jurisdiction = null): ?string
    {
        $id = DB::connection('tenant')->table('account_roles')
            ->where('entity_id', $this->entityId)
            ->where('role', $role)
            ->when($jurisdiction !== null, fn ($q) => $q->where('jurisdiction', $jurisdiction))
            ->value('account_id');

        return $id === null ? null : (string) $id;
    }

    private function movementOn(string $entryId, ?string $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }

        $sum = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $entryId)
            ->where('account_id', $accountId)
            ->sum('amount');

        return (string) $sum === '0' || $sum === null
            ? null
            : (string) \App\Support\Money\Money::of((string) $sum, 'SAR')->amount;
    }

    private function balanceOn(?string $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }

        $sum = DB::connection('tenant')->table('journal_lines')
            ->where('entity_id', $this->entityId)
            ->where('account_id', $accountId)
            ->sum('amount');

        return (string) \App\Support\Money\Money::of((string) ($sum ?? '0'), 'SAR')->amount;
    }
}
