<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Services\ChainVerifier;
use App\Tenant\Sales\Services\Issuer;
use App\Tenant\Tax\Enums\CustomerStatus;
use App\Tenant\Tax\Enums\EvidenceKind;
use App\Tenant\Tax\Enums\SupplyType;
use App\Tenant\Tax\Models\ReturnPeriod;
use App\Tenant\Tax\Services\PlaceOfSupply;
use App\Tenant\Tax\Services\ReturnPeriods;
use App\Tenant\Tax\SupplyContext;
use App\Tenant\Tax\TaxEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The tax point on a document, and the return period it chooses - PF-077.
 *
 * ## The one failure this file exists for
 *
 * A supply made on 30 April, invoiced on 18 May, reported in May's return.
 *
 * It is the failure PF-077 was written about, and the issuer used to produce
 * it. The tax point was `$draft->tax_point ?? $draft->issue_date`: a document
 * that carried no tax point silently got the invoice date, under any
 * jurisdiction rule, including the ones whose whole point is that the tax
 * point is the date of the supply. Nothing was logged, the date was of the
 * right type, and both returns were wrong - April short by the tax, May long
 * by it - until an authority compared the two sides of one supply.
 *
 * `TaxPoint::derive()` has refused a missing date since PF-077 was built. It
 * had no caller. A refusal nothing calls is a comment.
 *
 * ## Why the checks here measure the DOCUMENT and not the service
 *
 * `bin/verify tax` already proves the derivation in isolation: five bases,
 * the `BT-8` code of the date that won, an unknown basis throwing and an
 * unlisted profile falling back. None of that could see the issuer handing
 * the service's answer away and keeping the invoice date, because none of it
 * went through the issuer. So every check below issues a real document
 * through `Issuer::issue()` and then reads the row, the journal and the
 * frozen bytes.
 *
 * ## Three dates, and the configuration that decides between them
 *
 * Every shipped profile currently derives from the invoice date, which is
 * why the whole defect was invisible: with that basis the fallback and the
 * derivation agree on every document, forever. The checks that matter
 * therefore set the basis to `delivery_date` for the length of one check and
 * put it back - the arrangement under which the two answers differ, and the
 * one a customer in a delivery-basis regime would be running.
 *
 * Each restores the configuration in a `finally`, because a check that left
 * it changed would hand the next one a jurisdiction nobody configured.
 */
