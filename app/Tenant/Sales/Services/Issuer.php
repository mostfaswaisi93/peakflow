<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Authorization\SegregatesDuties;
use App\Support\Hashing\Canonicalizer;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Tenant\Catalog\Services\ExchangeRates;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Exceptions\IssueRefused;
use App\Tenant\Sales\InvoiceFinding;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Posting\Rules\FinalInvoiceIssuedRule;
use App\Tenant\Tax\Exceptions\TaxPointUndeterminable;
use App\Tenant\Tax\Services\EvidenceGate;
use App\Tenant\Tax\Services\ReturnPeriods;
use App\Tenant\Tax\Services\TaxPoint;
use App\Tenant\Tax\TaxPointDecision;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The issuing transaction - PF-095, PF-093, PF-096.
 *
 * ## Five concerns, one transaction
 *
 * In this order, and the order is the design:
 *
 *   0. derive the tax point     - once, before anything validates against it
 *   1. validate                 - refuse while nothing has been consumed
 *   2. freeze the snapshots     - the parties, the terms, the tax decision
 *   3. post to the ledger       - synchronously, inside the transaction
 *   4. allocate the number      - LAST, so the series lock is held briefly
 *   5. write the chain link     - over the canonical, legally significant form
 *
 * Then commit, and only then dispatch anything that touches the outside world.
 *
 * ## Why the number is allocated last - PF-093
 *
 * The series row is locked with `SELECT ... FOR UPDATE` from the moment it is
 * read until the transaction commits. Allocate first and that lock is held
 * through validation, the tax computation and the posting - so every other
 * invoice in the entity waits behind the slowest document anybody is issuing.
 *
 * Allocating last makes the critical section the last few milliseconds of the
 * transaction instead of all of it.
 *
 * ## Why a `SEQUENCE` cannot be used
 *
 * `nextval()` is deliberately non-transactional: it does not roll back. A
 * validation failure, a deadlock or a crashed worker would each leave a number
 * consumed and never used, and a gap in an invoice series is a finding in a
 * tax audit - the auditor's question is not "why is 41 missing" but "what did
 * you do with invoice 41". The counter is therefore a row.
 *
 * ## No I/O inside the lock
 *
 * Nothing between the allocation and the commit may talk to anything: no PDF
 * render, no clearance call, no mail, no queue dispatch. A tax authority that
 * takes thirty seconds to answer must not hold the invoice sequence for thirty
 * seconds, and a mail server that hangs must not stop the business invoicing.
 *
 * The post-commit work is returned to the caller rather than dispatched here,
 * so there is no `dispatch()` in this file at all - which is a property a
 * check can read.
 */
final class Issuer
{
    public function __construct(
        private readonly InvoiceValidator $validator,
        private readonly SupplyDecider $supply,
        private readonly Snapshots $snapshots,
        private readonly NumberAllocator $numbers,
        private readonly Poster $poster,
        private readonly AuditTrail $audit,
        private readonly SegregatesDuties $duties,
        private readonly RevenueRecognition $recognition,
        private readonly TaxPoint $taxPoint,
        private readonly ReturnPeriods $returnPeriods,
        private readonly EvidenceGate $evidence,
    ) {}

    /**
     * Issue a draft.
     *
     * @param  array<string, mixed>  $taxDecision  the place-of-supply decision as resolved at draft time
     *
     * @throws IssueRefused
     */
    public function issue(
        Invoice $draft,
        Entity $entity,
        string $userId,
        array $taxDecision,
        ?\App\Tenant\Catalog\Models\PaymentTerm $terms = null,
    ): Invoice {
        /*
         | `invoice.issue` is kept at any sample rate - PF-240.
         |
         | Named in `config/telemetry.php`, emitted here, and wrapped around
         | the whole act rather than opened inside the transaction: issuing
         | is the operation whose duration somebody asks about, and the
         | numbering allocation and the refusals before the transaction are
         | part of it.
         */
        return app(\App\Support\Telemetry\Tracer::class)->measure(
            'invoice.issue',
            \App\Support\Telemetry\SpanKind::Internal,
            [
                /*
                 | The enum's VALUE, never the enum.
                 |
                 | `document_type` is cast to `DocumentType`, and `(string)`
                 | on it throws - which took eleven sales checks down with
                 | "could not be converted to string" the first time this
                 | span was added. A span is diagnostic and must not be able
                 | to fail the thing it is measuring, so the one place it
                 | reads a model attribute reads it defensively.
                 */
                'document_type' => $draft->document_type instanceof \App\Tenant\Sales\Enums\DocumentType
                    ? $draft->document_type->value
                    : (is_scalar($draft->document_type) ? (string) $draft->document_type : null),
                'currency' => is_scalar($draft->currency) ? (string) $draft->currency : null,
                'jurisdiction' => is_string($taxDecision['country'] ?? null) ? $taxDecision['country'] : null,
            ],
            fn (): Invoice => $this->issueNow($draft, $entity, $userId, $taxDecision, $terms),
        );
    }

