<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Catalog\Models\Item;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Models\PartyAddress;
use App\Tenant\Parties\Models\PartyTaxRegistration;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceAllowanceCharge;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Models\InvoiceReference;
use App\Tenant\Sales\Models\InvoiceTaxBreakdownRow;
use App\Tenant\Sales\Services\Snapshots;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Models\TaxCode;
use App\Tenant\Tax\Models\TaxRate;
use App\Tenant\Tax\Services\TaxCodes;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Models\TaxRegistration;
use App\Tenant\Tenancy\Services\EntityContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The invoice schema, and what an issued document freezes - PF-091.
 *
 * ## The claim this suite exists to prove
 *
 * That an issued invoice is a historical statement rather than a view. The
 * headline check changes the customer's legal name and the tax rate AFTER
 * issue and asserts the document is unchanged in every respect - which is the
 * criterion written in the task file, because it is the failure that is
 * invisible until it matters.
 *
 * Reprinting an invoice is not an unusual act: it is what happens when a
 * customer disputes one, when an authority asks for one, and when a collection
 * agency needs one. A system that joins to `parties` at render time silently
 * rewrites every invoice a customer has ever received the day they rename
 * their company - including the ones already filed under the old name.
 *
 * ## Why the checks write rows directly
 *
 * There is no issuing transaction yet; PF-095 builds it. What is under test
 * here is the SCHEMA - the constraints, the triggers and the snapshot - and a
 * check that went through a service would be testing the service's care
 * rather than the database's refusal. A correction script and a psql session
 * write rows the same way these do.
 */
final class InvoiceSchemaRules
{
    private ?Connection $connection = null;

    private string $entityId = '';

    private string $otherEntityId = '';

    private ?Entity $entity = null;

    private ?Party $party = null;

    private ?TaxCode $code = null;

    private ?Item $item = null;

    private CarbonImmutable $date;

