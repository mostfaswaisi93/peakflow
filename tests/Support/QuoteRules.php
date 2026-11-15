<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Sales\Enums\QuoteState;
use App\Tenant\Sales\Models\Quote;
use App\Tenant\Sales\Models\SalesOrder;
use App\Tenant\Sales\Services\QuoteOutcomes;
use App\Tenant\Sales\Services\Quotes;
use App\Tenant\Tenancy\Services\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Quotes: the life of an offer - PF-089.
 *
 * ## A quote is not an accounting document
 *
 * No number from a legal series, no posting, no tax point, no place in a hash
 * chain. The checks here assert all four absences, because the easy way to
 * build quotes is to add a status to the invoice table - and then a document
 * with none of an invoice's obligations sits in the table every report, every
 * return and every chain walk reads, and the first missing status filter puts
 * a quote in a VAT return.
 *
 * ## Revising, rather than editing
 *
 * A sent quote is a thing somebody received, so a revision is a new version
 * and the old one is kept and closed. Both halves matter: deleting it loses
 * the record of what was offered, and leaving it open means two prices are
 * live at once and the customer picks.
 */
final class QuoteRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['quotes (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'a quote posts nothing and takes no legal number' => fn (): array => $this->weightless(),
                'the states it can reach are the ones it should' => fn (): array => $this->theStateMachine(),
                'a revision keeps the old version and closes it' => fn (): array => $this->aRevision(),
                'two versions are comparable, line by line' => fn (): array => $this->theComparison(),
                'a quote that has lapsed cannot be accepted' => fn (): array => $this->lapsed(),
                'expiry closes what has lapsed and nothing else' => fn (): array => $this->expiry(),
                'an acceptance names somebody, and is recorded' => fn (): array => $this->theAcceptance(),
                'accepting produces an order, or an invoice where there is no order step' => fn (): array => $this->theOutcome(),
                'a quote cannot become two things' => fn (): array => $this->onceOnly(),
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function weightless(): array
    {
        $violations = [];
        $entries = $this->journalEntries();
        $invoiceNumber = $this->nextNumber();

        $quote = $this->quote();

        if ($this->journalEntries() !== $entries) {
            $violations[] = 'drafting a quote posted a journal entry. A quote is an offer: nothing has '.
                'been agreed, supplied or owed';
        }

        if ($this->nextNumber() !== $invoiceNumber) {
            $violations[] = 'a quote consumed a number from the invoice series, which leaves a hole in '.
                'a legal sequence for a document that may never exist';
        }

        if (! str_starts_with((string) $quote->reference, 'QT-')) {
            $violations[] = sprintf(
                'the quote reference is %s and should come from the quote series',
                (string) $quote->reference,
            );
        }

        /*
         | And no row in `invoices`, which is the structural claim.
         |
         | The easy way to build quotes is a status on the invoice table.
         | Then a document with none of an invoice's obligations sits in the
         | table every report, every return and every chain walk reads.
         */
        $inInvoices = DB::connection('tenant')->table('invoices')
            ->where('entity_id', $this->entityId)
            ->count();

        if ($inInvoices !== 0) {
            $violations[] = 'a quote created a row in the invoices table';
        }

        foreach (['tax_point', 'canonical_hash'] as $column) {
            if (DB::connection('tenant')->getSchemaBuilder()->hasColumn('quotes', $column)) {
                $violations[] = sprintf(
                    'the quotes table has a "%s" column, which is an invoice\'s obligation: a quote '.
                    'has no tax point and no place in a chain',
                    $column,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function theStateMachine(): array
    {
        $violations = [];
        $quotes = app(Quotes::class);
        $quote = $this->quote();

        // A draft cannot be accepted: nobody has been shown it.
        try {
            $quotes->accept($quote, 'Somebody');

            $violations[] = 'a draft quote was accepted, which is an agreement to a price nobody sent';
        } catch (Throwable) {
            // As it should be.
        }

        $quote = $quotes->send($quote);

        if ($quote->status !== QuoteState::Sent) {
            $violations[] = 'sending a quote did not change its state';
        }

        if ($quote->sent_at === null) {
            $violations[] = 'a sent quote has no sent timestamp, so nobody can say when the customer '.
                'was told';
        }

        // And a closed quote stays closed.
        $declined = $quotes->decline($this->sent(), 'the budget moved');

        foreach ([
            'accepted after declining' => fn (): mixed => $quotes->accept($declined, 'Somebody'),
            'sent again after declining' => fn (): mixed => $quotes->send($declined),
        ] as $what => $attempt) {
            try {
                $attempt();

                $violations[] = sprintf(
                    'a declined quote was %s. Re-opening it changes what the customer was told with no '.
                    'record of the change, which is what a revision is for',
                    $what,
                );
            } catch (Throwable) {
                // As it should be.
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function aRevision(): array
    {
        $violations = [];
        $quotes = app(Quotes::class);
        $original = $this->sent();

        $revision = $quotes->revise($original, $this->lines('90.00000000'));

        if ((int) $revision->version !== 2) {
            $violations[] = sprintf('the revision is version %d', (int) $revision->version);
        }

        if ((string) $revision->reference !== (string) $original->reference) {
            $violations[] = 'the revision has a different reference. It is the same conversation: a '.
                'customer asked for a change to one quote and what they get back is version 2 of it, '.
                'not an unrelated offer';
        }

        if ($original->refresh()->status !== QuoteState::Superseded) {
            $violations[] = sprintf(
                'the original is %s after being revised, so two prices are live at once and the '.
                'customer picks',
                $original->status->value,
            );
        }

        if ((string) $revision->supersedes_id !== (string) $original->getKey()) {
            $violations[] = 'the revision does not say what it replaces';
        }

        // The old version is still readable: "the price I was sent" is a
        // question about a document that existed.
        if (Quote::query()->whereKey((string) $original->getKey())->doesntExist()) {
            $violations[] = 'the superseded version was deleted, so what the customer was offered '.
                'cannot be reconstructed';
        }

        // And it cannot be accepted any more.
        try {
            $quotes->accept($original->refresh(), 'Somebody');

            $violations[] = 'a superseded version was accepted';
        } catch (Throwable) {
            // As it should be.
        }

        // Nor revised twice into two live versions.
        try {
            $quotes->revise($original->refresh(), $this->lines('80.00000000'));

            $violations[] = 'a superseded version was revised again, which produces two version 2s';
        } catch (Throwable) {
            // As it should be.
        }

        return $violations;
    }

    /** @return list<string> */
    public function theComparison(): array
    {
        $violations = [];
        $quotes = app(Quotes::class);
        $original = $this->sent();

        $revision = $quotes->revise($original, [
            ...$this->lines('90.00000000'),
            [
                'description' => 'Extended warranty, 24 months',
                'quantity' => '1.00000000',
                'unit_price' => '250.00000000',
                'tax_code_id' => $this->taxCodeId,
                'tax_rate' => '15.000000',
                'tax_category' => 'S',
                'revenue_account_id' => $this->revenueAccountId,
            ],
        ]);

        $diff = $quotes->compare($original->refresh(), $revision);

        if (\count($diff['changed']) !== 1) {
            $violations[] = sprintf(
                '%d lines are reported as changed and one was: the price of the first line moved',
                \count($diff['changed']),
            );
        }

        if (\count($diff['added']) !== 1) {
            $violations[] = sprintf('%d lines are reported as added and one was', \count($diff['added']));
        }

        if ($diff['removed'] !== []) {
            $violations[] = 'a line is reported as removed and none was';
        }

        if ($diff['totals']['difference'] === '0.0000') {
            $violations[] = 'the comparison reports no difference in the totals, which is the figure '.
                'the customer reads first';
        }

        /*
         | And re-ordering the lines is not a change.
         |
         | A diff that said it was would make every revision look like a
         | rewrite, and a customer reading "everything changed" checks
         | nothing.
         */
        $reordered = $quotes->revise($revision, array_reverse([
            ...$this->lines('90.00000000'),
            [
                'description' => 'Extended warranty, 24 months',
                'quantity' => '1.00000000',
                'unit_price' => '250.00000000',
                'tax_code_id' => $this->taxCodeId,
                'tax_rate' => '15.000000',
                'tax_category' => 'S',
                'revenue_account_id' => $this->revenueAccountId,
            ],
        ]));
        $same = $quotes->compare($revision->refresh(), $reordered);

        if ($same['changed'] !== [] || $same['added'] !== [] || $same['removed'] !== []) {
            $violations[] = 'moving a line reads as a change to the offer';
        }

        return $violations;
    }

    /** @return list<string> */
    public function lapsed(): array
    {
        $quotes = app(Quotes::class);

        $quote = $quotes->send($quotes->draft(
            $this->entity(),
            $this->party,
            $this->lines(),
            Carbon::now()->addDays(2),
            Carbon::now(),
        ));

        if ($quote->hasLapsed(Carbon::now())) {
            return ['a quote valid for two more days reads as lapsed'];
        }

        /*
         | The day after it ends, it is not acceptable - whatever the status
         | column says.
         |
         | The status is set by a scheduled job, and between runs the answer
         | still has to be right: a quote whose last day was yesterday is
         | not acceptable this morning.
         */
        $after = Carbon::now()->addDays(3);

        if (! $quote->hasLapsed($after)) {
            return ['a quote is still acceptable after its validity date, between expiry runs'];
        }

        try {
            $quotes->accept($quote, 'Somebody', null, $after);

            return ['a lapsed quote was accepted, which binds the business to a price it withdrew'];
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'revision')
                ? []
                : ['the refusal does not say what to do instead: '.$e->getMessage()];
        }
    }

    /** @return list<string> */
    public function expiry(): array
    {
        $violations = [];
        $quotes = app(Quotes::class);

        $lapsed = $quotes->send($quotes->draft(
            $this->entity(), $this->party, $this->lines(),
            Carbon::now()->addDay(), Carbon::now(),
        ));

        $live = $quotes->send($quotes->draft(
            $this->entity(), $this->party, $this->lines(),
            Carbon::now()->addMonth(), Carbon::now(),
        ));

        $draft = $quotes->draft(
            $this->entity(), $this->party, $this->lines(),
            Carbon::now()->addDay(), Carbon::now(),
        );

        $closed = $quotes->expire(Carbon::now()->addDays(5));
        $references = array_column($closed, 'reference');

        if (! \in_array($lapsed->label(), $references, true)) {
            $violations[] = 'a lapsed quote was not closed';
        }

        if (\in_array($live->label(), $references, true)) {
            $violations[] = 'a quote that is still valid was closed';
        }

        if (\in_array($draft->label(), $references, true)) {
            $violations[] = 'a DRAFT was expired. Nobody has been shown it, so there is nothing to '.
                'withdraw - and a draft that expires is one somebody has to re-create to send';
        }

        if ($lapsed->refresh()->expired_at === null) {
            $violations[] = 'an expired quote has no timestamp, so nobody can say when it closed';
        }

        /*
         | And the report says what it closed, not how many.
         |
         | A job that reports "17 quotes expired" is a job nobody can check.
         */
        foreach (['reference', 'gross', 'valid_until'] as $field) {
            if (! \array_key_exists($field, $closed[0] ?? [])) {
                $violations[] = sprintf('the expiry report does not say the %s of what it closed', $field);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function theAcceptance(): array
    {
        $violations = [];
        $quotes = app(Quotes::class);
        $quote = $this->sent();

        try {
            $quotes->accept($quote, '   ');

            $violations[] = 'an anonymous acceptance was recorded. The person accepting is the '.
                'customer, who has no login here, so their name is what a dispute is argued with';
        } catch (Throwable) {
            // As it should be.
        }

        $accepted = $quotes->accept($quote->refresh(), 'Fahd al-Qahtani', '203.0.113.9');

        foreach ([
            'who' => (string) $accepted->accepted_by_name,
            'from where' => (string) $accepted->accepted_from_ip,
        ] as $what => $value) {
            if ($value === '') {
                $violations[] = sprintf('the acceptance does not record %s', $what);
            }
        }

        if ($accepted->accepted_at === null) {
            $violations[] = 'the acceptance has no time';
        }

        $record = DB::connection('tenant')->table('audit_log')
            ->where('action', 'quote.accepted')
            ->where('subject_id', (string) $quote->getKey())
            ->first();

        if ($record === null) {
            $violations[] = 'the acceptance is not in the audit trail';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theOutcome(): array
    {
        $violations = [];
        $settings = app(Settings::class);
        $outcomes = app(QuoteOutcomes::class);

        // With an order step, which is the default.
        $settings->set('sales.order_step', true, $this->entityId);
        $accepted = app(Quotes::class)->accept($this->sent(), 'Fahd al-Qahtani');
        $result = $outcomes->realise($accepted, $this->date);

        if (! $result instanceof SalesOrder) {
            $violations[] = 'an accepted quote became '.$result::class.' where the entity has an order step';
        }

        if ((string) $accepted->refresh()->outcome_type !== 'order') {
            $violations[] = 'the quote does not record what it became, which is the question somebody '.
                'asks six months later';
        }

        // And without one.
        $settings->set('sales.order_step', false, $this->entityId);
        $second = app(Quotes::class)->accept($this->sent(), 'Fahd al-Qahtani');
        $invoice = $outcomes->realise($second, $this->date);

        if (! $invoice instanceof \App\Tenant\Sales\Models\Invoice) {
            $violations[] = 'an accepted quote did not become an invoice where the entity has no order step';

            return $violations;
        }

        /*
         | A DRAFT invoice, and this is the point.
         |
         | Accepting is the customer's act; issuing is the business's, and it
         | consumes a number from a gapless series, writes a posting and
         | takes a place in a hash chain. Collapsing the two would do all of
         | that at the moment somebody clicked a link on a phone.
         */
        if (! $invoice->isDraft()) {
            $violations[] = 'accepting a quote ISSUED an invoice: a number consumed, a posting written '.
                'and a place taken in a hash chain, at the moment somebody clicked a link on a phone';
        }

        if ((string) $invoice->net_amount !== (string) $second->net_amount) {
            $violations[] = sprintf(
                'the invoice is for %s and the quote was for %s',
                (string) $invoice->net_amount, (string) $second->net_amount,
            );
        }

        $settings->set('sales.order_step', true, $this->entityId);

        return $violations;
    }

    /** @return list<string> */
    public function onceOnly(): array
    {
        $outcomes = app(QuoteOutcomes::class);
        $accepted = app(Quotes::class)->accept($this->sent(), 'Fahd al-Qahtani');

        $outcomes->realise($accepted, $this->date);

        try {
            $outcomes->realise($accepted->refresh(), $this->date);

            return ['a quote became two things, so the customer is charged twice for work they '.
                'ordered once'];
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'already') ? [] : ['the refusal is unclear: '.$e->getMessage()];
        }
    }

    /** @return list<array<string, string|null>> */
    private function lines(string $unitPrice = '100.00000000'): array
    {
        return [[
            'description' => 'Supply and install',
            'quantity' => '10.00000000',
            'unit_price' => $unitPrice,
            'tax_code_id' => $this->taxCodeId,
            'tax_rate' => '15.000000',
            'tax_category' => 'S',
            'revenue_account_id' => $this->revenueAccountId,
        ]];
    }

    private function quote(): Quote
    {
        return app(Quotes::class)->draft(
            $this->entity(),
            $this->party,
            $this->lines(),
            Carbon::now()->addDays(30),
            Carbon::now(),
            'Net 30',
        );
    }

    private function sent(): Quote
    {
        return app(Quotes::class)->send($this->quote());
    }
}
