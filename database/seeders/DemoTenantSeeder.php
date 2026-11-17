<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Banking\Matching\MatchingEngine;
use App\Tenant\Banking\Models\BankAccount;
use App\Tenant\Banking\Models\BankTransaction;
use App\Tenant\Banking\Models\MatchProposal;
use App\Tenant\Banking\Services\BankAccounts;
use App\Tenant\Banking\Services\Matches;
use App\Tenant\Banking\Services\StatementImport;
use App\Tenant\Catalog\Models\PaymentTerm;
use App\Tenant\Identity\Models\User;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Posting\Rules\OpeningBalanceRule;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Models\PartyTaxRegistration;
use App\Tenant\Purchasing\Services\Bills;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Models\InvoiceTaxBreakdownRow;
use App\Tenant\Sales\Services\Issuer;
use App\Tenant\Settlement\Enums\PaymentDirection;
use App\Tenant\Settlement\Enums\PaymentMethod;
use App\Tenant\Settlement\Services\Allocations;
use App\Tenant\Settlement\Services\Payments;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Models\ReturnPeriod;
use App\Tenant\Tax\Services\ReturnPeriods;
use App\Tenant\Tax\Services\TaxCodes;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Models\TaxRegistration;
use App\Tenant\Tenancy\Services\EntityContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Five months of a trading company, so the Overview has something to say.
 *
 *     php artisan db:seed --class=DemoTenantSeeder
 *
 * The README promised this seeder; this is the first version of it. It fills
 * the `demo` workspace's DEMO entity - the one DevelopmentSeeder creates -
 * with customers and suppliers, invoices issued through the real Issuer,
 * bills posted through the real Bills service, receipts and payments recorded
 * and allocated through Settlement, a bank account with monthly statements
 * imported through the real importer, and most lines matched.
 *
 * ## Through the services, never into the tables
 *
 * A seeder that inserted rows would produce a ledger the triggers never saw:
 * invoices with no posting, payments with no journal entry, a bank balance no
 * statement explains. The first thing anyone would learn from the demo is a
 * set of numbers that do not reconcile. Every document here goes through the
 * code a user's click goes through, so the demo is internally consistent by
 * construction and an auditor could re-perform it.
 *
 * ## Deterministic
 *
 * Amounts and dates come from fixed sequences, not a random generator, so two
 * developers seeding on the same day see the same figures - "my overview
 * says 128,450" is only a useful sentence if everyone's does.
 *
 * ## Local only, once
 *
 * Refuses outside local and testing, like DevelopmentSeeder. Runs once per
 * workspace: the first demo customer existing means it has run, and a second
 * run says so rather than doubling the books. Everything happens in ONE
 * transaction, so a refusal half-way leaves nothing behind.
 */
final class DemoTenantSeeder extends Seeder
{
    /** Present means this seeder has already run in the workspace. */
    private const string MARKER = 'DEMO-C-01';

    /** How far back the company's history starts. */
    private const int DAYS = 150;

    private const string OPENING_BALANCE = '85000.0000';

    private const array CUSTOMERS = [
        ['شركة النور للتجارة', 'NET30'],
        ['مؤسسة الأفق للمقاولات', 'NET30'],
        ['مجموعة الواحة القابضة', 'EOM30'],
        ['شركة رمال الخليج', 'NET15'],
        ['مكتب السدرة للاستشارات', 'NET30'],
        ['شركة نجد للصناعات', 'NET30'],
    ];

    /** [legal name, expense account code, what they bill for]. */
    private const array SUPPLIERS = [
        ['شركة المكتب الحديث للقرطاسية', '5.2', 'Office supplies'],
        ['مؤسسة الخليج للخدمات اللوجستية', '5.1', 'Freight and delivery'],
        ['شركة سحابة للتقنية', '5.2', 'Cloud hosting and licences'],
        ['شركة العقارات المتحدة', '5.2', 'Office rent'],
        ['شركة الطاقة والمرافق', '5.2', 'Electricity and water'],
    ];

    /** Net invoice amounts in whole riyals - multiples of 20, so 15% VAT is exact. */
    private const array INVOICE_NETS = [12400, 7860, 18900, 4200, 9600, 22300, 6740, 14200, 5380, 16820, 8460, 11240];

    private const array BILL_NETS = [2400, 5600, 3120, 8900, 1760, 12600, 4320, 6980];

    /** Every Nth invoice is paid late or not at all, so ageing has a tail. */
    private const int SLOW_PAYER_EVERY = 6;

    private string $userId = '';