    /**
     * @param  array<string, mixed>  $taxDecision
     */
    private function issueNow(
        Invoice $draft,
        Entity $entity,
        string $userId,
        array $taxDecision,
        ?\App\Tenant\Catalog\Models\PaymentTerm $terms = null,
    ): Invoice {
        if ($draft->isIssued()) {
            throw IssueRefused::alreadyIssued((string) $draft->number);
        }

        return DB::connection('tenant')->transaction(function () use (
            $draft, $entity, $userId, $taxDecision, $terms,
        ): Invoice {
            /*
             | Read the row before hashing anything over it.
             |
             | A model built by `create()` does not know the column defaults:
             | `prepaid_amount` is `0` in the database and absent in memory,
             | so the chain payload hashed a null where the verifier - which
             | reads the stored row - sees "0.0000". Measured on the first
             | chain walk: three genuine documents reported as diverging at
             | `totals.prepaid`, "it was (absent) and is now 0.0000".
             |
             | The frozen bytes have to describe the stored row, because the
             | stored row is what anybody will ever re-read.
             */
            $draft->refresh();

            /*
             | 0. Whose duty this is - PF-033.
             |
             | Before validation, because being refused for a reason about
             | the person is not a finding about the document: a validator
             | that reported "you may not credit an invoice you issued"
             | alongside "line 3 has no tax code" would invite somebody to
             | fix the lines and try again.
             |
             | The subject is the document this one ACTS ON, not this one:
             | the rule is "whoever issued invoice 41 must not credit invoice
             | 41", and object scope compares subject ids. A document that
             | acts on nothing - an ordinary invoice - is its own subject,
             | which conflicts with nothing and is the right answer.
             */
            $this->duties->assert(
                duty: $draft->document_type->duty(),
                subjectType: 'invoice',
                subjectId: $this->dutySubject($draft),
                partyId: (string) $draft->party_id,
                subjectLabel: $draft->document_type->label('en'),
            );

            /*
             | 0a. The tax point, derived by the jurisdiction's rule - PF-077.
             |
             | This used to be `$draft->tax_point ?? $draft->issue_date`,
             | taken later in the transaction, which is the one fallback
             | PF-077 exists to forbid. It is silent, it is always available,
             | and it is plausible: a delivery-date regime would have filed a
             | supply made on the 30th in the month the paperwork was raised,
             | and nothing on the document would have said which date decided.
             |
             | Derived ONCE, because re-deriving later reads a jurisdiction
             | profile that may have changed - which would move a document
             | between returns, retrospectively, after one of them was filed.
             |
             | Before validation, and written to the draft, because the
             | validator checks the tax point against two things that matter:
             | the return period it falls in (PF-085 refuses one that has
             | been filed) and the customer's registration as it stood on
             | that date. Derive afterwards and those checks pass on the date
             | somebody typed while the document is frozen with another one -
             | a refusal that reports it was satisfied.
             |
             | A refusal of its own rather than a finding, because it is the
             | input to those checks: there is no return period to check for
             | a date that could not be derived. The message is shaped like
             | the validator's so it reads the same way to a caller.
             */
            $taxPointDecision = $this->deriveTheTaxPoint($draft);
            $taxPoint = Carbon::parse($taxPointDecision->date->toDateString());

            $this->freezeTheTaxPoint($draft, $taxPoint);

            /*
             | 0c. Which jurisdiction's tax applies, asked of the rule book - PF-078.
             |
             | Before the validator, because the validator refuses a line
             | whose place of supply is unresolved - `place_of_supply_unresolved`
             | has been in `InvoiceValidator` since PF-091 - and that
             | refusal is only meaningful once something has tried to
             | resolve it.
             |
             | Nothing tried, until now. `PlaceOfSupply::decide()` had no
             | caller anywhere in the application: seven draft paths wrote
             | `'place_of_supply_rule' => 'domestic'` as a literal, three of
             | them with `'SA'` beside it, and the API handed the issuer
             | `['rule' => 'domestic']`. So every document recorded a
             | domestic supply, the gate above was satisfied by the literal
             | that caused the problem, and the eight-point engine existed
             | only in its own test suite.
             |
             | The decision is frozen onto the document in the snapshot
             | below rather than recomputed: it is derived from party
             | records and a rule book that will both have changed by the
             | time anybody asks, and the question an accountant asks is
             | what the rule was THEN.
             */
            $placeOfSupply = $this->supply->decide($draft, $entity, $draft->issue_date);

            /*
             | 1. Validate, while nothing has been consumed.
             |
             | Inside the transaction rather than before it, because the
             | period could close and a registration could lapse between a
             | check and a write - and the check that passed is the one
             | somebody would quote afterwards.
             */
            $findings = $this->validator->blocking($this->validator->validate($draft, $entity));

            if ($findings !== []) {
                throw IssueRefused::theDraftIsNotValid(array_map(
                    static fn (InvoiceFinding $finding): string => $finding->field.': '.$finding->messageEn,
                    $findings,
                ));
            }

            /*
             | 1a. The evidence the treatment needs, on record - PF-080.
             |
             | A zero rate, an exemption and an out-of-scope supply all show
             | nothing in the tax column, and each one has to be defensible
             | years later: an export rate claimed on the strength of a
             | foreign billing address, with nothing showing the goods ever
             | moved, is the most commonly assessed error there is.
             |
             | The gate has existed since PF-080 and had no caller, which
             | made it a comment. Called here because this is the moment the
             | document stops being editable: before, somebody can hold it
             | for a day while the customs declaration is found; after, the
             | customer holds a copy and a return may already include it.
             |
             | Against the decision being FROZEN, not a recomputed one. What
             | the gate checked and what the document claims have to be the
             | same array, or the refusal was about a different document.
             */
            $this->evidence->assertRecordedOn($taxDecision);

            $jurisdiction = (string) ($taxDecision['country'] ?? $entity->country);

            /*
             | And the return period that date falls into - PF-077, PF-085.
             |
             | A different calendar from the accounting period: this document
             | posts into the period its issue date falls in and is REPORTED
             | in the period its tax point falls in, and a monthly return on
             | a quarterly-closing entity makes the two differ as a matter of
             | course. Both are stored, and neither is derived from the other.
             |
             | Null when no period covers the date, which happens for a
             | jurisdiction nobody has generated periods for. Visibly unknown
             | rather than quietly attributed to the wrong return; the
             | validator has already refused the case that matters, which is
             | a tax point in a period somebody has FILED.
             */
            $returnPeriod = $this->returnPeriods->forTaxPoint(
                (string) $entity->getKey(),
                $jurisdiction,
                $taxPointDecision->date,
            );

            // 2. Freeze.
            $snapshot = [
                'supplier_snapshot' => $this->snapshots->supplier($entity, $jurisdiction, $taxPoint),
                'customer_snapshot' => $this->snapshots->customer(
                    $draft->party ?? throw IssueRefused::theDraftIsNotValid(['party_id: no customer']),
                    $jurisdiction,
                    $taxPoint,
                ),

                /*
                 | The place-of-supply decision the caller resolved, plus the
                 | tax point decision this transaction derived.
                 |
                 | The whole decision and not just its date: the basis, which
                 | candidate date won and the profile it came from. An
                 | accountant disagreeing with a tax point never asks what
                 | date was chosen - they ask why, and a bare date makes them
                 | reconstruct the rule from the other dates on the document.
                 | They will reconstruct it differently.
                 */
                'tax_decision_snapshot' => array_merge($taxDecision, [
                    'tax_point' => $taxPointDecision->jsonSerialize(),
                    /*
                     | And the place of supply, with its reasoning - PF-078.
                     |
                     | The winning rule's id, its precedence, the legal
                     | source it cites, the category, whether the customer
                     | accounts for the tax and what evidence the treatment
                     | needs - per line, because one invoice can carry a
                     | good and a digital service and the book treats them
                     | differently on purpose.
                     |
                     | An accountant disagreeing with a place of supply does
                     | not ask which country was chosen; they ask which rule
                     | chose it and what it cites. A bare country makes them
                     | reconstruct the decision from the party records, which
                     | will have changed.
                     */
                    'place_of_supply' => $placeOfSupply,
                ]),
                'terms_snapshot' => $terms === null
                    ? $draft->terms_snapshot
                    : $this->snapshots->terms($terms, $entity, $draft->issue_date, $draft->gross()),
            ];

            // 3. Post, synchronously.
            $entry = $this->post($draft, $entity, $userId);

            /*
             | 4. The number. Last.
             |
             | Everything that can fail has already failed by here, so the
             | series lock is held for the remainder of this transaction -
             | which is the chain write and the commit.
             */
            $number = $this->numbers->next(
                (string) $entity->getKey(),
                'sales_document',
                $draft->series_key,
                Carbon::parse($draft->issue_date->toDateString()),
            );

            // 5. The chain link, over the canonical legally significant form.
            $chain = $this->chainLink($draft, $entity, $number, $snapshot);

            $draft->update(array_merge($snapshot, [
                'status' => 'issued',
                'number' => $number,
                'tax_point' => $taxPoint->toDateString(),
                'return_period_label' => $returnPeriod?->label,
                'issued_at' => now(),
                'issued_by' => $userId,
                'canonical' => $chain['canonical'],
                'canonical_hash' => $chain['hash'],
                'previous_hash' => $chain['previous'],
                'chain_index' => $chain['index'],
            ]));

            /*
             | The recognition plan, after the posting - PF-107.
             |
             | After, because a plan for a document that failed to post is a
             | plan for a document that does not exist; inside the same
             | transaction, because a document that deferred revenue with no
             | plan is a liability nobody will ever release.
             */
            $this->recognition->plan($draft->refresh());

            $this->audit->record(
                category: 'sales',
                action: $draft->document_type->auditAction(),
                subjectType: 'invoice',
                subjectId: (string) $draft->getKey(),
                subjectLabel: $number,
                after: [
                    'number' => $number,
                    'gross' => (string) $draft->gross(),
                    'hash' => $chain['hash'],
                    'chain_index' => $chain['index'],
                    'journal_entry' => (string) $entry->getKey(),
                ],
                entityId: (string) $entity->getKey(),
            );

            $issued = $draft->refresh();

            /*
             | The event, inside the transaction - PF-048.
             |
             | A posting listener on this commits with the invoice or does
             | not happen; a posting that is eventually consistent with the
             | document that caused it is not a ledger. Anything that is
             | not a posting is a `SideEffect`, queued, and cannot fail the
             | issue - and the split is a build failure either way.
             |
             | A credit note and an invoice are different events rather
             | than one with a type field, because a single event means
             | every listener starts with a branch, and the first one
             | written without it treats a credit as a sale.
             */
            $gross = \App\Support\Money\Money::of(
                (string) $issued->gross_amount,
                (string) $issued->currency,
            );

            $type = $issued->document_type instanceof \App\Tenant\Sales\Enums\DocumentType
                ? $issued->document_type
                : \App\Tenant\Sales\Enums\DocumentType::tryFrom((string) $issued->document_type);

            event($type?->isCredit() === true
                ? new \App\Tenant\Sales\Events\CreditNoteIssued(
                    entityId: (string) $entity->getKey(),
                    creditNoteId: (string) $issued->getKey(),
                    number: (string) $issued->number,
                    partyId: (string) $issued->party_id,
                    gross: $gross,
                    issuedOn: (string) $issued->issue_date?->toDateString(),
                    creditsInvoiceId: $this->creditedInvoiceId($issued),
                    byUserId: $userId,
                )
                : new \App\Tenant\Sales\Events\InvoiceIssued(
                    entityId: (string) $entity->getKey(),
                    invoiceId: (string) $issued->getKey(),
                    number: (string) $issued->number,
                    partyId: (string) $issued->party_id,
                    gross: $gross,
                    issuedOn: (string) $issued->issue_date?->toDateString(),
                    byUserId: $userId,
                ));

            return $issued;
        });
    }

