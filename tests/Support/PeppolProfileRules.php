<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exchange\Builders\SemanticInvoiceBuilder;
use App\Exchange\Pipeline\ValidationPipeline;
use App\Exchange\Profiles\GenericProfile;
use App\Exchange\Profiles\Peppol\PeppolProfile;
use App\Exchange\Profiles\ProfileRegistry;
use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Semantics\SemanticNode;
use App\Exchange\Serializers\Ubl\UblSerializer;
use App\Exchange\Validation\SemanticViolation;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use Illuminate\Support\Facades\DB;

/**
 * Peppol BIS Billing 3.0 - PF-120.
 *
 * ## What the second profile is really testing
 *
 * PF-118's claim: adding a country is a new class plus fixtures and nothing
 * else. So the first of these checks is that the **serializer output is
 * identical** between the generic profile and Peppol for the same model,
 * apart from the two identifiers the profile itself supplies. A CIUS is a
 * narrowing, not a dialect, and if Peppol had needed a serializer of its own
 * then the semantic model would not be doing its job.
 *
 * The rest are the restrictions: each one is a document EN 16931 accepts and
 * the Peppol network rejects, which is the whole reason stage 4 exists.
 * Without it a supplier issues a conformant invoice, the access point
 * refuses it, and nobody can explain why from our side.
 *
 * ## The participant identifiers are fixture values
 *
 * A Saudi entity on the Peppol network is not a realistic combination, and
 * `0088` is not a real scheme for one. It does not matter: not one of the
 * rules being exercised depends on which registry the scheme names, and
 * PeakFlow does not hold the vendored EAS code list to pick a real one
 * from. What is being checked is the CIUS, not the geography.
 */
