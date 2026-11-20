<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Tax\Enums\CustomerStatus;
use App\Tenant\Tax\Enums\SupplyType;
use App\Tenant\Tax\Exceptions\PlaceOfSupplyUndeterminable;
use App\Tenant\Tax\PlaceOfSupplyDecision;
use App\Tenant\Tax\Services\PlaceOfSupply;
use App\Tenant\Tax\SupplyContext;
use App\Tenant\Tenancy\Models\Entity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The one place that asks the rule book which jurisdiction's tax applies - PF-078.
 *
 * ## Why this exists, which is the finding rather than the design
 *
 * `PlaceOfSupply::decide()` - the eight-point engine with sixteen asserted
 * cases, a vendored bloc register, a precedence integer in each row and a
 * dated UK departure - **was never called by the application**. Seven
 * services in this module wrote `'place_of_supply_rule' => 'domestic'` as a
 * literal, and `InvoiceApiController` handed `['rule' => 'domestic']`
 * straight to the issuer.
 *
 * So every document ever issued recorded a domestic supply, whatever the
 * customer's country and whatever the item type. An export of goods read as
 * a domestic sale on the document; an intra-GCC supply to a business, which
 * the book puts in category `AE` with the customer accounting for the tax,
 * read as a Saudi sale in `S`. The charge on the line came from the tax code
 * somebody picked, so the figures could be right while the recorded
 * **reason** was wrong - and the reason is what an authority asks for.
 *
 * PF-078's last criterion is that the decision and its reasoning are
 * visible on the invoice's tax panel, because an accountant must be able to
 * check it. A panel over a hard-coded literal would show a decision nobody
 * made, which is worse than no panel: it reads as checked.
 *
 * ## Per line, because the supply type is
 *
 * One invoice can carry a good and a digital service, and the book treats
 * them differently on purpose - the whole of the distance-selling and
 * digital-services rows exist for that difference. So each line is decided
 * on its own and carries its own `place_of_supply` and
 * `place_of_supply_rule`, which is what those two columns were always for.
 *
 * ## Undecided is a recorded answer, not a default
 *
 * A line with no supply type cannot be decided: the book's first question
 * is what kind of supply it is. Goods with no delivery country cannot be
 * decided either, because the highest-precedence rule tests where they went
 * and the engine refuses rather than falling through - PF-078's "refuses
 * rather than guesses", measured there with the refusal removed.
 *
 * Neither is turned into `domestic`. The decider records that the line was
 * not decided and why, the panel says so in words, and the accountant reads
 * "line 1 states no supply type" instead of a confident fiction. Issuing is
 * not blocked by it: a free-text line on a domestic invoice is an ordinary
 * thing to send, and refusing it would stop the product working to satisfy
 * a column added after the documents were.
 */
