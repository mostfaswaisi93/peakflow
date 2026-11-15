<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Sales\Enums\CorrectionMechanism;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Services\ChainVerifier;
use App\Tenant\Sales\Services\Corrections;
use App\Tenant\Sales\Services\Crediting;
use App\Tenant\Sales\Services\Issuer;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Debit notes and corrected invoices - PF-098.
 *
 * ## The mechanism is a jurisdiction's decision
 *
 * A credit note leaves the invoice standing and reduces it; a corrected
 * invoice replaces it. Both reach the same balances, and what differs is
 * which document the authority expects to receive and match. Issuing the
 * wrong one is not a preference somebody can be talked out of afterwards -
 * it is a document already filed under a procedure the regime does not
 * recognise - so it is refused by name, from the profile.
 *
 * ## One correction, by primary key
 *
 * Two replacements for one invoice is a question with no answer: which one is
 * the invoice? The second is refused by a primary key rather than by a
 * counted sum, which is the cheapest correct enforcement there is - two
 * transactions inserting one key cannot both succeed, and nothing about
 * snapshots has to be true for that to hold.
 */
final class CorrectionRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['corrections (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'the profile decides which mechanism is legal' => fn (): array => $this->theProfileDecides(),
                'a profile with no declared mechanism is refused, not guessed' => fn (): array => $this->anUnknownProfile(),
                'a debit note charges more, in its own series' => fn (): array => $this->aDebitNote(),
                'a debit note has no cap, because it adds' => fn (): array => $this->aDebitNoteHasNoCap(),
                'a corrected invoice reverses and restates in one entry' => fn (): array => $this->aCorrectedInvoice(),
                'an invoice can be replaced once' => fn (): array => $this->onceOnly(),
                'a replaced invoice is closed to further adjustment' => fn (): array => $this->closedToAdjustment(),
                'an adjusting document cannot itself be adjusted' => fn (): array => $this->notAnInvoice(),
                'a document that adjusts nothing cannot be issued' => fn (): array => $this->theReferenceIsMandatory(),
                'whoever issued the invoice cannot replace it' => fn (): array => $this->theDutyIsSeparated(),
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function theProfileDecides(): array
    {
        $violations = [];
        $corrections = app(Corrections::class);

        foreach ([
            'generic' => CorrectionMechanism::Both,
            'facturx' => CorrectionMechanism::CorrectedInvoice,
            'eta' => CorrectionMechanism::CreditNote,
        ] as $profile => $expected) {
            if ($corrections->mechanismFor($profile) !== $expected) {
                $violations[] = sprintf(
                    'the %s profile corrects with %s and should correct with %s',
                    $profile,
                    $corrections->mechanismFor($profile)->value,
                    $expected->value,
                );
            }
        }

        /*
         | And the refusal names the regime.
         |
         | "Not permitted" sends somebody to the settings screen to look for
         | a switch. "The facturx profile corrects with a replacement
         | corrected invoice, so a credit note cannot be issued under it"
         | tells them what to do instead.
         */
        foreach ([
            'facturx' => [DocumentType::CreditNote, 'a replacement corrected invoice'],
            'eta' => [DocumentType::CorrectedInvoice, 'a credit or debit note'],
        ] as $profile => [$type, $names]) {
            try {
                $corrections->assertPermitted($type, $profile);

                $violations[] = sprintf(
                    'the %s profile permitted %s, which it does not recognise',
                    $profile, $type->label('en'),
                );
            } catch (Throwable $e) {
                foreach ([$profile, $names] as $needle) {
                    if (! str_contains($e->getMessage(), $needle)) {
                        $violations[] = sprintf(
                            'the refusal under %s does not mention "%s": %s',
                            $profile, $needle, $e->getMessage(),
                        );
                    }
                }
            }
        }

        /*
         | The sales screens read `permitted()`, so it has to be the same
         | answer: a type the profile forbids must not be offered at all,
         | which is the only way a rule like this survives contact with
         | somebody in a hurry.
         */
        if (app(Corrections::class)->permitted('facturx') !== [DocumentType::CorrectedInvoice]) {
            $violations[] = 'facturx offers a document type its own refusal would reject';
        }

        return $violations;
    }

    /** @return list<string> */
    public function anUnknownProfile(): array
    {
        try {
            app(Corrections::class)->mechanismFor('a-profile-nobody-declared');

            return ['an undeclared profile was given a correction mechanism anyway. Guessing one '.
                'issues a document under a procedure nobody chose'];
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'config/jurisdictions.php')
                ? []
                : ['the refusal does not say where to declare it: '.$e->getMessage()];
        }
    }

    /** @return list<string> */
    public function aDebitNote(): array
    {
        $violations = [];
        $invoice = $this->issue();

        $debit = app(Issuer::class)->issue(
            app(Corrections::class)->debitNote($invoice, [[
                'description' => 'Freight, omitted from the invoice',
                'net' => '200.0000',
            ]], $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        if ($debit->document_type !== DocumentType::DebitNote) {
            $violations[] = 'a debit note is not UNCL 1001 code 383';
        }

        if ((string) $debit->series_key !== 'DBN') {
            $violations[] = sprintf('a debit note draws from the %s series', (string) $debit->series_key);
        }

        if ((string) $debit->gross_amount !== '230.0000') {
            $violations[] = sprintf(
                'a 200.00 charge at 15%% comes to %s and should come to 230.0000',
                (string) $debit->gross_amount,
            );
        }

        $entry = DB::connection('tenant')->table('journal_entries')
            ->where('source_id', (string) $debit->getKey())
            ->first();

        if ($entry === null) {
            return [...$violations, 'a debit note posted nothing'];
        }

        /*
         | The invoice's own shape: receivable up, revenue up, tax up.
         |
         | A debit note is not a credit note with the sign flipped - it
         | charges more for a supply already invoiced - so it posts through
         | the invoice rule rather than through a mirror of it.
         */
        if ((string) $entry->posting_rule !== 'sales.invoice_issued') {
            $violations[] = sprintf('a debit note posted through "%s"', (string) $entry->posting_rule);
        }

        $receivable = $this->movementOn($entry->id, $this->roleAccount('accounts_receivable'));

        if ($receivable !== '230.0000') {
            $violations[] = sprintf(
                'the receivable moves by %s on a debit note and should rise by 230.0000',
                $receivable ?? 'nothing',
            );
        }

        $walk = app(ChainVerifier::class)->verify($this->entityId, 'DBN');

        if (! $walk['verified'] || $walk['documents'] !== 1) {
            $violations[] = 'the debit note series has no chain of its own';
        }

        return $violations;
    }

    /** @return list<string> */
    public function aDebitNoteHasNoCap(): array
    {
        $invoice = $this->issue();
        $corrections = app(Corrections::class);

        /*
         | Twice the invoice, in two documents, and both are legitimate.
         |
         | A customer can be charged more than once against one invoice for
         | different things, and the only ceiling on what may be charged is
         | what was agreed - which is not a number this system holds. A cap
         | here would refuse a correct document.
         */
        foreach (['1000.0000', '1500.0000'] as $amount) {
            try {
                app(Issuer::class)->issue(
                    $corrections->debitNote($invoice, [[
                        'description' => 'An additional charge of '.$amount,
                        'net' => $amount,
                    ]], $this->date),
                    $this->entity(), $this->actorId(), $this->decision,
                );
            } catch (Throwable $e) {
                return [sprintf(
                    'a debit note for %s against a 1,150.00 invoice was refused: %s. A debit note adds '.
                    'to what is owed, and what may be charged is what was agreed',
                    $amount,
                    substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 140),
                )];
            }
        }

        return [];
    }

    /** @return list<string> */
    public function aCorrectedInvoice(): array
    {
        $violations = [];
        $invoice = $this->issue();

        $corrected = app(Issuer::class)->issue(
            app(Corrections::class)->correctedInvoice($invoice, [[
                'description' => 'Advisory services, May 2026, at the agreed rate',
                'net' => '800.0000',
            ]], $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        if ((string) $corrected->gross_amount !== '920.0000') {
            $violations[] = sprintf(
                'a corrected invoice for 800.00 net comes to %s and should come to 920.0000',
                (string) $corrected->gross_amount,
            );
        }

        $entry = DB::connection('tenant')->table('journal_entries')
            ->where('source_id', (string) $corrected->getKey())
            ->first();

        if ($entry === null) {
            return [...$violations, 'a corrected invoice posted nothing'];
        }

        if ((string) $entry->posting_rule !== 'sales.corrected_invoice_issued') {
            $violations[] = sprintf('a corrected invoice posted through "%s"', (string) $entry->posting_rule);
        }

        /*
         | One entry, both halves: the receivable nets to the difference.
         |
         | 920 restated against 1,150 reversed is -230. Two entries would
         | reach the same place and read as two decisions taken at two
         | moments, and anybody reconciling the receivable would have to
         | find both and know they belong together.
         */
        $receivable = $this->movementOn($entry->id, $this->roleAccount('accounts_receivable'));

        if ($receivable !== '-230.0000') {
            $violations[] = sprintf(
                'the receivable moves by %s and a 920.00 replacement for a 1,150.00 invoice moves it '.
                'by -230.0000',
                $receivable ?? 'nothing',
            );
        }

        $tax = $this->movementOn($entry->id, $this->roleAccount('vat_output', 'SA'));

        if ($tax !== '30.0000') {
            $violations[] = sprintf(
                'the output tax moves by %s and should fall by 30.0000 - 150.00 reversed and 120.00 '.
                'restated',
                $tax ?? 'nothing',
            );
        }

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $entry->id)
            ->count();

        if ($lines < 6) {
            $violations[] = sprintf(
                'the entry has %d lines: a replacement has to show both halves - what the replaced '.
                'document said, reversed, and what this one says',
                $lines,
            );
        }

        /*
         | And the original's entry is untouched.
         |
         | It was posted correctly from the document that existed at the
         | time. What changed is the document, not the books - which is why
         | this is a posting rule and not `Reverser`.
         */
        $original = DB::connection('tenant')->table('journal_entries')
            ->where('source_id', (string) $invoice->getKey())
            ->first();

        if ($original !== null && ($original->reversed_at ?? null) !== null) {
            $violations[] = 'replacing an invoice marked the invoice\'s own entry reversed';
        }

        return $violations;
    }

    /** @return list<string> */
    public function onceOnly(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $corrections = app(Corrections::class);

        /*
         | Both drafts first, then both issues.
         |
         | Drafting the second after the first is issued is refused by the
         | service, which is correct and is not what this check is about -
         | the question is whether the DATABASE allows two replacements, and
         | two drafts prepared side by side is exactly the state two people
         | working on the same invoice produce.
         */
        $first = $corrections->correctedInvoice($invoice, [[
            'description' => 'The first replacement',
            'net' => '800.0000',
        ]], $this->date);

        $second = $corrections->correctedInvoice($invoice, [[
            'description' => 'A second replacement, prepared at the same time',
            'net' => '700.0000',
        ]], $this->date);

        app(Issuer::class)->issue($first, $this->entity(), $this->actorId(), $this->decision);

        try {
            app(Issuer::class)->issue(
                $second->refresh(), $this->entity(), $this->actorId(), $this->decision,
            );

            $violations[] = 'an invoice was replaced twice. Which one is the invoice?';
        } catch (Throwable) {
            // The primary key on `invoice_corrections` is the whole rule.
        }

        // And the service refuses to draft a third, by name.
        try {
            $corrections->correctedInvoice($invoice->refresh(), [[
                'description' => 'A third replacement',
                'net' => '600.0000',
            ]], $this->date);

            $violations[] = 'the service drafted a replacement for an invoice already replaced';
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'already been replaced')) {
                $violations[] = 'the refusal does not explain itself: '.$e->getMessage();
            }
        }

        $corrections_count = DB::connection('tenant')->table('invoice_corrections')
            ->where('corrected_invoice_id', (string) $invoice->getKey())
            ->count();

        if ($corrections_count !== 1) {
            $violations[] = sprintf('%d replacements are recorded against one invoice', $corrections_count);
        }

        return $violations;
    }

    /** @return list<string> */
    public function closedToAdjustment(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $corrections = app(Corrections::class);

        app(Issuer::class)->issue(
            $corrections->correctedInvoice($invoice, [[
                'description' => 'The replacement',
                'net' => '800.0000',
            ]], $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        /*
         | A credit note against the replaced original would reduce a
         | receivable that the replacement already restated, so the
         | correction would be counted twice - and both documents would be
         | defensible on their own.
         */
        $credit = app(Crediting::class)->full($invoice->refresh(), $this->date);

        try {
            app(Issuer::class)->issue($credit, $this->entity(), $this->actorId(), $this->decision);

            $violations[] = 'a replaced invoice was credited as well, so the correction is counted twice';
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'replaced')) {
                $violations[] = 'the refusal does not mention the replacement: '.
                    substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 140);
            }
        }

        try {
            $corrections->debitNote($invoice->refresh(), [[
                'description' => 'A charge against a replaced invoice',
                'net' => '100.0000',
            ]], $this->date);

            $violations[] = 'a replaced invoice was charged against';
        } catch (Throwable) {
            // As it should be.
        }

        return $violations;
    }

    /** @return list<string> */
    public function notAnInvoice(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $corrections = app(Corrections::class);

        $credit = app(Issuer::class)->issue(
            app(Crediting::class)->full($invoice, $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        foreach ([
            'a credit note' => $credit,
            'a draft' => $this->draft(),
        ] as $what => $document) {
            try {
                $corrections->correctedInvoice($document, [[
                    'description' => 'A replacement for something that is not an invoice',
                    'net' => '100.0000',
                ]], $this->date);

                $violations[] = sprintf('%s was replaced by a corrected invoice', $what);
            } catch (Throwable) {
                // As it should be.
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function theReferenceIsMandatory(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $corrections = app(Corrections::class);

        foreach ([
            'a debit note' => fn (): Invoice => $corrections->debitNote($invoice, [[
                'description' => 'A charge', 'net' => '50.0000',
            ]], $this->date),
            'a corrected invoice' => fn (): Invoice => $corrections->correctedInvoice($invoice, [[
                'description' => 'A replacement', 'net' => '900.0000',
            ]], $this->date),
        ] as $what => $build) {
            $savepoint = $this->savepoint();

            try {
                $document = $build();
                $document->references()->delete();

                app(Issuer::class)->issue(
                    $document->refresh(), $this->entity(), $this->actorId(), $this->decision,
                );

                $violations[] = sprintf(
                    '%s with no reference was issued. A document that adjusts another one and cannot '.
                    'say which one adjusts a balance nobody can trace to a supply',
                    $what,
                );
            } catch (Throwable $e) {
                if (! str_contains($e->getMessage(), 'BT-25')) {
                    $violations[] = sprintf(
                        'the refusal of %s does not cite BT-25: %s',
                        $what,
                        substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 140),
                    );
                }
            } finally {
                $this->rollbackTo($savepoint);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function theDutyIsSeparated(): array
    {
        $rule = DB::connection('tenant')->table('sod_rules')
            ->where('key', 'invoice_then_correct')
            ->first();

        if ($rule === null) {
            return ['no rule pairs issuing an invoice with replacing it. A corrected invoice restates '.
                'the original in full, which is the stronger of the two instruments: a credit note '.
                'leaves the invoice in the record to be read'];
        }

        $violations = [];

        if ((string) $rule->duty_a !== 'invoice.issue' || (string) $rule->duty_b !== 'corrected_invoice.issue') {
            $violations[] = 'the rule does not pair issuing with replacing';
        }

        if ((string) $rule->scope !== 'object') {
            $violations[] = sprintf(
                'the rule is scoped to %s, and the question is whether the same person did both to the '.
                'same document',
                (string) $rule->scope,
            );
        }

        /** @var array<string, list<string>> $map */
        $map = (array) config('sod.performed_as', []);

        if (! \in_array('corrected_invoice.issued', $map['corrected_invoice.issue'] ?? [], true)) {
            $violations[] = 'the duty is not mapped to the action that records it, so the engine would '.
                'look for a permission key in a column that holds past-tense actions and find nothing';
        }

        /*
         | The debit note is deliberately NOT paired with issuing.
         |
         | It increases what the customer owes, so the fraud it enables is
         | overcharging - a different control, with a different second
         | signature, and pairing it here would refuse the ordinary case of
         | the person who raised an invoice adding the freight they forgot.
         */
        $paired = DB::connection('tenant')->table('sod_rules')
            ->where('duty_b', 'debit_note.issue')
            ->orWhere('duty_a', 'debit_note.issue')
            ->exists();

        if ($paired) {
            $violations[] = 'a rule pairs issuing with a debit note, which refuses the ordinary case '.
                'of adding the freight somebody forgot';
        }

        return $violations;
    }

    private function roleAccount(string $role, ?string $jurisdiction = null): ?string
    {
        $id = DB::connection('tenant')->table('account_roles')
            ->where('entity_id', $this->entityId)
            ->where('role', $role)
            ->when($jurisdiction !== null, fn ($q) => $q->where('jurisdiction', $jurisdiction))
            ->value('account_id');

        return $id === null ? null : (string) $id;
    }

    private function movementOn(string $entryId, ?string $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }

        $sum = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $entryId)
            ->where('account_id', $accountId)
            ->sum('amount');

        return $sum === null ? null : (string) \App\Support\Money\Money::of((string) $sum, 'SAR')->amount;
    }
}
