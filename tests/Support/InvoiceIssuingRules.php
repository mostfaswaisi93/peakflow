<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Hashing\Canonicalizer;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Services\ChainVerifier;
use App\Tenant\Sales\Services\Issuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Issuing a document: the transaction, the number and the chain - PF-095, PF-093, PF-096.
 *
 * ## What is actually being claimed
 *
 * That the five concerns either all happen or none of them do, and that a
 * failure anywhere consumes nothing. A gap in an invoice series is a finding
 * in a tax audit, and the auditor's question is not "why is 41 missing" but
 * "what did you do with invoice 41".
 *
 * ## The order is the property, not a preference
 *
 * The series row is locked from the moment it is read until the transaction
 * commits. Allocate first and that lock is held through validation, the tax
 * computation and the posting - so every other invoice in the entity waits
 * behind the slowest document anybody is issuing. Two of the checks here read
 * the issuer's source for exactly that, because it is the one property a
 * single-threaded functional check cannot see.
 *
 * ## What the chain does NOT prove
 *
 * Authorship. Anyone who can write to this database can recompute a chain
 * from a tampered document onward and the result verifies. It proves internal
 * consistency: that nothing was changed by a process that did not know to
 * recompute, which is every ordinary accident and most ordinary tampering.
 * One of the checks here asserts that the code says so, because a system
 * trusted for something it cannot do is worse than no system.
 */