    /**
     * The invoice a credit note names, if it names one.
     *
     * Null is a real answer: a goodwill credit with no invoice behind it
     * is an ordinary document, not a missing reference.
     */
    private function creditedInvoiceId(Invoice $document): ?string
    {
        $reference = DB::connection('tenant')->table('invoice_references')
            ->where('invoice_id', (string) $document->getKey())
            ->value('referenced_invoice_id');

        return $reference === null ? null : (string) $reference;
    }

    /**
     * The tax point this document's jurisdiction rule produces - PF-077.
     *
     * Two of the three dates go in and the profile decides which comes out:
     * the issue date (`BT-2`) and the delivery date (`BT-72`).
     *
     * ## No payment date, and that is why a payment basis refuses here
     *
     * The sell side does not know when money arrived - a receipt is
     * `Settlement`'s, and `Settlement` depends on `Sales` rather than the
     * other way round, which is the dependency that keeps one answer to
     * "what was this document settled by". Passing anything else in its
     * place would be the guess PF-077 is about: the prepayment invoice's own
     * date is the nearest plausible value and is not the date money arrived.
     *
     * So a profile configured for a payment basis refuses every document
     * until the date reaches the document. That is loud, it names the date it
     * wanted, and it is what PF-099's `PrepaymentTaxPoint` already decides
     * separately for the one case where the sell side does know - money taken
     * before performance.
     *
     * A basis whose input is missing REFUSES, through `TaxPoint`. Translated
     * to `IssueRefused` here because this is the issuing boundary and because
     * the message is actionable: it names the date the document needs rather
     * than the rule that wanted it.
     *
     * @throws IssueRefused
     */
    private function deriveTheTaxPoint(Invoice $draft): TaxPointDecision
    {
        $profile = (string) ($draft->profile ?? config('jurisdictions.default', 'generic'));

        try {
            return $this->taxPoint->derive(
                profile: $profile,
                invoiceDate: $draft->issue_date,
                deliveryDate: $draft->delivery_date,
            );
        } catch (TaxPointUndeterminable $e) {
            throw IssueRefused::theDraftIsNotValid(['tax_point: '.$e->getMessage()]);
        }
    }

