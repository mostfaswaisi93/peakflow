<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\InvoiceFinding;
use App\Tenant\Sales\Services\InvoiceValidator;
use App\Tenant\Sales\Services\Issuer;
use App\Tenant\Sales\Services\Simplified;
use App\Tenant\Tax\Enums\CustomerStatus;
use App\Tenant\Tax\Enums\SupplyType;
use App\Tenant\Tax\Services\PlaceOfSupply;
use App\Tenant\Tax\SupplyContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Simplified and B2C documents - PF-101.
 *
 * ## The threshold upgrades the document, it does not bend it
 *
 * The regimes that set a limit set it as a condition of using the form at
 * all. A document above it is not a simplified invoice with a small problem:
 * it has to be issued in full, with the customer's registration and address -
 * which is exactly what somebody chose the simplified form to avoid typing,
 * and exactly why the refusal has to happen while they are still editing.
 *
 * ## B2C is not a document type
 *
 * It is a customer with no tax registration, and what changes is the place of
 * supply: a digital service to a consumer abroad is taxed where the consumer
 * is, and the same service to a business abroad is reverse-charged. The
 * engine for that is PF-078's rule book; what this suite proves is that the
 * sales side reaches it and that an unregistered customer can be invoiced at
 * all.
 */
final class SimplifiedInvoiceRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['simplified invoices (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'the form and its limits come from the profile' => fn (): array => $this->theProfileDeclaresIt(),
                'a document within the limit may be simplified' => fn (): array => $this->withinTheLimit(),
                'above the limit the type is upgraded, not bent' => fn (): array => $this->aboveTheLimit(),
                'a document that outgrows its form cannot be issued' => fn (): array => $this->outgrown(),
                'only an invoice and a credit note have a simplified form' => fn (): array => $this->theWrongType(),
                'a regime with no simplified invoice is not given one' => fn (): array => $this->notPermitted(),
                'an unregistered customer can be invoiced' => fn (): array => $this->b2c(),
                'a digital service is placed differently for a consumer' => fn (): array => $this->digitalServices(),
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function theProfileDeclaresIt(): array
    {
        $violations = [];
        $simplified = app(Simplified::class);

        $zatca = $simplified->formFor('zatca');

        if (! $zatca->hasNoValueLimit()) {
            $violations[] = 'zatca is given a value threshold. Its simplified tax invoice is the B2C '.
                'form and what decides it is the customer, not the amount: a B2B supply needs a '.
                'standard invoice whatever it is worth';
        }

        $eu = $simplified->formFor('peppol');

        if ($eu->threshold === null
            || ! $eu->threshold->equals(Money::of('100.00', 'EUR'))) {
            $violations[] = sprintf(
                'the EU threshold is %s and the directive\'s ceiling is EUR 100',
                $eu->threshold === null ? 'absent' : (string) $eu->threshold,
            );
        }

        if (! $eu->omits('customer_registration') || ! $eu->omits('customer_address')) {
            $violations[] = 'the EU simplified form is not recorded as omitting anything, which makes '.
                'it a full invoice with a different name';
        }

        try {
            $simplified->formFor('a-profile-nobody-declared');

            $violations[] = 'an undeclared profile was given a simplified form. Inventing one issues '.
                'documents the authority rejects';
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'config/jurisdictions.php')) {
                $violations[] = 'the refusal does not say where to declare it: '.$e->getMessage();
            }
        }

        /*
         | And a threshold in another currency is not converted.
         |
         | Converting it would be this system deciding what the regime's
         | limit means in the document's currency, on a rate of its choosing.
         | The standard form is always acceptable; the simplified one is the
         | concession.
         */
        if ($eu->admits(Money::of('50.00', 'SAR'))) {
            $violations[] = 'a 50.00 SAR document was admitted against a EUR 100 threshold, which is '.
                'this system deciding what the regime\'s limit means in another currency';
        }

        return $violations;
    }

    /** @return list<string> */
    public function withinTheLimit(): array
    {
        $violations = [];
        $draft = $this->draft();

        $marked = app(Simplified::class)->mark($draft, 'zatca');

        if (! (bool) $marked->simplified) {
            $violations[] = 'a document within the limit was not marked simplified';
        }

        $issued = app(Issuer::class)->issue(
            $marked, $this->entity(), $this->actorId(), $this->decision,
        );

        if (! (bool) $issued->simplified) {
            $violations[] = 'issuing lost the form the document was issued in, which is part of what '.
                'it says';
        }

        /*
         | And it is in the frozen bytes.
         |
         | Which form a document was issued in is material: a simplified
         | invoice and a full one carry different obligations, so a change
         | from one to the other has to invalidate the chain.
         */
        $canonical = (string) $issued->canonicalForm();

        if ($canonical !== '' && ! str_contains($canonical, 'simplified')) {
            $violations[] = 'the frozen canonical form does not record whether the document was '.
                'simplified, so changing it afterwards would not break the chain';
        }

        return $violations;
    }

    /** @return list<string> */
    public function aboveTheLimit(): array
    {
        $simplified = app(Simplified::class);
        $draft = $this->draft();

        // 1,150.00 SAR against the EU's EUR 100, and against a EUR document
        // of the same size: both are above the form's reach.
        try {
            $simplified->mark($draft, 'peppol');

            return ['a 1,150.00 document was marked simplified under a profile that permits the form '.
                'up to 100. Above the limit the form is not available at all'];
        } catch (Throwable $e) {
            foreach (['100', 'full invoice'] as $needle) {
                if (! str_contains($e->getMessage(), $needle)) {
                    return [sprintf(
                        'the refusal does not mention "%s", so it does not say what the limit is or '.
                        'what to do instead: %s',
                        $needle,
                        $e->getMessage(),
                    )];
                }
            }
        }

        if ($simplified->formIsStillAvailable($draft, 'peppol')) {
            return ['the sales screens would still offer the simplified form for a document the '.
                'service refuses to mark'];
        }

        return [];
    }

    /** @return list<string> */
    public function outgrown(): array
    {
        $draft = app(Simplified::class)->mark($this->draft(), 'zatca');

        /*
         | Marked under a profile with no limit, then moved to one with a
         | limit it exceeds - which is the shape of a document edited after
         | it was marked, and the reason the validator checks the form as
         | well as the service.
         */
        DB::connection('tenant')->table('invoices')
            ->where('id', (string) $draft->getKey())
            ->update(['profile' => 'peppol']);

        $findings = app(InvoiceValidator::class)->blocking(
            app(InvoiceValidator::class)->validate($draft->refresh(), $this->entity()),
        );

        $codes = array_map(static fn (InvoiceFinding $f): string => $f->code, $findings);

        if (! \in_array('above_the_simplified_threshold', $codes, true)) {
            return [sprintf(
                'a simplified document above its profile\'s threshold was accepted. What came back: %s',
                $codes === [] ? 'nothing at all' : implode(', ', $codes),
            )];
        }

        try {
            app(Issuer::class)->issue(
                $draft->refresh(), $this->entity(), $this->actorId(), $this->decision,
            );

            return ['and it was issued anyway'];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    public function theWrongType(): array
    {
        $violations = [];
        $simplified = app(Simplified::class);

        $prepayment = $this->draft();

        DB::connection('tenant')->table('invoices')
            ->where('id', (string) $prepayment->getKey())
            ->update(['document_type' => DocumentType::PrepaymentInvoice->value, 'series_key' => 'PRE']);

        try {
            $simplified->mark($prepayment->refresh(), 'zatca');

            $violations[] = 'a prepayment invoice was marked simplified. The regimes define the form '.
                'for an invoice and the credit note that corrects one';
        } catch (Throwable) {
            // As it should be.
        }

        /*
         | And the database refuses it too, which is the half that survives a
         | second write path.
         */
        try {
            DB::connection('tenant')->table('invoices')
                ->where('id', (string) $prepayment->getKey())
                ->update(['simplified' => true]);

            $violations[] = 'the database allowed a prepayment invoice to be marked simplified';
        } catch (Throwable) {
            // The CHECK constraint.
        }

        return $violations;
    }

    /** @return list<string> */
    public function notPermitted(): array
    {
        try {
            app(Simplified::class)->mark($this->draft(), 'eta');

            return ['a profile with no simplified invoice was given one. Where a regime handles '.
                'consumer sales through a separate receipt system, a simplified invoice is a document '.
                'it will not accept'];
        } catch (Throwable $e) {
            /*
             | And the refusal has to say the right thing.
             |
             | Measured: asserting only that it names the profile left this
             | check green when the `permitted` branch was removed - the
             | threshold branch refused it instead, with a message about a
             | value limit this regime does not have. "There is no simplified
             | invoice here" and "this document is too large for one" send
             | somebody to two different places.
             */
            foreach (['eta', 'no simplified invoice'] as $needle) {
                if (! str_contains($e->getMessage(), $needle)) {
                    return [sprintf(
                        'the refusal does not say "%s": %s', $needle, $e->getMessage(),
                    )];
                }
            }

            return [];
        }
    }

    /** @return list<string> */
    public function b2c(): array
    {
        $violations = [];

        /*
         | A consumer: a party with no tax registration at all.
         |
         | The document omits the customer's registration because there is
         | none, and nothing about that may block the sale - which is the
         | whole of B2C as far as the document is concerned.
         */
        $consumer = Party::create([
            'code' => 'B2C-'.random_int(1000, 9999),
            'legal_name' => 'Walk-in customer',
            'is_customer' => true,
            'country' => 'SA',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        $draft = $this->draft();
        $draft->update(['party_id' => (string) $consumer->getKey()]);

        $findings = app(InvoiceValidator::class)->blocking(
            app(InvoiceValidator::class)->validate($draft->refresh(), $this->entity()),
        );

        if ($findings !== []) {
            $violations[] = sprintf(
                'a consumer with no tax registration could not be invoiced: %s',
                implode(', ', array_map(static fn (InvoiceFinding $f): string => $f->code, $findings)),
            );
        }

        $issued = app(Issuer::class)->issue(
            $draft->refresh(), $this->entity(), $this->actorId(), $this->decision,
        );

        $snapshot = (array) $issued->customer_snapshot;

        if (($snapshot['registration'] ?? null) !== null) {
            $violations[] = 'the frozen customer carries a registration that does not exist';
        }

        if (($snapshot['legal_name'] ?? '') === '') {
            $violations[] = 'the frozen customer has no name at all. A consumer document still names '.
                'somebody - a generic walk-in party where a regime permits it - because a document '.
                'addressed to nobody cannot be delivered or disputed';
        }

        return $violations;
    }

    /** @return list<string> */
    public function digitalServices(): array
    {
        $violations = [];
        $rules = app(PlaceOfSupply::class);

        $consumer = $rules->decide(SupplyContext::withNoDeliveryLocation(
            SupplyType::DigitalServices, 'SA', 'FR', CustomerStatus::Consumer,
        ));

        $business = $rules->decide(SupplyContext::withNoDeliveryLocation(
            SupplyType::DigitalServices, 'SA', 'FR', CustomerStatus::Business,
        ));

        if ($consumer->country !== 'FR') {
            $violations[] = sprintf(
                'a digital service to a consumer in France is placed in %s. The destination principle '.
                'is the rule that made a small business selling a download liable where the consumer '.
                'is',
                $consumer->country,
            );
        }

        if ($consumer->rule === $business->rule) {
            $violations[] = sprintf(
                'the same rule (%s) placed a digital service for a consumer and for a business. The '.
                'consumer rule is the exception to the general one and sits above it precisely because '.
                'the treatment differs',
                $consumer->rule,
            );
        }

        if ($business->category->value !== 'AE') {
            $violations[] = sprintf(
                'a digital service to a business abroad is category %s and should be AE - the customer '.
                'accounts for it',
                $business->category->value,
            );
        }

        if ($consumer->category->value === 'AE') {
            $violations[] = 'a consumer was asked to reverse-charge, which only a registered business '.
                'can do';
        }

        return $violations;
    }
}
