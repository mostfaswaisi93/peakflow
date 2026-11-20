<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Authorization\SegregatesDuties;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Services\ChainVerifier;
use App\Tenant\Sales\Services\Crediting;
use App\Tenant\Sales\Services\Issuer;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Services\TaxCodes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Crediting an issued invoice - PF-097.
 *
 * ## What a credit note is, and is not
 *
 * It is a document. It is not an undo. The invoice was correct when it was
 * issued, it is in a return that may already be filed, and the goods really
 * did come back - so both documents stay in the record and the receivable
 * ends up where it should be by addition, not by amendment.
 *
 * That is why the posting is its own rule rather than `Reverser` run over the
 * invoice's entry, and why the credit note's tax point is its own date rather
 * than the invoice's.
 *
 * ## The cap is enforced twice, deliberately
 *
 * The service refuses an over-credit with a sentence somebody can act on -
 * "690.0000 left and 800.0000 was asked for". The database refuses it under a
 * lock on the invoice, which is what makes it true when two credit notes are
 * issued at the same instant. Neither is redundant: the first is usable, the
 * second is correct.
 */
final class CreditNoteRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\CapturesEvents;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['credit notes (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'a full credit is the invoice mirrored' => fn (): array => $this->aFullCredit(),
                'a part of one line is credited, with its share of the quantity' => fn (): array => $this->aPartialCredit(),
                'an amount is apportioned across the rate groups' => fn (): array => $this->anAmountCredit(),
                'the posting is the mirror, and not a reversal' => fn (): array => $this->thePosting(),
                'more than remains is refused, by the service and by the database' => fn (): array => $this->theCap(),
                'a draft and a credit note cannot be credited' => fn (): array => $this->whatCannotBeCredited(),
                'a credit note without its reference cannot be issued' => fn (): array => $this->theReferenceIsMandatory(),
                'the invoice shows what has been credited against it' => fn (): array => $this->theInvoiceShowsItsCredits(),
                'whoever issued the invoice cannot credit it' => fn (): array => $this->theDutyIsSeparated(),
                'a credit note has its own series and its own chain' => fn (): array => $this->itsOwnChain(),
                'the enforcement counter agrees with the documents' => fn (): array => $this->theCounterAgrees(),
                'issuing a credit note raises CreditNoteIssued, and not InvoiceIssued' => fn (): array => $this->theEventIsRaised(),
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function aFullCredit(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $credit = app(Crediting::class)->full($invoice, $this->date);

        foreach ([
            'net' => [(string) $credit->net_amount, (string) $invoice->net_amount],
            'tax' => [(string) $credit->tax_amount, (string) $invoice->tax_amount],
            'gross' => [(string) $credit->gross_amount, (string) $invoice->gross_amount],
            'currency' => [(string) $credit->currency, (string) $invoice->currency],
            'party' => [(string) $credit->party_id, (string) $invoice->party_id],
        ] as $what => [$actual, $expected]) {
            if ($actual !== $expected) {
                $violations[] = sprintf('a full credit\'s %s is %s and the invoice\'s is %s', $what, $actual, $expected);
            }
        }

        if ($credit->document_type !== DocumentType::CreditNote) {
            $violations[] = 'a credit note is not UNCL 1001 code 381';
        }

        if ($credit->lines()->count() !== $invoice->lines()->count()) {
            $violations[] = 'a full credit does not have one line per invoice line';
        }

        $line = $credit->lines()->first();

        if ($line === null || $line->credits_line_id === null) {
            $violations[] = 'a credited line does not say which line it credits, so the invoice cannot '.
                'show which of its lines have been credited';
        }

        /*
         | And the tax point is the CREDIT's, not the invoice's.
         |
         | A credit note issued in June against an April invoice belongs in
         | June's return: the supply did not un-happen in April, and a return
         | already filed is corrected by PF-087's procedure rather than by
         | backdating a document into it.
         */
        if ((string) $credit->tax_point?->toDateString() !== $this->date->toDateString()) {
            $violations[] = sprintf(
                'the credit note\'s tax point is %s and should be its own issue date, %s',
                (string) $credit->tax_point?->toDateString(),
                $this->date->toDateString(),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function aPartialCredit(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $line = $invoice->lines()->first();

        if ($line === null) {
            return ['the fixture invoice has no lines'];
        }

        $credit = app(Crediting::class)->forLines($invoice, [[
            'line_id' => (string) $line->getKey(),
            'net' => '400.0000',
        ]], $this->date);

        if ((string) $credit->net_amount !== '400.0000') {
            $violations[] = sprintf('a 400 credit is for %s', (string) $credit->net_amount);
        }

        if ((string) $credit->tax_amount !== '60.0000') {
            $violations[] = sprintf(
                'the tax on a 400 credit at 15%% is %s and should be 60.0000',
                (string) $credit->tax_amount,
            );
        }

        /*
         | The quantity is proportional.
         |
         | 40% of the value of a one-unit line is 0.4 units, not one unit at
         | 40% of the price. A warehouse receiving the goods back reads the
         | quantity, and "1" on a part credit is how a stock count goes
         | wrong.
         */
        $quantity = (string) $credit->lines()->value('quantity');

        if (rtrim(rtrim($quantity, '0'), '.') !== '0.4') {
            $violations[] = sprintf('the credited quantity is %s and 40%% of one unit is 0.4', $quantity);
        }

        $remaining = app(Crediting::class)->remainingOn($invoice->refresh(), 'SAR');

        if ((string) $remaining->amount !== '1150.0000') {
            $violations[] = sprintf(
                'a DRAFT credit note changed what remains creditable to %s. Only an issued document '.
                'may: two drafts each crediting the whole invoice are two alternatives somebody is '.
                'considering',
                (string) $remaining->amount,
            );
        }

        app(Issuer::class)->issue($credit, $this->entity(), $this->actorId(), $this->decision);
        $remaining = app(Crediting::class)->remainingOn($invoice->refresh(), 'SAR');

        if ((string) $remaining->amount !== '690.0000') {
            $violations[] = sprintf(
                'after crediting 460 of 1150, %s remains creditable and 690.0000 should',
                (string) $remaining->amount,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function anAmountCredit(): array
    {
        $violations = [];

        /*
         | An invoice with TWO rate groups, because that is where an
         | amount-only credit can go wrong.
         |
         | A 1,150 settlement on an invoice carrying 15% and 5% lines is not
         | a 15% credit. Choosing one rate for the whole thing misstates both
         | boxes of the return, and the net due by the difference.
         */
        $reduced = app(TaxCodes::class)->introduce(
            entityId: $this->entityId,
            code: 'SA-S-5',
            category: TaxCategory::Standard,
            jurisdiction: 'SA',
            rate: '5.000000',
            from: CarbonImmutable::parse('2020-07-01'),
            labelAr: 'نسبة مخففة',
            labelEn: 'Reduced rate',
            source: 'a credit note check',
            reportingBoxes: ['sa' => ['output' => 'standard_sales']],
        );

        $draft = $this->draft();

        InvoiceLine::create([
            'entity_id' => $this->entityId,
            'invoice_id' => (string) $draft->getKey(),
            'line_no' => 2,
            'description' => 'Support, at the reduced rate',
            'unit_code' => 'C62',
            'quantity' => '1.00000000',
            'unit_price' => '1000.0000',
            'net_amount' => '1000.0000',
            'revenue_account_id' => $this->revenueAccountId,
            'tax_code_id' => (string) $reduced->getKey(),
            'tax_category' => 'S',
            'tax_rate' => '5.000000',
            'tax_point' => $this->date->toDateString(),
            'place_of_supply' => 'SA',
            'place_of_supply_rule' => 'domestic',
        ]);

        $draft->taxBreakdown()->create([
            'entity_id' => $this->entityId,
            'category' => 'S',
            'rate' => '5.000000',
            'taxable_amount' => '1000.0000',
            'tax_amount' => '50.0000',
            'report_box' => 'standard_sales',
        ]);

        $draft->update([
            'net_amount' => '2000.0000',
            'tax_amount' => '200.0000',
            'gross_amount' => '2200.0000',
            'payable_amount' => '2200.0000',
        ]);

        $invoice = app(Issuer::class)->issue(
            $draft->refresh(), $this->entity(), $this->actorId(), $this->decision,
        );

        $credit = app(Crediting::class)->forAnAmount($invoice, '1100.0000', $this->date);

        if ($credit->lines()->count() !== 2) {
            $violations[] = sprintf(
                'an amount credit against a two-group invoice produced %d line(s). Both groups have to '.
                'be credited, or the return is wrong in two boxes',
                $credit->lines()->count(),
            );
        }

        $groups = $credit->taxBreakdown()->orderBy('rate')->get()
            ->mapWithKeys(static fn (object $row): array => [
                (string) $row->rate => (string) $row->tax_amount,
            ])->all();

        if (($groups['5.000000'] ?? null) !== '25.0000' || ($groups['15.000000'] ?? null) !== '75.0000') {
            $violations[] = sprintf(
                'half of a 2,200 invoice credits 25.00 at 5%% and 75.00 at 15%%, and this credit has %s',
                json_encode($groups),
            );
        }

        if ((string) $credit->gross_amount !== '1100.0000') {
            $violations[] = sprintf(
                'a credit asked for as 1,100 gross came out at %s. The apportionment has to add back '.
                'to what was asked for, or the person typing a settlement figure gets a different one',
                (string) $credit->gross_amount,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function thePosting(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $credit = app(Issuer::class)->issue(
            app(Crediting::class)->full($invoice, $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        $entry = DB::connection('tenant')->table('journal_entries')
            ->where('source_type', 'invoice')
            ->where('source_id', (string) $credit->getKey())
            ->first();

        if ($entry === null) {
            return ['issuing a credit note produced no journal entry'];
        }

        if ((string) $entry->posting_rule !== 'sales.credit_note_issued') {
            $violations[] = sprintf(
                'a credit note posted through "%s". A credit note is its own rule: a partial credit is '.
                'not the reversal of anything, and negating the invoice\'s rows produces an entry that '.
                'reads as a correction to the original rather than as a document of its own',
                (string) $entry->posting_rule,
            );
        }

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $entry->id)
            ->get();

        $receivable = DB::connection('tenant')->table('account_roles')
            ->where('entity_id', $this->entityId)
            ->where('role', 'accounts_receivable')
            ->value('account_id');

        $output = DB::connection('tenant')->table('account_roles')
            ->where('entity_id', $this->entityId)
            ->where('role', 'vat_output')
            ->where('jurisdiction', 'SA')
            ->value('account_id');

        foreach ([
            'the receivable' => [$receivable, '-1150.0000'],
            'the output tax' => [$output, '150.0000'],
            'the revenue' => [$this->revenueAccountId, '1000.0000'],
        ] as $what => [$accountId, $expected]) {
            $line = $lines->firstWhere('account_id', $accountId);
            $actual = $line === null ? null : (string) $line->amount;

            if ($actual !== $expected) {
                $violations[] = sprintf('%s moves by %s and should move by %s', $what, $actual ?? 'nothing', $expected);
            }
        }

        /*
         | And the original entry is untouched.
         |
         | A reversal would have marked it reversed. The invoice was correct
         | when it was issued; what an auditor reading the receivable needs
         | is both documents, not one amended document.
         */
        $original = DB::connection('tenant')->table('journal_entries')
            ->where('source_id', (string) $invoice->getKey())
            ->first();

        if ($original !== null && ($original->reversed_at ?? null) !== null) {
            $violations[] = 'crediting an invoice reversed the invoice\'s own entry. The supply '.
                'happened; a credit note records that something else happened too';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theCap(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $crediting = app(Crediting::class);

        app(Issuer::class)->issue(
            $crediting->forAnAmount($invoice, '1000.0000', $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        try {
            $crediting->forAnAmount($invoice->refresh(), '200.0000', $this->date);

            $violations[] = 'the service drafted a credit for 200 against an invoice with 150 left';
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), '150.0000')) {
                $violations[] = 'the refusal does not say how much is actually left: '.$e->getMessage();
            }
        }

        /*
         | And the database refuses it when the service is bypassed.
         |
         | Which is the enforcement that matters: the service's arithmetic is
         | a courtesy, and the day somebody writes a second crediting path -
         | an import, an API endpoint, a correction script - the cap has to
         | still be there.
         */
        $sneaky = $crediting->forAnAmount($invoice->refresh(), '100.0000', $this->date);

        DB::connection('tenant')->table('invoice_lines')
            ->where('invoice_id', (string) $sneaky->getKey())
            ->update(['net_amount' => '500.0000']);

        DB::connection('tenant')->table('invoice_tax_breakdown')
            ->where('invoice_id', (string) $sneaky->getKey())
            ->update(['taxable_amount' => '500.0000', 'tax_amount' => '75.0000']);

        DB::connection('tenant')->table('invoices')
            ->where('id', (string) $sneaky->getKey())
            ->update([
                'net_amount' => '500.0000',
                'tax_amount' => '75.0000',
                'gross_amount' => '575.0000',
                'payable_amount' => '575.0000',
            ]);

        try {
            app(Issuer::class)->issue(
                $sneaky->refresh(), $this->entity(), $this->actorId(), $this->decision,
            );

            $violations[] = 'the database issued a credit note for more than the invoice\'s remaining '.
                'value. Two credit notes for the full amount, issued at the same instant, would both '.
                'see nothing credited and both succeed';
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'would bring the total credited')) {
                $violations[] = 'the database refused it for another reason: '.
                    substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 160);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function whatCannotBeCredited(): array
    {
        $violations = [];
        $crediting = app(Crediting::class);

        try {
            $crediting->full($this->draft(), $this->date);

            $violations[] = 'a draft invoice was credited. There is nothing to credit: a draft has no '.
                'number, no posting and no effect on any balance';
        } catch (Throwable) {
            // As it should be.
        }

        $invoice = $this->issue();
        $credit = app(Issuer::class)->issue(
            $crediting->full($invoice, $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        try {
            $crediting->full($credit, $this->date);

            $violations[] = 'a credit note was credited. Reversing a credit means a debit note or a '.
                'new invoice, so that both documents stay in the returns they were reported in';
        } catch (Throwable) {
            // As it should be.
        }

        return $violations;
    }

    /** @return list<string> */
    public function theReferenceIsMandatory(): array
    {
        $invoice = $this->issue();
        $credit = app(Crediting::class)->full($invoice, $this->date);

        $credit->references()->delete();

        try {
            app(Issuer::class)->issue(
                $credit->refresh(), $this->entity(), $this->actorId(), $this->decision,
            );

            return ['a credit note with no reference was issued. EN 16931 BT-25 is mandatory on a '.
                'credit note, and a credit with no reference reduces a receivable nobody can trace to '.
                'a supply'];
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'BT-25')
                || str_contains($e->getMessage(), 'reference')
                ? []
                : ['the refusal does not mention the missing reference: '.
                    substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 160)];
        }
    }

    /** @return list<string> */
    public function theInvoiceShowsItsCredits(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $crediting = app(Crediting::class);

        if ($invoice->credits()->count() !== 0) {
            $violations[] = 'a freshly issued invoice already shows a credit';
        }

        $draft = $crediting->forAnAmount($invoice, '500.0000', $this->date);

        if ($invoice->credits()->count() !== 0) {
            $violations[] = 'a DRAFT credit note shows on the invoice as a credit. It has no number '.
                'and may never be issued';
        }

        app(Issuer::class)->issue($draft, $this->entity(), $this->actorId(), $this->decision);

        if ($invoice->credits()->count() !== 1) {
            $violations[] = sprintf(
                'the invoice shows %d credit(s) after one was issued against it',
                $invoice->credits()->count(),
            );
        }

        $line = $invoice->lines()->first();

        if ($line !== null) {
            $creditedOnTheLine = InvoiceLine::query()
                ->where('credits_line_id', (string) $line->getKey())
                ->count();

            if ($creditedOnTheLine !== 1) {
                $violations[] = 'the invoice LINE does not show the line that credits it, so a partial '.
                    'credit cannot be traced to what was charged for';
            }
        }

        return $violations;
    }

    /**
     * The control that stops somebody erasing a receivable they created.
     *
     * @return list<string>
     */
    public function theDutyIsSeparated(): array
    {
        $violations = [];
        $issuer = $this->probeUser('issuer');
        $other = $this->probeUser('other');

        if ($issuer === null || $other === null) {
            return ['could not create probe users, so the duty separation could not be checked'];
        }

        /*
         | Signed in, because the audit trail attributes an act to the
         | session's user and the engine reads the audit trail. A string id
         | passed to the issuer is what the DOCUMENT records as its issuer;
         | it is not who the system believes acted.
         */
        auth()->guard('web')->setUser($issuer);

        $invoice = app(Issuer::class)->issue(
            $this->draft(), $this->entity(), (string) $issuer->getKey(), $this->decision,
        );

        $record = DB::connection('tenant')->table('audit_log')
            ->where('subject_id', (string) $invoice->getKey())
            ->where('action', 'invoice.issued')
            ->first();

        if ($record === null || (string) $record->actor_user_id !== (string) $issuer->getKey()) {
            $violations[] = 'issuing an invoice was not attributed to the signed-in user, so no duty '.
                'control over it can fire at all';

            auth()->guard('web')->forgetUser();

            return $violations;
        }

        $duties = app(SegregatesDuties::class);
        $blocking = $duties->blockingRule(
            'credit_note.issue', 'invoice', (string) $invoice->getKey(), (string) $invoice->party_id,
        );

        if ($blocking !== 'invoice_then_credit') {
            $violations[] = sprintf(
                'the person who issued the invoice is not refused the credit note (blocking rule: %s). '.
                'Whoever issues an invoice must not credit it, or they can erase a receivable they '.
                'created',
                $blocking ?? 'none',
            );
        }

        /*
         | And the refusal reaches the issuing path, not just the engine.
         |
         | A control that is only asserted where somebody remembered to call
         | it is a control with as many holes as there are callers.
         */
        $credit = app(Crediting::class)->full($invoice, $this->date);

        try {
            app(Issuer::class)->issue(
                $credit, $this->entity(), (string) $issuer->getKey(), $this->decision,
            );

            $violations[] = 'the issuer let the invoice\'s own issuer credit it';
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'invoice')) {
                $violations[] = 'the refusal does not explain itself: '.$e->getMessage();
            }
        }

        // Somebody else may.
        auth()->guard('web')->setUser($other);

        if ($duties->blockingRule(
            'credit_note.issue', 'invoice', (string) $invoice->getKey(), (string) $invoice->party_id,
        ) !== null) {
            $violations[] = 'a second person was refused a credit note they had no part in issuing';
        }

        try {
            app(Issuer::class)->issue(
                $credit->refresh(), $this->entity(), (string) $other->getKey(), $this->decision,
            );
        } catch (Throwable $e) {
            $violations[] = 'a different person could not credit the invoice either: '.$e->getMessage();
        }

        auth()->guard('web')->forgetUser();

        return $violations;
    }

    /** @return list<string> */
    public function itsOwnChain(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $credit = app(Issuer::class)->issue(
            app(Crediting::class)->full($invoice, $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        if ((string) $credit->series_key === (string) $invoice->series_key) {
            $violations[] = 'a credit note draws from the invoice series, so the two types contend for '.
                'one lock and share a sequence';
        }

        if ($credit->chain_index !== 1 || (string) $credit->previous_hash !== str_repeat('0', 64)) {
            $violations[] = 'the first credit note does not start its own chain, so a gap in one '.
                'series would read as a gap in the other';
        }

        foreach (['INV', 'CRN'] as $series) {
            $walk = app(ChainVerifier::class)->verify($this->entityId, $series);

            if (! $walk['verified']) {
                $violations[] = sprintf('the %s chain does not verify: %s', $series, (string) ($walk['divergence']['reason'] ?? ''));
            }
        }

        return $violations;
    }

    /**
     * The counter the database refuses with, against the documents.
     *
     * ## Why this check has to exist
     *
     * The cap is enforced through a running total in
     * `invoice_credit_totals`, because a locking read inside a trigger is
     * bound by the snapshot of the statement that fired it and a write is
     * not - measured: three simultaneous full credit notes against one
     * 115.0000 invoice all succeeded while the lock was being taken and
     * dutifully waited for.
     *
     * That total is a second place where "how much has been credited" is
     * written down, and the documents are the first. A derived figure nobody
     * compares is how a system comes to refuse a legitimate credit note, or
     * allow an illegitimate one, for a reason nobody can see.
     *
     * @return list<string>
     */
    public function theCounterAgrees(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $crediting = app(Crediting::class);

        foreach (['300.0000', '450.0000'] as $amount) {
            app(Issuer::class)->issue(
                $crediting->forAnAmount($invoice->refresh(), $amount, $this->date),
                $this->entity(), $this->actorId(), $this->decision,
            );
        }

        $counter = DB::connection('tenant')->table('invoice_credit_totals')
            ->where('invoice_id', (string) $invoice->getKey())
            ->value('gross_credited');

        $fromTheDocuments = Invoice::query()
            ->where('status', 'issued')
            ->whereHas('references', fn ($q) => $q
                ->where('kind', 'credits')
                ->where('referenced_invoice_id', (string) $invoice->getKey()))
            ->sum('gross_amount');

        if ((string) $counter !== (string) $fromTheDocuments) {
            $violations[] = sprintf(
                'the counter says %s has been credited and the documents say %s. The documents are '.
                'the record; the counter is only how the database refuses atomically, and a '.
                'disagreement means one of the two is refusing or allowing for a reason nobody can see',
                (string) ($counter ?? 'nothing'),
                (string) $fromTheDocuments,
            );
        }

        if ((string) $counter !== '750.0000') {
            $violations[] = sprintf('two credits of 300 and 450 total %s', (string) ($counter ?? 'nothing'));
        }

        $remaining = $crediting->remainingOn($invoice->refresh(), 'SAR');

        if ((string) $remaining->amount !== '400.0000') {
            $violations[] = sprintf(
                'the service offers %s as creditable and the database would allow 400.0000. A service '.
                'that offers an amount the database then refuses is worse than one that offers nothing',
                (string) $remaining->amount,
            );
        }

        return $violations;
    }

    private function probeUser(string $handle): ?User
    {
        $role = Role::query()->where('key', 'controller')->first();

        if ($role === null) {
            return null;
        }

        $user = User::create([
            'name' => 'Credit note '.$handle,
            'email' => $handle.'-'.bin2hex(random_bytes(5)).'@invalid.test',
            'password' => Str::password(32),
            'active' => true,
        ]);

        UserEntityRole::create([
            'user_id' => $user->getKey(),
            'entity_id' => $this->entityId,
            'role_id' => $role->getKey(),
        ]);

        return $user;
    }
    /**
     * A credit note raises its own event, and not the invoice's - PF-048.
     *
     * ## The branch this guards
     *
     * `Issuer::issueNow()` ends in one ternary: credit note or not. The
     * discriminator is the cast enum's `isCredit()`, and the first version
     * called `isCreditNote()` on the model - a method that does not exist,
     * which PHP reports only when the line runs, i.e. on the first issue
     * after deployment, inside the transaction, rolling back a real
     * document.
     *
     * Reading it the other way is worse than crashing: a listener that
     * posts a sale, emails a sales invoice or adds to a revenue forecast
     * would do all three for a document that REDUCES what the customer
     * owes, and the entry would balance, so nothing would look wrong.
     *
     * So both halves are asserted - the right event once, and the wrong one
     * not at all.
     *
     * @return list<string>
     */
    public function theEventIsRaised(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $credit = null;

        $issueTheCredit = function () use ($invoice, &$credit): void {
            $credit = app(Issuer::class)->issue(
                app(Crediting::class)->full($invoice, $this->date),
                $this->entity(), $this->actorId(), $this->decision,
            );
        };

        $caught = $this->captureEvents(\App\Tenant\Sales\Events\CreditNoteIssued::class, $issueTheCredit);

        if (\count($caught) !== 1) {
            return [sprintf(
                'issuing a credit note raised CreditNoteIssued %d times, expected once',
                \count($caught),
            )];
        }

        $event = $caught[0];

        if ($event->eventKey() !== 'sales.credit_note.issued') {
            $violations[] = sprintf('its eventKey is "%s", and the audit entry reads that', $event->eventKey());
        }

        if ($credit !== null && $event->creditNoteId !== (string) $credit->getKey()) {
            $violations[] = 'the event names a different document from the one issued';
        }

        /*
         | The invoice it credits is ON the event.
         |
         | Without it a listener has the credit note and no way to find what
         | it adjusts without reading `invoice_references` - which is Sales'
         | table, and a listener in another module is not permitted to read
         | it. A null here would make the event unusable for the one thing
         | anybody wants a credit note event for.
         */
        if ($event->creditsInvoiceId !== (string) $invoice->getKey()) {
            $violations[] = sprintf(
                'the event says it credits "%s" and it credits %s. A listener cannot look that up: '.
                'the reference lives in a Sales table it may not read',
                (string) $event->creditsInvoiceId,
                (string) $invoice->getKey(),
            );
        }

        $second = $this->issue();
        $strays = $this->captureEvents(
            \App\Tenant\Sales\Events\InvoiceIssued::class,
            function () use ($second): void {
                app(Issuer::class)->issue(
                    app(Crediting::class)->full($second, $this->date),
                    $this->entity(), $this->actorId(), $this->decision,
                );
            },
        );

        if ($strays !== []) {
            $violations[] = 'a credit note also raised InvoiceIssued, so a listener that posts a sale, '.
                'emails a sales invoice or forecasts revenue does all three for a document that '.
                'reduces what the customer owes';
        }

        return $violations;
    }

}
