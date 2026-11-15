<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Models\PartyTaxRegistration;
use App\Tenant\Tax\Enums\CustomerStatus;
use App\Tenant\Tax\Enums\SupplyType;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Sales\Services\CounterpartyRegistrationReader;
use App\Tenant\Tax\Exceptions\ReverseChargeNotIssuable;
use App\Tenant\Tax\CounterpartyRegistrations;
use App\Tenant\Tax\PlaceOfSupplyDecision;
use App\Tenant\Tax\Services\PlaceOfSupply;
use App\Tenant\Tax\Services\ReverseCharge;
use App\Tenant\Tax\SupplyContext;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Models\TaxRegistration;
use App\Tenant\Tenancy\Services\EntityContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The reverse charge, and the registrations it depends on - PF-079.
 *
 * ## The scenario these are built around
 *
 * A Saudi consultancy invoices a French business for advice. The place of
 * supply is France (Art. 44), the category is `AE`, and the invoice shows no
 * tax. If the French customer's registration had lapsed, the supply was
 * taxable in Saudi Arabia all along - and the authority collects from the
 * Saudi consultancy, with penalties, after the return it was left out of has
 * been filed.
 *
 * So the question every rule here asks is some form of "was the registration
 * valid ON THE TAX POINT", not "is there a number on the record".
 *
 * ## Why the date is the whole point
 *
 * `Entity::primaryTaxRegistration()` answers for today. Its own docblock says
 * an invoice issued last year must carry the number that was current when it
 * was issued, which `now()` cannot do - so PF-079 added a date-taking lookup
 * and these checks exercise it across a boundary in both directions: a
 * registration that starts after the tax point, and one that ended before it.
 */
final class ReverseChargeRules
{
    private ?Connection $connection = null;

    private string $entityId = '';

    private ?Entity $entity = null;

    private ?Party $registered = null;

    private ?Party $unregistered = null;

    private ?Party $lapsed = null;

    private ?Party $notYet = null;

    /** The tax point every case is judged on. */
    private CarbonImmutable $taxPoint;