final class PeppolProfileRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $static = [
            'the profile is built and deliberately not selectable' => $this->notYetSelectable(),
            'a CIUS is a narrowing, not a dialect' => $this->declaresNoNewSyntax(),
        ];

        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return [...$static, 'Peppol (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return [
                ...$static,
                ...$this->withTheLedgerProof($this->runEachInASavepoint([
                    'the same model serializes identically under both profiles' => fn (): array => $this->sameBytes(),
                    'a Peppol-ready document passes stage 4' => fn (): array => $this->passes(),
                    'a document with no business process is refused' => fn (): array => $this->r001(),
                    'a second note is refused' => fn (): array => $this->r002(),
                    'a document the buyer cannot match is refused' => fn (): array => $this->r003(),
                    'a document with no electronic address is refused' => fn (): array => $this->addresses(),
                    'an address with no four-digit scheme is refused' => fn (): array => $this->scheme(),
                    'a debit note is refused before a number is allocated' => fn (): array => $this->documentType(),
                    'an accounting currency equal to the invoice\'s is refused' => fn (): array => $this->r005(),
                    'a percentage without its base is refused' => fn (): array => $this->r041(),
                    'a net price that is not gross less discount is refused' => fn (): array => $this->r046(),
                    'a line period outside the invoice period is refused' => fn (): array => $this->r110(),
                    'a line net amount that cannot be recomputed is refused' => fn (): array => $this->r120(),
                    'a price base quantity of zero is refused' => fn (): array => $this->r121(),
                    'a price in a different unit from the line is refused' => fn (): array => $this->r130(),
                    'the CIUS failure is reported at stage 4' => fn (): array => $this->stage(),
                    'and the ledger still proves its own invariants' => fn (): array => [],
                ]), $this->entityId),
            ];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function notYetSelectable(): array
    {
        $registry = app(ProfileRegistry::class);
        $violations = [];

        if (! $registry->has('peppol')) {
            return ['the Peppol profile is not registered'];
        }

        /*
         | Built, and off.
         |
         | The CIUS and the identifiers are implemented and checked; the AS4
         | transport and the SMP lookup are not. A tenant selecting it today
         | would produce documents that validate and cannot be sent, and
         | "issued but undeliverable" is a worse state than "not yet
         | issued".
         */
        if ($registry->isEnabled('peppol')) {
            $violations[] = 'Peppol is enabled while its transport is not built, so a tenant could '.
                'issue documents nothing can send';
        }

        if (\in_array('peppol', $registry->declaredButNotBuilt(), true)) {
            $violations[] = 'the registry still reports Peppol as unimplemented';
        }

        $profile = $registry->get('peppol');

        if (! str_contains($profile->specificationIdentifier(), 'peppol.eu')) {
            $violations[] = 'the customization identifier is not the Peppol one, so a recipient would '.
                'validate the document against the wrong rule set';
        }

        if ($profile->businessProcess() === null) {
            $violations[] = 'the profile declares no business process, which its own R001 requires';
        }

        if (! \in_array('cius', $profile->validatorChain(), true)) {
            $violations[] = 'the chain does not name the cius stage, so none of the restrictions would run';
        }

        return $violations;
    }

    /** @return list<string> */
    private function declaresNoNewSyntax(): array
    {
        $peppol = app(PeppolProfile::class);
        $generic = app(GenericProfile::class);
        $violations = [];

        if ($peppol->serializer()::class !== $generic->serializer()::class) {
            $violations[] = sprintf(
                'Peppol uses %s and the baseline uses %s. Peppol is UBL 2.1 with a narrower profile, '.
                'and needing a serializer of its own would mean the semantic model is not doing its job',
                $peppol->serializer()::class,
                $generic->serializer()::class,
            );
        }

        $source = (string) file_get_contents(app_path('Exchange/Profiles/Peppol/PeppolProfile.php'));

        if (str_contains($source, 'class PeppolSerializer') || str_contains($source, 'Serializers\Peppol')) {
            $violations[] = 'the profile carries a serializer of its own';
        }

        return $violations;
    }

    /** @return list<string> */
    private function sameBytes(): array
    {
        $model = $this->peppolReady();
        $serializer = app(UblSerializer::class);

        $first = $serializer->serialize($model);
        $second = $serializer->serialize($model);

        if ($first !== $second) {
            return ['the serializer is not deterministic, so this comparison proves nothing'];
        }

        /*
         | The same model, the same bytes, whichever profile is validating.
         |
         | The profile supplies BT-23 and BT-24 into the model, and once it
         | has, the serializer does not know which profile asked. That is
         | the property PF-118's interface exists to have.
         */
        $generic = app(ValidationPipeline::class)->run($model, app(GenericProfile::class));
        $peppol = app(ValidationPipeline::class)->run($model, app(PeppolProfile::class));

        $violations = [];

        if (! $generic->passed()) {
            $violations[] = 'a Peppol-ready document does not satisfy the baseline, which it must: a '.
                'CIUS narrows EN 16931 and cannot contradict it';
        }

        if (! $peppol->passed()) {
            $violations[] = 'the Peppol-ready document does not satisfy Peppol: '.implode('; ', array_map(
                static fn (SemanticViolation $v): string => $v->rule.' - '.(string) $v->detail,
                $peppol->all(),
            ));
        }

        if ($serializer->serialize($model) !== $first) {
            $violations[] = 'the bytes changed after validation ran';
        }

        return $violations;
    }

    /** @return list<string> */
    private function passes(): array
    {
        $report = app(ValidationPipeline::class)->run($this->peppolReady(), app(PeppolProfile::class));

        return $report->passed() ? [] : array_map(
            static fn (SemanticViolation $v): string => sprintf('%s: %s', $v->rule, (string) $v->detail),
            $report->all(),
        );
    }

    /** @return list<string> */
    private function r001(): array
    {
        return $this->expect('PEPPOL-EN16931-R001', function (SemanticNode $root): void {
            $root->first(BG::ProcessControl)?->set(BT::BusinessProcessType, null);
        });
    }

    /** @return list<string> */
    private function r002(): array
    {
        /*
         | TWO notes, because the fixture's document has none.
         |
         | Measured: adding one made it one note, which Peppol permits, and
         | the check passed while proving nothing. The rule is about the
         | second one - a jurisdiction that requires a legal note and a user
         | who wrote their own have to be combined into it.
         */
        return $this->expect('PEPPOL-EN16931-R002', function (SemanticNode $root): void {
            foreach (['A legal note the jurisdiction requires', 'And one the user wrote'] as $text) {
                $note = SemanticNode::of(BG::InvoiceNote);
                $note->set(BT::InvoiceNote, $text);
                $root->nest($note);
            }
        });
    }

    /** @return list<string> */
    private function r003(): array
    {
        return $this->expect('PEPPOL-EN16931-R003', function (SemanticNode $root): void {
            $root->set(BT::BuyerReference, null)->set(BT::PurchaseOrderReference, null);
        });
    }

    /** @return list<string> */
    private function addresses(): array
    {
        return [
            ...$this->expect('PEPPOL-EN16931-R020', function (SemanticNode $root): void {
                $root->first(BG::Seller)?->set(BT::SellerElectronicAddress, null);
            }),
            ...$this->expect('PEPPOL-EN16931-R010', function (SemanticNode $root): void {
                $root->first(BG::Buyer)?->set(BT::BuyerElectronicAddress, null);
            }),
        ];
    }

    /** @return list<string> */
    private function scheme(): array
    {
        return $this->expect('PEPPOL-COMMON-R050', function (SemanticNode $root): void {
            $buyer = $root->first(BG::Buyer);
            $buyer?->set(BT::BuyerElectronicAddress, 'buyer@example.com')
                ->qualify(BT::BuyerElectronicAddress, 'EMAIL');
        });
    }

    /** @return list<string> */
    private function documentType(): array
    {
        return $this->expect('PEPPOL-DOCUMENT-TYPE', function (SemanticNode $root): void {
            $root->set(BT::InvoiceTypeCode, '383');
        });
    }

    /** @return list<string> */
    private function r005(): array
    {
        return $this->expect('PEPPOL-EN16931-R005', function (SemanticNode $root): void {
            $root->set(BT::VatAccountingCurrencyCode, 'SAR');
            $root->first(BG::DocumentTotals)?->set(BT::TotalVatAmountInAccountingCurrency, '150.00');
        });
    }

    /** @return list<string> */
    private function r041(): array
    {
        /*
         | The totals are adjusted with the allowance, so stage 3 passes and
         | the pipeline reaches stage 4.
         |
         | Measured: without them `BR-CO-13` fired first and the pipeline
         | stopped - correctly, and the check proved nothing. A document
         | whose totals do not add up has a larger problem than a missing
         | base amount, and the pipeline is right to say so first.
         */
        return $this->expect('PEPPOL-EN16931-R041', function (SemanticNode $root): void {
            $allowance = SemanticNode::of(BG::DocumentLevelAllowances);
            $allowance->set(BT::DocumentAllowanceAmount, '10.00')
                ->set(BT::DocumentAllowancePercentage, '1')
                ->set(BT::DocumentAllowanceVatCategoryCode, 'S')
                ->set(BT::DocumentAllowanceReason, 'A settlement discount');
            $root->nest($allowance);

            $root->first(BG::DocumentTotals)
                ?->set(BT::SumOfAllowances, '10.00')
                ->set(BT::TotalWithoutVat, '990.00')
                ->set(BT::TotalWithVat, '1140.00')
                ->set(BT::AmountDueForPayment, '1140.00');
        });
    }

    /** @return list<string> */
    private function r046(): array
    {
        return $this->expect('PEPPOL-EN16931-R046', function (SemanticNode $root): void {
            $price = $root->children(BG::InvoiceLine)[0]->first(BG::PriceDetails);
            $price?->set(BT::ItemGrossPrice, '1200')->set(BT::ItemPriceDiscount, '100');
        });
    }

    /** @return list<string> */
    private function r110(): array
    {
        return $this->expect('PEPPOL-EN16931-R110', function (SemanticNode $root): void {
            $period = SemanticNode::of(BG::InvoicingPeriod);
            $period->set(BT::InvoicingPeriodStartDate, '2026-05-01')
                ->set(BT::InvoicingPeriodEndDate, '2026-05-31');
            $root->nest($period);

            $line = $root->children(BG::InvoiceLine)[0];
            $linePeriod = SemanticNode::of(BG::InvoiceLinePeriod);
            $linePeriod->set(BT::LinePeriodStartDate, '2026-04-01')
                ->set(BT::LinePeriodEndDate, '2026-04-30');
            $line->nest($linePeriod);
        }, alsoAllow: ['PEPPOL-EN16931-R111']);
    }

    /** @return list<string> */
    private function r120(): array
    {
        return $this->expect('PEPPOL-EN16931-R120', function (SemanticNode $root): void {
            $root->children(BG::InvoiceLine)[0]->set(BT::InvoicedQuantity, '7');
        }, alsoAllow: ['BR-CO-10', 'BR-CO-13', 'BR-CO-15', 'BR-CO-16']);
    }

    /** @return list<string> */
    private function r121(): array
    {
        return $this->expect('PEPPOL-EN16931-R121', function (SemanticNode $root): void {
            $price = $root->children(BG::InvoiceLine)[0]->first(BG::PriceDetails);
            $price?->set(BT::ItemPriceBaseQuantity, '0')
                ->set(BT::ItemPriceBaseQuantityUnitCode, 'C62');
        });
    }

    /** @return list<string> */
    private function r130(): array
    {
        return $this->expect('PEPPOL-EN16931-R130', function (SemanticNode $root): void {
            $price = $root->children(BG::InvoiceLine)[0]->first(BG::PriceDetails);
            $price?->set(BT::ItemPriceBaseQuantity, '1000')
                ->set(BT::ItemPriceBaseQuantityUnitCode, 'LTR');
        }, alsoAllow: ['PEPPOL-EN16931-R120']);
    }

    /** @return list<string> */
    private function stage(): array
    {
        $model = $this->peppolReady();
        $model->root->first(BG::ProcessControl)?->set(BT::BusinessProcessType, null);

        $report = app(ValidationPipeline::class)->run($model, app(PeppolProfile::class));
        $violations = [];

        if ($report->failedAt() !== 'cius') {
            $violations[] = sprintf(
                'a Peppol restriction is reported at the %s stage. Stage 3 is EN 16931 and a failure '.
                'there is usually our bug; stage 4 is a jurisdiction restriction the supplier has to '.
                'satisfy, and different people fix those',
                (string) $report->failedAt(),
            );
        }

        if ($report->forTheUser() === []) {
            $violations[] = 'a CIUS failure did not reach the customer-facing list. A restriction the '.
                'supplier has to satisfy is one they have to be told about';
        }

        $problem = $report->toProblemDocument('https://peakflow.app/problems/peppol', '/documents/1');

        if (($problem['stage'] ?? null) !== 'cius') {
            $violations[] = 'the problem document does not name the stage';
        }

        return $violations;
    }

    /**
     * Break one thing on a Peppol-ready document and require that rule.
     *
     * `alsoAllow` names the rules the same change legitimately trips - a
     * line quantity of seven breaks `BR-CO-10` as well as `R120`, because
     * the totals no longer add up either. Listing them is how the check
     * stays strict about everything else: any other rule firing is a
     * finding.
     *
     * @param  list<string>  $alsoAllow
     * @return list<string>
     */
    private function expect(string $rule, callable $break, array $alsoAllow = []): array
    {
        $model = $this->peppolReady();
        $profile = app(PeppolProfile::class);
        $pipeline = app(ValidationPipeline::class);

        if (! $pipeline->run($model, $profile)->passed()) {
            return ['the untampered document does not pass, so this check proves nothing'];
        }

        $break($model->root);

        $report = $pipeline->run($model, $profile);
        $rules = array_values(array_unique(array_map(
            static fn (SemanticViolation $v): string => $v->rule,
            $report->all(),
        )));

        if (! \in_array($rule, $rules, true)) {
            return [sprintf(
                '%s did not fire. Reported instead: %s',
                $rule,
                $rules === [] ? 'nothing at all' : implode(', ', $rules),
            )];
        }

        $unexpected = array_diff($rules, [$rule], $alsoAllow);

        return $unexpected === []
            ? []
            : [sprintf('%s fired, and so did %s', $rule, implode(', ', $unexpected))];
    }

    /**
     * A document that satisfies the whole CIUS.
     *
     * Built from a real issued invoice, then given the two terms Peppol
     * makes mandatory that the generic profile does not populate: the
     * business process and a buyer reference.
     */
    private function peppolReady(): SemanticInvoice
    {
        $model = app(SemanticInvoiceBuilder::class)->build($this->issue());
        $profile = app(PeppolProfile::class);

        $model->root->first(BG::ProcessControl)
            ?->set(BT::BusinessProcessType, $profile->businessProcess())
            ->set(BT::SpecificationIdentifier, $profile->specificationIdentifier());

        $model->root->set(BT::BuyerReference, 'PO-2026-0041');

        return $model;
    }
}