    /**
     * Put the derived date on the header and on every line that carries a code.
     *
     * Only those lines. `journal_lines` pairs a tax point with a tax code by
     * a CHECK - that pairing is what makes a return complete by construction
     * - so a line with no code must keep a null tax point or its journal line
     * cannot be written at all.
     *
     * ## One date, on both legs
     *
     * The posting takes the tax leg's tax point from the header and each
     * revenue leg's from its line. Leave the two to disagree and one
     * document's taxable base lands in one return period while its tax lands
     * in another: a box carrying tax with no base in the first period, and a
     * base with no tax in the second. Both figures are individually
     * plausible and the return is wrong in two places.
     *
     * The header is set in memory and persisted by the single update at the
     * end of the transaction; the lines are written now, because the
     * validator and the posting both re-read them from the database rather
     * than from this model. A refusal after this point rolls the whole
     * transaction back, lines included.
     */
    private function freezeTheTaxPoint(Invoice $draft, Carbon $taxPoint): void
    {
        $draft->tax_point = $taxPoint;

        InvoiceLine::query()
            ->where('invoice_id', (string) $draft->getKey())
            ->whereNotNull('tax_code_id')
            ->update(['tax_point' => $taxPoint->toDateString()]);
    }

    /**
     * Which rule posts this document.
     *
     * The type decides, except for an invoice carrying a prepayment: the
     * receivable is then only the balance, the revenue is the whole supply
     * and the tax is the increment, which is a different entry and so a
     * different rule. Deciding it here rather than in the enum because the
     * enum cannot see a document's references, and a rule chosen from the
     * type alone would post the full gross again - showing the customer
     * owing for one supply twice.
     */
    private function postingRuleFor(Invoice $draft): string
    {
        if ($draft->document_type === DocumentType::Invoice && $this->prepaymentOn($draft) !== null) {
            return FinalInvoiceIssuedRule::KEY;
        }

        return $draft->document_type->postingRuleKey();
    }

