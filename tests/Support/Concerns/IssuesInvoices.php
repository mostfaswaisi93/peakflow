<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use App\Tenant\Catalog\Models\PaymentTerm;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Models\PartyTaxRegistration;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Models\InvoiceTaxBreakdownRow;
use App\Tenant\Sales\Services\Issuer;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Services\ReturnPeriods;
use App\Tenant\Tax\Services\TaxCodes;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Models\TaxRegistration;
use App\Tenant\Tenancy\Services\EntityContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One sellable entity, one customer, one standard-rate draft.
 *
 * ## Why this is shared and not copied
 *
 * The validation suite and the issuing suite need the same thing: an entity
 * registered for VAT, an open accounting period, an open return period, a
 * customer with a registration, a tax code and a postable revenue account.
 * Copied, the two fixtures drift - and the day they drift is the day one
 * suite's green stops meaning anything about the other's.
 *
 * Every rule runs inside its own savepoint, so a check that issues three
 * documents does not leave them where the next check can see them.
 */
trait IssuesInvoices
{
    protected ?Connection $connection = null;

    protected string $entityId = '';

    protected ?Entity $fixtureEntity = null;

    protected ?Party $party = null;

    protected string $taxCodeId = '';

    protected string $revenueAccountId = '';

    protected ?Period $period = null;

    protected ?PaymentTerm $terms = null;

    protected Carbon $date;

    /** @var array<string, mixed> */
    protected array $decision = [
        'country' => 'SA',
        'rule' => 'domestic',
        'category' => 'S',
        'rate' => '15.000000',
    ];

    /** The id an audit record is attributed to when there is no session. */
    protected function actorId(): string
    {
        return str_repeat('0', 26);
    }

    protected function entity(): Entity
    {
        \assert($this->fixtureEntity !== null);

        return $this->fixtureEntity;
    }

    /**
     * A complete, valid, standard-rate draft: 1,000.00 net, 150.00 tax.
     *
     * Deliberately the shape that passes. Every refusal in both suites is
     * built by taking something away from this, so a check that passes
     * because its fixture was broken in two places at once cannot hide.
     */
    protected function draft(DocumentType $type = DocumentType::Invoice, bool $readBack = true): Invoice
    {
        $net = '1000.0000';
        $tax = '150.0000';
        $gross = (string) BigDecimal::of($net)->plus($tax)->toScale(4);

        $invoice = Invoice::create([
            'entity_id' => $this->entityId,
            'series_key' => $type->seriesKey(),
            'document_type' => $type->value,
            'status' => 'draft',
            'party_id' => (string) $this->party?->getKey(),
            'issue_date' => $this->date->toDateString(),
            'tax_point' => $this->date->toDateString(),
            'currency' => 'SAR',
            'net_amount' => $net,
            'tax_amount' => $tax,
            'gross_amount' => $gross,
            'payable_amount' => $gross,
            'presentation_exponent' => 2,
        ]);

        InvoiceLine::create([
            'entity_id' => $this->entityId,
            'invoice_id' => (string) $invoice->getKey(),
            'line_no' => 1,
            'description' => 'Advisory services, May 2026',
            'unit_code' => 'C62',
            'quantity' => '1.00000000',
            'unit_price' => $net,
            'net_amount' => $net,
            'revenue_account_id' => $this->revenueAccountId,
            'tax_code_id' => $this->taxCodeId,
            'tax_category' => 'S',
            'tax_rate' => '15.000000',
            'tax_point' => $this->date->toDateString(),
            'place_of_supply' => 'SA',
            'place_of_supply_rule' => 'domestic',
        ]);

        InvoiceTaxBreakdownRow::create([
            'entity_id' => $this->entityId,
            'invoice_id' => (string) $invoice->getKey(),
            'category' => 'S',
            'rate' => '15.000000',
            'taxable_amount' => $net,
            'tax_amount' => $tax,
            'report_box' => 'standard_sales',
        ]);

        /*
         | `$readBack` false hands back the model as `create()` left it.
         |
         | Which is a model that does not know the column defaults:
         | `prepaid_amount` is `0.0000` in the database and absent in memory.
         | That is the shape a controller passes to the issuer, and it is how
         | the first three genuine documents came to be reported as diverging
         | at `totals.prepaid` - "it was (absent) and is now 0.0000". The
         | issuer re-reads the row for exactly this reason, and one check
         | needs the unread model to prove it.
         */
        return $readBack ? $invoice->refresh() : $invoice;
    }