    public function __construct()
    {
        $this->taxPoint = CarbonImmutable::parse('2026-03-15');
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['reverse charge (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return $this->runEach([
                'AE and Z are different treatments, not the same empty column' => fn (): array => $this->aeIsNotAZeroRate(),
                'every profile declares a note, in both languages, with its article' => fn (): array => $this->everyProfileDeclaresANote(),
                'the note is prescribed per jurisdiction, not translated at render time' => fn (): array => $this->theNoteIsPerJurisdiction(),
                'a profile with no note refuses rather than printing something generic' => fn (): array => $this->aMissingNoteRefuses(),
                'both parties registered on the tax point, and it issues' => fn (): array => $this->theHappyPath(),
                'an unregistered customer is refused, and told apart from a lapsed one' => fn (): array => $this->registrationsAreChecked(),
                'a registration is judged on the tax point, not on today' => fn (): array => $this->theDateThatMatters(),
                'overlapping registrations are refused by the database' => fn (): array => $this->overlapsAreRefusedByTheDatabase(),
                'asking for a reverse charge where there is none refuses' => fn (): array => $this->theWrongCategoryRefuses(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * `AE` and `Z` both leave the tax column empty and are not the same thing.
     *
     * Written out from the code list rather than read from the enum: a check
     * that derived the matrix would assert the enum equals itself, and the
     * whole claim is that these two are different.
     *
     * @return list<string>
     */
    public function aeIsNotAZeroRate(): array
    {
        $violations = [];

        $expected = [
            // category => [taxable supply, restricts input recovery, reverse charge, must be zero]
            'AE' => [true, false, true, true],
            'Z' => [true, false, false, true],
        ];

        foreach ($expected as $code => [$taxable, $restricts, $reverse, $zero]) {
            $category = TaxCategory::from($code);

            foreach ([
                'is a taxable supply' => [$category->isTaxableSupply(), $taxable],
                'restricts input recovery' => [$category->restrictsInputRecovery(), $restricts],
                'is a reverse charge' => [$category->isReverseCharge(), $reverse],
                'requires a zero rate' => [$category->requiresZeroRate(), $zero],
            ] as $what => [$actual, $wanted]) {
                if ($actual !== $wanted) {
                    $violations[] = sprintf('%s %s: %s, and should be %s',
                        $code, $what, $actual ? 'true' : 'false', $wanted ? 'true' : 'false');
                }
            }
        }

        if (TaxCategory::ZeroRated->isReverseCharge()) {
            $violations[] = 'Z reports itself as a reverse charge. Modelling a reverse charge as a zero '.
                'rate with a note reports a taxable supply the supplier did not make, reports nothing '.
                'at all on the customer\'s return, and is found when an authority matches the two';
        }

        if (! TaxCategory::ReverseCharge->isReverseCharge()) {
            $violations[] = 'AE does not report itself as a reverse charge, so nothing downstream will '.
                'move the liability to the customer';
        }

        return $violations;
    }

    /** @return list<string> */
    public function everyProfileDeclaresANote(): array
    {
        $violations = [];
        $charge = app(ReverseCharge::class);
        $profiles = config('jurisdictions.enabled');

        foreach (\is_array($profiles) ? array_keys($profiles) : [] as $profile) {
            $profile = (string) $profile;

            foreach (['ar', 'en'] as $locale) {
                try {
                    $note = $charge->noteFor($profile, $locale);
                } catch (Throwable $e) {
                    $violations[] = sprintf('the %s profile has no %s note: %s', $profile, $locale, $e->getMessage());

                    continue;
                }

                if (mb_strlen($note) < 12) {
                    $violations[] = sprintf(
                        'the %s profile\'s %s note is "%s", which is too short to be the legal mention '.
                        'Art. 226(11a) requires', $profile, $locale, $note,
                    );
                }
            }

            try {
                $source = $charge->sourceFor($profile);
            } catch (Throwable $e) {
                $violations[] = sprintf('the %s profile\'s note cites no article: %s', $profile, $e->getMessage());

                continue;
            }

            /*
             | An article, or an explicit statement that the article was not
             | verified.
             |
             | This check first failed on the Jordanian note, which cited the
             | law and no article - and the fix was NOT to invent one. A
             | fabricated article number reads as checked and is the first
             | thing an auditor looks up, so the data says what it does not
             | know, the same way database/iso/provenance.json does.
             */
            if (! str_contains($source, 'Art.') && ! str_contains($source, 'article unverified')) {
                $violations[] = sprintf(
                    'the %s profile\'s note cites "%s", which names neither an article nor the fact '.
                    'that the article is unverified. A note nobody can trace cannot be defended when an '.
                    'authority asks why no tax was charged - and a guessed article is worse, because it '.
                    'reads as checked',
                    $profile, $source,
                );
            }

            if (mb_strlen($source) < 20) {
                $violations[] = sprintf(
                    'the %s profile\'s note cites "%s", which does not identify an instrument',
                    $profile, $source,
                );
            }
        }

        return $violations;
    }

    /**
     * The wording is prescribed, so the profiles must actually differ.
     *
     * @return list<string>
     */
    public function theNoteIsPerJurisdiction(): array
    {
        $violations = [];
        $charge = app(ReverseCharge::class);

        if ($charge->noteFor('zatca', 'ar') === $charge->noteFor('generic', 'ar')) {
            $violations[] = 'the zatca Arabic note is identical to the generic one. Saudi invoice '.
                'contents are prescribed by Art. 53 of the Implementing Regulations, and a generic '.
                'phrase translated at render time satisfies nobody\'s rules';
        }

        if ($charge->noteFor('facturx', 'en') === $charge->noteFor('generic', 'en')) {
            $violations[] = 'the facturx note is identical to the generic one, so the French '.
                'self-assessment wording is not actually configured';
        }

        foreach (['generic', 'zatca', 'facturx', 'eta', 'jofotara'] as $profile) {
            $arabic = $charge->noteFor($profile, 'ar');

            if (preg_match('/\p{Arabic}/u', $arabic) !== 1) {
                $violations[] = sprintf(
                    'the %s profile\'s Arabic note contains no Arabic script: "%s"', $profile, $arabic,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function aMissingNoteRefuses(): array
    {
        $notes = config('jurisdictions.reverse_charge_note');
        config(['jurisdictions.reverse_charge_note' => array_diff_key(
            \is_array($notes) ? $notes : [], ['zatca' => null],
        )]);

        try {
            $note = app(ReverseCharge::class)->noteFor('zatca', 'ar');

            return [sprintf(
                'a profile with no configured note returned "%s". The note is the only thing on a '.
                'reverse-charge invoice that says why no tax is shown, and a document missing a '.
                'mandatory legal mention looks complete',
                $note,
            )];
        } catch (ReverseChargeNotIssuable) {
            return [];
        } catch (Throwable $e) {
            return ['a missing note was refused by the wrong thing: '.$e->getMessage()];
        } finally {
            config(['jurisdictions.reverse_charge_note' => $notes]);
        }
    }

    /** @return list<string> */
    public function theHappyPath(): array
    {
        $charge = app(ReverseCharge::class);
        $decision = $this->decision();

        if ($decision->category !== TaxCategory::ReverseCharge) {
            return [sprintf(
                'the scenario resolved to category %s rather than AE, so none of these checks is about '.
                'a reverse charge', $decision->category->value,
            )];
        }

        try {
            $charge->assertIssuable($this->entity(), $this->evidence('registered'), $decision, $this->taxPoint);
        } catch (Throwable $e) {
            return ['a supply where both parties are registered on the tax point was refused: '.$e->getMessage()];
        }

        return [];
    }

    /** @return list<string> */
    public function registrationsAreChecked(): array
    {
        $violations = [];
        $charge = app(ReverseCharge::class);
        $decision = $this->decision();

        $refusal = $this->refusalFor($charge, $this->evidence('unregistered'), $decision);

        if ($refusal === null) {
            $violations[] = 'a reverse charge to a customer with no registration anywhere was issued. '.
                'That is a taxable supply with no tax on it, collected from the supplier years later';
        } elseif (! str_contains($refusal, 'no tax registration in FR')) {
            $violations[] = 'an unregistered customer was refused with the wrong reason: '.$refusal;
        }

        $refusal = $this->refusalFor($charge, $this->evidence('lapsed'), $decision);

        if ($refusal === null) {
            $violations[] = 'a reverse charge to a customer whose registration ended before the tax '.
                'point was issued. A cancelled registration is indistinguishable from a current one '.
                'unless somebody checks the date';
        } elseif (! str_contains($refusal, 'stopped applying on')) {
            $violations[] = sprintf(
                'a lapsed registration was refused with the same message as a missing one, so the two '.
                'cannot be told apart - one sends somebody to the customer record and the other to the '.
                'customer: %s', $refusal,
            );
        }

        return $violations;
    }

    /**
     * The registration that is valid today and was not on the tax point.
     *
     * The direction a `now()`-based lookup gets wrong without ever looking
     * wrong: the number is on the record, it is current, and it was not the
     * customer's number when the supply happened.
     *
     * @return list<string>
     */
    public function theDateThatMatters(): array
    {
        $violations = [];
        $charge = app(ReverseCharge::class);
        $decision = $this->decision();

        $refusal = $this->refusalFor($charge, $this->evidence('notYet'), $decision);

        if ($refusal === null) {
            $violations[] = 'a registration that only began AFTER the tax point was accepted. It is on '.
                'the record and it is current, and it was not the customer\'s registration when the '.
                'supply happened';
        }

        // And the supplier side, on the same boundary.
        $entity = $this->entity();
        $entity->taxRegistrations()->delete();

        TaxRegistration::create([
            'entity_id' => $this->entityId,
            'jurisdiction' => 'SA',
            'scheme' => 'VAT',
            'identifier' => '310000000000003',
            'effective_from' => $this->taxPoint->addMonth()->toDateString(),
            'is_primary' => true,
        ]);

        $refusal = $this->refusalFor($charge, $this->evidence('registered'), $decision, $entity->fresh());

        if ($refusal === null) {
            $violations[] = 'the supplying entity\'s registration began after the tax point and the '.
                'reverse charge was issued anyway. An unregistered supplier has no standing to shift '.
                'the liability';
        } elseif (! str_contains($refusal, 'no tax registration in SA')) {
            $violations[] = 'an unregistered supplier was refused with the wrong reason: '.$refusal;
        }

        return $violations;
    }

    /**
     * Two registrations, same jurisdiction and scheme, overlapping periods.
     *
     * Attempted through the model rather than through a service, because the
     * constraint is what holds when somebody writes an UPDATE - and a
     * service's own validation would pass this check while the constraint was
     * missing.
     *
     * @return list<string>
     */
    public function overlapsAreRefusedByTheDatabase(): array
    {
        $party = $this->party('registered');

        try {
            PartyTaxRegistration::create([
                'party_id' => $party->getKey(),
                'jurisdiction' => 'FR',
                'scheme' => 'VAT',
                'identifier' => 'FR99999999999',
                'is_primary' => false,
                'validity' => PartyTaxRegistration::range(
                    CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'),
                ),
            ]);
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'party_tax_registrations_no_overlap')
                ? []
                : ['an overlapping registration was refused by something other than the exclusion '.
                    'constraint: '.$e->getMessage()];
        }

        return ['two registrations for the same party, jurisdiction and scheme covering the same dates '.
            'were both stored. "Which number was valid then" now has two answers, and a document will '.
            'pick whichever the index returns first'];
    }

    /** @return list<string> */
    public function theWrongCategoryRefuses(): array
    {
        $charge = app(ReverseCharge::class);

        $domestic = app(PlaceOfSupply::class)->decide(SupplyContext::withNoDeliveryLocation(
            SupplyType::Services, 'SA', 'SA', CustomerStatus::Business,
        ));

        try {
            $charge->assertIssuable($this->entity(), $this->evidence('registered'), $domestic, $this->taxPoint);
        } catch (ReverseChargeNotIssuable $e) {
            return str_contains($e->getMessage(), 'not AE')
                ? []
                : ['a domestic supply was refused for the wrong reason: '.$e->getMessage()];
        }

        return ['assertIssuable accepted a domestic supply in category S. A caller that reaches it '.
            'believes the customer is accounting for the tax, and nobody would be'];
    }

    private function decision(): PlaceOfSupplyDecision
    {
        return app(PlaceOfSupply::class)->decide(SupplyContext::withNoDeliveryLocation(
            SupplyType::Services, 'SA', 'FR', CustomerStatus::Business,
        ));
    }

    private function refusalFor(
        ReverseCharge $charge,
        CounterpartyRegistrations $customer,
        PlaceOfSupplyDecision $decision,
        ?Entity $entity = null,
    ): ?string {
        try {
            $charge->assertIssuable($entity ?? $this->entity(), $customer, $decision, $this->taxPoint);

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    private function entity(): Entity
    {
        \assert($this->entity !== null);

        return $this->entity;
    }

    private function party(string $which): Party
    {
        $party = match ($which) {
            'registered' => $this->registered,
            'unregistered' => $this->unregistered,
            'lapsed' => $this->lapsed,
            'notYet' => $this->notYet,
            default => null,
        };

        \assert($party !== null);

        return $party;
    }

    /**
     * The party's registrations, read through the Sales-side reader.
     *
     * Deliberately through the real reader rather than built by hand: the
     * translation from a party's rows to the engine's value objects is where
     * a date can be dropped, and a check that constructed the evidence itself
     * would prove the engine right about data no production path produces.
     */
    private function evidence(string $which): CounterpartyRegistrations
    {
        return app(CounterpartyRegistrationReader::class)->for($this->party($which));
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'RVC'.random_int(100, 999),
            'legal_name' => 'Reverse charge check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
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

        $this->registered = $this->customer('RC-REG', [
            ['FR', 'VAT', 'FR40303265045', '2019-01-01', null],
        ]);

        $this->unregistered = $this->customer('RC-NONE', []);

        $this->lapsed = $this->customer('RC-LAPSED', [
            ['FR', 'VAT', 'FR11111111111', '2019-01-01', '2026-02-01'],
        ]);

        $this->notYet = $this->customer('RC-FUTURE', [
            ['FR', 'VAT', 'FR22222222222', '2026-06-01', null],
        ]);
    }

    /** @param  list<array{string, string, string, string, string|null}>  $registrations */
    private function customer(string $code, array $registrations): Party
    {
        $party = Party::create([
            'code' => $code.'-'.random_int(100, 999),
            'legal_name' => 'Customer '.$code,
            'is_customer' => true,
            'country' => 'FR',
            'default_currency' => 'EUR',
            'active' => true,
        ]);

        foreach ($registrations as [$jurisdiction, $scheme, $identifier, $from, $until]) {
            PartyTaxRegistration::create([
                'party_id' => $party->getKey(),
                'jurisdiction' => $jurisdiction,
                'scheme' => $scheme,
                'identifier' => $identifier,
                'is_primary' => true,
                'validity' => PartyTaxRegistration::range(
                    CarbonImmutable::parse($from),
                    $until === null ? null : CarbonImmutable::parse($until),
                ),
            ]);
        }

        return $party;
    }

    /**
     * @param  array<string, callable(): list<string>>  $rules
     * @return array<string, list<string>>
     */
    private function runEach(array $rules): array
    {
        $results = [];

        foreach ($rules as $name => $rule) {
            $savepoint = 'rvc_'.bin2hex(random_bytes(4));
            $this->connection?->statement('SAVEPOINT '.$savepoint);

            try {
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
