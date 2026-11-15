<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Sales\Enums\OrderState;
use App\Tenant\Sales\Models\SalesOrder;
use App\Tenant\Sales\Services\Issuer;
use App\Tenant\Sales\Services\Quotes;
use App\Tenant\Sales\Services\SalesOrders;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sales orders, and invoicing against them - PF-090.
 *
 * ## The balance is per LINE, and that is the whole task
 *
 * "Have we invoiced the installation yet?" is the question people ask. An
 * order invoiced to 60% could be any combination of its lines, so a balance
 * held only at the order level cannot answer it - and the second invoice
 * duplicates a line, which nobody notices until the customer does.
 *
 * ## An order never posts
 *
 * A confirmed order is a commitment, and a commitment is neither a receivable
 * nor a liability: nothing has been supplied and nothing is owed. It shows
 * against credit exposure and, where a tenant wants it, in a budget. A check
 * asserts no journal entry ever names an order as its source.
 */
final class SalesOrderRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['sales orders (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'an order from an accepted quote carries its lines' => fn (): array => $this->fromAQuote(),
                'only a confirmed order may be invoiced' => fn (): array => $this->onlyConfirmed(),
                'an order is invoiced across several documents, per line' => fn (): array => $this->partialInvoicing(),
                'over-invoicing is refused, by the service and by the database' => fn (): array => $this->overInvoicing(),
                'crediting an invoice puts the balance back on the order' => fn (): array => $this->creditingPutsItBack(),
                'cancelling an invoiced order is refused, with the figure' => fn (): array => $this->cancelling(),
                'a confirmed order commits, and posts nothing' => fn (): array => $this->theCommitment(),
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function fromAQuote(): array
    {
        $violations = [];
        $quotes = app(Quotes::class);
        $orders = app(SalesOrders::class);

        $quote = $quotes->accept(
            $quotes->send($quotes->draft(
                $this->entity(), $this->party, $this->lines(),
                Carbon::now()->addDays(30), Carbon::now(),
            )),
            'Fahd al-Qahtani',
        );

        $order = $orders->fromQuote($quote, $this->date);

        if ($order->status !== OrderState::Draft) {
            $violations[] = 'an order from a quote starts confirmed. Confirming is the business\'s '.
                'act: the customer accepted a price, and somebody still has to agree to supply it';
        }

        if ((string) $order->gross_amount !== (string) $quote->gross_amount) {
            $violations[] = sprintf(
                'the order is for %s and the quote was for %s',
                (string) $order->gross_amount, (string) $quote->gross_amount,
            );
        }

        if ($order->lines()->count() !== $quote->lines()->count()) {
            $violations[] = 'the order does not carry the quote\'s lines';
        }

        if ((string) $order->quote_id !== (string) $quote->getKey()) {
            $violations[] = 'the order does not say which quote it came from';
        }

        // And an unaccepted quote cannot produce one.
        $unaccepted = $quotes->send($quotes->draft(
            $this->entity(), $this->party, $this->lines(),
            Carbon::now()->addDays(30), Carbon::now(),
        ));

        try {
            $orders->fromQuote($unaccepted, $this->date);

            $violations[] = 'an order was raised from a quote the customer has not accepted, which '.
                'commits the business to work nobody asked for';
        } catch (Throwable) {
            // As it should be.
        }

        return $violations;
    }

    /** @return list<string> */
    public function onlyConfirmed(): array
    {
        $violations = [];
        $orders = app(SalesOrders::class);
        $order = $this->order();

        try {
            $orders->invoice($order, null, $this->date);

            $violations[] = 'a draft order was invoiced, which bills a customer for an agreement that '.
                'has not been made';
        } catch (Throwable) {
            // As it should be.
        }

        $confirmed = $orders->confirm($order->refresh());

        if ($confirmed->confirmed_at === null) {
            $violations[] = 'a confirmed order has no timestamp';
        }

        if (! $confirmed->status->mayBeInvoiced()) {
            $violations[] = 'a confirmed order may not be invoiced';
        }

        return $violations;
    }

    /** @return list<string> */
    public function partialInvoicing(): array
    {
        $violations = [];
        $orders = app(SalesOrders::class);
        $order = $orders->confirm($this->order());
        $line = $order->lines()->first();

        if ($line === null) {
            return ['the order has no lines'];
        }

        $first = app(Issuer::class)->issue(
            $orders->invoice($order, [(string) $line->getKey() => '4.00000000'], $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        if ((string) $first->net_amount !== '400.0000') {
            $violations[] = sprintf('four of ten units at 100 is %s', (string) $first->net_amount);
        }

        $line->refresh();

        if ((string) $line->invoiced_quantity !== '4.00000000') {
            $violations[] = sprintf(
                'the order line records %s invoiced and four units were. The balance is tracked per '.
                'line because "have we invoiced the installation yet" is the question people ask',
                (string) $line->invoiced_quantity,
            );
        }

        if ((string) $line->remainingQuantity() !== '6.00000000') {
            $violations[] = sprintf('%s remains and six units do', (string) $line->remainingQuantity());
        }

        /*
         | The invoice line points at the order line.
         |
         | Without the link the cap could only be enforced per order, and an
         | order invoiced to 60% could be any combination of its lines.
         */
        if ((string) $first->lines()->value('order_line_id') !== (string) $line->getKey()) {
            $violations[] = 'the invoice line does not say which order line it invoices';
        }

        $second = app(Issuer::class)->issue(
            $orders->invoice($order->refresh(), null, $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        if ((string) $second->net_amount !== '600.0000') {
            $violations[] = sprintf(
                'invoicing the rest produced %s and the remaining six units are 600.0000',
                (string) $second->net_amount,
            );
        }

        if (! $order->refresh()->isFullyInvoiced()) {
            $violations[] = sprintf(
                '%s is still uninvoiced after both documents',
                (string) $order->uninvoicedNet()->amount,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function overInvoicing(): array
    {
        $violations = [];
        $orders = app(SalesOrders::class);
        $order = $orders->confirm($this->order());
        $line = $order->lines()->first();
        \assert($line !== null);

        app(Issuer::class)->issue(
            $orders->invoice($order, [(string) $line->getKey() => '10.00000000'], $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        try {
            $orders->invoice($order->refresh(), [(string) $line->getKey() => '1.00000000'], $this->date);

            $violations[] = 'the service invoiced an eleventh unit of a ten-unit line';
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'ordered')) {
                $violations[] = 'the refusal does not explain itself: '.$e->getMessage();
            }
        }

        /*
         | And the database refuses it when the service is bypassed.
         |
         | The trigger adds to the line and a CHECK on the row refuses the
         | result, so the cap holds against any writer at all - a second
         | invoicing path, an import, a correction script - and not only
         | against the code that does the adding.
         */
        $sneaky = $this->draft();

        DB::connection('tenant')->table('invoice_lines')
            ->where('invoice_id', (string) $sneaky->getKey())
            ->update(['order_line_id' => (string) $line->getKey(), 'quantity' => '1.00000000']);

        try {
            app(Issuer::class)->issue(
                $sneaky->refresh(), $this->entity(), $this->actorId(), $this->decision,
            );

            $violations[] = 'the database allowed an invoice against a fully invoiced order line';
        } catch (Throwable) {
            // The CHECK on `sales_order_lines`.
        }

        return $violations;
    }

    /** @return list<string> */
    public function creditingPutsItBack(): array
    {
        $violations = [];
        $orders = app(SalesOrders::class);
        $order = $orders->confirm($this->order());
        $line = $order->lines()->first();
        \assert($line !== null);

        $invoice = app(Issuer::class)->issue(
            $orders->invoice($order, [(string) $line->getKey() => '10.00000000'], $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        if ((string) $line->refresh()->invoiced_quantity !== '10.00000000') {
            $violations[] = 'the order line does not record the invoice';
        }

        app(Issuer::class)->issue(
            app(\App\Tenant\Sales\Services\Crediting::class)->full($invoice, $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        /*
         | Crediting the invoice puts the order's balance back.
         |
         | Without it, crediting and re-issuing an invoice would exhaust an
         | order nobody has delivered - and the customer who asked for a
         | corrected invoice would be told the order is finished.
         */
        if ((string) $line->refresh()->invoiced_quantity !== '0.00000000') {
            $violations[] = sprintf(
                'after crediting the whole invoice the order line still shows %s invoiced',
                (string) $line->invoiced_quantity,
            );
        }

        if (! $order->refresh()->uninvoicedNet()->equals(\App\Support\Money\Money::of('1000.0000', 'SAR'))) {
            $violations[] = sprintf(
                'the order shows %s left to invoice and the credit put the whole 1,000.00 back',
                (string) $order->uninvoicedNet()->amount,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function cancelling(): array
    {
        $violations = [];
        $orders = app(SalesOrders::class);

        // An uninvoiced order cancels, with a reason.
        $clean = $orders->confirm($this->order());

        try {
            $orders->cancel($clean, 'short');

            $violations[] = 'an order was cancelled with no real reason. A cancellation nobody can '.
                'reconstruct leaves the customer\'s version of the conversation as the only one';
        } catch (Throwable) {
            // As it should be.
        }

        $cancelled = $orders->cancel($clean->refresh(), 'the customer withdrew the project entirely');

        if ($cancelled->status !== OrderState::Cancelled || $cancelled->cancelled_at === null) {
            $violations[] = 'cancelling did not take';
        }

        // An invoiced one does not.
        $invoiced = $orders->confirm($this->order());
        $line = $invoiced->lines()->first();
        \assert($line !== null);

        app(Issuer::class)->issue(
            $orders->invoice($invoiced, [(string) $line->getKey() => '2.00000000'], $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        try {
            $orders->cancel($invoiced->refresh(), 'the customer withdrew the project entirely');

            $violations[] = 'an order with an invoice against it was cancelled, which leaves a '.
                'document in a legal sequence pointing at an agreement the system says never happened';
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), '200.0000')) {
                $violations[] = 'the refusal does not say how much is invoiced: '.$e->getMessage();
            }
        }

        // And the database refuses it too.
        try {
            DB::connection('tenant')->table('sales_orders')
                ->where('id', (string) $invoiced->getKey())
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancelled_reason' => 'around the service entirely',
                ]);

            $violations[] = 'the database allowed an invoiced order to be cancelled';
        } catch (Throwable) {
            // The trigger.
        }

        return $violations;
    }

    /** @return list<string> */
    public function theCommitment(): array
    {
        $violations = [];
        $orders = app(SalesOrders::class);
        $entries = $this->journalEntries();

        $order = $orders->confirm($this->order());

        if ($this->journalEntries() !== $entries) {
            $violations[] = 'confirming an order posted a journal entry. A commitment is neither a '.
                'receivable nor a liability: nothing has been supplied and nothing is owed, and a '.
                'number in the trial balance that no statement has a line for is one somebody '.
                'reconciles';
        }

        $commitment = $orders->commitment($this->entityId, 'SAR');

        if ($commitment['committed'] !== '1000.0000') {
            $violations[] = sprintf(
                'the commitment is %s and a confirmed, uninvoiced 1,000.00 order commits 1000.0000',
                $commitment['committed'],
            );
        }

        if (($commitment['by_party'][(string) $order->party_id] ?? null) === null) {
            $violations[] = 'the commitment is not broken down by party, so it cannot reach a '.
                'customer\'s credit exposure';
        }

        // A draft order commits nothing.
        $this->order();

        if (app(SalesOrders::class)->commitment($this->entityId, 'SAR')['committed'] !== '1000.0000') {
            $violations[] = 'a DRAFT order counts towards the commitment. Nobody has agreed to supply '.
                'it, and an exposure figure that includes drafts blocks customers for work that may '.
                'never happen';
        }

        // Invoicing it releases the commitment: the receivable has it now.
        $line = $order->lines()->first();
        \assert($line !== null);

        app(Issuer::class)->issue(
            $orders->invoice($order->refresh(), [(string) $line->getKey() => '10.00000000'], $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        if (app(SalesOrders::class)->commitment($this->entityId, 'SAR')['committed'] !== '0.0000') {
            $violations[] = 'a fully invoiced order still commits. The receivable has it now, and '.
                'counting both doubles the customer\'s exposure';
        }

        if (DB::connection('tenant')->table('journal_entries')->where('source_type', 'sales_order')->exists()) {
            $violations[] = 'a journal entry names an order as its source';
        }

        return $violations;
    }

    /** @return list<array<string, string|null>> */
    private function lines(): array
    {
        return [[
            'description' => 'Supply and install',
            'quantity' => '10.00000000',
            'unit_price' => '100.00000000',
            'tax_code_id' => $this->taxCodeId,
            'tax_rate' => '15.000000',
            'tax_category' => 'S',
            'revenue_account_id' => $this->revenueAccountId,
        ]];
    }

    private function order(): SalesOrder
    {
        return app(SalesOrders::class)->draft(
            $this->entity(),
            $this->party,
            $this->lines(),
            $this->date,
        );
    }
}
