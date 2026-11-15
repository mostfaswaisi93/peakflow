<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Place of supply - PF-078
|--------------------------------------------------------------------------
|
| Which jurisdiction's tax applies to a supply. The engine in
| App\Tenant\Tax\Services\PlaceOfSupply knows how to evaluate these rows and
| nothing about any country: every country-specific fact is here, with the
| article it comes from written next to it.
|
| ## First match wins, by precedence
|
| Rules are evaluated in ascending `precedence` and the first one whose
| conditions all hold decides. Precedence is explicit rather than array order
| because array order is invisible in a diff: a rule moved up by three lines
| changes the answer for thousands of documents and reads as a reformatting.
|
| Precedence numbers are spaced by ten so a rule can be inserted between two
| without renumbering the file - renumbering is how a rule book silently
| changes meaning.
|
| ## Conditions
|
| Every rule states all five conditions. There is no default: a missing
| condition is refused at load, because the condition somebody forgot to write
| is the condition they did not think about.
|
|   supply_type  goods | services | digital_services | *
|   supplier     * | bloc:<name> | <alpha-2>
|   customer     * | same | abroad | outside | bloc:<name> | <alpha-2>
|   status       business | consumer | *
|   delivery     * | same | abroad | outside | bloc:<name> | <alpha-2>
|
| Each rule also states `evidence`: what must be on record before a document
| carrying this treatment may be issued - PF-080. An empty list is written out
| rather than omitted, for the same reason the conditions are.
|
|   export_proof           the goods left the jurisdiction
|   dispatch_proof         the goods reached another member state
|   customer_registration  a registration valid on the tax point
|
| Any condition may also be a LIST, which matches if any element matches.
|
|   same     the same country as the supplier
|   abroad   any country other than the supplier's
|   outside  another country that shares no bloc with the supplier
|   bloc:x   a member of bloc x, excluding the supplier's own country
|
| `bloc:x` on `supplier` carries no "different" sense - there is nothing for
| the supplier to differ from. On `customer` and `delivery` it excludes the
| supplier's country, so a bloc rule can never also catch the domestic case.
|
| ## Why `abroad` and `outside` are two words
|
| Because the distinction is the whole of intra-bloc treatment. A French
| supplier delivering to Germany is `abroad` and `bloc:eu`; delivering to
| Switzerland is `abroad` and `outside`. One is an intra-Community supply, the
| other an export, and they carry different tax categories, different evidence
| and different return boxes. A single "is it foreign" flag collapses them,
| which is the defect this vocabulary exists to prevent.
|
| ## A missing delivery location refuses
|
| A rule that tests `delivery` cannot be evaluated without one. The engine
| does NOT fall through to a looser rule - falling through IS the guess. It
| refuses and names the rule it could not evaluate.
|
| Defaulting the delivery location to the customer's address is a reasonable
| thing for a DOCUMENT to do, and Phase 05 does it explicitly where an
| accountant can see it. The engine will not do it quietly.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Blocs
    |----------------------------------------------------------------------
    |
    | Country codes are ISO 3166-1 alpha-2 and are validated against the
    | vendored register in database/iso/countries.tsv, so a typo is refused
    | at load rather than silently never matching.
    |
    | Note for anyone comparing this with a VAT system: EU VAT identifiers
    | use `EL` for Greece and `XI` for Northern Ireland. Neither is an ISO
    | 3166-1 code. Greece is `GR` here; Northern Ireland is not a bloc member
    | in its own right and its protocol treatment is not modelled.
    */
    'blocs' => [

        'gcc' => [
            'source' => 'Unified Agreement for Value Added Tax of the Cooperation Council for the Arab States of the Gulf, Art. 1 (definition of Member State)',
            'members' => ['AE', 'BH', 'KW', 'OM', 'QA', 'SA'],

            /*
             | Being party to the Agreement is not the same as having
             | implemented it, and the Agreement's intra-GCC provisions only
             | operate between states that have.
             |
             | This is not modelled as membership because it is not a
             | membership question: it is a transitional question whose answer
             | changes per pair of states and per date. Treating an
             | unimplemented state as a non-member would make an intra-GCC
             | supply look like an export, which is zero-rated - the direction
             | of the error that costs a business money rather than one that
             | shows up as an overpayment.
             |
             | So the rows below place the supply in the destination state and
             | PF-080's evidence requirement is what stops a zero rate being
             | claimed without proof.
             */
            'note' => 'Implementation status per member state is a transitional question and is not modelled here; see docs/domain/place-of-supply.md',
        ],

        'eu' => [
            'source' => 'Council Directive 2006/112/EC, applied to the territory of the Member States as defined in Art. 5-8',
            'members' => [
                'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI',
                'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
                'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
            ],

            /*
             | A departure, kept rather than deleted.
             |
             | A rule book that merely stops listing GB answers "export"
             | correctly today and cannot answer anything about a supply made
             | in 2019 - and a credit note against a 2019 invoice has to be
             | treated the way the original was. The date is the fact; the
             | engine does not use it yet, and PF-087 (post-filing
             | adjustments) is where it starts to.
             */
            'left' => [
                'GB' => '2020-12-31', // End of the transition period, Art. 126 of the Withdrawal Agreement.
            ],
        ],

    ],

    /*
    |----------------------------------------------------------------------
    | Rules
    |----------------------------------------------------------------------
    |
    | `place` says WHOSE country's tax applies, symbolically:
    |
    |   supplier | customer | delivery
    |
    | `category` is the UNCL 5305 code the supply takes, which is what makes
    | the decision reach the invoice line rather than stopping at a country:
    | `AE` puts the liability on the customer, `K` reports an intra-Community
    | supply, `Z` requires export evidence (PF-080).
    */
    'rules' => [

        /*
         | Goods that never leave.
         |
         | First, because it beats every rule about where the customer is. A
         | French customer collecting goods from a Saudi warehouse is a Saudi
         | supply: the goods did not move, and the customer's address is not
         | evidence that they did.
         */
        [
            'precedence' => 10,
            'id' => 'goods-delivered-in-the-supplier-state',
            'supply_type' => 'goods',
            'supplier' => '*',
            'customer' => '*',
            'status' => '*',
            'delivery' => 'same',
            'place' => 'supplier',
            'category' => 'S',
            'evidence' => [],
            'source' => 'Council Directive 2006/112/EC Art. 31 (goods not dispatched); GCC Unified Agreement Art. 10(1)',
        ],

        [
            'precedence' => 20,
            'id' => 'intra-gcc-goods-to-a-business',
            'supply_type' => 'goods',
            'supplier' => 'bloc:gcc',
            'customer' => '*',
            'status' => 'business',
            'delivery' => 'bloc:gcc',
            'place' => 'delivery',
            'category' => 'AE',
            'evidence' => ['customer_registration'],
            'source' => 'GCC Unified Agreement Art. 10(2) (place of supply where goods are dispatched to another Member State) with Art. 9 (customer accounts for the tax)',
        ],

        /*
         | The same supply to a consumer stays with the supplier.
         |
         | Until the supplier's sales into that state cross its registration
         | threshold, at which point the supplier registers there and this row
         | stops describing them. The threshold is a per-state amount and a
         | running total of past sales, which is PF-085's territory; what
         | belongs here is that the two cases are different rules.
         */
        [
            'precedence' => 30,
            'id' => 'intra-gcc-goods-to-a-consumer',
            'supply_type' => 'goods',
            'supplier' => 'bloc:gcc',
            'customer' => '*',
            'status' => 'consumer',
            'delivery' => 'bloc:gcc',
            'place' => 'supplier',
            'category' => 'S',
            'evidence' => [],
            'source' => 'GCC Unified Agreement Art. 10(1) with the Art. 11 mandatory registration threshold in the state of destination',
        ],

        [
            'precedence' => 40,
            'id' => 'intra-eu-goods-to-a-business',
            'supply_type' => 'goods',
            'supplier' => 'bloc:eu',
            'customer' => '*',
            'status' => 'business',
            'delivery' => 'bloc:eu',
            'place' => 'delivery',
            'category' => 'K',
            'evidence' => ['dispatch_proof', 'customer_registration'],
            'source' => 'Council Directive 2006/112/EC Art. 138(1) (exempt intra-Community supply) with Art. 20 (acquisition taxed in the state of arrival)',
        ],

        /*
         | Distance selling, destination from the first euro.
         |
         | The old per-state thresholds were replaced in 2021 by a single
         | EUR 10,000 Union-wide threshold below which the supplier may keep
         | charging its own rate. "May" - it is an option, not a default, and
         | a business that has opted in to OSS is taxed at destination from
         | the first euro. This row states the destination treatment because
         | that is the one that is right whether or not the option is taken;
         | the option is a per-entity setting PF-085 adds.
         */
        [
            'precedence' => 50,
            'id' => 'intra-eu-goods-to-a-consumer',
            'supply_type' => 'goods',
            'supplier' => 'bloc:eu',
            'customer' => '*',
            'status' => 'consumer',
            'delivery' => 'bloc:eu',
            'place' => 'delivery',
            'category' => 'S',
            'evidence' => [],
            'source' => 'Council Directive 2006/112/EC Art. 33(a) as amended by Directive (EU) 2017/2455, with the Art. 59c threshold',
        ],

        /*
         | Export, in two rows, because the two regimes CODE it differently.
         |
         | EN 16931 has a category for an export - `G`, "free export item, VAT
         | not charged" - and a separate one for a domestic zero-rated supply,
         | `Z`. The Saudi regime zero-rates exports instead: Art. 32 of the
         | Implementing Regulations, and a ZATCA invoice carries `Z` with the
         | article as its reason.
         |
         | One row carrying `Z` for everybody was wrong for an EU supplier and
         | one carrying `G` would be wrong for a Saudi one. Which code an
         | export takes is a fact about a regime, so it is two rows - and this
         | is the case that shows why the rule book has to hold the category
         | rather than deriving it from "is it cross-border".
         |
         | Both demand proof that the goods left. An export rate claimed with
         | no evidence of exit is the single most common VAT assessment there
         | is, which is why the requirement is on the rule and not left to a
         | reviewer.
         */
        [
            'precedence' => 55,
            'id' => 'goods-exported-from-the-eu',
            'supply_type' => 'goods',
            'supplier' => 'bloc:eu',
            'customer' => '*',
            'status' => '*',
            'delivery' => 'outside',
            'place' => 'supplier',
            'category' => 'G',
            'evidence' => ['export_proof'],
            'source' => 'Council Directive 2006/112/EC Art. 146(1)(a)-(b), with EN 16931 category G',
        ],

        [
            'precedence' => 60,
            'id' => 'goods-exported',
            'supply_type' => 'goods',
            'supplier' => '*',
            'customer' => '*',
            'status' => '*',
            'delivery' => 'outside',
            'place' => 'supplier',
            'category' => 'Z',
            'evidence' => ['export_proof'],
            'source' => 'KSA VAT Implementing Regulations Art. 32(1); GCC Unified Agreement Art. 35',
        ],

        /*
         | Digital services to a consumer: where the consumer is.
         |
         | The destination principle, and the rule that made a small business
         | selling a download to a consumer in another state liable there. It
         | sits above the general B2C services rule because it is the
         | exception to it.
         */
        [
            'precedence' => 70,
            'id' => 'digital-services-to-a-consumer-abroad',
            'supply_type' => 'digital_services',
            'supplier' => '*',
            'customer' => 'abroad',
            'status' => 'consumer',
            'delivery' => '*',
            'place' => 'customer',
            'category' => 'S',
            'evidence' => [],
            'source' => 'Council Directive 2006/112/EC Art. 58 (telecommunications, broadcasting and electronically supplied services to non-taxable persons); KSA Implementing Regulations Art. 23',
        ],

        /*
         | Services to a business abroad: the customer accounts for it.
         |
         | The general B2B rule, and it covers digital services too - which is
         | why the row above is restricted to consumers. A business customer
         | reverse-charges a download exactly as it reverse-charges consulting.
         */
        [
            'precedence' => 80,
            'id' => 'services-to-a-business-abroad',
            'supply_type' => ['services', 'digital_services'],
            'supplier' => '*',
            'customer' => 'abroad',
            'status' => 'business',
            'delivery' => '*',
            'place' => 'customer',
            'category' => 'AE',
            'evidence' => ['customer_registration'],
            'source' => 'Council Directive 2006/112/EC Art. 44 (general rule, taxable persons) with Art. 196 (customer liable); GCC Unified Agreement Art. 15; KSA Implementing Regulations Art. 24',
        ],

        /*
         | Services to a consumer abroad: where the SUPPLIER is.
         |
         | The one that reads backwards and is correct. The general B2C rule
         | taxes a service where the supplier is established, so a Saudi firm
         | advising a French individual charges Saudi VAT. Only the named
         | exceptions - digital services above, immovable property, transport,
         | admissions - move it, and those are rows this file does not have
         | yet rather than behaviour the engine lacks.
         */
        [
            'precedence' => 90,
            'id' => 'services-to-a-consumer-abroad',
            'supply_type' => 'services',
            'supplier' => '*',
            'customer' => 'abroad',
            'status' => 'consumer',
            'delivery' => '*',
            'place' => 'supplier',
            'category' => 'S',
            'evidence' => [],
            'source' => 'Council Directive 2006/112/EC Art. 45 (general rule, non-taxable persons); GCC Unified Agreement Art. 16',
        ],

        /*
         | Domestic, last.
         |
         | Everything above either requires goods to have moved or a customer
         | abroad, so a supply reaching this row is one where supplier and
         | customer are in the same country. Last rather than first because a
         | domestic rule placed first would catch a domestic customer taking
         | delivery abroad, and that is an export.
         */
        [
            'precedence' => 100,
            'id' => 'domestic',
            'supply_type' => '*',
            'supplier' => '*',
            'customer' => 'same',
            'status' => '*',
            'delivery' => '*',
            'place' => 'supplier',
            'category' => 'S',
            'evidence' => [],
            'source' => 'Council Directive 2006/112/EC Art. 31, 44, 45; GCC Unified Agreement Art. 10, 15, 16',
        ],

    ],

];