final class InvoiceIssuingRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\CapturesEvents;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['issuing (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'the number is allocated after everything that can fail' => fn (): array => $this->theNumberIsLast(),
                'nothing between the allocation and the commit touches the world' => fn (): array => $this->noIoInsideTheLock(),
                'a refusal consumes no number and leaves the draft a draft' => fn (): array => $this->aRefusalConsumesNothing(),
                'a rolled-back transaction returns the number' => fn (): array => $this->aRollbackReturnsTheNumber(),
                'issuing posts the receivable, the revenue and the output tax' => fn (): array => $this->thePosting(),
                'each document links to the one before it in its series' => fn (): array => $this->theChainLinks(),
                'the frozen bytes describe the stored row, not the model in memory' => fn (): array => $this->theBytesDescribeTheRow(),
                'the frozen bytes cover the substance and not the cosmetics' => fn (): array => $this->whatTheHashCovers(),
                'a document that no longer matches its bytes is caught, by field' => fn (): array => $this->tamperingIsCaught(),
                'a document removed from the middle of a series is caught' => fn (): array => $this->aGapIsCaught(),
                'the chain claims consistency and not authorship' => fn (): array => $this->theChainDoesNotOverclaim(),
                'issuing is audited' => fn (): array => $this->issuingIsAudited(),
                'issuing raises InvoiceIssued, once, with the document on it' => fn (): array => $this->theEventIsRaised(),
                'the place of supply is decided by the rule book and frozen with its reasoning' => fn (): array => $this->thePlaceIsFrozen(),
                'goods delivered abroad are not recorded as a domestic supply' => fn (): array => $this->anExportIsNotDomestic(),
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function theNumberIsLast(): array
    {
        $violations = [];
        $source = $this->issuerCode();

        $validate = strpos($source, 'validator->validate(');
        $post = strpos($source, 'this->post(');
        $allocate = strpos($source, 'numbers->next(');
        $chain = strpos($source, 'chainLink(');

        foreach ([
            'validation' => $validate,
            'the posting' => $post,
            'the allocation' => $allocate,
            'the chain link' => $chain,
        ] as $what => $position) {
            if ($position === false) {
                $violations[] = sprintf('the issuer does not call %s at all', $what);
            }
        }

        if ($violations !== []) {
            return $violations;
        }

        if ($validate > $post || $post > $allocate || $allocate > $chain) {
            $violations[] = sprintf(
                'the issuer\'s steps are out of order (validate at %d, post at %d, allocate at %d, '.
                'chain at %d). The series row is locked from the allocation until the commit, so '.
                'allocating before the posting holds the lock through it - and every other invoice in '.
                'the entity waits behind the slowest document anybody is issuing',
                $validate, $post, $allocate, $chain,
            );
        }

        /*
         | And the comment that says why a SEQUENCE cannot be used.
         |
         | Asserted because it is the single most likely thing for somebody
         | to "optimise": nextval() is faster, obviously correct-looking, and
         | silently leaves a gap on every rollback.
         */
        $allocator = (string) file_get_contents(
            \dirname(__DIR__, 2).'/app/Tenant/Ledger/Services/NumberAllocator.php',
        );

        foreach (['nextval', 'non-transactional', 'FOR UPDATE'] as $needle) {
            if (! str_contains($allocator, $needle)) {
                $violations[] = sprintf(
                    'the allocator does not mention "%s". The reason a PostgreSQL sequence cannot be '.
                    'used has to be written where somebody would reach for one',
                    $needle,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function noIoInsideTheLock(): array
    {
        $violations = [];
        $source = $this->issuerCode();

        foreach ([
            'a queued job' => 'dispatch(',
            'an HTTP call' => 'Http::',
            'mail' => 'Mail::',
            'a render' => '->render(',
            'a sleep' => 'sleep(',
        ] as $what => $needle) {
            if (str_contains($source, $needle)) {
                $violations[] = sprintf(
                    'the issuer contains %s. A tax authority that takes thirty seconds to answer must '.
                    'not hold the invoice sequence for thirty seconds, and a mail server that hangs '.
                    'must not stop the business invoicing',
                    $what,
                );
            }
        }

        $issuer = app(Issuer::class);
        $invoice = $this->issue();
        $jobs = array_column($issuer->afterCommit($invoice), 'job');

        foreach (['render.pdf', 'deliver', 'clearance.submit'] as $job) {
            if (! \in_array($job, $jobs, true)) {
                $violations[] = sprintf(
                    'the post-commit work does not include "%s", so either it happens inside the '.
                    'transaction or it does not happen', $job,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function aRefusalConsumesNothing(): array
    {
        $violations = [];
        $issuer = app(Issuer::class);

        $cases = [
            'a draft with no lines' => function (): Invoice {
                $draft = $this->draft();
                $draft->lines()->delete();

                return $draft->refresh();
            },
            'a tax total that disagrees with the breakdown' => function (): Invoice {
                $draft = $this->draft();
                $draft->taxBreakdown()->update(['tax_amount' => '9.0000']);

                return $draft->refresh();
            },
            'a line with no revenue account' => function (): Invoice {
                $draft = $this->draft();
                $draft->lines()->update(['revenue_account_id' => null]);

                return $draft->refresh();
            },
            'a customer with no legal name to freeze' => function (): Invoice {
                /*
                 | A failure at the FREEZE step, past validation.
                 |
                 | PF-095's criterion is a failure injected at each of the
                 | five steps, and this is the one that is reachable from
                 | outside: the validator has no opinion about a blank legal
                 | name and `Snapshots` refuses to freeze one, because a
                 | document that names nobody is not a document.
                 */
                $draft = $this->draft();
                DB::connection('tenant')->table('parties')
                    ->where('id', (string) $this->party?->getKey())
                    ->update(['legal_name' => ' ']);

                return $draft->refresh();
            },
            'a line with no tax code' => function (): Invoice {
                $draft = $this->draft();
                $draft->lines()->update(['tax_code_id' => null, 'tax_point' => null]);

                return $draft->refresh();
            },
        ];

        foreach ($cases as $what => $build) {
            $savepoint = $this->savepoint();

            try {
                $before = $this->nextNumber();
                $entries = $this->journalEntries();
                $draft = $build();

                try {
                    $issued = $issuer->issue($draft, $this->entity(), $this->actorId(), $this->decision);

                    $violations[] = sprintf(
                        '%s was issued as %s. It should have been refused while nothing was consumed',
                        $what, (string) $issued->number,
                    );
                } catch (Throwable) {
                    if ($this->nextNumber() !== $before) {
                        $violations[] = sprintf(
                            '%s was refused and consumed a number anyway (%s became %s). A gap in an '.
                            'invoice series is a finding in a tax audit, and the question is not why 41 '.
                            'is missing but what you did with invoice 41',
                            $what, (string) $before, (string) $this->nextNumber(),
                        );
                    }

                    if ($this->journalEntries() !== $entries) {
                        $violations[] = sprintf('%s was refused and left a journal entry behind', $what);
                    }

                    if (! $draft->refresh()->isDraft()) {
                        $violations[] = sprintf('%s was refused and left the document issued', $what);
                    }
                }
            } finally {
                $this->rollbackTo($savepoint);
            }
        }

        return $violations;
    }

    /**
     * The property a `SEQUENCE` does not have.
     *
     * ## What this can and cannot observe
     *
     * The whole suite runs inside one transaction, so an allocator that
     * advanced its counter on a second connection could not see the series
     * row at all - and the failure surfaces as "the counter stopped moving"
     * rather than as "it was left at 2". Both are red, and the second branch
     * is kept because in production there is no outer transaction and that is
     * the form the failure takes there.
     *
     * Measured against a patched allocator with the row lock removed and the
     * increment moved to its own connection: red, on the first branch.
     *
     * @return list<string>
     */
    public function aRollbackReturnsTheNumber(): array
    {
        $violations = [];

        /*
         | One document first, so the series row exists before anything is
         | measured.
         |
         | Measured: without it this check passed against a deliberately
         | non-transactional allocator. The series row is created on first
         | use, so `$before` was null, the row's own creation counted as
         | "the series advanced", and the rollback removing the row counted
         | as "the number came back" - a check that watched a row appear and
         | disappear and never once watched a counter.
         */
        $this->issue();
        $before = $this->nextNumber();

        if ($before === null) {
            return ['the series row does not exist after a document was issued into it'];
        }

        $savepoint = $this->savepoint();

        try {
            $allocated = app(NumberAllocator::class)->next(
                $this->entityId, 'sales_document', 'INV', $this->date,
            );

            if ($allocated === '') {
                $violations[] = 'the allocator returned nothing';
            }

            if ($this->nextNumber() === $before) {
                $violations[] = 'allocating a number did not advance the series, so this check cannot '.
                    'tell a rollback from a no-op';
            }
        } finally {
            $this->rollbackTo($savepoint);
        }

        if ($this->nextNumber() !== $before) {
            $violations[] = sprintf(
                'a rolled-back allocation left the series at %s instead of %s. `nextval()` behaves '.
                'exactly this way - it is deliberately non-transactional - which is why the counter is '.
                'a row locked with SELECT ... FOR UPDATE',
                (string) $this->nextNumber(), (string) $before,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function thePosting(): array
    {
        $violations = [];
        $invoice = $this->issue();

        $entry = DB::connection('tenant')->table('journal_entries')
            ->where('source_type', 'invoice')
            ->where('source_id', (string) $invoice->getKey())
            ->first();

        if ($entry === null) {
            return ['issuing produced no journal entry'];
        }

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $entry->id)
            ->get();

        $amountOn = function (ControlRole $role, ?string $jurisdiction) use ($lines): ?string {
            $accountId = AccountRole::query()
                ->where('entity_id', $this->entityId)
                ->where('role', $role->value)
                ->when($jurisdiction !== null, fn ($q) => $q->where('jurisdiction', $jurisdiction))
                ->value('account_id');

            $line = $lines->firstWhere('account_id', $accountId);

            return $line === null ? null : (string) $line->amount;
        };

        foreach ([
            'the receivable' => [$amountOn(ControlRole::AccountsReceivable, null), '1150.0000'],
            'the output tax' => [$amountOn(ControlRole::VatOutput, 'SA'), '-150.0000'],
        ] as $what => [$actual, $expected]) {
            if ($actual !== $expected) {
                $violations[] = sprintf('%s is %s and should be %s', $what, $actual ?? 'absent', $expected);
            }
        }

        $revenue = $lines->firstWhere('account_id', $this->revenueAccountId);

        if ($revenue === null || (string) $revenue->amount !== '-1000.0000') {
            $violations[] = sprintf(
                'the revenue line is %s and should be -1000.0000 against the line\'s own revenue account',
                $revenue === null ? 'absent' : (string) $revenue->amount,
            );
        }

        /*
         | And the revenue line carries the tax code and the tax point, which
         | is what puts it in a return's taxable amount - PF-086.
         */
        if ($revenue !== null && ($revenue->tax_code_id === null || $revenue->tax_point === null)) {
            $violations[] = 'the revenue line carries no tax code and tax point, so the return would '.
                'show tax with no taxable amount behind it';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theChainLinks(): array
    {
        $violations = [];
        $genesis = str_repeat('0', 64);

        $first = $this->issue();
        $second = $this->issue();
        $third = $this->issue();

        if ($first->chain_index !== 1 || (string) $first->previous_hash !== $genesis) {
            $violations[] = sprintf(
                'the first document in a series is index %s with previous %s, and should be 1 with the '.
                'genesis hash',
                (string) $first->chain_index, substr((string) $first->previous_hash, 0, 8),
            );
        }

        foreach ([[$second, $first], [$third, $second]] as [$document, $previous]) {
            if ((string) $document->previous_hash !== (string) $previous->canonical_hash) {
                $violations[] = sprintf('%s does not link to %s', (string) $document->number, (string) $previous->number);
            }

            if ($document->chain_index !== $previous->chain_index + 1) {
                $violations[] = sprintf(
                    '%s is index %d and follows index %d',
                    (string) $document->number, (int) $document->chain_index, (int) $previous->chain_index,
                );
            }
        }

        $walk = app(ChainVerifier::class)->verify($this->entityId, 'INV');

        if (! $walk['verified']) {
            $violations[] = 'a chain of three documents issued through the real path does not verify: '.
                ($walk['divergence']['reason'] ?? '');
        }

        if ($walk['documents'] !== 3) {
            $violations[] = sprintf('the walk saw %d documents and should see 3', $walk['documents']);
        }

        return $violations;
    }

    /*
     | Two series not sharing a chain is proved in `CreditNoteRules`.
     |
     | It used to be proved here, by issuing a bare credit note - which the
     | database now refuses, correctly: a credit note with no reference to
     | the invoice it credits is not a credit note (EN 16931 BT-25). The
     | claim is the same and the fixture has to be a real credit note, which
     | belongs in the suite that builds one.
     */

    /**
     * The hash is taken over the row, not over the model that wrote it.
     *
     * @return list<string>
     */
    public function theBytesDescribeTheRow(): array
    {
        /*
         | The draft is handed over exactly as `create()` left it.
         |
         | Measured: hashing the in-memory attributes froze `totals.prepaid`
         | as absent while the stored row holds `0.0000`, and three genuine
         | documents - issued through the real path, untouched afterwards -
         | were reported as diverging at `totals.prepaid`: "it was (absent)
         | and is now 0.0000". A verifier that cries tampering over a column
         | default is a verifier nobody runs twice.
         */
        $invoice = app(Issuer::class)->issue(
            $this->draft(DocumentType::Invoice, readBack: false),
            $this->entity(),
            $this->actorId(),
            $this->decision,
            $this->terms,
        );

        $walk = app(ChainVerifier::class)->verify($this->entityId, 'INV');

        if ($walk['verified']) {
            return [];
        }

        $divergence = $walk['divergence'];

        return [sprintf(
            'a document issued from an unread draft does not verify: %s at %s. The frozen bytes have '.
            'to describe the stored row, because the stored row is what anybody will ever re-read',
            (string) ($divergence['field'] ?? 'an unnamed field'),
            (string) ($divergence['number'] ?? $invoice->number),
        )];
    }

    /** @return list<string> */
    public function whatTheHashCovers(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $canonical = (string) $invoice->canonicalForm();

        if ($canonical === '') {
            return ['the issued document has no frozen canonical form'];
        }

        if (hash('sha256', $canonical) !== (string) $invoice->canonical_hash) {
            $violations[] = 'the stored hash is not a digest of the stored bytes';
        }

        $decoded = json_decode($canonical, true);

        if (! \is_array($decoded) || Canonicalizer::encode($decoded) !== $canonical) {
            $violations[] = 'the frozen bytes are not canonical, so a reprint computed elsewhere would '.
                'differ';
        }

        foreach (['number', 'totals', 'customer', 'supplier', 'lines', 'breakdown', 'tax_decision'] as $field) {
            if (! \is_array($decoded) || ! array_key_exists($field, $decoded)) {
                $violations[] = sprintf(
                    'the hash does not cover "%s". A material change has to invalidate the chain', $field,
                );
            }
        }

        /*
         | And what it must NOT cover.
         |
         | A cosmetic change must not invalidate a chain: an issued document's
         | note is not part of what was agreed, and a chain that broke when
         | somebody fixed a typo in one would be a chain people learn to
         | ignore.
         */
        foreach (['note', 'created_at', 'updated_at', 'presentation_exponent', 'issued_by'] as $cosmetic) {
            if (\is_array($decoded) && array_key_exists($cosmetic, $decoded)) {
                $violations[] = sprintf(
                    'the hash covers "%s", which is cosmetic. A chain that breaks when somebody fixes a '.
                    'typo is a chain people learn to ignore', $cosmetic,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function tamperingIsCaught(): array
    {
        $violations = [];
        $genuine = $this->issue();

        /*
         | A document that disagrees with its own frozen bytes.
         |
         | Constructed rather than edited, because the application role cannot
         | edit an issued invoice at all - the trigger refuses, and the role
         | cannot disable the trigger either, which is PF-010 doing its job.
         | A row inserted as issued with a canonical form describing a
         | different net is the state a tampered row ends up in, and the state
         | the verifier has to catch.
         */
        $lying = json_decode((string) $genuine->canonical, true);
        \assert(\is_array($lying));

        $lying['number'] = 'INV-TAMPERED';
        $lying['totals']['net'] = '9999.0000';
        $bytes = Canonicalizer::encode($lying);

        DB::connection('tenant')->table('invoices')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'number' => 'INV-TAMPERED',
            'series_key' => 'INV',
            'document_type' => '380',
            'status' => 'issued',
            'party_id' => (string) $this->party?->getKey(),
            'issue_date' => $this->date->toDateString(),
            'tax_point' => $this->date->toDateString(),
            'currency' => 'SAR',
            'net_amount' => '1000.0000',
            'tax_amount' => '150.0000',
            'gross_amount' => '1150.0000',
            'payable_amount' => '1150.0000',
            'presentation_exponent' => 2,
            'supplier_snapshot' => json_encode($lying['supplier']),
            'customer_snapshot' => json_encode($lying['customer']),
            'tax_decision_snapshot' => json_encode($lying['tax_decision']),
            'issued_at' => now(),
            'issued_by' => $this->actorId(),
            'canonical' => $bytes,
            'canonical_hash' => hash('sha256', $bytes),
            'previous_hash' => (string) $genuine->canonical_hash,
            'chain_index' => (int) $genuine->chain_index + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $walk = app(ChainVerifier::class)->verify($this->entityId, 'INV');

        if ($walk['verified']) {
            $violations[] = 'a document whose contents do not match its frozen bytes verified. The '.
                'chain exists to catch exactly this';

            return $violations;
        }

        $divergence = $walk['divergence'];

        if (($divergence['number'] ?? '') !== 'INV-TAMPERED') {
            $violations[] = sprintf(
                'the walk reports the divergence at %s and should report it at the tampered document - '.
                'the first one, because after it every later link is wrong too',
                (string) ($divergence['number'] ?? 'nothing'),
            );
        }

        if (($divergence['field'] ?? null) === null) {
            $violations[] = 'the divergence names no field. A report that says only "this is not what '.
                'it was" is true, unhelpful, and what a verifier holding a hash alone can manage - '.
                'which is why the canonical form is stored';
        }

        return $violations;
    }

    /** @return list<string> */
    public function aGapIsCaught(): array
    {
        $this->issue();
        $second = $this->issue();

        /*
         | A series that jumps from 2 to 4.
         |
         | Made by inserting the later document rather than by renumbering an
         | existing one, because the application role cannot renumber an
         | issued invoice at all - `invoices_are_immutable_once_issued`
         | refuses the UPDATE and the role cannot disable the trigger, which
         | is PF-010 doing its job. What is left is the shape this check
         | cares about: a document at index 4 with nothing at 3, which is what
         | a series looks like after an invoice has been issued and then made
         | to disappear.
         */
        $bytes = (string) $second->canonical;
        $payload = json_decode($bytes, true);
        \assert(\is_array($payload));

        DB::connection('tenant')->table('invoices')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'number' => 'INV-GAP',
            'series_key' => 'INV',
            'document_type' => '380',
            'status' => 'issued',
            'party_id' => (string) $this->party?->getKey(),
            'issue_date' => $this->date->toDateString(),
            'tax_point' => $this->date->toDateString(),
            'currency' => 'SAR',
            'net_amount' => '1000.0000',
            'tax_amount' => '150.0000',
            'gross_amount' => '1150.0000',
            'payable_amount' => '1150.0000',
            'presentation_exponent' => 2,
            'supplier_snapshot' => json_encode($payload['supplier']),
            'customer_snapshot' => json_encode($payload['customer']),
            'tax_decision_snapshot' => json_encode($payload['tax_decision']),
            'issued_at' => now(),
            'issued_by' => $this->actorId(),
            'canonical' => $bytes,
            'canonical_hash' => hash('sha256', $bytes),
            'previous_hash' => (string) $second->canonical_hash,
            'chain_index' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $walk = app(ChainVerifier::class)->verify($this->entityId, 'INV');

        if ($walk['verified']) {
            return ['a chain that jumps from 2 to 4 verified. A document has been removed from the '.
                'middle of the series, which is the shape of an invoice that was issued and then made '.
                'to disappear'];
        }

        return ($walk['divergence']['field'] ?? null) === 'chain_index'
            ? []
            : [sprintf(
                'the gap was reported as "%s" rather than as a chain index problem. The index is '.
                'checked before the contents on purpose: "index 3 is missing" is the finding an '.
                'auditor acts on, and "document 4 does not match its bytes" is not',
                (string) ($walk['divergence']['field'] ?? 'nothing'),
            )];
    }

    /** @return list<string> */
    public function theChainDoesNotOverclaim(): array
    {
        $violations = [];

        foreach ([
            'app/Tenant/Sales/Services/ChainVerifier.php',
            'app/Console/Commands/InvoicesVerifyChain.php',
        ] as $relative) {
            $source = (string) file_get_contents(\dirname(__DIR__, 2).'/'.$relative);

            if (! str_contains($source, 'not') || ! str_contains($source, 'authorship')) {
                $violations[] = sprintf(
                    '%s does not say that the chain proves internal consistency rather than '.
                    'authorship. Anyone who can write to this database can recompute a chain from a '.
                    'tampered document onward and the result verifies - claiming more than that is how '.
                    'a system comes to be trusted for something it cannot do',
                    $relative,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function issuingIsAudited(): array
    {
        $violations = [];
        $invoice = $this->issue();

        $record = DB::connection('tenant')->table('audit_log')
            ->where('action', 'invoice.issued')
            ->orderByDesc('created_at')
            ->first();

        if ($record === null) {
            return ['issuing recorded nothing in the audit trail'];
        }

        $after = json_decode((string) ($record->after_state ?? '{}'), true);

        foreach (['number', 'gross', 'hash', 'chain_index', 'journal_entry'] as $key) {
            if (! \is_array($after) || ! array_key_exists($key, $after)) {
                $violations[] = sprintf('the audit record does not carry "%s"', $key);
            }
        }

        if (\is_array($after) && ($after['number'] ?? null) !== $invoice->number) {
            $violations[] = 'the audit record names a different document';
        }

        return $violations;
    }

    private function issuerSource(): string
    {
        return (string) file_get_contents(
            \dirname(__DIR__, 2).'/app/Tenant/Sales/Services/Issuer.php',
        );
    }

    /**
     * The issuer's code with its comments removed.
     *
     * Because the issuer documents at length that it contains no `dispatch()`,
     * and a check that greps the raw file would be failed by that very
     * sentence - a check that can only pass if the reasoning behind it is
     * deleted.
     */
    private function issuerCode(): string
    {
        $code = '';

        foreach (token_get_all($this->issuerSource()) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
    /**
     * Issuing raises the event, once, with the document on it - PF-048.
     *
     * ## Why this is a check and not a line in the docblock
     *
     * `Issuer::issueNow()` is a long transaction and the event is dispatched
     * in its last few lines - after the posting, the chain link and the
     * audit entry, which is the correct place and also the place an early
     * `return` skips. There are several returns above it; each one is right,
     * and the next one added will not know about this.
     *
     * The payload is asserted, not only the arrival. An event with the right
     * class and a blank number is an event whose listener writes a blank
     * number into a webhook.
     *
     * @return list<string>
     */
    private function theEventIsRaised(): array
    {
        $issued = null;

        $caught = $this->captureEvents(
            \App\Tenant\Sales\Events\InvoiceIssued::class,
            function () use (&$issued): void {
                $issued = $this->issue();
            },
        );

        if (\count($caught) !== 1) {
            return [sprintf(
                'issuing raised InvoiceIssued %d times, expected once. Nothing raised is a '.
                'declaration with no mechanism; twice makes every posting listener post twice',
                \count($caught),
            )];
        }

        $event = $caught[0];
        $violations = [];

        if (! $event instanceof \App\Support\Events\FinancialEvent) {
            $violations[] = 'InvoiceIssued does not implement FinancialEvent, so nothing forces its '.
                'posting to be synchronous';
        } elseif ($event->eventKey() !== 'sales.invoice.issued') {
            $violations[] = sprintf('its eventKey is "%s", and the audit entry reads that', $event->eventKey());
        }

        if ($issued !== null && $event->invoiceId !== (string) $issued->getKey()) {
            $violations[] = sprintf(
                'the event names document %s and the issued one is %s',
                $event->invoiceId,
                (string) $issued->getKey(),
            );
        }

        if ($issued !== null && $event->number !== (string) $issued->number) {
            $violations[] = sprintf(
                'the event carries number "%s" and the document is "%s"',
                $event->number,
                (string) $issued->number,
            );
        }

        if ($issued !== null && (string) $event->gross !== (string) \App\Support\Money\Money::of(
            (string) $issued->gross_amount,
            (string) $issued->currency,
        )) {
            $violations[] = sprintf(
                'the event carries %s and the document is %s %s',
                (string) $event->gross,
                (string) $issued->gross_amount,
                (string) $issued->currency,
            );
        }

        return $violations;
    }

    /**
     * The place of supply is decided, and frozen with its reasoning - PF-078.
     *
     * ## The defect this closes, which was not a missing panel
     *
     * `PlaceOfSupply::decide()` - eight points, sixteen asserted cases, a
     * vendored bloc register, a precedence integer per row and a dated UK
     * departure - **had no caller anywhere in the application**. Six draft
     * services wrote `'place_of_supply_rule' => 'domestic'` as a literal,
     * three of them with `'SA'` beside it, and `InvoiceApiController` handed
     * the issuer `['rule' => 'domestic']`.
     *
     * So the validator's `place_of_supply_unresolved` gate - which refuses a
     * line whose place of supply is unknown, on the grounds that "a default
     * would issue a document in a jurisdiction nobody chose" - was satisfied
     * by exactly that default, written one layer up.
     *
     * PF-078's last criterion asks for the decision and its reasoning on the
     * invoice's tax panel, because an accountant must be able to check it. A
     * panel over the literal would have shown a decision nobody made, which
     * is worse than no panel: it reads as checked.
     *
     * ## What is asserted
     *
     * The winning rule's id, its precedence and the source it cites, because
     * those are what "check it" means - an accountant disagreeing with a
     * place of supply asks which rule chose it and what it quotes. And the
     * explanation in both languages, because the panel shows the one for the
     * reader's locale.
     *
     * @return list<string>
     */
    private function thePlaceIsFrozen(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $snapshot = $invoice->tax_decision_snapshot['place_of_supply'] ?? null;

        if (! \is_array($snapshot)) {
            return ['the document carries no place-of-supply decision at all, so the panel has '.
                'nothing to show and the rule book was never asked'];
        }

        if (($snapshot['decided'] ?? false) !== true) {
            return [sprintf(
                'the decision was not made: %s',
                (string) ($snapshot['why'] ?? 'no reason recorded, which is its own problem'),
            )];
        }

        $line = $snapshot['lines'][0] ?? null;

        if (! \is_array($line)) {
            return ['the decision carries no per-line reasoning, and the lines are what a rule '.
                'applies to - one invoice can carry a good and a digital service'];
        }

        foreach (['country', 'rule', 'category', 'source', 'explanation_en', 'explanation_ar'] as $field) {
            if (! \is_string($line[$field] ?? null) || trim((string) $line[$field]) === '') {
                $violations[] = sprintf('the frozen decision has no %s on it', $field);
            }
        }

        if (! \is_int($line['precedence'] ?? null)) {
            $violations[] = 'the frozen decision carries no precedence, so the rule cannot be found '.
                'in a book that is ordered by it';
        }

        /*
         | The rule id is a rule the book actually has.
         |
         | The literal was `'domestic'`, which IS a rule id - the lowest
         | precedence one, the documented fallback - so a check that only
         | asserted "there is a rule" would have passed against the defect.
         | Asserted against the book's own ids instead.
         */
        $known = array_map(
            static fn (array $rule): string => (string) $rule['id'],
            (array) config('place_of_supply.rules', []),
        );

        if (! \in_array((string) $line['rule'], $known, true)) {
            $violations[] = sprintf(
                'the document names rule "%s" and the book has no such rule, so nothing can be '.
                'checked against it',
                (string) $line['rule'],
            );
        }

        /*
         | And the columns agree with the snapshot.
         |
         | Two places record the same decision: the snapshot an accountant
         | reads and the two columns a query filters on. `Issuer` falls back
         | to `lines()->value('place_of_supply')` in three places, and those
         | fallbacks were reading the literal nobody decided.
         */
        $stored = $invoice->lines()->first();

        if ($stored === null || (string) $stored->place_of_supply_rule !== (string) $line['rule']) {
            $violations[] = sprintf(
                'the line column says "%s" and the snapshot says "%s" - two answers to which rule '.
                'applied, and three readers take the column',
                (string) ($stored->place_of_supply_rule ?? 'null'),
                (string) $line['rule'],
            );
        }

        return $violations;
    }

    /**
     * Goods delivered abroad are not a domestic supply - PF-078.
     *
     * ## Why this is the check that matters
     *
     * `thePlaceIsFrozen()` proves a decision was made and recorded. It does
     * not prove the decision is the RIGHT one, and the defect it replaced -
     * a hard-coded `'domestic'` - would have satisfied a weaker version of
     * it, because the entity here is domestic and `domestic` is a real rule
     * in the book.
     *
     * So this one changes the facts and asserts the answer changes with
     * them: the same line, as goods, delivered outside the supplier's state,
     * must not come back as a supply in the supplier's state. The book's
     * highest-precedence rule exists for exactly this distinction - a
     * customer collecting goods from the supplier's warehouse IS a domestic
     * supply, and goods that left are not.
     *
     * An export recorded as domestic charges output tax that is not due,
     * puts it in the standard-rate box of the return, and is found by an
     * authority comparing the return against the customs declarations.
     *
     * @return list<string>
     */
    private function anExportIsNotDomestic(): array
    {
        $draft = $this->draft();

        /*
         | Goods, and they went to Jordan.
         |
         | Jordan rather than a GCC state deliberately: an intra-GCC supply
         | has rules of its own at precedences 20 and 30, and this check is
         | about the plain export. The customer stays domestic, so the ONLY
         | fact that changed is where the goods went - which is the fact a
         | hard-coded rule cannot see.
         */
        DB::connection('tenant')->table('invoices')
            ->where('id', (string) $draft->getKey())
            ->update(['delivery_country' => 'JO', 'updated_at' => now()]);

        DB::connection('tenant')->table('invoice_lines')
            ->where('invoice_id', (string) $draft->getKey())
            ->update(['supply_type' => 'good', 'updated_at' => now()]);

        $decision = app(\App\Tenant\Sales\Services\SupplyDecider::class)
            ->decide($draft->refresh(), $this->entity(), $this->date);

        $line = $decision['lines'][0] ?? null;

        if (! \is_array($line) || ($line['decided'] ?? false) !== true) {
            return [sprintf(
                'goods delivered to Jordan could not be placed: %s',
                \is_array($line) ? (string) ($line['why'] ?? '') : 'no line in the decision',
            )];
        }

        $violations = [];

        if ((string) $line['rule'] === 'goods-delivered-in-the-supplier-state') {
            $violations[] = 'goods that went to Jordan were placed by the rule for goods that did '.
                'NOT move, so the delivery location is not reaching the book';
        }

        if ((string) $line['rule'] === 'domestic') {
            $violations[] = 'goods delivered abroad fell through to the documented fallback, which '.
                'is the rule the hard-coded literal named - so either the engine is not being '.
                'asked or the export rows are not matching';
        }

        if ((string) $line['country'] === (string) $this->entity()->country
            && (string) $line['category'] === 'S') {
            $violations[] = sprintf(
                'an export was placed in %s at the standard rate, which charges output tax that is '.
                'not due and puts it in the standard-rate box of the return',
                (string) $line['country'],
            );
        }

        return $violations;
    }

}