    private function prepaymentOn(Invoice $draft): ?Invoice
    {
        $id = $draft->references()
            ->where('kind', 'prepayment')
            ->value('referenced_invoice_id');

        return $id === null ? null : Invoice::query()->find((string) $id);
    }

    /** The invoice a credit note credits, if it credits one. */
    private function creditedInvoice(Invoice $draft): ?Invoice
    {
        $id = $draft->references()
            ->where('kind', 'credits')
            ->value('referenced_invoice_id');

        return $id === null ? null : Invoice::query()->find((string) $id);
    }

    /**
     * The figures of the document a correction replaces.
     *
     * @return array{gross: string, revenue: list<array<string, mixed>>, tax: list<array<string, mixed>>}
     */
    private function replacedFigures(Invoice $draft, Entity $entity): array
    {
        $replacedId = $draft->references()
            ->where('kind', 'corrects')
            ->value('referenced_invoice_id');

        $replaced = $replacedId === null ? null : Invoice::query()->find((string) $replacedId);

        if ($replaced === null) {
            throw IssueRefused::theDraftIsNotValid([
                'references: a corrected invoice must reference the invoice it replaces (EN 16931 BT-25)',
            ]);
        }

        $revenue = [];

        foreach ($replaced->lines()->get() as $line) {
            $revenue[] = [
                'account_id' => (string) $line->revenue_account_id,
                'amount' => $line->net_amount,
                'tax_code_id' => $line->tax_code_id === null ? null : (string) $line->tax_code_id,
                'tax_point' => $line->tax_point?->toDateString(),
            ];
        }

        $tax = [];

        foreach ($replaced->taxBreakdown()->get() as $row) {
            $tax[] = [
                'jurisdiction' => (string) ($replaced->tax_decision_snapshot['country']
                    ?? $replaced->lines()->value('place_of_supply')
                    ?? $entity->country),
                'amount' => $row->tax_amount,
                'tax_code_id' => (string) $replaced->lines()
                    ->where('tax_category', $row->category->value)
                    ->value('tax_code_id'),

                /*
                 | The REPLACEMENT's tax point, not the original's.
                 |
                 | The reversal belongs in the period the correction is
                 | reported in: putting it back in the original's period
                 | changes a return that has been filed, which is PF-087's
                 | procedure and not a posting's decision.
                 */
                'tax_point' => ($draft->tax_point ?? $draft->issue_date)->toDateString(),
            ];
        }

        return [
            'gross' => (string) $replaced->gross_amount,
            'revenue' => $revenue,
            'tax' => $tax,
        ];
    }