final readonly class SupplyDecider
{
    public function __construct(
        private PlaceOfSupply $engine,
        private CounterpartyRegistrationReader $registrations,
    ) {}

    /**
     * Decide each line, write the two columns, and return what to freeze.
     *
     * The returned array is the `place_of_supply` fragment of
     * `tax_decision_snapshot`: frozen onto the document rather than
     * recomputed, because it is derived from party records and a rule book
     * that will both have changed by the time anybody looks - and the
     * question an accountant asks is what the rule was **then**.
     *
     * @return array<string, mixed>
     */
    public function decide(Invoice $draft, Entity $entity, ?CarbonInterface $on = null): array
    {
        $on = CarbonImmutable::parse(($on ?? $draft->issue_date ?? now())->toDateString());
        $supplierCountry = \is_string($entity->country) ? $entity->country : '';
        $party = $draft->party;

        if ($supplierCountry === '' || $party === null) {
            return $this->undecidedDocument(
                'The supplier country or the customer is not on the document, and the rule book '.
                "asks for both before anything else.",
                'بلد المورّد أو العميل غير مثبت على المستند، وكتاب القواعد يسأل عنهما قبل أي شيء.',
            );
        }

        $customerCountry = \is_string($party->country) ? $party->country : '';

        if ($customerCountry === '') {
            return $this->undecidedDocument(
                sprintf('Customer %s has no country recorded, and every rule in the book tests it.', (string) $party->code),
                sprintf('العميل %s بلا بلد مسجّل، وكل قاعدة في الكتاب تختبره.', (string) $party->code),
            );
        }

        /*
         | Business or consumer, from the registrations rather than a flag.
         |
         | `CustomerStatus`'s own docblock says why there is no third case:
         | an unvalidated number that behaves like a consumer is a case
         | that gets treated like a business. A party with a registration
         | valid ON THE DATE is a business; one whose registration lapsed
         | before it is a consumer for this supply, which is the correct
         | and uncomfortable answer.
         */
        $registrations = $this->registrations->for($party);
        $status = $registrations->validOn($customerCountry, $on) !== null
            ? CustomerStatus::Business
            : CustomerStatus::Consumer;

        $delivery = \is_string($draft->delivery_country) && $draft->delivery_country !== ''
            ? $draft->delivery_country
            : null;

        $lines = [];
        $decided = [];

        /*
         | The relation's own order, not a second one.
         |
         | `Invoice::lines()` already orders by `line_no`, and an appended
         | `orderBy` does not replace it - it is a tiebreaker after it, so
         | the clause reads as if it decided something and decides nothing.
         | An architecture rule says so by name.
         */
        foreach ($draft->lines as $line) {
            $type = $this->supplyTypeOf($line);

            if ($type === null) {
                $lines[] = [
                    'line_no' => (int) $line->line_no,
                    'decided' => false,
                    'why' => 'The line states no supply type, and the rule book asks what kind of '.
                        'supply it is before anything else. Set it on the line, or give the line an '.
                        'item - an item carries its type.',
                    'why_ar' => 'السطر لا يحدد نوع التوريد، وكتاب القواعد يسأل عن نوعه قبل أي شيء. '.
                        'حدّده على السطر، أو اربط السطر ببند - فالبند يحمل نوعه.',
                ];

                continue;
            }

            try {
                $context = $delivery === null
                    ? SupplyContext::withNoDeliveryLocation($type, $supplierCountry, $customerCountry, $status)
                    : SupplyContext::deliveredTo($type, $supplierCountry, $customerCountry, $status, $delivery);

                $decision = $this->engine->decide($context);
            } catch (PlaceOfSupplyUndeterminable $refused) {
                $lines[] = [
                    'line_no' => (int) $line->line_no,
                    'decided' => false,
                    'why' => $refused->getMessage(),
                    'why_ar' => $refused->getMessage(),
                ];

                continue;
            }

            $lines[] = [
                'line_no' => (int) $line->line_no,
                'decided' => true,
                ...$decision->jsonSerialize(),
                'explanation_en' => $decision->explain('en'),
                'explanation_ar' => $decision->explain('ar'),
            ];

            $decided[] = $decision;

            /*
             | Written on the line as well as frozen in the snapshot.
             |
             | The snapshot is what an accountant reads and the columns are
             | what a query filters on - `Issuer` already falls back to
             | `lines()->value('place_of_supply')` in three places when the
             | snapshot has no country, and those fallbacks were reading the
             | literal `'domestic'` nobody decided.
             */
            DB::connection('tenant')->table('invoice_lines')
                ->where('id', (string) $line->getKey())
                ->update([
                    'place_of_supply' => $decision->country,
                    'place_of_supply_rule' => $decision->rule,
                    'updated_at' => now(),
                ]);
        }

        /*
         | The caller's copy of the lines is now stale.
         |
         | Two columns were written under it by query builder, which does
         | not go through the models the relation handed out. `Issuer`
         | validates the same instance immediately afterwards and the
         | validator refuses a line whose place of supply is unresolved -
         | so a cached relation would make it refuse the document this just
         | placed.
         |
         | `unsetRelation` rather than `refresh()`: the draft carries a tax
         | point frozen in memory a few lines earlier, and refreshing the
         | model threw it away. Measured - the legs of one document came out
         | with two different tax points, and the chain stopped verifying.
         */
        $draft->unsetRelation('lines');

        if ($lines === []) {
            return $this->undecidedDocument(
                'The document has no lines, so there is no supply to place.',
                'لا سطور على المستند، فلا توريد يُحدَّد مكانه.',
            );
        }

        $first = $decided[0] ?? null;

        return [
            'decided' => $first instanceof PlaceOfSupplyDecision,
            /*
             | The document-level country and rule are the FIRST decided
             | line's, and `lines` is the whole answer.
             |
             | Three readers outside this module already take
             | `tax_decision_snapshot['country']` as the document's
             | jurisdiction - `Bills`, `SupplierCredits` and `Issuer`'s
             | correction path - so dropping it would make a reverse-charge
             | bill resolve its jurisdiction from a fallback. Where lines
             | disagree, `mixed` says so rather than letting the first line
             | speak for the rest.
             */
            'country' => $first?->country,
            'rule' => $first?->rule,
            'mixed' => \count(array_unique(array_map(
                static fn (PlaceOfSupplyDecision $d): string => $d->rule.'|'.$d->country,
                $decided,
            ))) > 1,
            'decided_on' => $on->toDateString(),
            'customer_status' => $status->value,
            'delivery_country' => $delivery,
            'lines' => $lines,
        ];
    }

    /**
     * The line's supply type: its own column, else its item's.
     *
     * The item fills the line in rather than the line reading the item at
     * display time, because `items.type` is editable and a document is not:
     * an item retyped from `good` to `service` next year must not change
     * what an issued invoice says it was.
     */
    private function supplyTypeOf(object $line): ?SupplyType
    {
        $stated = \App\Tenant\Sales\SupplyTypes::of(
            \is_string($line->supply_type ?? null) ? (string) $line->supply_type : null,
            $line->item_id === null ? null : (string) $line->item_id,
        );

        /*
         | `items.type` is singular - `good` - and `SupplyType` is plural.
         |
         | Two vocabularies for one fact, which is the mapping the migration
         | warns about; it exists because the enum was written for the rule
         | book's `supply_type` keys and the column for a catalogue. Mapped
         | in exactly one place, here, so a third spelling cannot appear.
         */
        return match ($stated) {
            'good', 'goods' => SupplyType::Goods,
            'service', 'services' => SupplyType::Services,
            'digital_service', 'digital_services' => SupplyType::DigitalServices,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function undecidedDocument(string $why, string $whyAr): array
    {
        return [
            'decided' => false,
            'country' => null,
            'rule' => null,
            'mixed' => false,
            'why' => $why,
            'why_ar' => $whyAr,
            'lines' => [],
        ];
    }
}