    public function __construct()
    {
        $this->date = CarbonImmutable::parse('2026-05-18');
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['the invoice schema (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return $this->runEach([
                'a party renamed and a rate changed after issue leave the document untouched' => fn (): array => $this->theSnapshotHolds(),
                'a draft has no number and an issued document has one' => fn (): array => $this->theNumberPairsWithIssue(),
                'an issued document carries every snapshot it needs' => fn (): array => $this->snapshotsAreRequired(),
                'the totals tie to each other in the database' => fn (): array => $this->theTotalsTie(),
                'an issued invoice cannot be changed or deleted' => fn (): array => $this->issuedIsImmutable(),
                'an issued invoice\'s lines and breakdown cannot be touched' => fn (): array => $this->thePartsAreImmutable(),
                'a line carries the item reference and its own description' => fn (): array => $this->theLineKeepsItsDescription(),
                'a zero category cannot carry a rate' => fn (): array => $this->zeroCategoriesCarryZero(),
                'the breakdown demands a reason where the category does' => fn (): array => $this->theBreakdownNeedsAReason(),
                'a legal reference points at a document; a commercial one may be text' => fn (): array => $this->theReferencesAreTyped(),
                'the presentation exponent is recorded on the document' => fn (): array => $this->theExponentIsRecorded(),
                'another entity cannot see these invoices' => fn (): array => $this->rowLevelSecurityApplies(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * The criterion from the task file, as a check.
     *
     * @return list<string>
     */
    public function theSnapshotHolds(): array
    {
        $violations = [];
        $invoice = $this->issueAnInvoice();

        $before = [
            'supplier' => $invoice->supplier_snapshot,
            'customer' => $invoice->customer_snapshot,
            'terms' => $invoice->terms_snapshot,
            'net' => $invoice->net_amount,
            'tax' => $invoice->tax_amount,
            'gross' => $invoice->gross_amount,
        ];

        $lines = $invoice->lines()->get()
            ->map(static fn (InvoiceLine $line): array => [
                'description' => $line->description,
                'rate' => $line->tax_rate,
                'net' => $line->net_amount,
            ])->all();

        $breakdown = $invoice->taxBreakdown()->get()
            ->map(static fn (InvoiceTaxBreakdownRow $row): array => [
                'rate' => $row->rate,
                'tax' => $row->tax_amount,
            ])->all();

        /*
         | Now the world moves.
         |
         | The customer renames itself, its registration is replaced, and the
         | standard rate goes from 15% to 20%. All three are ordinary events
         | and all three would rewrite an invoice that joined instead of
         | freezing.
         */
        \assert($this->party !== null && $this->code !== null);

        $this->party->update(['legal_name' => 'Acme Industrial Holding PJSC']);

        app(TaxCodes::class)->supersede(
            (string) $this->code->code,
            '20.000000',
            CarbonImmutable::parse('2026-07-01'),
            'KSA rate change - a fixture, not a real announcement',
        );

        $this->party->update(['legal_name' => 'Acme Industrial Holding PJSC']);

        $after = $invoice->fresh();

        if ($after === null) {
            return ['the invoice disappeared'];
        }

        foreach ([
            'the supplier snapshot' => [$after->supplier_snapshot, $before['supplier']],
            'the customer snapshot' => [$after->customer_snapshot, $before['customer']],
            'the terms snapshot' => [$after->terms_snapshot, $before['terms']],
        ] as $what => [$now, $then]) {
            if ($now !== $then) {
                $violations[] = sprintf(
                    '%s changed after the party was renamed. An issued invoice is a historical '.
                    'statement: the customer holds a copy with the old name, and a return may already '.
                    'include it under that name',
                    $what,
                );
            }
        }

        if ($after->customerName() !== 'Acme Industrial LLC') {
            $violations[] = sprintf(
                'the document now names the customer "%s" and should still name "Acme Industrial LLC"',
                (string) $after->customerName(),
            );
        }

        /*
         | The registration the customer held ON THE TAX POINT.
         |
         | The party holds two - it was renumbered on 1 June 2026 - and the
         | document's tax point is 18 May. The second one is the primary and
         | the current one, which is exactly why this assertion is on the
         | identifier rather than on "a registration is present".
         */
        if ($after->customerRegistration() !== '300000000000003') {
            $violations[] = sprintf(
                'the frozen registration is "%s" and should be 300000000000003 - the one the customer '.
                'held on the tax point, not the one it holds today. The party was renumbered on 1 June '.
                'and this document is dated 18 May',
                (string) $after->customerRegistration(),
            );
        }

        foreach (['net', 'tax', 'gross'] as $total) {
            if ($after->{$total.'_amount'} !== $before[$total]) {
                $violations[] = sprintf('the %s total moved after the rate change', $total);
            }
        }

        $linesNow = $after->lines()->get()
            ->map(static fn (InvoiceLine $line): array => [
                'description' => $line->description,
                'rate' => $line->tax_rate,
                'net' => $line->net_amount,
            ])->all();

        if ($linesNow !== $lines) {
            $violations[] = 'a line changed after the rate change. Reprinting a 2019 invoice after a VAT '.
                'increase must not show the new rate against the old total';
        }

        $breakdownNow = $after->taxBreakdown()->get()
            ->map(static fn (InvoiceTaxBreakdownRow $row): array => [
                'rate' => $row->rate,
                'tax' => $row->tax_amount,
            ])->all();

        if ($breakdownNow !== $breakdown) {
            $violations[] = 'the frozen tax breakdown changed after the rate change';
        }

        /*
         | And the live world really did move - otherwise this check would
         | pass on a fixture where nothing happened.
         */
        if ($this->party->fresh()?->legal_name !== 'Acme Industrial Holding PJSC') {
            $violations[] = 'the party was not actually renamed, so this check proved nothing';
        }

        $rate = app(TaxCodes::class)->resolve(
            (string) $this->code->code, CarbonImmutable::parse('2026-08-01'),
        )[1];

        if ((string) $rate->percentage()->strippedOfTrailingZeros() !== '20') {
            $violations[] = 'the rate change did not take effect, so this check proved nothing';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theNumberPairsWithIssue(): array
    {
        $violations = [];

        $draft = $this->draft();

        if ($draft->number !== null) {
            $violations[] = 'a draft was created with a number. It has consumed one from a gapless '.
                'series and may never be issued';
        }

        foreach ([
            'a draft with a number' => ['number' => 'INV-2026/00001'],
            'an issued document with no number' => ['status' => 'issued', 'issued_at' => now()],
        ] as $what => $changes) {
            $savepoint = $this->savepoint();

            try {
                Invoice::query()->whereKey($draft->getKey())->update($changes);

                $violations[] = sprintf(
                    '%s was stored. A draft with a number has consumed one and may never be issued; an '.
                    'issued document without one is a legal document with no identity',
                    $what,
                );
            } catch (Throwable) {
                // Correct.
            } finally {
                $this->rollbackTo($savepoint);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function snapshotsAreRequired(): array
    {
        $violations = [];
        $draft = $this->draft();

        foreach ([
            'no customer snapshot' => ['customer_snapshot' => null],
            'no supplier snapshot' => ['supplier_snapshot' => null],
            'no tax decision' => ['tax_decision_snapshot' => null],
            'no tax point' => ['tax_point' => null],
        ] as $what => $missing) {
            $savepoint = $this->savepoint();

            try {
                Invoice::query()->whereKey($draft->getKey())->update(array_merge([
                    'status' => 'issued',
                    'number' => 'INV-2026/09999',
                    'issued_at' => now(),
                    'supplier_snapshot' => ['legal_name' => 'x'],
                    'customer_snapshot' => ['legal_name' => 'y'],
                    'tax_decision_snapshot' => ['country' => 'SA'],
                    'tax_point' => $this->date->toDateString(),
                ], $missing));

                $violations[] = sprintf(
                    'a document was issued with %s. Every one of these is something the document has to '.
                    'say on its own, after the thing it describes has changed',
                    $what,
                );
            } catch (Throwable) {
                // Correct.
            } finally {
                $this->rollbackTo($savepoint);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function theTotalsTie(): array
    {
        $violations = [];

        foreach ([
            'a gross that is not net plus tax' => ['net_amount' => '100.0000', 'tax_amount' => '15.0000', 'gross_amount' => '120.0000', 'payable_amount' => '120.0000'],
            'a payable that ignores the prepayment' => ['net_amount' => '100.0000', 'tax_amount' => '15.0000', 'gross_amount' => '115.0000', 'prepaid_amount' => '50.0000', 'payable_amount' => '115.0000'],
            'a negative prepayment' => ['prepaid_amount' => '-10.0000', 'payable_amount' => '10.0000', 'gross_amount' => '0.0000'],
        ] as $what => $totals) {
            $savepoint = $this->savepoint();

            try {
                $this->draft($totals);

                $violations[] = sprintf(
                    '%s was stored. A document whose own totals do not tie is one nobody can check '.
                    'against anything else', $what,
                );
            } catch (Throwable) {
                // Correct.
            } finally {
                $this->rollbackTo($savepoint);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function issuedIsImmutable(): array
    {
        $violations = [];
        $invoice = $this->issueAnInvoice();

        foreach ([
            'a total' => ['net_amount' => '1.0000'],
            'the note' => ['note' => 'added later'],
            'the customer snapshot' => ['customer_snapshot' => ['legal_name' => 'Somebody else']],
            'the number' => ['number' => 'INV-2026/00002'],
            'the status' => ['status' => 'draft'],
        ] as $what => $changes) {
            $savepoint = $this->savepoint();

            try {
                Invoice::query()->whereKey($invoice->getKey())->update($changes);

                $violations[] = sprintf(
                    'changing %s on an issued invoice succeeded. The customer holds a copy, a return '.
                    'may already include it, and the hash was taken over it',
                    $what,
                );
            } catch (Throwable) {
                // Correct.
            } finally {
                $this->rollbackTo($savepoint);
            }
        }

        $savepoint = $this->savepoint();

        try {
            Invoice::query()->whereKey($invoice->getKey())->delete();

            $violations[] = 'an issued invoice was deleted. It is credited or corrected by another '.
                'document that references it, which is what leaves a trail';
        } catch (Throwable) {
            // Correct.
        } finally {
            $this->rollbackTo($savepoint);
        }

        // And a draft is still freely editable, or the whole flow is unusable.
        $draft = $this->draft();

        try {
            Invoice::query()->whereKey($draft->getKey())->update(['note' => 'still editable']);
        } catch (Throwable $e) {
            $violations[] = 'a DRAFT could not be edited, which would make the editing flow in PF-092 '.
                'impossible: '.$e->getMessage();
        }

        return $violations;
    }

    /** @return list<string> */
    public function thePartsAreImmutable(): array
    {
        $violations = [];
        $invoice = $this->issueAnInvoice();
        $line = $invoice->lines()->first();

        if ($line === null) {
            return ['the fixture invoice has no lines'];
        }

        $attempts = [
            'editing a line' => fn (): mixed => InvoiceLine::query()
                ->whereKey($line->getKey())->update(['net_amount' => '1.0000']),
            'deleting a line' => fn (): mixed => InvoiceLine::query()->whereKey($line->getKey())->delete(),
            'adding a line' => fn (): mixed => InvoiceLine::create([
                'entity_id' => $this->entityId,
                'invoice_id' => $invoice->getKey(),
                'line_no' => 99,
                'description' => 'Added after issue',
                'quantity' => '1.00000000',
                'unit_price' => '1.00000000',
                'net_amount' => '1.0000',
                'tax_category' => 'S',
                'tax_rate' => '15.000000',
            ]),
            'editing the breakdown' => fn (): mixed => InvoiceTaxBreakdownRow::query()
                ->where('invoice_id', $invoice->getKey())->update(['tax_amount' => '0.0000']),
            'adding a reference' => fn (): mixed => InvoiceReference::create([
                'entity_id' => $this->entityId,
                'invoice_id' => $invoice->getKey(),
                'kind' => 'order',
                'reference' => 'PO-added-later',
            ]),
        ];

        foreach ($attempts as $what => $attempt) {
            $savepoint = $this->savepoint();

            try {
                $attempt();

                $violations[] = sprintf(
                    '%s on an issued invoice succeeded. A line edited after issue changes the document '.
                    'as surely as editing its header does, and it is the easier mistake to make because '.
                    'a line looks like data',
                    $what,
                );
            } catch (Throwable) {
                // Correct.
            } finally {
                $this->rollbackTo($savepoint);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function theLineKeepsItsDescription(): array
    {
        $violations = [];
        $invoice = $this->issueAnInvoice();
        $line = $invoice->lines()->first();

        \assert($this->item !== null);

        $this->item->update(['name_en' => 'Renamed consultancy product', 'name_ar' => 'منتج بعد التسمية']);

        $after = $line?->fresh();

        if ($after === null) {
            return ['the line disappeared'];
        }

        if ($after->description !== 'Advisory services, May 2026') {
            $violations[] = sprintf(
                'the line now reads "%s". Changing an item\'s name must not rewrite history - the '.
                'description is what the customer read', $after->description,
            );
        }

        if ($after->item_id === null || $after->item_code === null) {
            $violations[] = 'the line lost its item reference, so no report can group by the item';
        }

        if ($after->unit_code === '' ) {
            $violations[] = 'the line has no unit code, which an e-invoice needs';
        }

        return $violations;
    }

    /** @return list<string> */
    public function zeroCategoriesCarryZero(): array
    {
        $violations = [];

        foreach ([['Z', '5.000000'], ['E', '15.000000'], ['AE', '1.000000'], ['O', '15.000000']] as [$category, $rate]) {
            $savepoint = $this->savepoint();

            try {
                $this->lineOn($this->draft(), ['tax_category' => $category, 'tax_rate' => $rate]);

                $violations[] = sprintf(
                    'a %s line at %s%% was stored. A Z line at 5%% is not a zero rate and an E line at '.
                    '15%% is a taxable supply wearing an exempt label - both produce a return whose '.
                    'turnover boxes are wrong',
                    $category, $rate,
                );
            } catch (Throwable) {
                // Correct.
            } finally {
                $this->rollbackTo($savepoint);
            }
        }

        // And a standard line at a real rate is fine.
        try {
            $this->lineOn($this->draft(), ['tax_category' => 'S', 'tax_rate' => '15.000000']);
        } catch (Throwable $e) {
            $violations[] = 'an ordinary standard-rated line was refused: '.$e->getMessage();
        }

        return $violations;
    }

    /** @return list<string> */
    public function theBreakdownNeedsAReason(): array
    {
        $violations = [];
        $draft = $this->draft();

        $savepoint = $this->savepoint();

        try {
            InvoiceTaxBreakdownRow::create([
                'entity_id' => $this->entityId,
                'invoice_id' => $draft->getKey(),
                'category' => 'Z',
                'rate' => '0.000000',
                'taxable_amount' => '100.0000',
                'tax_amount' => '0.0000',
            ]);

            $violations[] = 'a zero-rated breakdown row was stored with no exemption reason. An invoice '.
                'that says 0% with no reason is an invoice that cannot be defended - EN 16931 BT-121';
        } catch (Throwable) {
            // Correct.
        } finally {
            $this->rollbackTo($savepoint);
        }

        try {
            InvoiceTaxBreakdownRow::create([
                'entity_id' => $this->entityId,
                'invoice_id' => $draft->getKey(),
                'category' => 'S',
                'rate' => '15.000000',
                'taxable_amount' => '100.0000',
                'tax_amount' => '15.0000',
            ]);
        } catch (Throwable $e) {
            $violations[] = 'a standard-rated breakdown row was refused for want of a reason it does '.
                'not need: '.$e->getMessage();
        }

        return $violations;
    }

    /** @return list<string> */
    public function theReferencesAreTyped(): array
    {
        $violations = [];
        $draft = $this->draft();

        $savepoint = $this->savepoint();

        try {
            InvoiceReference::create([
                'entity_id' => $this->entityId,
                'invoice_id' => $draft->getKey(),
                'kind' => 'credits',
                'reference' => 'the invoice from March',
            ]);

            $violations[] = 'a credit reference was stored as free text. A credit note whose reference '.
                'is "the invoice from March" is a credit note nobody can match - EN 16931 BT-25';
        } catch (Throwable) {
            // Correct.
        } finally {
            $this->rollbackTo($savepoint);
        }

        try {
            InvoiceReference::create([
                'entity_id' => $this->entityId,
                'invoice_id' => $draft->getKey(),
                'kind' => 'order',
                'reference' => 'PO-2026-0041',
            ]);
        } catch (Throwable $e) {
            $violations[] = 'a commercial reference as free text was refused, which is the ordinary '.
                'case: '.$e->getMessage();
        }

        $savepoint = $this->savepoint();

        try {
            InvoiceReference::create([
                'entity_id' => $this->entityId,
                'invoice_id' => $draft->getKey(),
                'kind' => 'order',
            ]);

            $violations[] = 'a reference with neither a document nor any text was stored, which says '.
                'nothing at all';
        } catch (Throwable) {
            // Correct.
        } finally {
            $this->rollbackTo($savepoint);
        }

        return $violations;
    }

    /** @return list<string> */
    public function theExponentIsRecorded(): array
    {
        $violations = [];
        $invoice = $this->issueAnInvoice();

        if ($invoice->presentation_exponent !== 2) {
            $violations[] = sprintf(
                'a SAR invoice records an exponent of %d and should record 2',
                $invoice->presentation_exponent,
            );
        }

        /*
         | Recorded rather than derived at render time, because ISO 4217 can
         | change one - it has - and a reprint has to show what was shown.
         */
        $savepoint = $this->savepoint();

        try {
            $this->draft(['presentation_exponent' => 7]);

            $violations[] = 'an exponent of 7 was stored. No ISO 4217 currency has one, so it is a '.
                'number somebody typed';
        } catch (Throwable) {
            // Correct.
        } finally {
            $this->rollbackTo($savepoint);
        }

        return $violations;
    }

    /** @return list<string> */
    public function rowLevelSecurityApplies(): array
    {
        $violations = [];
        $this->issueAnInvoice();

        $context = app(EntityContext::class);
        $context->use(Entity::query()->findOrFail($this->otherEntityId));

        try {
            $leaked = Invoice::withoutEntityScope()->where('entity_id', $this->entityId)->count();

            if ($leaked !== 0) {
                $violations[] = sprintf(
                    'another entity read %d of this entity\'s invoices with the global scope removed', $leaked,
                );
            }

            $lines = InvoiceLine::withoutEntityScope()->where('entity_id', $this->entityId)->count();

            if ($lines !== 0) {
                $violations[] = sprintf('another entity read %d invoice lines', $lines);
            }
        } finally {
            $context->use(Entity::query()->findOrFail($this->entityId));
        }

        return $violations;
    }

    /** @param  array<string, mixed>  $overrides */
    private function draft(array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'entity_id' => $this->entityId,
            'series_key' => DocumentType::Invoice->seriesKey(),
            'document_type' => DocumentType::Invoice->value,
            'status' => 'draft',
            'party_id' => (string) $this->party?->getKey(),
            'issue_date' => $this->date->toDateString(),
            'currency' => 'SAR',
            'net_amount' => '1000.0000',
            'tax_amount' => '150.0000',
            'gross_amount' => '1150.0000',
            'payable_amount' => '1150.0000',
            'presentation_exponent' => 2,
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    private function lineOn(Invoice $invoice, array $overrides = []): InvoiceLine
    {
        return InvoiceLine::create(array_merge([
            'entity_id' => $this->entityId,
            'invoice_id' => (string) $invoice->getKey(),
            'line_no' => 1,
            'item_id' => (string) $this->item?->getKey(),
            'item_code' => (string) $this->item?->code,
            'description' => 'Advisory services, May 2026',
            'unit_code' => 'C62',
            'quantity' => '1.00000000',
            'unit_price' => '1000.00000000',
            'net_amount' => '1000.0000',
            'tax_code_id' => (string) $this->code?->getKey(),
            'tax_category' => 'S',
            'tax_rate' => '15.000000',
            'tax_point' => $this->date->toDateString(),
            'place_of_supply' => 'SA',
            'place_of_supply_rule' => 'domestic',
        ], $overrides));
    }

    /**
     * A draft with a line and a breakdown, then flipped to issued.
     *
     * Written directly rather than through a service: PF-095 builds the
     * issuing transaction, and what is under test here is the schema.
     */
    private function issueAnInvoice(): Invoice
    {
        $invoice = $this->draft();
        $this->lineOn($invoice);

        InvoiceTaxBreakdownRow::create([
            'entity_id' => $this->entityId,
            'invoice_id' => (string) $invoice->getKey(),
            'category' => 'S',
            'rate' => '15.000000',
            'taxable_amount' => '1000.0000',
            'tax_amount' => '150.0000',
            'report_box' => 'standard_sales',
        ]);

        InvoiceAllowanceCharge::create([
            'entity_id' => $this->entityId,
            'invoice_id' => (string) $invoice->getKey(),
            'is_charge' => true,
            'amount' => '0.0001',
            'reason' => 'A rounding charge, so the fixture carries one of these too',
        ]);

        \assert($this->entity !== null && $this->party !== null);

        $snapshots = app(Snapshots::class);

        $invoice->update([
            'status' => 'issued',
            'number' => 'INV-2026/'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'tax_point' => $this->date->toDateString(),
            'return_period_label' => '2026-05',
            'issued_at' => now(),
            'supplier_snapshot' => $snapshots->supplier($this->entity, 'SA', $this->date),
            'customer_snapshot' => $snapshots->customer($this->party, 'SA', $this->date),
            'tax_decision_snapshot' => [
                'country' => 'SA',
                'rule' => 'domestic',
                'category' => 'S',
                'rate' => '15.000000',
            ],
            'terms_snapshot' => ['code' => 'NET30', 'due_dates' => [
                ['sequence' => 1, 'date' => '2026-06-17', 'proportion' => '1', 'amount' => null, 'derivation' => 'fixture'],
            ]],
        ]);

        return $invoice->refresh();
    }

    private function savepoint(): string
    {
        $name = 'inv_'.bin2hex(random_bytes(4));
        $this->connection?->statement('SAVEPOINT '.$name);

        return $name;
    }

    private function rollbackTo(string $savepoint): void
    {
        $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'INV'.random_int(100, 999),
            'legal_name' => 'Peakflow Trading Co.',
            'trading_name' => 'Peakflow',
            'registration_number' => 'CR-1010101010',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
            'address' => ['line_1' => 'King Fahd Road', 'city' => 'Riyadh', 'country' => 'SA'],
        ]);

        $other = Entity::create([
            'code' => 'INO'.random_int(100, 999),
            'legal_name' => 'Peakflow, other entity',
            'country' => 'JO',
            'functional_currency' => 'JOD',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $this->otherEntityId = (string) $other->getKey();
        $this->entity = $entity;
        $context->use($entity);

        TaxRegistration::create([
            'entity_id' => $this->entityId,
            'jurisdiction' => 'SA',
            'scheme' => 'VAT',
            'identifier' => '310000000000003',
            'effective_from' => '2020-01-01',
            'is_primary' => true,
        ]);

        $party = Party::create([
            'code' => 'INV-C-'.random_int(100, 999),
            'legal_name' => 'Acme Industrial LLC',
            'is_customer' => true,
            'country' => 'SA',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        PartyAddress::create([
            'party_id' => $party->getKey(),
            'type' => 'billing',
            'line_1' => 'Olaya Street',
            'city' => 'Riyadh',
            'postal_code' => '11564',
            'country' => 'SA',
            'is_default' => true,
        ]);

        /*
         | TWO registrations, because the party has been renumbered.
         |
         | One valid until 1 June 2026 and one from it. The document's tax
         | point is 18 May, so the snapshot has to carry the FIRST - and a
         | snapshot that resolved "the registration" as at today, or as at the
         | primary one, would carry the second.
         |
         | Without both in the fixture the check could not tell the two apart:
         | the first version of this suite had one registration, and the bite
         | that froze today's registration instead of the tax point's passed
         | silently.
         */
        PartyTaxRegistration::create([
            'party_id' => $party->getKey(),
            'jurisdiction' => 'SA',
            'scheme' => 'VAT',
            'identifier' => '300000000000003',
            'is_primary' => false,
            'validity' => PartyTaxRegistration::range(
                CarbonImmutable::parse('2019-01-01'), CarbonImmutable::parse('2026-06-01'),
            ),
        ]);

        PartyTaxRegistration::create([
            'party_id' => $party->getKey(),
            'jurisdiction' => 'SA',
            'scheme' => 'VAT',
            'identifier' => '399999999999993',
            'is_primary' => true,
            'validity' => PartyTaxRegistration::range(CarbonImmutable::parse('2026-06-01')),
        ]);

        $this->party = $party;

        $this->code = app(TaxCodes::class)->introduce(
            entityId: $this->entityId,
            code: 'SA-S-15',
            category: TaxCategory::Standard,
            jurisdiction: 'SA',
            rate: '15.000000',
            from: CarbonImmutable::parse('2020-07-01'),
            labelAr: 'النسبة الأساسية',
            labelEn: 'Standard rate',
            source: 'KSA VAT Implementing Regulations Art. 1 - a fixture',
            reportingBoxes: ['sa' => ['output' => 'standard_sales', 'input' => 'standard_purchases']],
        );

        $this->item = Item::query()->firstOrCreate(
            ['code' => 'INV-ITEM-'.random_int(100, 999)],
            [
                'name_ar' => 'خدمات استشارية',
                'name_en' => 'Advisory services',
                'type' => 'service',
                'unit_code' => 'C62',
                'recognition' => 'immediate',
                'active' => true,
            ],
        );
    }

    /**
     * @param  array<string, callable(): list<string>>  $rules
     * @return array<string, list<string>>
     */
    private function runEach(array $rules): array
    {
        $results = [];

        foreach ($rules as $name => $rule) {
            $savepoint = 'invr_'.bin2hex(random_bytes(4));
            $this->connection?->statement('SAVEPOINT '.$savepoint);

            try {
                $this->party = $this->party?->fresh();
                $this->code = $this->code?->fresh();
                $this->item = $this->item?->fresh();

                $results[$name] = $rule();
            } catch (Throwable $e) {
                $results[$name] = ['the check could not run: '.$e->getMessage()];
            } finally {
                $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }
        }

        return $results;
    }
}