    /**
     * The document a duty is asserted against.
     *
     * A credit note's duty is about the invoice it credits. An invoice's is
     * about itself, so nothing conflicts - which is the correct answer rather
     * than an exemption: nobody has issued it yet.
     */
    private function dutySubject(Invoice $draft): string
    {
        $kind = $draft->document_type->referenceKind();

        if ($kind === null) {
            return (string) $draft->getKey();
        }

        $referenced = $draft->references()
            ->where('kind', $kind)
            ->whereNotNull('referenced_invoice_id')
            ->value('referenced_invoice_id');

        return (string) ($referenced ?? $draft->getKey());
    }

    /**
     * What has to happen after the commit, for the caller to dispatch.
     *
     * Returned rather than dispatched, so this class never queues anything
     * inside the transaction - a job that ran before the commit would read a
     * document that does not exist yet, and one dispatched inside a
     * transaction that then rolls back is a job about a document that never
     * existed.
     *
     * @return list<array{job: string, invoice_id: string}>
     */
    public function afterCommit(Invoice $invoice): array
    {
        return [
            ['job' => 'render.pdf', 'invoice_id' => (string) $invoice->getKey()],
            ['job' => 'deliver', 'invoice_id' => (string) $invoice->getKey()],
            ['job' => 'clearance.submit', 'invoice_id' => (string) $invoice->getKey()],
        ];
    }

    /**
     * The legally significant fields, canonicalized - PF-096.
     *
     * NOT the whole row. A cosmetic change must not invalidate a chain and a
     * material one must not avoid it, so the hash covers the parties, the
     * dates, the currency, the totals, the tax breakdown and each line's
     * substance - and not the note, the timestamps or anything about
     * presentation.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{canonical: string, hash: string, previous: string, index: int}
     */
    private function chainLink(Invoice $draft, Entity $entity, string $number, array $snapshot): array
    {
        $previous = Invoice::query()
            ->where('entity_id', (string) $entity->getKey())
            ->where('series_key', $draft->series_key)
            ->whereNotNull('chain_index')
            ->orderByDesc('chain_index')
            ->first();

        $genesis = str_repeat('0', 64);

        $payload = [
            'number' => $number,
            'series' => $draft->series_key,
            'type' => $draft->document_type->value,
            'issue_date' => $draft->issue_date->toDateString(),

            /*
             | The date the tax point was DERIVED FROM, not only the tax
             | point - PF-077.
             |
             | A derivation whose output is frozen and whose input is not is
             | half frozen: the stored basis would say "the delivery date
             | won" and nothing in the bytes would say what that date was,
             | so a re-check six years later could not re-run the rule. The
             | row is immutable by trigger, which stops the application
             | editing it; the bytes are what catch a write that went round
             | the application.
             */
            'delivery_date' => $draft->delivery_date?->toDateString(),
            'tax_point' => ($draft->tax_point ?? $draft->issue_date)->toDateString(),
            'currency' => $draft->currency,

            /*
             | Which form it was issued in - PF-101.
             |
             | Material, not cosmetic: a simplified invoice and a full one
             | carry different obligations, and a document changed from one
             | to the other after issue has to break the chain.
             */
            'simplified' => (bool) $draft->simplified,
            'totals' => [
                'net' => $draft->net_amount,
                'tax' => $draft->tax_amount,
                'gross' => $draft->gross_amount,
                'prepaid' => $draft->prepaid_amount,
                'payable' => $draft->payable_amount,
            ],
            'supplier' => $snapshot['supplier_snapshot'],
            'customer' => $snapshot['customer_snapshot'],
            'tax_decision' => $snapshot['tax_decision_snapshot'],
            'lines' => $draft->lines()->get()->map(static fn (InvoiceLine $line): array => [
                'line_no' => $line->line_no,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'net' => $line->net_amount,
                'category' => $line->tax_category->value,
                'rate' => $line->tax_rate,
            ])->all(),
            'breakdown' => $draft->taxBreakdown()->get()
                ->map(static fn (object $row): array => [
                    'category' => $row->category->value,
                    'rate' => $row->rate,
                    'taxable' => $row->taxable_amount,
                    'tax' => $row->tax_amount,
                ])->all(),
            'previous' => $previous?->canonical_hash ?? $genesis,
        ];

        $canonical = Canonicalizer::encode($payload);

        return [
            'canonical' => $canonical,
            'hash' => hash('sha256', $canonical),
            'previous' => $previous?->canonical_hash ?? $genesis,
            'index' => ($previous?->chain_index ?? 0) + 1,
        ];
    }

