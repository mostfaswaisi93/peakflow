<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Models\SelfBillingAgreement;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Services\Issuer;
use App\Tenant\Sales\Services\SelfBilling;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Self-billed invoices - PF-100, UNCL 1001 389.
 *
 * ## The part that is easy to get backwards
 *
 * This document is issued by this system and it is not a sale. Self-billing
 * is the buyer writing the supplier's invoice on their behalf, so what it
 * records is a purchase.
 *
 * Posting it as a sale is the kind of mistake that looks right in every
 * individual figure: the receivable rises instead of the payable, so the
 * supplier appears as a customer who owes money; the tax lands in output
 * instead of input, so the return declares tax on a purchase and claims
 * nothing back; and revenue rises by the cost of the goods. The entry
 * balances and the trial balance adds up. What is wrong is the direction of
 * the whole document - which is why the check reads the side of each
 * account rather than the amounts.
 */
final class SelfBillingRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    private ?Party $supplier = null;

    private string $expenseAccountId = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['self-billing (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();
            $this->seedTheSupplier();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'the type is unavailable until the agreement exists' => fn (): array => $this->theAgreementComesFirst(),
                'an agreement that lapsed is not an agreement' => fn (): array => $this->aLapsedAgreement(),
                'two agreements with one party cannot overlap' => fn (): array => $this->noOverlap(),
                'it posts as a purchase, not a sale' => fn (): array => $this->itPostsAsAPurchase(),
                'a line on the wrong side of the books is refused' => fn (): array => $this->theWrongSide(),
                'it is numbered from its own series and marked on the document' => fn (): array => $this->itsOwnSeries(),
                'the database refuses one with no agreement behind it' => fn (): array => $this->theDatabaseRefuses(),
                'a customer record cannot be self-billed' => fn (): array => $this->notASupplier(),
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function theAgreementComesFirst(): array
    {
        $violations = [];
        $selfBilling = app(SelfBilling::class);
        $supplier = $this->supplier();

        if ($selfBilling->availableFor($supplier, $this->date)) {
            $violations[] = 'the type is available to a supplier with no agreement';
        }

        try {
            $selfBilling->invoice($this->entity(), $supplier, $this->aLine(), $this->date, 'generic');

            $violations[] = 'a self-billed invoice was drafted with no agreement behind it';
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'agreement')) {
                $violations[] = 'the refusal does not mention the agreement: '.$e->getMessage();
            }
        }

        $this->agreement();

        if (! $selfBilling->availableFor($supplier->refresh(), $this->date)) {
            $violations[] = 'the type is still unavailable after an agreement was recorded';
        }

        return $violations;
    }

    /** @return list<string> */
    public function aLapsedAgreement(): array
    {
        $agreement = $this->agreement(
            from: CarbonImmutable::parse('2024-01-01'),
            until: CarbonImmutable::parse('2026-01-01'),
        );

        $selfBilling = app(SelfBilling::class);

        if ($selfBilling->agreementFor($this->supplier(), $this->date) !== null) {
            return [sprintf(
                'an agreement that ended on %s still covers %s. An agreement that lapsed is not an '.
                'agreement, and the question an auditor asks is which one was in force on the date of '.
                'the document',
                '2026-01-01',
                $this->date->toDateString(),
            )];
        }

        /*
         | And the day before it ended, it did cover it.
         |
         | Which is what makes the range half-open: the upper bound is the
         | first day NOT covered, so an agreement replaced on the 1st is
         | unambiguous about the 31st.
         */
        return $selfBilling->agreementFor($this->supplier(), \Illuminate\Support\Carbon::parse('2025-12-31')) === null
            ? ['the agreement does not cover the day before it ended, so the range is not half-open']
            : [];
    }

    /** @return list<string> */
    public function noOverlap(): array
    {
        $this->agreement(from: CarbonImmutable::parse('2026-01-01'));

        try {
            $this->agreement(from: CarbonImmutable::parse('2026-06-01'), reference: 'SB-SECOND');

            return ['two self-billing agreements with one party overlap. Two in force at once is two '.
                'answers to "which agreement is this document under", and the document carries one '.
                'reference'];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    public function itPostsAsAPurchase(): array
    {
        $violations = [];
        $this->agreement();

        $invoice = app(Issuer::class)->issue(
            app(SelfBilling::class)->invoice(
                $this->entity(), $this->supplier(), $this->aLine(), $this->date, 'generic',
            ),
            $this->entity(), $this->actorId(), $this->decision,
        );

        $entry = DB::connection('tenant')->table('journal_entries')
            ->where('source_id', (string) $invoice->getKey())
            ->first();

        if ($entry === null) {
            return ['a self-billed invoice posted nothing'];
        }

        if ((string) $entry->posting_rule !== 'sales.self_billed_invoice_issued') {
            $violations[] = sprintf('it posted through "%s"', (string) $entry->posting_rule);
        }

        foreach ([
            'the payable' => [$this->roleAccount('accounts_payable'), '-1150.0000'],
            'the input tax' => [$this->roleAccount('vat_input', 'SA'), '150.0000'],
            'the expense' => [$this->expenseAccountId, '1000.0000'],
        ] as $what => [$account, $expected]) {
            $actual = $this->movementOn($entry->id, $account);

            if ($actual !== $expected) {
                $violations[] = sprintf('%s moves by %s and should move by %s', $what, $actual ?? 'nothing', $expected);
            }
        }

        /*
         | And nothing touched the sales side.
         |
         | A receivable on a self-billed document shows the supplier as a
         | customer who owes money, and output tax on it declares tax the
         | business does not owe while claiming nothing back - two errors of
         | the same size in opposite directions, so the net due is wrong by
         | twice the tax and both boxes of the return are wrong.
         */
        foreach ([
            'the receivable' => $this->roleAccount('accounts_receivable'),
            'the output tax' => $this->roleAccount('vat_output', 'SA'),
            'revenue' => $this->revenueAccountId,
        ] as $what => $account) {
            $actual = $this->movementOn($entry->id, $account);

            if ($actual !== null) {
                $violations[] = sprintf(
                    'a self-billed invoice moved %s by %s. It is a purchase: the party it names is '.
                    'owed money, not chased for it',
                    $what, $actual,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function theWrongSide(): array
    {
        $this->agreement();

        $invoice = app(SelfBilling::class)->invoice(
            $this->entity(), $this->supplier(), $this->aLine(), $this->date, 'generic',
        );

        // Pointed at a revenue account, which is the direction being refused.
        $invoice->lines()->update(['revenue_account_id' => $this->revenueAccountId]);

        $findings = app(\App\Tenant\Sales\Services\InvoiceValidator::class)->blocking(
            app(\App\Tenant\Sales\Services\InvoiceValidator::class)->validate($invoice->refresh(), $this->entity()),
        );

        $codes = array_map(
            static fn (\App\Tenant\Sales\InvoiceFinding $f): string => $f->code,
            $findings,
        );

        if (! \in_array('the_wrong_side_of_the_books', $codes, true)) {
            return [sprintf(
                'a self-billed invoice posting to a revenue account was accepted. What came back: %s. '.
                'A purchase posted into revenue balances and adds up - what is wrong is the direction '.
                'of the whole document',
                $codes === [] ? 'nothing at all' : implode(', ', $codes),
            )];
        }

        return [];
    }

    /** @return list<string> */
    public function itsOwnSeries(): array
    {
        $violations = [];
        $agreement = $this->agreement();

        $invoice = app(Issuer::class)->issue(
            app(SelfBilling::class)->invoice(
                $this->entity(), $this->supplier(), $this->aLine(), $this->date, 'generic',
            ),
            $this->entity(), $this->actorId(), $this->decision,
        );

        if ((string) $invoice->series_key !== 'SLF') {
            $violations[] = sprintf('a self-billed invoice draws from the %s series', (string) $invoice->series_key);
        }

        if ($invoice->document_type !== DocumentType::SelfBilledInvoice) {
            $violations[] = 'it is not UNCL 1001 code 389';
        }

        $note = (string) $invoice->note;

        foreach ([
            'the agreement reference' => $agreement->reference,
            'the English marking' => 'Self-billed',
            'the Arabic marking' => 'فاتورة ذاتية',
        ] as $what => $needle) {
            if (! str_contains($note, (string) $needle)) {
                $violations[] = sprintf(
                    'the document does not carry %s. The type code says 389 to a machine; a person '.
                    'reading the paper needs to know the buyer issued it and under which agreement',
                    $what,
                );
            }
        }

        $walk = app(\App\Tenant\Sales\Services\ChainVerifier::class)->verify($this->entityId, 'SLF');

        if (! $walk['verified'] || $walk['documents'] !== 1) {
            $violations[] = 'the self-billed series has no chain of its own';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theDatabaseRefuses(): array
    {
        $this->agreement();

        $invoice = app(SelfBilling::class)->invoice(
            $this->entity(), $this->supplier(), $this->aLine(), $this->date, 'generic',
        );

        /*
         | The agreement is removed after the draft was prepared.
         |
         | Which is the state a second issuing path produces - an import, an
         | API, a correction script - and the state somebody produces by
         | deleting an agreement while a draft is open. The document the
         | regime does not recognise is the one the supplier is asked to
         | explain.
         */
        SelfBillingAgreement::query()->where('party_id', (string) $this->supplier()->getKey())->delete();

        try {
            app(Issuer::class)->issue(
                $invoice->refresh(), $this->entity(), $this->actorId(), $this->decision,
            );

            return ['a self-billed invoice was issued after its agreement was deleted'];
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'agreement')
                ? []
                : ['the refusal is about something else: '.
                    substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 160)];
        }
    }

    /** @return list<string> */
    public function notASupplier(): array
    {
        $customer = $this->party;
        \assert($customer !== null);

        SelfBillingAgreement::create([
            'entity_id' => $this->entityId,
            'party_id' => (string) $customer->getKey(),
            'reference' => 'SB-CUSTOMER',
            'signed_on' => '2025-12-01',
            'validity' => SelfBillingAgreement::range(CarbonImmutable::parse('2026-01-01')),
        ]);

        try {
            app(SelfBilling::class)->invoice(
                $this->entity(), $customer, $this->aLine(), $this->date, 'generic',
            );

            return ['a customer record was self-billed, which credits a payable to somebody who owes '.
                'you money'];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string, string>> */
    private function aLine(): array
    {
        return [[
            'description' => 'Subcontracted work, May 2026',
            'net' => '1000.0000',
            'expense_account_id' => $this->expenseAccountId,
            'tax_code_id' => $this->taxCodeId,
            'tax_rate' => '15.000000',
            /*
             | Subcontracted work is a service, and the line says so - PF-078.
             |
             | `place_of_supply` used to be stated here and `SelfBilling`
             | wrote `'domestic'` beside it; both are the rule book's answer
             | now, and what the caller has to supply is the question's
             | input.
             */
            'supply_type' => 'service',
        ]];
    }

    private function supplier(): Party
    {
        \assert($this->supplier !== null);

        return $this->supplier;
    }

    private function agreement(
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $until = null,
        string $reference = 'SB-2026-14',
    ): SelfBillingAgreement {
        return SelfBillingAgreement::create([
            'entity_id' => $this->entityId,
            'party_id' => (string) $this->supplier()->getKey(),
            'reference' => $reference,
            'signed_on' => ($from ?? CarbonImmutable::parse('2026-01-01'))->subDay()->toDateString(),
            'validity' => SelfBillingAgreement::range($from ?? CarbonImmutable::parse('2026-01-01'), $until),
        ]);
    }

    private function seedTheSupplier(): void
    {
        $this->supplier = Party::create([
            'code' => 'SB-S-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(6)),
            'legal_name' => 'Subcontractor Industrial LLC',
            'is_customer' => false,
            'is_supplier' => true,
            'country' => 'SA',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        $this->expenseAccountId = (string) Account::query()
            ->where('entity_id', $this->entityId)
            ->where('type', 'expense')
            ->where('postable', true)
            ->orderBy('code')
            ->value('id');
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

        return $sum === null || (string) $sum === '0'
            ? null
            : (string) \App\Support\Money\Money::of((string) $sum, 'SAR')->amount;
    }
}
