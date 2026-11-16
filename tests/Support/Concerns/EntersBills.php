<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use App\Tenant\Ledger\Models\Account;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Models\PartyTaxRegistration;
use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Purchasing\Services\Bills;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The fixture a purchasing check needs - PF-133.
 *
 * Builds on {@see IssuesInvoices::seedTheSellingEntity()} rather than
 * repeating it: the entity, its registration, its calendar and its open
 * period are the same ones, and a second seeder would drift from the first
 * and then the two suites would be testing different entities.
 *
 * What it adds is the payable side: a supplier, and an expense account to
 * post a purchase to.
 */
trait EntersBills
{
    use IssuesInvoices;

    protected ?Party $supplier = null;

    protected string $expenseAccountId = '';

    protected function seedThePayableSide(): void
    {
        $this->seedTheSellingEntity();

        $this->supplier = Party::create([
            'code' => 'SUP-'.Str::upper(Str::random(6)),
            'legal_name' => 'Gulf Components Trading Co.',
            'is_supplier' => true,
            'country' => 'SA',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        PartyTaxRegistration::create([
            'party_id' => $this->supplier->getKey(),
            'jurisdiction' => 'SA',
            'scheme' => 'VAT',
            'identifier' => '399999999999993',
            'eas_code' => '0088',
            'is_primary' => true,
            'validity' => PartyTaxRegistration::range(CarbonImmutable::parse('2019-01-01')),
        ]);

        /*
         | An expense account, not a revenue one.
         |
         | The sales fixture's account is revenue, and posting a purchase to
         | it would balance and be nonsense - which a check that only
         | asserted the entry balances would not notice.
         */
        $this->expenseAccountId = (string) Account::query()
            ->where('entity_id', $this->entityId)
            ->where('type', 'expense')
            ->where('postable', true)
            ->orderBy('code')
            ->value('id');
    }

    /**
     * Enter a bill through the real service.
     *
     * @param  list<array<string, mixed>>|null  $lines
     */
    protected function enterBill(
        ?array $lines = null,
        ?string $number = null,
        ?Carbon $documentDate = null,
        ?Carbon $taxPoint = null,
        ?Carbon $postingDate = null,
        string $currency = 'SAR',
        ?string $partyId = null,
        ?string $paymentTermId = null,
    ): Bill {
        $lines ??= [[
            'description' => 'Machined brackets, batch 41',
            'quantity' => '100',
            'unit_price' => '10',
            'net_amount' => '1000.0000',
            'expense_account_id' => $this->expenseAccountId,
            'tax_code_id' => $this->taxCodeId,
            'tax_category' => 'S',
            'tax_rate' => '15.000000',
            'tax_amount' => '150.0000',
        ]];

        $documentDate ??= $this->date->copy();

        return app(Bills::class)->enter(
            entity: $this->entity(),
            partyId: $partyId ?? (string) $this->supplier?->getKey(),
            supplierDocumentNumber: $number ?? 'GC-'.Str::upper(Str::random(6)),
            supplierDocumentDate: $documentDate,
            taxPoint: $taxPoint ?? $documentDate->copy(),
            postingDate: $postingDate ?? $this->date->copy(),
            currency: $currency,
            lines: $lines,
            createdBy: $this->actorId(),
            paymentTermId: $paymentTermId ?? ($this->terms === null ? null : (string) $this->terms->getKey()),
        );
    }

    /** A reverse-charge line: the supplier charges nothing and we account for both sides. */
    protected function reverseChargeLine(string $net = '1000.0000', string $tax = '150.0000'): array
    {
        return [
            'description' => 'Consultancy from a non-resident supplier',
            'quantity' => '1',
            'unit_price' => $net,
            'net_amount' => $net,
            'expense_account_id' => $this->expenseAccountId,
            'tax_code_id' => $this->taxCodeId,
            'tax_category' => 'AE',
            'tax_rate' => '15.000000',
            'tax_amount' => $tax,
            'reverse_charge' => true,
        ];
    }

    /** A line whose input tax is only partly recoverable. */
    protected function partlyRecoverableLine(string $percent = '50'): array
    {
        return [
            'description' => 'Client entertainment',
            'quantity' => '1',
            'unit_price' => '1000.0000',
            'net_amount' => '1000.0000',
            'expense_account_id' => $this->expenseAccountId,
            'tax_code_id' => $this->taxCodeId,
            'tax_category' => 'S',
            'tax_rate' => '15.000000',
            'tax_amount' => '150.0000',
            'recoverable_percent' => $percent,
        ];
    }
}