    /**
     * The rate the document was invoiced at, rebuilt from the document.
     *
     * ## Why it is read and not fetched again
     *
     * The rate on the paper the customer holds is the rate the books must
     * use. A second lookup at posting time could return a different one -
     * the feed updates, the document was issued yesterday - and then the
     * ledger and the invoice disagree about what the sale was worth, with
     * nothing on either to say which is right.
     *
     * `InvoiceValidator` already refuses a foreign document with no frozen
     * rate, so by the time a draft reaches here the three columns are
     * filled. Null here therefore means one thing only: the document is in
     * the currency the books are kept in, and there is no translation.
     */
    private function rateToPostAt(Invoice $draft, Entity $entity): ?Rate
    {
        $functional = (string) $entity->functional_currency;
        $currency = (string) $draft->currency;

        if ($currency === $functional || $draft->fx_rate === null) {
            return null;
        }

        return new Rate(
            from: $currency,
            to: $functional,
            value: (string) $draft->fx_rate,
            /*
             | The rate's OWN date, not the document's.
             |
             | A rate is effective-dated, so Friday's is a legitimate answer
             | for a Saturday invoice. Recording the question rather than
             | the answer points an auditor at a row that does not exist.
             */
            date: ($draft->fx_rate_date ?? $draft->issue_date)->toDateTimeImmutable(),
            provider: (string) ($draft->fx_source ?? ExchangeRates::PROVIDER_MANUAL),
            rateSet: Rate::SET_MARKET,
        );
    }

