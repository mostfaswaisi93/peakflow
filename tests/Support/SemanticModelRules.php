<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exchange\Builders\SemanticInvoiceBuilder;
use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Semantics\SemanticNode;
use App\Exchange\Validation\SemanticViolation;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Services\Crediting;
use App\Tenant\Sales\Services\Issuer;
use Illuminate\Support\Facades\DB;

/**
 * The semantic model, built from a real issued document - PF-110, PF-116.
 *
 * ## Why these run against the database
 *
 * `ExchangeRules` checks the registry and the mapping document, which are
 * code. This suite checks the thing that actually matters: that an invoice
 * issued through the real path - with its frozen snapshots, its grouped tax
 * and its gapless number - produces a model that satisfies EN 16931 without
 * anybody having hand-built a fixture to make it pass.
 *
 * A hand-built fixture is the failure mode this phase invites. It is easy to
 * write a model that validates and does not resemble what the issuer stores,
 * and the first real document then fails at the recipient.
 *
 * ## Each calculation rule has its own failing case
 *
 * The rules are asserted by tampering with the MODEL, never with the stored
 * document: the database refuses to change an issued invoice, and rightly.
 * Tampering with the model is also the honest test - it asks "if the figures
 * disagreed, would we catch it" without asking the database to permit
 * something it exists to prevent.
 */