    private ?Entity $entity = null;

    private ?BankAccount $bank = null;

    private string $taxCodeId = '';

    private string $revenueAccountId = '';

    /** @var array<string, string> code => id */
    private array $accounts = [];

    /** @var array<string, PaymentTerm> */
    private array $terms = [];

    /** @var list<array{date: CarbonImmutable, amount: string, name: string, remittance: string}> */
    private array $bankMovements = [];

    private CarbonImmutable $today;

    private CarbonImmutable $start;

    public function __construct()
    {
        $this->today = CarbonImmutable::today();
        $this->start = $this->today->subDays(self::DAYS);
    }

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'DemoTenantSeeder writes five months of invented books and will not run in the '.
                app()->environment().' environment.',
            );
        }

        $tenant = Tenant::query()->where('slug', DevelopmentSeeder::TENANT_SLUG)->first()
            ?? throw new RuntimeException('No `demo` workspace. Run DevelopmentSeeder first.');

        app(TenantConnectionResolver::class)->point($tenant);

        $entity = Entity::query()->where('code', 'DEMO')->first()
            ?? throw new RuntimeException('The demo workspace has no DEMO entity. Run DevelopmentSeeder first.');

        if (Party::query()->where('code', self::MARKER)->exists()) {
            $this->command?->info('  The demo books are already there - nothing to do.');

            return;
        }

        $this->entity = $entity;
        $this->userId = self::text(User::query()->where('email', DevelopmentSeeder::USER_EMAIL)->value('id'));

        app(EntityContext::class)->use($entity);

        DB::connection('tenant')->transaction(function (): void {
            $this->foundations();
            $customers = $this->customers();
            $suppliers = $this->suppliers();
            $this->openTheBank();

            $invoices = $this->sell($customers);
            $bills = $this->buy($suppliers);
            $this->leaveSomeDrafts($customers, $suppliers);

            $this->importStatements();
            $this->reconcile();
            $this->fileTheTaxPeriods();

            $this->command?->info(\sprintf(
                '  %d invoices, %d bills and %d bank lines over %d days, through the real services.',
                $invoices,
                $bills,
                \count($this->bankMovements),
                self::DAYS,
            ));
        });
    }

    /* -----------------------------------------------------------------------
     |  Setup
     * -------------------------------------------------------------------- */

    private function foundations(): void
    {
        $entityId = self::text($this->entity()->getKey());

        if (! TaxRegistration::query()->where('entity_id', $entityId)->where('jurisdiction', 'SA')->exists()) {
            TaxRegistration::create([
                'entity_id' => $entityId,
                'jurisdiction' => 'SA',
                'scheme' => 'VAT',
                'identifier' => '310000000000003',
                'eas_code' => '0088',
                'effective_from' => '2020-01-01',
                'is_primary' => true,
                'filing_frequency' => 'monthly',
            ]);
        }

        if (! ReturnPeriod::query()->where('entity_id', $entityId)->exists()) {
            app(ReturnPeriods::class)->generateYear($this->entity(), 'SA', $this->today->year);
        }

        // Open every accounting period the history touches.
        for ($month = $this->start->startOfMonth(); $month->lessThanOrEqualTo($this->today); $month = $month->addMonth()) {
            $period = app(FiscalCalendarGenerator::class)->periodFor($entityId, Carbon::parse($month->toDateString()))
                ?? throw new RuntimeException('No accounting period covers '.$month->format('Y-m').'.');

            if ($period->state !== PeriodState::Open) {
                app(PeriodStateMachine::class)->transition($period, PeriodState::Open, 'opened for the demo books');
            }
        }

        $this->taxCodeId = self::text(app(TaxCodes::class)->introduce(
            entityId: $entityId,
            code: 'SA-S-15',
            category: TaxCategory::Standard,
            jurisdiction: 'SA',
            rate: '15.000000',
            from: CarbonImmutable::parse('2020-07-01'),
            labelAr: 'النسبة الأساسية',
            labelEn: 'Standard rate',
            source: 'the demo seeder',
            reportingBoxes: ['sa' => ['output' => 'standard_sales']],
        )->getKey());

        foreach (Account::query()->where('entity_id', $entityId)->where('postable', true)->get(['id', 'code']) as $account) {
            $this->accounts[self::text($account->getAttribute('code'))] = self::text($account->getKey());
        }

        $this->revenueAccountId = $this->accounts['4.1'] ?? throw new RuntimeException('The chart has no 4.1 Sales revenue.');

        foreach (PaymentTerm::query()->where('active', true)->get() as $term) {
            $this->terms[self::text($term->getAttribute('code'))] = $term;
        }
    }

    /** @return list<Party> */
    private function customers(): array
    {
        $parties = [];

        foreach (self::CUSTOMERS as $i => [$name]) {
            $parties[] = $this->party(\sprintf('DEMO-C-%02d', $i + 1), $name, customer: true, n: $i + 1);
        }

        return $parties;
    }

    /** @return list<Party> */
    private function suppliers(): array
    {
        $parties = [];

        foreach (self::SUPPLIERS as $i => [$name]) {
            $parties[] = $this->party(\sprintf('DEMO-S-%02d', $i + 1), $name, customer: false, n: $i + 21);
        }

        return $parties;
    }

    private function party(string $code, string $name, bool $customer, int $n): Party
    {
        $party = Party::create([
            'code' => $code,
            'legal_name' => $name,
            'is_customer' => $customer,
            'is_supplier' => ! $customer,
            'country' => 'SA',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        PartyTaxRegistration::create([
            'party_id' => $party->getKey(),
            'jurisdiction' => 'SA',
            'scheme' => 'VAT',
            'identifier' => \sprintf('3000000000%03d03', $n),
            'eas_code' => '0088',
            'is_primary' => true,
            'validity' => PartyTaxRegistration::range(CarbonImmutable::parse('2019-01-01')),
        ]);

        return $party;
    }

    private function openTheBank(): void
    {
        $bankGl = self::text(DB::connection('tenant')->table('accounts as a')
            ->join('account_roles as r', 'r.account_id', '=', 'a.id')
            ->where('a.entity_id', self::text($this->entity()->getKey()))
            ->where('r.role', ControlRole::Bank->value)
            ->value('a.id'));

        $this->bank = app(BankAccounts::class)->open(
            entity: $this->entity(),
            label: 'الحساب الجاري الرئيسي',
            institutionName: 'Al Rajhi Bank',
            institutionCountry: 'SA',
            holderLegalName: self::text($this->entity()->getAttribute('legal_name')),
            currency: 'SAR',
            glAccountId: $bankGl,
            openingDate: Carbon::parse($this->start->toDateString()),
            openingBalance: self::OPENING_BALANCE,
            iban: 'SA0380000000608010167519',
            bic: 'RJHISARI',
            domesticScheme: null,
            domesticIdentifier: null,
            userId: $this->userId,
        );

        app(Poster::class)->post(
            entity: $this->entity(),
            ruleKey: OpeningBalanceRule::KEY,
            event: ['balances' => [['account_id' => $bankGl, 'amount' => self::OPENING_BALANCE]]],
            accountingDate: Carbon::parse($this->start->toDateString()),
            narration: 'Opening balance brought forward',
        );
    }

    /* -----------------------------------------------------------------------
     |  Selling and being paid
     * -------------------------------------------------------------------- */

    /** @param list<Party> $customers */
    private function sell(array $customers): int
    {
        $issued = 0;
        $day = $this->start->addDays(3);

        for ($i = 0; $day->lessThan($this->today); $i++, $day = $day->addDays(4 + ($i % 3))) {
            $customer = $customers[$i % \count($customers)] ?? throw new RuntimeException('No customer.');
            [, $termCode] = self::CUSTOMERS[$i % \count(self::CUSTOMERS)];
            $terms = $this->terms[$termCode] ?? $this->terms['NET30'] ?? null;
            $net = self::INVOICE_NETS[$i % \count(self::INVOICE_NETS)];

            $invoice = app(Issuer::class)->issue(
                $this->draft($customer, $day, $net),
                $this->entity(),
                $this->userId,
                ['country' => 'SA', 'rule' => 'domestic', 'category' => 'S', 'rate' => '15.000000'],
                $terms,
            );
            $issued++;

            // Most customers pay a few days either side of the due date; every
            // sixth invoice is a slow payer and is still open, which is what
            // gives the ageing its tail.
            $due = $this->dueDateOf($invoice) ?? $day->addDays(30);
            $paidOn = $due->addDays(($i % 5) - 2);

            if ($i % self::SLOW_PAYER_EVERY !== 5 && $paidOn->lessThan($this->today->subDays(2))) {
                $this->receive($invoice, $customer, $paidOn);
            }
        }

        return $issued;
    }

    private function draft(Party $customer, CarbonImmutable $day, int $net, bool $readBack = true): Invoice
    {
        $netAmount = $net.'.0000';
        $tax = (string) BigDecimal::of($net)->multipliedBy(15)->dividedBy(100, 4);
        $gross = (string) BigDecimal::of($netAmount)->plus($tax)->toScale(4);
        $entityId = self::text($this->entity()->getKey());

        $invoice = Invoice::create([
            'entity_id' => $entityId,
            'series_key' => DocumentType::Invoice->seriesKey(),
            'document_type' => DocumentType::Invoice->value,
            'status' => 'draft',
            'party_id' => self::text($customer->getKey()),
            'issue_date' => $day->toDateString(),
            'tax_point' => $day->toDateString(),
            'currency' => 'SAR',
            'net_amount' => $netAmount,
            'tax_amount' => $tax,
            'gross_amount' => $gross,
            'payable_amount' => $gross,
            'presentation_exponent' => 2,
        ]);

        InvoiceLine::create([
            'entity_id' => $entityId,
            'invoice_id' => self::text($invoice->getKey()),
            'line_no' => 1,
            'description' => 'Consulting and implementation services',
            'unit_code' => 'C62',
            'quantity' => '1.00000000',
            'unit_price' => $netAmount,
            'net_amount' => $netAmount,
            'revenue_account_id' => $this->revenueAccountId,
            'tax_code_id' => $this->taxCodeId,
            'tax_category' => 'S',
            'tax_rate' => '15.000000',
            'tax_point' => $day->toDateString(),
            'place_of_supply' => 'SA',
            'place_of_supply_rule' => 'domestic',
        ]);

        InvoiceTaxBreakdownRow::create([
            'entity_id' => $entityId,
            'invoice_id' => self::text($invoice->getKey()),
            'category' => 'S',
            'rate' => '15.000000',
            'taxable_amount' => $netAmount,
            'tax_amount' => $tax,
            'report_box' => 'standard_sales',
        ]);

        return $readBack ? $invoice->refresh() : $invoice;
    }

    private function receive(Invoice $invoice, Party $customer, CarbonImmutable $on): void
    {
        $payable = self::text($invoice->getAttribute('payable_amount'));
        $number = self::text($invoice->getAttribute('number'));
        $amount = Money::of($payable, 'SAR');

        $payment = app(Payments::class)->record(
            entityId: self::text($this->entity()->getKey()),
            direction: PaymentDirection::Receipt,
            method: PaymentMethod::CreditTransfer,
            amount: $amount,
            valueDate: Carbon::parse($on->toDateString()),
            userId: $this->userId,
            bankAccountId: self::text($this->bank()->getKey()),
            partyId: self::text($customer->getKey()),
            externalReference: $number,
        );

        app(Allocations::class)->allocate(
            paymentId: self::text($payment['id'] ?? null),
            documentType: 'invoice',
            documentId: self::text($invoice->getKey()),
            amount: $amount,
            userId: $this->userId,
            on: Carbon::parse($on->toDateString()),
        );

        $this->bankMovements[] = [
            'date' => $on,
            'amount' => $payable,
            'name' => self::text($customer->getAttribute('legal_name')),
            'remittance' => 'Payment '.$number,
        ];
    }

    /* -----------------------------------------------------------------------
     |  Buying and paying
     * -------------------------------------------------------------------- */

    /** @param list<Party> $suppliers */
    private function buy(array $suppliers): int
    {
        $posted = 0;
        $day = $this->start->addDays(2);

        for ($i = 0; $day->lessThan($this->today); $i++, $day = $day->addDays(6 + ($i % 2))) {
            $supplier = $suppliers[$i % \count($suppliers)] ?? throw new RuntimeException('No supplier.');
            [, $accountCode, $what] = self::SUPPLIERS[$i % \count(self::SUPPLIERS)];
            $net = self::BILL_NETS[$i % \count(self::BILL_NETS)];
            $tax = (string) BigDecimal::of($net)->multipliedBy(15)->dividedBy(100, 4);

            $bill = app(Bills::class)->enter(
                entity: $this->entity(),
                partyId: self::text($supplier->getKey()),
                supplierDocumentNumber: \sprintf('SUP-%s-%03d', $this->today->format('y'), $i + 1),
                supplierDocumentDate: Carbon::parse($day->toDateString()),
                taxPoint: Carbon::parse($day->toDateString()),
                postingDate: Carbon::parse($day->toDateString()),
                currency: 'SAR',
                lines: [[
                    'description' => $what,
                    'quantity' => '1',
                    'unit_price' => (string) $net,
                    'net_amount' => $net.'.0000',
                    'expense_account_id' => $this->accounts[$accountCode] ?? $this->account('5.2'),
                    'tax_code_id' => $this->taxCodeId,
                    'tax_category' => 'S',
                    'tax_rate' => '15.000000',
                    'tax_amount' => $tax,
                ]],
                createdBy: $this->userId,
                paymentTermId: isset($this->terms['NET30']) ? self::text($this->terms['NET30']->getKey()) : null,
            );

            $bill = app(Bills::class)->post($bill, $this->entity(), $this->userId);
            $posted++;

            // Paid on the due date, except every fifth one, which is late.
            $paidOn = $day->addDays(30);

            if ($i % 5 !== 3 && $paidOn->lessThan($this->today->subDays(1))) {
                $this->pay($bill, $supplier, $paidOn);
            }
        }

        return $posted;
    }

    private function pay(\App\Tenant\Purchasing\Models\Bill $bill, Party $supplier, CarbonImmutable $on): void
    {
        $payable = self::text($bill->getAttribute('payable_amount'));
        $reference = self::text($bill->getAttribute('supplier_document_number'));
        $amount = Money::of($payable, 'SAR');

        $payment = app(Payments::class)->record(
            entityId: self::text($this->entity()->getKey()),
            direction: PaymentDirection::Disbursement,
            method: PaymentMethod::CreditTransfer,
            amount: $amount,
            valueDate: Carbon::parse($on->toDateString()),
            userId: $this->userId,
            bankAccountId: self::text($this->bank()->getKey()),
            partyId: self::text($supplier->getKey()),
            externalReference: $reference,
        );

        app(Allocations::class)->allocate(
            paymentId: self::text($payment['id'] ?? null),
            documentType: 'bill',
            documentId: self::text($bill->getKey()),
            amount: $amount,
            userId: $this->userId,
            on: Carbon::parse($on->toDateString()),
        );

        $this->bankMovements[] = [
            'date' => $on,
            'amount' => '-'.$payable,
            'name' => self::text($supplier->getAttribute('legal_name')),
            'remittance' => 'Payment '.$reference,
        ];
    }

    /**
     * @param  list<Party>  $customers
     * @param  list<Party>  $suppliers
     */
    private function leaveSomeDrafts(array $customers, array $suppliers): void
    {
        $this->draft($customers[0] ?? throw new RuntimeException('No customer.'), $this->today, 9840);
        $this->draft($customers[3] ?? throw new RuntimeException('No customer.'), $this->today, 3260);

        app(Bills::class)->enter(
            entity: $this->entity(),
            partyId: self::text(($suppliers[2] ?? throw new RuntimeException('No supplier.'))->getKey()),
            supplierDocumentNumber: 'SUP-DRAFT-001',
            supplierDocumentDate: Carbon::parse($this->today->toDateString()),
            taxPoint: Carbon::parse($this->today->toDateString()),
            postingDate: Carbon::parse($this->today->toDateString()),
            currency: 'SAR',
            lines: [[
                'description' => 'Cloud hosting and licences',
                'quantity' => '1',
                'unit_price' => '2860',
                'net_amount' => '2860.0000',
                'expense_account_id' => $this->account('5.2'),
                'tax_code_id' => $this->taxCodeId,
                'tax_category' => 'S',
                'tax_rate' => '15.000000',
                'tax_amount' => '429.0000',
            ]],
            createdBy: $this->userId,
        );
    }

    /* -----------------------------------------------------------------------
     |  The bank's side
     * -------------------------------------------------------------------- */

    /**
     * One statement per calendar month, each opening where the last closed,
     * holding exactly the receipts and payments recorded above - so the
     * ledger and the bank agree, as they would at a real company.
     */
    private function importStatements(): void
    {
        usort($this->bankMovements, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        $balance = BigDecimal::of(self::OPENING_BALANCE);
        $sequence = 0;

        for ($month = $this->start->startOfMonth(); $month->lessThanOrEqualTo($this->today); $month = $month->addMonth()) {
            $from = $month->lessThan($this->start) ? $this->start : $month;
            $to = $month->endOfMonth()->startOfDay()->min($this->today->subDay());

            if ($to->lessThan($from)) {
                continue;
            }

            $lines = [];
            $opening = $balance;

            foreach ($this->bankMovements as $movement) {
                if ($movement['date']->lessThan($from) || $movement['date']->greaterThan($to)) {
                    continue;
                }

                $sequence++;
                $balance = $balance->plus($movement['amount']);
                $lines[] = [
                    'booking_date' => $movement['date']->toDateString(),
                    'value_date' => $movement['date']->toDateString(),
                    'amount' => (string) BigDecimal::of($movement['amount'])->toScale(4),
                    'bank_reference' => \sprintf('RJ%s%04d', $movement['date']->format('ymd'), $sequence),
                    'counterparty_name' => $movement['name'],
                    'remittance_unstructured' => $movement['remittance'],
                ];
            }

            app(StatementImport::class)->import(
                account: $this->bank()->refresh(),
                sourceFormat: 'camt053',
                periodFrom: Carbon::parse($from->toDateString()),
                periodTo: Carbon::parse($to->toDateString()),
                openingBalance: (string) $opening->toScale(4),
                closingBalance: (string) $balance->toScale(4),
                lines: $lines,
                statementNumber: $month->format('Y/m'),
                fileName: 'camt053-'.$month->format('Y-m').'.xml',
                contentHash: hash('sha256', 'demo-statement-'.$month->format('Y-m')),
                userId: $this->userId,
            );
        }
    }

    /**
     * Older lines matched, the last fortnight left as a person would find it:
     * some with a suggestion waiting, the rest not looked at yet.
     */
    private function reconcile(): void
    {
        $engine = app(MatchingEngine::class);
        $matches = app(Matches::class);
        $cutoff = $this->today->subDays(14);
        $i = 0;

        $transactions = BankTransaction::query()
            ->where('bank_account_id', self::text($this->bank()->getKey()))
            ->orderBy('booking_date')
            ->get();

        foreach ($transactions as $transaction) {
            $recent = CarbonImmutable::parse(self::text($transaction->getAttribute('booking_date')))->greaterThan($cutoff);
            $i++;

            if ($recent && $i % 2 === 0) {
                continue; // not looked at yet
            }

            try {
                $engine->propose($transaction);
            } catch (Throwable) {
                continue; // a line the engine has no candidate for stays waiting
            }

            if ($recent) {
                continue; // a suggestion, waiting for a person
            }

            $best = MatchProposal::query()
                ->where('bank_transaction_id', self::text($transaction->getKey()))
                ->where('status', 'proposed')
                ->orderBy('rank')
                ->first();

            if ($best !== null) {
                try {
                    $matches->accept($best, $this->userId);
                } catch (Throwable) {
                    // left as a suggestion
                }
            }
        }
    }

    /**
     * Every return period before last month filed; the last two left open
     * with the date they are due, as at a company that files on time.
     */
    private function fileTheTaxPeriods(): void
    {
        $periods = ReturnPeriod::query()
            ->where('entity_id', self::text($this->entity()->getKey()))
            ->where('state', 'open')
            ->orderBy('label')
            ->get();

        $lastMonth = $this->today->subMonthNoOverflow()->startOfMonth();

        foreach ($periods as $period) {
            $label = self::text($period->getAttribute('label'));
            $starts = CarbonImmutable::parse(substr($label, 0, 7).'-01');

            try {
                if ($starts->lessThan($lastMonth)) {
                    app(ReturnPeriods::class)->file($period, $this->userId, 'DEMO-'.$label, $starts->addMonth()->addDays(20));
                } elseif ($starts->lessThanOrEqualTo($this->today)) {
                    $period->update(['due_on' => $starts->addMonth()->endOfMonth()->toDateString()]);
                }
            } catch (Throwable $e) {
                $this->command?->warn('  Return period '.$label.': '.$e->getMessage());
            }
        }
    }

    private function entity(): Entity
    {
        return $this->entity ?? throw new RuntimeException('The demo entity was not resolved.');
    }

    private function bank(): BankAccount
    {
        return $this->bank ?? throw new RuntimeException('The demo bank account was not opened.');
    }

    private function account(string $code): string
    {
        return $this->accounts[$code] ?? throw new RuntimeException('The chart has no account '.$code.'.');
    }

    /**
     * A model attribute or id as a string, with its type checked rather than
     * cast blind: a date is its calendar day, anything unexpected is empty.
     */
    private static function text(mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_int($value) => (string) $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof Stringable => (string) $value,
            default => '',
        };
    }

    private function dueDateOf(Invoice $invoice): ?CarbonImmutable
    {
        $dates = $invoice->dueDates();
        $last = null;

        foreach ($dates as $due) {
            $date = CarbonImmutable::parse(self::text(\is_array($due) ? ($due['date'] ?? null) : null));
            $last = $last === null || $date->greaterThan($last) ? $date : $last;
        }

        return $last;
    }
}
