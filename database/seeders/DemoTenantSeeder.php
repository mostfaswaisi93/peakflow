<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Platform\Services\TenantProvisioner;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Tenant\Banking\Matching\MatchingEngine;
use App\Tenant\Banking\Models\BankAccount;
use App\Tenant\Banking\Models\BankTransaction;
use App\Tenant\Banking\Models\MatchProposal;
use App\Tenant\Banking\Services\BankAccounts;
use App\Tenant\Banking\Services\Matches;
use App\Tenant\Banking\Services\StatementImport;
use App\Tenant\Catalog\Models\PaymentTerm;
use App\Tenant\Catalog\Services\CurrencyPolicy;
use App\Tenant\Catalog\Services\ExchangeRates;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Ledger\Posting\Rules\OpeningBalanceRule;
use App\Tenant\Ledger\Services\ChartTemplate;
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
    /**
     * A year, so every ageing bucket has something in it - PF-281.
     *
     * 150 days could not put anything in a 365-day bucket, and an ageing
     * report whose last column is always empty reads as a report that does
     * not work rather than as a company that collects its debts.
     */
    private const int DAYS = 365;

    /**
     * The functional currency, and the jurisdiction that goes with it.
     *
     * ## This is a default, not an option
     *
     * The fixture used to trade in riyals under a Saudi VAT registration.
     * Changing the currency alone would have left a Saudi registration
     * billing in shekels, which is not a demo anybody could show to an
     * accountant - so the registration, the party countries, the bank and
     * the tax code move together.
     *
     * ## The rate, and what it rests on
     *
     * 16% is the Palestinian Authority's standard VAT rate. Under the Paris
     * Economic Protocol the PA's rate tracks Israel's within two percentage
     * points, and Israel's standard rate is 18%.
     *
     * That is stated here as **the demo's assumption**, not as a verified
     * statutory citation: no authority source could be read from this
     * environment, and `config/tax_returns.php` already marks the box
     * numbers it could not verify for the same reason. A fixture is the
     * right place for an assumption as long as it is written down; a tax
     * engine is not, and this rate is not wired into one.
     *
     * The jurisdiction has no profile of its own and does not need one: it
     * falls through to the `generic` EN 16931 return in
     * `config/tax_returns.php`, which is explicit about being a minimal
     * form rather than a statutory one.
     */
    private const string TENANT_SLUG = 'showcase';

    private const string ENTITY_CODE = 'SHOW';

    private const string USER_EMAIL = 'owner@showcase.test';

    private const string CURRENCY = 'ILS';

    private const string JURISDICTION = 'PS';

    private const string VAT_RATE = '16.000000';

    /**
     * A customer who buys in dollars and a supplier who invoices in euros.
     *
     * Without these the FX difference account stays at zero and the
     * revaluation has nothing to revalue, so two whole screens of the
     * product demonstrate as empty - PF-281.
     */
    private const string FOREIGN_SALES_CURRENCY = 'USD';

    private const string FOREIGN_PURCHASE_CURRENCY = 'EUR';

    /** One IBAN per foreign account: invented bank code, real check digits. */
    private const array FOREIGN_IBANS = [
        'USD' => 'PS53DEMO000000000000000067890',
        'EUR' => 'PS47DEMO000000000000000054321',
    ];

    private const string OPENING_BALANCE = '85000.0000';

    private const array CUSTOMERS = [
        ['شركة النور للتجارة', 'NET30'],
        ['مؤسسة الأفق للمقاولات', 'NET30'],
        ['مجموعة الزيتون القابضة', 'EOM30'],
        ['شركة البحر المتوسط للتوزيع', 'NET15'],
        ['مكتب السنابل للاستشارات', 'NET30'],
        ['شركة الكرمل للصناعات', 'NET30'],
    ];

    /** [legal name, expense account code, what they bill for]. */
    private const array SUPPLIERS = [
        ['شركة المكتب الحديث للقرطاسية', '5.2', 'Office supplies'],
        ['مؤسسة الشرق للخدمات اللوجستية', '5.1', 'Freight and delivery'],
        ['شركة سحابة للتقنية', '5.2', 'Cloud hosting and licences'],
        ['شركة العقارات المتحدة', '5.2', 'Office rent'],
        ['شركة الطاقة والمرافق', '5.2', 'Electricity and water'],
    ];

    /**
     * Net invoice amounts in whole shekels - multiples of 25.
     *
     * 16% is 4/25, so a net that is a multiple of 25 carries a tax amount in
     * whole shekels. Not because a fraction could not be represented - the
     * money type is decimal - but because a demo whose every line reads
     * 1,257.60 invites the viewer to wonder whether the rounding is right
     * instead of looking at the product.
     */
    private const array INVOICE_NETS = [12400, 7850, 18900, 4200, 9600, 22300, 6750, 14200, 5375, 16825, 8450, 11250];

    private const array BILL_NETS = [2400, 5600, 3125, 8900, 1750, 12600, 4325, 6975];

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
                'DemoTenantSeeder writes a year of invented books and will not run in the '.
                app()->environment().' environment.',
            );
        }

        $tenant = $this->workspace();

        app(TenantConnectionResolver::class)->point($tenant);

        $entity = $this->showcaseEntity();

        if (Party::query()->where('code', self::MARKER)->exists()) {
            $this->command?->info('  The showcase books are already there - nothing to do.');

            return;
        }

        $this->entity = $entity;
        $this->userId = self::text($this->owner($entity)->getKey());

        app(EntityContext::class)->use($entity);

        DB::connection('tenant')->transaction(function (): void {
            $this->foundations();
            $customers = $this->customers();
            $suppliers = $this->suppliers();
            $this->openTheBank();

            $invoices = $this->sell($customers);
            $bills = $this->buy($suppliers);

            $abroad = $this->tradeAbroad();
            $invoices += $abroad['invoices'];
            $bills += $abroad['bills'];

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
     |  Its own workspace
     * -------------------------------------------------------------------- */

    /**
     * Provision the showcase workspace, separately from `demo` - PF-281.
     *
     * ## Why it is not the development workspace
     *
     * It used to borrow `demo`, which made the two fixtures one: the
     * development seeder had to stay in whatever shape the demo books
     * needed, and a developer who wanted the minimal workspace got a year
     * of trading in it or nothing at all.
     *
     * The coupling had a second cost that is harder to see. `demo` is
     * created once and re-seeded for months, so its chart of accounts is
     * whatever template version was current the day it was provisioned -
     * and `ChartTemplate::apply()` is a no-op on an entity that already has
     * a chart, by design. An entity created under chart v5 never receives
     * the accounts v6 added, there is no upgrade path, and the symptom is
     * the books refusing a posting for want of an `unapplied_cash` account
     * that the template has had for versions. A workspace provisioned when
     * the showcase is seeded has the current chart because it has no other.
     */
    private function workspace(): Tenant
    {
        $existing = Tenant::query()->where('slug', self::TENANT_SLUG)->first();

        if ($existing !== null) {
            return $existing;
        }

        // Through the real provisioner: a seeder that writes the registry row
        // itself produces a tenant with no database, and the first query
        // fails about a missing password rather than about the seeder.
        return app(TenantProvisioner::class)->create('Peakflow Showcase', 'business', self::TENANT_SLUG);
    }

    private function showcaseEntity(): Entity
    {
        $entity = Entity::query()->where('code', self::ENTITY_CODE)->first();

        if ($entity !== null) {
            return $entity;
        }

        $entity = Entity::query()->create([
            'code' => self::ENTITY_CODE,
            'legal_name' => 'شركة بيك فلو للتجارة العامة',
            'trading_name' => 'Peakflow Showcase',
            'country' => self::JURISDICTION,
            'functional_currency' => self::CURRENCY,
            'presentation_currency' => self::CURRENCY,
            'jurisdiction_profiles' => ['generic'],
            'active' => true,
        ]);

        app(EntityContext::class)->during(
            $entity,
            static fn (): array => app(ChartTemplate::class)->apply((string) $entity->getKey(), 'ifrs-generic'),
        );

        return $entity;
    }

    /**
     * The owner of the showcase workspace.
     *
     * Invented, under a domain RFC 2606 reserves, as PF-239 requires: a
     * fixture that seeds a plausible real address is a fixture that mails
     * somebody when a notification path is wired up by accident.
     */
    private function owner(Entity $entity): User
    {
        $user = User::query()->where('email', self::USER_EMAIL)->first();

        if ($user === null) {
            $user = User::query()->create([
                'name' => 'Showcase Owner',
                'email' => self::USER_EMAIL,
                'password' => 'peakflow',
                'locale' => 'ar',
                'timezone' => 'Asia/Hebron',
                'active' => true,
            ]);
        }

        $owner = Role::query()->where('key', 'owner')->first();

        if ($owner !== null) {
            $assigned = UserEntityRole::query()
                ->where('user_id', $user->getKey())
                ->where('entity_id', $entity->getKey())
                ->where('role_id', $owner->getKey())
                ->exists();

            if (! $assigned) {
                UserEntityRole::query()->create([
                    'user_id' => $user->getKey(),
                    'entity_id' => $entity->getKey(),
                    'role_id' => $owner->getKey(),
                ]);
            }
        }

        if ($user->last_entity_id === null) {
            $user->forceFill(['last_entity_id' => $entity->getKey()])->save();
        }

        return $user;
    }

    /* -----------------------------------------------------------------------
     |  Setup
     * -------------------------------------------------------------------- */

    private function foundations(): void
    {
        $entityId = self::text($this->entity()->getKey());

        if (! TaxRegistration::query()->where('entity_id', $entityId)->where('jurisdiction', self::JURISDICTION)->exists()) {
            TaxRegistration::create([
                'entity_id' => $entityId,
                'jurisdiction' => self::JURISDICTION,
                'scheme' => 'VAT',
                'identifier' => '400000003',
                'eas_code' => null,
                'effective_from' => '2020-01-01',
                'is_primary' => true,
                'filing_frequency' => 'monthly',
            ]);
        }

        /*
         | Both calendar years, because a year of trading crosses one.
         |
         | With 150 days of history this happened to work from the current
         | year alone for most of the year and to break in January, which is
         | the kind of fixture that is green until somebody demonstrates the
         | product in the first week of a new year.
         */
        foreach (array_unique([$this->start->year, $this->today->year]) as $year) {
            $already = ReturnPeriod::query()
                ->where('entity_id', $entityId)
                ->where('jurisdiction', self::JURISDICTION)
                ->where('label', 'like', $year.'%')
                ->exists();

            if (! $already) {
                // generateYear REFUSES a year it has already generated rather
                // than skipping it, so the guard has to be here.
                app(ReturnPeriods::class)->generateYear($this->entity(), self::JURISDICTION, $year);
            }
        }

        /*
         | The fiscal years the history spans, before the periods inside them.
         |
         | A year of trading crosses a year boundary, and `periodFor()`
         | returns null rather than creating anything - so with only the
         | current fiscal year in place the seeder stopped at "No accounting
         | period covers 2025-11". `generate()` returns an existing year
         | untouched, so asking for both is safe on a re-run.
         */
        foreach (array_unique([$this->start->year, $this->today->year]) as $year) {
            app(FiscalCalendarGenerator::class)->generate(
                entityId: $entityId,
                label: (string) $year,
                startsOn: Carbon::create($year, 1, 1),
            );
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
            code: 'PS-S-16',
            category: TaxCategory::Standard,
            jurisdiction: self::JURISDICTION,
            rate: self::VAT_RATE,
            from: CarbonImmutable::parse('2020-07-01'),
            labelAr: 'النسبة الأساسية',
            labelEn: 'Standard rate',
            source: 'the demo seeder',
            reportingBoxes: ['generic' => ['output' => 'standard_sales', 'input' => 'standard_purchases']],
        )->getKey());

        foreach (Account::query()->where('entity_id', $entityId)->where('postable', true)->get(['id', 'code']) as $account) {
            $this->accounts[self::text($account->getAttribute('code'))] = self::text($account->getKey());
        }

        $this->revenueAccountId = $this->accounts['4.1'] ?? throw new RuntimeException('The chart has no 4.1 Sales revenue.');

        foreach (PaymentTerm::query()->where('active', true)->get() as $term) {
            $this->terms[self::text($term->getAttribute('code'))] = $term;
        }

        $this->loadTheRates();
    }

    /**
     * A rate curve, because one rate makes an FX fixture prove nothing.
     *
     * ## Why a curve rather than a rate
     *
     * `ExchangeRates` resolves the most recent rate on or before a date. A
     * single rate would therefore be the rate on the invoice AND the rate
     * on the payment, the two legs of the allocation would translate at the
     * same number, and the realized difference would be zero - so the FX
     * difference account would sit at zero and the fixture would
     * demonstrate nothing while looking complete.
     *
     * One rate a day, drifting, so a document settles at a different rate
     * than it was booked at. That difference is what
     * `AllocationPostedRule` books as realized, and it is left over by
     * construction rather than computed.
     *
     * ## Daily, because monthly was stale
     *
     * The first version loaded one rate a month, which is a rate up to 30
     * days old on the day a document is posted and up to 50 on the day it
     * is paid. `LedgerVerifier` reported every one of those as *"posted at
     * a stale rate... against a threshold of 3"*, and it was right: trading
     * on an old rate is legitimate when a feed is down, and a fixture whose
     * normal state is a stale rate teaches the reader to ignore the
     * finding. It starts three days before the first document so the
     * earliest one is not itself stale.
     *
     * A real feed publishes daily, so this does too - 366 days of two
     * pairs, which is what the staleness threshold is written against.
     *
     * ## The figures
     *
     * Plausible mid-market levels for the shekel - roughly 3.6-3.8 to the
     * dollar and 3.9-4.2 to the euro - moved by a few agorot a month. They
     * are invented, and the provider says so: `seed` is not a feed, and a
     * fixture that labelled these as coming from a central bank would be a
     * fixture somebody eventually cites.
     */
    private function loadTheRates(): void
    {
        $policy = app(CurrencyPolicy::class);

        foreach ([self::FOREIGN_SALES_CURRENCY, self::FOREIGN_PURCHASE_CURRENCY] as $code) {
            $policy->enable($this->entity(), $code);
        }

        $rates = app(ExchangeRates::class);
        $n = 0;

        for ($day = $this->start->subDays(3); $day->lessThanOrEqualTo($this->today); $day = $day->addDay(), $n++) {
            /*
             | A deterministic wobble, not random.
             |
             | A seeder whose figures change between runs produces a
             | different trial balance every time, and then nobody can tell
             | a real change from the seed moving under them.
             */
            $wobble = (($n * 7) % 61) - 30;

            $rates->record(
                self::FOREIGN_SALES_CURRENCY,
                self::CURRENCY,
                Carbon::parse($day->toDateString()),
                (string) BigDecimal::of('3.7000')->plus(BigDecimal::of($wobble)->multipliedBy('0.0020'))->toScale(4),
                'seed',
            );

            $rates->record(
                self::FOREIGN_PURCHASE_CURRENCY,
                self::CURRENCY,
                Carbon::parse($day->toDateString()),
                (string) BigDecimal::of('4.0500')->plus(BigDecimal::of(-$wobble)->multipliedBy('0.0025'))->toScale(4),
                'seed',
            );
        }
    }

    /**
     * The rate in force for a currency on a day.
     *
     * `rateFor()` refuses rather than returning null or 1 when nothing is
     * loaded, which is the behaviour this criterion wants: a document
     * posted at a rate nobody loaded is the thing to make impossible, and
     * a fixture with its own fallback would be the fallback. If this
     * throws, the curve does not cover the dates the fixture uses, and the
     * fix is the curve.
     */
    private function rateOn(string $currency, CarbonImmutable $day): Rate
    {
        return app(ExchangeRates::class)->rateFor(
            $currency,
            self::CURRENCY,
            Carbon::parse($day->toDateString()),
        );
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

    private function party(
        string $code,
        string $name,
        bool $customer,
        int $n,
        ?string $currency = null,
    ): Party {
        $party = Party::create([
            'code' => $code,
            'legal_name' => $name,
            'is_customer' => $customer,
            'is_supplier' => ! $customer,
            'country' => self::JURISDICTION,
            'default_currency' => $currency ?? self::CURRENCY,
            'active' => true,
        ]);

        PartyTaxRegistration::create([
            'party_id' => $party->getKey(),
            'jurisdiction' => self::JURISDICTION,
            'scheme' => 'VAT',
            'identifier' => \sprintf('4%08d', $n),
            'eas_code' => null,
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
            /*
             | An invented institution, and an invented bank code inside the
             | IBAN and the BIC.
             |
             | The check digits are real - PS51DEMO... satisfies mod-97, so
             | the fixture exercises the validator rather than bypassing it -
             | but `DEMO` is not an assigned bank code and `DEMOPS22` is not
             | an assigned BIC. A demo that carries a real bank's identifiers
             | is a demo that names a company which had no say in it.
             */
            institutionName: 'Demo Bank of Palestine (invented)',
            institutionCountry: self::JURISDICTION,
            holderLegalName: self::text($this->entity()->getAttribute('legal_name')),
            currency: self::CURRENCY,
            glAccountId: $bankGl,
            openingDate: Carbon::parse($this->start->toDateString()),
            openingBalance: self::OPENING_BALANCE,
            iban: 'PS51DEMO000000000000000012345',
            bic: 'DEMOPS22',
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
                ['country' => self::JURISDICTION, 'rule' => 'domestic', 'category' => 'S', 'rate' => self::VAT_RATE],
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

    private function draft(
        Party $customer,
        CarbonImmutable $day,
        int $net,
        bool $readBack = true,
        ?string $currency = null,
    ): Invoice {
        $currency ??= self::CURRENCY;
        $netAmount = $net.'.0000';
        $tax = self::taxOn($netAmount);
        $gross = (string) BigDecimal::of($netAmount)->plus($tax)->toScale(4);
        $entityId = self::text($this->entity()->getKey());

        /*
         | The rate is frozen on the document, not looked up when it is read.
         |
         | `InvoiceValidator` refuses a foreign-currency invoice with no
         | rate, which is the control working: an invoice whose functional
         | value is computed at read time changes value when a rate is
         | corrected, and an issued document does not get to do that.
         */
        $rate = $currency === self::CURRENCY ? null : $this->rateOn($currency, $day);

        $invoice = Invoice::create([
            'entity_id' => $entityId,
            'series_key' => DocumentType::Invoice->seriesKey(),
            'document_type' => DocumentType::Invoice->value,
            'status' => 'draft',
            'party_id' => self::text($customer->getKey()),
            'issue_date' => $day->toDateString(),
            'tax_point' => $day->toDateString(),
            'currency' => $currency,
            'fx_rate' => $rate?->value,
            'fx_source' => $rate === null ? null : 'seed',
            'fx_rate_date' => $rate === null ? null : $day->toDateString(),
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
            'tax_rate' => self::VAT_RATE,
            'tax_point' => $day->toDateString(),
            'place_of_supply' => self::JURISDICTION,
            'place_of_supply_rule' => 'domestic',
        ]);

        InvoiceTaxBreakdownRow::create([
            'entity_id' => $entityId,
            'invoice_id' => self::text($invoice->getKey()),
            'category' => 'S',
            'rate' => self::VAT_RATE,
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
        $amount = Money::of($payable, self::CURRENCY);

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
     |  Trading in another currency
     * -------------------------------------------------------------------- */

    /**
     * One customer in dollars and one supplier in euros - PF-281.
     *
     * ## Foreign currency, not foreign supply
     *
     * Both are local companies that contract in another currency, which is
     * ordinary here. Making them foreign would also make the sale an
     * export, and an export is a different tax treatment - a zero-rated
     * category, a place of supply outside the jurisdiction and a second tax
     * code - none of which this criterion asks for. The criterion asks for
     * a non-zero FX difference account and a revaluation with something to
     * revalue, and a currency is enough for both.
     *
     * ## Why the payment lands months later
     *
     * The realized difference only exists if the rate moved between the
     * document and the money. A payment the same week settles at the rate
     * it was booked at, the two legs of the allocation translate at the
     * same number, and `fx_gain_loss` stays at zero while every screen
     * looks populated - which is the fixture proving nothing and looking
     * like it proved something.
     *
     * @return array{invoices: int, bills: int}
     */
    private function tradeAbroad(): array
    {
        $customer = $this->party(self::MARKER.'-USD', 'شركة الجسر للتجارة الدولية', true, 90, self::FOREIGN_SALES_CURRENCY);
        $supplier = $this->party(self::MARKER.'-EUR', 'شركة البحر للشحن الدولي', false, 91, self::FOREIGN_PURCHASE_CURRENCY);

        $dollars = $this->foreignBank(self::FOREIGN_SALES_CURRENCY, 'الحساب بالدولار');
        $euros = $this->foreignBank(self::FOREIGN_PURCHASE_CURRENCY, 'الحساب باليورو');

        $invoices = 0;
        $bills = 0;

        // Four quarters, so each one straddles a rate change.
        foreach ([40, 130, 220, 310] as $i => $offset) {
            $day = $this->start->addDays($offset);
            $net = [7500, 12000, 4800, 9600][$i];

            $invoice = app(Issuer::class)->issue(
                $this->draft($customer, $day, $net, currency: self::FOREIGN_SALES_CURRENCY),
                $this->entity(),
                $this->userId,
                ['country' => self::JURISDICTION, 'rule' => 'domestic', 'category' => 'S', 'rate' => self::VAT_RATE],
                $this->terms['NET30'] ?? null,
            );
            $invoices++;

            // 50 days, so the rate has moved by the time the money arrives.
            $paidOn = $day->addDays(50);

            /*
             | The last one stays open, so the revaluation has something.
             |
             | `MonetaryRevaluation` works on monetary balances still held in
             | a foreign currency. If every foreign document were settled the
             | account would be flat at the period end, the revaluation would
             | have nothing to revalue, and that screen would demonstrate as
             | empty - which is half of what this criterion asks for.
             */
            $leaveOpen = $i === 3;

            if (! $leaveOpen && $paidOn->lessThan($this->today->subDays(2))) {
                $this->settleAbroad(
                    'invoice',
                    self::text($invoice->getKey()),
                    self::text($invoice->getAttribute('payable_amount')),
                    self::FOREIGN_SALES_CURRENCY,
                    PaymentDirection::Receipt,
                    $dollars,
                    $customer,
                    self::text($invoice->getAttribute('number')),
                    $paidOn,
                );
            }
        }

        foreach ([70, 190, 300] as $i => $offset) {
            $day = $this->start->addDays($offset);
            $net = [3200, 5400, 2600][$i];

            /*
             | Asked for, though `Bills::enter()` resolves it itself.
             |
             | It refuses if nothing is loaded for the pair and the date, so
             | this fails HERE - naming the pair and the day - rather than
             | inside the service with a bill half-built. The criterion is
             | that no document is posted at a rate nobody loaded, and the
             | cheapest place to prove the curve covers the fixture's dates
             | is before the first bill of each one.
             */
            $this->rateOn(self::FOREIGN_PURCHASE_CURRENCY, $day);

            $bill = app(Bills::class)->enter(
                entity: $this->entity(),
                partyId: self::text($supplier->getKey()),
                supplierDocumentNumber: sprintf('MFB-%s-%02d', $this->today->format('y'), $i + 1),
                supplierDocumentDate: Carbon::parse($day->toDateString()),
                taxPoint: Carbon::parse($day->toDateString()),
                postingDate: Carbon::parse($day->toDateString()),
                currency: self::FOREIGN_PURCHASE_CURRENCY,
                lines: [[
                    'description' => 'International freight',
                    'quantity' => '1',
                    'unit_price' => (string) $net,
                    'net_amount' => $net.'.0000',
                    'expense_account_id' => $this->accounts['5.1'] ?? $this->account('5.2'),
                    'tax_code_id' => $this->taxCodeId,
                    'tax_category' => 'S',
                    'tax_rate' => self::VAT_RATE,
                    'tax_amount' => self::taxOn($net.'.0000'),
                ]],
                createdBy: $this->userId,
                paymentTermId: isset($this->terms['NET30']) ? self::text($this->terms['NET30']->getKey()) : null,
            );

            $bill = app(Bills::class)->post($bill, $this->entity(), $this->userId);
            $bills++;

            $paidOn = $day->addDays(45);

            // And one euro bill unpaid, for the same reason on the other side.
            if ($i !== 2 && $paidOn->lessThan($this->today->subDays(2))) {
                $this->settleAbroad(
                    'bill',
                    self::text($bill->getKey()),
                    self::text($bill->getAttribute('payable_amount')),
                    self::FOREIGN_PURCHASE_CURRENCY,
                    PaymentDirection::Disbursement,
                    $euros,
                    $supplier,
                    self::text($bill->getAttribute('supplier_document_number')),
                    $paidOn,
                );
            }
        }

        return ['invoices' => $invoices, 'bills' => $bills];
    }

    /**
     * Record and allocate a payment in the document's own currency.
     *
     * A payment in one currency against a bank account in another is
     * refused - rightly, because a rate would have to be picked and
     * nothing would say which - so each currency has its own account.
     */
    private function settleAbroad(
        string $documentType,
        string $documentId,
        string $payable,
        string $currency,
        PaymentDirection $direction,
        BankAccount $account,
        Party $party,
        string $reference,
        CarbonImmutable $on,
    ): void {
        $amount = Money::of($payable, $currency);

        $payment = app(Payments::class)->record(
            entityId: self::text($this->entity()->getKey()),
            direction: $direction,
            method: PaymentMethod::CreditTransfer,
            amount: $amount,
            valueDate: Carbon::parse($on->toDateString()),
            userId: $this->userId,
            bankAccountId: self::text($account->getKey()),
            partyId: self::text($party->getKey()),
            externalReference: $reference,
        );

        app(Allocations::class)->allocate(
            paymentId: self::text($payment['id'] ?? null),
            documentType: $documentType,
            documentId: $documentId,
            amount: $amount,
            userId: $this->userId,
            on: Carbon::parse($on->toDateString()),
        );
    }

    /**
     * A bank account in a currency other than the functional one.
     *
     * ## Each one gets its own GL account, because the product insists
     *
     * The first version pointed all three accounts at the bank control
     * account, reasoning that the ledger records a currency on every line
     * so one account could carry them all. `BankAccounts::open()` refused
     * it, and the refusal is the better argument: *"Two bank accounts
     * sharing one GL account cannot be reconciled separately: the ledger
     * has one balance and the bank has two, and no arithmetic recovers
     * which part is which."* Reconciliation is per account, and a shared
     * control account makes it impossible rather than inconvenient.
     *
     * So each currency gets a sub-account under the bank control account -
     * which is what the refusal says to do, and what a real chart looks
     * like for a company holding three currencies.
     */
    private function foreignBank(string $currency, string $label): BankAccount
    {
        return app(BankAccounts::class)->open(
            entity: $this->entity(),
            label: $label,
            institutionName: 'Demo Bank of Palestine (invented)',
            institutionCountry: self::JURISDICTION,
            holderLegalName: self::text($this->entity()->getAttribute('legal_name')),
            currency: $currency,
            glAccountId: $this->bankSubAccountFor($currency),
            openingDate: Carbon::parse($this->start->toDateString()),
            openingBalance: '0',
            // Invented bank code, real check digits - as with the shekel
            // account above. An account with neither an IBAN nor a domestic
            // identifier is refused, and rightly: it is an account nobody
            // can pay into that looks complete until somebody tries.
            iban: self::FOREIGN_IBANS[$currency] ?? throw new RuntimeException(
                'No IBAN is defined for the '.$currency.' account.',
            ),
            bic: 'DEMOPS22',
            userId: $this->userId,
        );
    }

    /**
     * A postable sub-account of the bank control account, for one currency.
     *
     * No `AccountRole`: the role belongs to the parent, and a second
     * account claiming `bank` would make "the bank control account"
     * ambiguous for every posting rule that asks for it by role.
     */
    private function bankSubAccountFor(string $currency): string
    {
        $entityId = self::text($this->entity()->getKey());

        $parent = Account::query()
            ->where('entity_id', $entityId)
            ->whereIn('id', AccountRole::query()
                ->where('entity_id', $entityId)
                ->where('role', ControlRole::Bank->value)
                ->select('account_id'))
            ->firstOrFail();

        $code = self::text($parent->getAttribute('code')).'.'.strtolower($currency);

        $existing = Account::query()->where('entity_id', $entityId)->where('code', $code)->first();

        if ($existing !== null) {
            return self::text($existing->getKey());
        }

        $path = self::text($parent->getAttribute('path')).'.'.strtolower($currency);

        $account = Account::create([
            'entity_id' => $entityId,
            'code' => $code,
            'name_ar' => 'النقد في البنك - '.$currency,
            'name_en' => 'Cash at bank - '.$currency,
            'parent_id' => $parent->getKey(),
            'path' => $path,
            'depth' => substr_count($path, '.'),
            /*
             | The enum itself, not self::text() of it.
             |
             | `type` is cast, so getAttribute returns an AccountType and
             | self::text() of an enum is the empty string - which Eloquent
             | then refuses as a backing value, several frames away.
             */
            'type' => $parent->getAttribute('type'),
            'statement_line' => $parent->getAttribute('statement_line'),
            'postable' => true,
            'monetary' => true,
        ]);

        return self::text($account->getKey());
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
            $tax = self::taxOn($net.'.0000');

            $bill = app(Bills::class)->enter(
                entity: $this->entity(),
                partyId: self::text($supplier->getKey()),
                supplierDocumentNumber: \sprintf('SUP-%s-%03d', $this->today->format('y'), $i + 1),
                supplierDocumentDate: Carbon::parse($day->toDateString()),
                taxPoint: Carbon::parse($day->toDateString()),
                postingDate: Carbon::parse($day->toDateString()),
                currency: self::CURRENCY,
                lines: [[
                    'description' => $what,
                    'quantity' => '1',
                    'unit_price' => (string) $net,
                    'net_amount' => $net.'.0000',
                    'expense_account_id' => $this->accounts[$accountCode] ?? $this->account('5.2'),
                    'tax_code_id' => $this->taxCodeId,
                    'tax_category' => 'S',
                    'tax_rate' => self::VAT_RATE,
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
        $amount = Money::of($payable, self::CURRENCY);

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
            currency: self::CURRENCY,
            lines: [[
                'description' => 'Cloud hosting and licences',
                'quantity' => '1',
                'unit_price' => '2860',
                'net_amount' => '2860.0000',
                'expense_account_id' => $this->account('5.2'),
                'tax_code_id' => $this->taxCodeId,
                'tax_category' => 'S',
                'tax_rate' => self::VAT_RATE,
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

    /**
     * The tax on a net amount, at the rate the fixture declares.
     *
     * Both callers used to compute `net * 15 / 100` with the 15 written
     * out, while putting `self::VAT_RATE` in the document's `tax_rate`
     * column. So every line declared 16% and carried a 15% figure, and a
     * tax breakdown that disagrees with its own rate is the kind of
     * fixture an auditor would stop reading at.
     *
     * One function, so the rate cannot be declared in one place and
     * computed in another.
     */
    private static function taxOn(string $net): string
    {
        return (string) BigDecimal::of($net)
            ->multipliedBy(self::VAT_RATE)
            ->dividedBy(100, 4, \Brick\Math\RoundingMode::HalfUp);
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