final class SemanticModelRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['the semantic model (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'an issued invoice produces a model that validates' => fn (): array => $this->issuedValidates(),
                'a draft cannot be serialized' => fn (): array => $this->draftIsRefused(),
                'the model reads the frozen snapshot, not the live party' => fn (): array => $this->frozen(),
                'amounts are at the currency\'s own exponent' => fn (): array => $this->exponent(),
                'the tax breakdown becomes one BG-23 per rate group' => fn (): array => $this->breakdown(),
                'a credit note carries the document it credits' => fn (): array => $this->creditNoteReference(),
                'BR-CO-10 fires when the line sum disagrees' => fn (): array => $this->brCo10(),
                'BR-CO-13 fires when the net total disagrees' => fn (): array => $this->brCo13(),
                'BR-CO-14 fires when the tax total disagrees with the breakdown' => fn (): array => $this->brCo14(),
                'BR-CO-15 fires when the gross total disagrees' => fn (): array => $this->brCo15(),
                'BR-CO-16 fires when the amount due disagrees' => fn (): array => $this->brCo16(),
                'BR-CO-17 fires when a group\'s tax is not its rate' => fn (): array => $this->brCo17(),
                'BR-CO-3 refuses a tax point stated twice' => fn (): array => $this->brCo3(),
                'BR-CO-25 refuses money owed with no date and no terms' => fn (): array => $this->brCo25(),
                'BR-CO-26 refuses an unidentifiable seller' => fn (): array => $this->brCo26(),
                'BR-CO-9 refuses a VAT number with no country' => fn (): array => $this->brCo9(),
                'an exemption with no reason is refused' => fn (): array => $this->exemptionReason(),
                'a calculation failure is internal, not customer-facing' => fn (): array => $this->internal(),
                'and the ledger still proves its own invariants' => fn (): array => [],
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function issuedValidates(): array
    {
        $model = $this->model($this->issue());
        $violations = $model->validate();

        if ($violations === []) {
            return [];
        }

        return array_map(
            static fn (SemanticViolation $v): string => sprintf(
                'a document issued through the real path fails %s on %s: %s',
                $v->rule,
                $v->address(),
                (string) $v->detail,
            ),
            $violations,
        );
    }

    /** @return list<string> */
    private function draftIsRefused(): array
    {
        try {
            app(SemanticInvoiceBuilder::class)->build($this->draft());
        } catch (\LogicException $e) {
            return str_contains($e->getMessage(), 'issued')
                ? []
                : ['the refusal does not say why a draft cannot be serialized: '.$e->getMessage()];
        }

        return ['a draft was serialized. It has no number, no frozen supplier and no hash, and the '.
            'document produced would look issuable and not be'];
    }

    /**
     * The frozen snapshot, not the live party.
     *
     * @return list<string>
     */
    private function frozen(): array
    {
        $invoice = $this->issue();
        $was = (string) $invoice->customer_snapshot['legal_name'];

        Party::query()->whereKey((string) $invoice->party_id)->update([
            'legal_name' => 'Renamed After Issue LLC',
        ]);

        $model = $this->model($invoice->refresh());
        $name = $model->group(BG::Buyer)?->get(BT::BuyerName);

        if ($name === $was) {
            return [];
        }

        return [sprintf(
            'the model says the buyer is "%s" and the document was issued to "%s". A customer renamed '.
            'in March must not change January\'s XML: the recipient has archived the January one and '.
            'the two copies would no longer hash the same',
            (string) $name,
            $was,
        )];
    }

    /** @return list<string> */
    private function exponent(): array
    {
        $model = $this->model($this->issue());
        $totals = $model->totals();
        $violations = [];

        foreach ([BT::TotalWithoutVat, BT::TotalVatAmount, BT::TotalWithVat, BT::AmountDueForPayment] as $term) {
            $value = (string) $totals?->get($term);

            if (! preg_match('/^-?\d+\.\d{2}$/', $value)) {
                $violations[] = sprintf(
                    '%s is "%s" and SAR has two decimals. BR-DEC-* rejects an amount with more '.
                    'decimals than the currency has, and the stored 21,4 column is not what goes in '.
                    'the document',
                    $term->value,
                    $value,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function breakdown(): array
    {
        $invoice = $this->issue();
        $model = $this->model($invoice);
        $groups = $model->vatBreakdown();
        $violations = [];

        $stored = DB::connection('tenant')->table('invoice_tax_breakdown')
            ->where('invoice_id', (string) $invoice->getKey())
            ->count();

        if (\count($groups) !== $stored) {
            $violations[] = sprintf(
                'the document stores %d rate groups and the model emits %d',
                $stored,
                \count($groups),
            );
        }

        foreach ($groups as $group) {
            if (! $group->has(BT::VatCategoryCode)) {
                $violations[] = 'a rate group has no category code (BT-118)';
            }

            if (! $group->has(BT::VatCategoryTaxableAmount) || ! $group->has(BT::VatCategoryTaxAmount)) {
                $violations[] = 'a rate group is missing its taxable or tax amount';
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function creditNoteReference(): array
    {
        $invoice = $this->issue();

        $credit = app(Issuer::class)->issue(
            app(Crediting::class)->full($invoice, $this->date),
            $this->entity(),
            $this->actorId(),
            $this->decision,
        );

        $model = $this->model($credit);
        $violations = [];

        if ((string) $model->documentTypeCode !== DocumentType::CreditNote->value) {
            $violations[] = sprintf('the credit note serializes as type %s', $model->documentTypeCode);
        }

        $preceding = $model->groups(BG::PrecedingInvoiceReference);

        if ($preceding === []) {
            return [...$violations, 'the credit note carries no BG-3, so the recipient cannot tell which '.
                'document it reverses - which is EN 16931 BT-25 and the whole point of a credit note'];
        }

        if ((string) $preceding[0]->get(BT::PrecedingInvoiceReference) !== (string) $invoice->number) {
            $violations[] = sprintf(
                'BT-25 says %s and the credited document is %s',
                (string) $preceding[0]->get(BT::PrecedingInvoiceReference),
                (string) $invoice->number,
            );
        }

        $errors = $model->validate();

        foreach ($errors as $error) {
            $violations[] = sprintf('the credit note fails %s: %s', $error->rule, (string) $error->detail);
        }

        return $violations;
    }

    /** @return list<string> */
    private function brCo10(): array
    {
        return $this->tamper('BR-CO-10', function (SemanticNode $root): void {
            $root->first(BG::DocumentTotals)?->set(BT::SumOfLineNetAmounts, '999.00');
        });
    }

    /** @return list<string> */
    private function brCo13(): array
    {
        return $this->tamper('BR-CO-13', function (SemanticNode $root): void {
            $root->first(BG::DocumentTotals)?->set(BT::SumOfCharges, '10.00');
        });
    }

    /** @return list<string> */
    private function brCo14(): array
    {
        return $this->tamper('BR-CO-14', function (SemanticNode $root): void {
            $root->first(BG::DocumentTotals)?->set(BT::TotalVatAmount, '1.00');
        });
    }

    /** @return list<string> */
    private function brCo15(): array
    {
        return $this->tamper('BR-CO-15', function (SemanticNode $root): void {
            $root->first(BG::DocumentTotals)?->set(BT::TotalWithVat, '1.00');
        });
    }

    /** @return list<string> */
    private function brCo16(): array
    {
        return $this->tamper('BR-CO-16', function (SemanticNode $root): void {
            $root->first(BG::DocumentTotals)?->set(BT::PaidAmount, '5.00');
        });
    }

    /** @return list<string> */
    private function brCo17(): array
    {
        return $this->tamper('BR-CO-17', function (SemanticNode $root): void {
            $groups = $root->children(BG::VatBreakdown);
            $groups[0]->set(BT::VatCategoryRate, '7');
        });
    }

    /** @return list<string> */
    private function brCo3(): array
    {
        return $this->tamper('BR-CO-3', function (SemanticNode $root): void {
            $root->set(BT::TaxPointDate, '2026-05-18')->set(BT::TaxPointDateCode, '35');
        });
    }

    /** @return list<string> */
    private function brCo25(): array
    {
        return $this->tamper('BR-CO-25', function (SemanticNode $root): void {
            $root->set(BT::PaymentDueDate, null)->set(BT::PaymentTerms, null);
        });
    }

    /** @return list<string> */
    private function brCo26(): array
    {
        return $this->tamper('BR-CO-26', function (SemanticNode $root): void {
            $seller = $root->first(BG::Seller);
            $seller?->set(BT::SellerVatIdentifier, null)
                ->set(BT::SellerLegalRegistrationIdentifier, null)
                ->set(BT::SellerTaxRegistrationIdentifier, null);
        });
    }

    /** @return list<string> */
    private function brCo9(): array
    {
        return $this->tamper('BR-CO-9', function (SemanticNode $root): void {
            $root->first(BG::Seller)?->set(BT::SellerVatIdentifier, '300000000000003');
        });
    }

    /** @return list<string> */
    private function exemptionReason(): array
    {
        return $this->tamper('BR-E-10', function (SemanticNode $root): void {
            $group = $root->children(BG::VatBreakdown)[0];
            $group->set(BT::VatCategoryCode, 'E')
                ->set(BT::VatExemptionReasonText, null)
                ->set(BT::VatExemptionReasonCode, null);
        });
    }

    /**
     * A calculation failure is an internal error, not a message for a customer.
     *
     * @return list<string>
     */
    private function internal(): array
    {
        $model = $this->model($this->issue());
        $model->root->first(BG::DocumentTotals)?->set(BT::TotalVatAmount, '1.00');

        foreach ($model->validate() as $violation) {
            if ($violation->rule !== 'BR-CO-14') {
                continue;
            }

            return $violation->isInternal()
                ? []
                : ['BR-CO-14 is reported as a customer-facing failure. The document was built from '.
                    'stored figures the ledger has already posted: if the total disagrees with the '.
                    'breakdown, the document and the journal entry disagree, and asking the customer '.
                    'to correct it is asking them to fix our arithmetic'];
        }

        return ['BR-CO-14 did not fire at all'];
    }

    /**
     * Break one thing in the model and require that rule, and only sensible
     * company, to be reported.
     *
     * @return list<string>
     */
    private function tamper(string $rule, callable $break): array
    {
        $model = $this->model($this->issue());

        if ($model->validate() !== []) {
            return ['the untampered document does not validate, so this check proves nothing'];
        }

        $break($model->root);

        $rules = array_map(static fn (SemanticViolation $v): string => $v->rule, $model->validate());

        if (\in_array($rule, $rules, true)) {
            return [];
        }

        return [sprintf(
            '%s did not fire. Reported instead: %s',
            $rule,
            $rules === [] ? 'nothing at all' : implode(', ', array_unique($rules)),
        )];
    }

    private function model(Invoice $invoice): SemanticInvoice
    {
        return app(SemanticInvoiceBuilder::class)->build($invoice);
    }
}