    private function post(Invoice $draft, Entity $entity, string $userId): JournalEntry
    {
        $revenue = [];

        $credited = $draft->document_type === DocumentType::CreditNote
            ? $this->creditedInvoice($draft)
            : null;

        foreach ($draft->lines()->get() as $line) {
            $basis = $line->recognitionBasis();

            /*
             | A credit note against a partially recognised invoice - PF-107.
             |
             | Three months of a twelve-month contract are revenue and nine
             | are still a liability. Debiting revenue for the whole amount
             | overstates the reversal by three months of income; debiting
             | the deferral for the whole amount overstates revenue by the
             | same. Both leave the control account unreconcilable, in
             | opposite directions - so the split is computed from the plan
             | and the rule posts both halves.
             */
            if ($credited !== null) {
                $split = $this->recognition->unwind(
                    $credited,
                    Money::of((string) $line->net_amount, (string) $draft->currency),
                    $line->credits_line_id === null
                        ? null
                        : InvoiceLine::query()->find((string) $line->credits_line_id),
                );

                if (Money::of($split['deferred'], (string) $draft->currency)->isPositive()) {
                    $revenue[] = [
                        'account_id' => (string) $line->revenue_account_id,
                        'amount' => $split['recognised'],
                        'deferred_amount' => $split['deferred'],
                        'tax_code_id' => $line->tax_code_id === null ? null : (string) $line->tax_code_id,
                        'tax_point' => $line->tax_point?->toDateString(),
                        'dimensions' => [],
                    ];

                    continue;
                }
            }

            $revenue[] = [
                'account_id' => (string) $line->revenue_account_id,
                'amount' => $line->net_amount,
                'tax_code_id' => $line->tax_code_id === null ? null : (string) $line->tax_code_id,
                'tax_point' => $line->tax_point?->toDateString(),
                'dimensions' => array_values(array_filter([
                    $line->dimension_1, $line->dimension_2, $line->dimension_3, $line->dimension_4,
                ])),

                /*
                 | Whether this line's revenue is earned yet - PF-107.
                 |
                 | A deferred line credits `deferred_revenue` instead of its
                 | revenue account: money invoiced for work not yet done is
                 | a contract liability under IFRS 15, and crediting revenue
                 | now overstates income in a period that may be closed,
                 | reported and filed by the time anybody notices.
                 */
                'deferred' => $basis->defersRevenue(),
            ];
        }

        $tax = [];

        foreach ($draft->taxBreakdown()->get() as $row) {
            if (Money::of($row->tax_amount, $draft->currency)->isZero()) {
                continue;
            }

            $tax[] = [
                'jurisdiction' => (string) ($draft->tax_decision_snapshot['country']
                    ?? $draft->lines()->value('place_of_supply')
                    ?? $entity->country),
                'amount' => $row->tax_amount,
                'tax_code_id' => (string) $draft->lines()
                    ->where('tax_category', $row->category->value)
                    ->value('tax_code_id'),
                'tax_point' => ($draft->tax_point ?? $draft->issue_date)->toDateString(),
            ];
        }

        $event = [
            'invoice_id' => (string) $draft->getKey(),
            'party_id' => (string) $draft->party_id,
            'gross' => $draft->gross_amount,
            /*
             | The document's currency and its frozen rate - PF-082.
             |
             | Without these the rule labelled the DOCUMENT's figures with
             | the ENTITY's currency, so a 1,000 EUR invoice credited 1,000
             | riyals to revenue. The entry balanced, because every line was
             | wrong by the same factor - which is what made it invisible to
             | the trial balance, to `ledger:verify` and to the database
             | trigger that refuses a foreign line with no rate, since every
             | line claimed to be functional and had nothing to object to.
             |
             | The validator already refuses a foreign document with no
             | frozen rate, so by the time anything reaches here the rate is
             | on the document. It is read rather than re-fetched: the rate
             | the customer was invoiced at is the rate the books must use,
             | and a second lookup could disagree with the paper.
             */
            'currency' => (string) $draft->currency,
            'rate' => $this->rateToPostAt($draft, $entity),
            'narration' => sprintf('%s %s', $draft->document_type->label('en'), $draft->series_key),
            'revenue' => $revenue,
            'tax' => $tax,
        ];

        /*
         | A replacement carries what it replaces - PF-098.
         |
         | Read here rather than in the rule, because a posting rule that
         | loaded a document would make the ledger depend on Sales, and the
         | layering ruleset does not permit that. It also means the posting
         | cannot disagree with the document: both halves come from the same
         | read, in the same transaction.
         */
        if ($draft->document_type === DocumentType::CorrectedInvoice) {
            $event['replaced'] = $this->replacedFigures($draft, $entity);
        }

        /*
         | A prepayment applied changes the shape of the posting - PF-099.
         |
         | Not the document type: a final invoice is an ordinary `380`, and
         | whether it carries a prepayment is a fact about its references.
         | The figures are read here for the same reason the replaced ones
         | are: a posting rule that loaded a document would make the ledger
         | depend on Sales.
         */
        $prepayment = $this->prepaymentOn($draft);

        if ($prepayment !== null) {
            $event['prepayment'] = [
                'gross' => (string) $prepayment->gross_amount,
                'net' => (string) $prepayment->net_amount,
                'tax' => (string) $prepayment->tax_amount,
                'jurisdiction' => (string) ($prepayment->tax_decision_snapshot['country']
                    ?? $prepayment->lines()->value('place_of_supply')
                    ?? $entity->country),
                'tax_code_id' => (string) $prepayment->lines()->value('tax_code_id'),
                'tax_point' => $prepayment->tax_point?->toDateString(),
            ];
        }

        return $this->poster->post(
            $entity,
            $this->postingRuleFor($draft),
            $event,
            Carbon::parse($draft->issue_date->toDateString()),
            'SAL',
            'invoice',
            (string) $draft->getKey(),
        );
    }
}