    protected function issue(DocumentType $type = DocumentType::Invoice): Invoice
    {
        return app(Issuer::class)->issue(
            $this->draft($type),
            $this->entity(),
            $this->actorId(),
            $this->decision,
            $this->terms,
        );
    }

    protected function savepoint(): string
    {
        $name = 'inv_'.bin2hex(random_bytes(4));
        $this->connection?->statement('SAVEPOINT '.$name);

        return $name;
    }

    protected function rollbackTo(string $savepoint): void
    {
        $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
    }

    /**
     * The entity, the customer, the code, the account and both calendars.
     *
     * The accounting period is opened explicitly: a generated calendar's
     * periods start `future`, and `future` does not accept postings - which
     * is correct, and would otherwise make every check in both suites fail
     * for the same uninteresting reason.
     */
    protected function seedTheSellingEntity(): void
    {
        $this->date = Carbon::parse('2026-05-18');

        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'INV'.random_int(100, 999),
            'legal_name' => 'Peakflow Trading Co.',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $this->fixtureEntity = $entity;
        $context->use($entity);

        TaxRegistration::create([
            'entity_id' => $this->entityId,
            'jurisdiction' => 'SA',
            'scheme' => 'VAT',
            'identifier' => '310000000000003',
            'effective_from' => '2020-01-01',
            'is_primary' => true,
            'filing_frequency' => 'monthly',
        ]);

        app(ReturnPeriods::class)->generateYear($entity, 'SA', 2026);

        $period = app(FiscalCalendarGenerator::class)->periodFor($this->entityId, $this->date);
        app(PeriodStateMachine::class)->transition($period, PeriodState::Open, 'opened for an invoicing check');
        $this->period = $period->refresh();

        $party = Party::create([
            'code' => 'INV-C-'.random_int(100, 999),
            'legal_name' => 'Acme Industrial LLC',
            'is_customer' => true,
            'country' => 'SA',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        PartyTaxRegistration::create([
            'party_id' => $party->getKey(),
            'jurisdiction' => 'SA',
            'scheme' => 'VAT',
            'identifier' => '300000000000003',
            'is_primary' => true,
            'validity' => PartyTaxRegistration::range(CarbonImmutable::parse('2019-01-01')),
        ]);

        $this->party = $party;

        $this->taxCodeId = (string) app(TaxCodes::class)->introduce(
            entityId: $this->entityId,
            code: 'SA-S-15',
            category: TaxCategory::Standard,
            jurisdiction: 'SA',
            rate: '15.000000',
            from: CarbonImmutable::parse('2020-07-01'),
            labelAr: 'النسبة الأساسية',
            labelEn: 'Standard rate',
            source: 'a fixture',
            reportingBoxes: ['sa' => ['output' => 'standard_sales']],
        )->getKey();

        $this->revenueAccountId = (string) Account::query()
            ->where('entity_id', $this->entityId)
            ->where('type', 'revenue')
            ->where('postable', true)
            ->orderBy('code')
            ->value('id');

        $this->terms = PaymentTerm::query()->where('active', true)->first();
    }

    /**
     * @param  array<string, callable(): list<string>>  $rules
     * @return array<string, list<string>>
     */
    protected function runEachInASavepoint(array $rules): array
    {
        $results = [];

        foreach ($rules as $name => $rule) {
            $savepoint = $this->savepoint();

            try {
                // The fixture models are re-read, because the savepoint that
                // rolled back the previous rule did not roll back PHP.
                $this->party = $this->party?->fresh();
                $this->period = $this->period?->fresh();
                $this->fixtureEntity = $this->fixtureEntity?->fresh();

                $results[$name] = $rule();
            } catch (Throwable $e) {
                $results[$name] = ['the check could not run: '.$e->getMessage()];
            } finally {
                $this->rollbackTo($savepoint);
            }
        }

        return $results;
    }

    /** The number the next document in the invoice series would take. */
    protected function nextNumber(string $series = 'INV'): ?string
    {
        $value = DB::connection('tenant')->table('number_series')
            ->where('entity_id', $this->entityId)
            ->where('scope', 'sales_document')
            ->where('key', $series)
            ->value('next_number');

        return $value === null ? null : (string) $value;
    }

    protected function journalEntries(): int
    {
        return DB::connection('tenant')->table('journal_entries')
            ->where('entity_id', $this->entityId)
            ->count();
    }
}