final class TaxCoverageRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** The supply: the last day of the month before the one it is invoiced in. */
    private const DELIVERED_ON = '2026-04-30';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['the tax point on a document (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'the tax point is its own column, and not a copy of the invoice date' => fn (): array => $this->theTaxPointIsItsOwnColumn(),
                'a basis whose date is missing refuses, rather than taking the invoice date' => fn (): array => $this->aMissingDeliveryDateRefuses(),
                'the whole decision is frozen on the document, not just its date' => fn (): array => $this->theDecisionIsFrozen(),
                'and a later change to the rule does not move a document already issued' => fn (): array => $this->theFrozenDecisionDoesNotMove(),
                'every leg of the posting carries the derived date' => fn (): array => $this->bothLegsCarryTheDerivedDate(),
                'the frozen bytes cover the date the tax point was derived from' => fn (): array => $this->theBytesCoverTheDerivationInput(),
                'the return period comes from the tax point, and differs from the accounting one' => fn (): array => $this->theReturnPeriodIsStored(),
                'a derived tax point landing in a filed return is refused' => fn (): array => $this->aFiledReturnRefusesTheDocument(),

                // PF-080: the issue path, which is what the gate was built for.
                'an export whose proof of exit is not recorded is not issued' => fn (): array => $this->anExportWithNoProofIsNotIssued(),
                'and the proof travels with the document, inside its bytes' => fn (): array => $this->theProofTravelsWithTheDocument(),
                'a blank reference is refused, and told apart from a missing one' => fn (): array => $this->aBlankProofIsToldApart(),
                'an evidence kind this engine does not know refuses, rather than being skipped' => fn (): array => $this->anUnknownEvidenceKindRefuses(),
            ]), $this->entityId);
        } catch (Throwable $e) {
            return ['the tax point on a document could not be checked' => [
                Str::limit((string) preg_replace('/\s+/', ' ', $e->getMessage()), 300),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * `BT-2`, `BT-72` and `BT-7` are three columns, and hold three answers.
     *
     * The weaker claim - that the columns exist - is worth nothing on its
     * own: `tax_point` existed throughout the defect this file is about, and
     * was filled with the invoice date. So this issues a document whose
     * supply happened in the previous month under a delivery-date basis and
     * asserts the stored tax point followed the SUPPLY.
     *
     * @return list<string>
     */
    public function theTaxPointIsItsOwnColumn(): array
    {
        $violations = [];

        foreach (['issue_date', 'delivery_date', 'tax_point'] as $column) {
            $type = $this->columnType('invoices', $column);

            if ($type !== 'date') {
                $violations[] = sprintf(
                    'invoices.%s is %s. EN 16931 keeps BT-2, BT-72 and BT-7 apart because they answer '.
                    'three different questions, and a document that cannot hold three answers can only '.
                    'repeat one of them',
                    $column,
                    $type ?? 'absent',
                );
            }
        }

        if ($violations !== []) {
            return $violations;
        }

        $invoice = $this->underBasis('delivery_date', fn (): Invoice => $this->issueDelivered(self::DELIVERED_ON));

        if ((string) $invoice->tax_point?->toDateString() !== self::DELIVERED_ON) {
            $violations[] = sprintf(
                'the supply was delivered on %s, the invoice was raised on %s, the jurisdiction taxes '.
                'the delivery - and the document says its tax point is %s. The invoice date is always '.
                'present and always plausible, which is exactly why reaching for it is the failure this '.
                'criterion forbids: the supply is reported a month late and nothing says so',
                self::DELIVERED_ON,
                $this->date->toDateString(),
                (string) $invoice->tax_point?->toDateString(),
            );
        }

        if ($invoice->issue_date->toDateString() === (string) $invoice->tax_point?->toDateString()) {
            $violations[] = 'the tax point equals the issue date on a document delivered in another '.
                'month, so the column is a copy of BT-2 rather than its own fact';
        }

        return $violations;
    }

    /**
     * No delivery date under a delivery basis REFUSES.
     *
     * The measured bite: with the issuer's old `?? $draft->issue_date` in
     * place, this document was issued, numbered, posted and chained, and its
     * tax point was 18 May - a date no rule in the configuration had chosen.
     *
     * @return list<string>
     */
    public function aMissingDeliveryDateRefuses(): array
    {
        $before = $this->nextNumber();

        $outcome = $this->underBasis('delivery_date', function (): array {
            try {
                $invoice = $this->issue();

                return ['issued' => true, 'tax_point' => (string) $invoice->tax_point?->toDateString()];
            } catch (Throwable $e) {
                return ['issued' => false, 'message' => $e->getMessage()];
            }
        });

        if ($outcome['issued'] === true) {
            return [sprintf(
                'a document with no delivery date was issued under a delivery-date basis, with a tax '.
                'point of %s. That is the invoice date wearing the rule\'s name: the return it lands in '.
                'was chosen by an absence',
                (string) $outcome['tax_point'],
            )];
        }

        $violations = [];
        $message = (string) $outcome['message'];

        if (! str_contains($message, 'BT-72')) {
            $violations[] = sprintf(
                'the refusal does not name the date the document needs (EN 16931 BT-72). "The tax '.
                'point could not be determined" sends somebody to the configuration; "this document '.
                'needs a delivery date" sends them to the document. The message was: %s',
                Str::limit($message, 160),
            );
        }

        if ($this->nextNumber() !== $before) {
            $violations[] = 'the refusal consumed a number from the series. A gap in an invoice series '.
                'is a finding in a tax audit, and this refusal happens before anything is allocated '.
                'precisely so it cannot leave one';
        }

        return $violations;
    }

    /**
     * The basis, the winning date, the profile and the `BT-8` code, all stored.
     *
     * A bare date is not a decision. An accountant disagreeing with a tax
     * point does not ask which date was chosen - they ask why, and a document
     * carrying only the answer makes them reconstruct the rule from the other
     * dates on it. They will reconstruct it differently, and the
     * reconstruction is what goes into a reply to an authority.
     *
     * @return list<string>
     */
    public function theDecisionIsFrozen(): array
    {
        $invoice = $this->underBasis('delivery_date', fn (): Invoice => $this->issueDelivered(self::DELIVERED_ON));

        $frozen = $invoice->tax_decision_snapshot['tax_point'] ?? null;

        if (! \is_array($frozen)) {
            return ['the issued document carries no frozen tax point decision. The date is on the row '.
                'and the reasoning that produced it is nowhere, which is the state in which two people '.
                'reading one document disagree about which return it belongs to'];
        }

        $expected = [
            'date' => self::DELIVERED_ON,
            'basis' => 'delivery_date',
            'winning_date' => 'delivery',
            'profile' => 'generic',
            // UNCL 2005 `35`: the delivery date. Not `3`, which is the
            // invoice date - a document declaring `3` while the delivery
            // date won would be wrong on BT-8.
            'document_code' => '35',
        ];

        $violations = [];

        foreach ($expected as $key => $value) {
            if (($frozen[$key] ?? null) !== $value) {
                $violations[] = sprintf(
                    'the frozen decision says %s = %s and it should be %s',
                    $key,
                    json_encode($frozen[$key] ?? null),
                    json_encode($value),
                );
            }
        }

        return $violations;
    }

    /**
     * Frozen means frozen: the rule changes, the document does not.
     *
     * This is the half that makes the storage worth having. A document whose
     * tax point were recomputed on read would move between returns when
     * somebody edited a jurisdiction profile - retrospectively, silently, and
     * including documents in returns that have been filed.
     *
     * @return list<string>
     */
    public function theFrozenDecisionDoesNotMove(): array
    {
        $invoice = $this->underBasis('delivery_date', fn (): Invoice => $this->issueDelivered(self::DELIVERED_ON));

        // The regime changes its mind. Everything issued before stays put.
        $reread = $this->underBasis(
            'invoice_date',
            fn (): Invoice => Invoice::query()->findOrFail((string) $invoice->getKey()),
        );

        $violations = [];

        if ((string) $reread->tax_point?->toDateString() !== self::DELIVERED_ON) {
            $violations[] = sprintf(
                'the document read %s after the jurisdiction rule changed, and was issued as %s. A tax '.
                'point that follows the current configuration is a document that has changed which '.
                'return it belongs to, after that return was filed',
                (string) $reread->tax_point?->toDateString(),
                self::DELIVERED_ON,
            );
        }

        if ((($reread->tax_decision_snapshot['tax_point'] ?? [])['basis'] ?? null) !== 'delivery_date') {
            $violations[] = 'the frozen basis followed the configuration rather than staying as it was '.
                'issued, so the document no longer records which rule decided it';
        }

        return $violations;
    }

    /**
     * The revenue leg and the tax leg carry one date between them.
     *
     * They are read from two places - the tax leg from the header, each
     * revenue leg from its own line - and the return is assembled from the
     * journal by tax point. Leave them to disagree and one document's taxable
     * base lands in one return period while its tax lands in another: a box
     * with tax and no base in the first, a base with no tax in the second.
     * Both figures are individually plausible.
     *
     * @return list<string>
     */
    public function bothLegsCarryTheDerivedDate(): array
    {
        $invoice = $this->underBasis('delivery_date', fn (): Invoice => $this->issueDelivered(self::DELIVERED_ON));

        $points = $this->connection->table('journal_lines')
            ->where('entity_id', $this->entityId)
            ->whereNotNull('tax_code_id')
            ->whereIn('journal_entry_id', $this->connection->table('journal_entries')
                ->where('source_type', 'invoice')
                ->where('source_id', (string) $invoice->getKey())
                ->pluck('id'))
            ->pluck('tax_point')
            ->map(static fn (mixed $date): string => (string) $date)
            ->unique()
            ->values()
            ->all();

        if ($points === []) {
            return ['the document posted no tax-bearing journal line, so there is nothing for a return '.
                'to pick up. A return is assembled from the journal, not from the document'];
        }

        if (\count($points) > 1) {
            return [sprintf(
                'the legs of one document carry %s as their tax points. One document belongs to one '.
                'return period: two dates across its legs split a single supply between two returns - '.
                'the taxable base in one and the tax in the other - and each half looks internally '.
                'consistent',
                implode(' and ', $points),
            )];
        }

        if ($points !== [self::DELIVERED_ON]) {
            return [sprintf(
                'every leg of the document carries %s, and the derived tax point is %s. The legs agree '.
                'with each other and with nothing else, so the whole document is in the wrong return '.
                'rather than split across two',
                $points[0],
                self::DELIVERED_ON,
            )];
        }

        return [];
    }

    /**
     * The derivation's INPUT is inside the hash, not only its output.
     *
     * A document whose stored basis says "the delivery date won" and whose
     * bytes do not say what that date was cannot be re-checked: the
     * determination is unverifiable exactly when somebody needs to verify it,
     * six years later, against a query from an authority.
     *
     * Read from both sides' source, because the two payload builders are
     * deliberately separate copies - if they disagree every document verifies
     * as broken, which is loud, and a check that reads only one of them would
     * not say which side to fix.
     *
     * @return list<string>
     */
    public function theBytesCoverTheDerivationInput(): array
    {
        $violations = [];

        $sides = [
            'the issuer' => (new \ReflectionClass(Issuer::class))->getFileName(),
            'the verifier' => (new \ReflectionClass(ChainVerifier::class))->getFileName(),
        ];

        foreach ($sides as $side => $file) {
            $source = (string) file_get_contents((string) $file);

            if (! str_contains($source, "'delivery_date' =>")) {
                $violations[] = sprintf(
                    '%s does not put the delivery date in the legally significant form. The tax point '.
                    'is in there and the date it was derived from is not, so the bytes record an '.
                    'answer nobody can re-derive',
                    $side,
                );
            }
        }

        if ($violations !== []) {
            return $violations;
        }

        $invoice = $this->underBasis('delivery_date', fn (): Invoice => $this->issueDelivered(self::DELIVERED_ON));
        $canonical = json_decode((string) $invoice->canonical, true);

        if (($canonical['delivery_date'] ?? null) !== self::DELIVERED_ON) {
            $violations[] = sprintf(
                'the stored bytes say the delivery date was %s and the row says %s',
                json_encode($canonical['delivery_date'] ?? null),
                self::DELIVERED_ON,
            );
        }

        $walk = app(ChainVerifier::class)->verify($this->entityId, 'INV');

        if (! $walk['verified']) {
            $violations[] = sprintf(
                'the chain no longer verifies (%s). The two payload builders have to name the same '.
                'fields: when they do not, every document in the series reports as broken',
                json_encode($walk['divergence'] ?? null),
            );
        }

        return $violations;
    }

    /**
     * Two calendars, both stored, and this document is in different cells of each.
     *
     * The return period comes from the tax point; the accounting period comes
     * from the posting date. A monthly return on an entity closing quarterly
     * makes them differ as a matter of course, and a system storing one and
     * deriving the other cannot answer "which return was this in" after the
     * rule that derived it changed.
     *
     * @return list<string>
     */
    public function theReturnPeriodIsStored(): array
    {
        $invoice = $this->underBasis('delivery_date', fn (): Invoice => $this->issueDelivered(self::DELIVERED_ON));

        $violations = [];

        $expected = app(ReturnPeriods::class)->forTaxPoint(
            $this->entityId,
            'SA',
            $invoice->tax_point ?? $invoice->issue_date,
        );

        if ($expected === null) {
            return ['no return period covers the derived tax point, so the fixture cannot show the two '.
                'calendars apart'];
        }

        if ((string) $invoice->return_period_label !== (string) $expected->label) {
            $violations[] = sprintf(
                'the document was issued into return period "%s" and the period covering its tax point '.
                'is "%s". A document whose return period is not stored has to be re-derived to be '.
                'reported, and re-derivation reads a calendar that may have been regenerated since',
                (string) ($invoice->return_period_label ?? 'nothing'),
                (string) $expected->label,
            );
        }

        $accounting = $this->connection->table('journal_entries as e')
            ->join('periods as p', 'p.id', '=', 'e.period_id')
            ->where('e.source_type', 'invoice')
            ->where('e.source_id', (string) $invoice->getKey())
            ->value('p.label');

        if ($accounting === null) {
            $violations[] = 'the document posted into no accounting period, so only one of the two '.
                'calendars is actually recorded';

            return $violations;
        }

        if ((string) $accounting === (string) $invoice->return_period_label) {
            $violations[] = sprintf(
                'the accounting period and the return period are both "%s" on a document delivered in '.
                'April and invoiced in May. The fixture is meant to put them in different cells: if '.
                'they agree here, the check cannot tell a stored return period from one copied off the '.
                'posting date',
                (string) $accounting,
            );
        }

        return $violations;
    }

    /**
     * A document whose DERIVED tax point lands in a filed return is refused.
     *
     * The ordering property, stated as behaviour. The validator refuses a tax
     * point in a filed period, and it can only do so for the date the
     * document will actually carry: derive after validation and the check
     * passes on the date somebody typed while the document is frozen with
     * another one, which is a refusal reporting that it was satisfied.
     *
     * Quietly reporting a supply a quarter late mis-states both returns, and
     * the filed one cannot be corrected by anything except an adjustment that
     * says what it corrects (PF-087).
     *
     * @return list<string>
     */
    public function aFiledReturnRefusesTheDocument(): array
    {
        $period = ReturnPeriod::query()
            ->where('entity_id', $this->entityId)
            ->where('jurisdiction', 'SA')
            ->effectiveOn(CarbonImmutable::parse(self::DELIVERED_ON))
            ->first();

        if ($period === null) {
            return ['no return period covers the delivery date, so the refusal cannot be exercised'];
        }

        app(ReturnPeriods::class)->file($period, $this->actorId(), 'a check that the gate is shut');

        $outcome = $this->underBasis('delivery_date', function (): array {
            try {
                $this->issueDelivered(self::DELIVERED_ON);

                return ['issued' => true, 'message' => ''];
            } catch (Throwable $e) {
                return ['issued' => false, 'message' => $e->getMessage()];
            }
        });

        if ($outcome['issued'] === true) {
            return [sprintf(
                'a document whose tax point falls in the filed %s return was issued. Its tax point was '.
                'derived from the delivery date AFTER the only check that could refuse it, so the '.
                'refusal was asked about a date the document does not carry',
                (string) $period->label,
            )];
        }

        $message = (string) $outcome['message'];

        return str_contains($message, (string) $period->label) ? [] : [sprintf(
            'the refusal does not name the filed period, so nobody can tell which return is closed. '.
            'The message was: %s',
            Str::limit($message, 200),
        )];
    }

    /**
     * An export rate claimed with nothing showing the goods left - PF-080.
     *
     * The most commonly assessed error in VAT, and the one the evidence gate
     * was built for: a foreign billing address is not evidence of export, and
     * the assessment arrives years later with tax, interest and a penalty,
     * when the goods are gone and the forwarder has changed systems.
     *
     * `EvidenceGate::assertSatisfied()` has refused this since PF-080. Until
     * now nothing on the issue path called it, which made the gate a comment:
     * the decision carried `evidence_required: [export_proof]`, the document
     * carried no proof, and it was issued, numbered, posted and chained.
     *
     * @return list<string>
     */
    public function anExportWithNoProofIsNotIssued(): array
    {
        $decision = $this->exportDecision();

        if (($decision['evidence_required'] ?? []) !== ['export_proof']) {
            return [sprintf(
                'the rule book no longer requires proof of export for an export of goods (%s), so this '.
                'check is measuring nothing. PF-080 puts the requirement on the winning RULE, not on '.
                'the category: %s is also what a domestic zero-rated foodstuff carries',
                json_encode($decision['evidence_required'] ?? null),
                (string) ($decision['category'] ?? '?'),
            )];
        }

        $before = $this->nextNumber();
        $entriesBefore = $this->journalEntries();
        $violations = [];

        try {
            $issued = $this->issueWith($decision);

            $violations[] = sprintf(
                'the document was issued as %s, claiming category %s under rule "%s", with no proof of '.
                'export recorded. The zero rate is on the invoice, the customer has claimed against it, '.
                'and the only thing supporting it is the customer\'s address',
                (string) $issued->number,
                (string) ($decision['category'] ?? '?'),
                (string) ($decision['rule'] ?? '?'),
            );
        } catch (Throwable $e) {
            $message = $e->getMessage();

            if (! str_contains($message, 'proof of export')) {
                $violations[] = sprintf(
                    'the refusal does not say which document to go and find. "Evidence is missing" is a '.
                    'state; "proof of export - a customs declaration, an export entry, a bill of '.
                    'lading" is an instruction. The message was: %s',
                    Str::limit($message, 160),
                );
            }
        }

        if ($this->nextNumber() !== $before) {
            $violations[] = 'the refusal consumed a number from the gapless series';
        }

        if ($this->journalEntries() !== $entriesBefore) {
            $violations[] = 'the refusal left a journal entry behind, so the document did not post and '.
                'the books say it did';
        }

        return $violations;
    }

    /**
     * Recorded, and then unalterable - PF-080.
     *
     * A reference, not a boolean: the customs declaration number is what the
     * person producing it three years later actually needs. And inside the
     * frozen bytes, because evidence that could be attached after the fact is
     * evidence nobody has to hold at issue - which is the state the gate
     * exists to prevent, reached a day later.
     *
     * @return list<string>
     */
    public function theProofTravelsWithTheDocument(): array
    {
        $reference = 'KSA-EX-2026-5512';
        $violations = [];

        $issued = $this->issueWith($this->exportDecision(TaxEvidence::none()->with(
            EvidenceKind::ExportProof,
            $reference,
            CarbonImmutable::parse(self::DELIVERED_ON),
        )));

        $frozen = ($issued->tax_decision_snapshot['evidence'] ?? [])['export_proof'] ?? null;

        if ((($frozen['reference'] ?? null)) !== $reference) {
            $violations[] = sprintf(
                'the issued document records %s as its proof of export. A ticked box with nothing '.
                'behind it is worse than nothing: the system says the evidence exists, so nobody looks '.
                'for it, and the assessment is unanswerable',
                json_encode($frozen['reference'] ?? null),
            );
        }

        if (! str_contains((string) $issued->canonical, $reference)) {
            $violations[] = 'the reference is on the row and not in the frozen bytes. Evidence that can '.
                'be attached after issue is evidence nobody has to hold at issue, and the hash is the '.
                'only thing that makes the difference visible';
        }

        $walk = app(ChainVerifier::class)->verify($this->entityId, 'INV');

        if (! $walk['verified']) {
            $violations[] = sprintf('the chain no longer verifies: %s', json_encode($walk['divergence'] ?? null));
        }

        return $violations;
    }

    /**
     * Whitespace is refused, and refused differently - PF-080.
     *
     * The two states send somebody to different places: nothing recorded
     * means asking the forwarder for the document, and something recorded
     * with no reference means asking whoever ticked the box. A gate that
     * reported both as "missing" would be resolved by typing a number in.
     *
     * `TaxEvidence::has()` treats a blank reference as absent, so the order
     * inside the gate is what makes this work at all - ask about blanks
     * before asking what is missing, or the blank is reported as the absence.
     *
     * @return list<string>
     */
    public function aBlankProofIsToldApart(): array
    {
        $blank = $this->refusalFor($this->exportDecision(TaxEvidence::none()->with(
            EvidenceKind::ExportProof,
            '   ',
        )));

        $absent = $this->refusalFor($this->exportDecision());

        if ($blank === null) {
            return ['a proof of export recorded as whitespace was accepted. The document now asserts '.
                'that the evidence exists, which is the one state in which nobody goes looking for it'];
        }

        if ($absent === null) {
            return ['the check cannot compare the two refusals, because no proof at all was accepted'];
        }

        $violations = [];

        if (! str_contains($blank, 'recorded with no reference')) {
            $violations[] = sprintf(
                'the blank reference was refused as something else. The message was: %s',
                Str::limit($blank, 160),
            );
        }

        if ($blank === $absent) {
            $violations[] = 'a blank reference and no evidence at all produce the same refusal, so the '.
                'person reading it cannot tell whether to ask the forwarder or the colleague who '.
                'ticked the box';
        }

        return $violations;
    }

    /**
     * A requirement this engine cannot honour refuses - PF-080.
     *
     * The failure direction matters. Skipping a kind nobody implemented turns
     * a typo in a rule's evidence list into a rule with no evidence list, and
     * every document issued under it carries a rate with nothing behind it -
     * silently, and in exactly the configuration somebody was editing when
     * they made the typo.
     *
     * @return list<string>
     */
    public function anUnknownEvidenceKindRefuses(): array
    {
        $decision = $this->exportDecision();
        $decision['evidence_required'] = ['export_prof'];

        $refusal = $this->refusalFor($decision);

        if ($refusal === null) {
            return ['a rule requiring "export_prof" - a typo for export_proof - issued a document with '.
                'no evidence at all. The requirement was dropped because nothing recognised it, which '.
                'makes a misspelt rule the most permissive kind there is'];
        }

        return str_contains($refusal, 'export_prof') ? [] : [sprintf(
            'the refusal does not quote the unknown kind, so nobody can find the row to fix. The '.
            'message was: %s',
            Str::limit($refusal, 160),
        )];
    }

    /**
     * A real export determination, from the shipped rule book.
     *
     * Read through `PlaceOfSupply` rather than written out here, so the check
     * is measuring the rule the product ships: goods sold from Saudi Arabia
     * to a customer in the United States and delivered there are an export,
     * zero-rated under Art. 32(1), and the rule - not the category - is what
     * demands proof of exit.
     *
     * @return array<string, mixed>
     */
    private function exportDecision(?TaxEvidence $evidence = null): array
    {
        $decision = app(PlaceOfSupply::class)->decide(SupplyContext::deliveredTo(
            SupplyType::Goods,
            'SA',
            'US',
            CustomerStatus::Business,
            'US',
        ))->jsonSerialize();

        if ($evidence !== null) {
            $decision['evidence'] = $evidence->jsonSerialize();
        }

        return $decision;
    }

    /**
     * The message issuing refused with, or null when it was issued.
     *
     * @param  array<string, mixed>  $decision
     */
    private function refusalFor(array $decision): ?string
    {
        try {
            $this->issueWith($decision);

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Issue the fixture's draft under a given tax determination.
     *
     * The draft is the shape that passes, so a refusal below is the gate's
     * and not a document broken in some other way. Its lines carry the
     * fixture's own domestic categories: what the evidence gate reads is the
     * determination, and what is being checked is that the issue path
     * consults it at all.
     *
     * @param  array<string, mixed>  $decision
     */
    private function issueWith(array $decision): Invoice
    {
        return app(Issuer::class)->issue(
            $this->draft(),
            $this->entity(),
            $this->actorId(),
            $decision,
            $this->terms,
        );
    }

    /**
     * Issue the standard draft, delivered on a date in another month.
     *
     * The draft is the fixture's own - the shape that passes - with one date
     * added, so a check that fails cannot be failing because its document was
     * broken in some other way.
     */
    private function issueDelivered(string $deliveredOn): Invoice
    {
        $draft = $this->draft();
        $draft->update(['delivery_date' => $deliveredOn]);

        return app(Issuer::class)->issue(
            $draft->refresh(),
            $this->entity(),
            $this->actorId(),
            $this->decision,
            $this->terms,
        );
    }

    /**
     * Run something with the generic profile on a given basis, then put it back.
     *
     * In a `finally`, because a check that throws while the configuration is
     * changed would hand every check after it a jurisdiction rule nobody
     * configured - and they would fail for that reason rather than their own.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function underBasis(string $basis, callable $work): mixed
    {
        $was = config('jurisdictions.tax_point.generic');

        try {
            config(['jurisdictions.tax_point.generic' => $basis]);

            return $work();
        } finally {
            config(['jurisdictions.tax_point.generic' => $was]);
        }
    }

    /** The declared type of a column, or null when there is no such column. */
    private function columnType(string $table, string $column): ?string
    {
        $row = $this->connection->selectOne(<<<'SQL'
            SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = ? AND column_name = ?
        SQL, [$table, $column]);

        return $row === null ? null : (string) $row->data_type;
    }
}
