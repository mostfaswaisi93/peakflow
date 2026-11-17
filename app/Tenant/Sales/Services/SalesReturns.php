<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Inventory\Services\Stock;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Sales\Exceptions\ReturnRefused;
use App\Tenant\Sales\Models\Invoice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Goods coming back, and the credit for them - PF-252.
 *
 * ## The credit note and the stock movement are one transaction
 *
 * That is the whole point of this class. A return that credited the
 * customer and did not restore the stock is the error nobody notices
 * until a count - which is three months later, by which time the
 * difference is written off as shrinkage and the real cause is gone. A
 * return that restored the stock and did not credit the customer is the
 * one they telephone about.
 *
 * So `accept()` opens one transaction, draws the credit note through
 * `Crediting` and writes every stock movement inside it. Either all of
 * it happened or none of it did.
 *
 * ## Restocking is a decision, not an assumption
 *
 * It is on the return LINE, because two units of one line go to two
 * places: one back on the shelf and one into quarantine with a cracked
 * casing. The destination is a real location rather than a flag, and the
 * database refuses a non-resellable unit into a sellable location - the
 * trigger, not this service, because the person doing it at half past
 * five is not going through this method.
 *
 * ## What quarantined goods are worth is a SEPARATE decision
 *
 * They come back into inventory at the cost the sale took out, in a
 * location that is not sellable. They are not written off here.
 *
 * The person accepting a parcel at a counter does not know whether a
 * cracked casing can be repaired, reshipped to the supplier or scrapped,
 * and a return that wrote the unit off would be reversed by hand the day
 * it turns out to be repairable. So the write-down is its own
 * adjustment, with its own reason and its own name against it, and until
 * somebody makes it the goods sit in quarantine where a count can see
 * them.
 *
 * ## The cost it comes back at is the cost it left at
 *
 * Read from the issue movement the sale recorded against that invoice
 * line. Not today's average, which would restore inventory at a figure
 * the sale never took out of it; not the selling price, which would put
 * margin into inventory. When the sale moved no stock the return is
 * refused, because there is no third answer that is not a guess.
 */
final class SalesReturns
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly Crediting $crediting,
        private readonly Stock $stock,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Open a draft return against an issued invoice.
     *
     * @return array<string, mixed>
     *
     * @throws ReturnRefused
     */
    public function open(
        string $entityId,
        string $invoiceId,
        string $reason,
        Carbon $on,
        string $userId,
    ): array {
        $invoice = $this->issuedInvoice($invoiceId);

        if (mb_strlen(trim($reason)) < 10) {
            throw ReturnRefused::because(
                'A return needs its reason in a sentence. "Wrong size", "arrived damaged" and "changed '.
                'their mind" are three different commercial events, and the one nobody wrote down is the '.
                'one the pattern never shows.',
            );
        }

        $id = (string) Str::ulid();
        $number = $this->numbers->next($entityId, 'sales_return', 'RET', $on);

        DB::connection('tenant')->table('sales_returns')->insert([
            'id' => $id,
            'entity_id' => $entityId,
            'invoice_id' => (string) $invoice->getKey(),
            'party_id' => (string) $invoice->party_id,
            'number' => $number,
            'returned_on' => $on->toDateString(),
            'state' => 'draft',
            'reason' => trim($reason),
            'goods_value' => '0.0000',
            'restocking_fee' => '0.0000',
            'credit_value' => '0.0000',
            'currency' => (string) $invoice->currency,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'sales',
            action: 'return.opened',
            subjectType: 'sales_return',
            subjectId: $id,
            subjectLabel: sprintf('%s against %s', $number, (string) $invoice->number),
            after: ['invoice' => (string) $invoice->number, 'number' => $number],
            reason: trim($reason),
            entityId: $entityId,
            partyId: (string) $invoice->party_id,
        );

        return ['id' => $id, 'number' => $number, 'state' => 'draft'];
    }

    /**
     * Add a line: what came back, how much, and where it goes.
     *
     * The ceiling - no more than was sold - is the database's, because a
     * part return of a part return is where a service check is wrong: it
     * reads the invoice, subtracts what it can see, and misses the return
     * another till recorded two seconds ago.
     *
     * @return array<string, mixed>
     *
     * @throws ReturnRefused
     */
    public function addLine(
        string $returnId,
        string $invoiceLineId,
        string $quantity,
        string $locationId,
        bool $resellable,
        string $userId,
        ?string $conditionNote = null,
        ?string $serialId = null,
        ?string $batchId = null,
    ): array {
        $return = $this->draftReturn($returnId);
        $line = $this->lineOf($return, $invoiceLineId);

        if ($line->item_id === null) {
            throw ReturnRefused::anUnstockedItem((string) $line->description);
        }

        $item = DB::connection('tenant')->table('items')->where('id', (string) $line->item_id)->first();

        if ($item === null || ! $item->stocked) {
            throw ReturnRefused::anUnstockedItem((string) $line->description);
        }

        /*
         | The unit price from the invoice line, not from the caller.
         |
         | A return priced by whoever typed it is a credit note that does
         | not agree with the invoice it reverses, and the customer is
         | comparing the two documents side by side.
         */
        $unitPrice = BigDecimal::of((string) $line->unit_price);
        $value = $unitPrice->multipliedBy($quantity)->toScale(4, RoundingMode::HalfUp);

        $id = (string) Str::ulid();

        DB::connection('tenant')->table('sales_return_lines')->insert([
            'id' => $id,
            'entity_id' => (string) $return->entity_id,
            'return_id' => $returnId,
            'invoice_line_id' => $invoiceLineId,
            'item_id' => (string) $line->item_id,
            'serial_id' => $serialId,
            'batch_id' => $batchId,
            'quantity' => $quantity,
            'unit_price' => (string) $unitPrice,
            'line_value' => (string) $value,
            'location_id' => $locationId,
            'resellable' => $resellable,
            'condition_note' => $conditionNote === null ? null : trim($conditionNote),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->restate($returnId);

        return ['id' => $id, 'line_value' => (string) $value];
    }

    /**
     * What the customer is charged for the trouble, as its own figure.
     *
     * Recorded separately rather than folded into the credit, so the
     * figure on the credit note is explainable from the return - which is
     * the one arithmetic a customer checks.
     *
     * @throws ReturnRefused
     */
    public function chargeRestocking(string $returnId, string $fee, string $why, string $userId): void
    {
        $return = $this->draftReturn($returnId);

        if (mb_strlen(trim($why)) < 10) {
            throw ReturnRefused::because(
                'A restocking fee is a charge the customer will ask about, so it needs its reason in a '.
                'sentence - what it covers, not that it applies.',
            );
        }

        $goods = Money::of((string) $return->goods_value, (string) $return->currency);
        $charge = Money::of($fee, (string) $return->currency);

        if ($charge->isNegative()) {
            throw ReturnRefused::because('A negative restocking fee is a discount, which is not this.');
        }

        if ($charge->isGreaterThan($goods)) {
            throw ReturnRefused::because(sprintf(
                'A fee of %s against goods of %s would leave the customer paying to return them, which '.
                'is not a return. The usual cause is a percentage applied to the wrong base.',
                $charge->toPresentation(),
                $goods->toPresentation(),
            ));
        }

        DB::connection('tenant')->table('sales_returns')->where('id', $returnId)->update([
            'restocking_fee' => (string) $charge->amount,
            'credit_value' => (string) $goods->minus($charge)->amount,
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'sales',
            action: 'return.fee_charged',
            subjectType: 'sales_return',
            subjectId: $returnId,
            subjectLabel: sprintf('%s %s', (string) $return->number, (string) $charge->toPresentation()),
            before: ['restocking_fee' => (string) $return->restocking_fee],
            after: ['restocking_fee' => (string) $charge->amount],
            reason: trim($why),
            entityId: (string) $return->entity_id,
        );
    }

    /**
     * Accept the return: the credit note and the stock, in one transaction.
     *
     * @return array<string, mixed>
     *
     * @throws ReturnRefused
     */
    public function accept(string $returnId, Carbon $on, string $userId): array
    {
        $return = $this->draftReturn($returnId);
        $invoice = $this->issuedInvoice((string) $return->invoice_id);

        $lines = DB::connection('tenant')->table('sales_return_lines')
            ->where('return_id', $returnId)
            ->orderBy('id')
            ->get()
            ->all();

        if ($lines === []) {
            throw ReturnRefused::aReturnOfNothing();
        }

        /*
         | Every cost resolved BEFORE the transaction opens.
         |
         | A refusal discovered half way through would roll back a credit
         | note that had already drawn a number from its series, and a gap
         | in a document series is a question an auditor asks.
         */
        $costs = [];

        foreach ($lines as $line) {
            $costs[(string) $line->id] = $this->costItLeftAt($line);
        }

        $currency = (string) $return->currency;

        return DB::connection('tenant')->transaction(function () use (
            $return, $returnId, $invoice, $lines, $costs, $currency, $on, $userId
        ): array {
            $selections = [];

            foreach ($lines as $line) {
                $selections[] = [
                    'line_id' => (string) $line->invoice_line_id,
                    'net' => (string) Money::of((string) $line->line_value, $currency)->amount,
                    'quantity' => (string) $line->quantity,
                ];
            }

            $credit = $this->crediting->forLines($invoice, $selections, $on);

            $movements = [];

            foreach ($lines as $line) {
                /*
                 | `returnIn`, not `receive` - PF-252.
                 |
                 | A receipt credits goods-received-not-invoiced, which
                 | holds one thing: the gap until a SUPPLIER bills us.
                 | There is no supplier behind a sales return and no bill
                 | coming, so the credit would sit in a liability the
                 | three-way match can never clear while cost of sales
                 | stayed overstated by the same figure. Neither half
                 | shows up in a stock report.
                 */
                $movement = $this->stock->returnIn(
                    entityId: (string) $return->entity_id,
                    itemId: (string) $line->item_id,
                    locationId: (string) $line->location_id,
                    quantity: (string) $line->quantity,
                    unitCost: $costs[(string) $line->id],
                    on: $on,
                    userId: $userId,
                    sourceType: 'sales_return_line',
                    sourceId: (string) $line->id,
                    batchId: $line->batch_id === null ? null : (string) $line->batch_id,
                    serialId: $line->serial_id === null ? null : (string) $line->serial_id,
                );

                DB::connection('tenant')->table('sales_return_lines')
                    ->where('id', (string) $line->id)
                    ->update(['movement_id' => (string) $movement['id'], 'updated_at' => now()]);

                $movements[] = (string) $movement['id'];
            }

            DB::connection('tenant')->table('sales_returns')->where('id', $returnId)->update([
                'state' => 'accepted',
                'credit_note_id' => (string) $credit->getKey(),
                'approved_by' => $userId,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'sales',
                action: 'return.accepted',
                subjectType: 'sales_return',
                subjectId: $returnId,
                subjectLabel: sprintf(
                    '%s credited by %s',
                    (string) $return->number,
                    (string) ($credit->number ?? $credit->getKey()),
                ),
                before: ['state' => 'draft'],
                after: [
                    'state' => 'accepted',
                    'credit_note' => (string) $credit->getKey(),
                    'movements' => \count($movements),
                ],
                entityId: (string) $return->entity_id,
                partyId: (string) $return->party_id,
            );

            return [
                'id' => $returnId,
                'state' => 'accepted',
                'credit_note_id' => (string) $credit->getKey(),
                'movements' => $movements,
            ];
        });
    }

    /**
     * What one returned unit cost when it left, from the issue that took it.
     *
     * @throws ReturnRefused
     */
    private function costItLeftAt(object $line): string
    {
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT m.unit_cost::text AS unit_cost
            FROM stock_movements m
            WHERE m.source_type = 'invoice_line'
              AND m.source_id = ?
              AND m.kind = 'issue'
            /*
             | The earliest issue against the line, which is the sale.
             |
             | A line issued twice is a correction, and the first movement
             | is the one the revenue was recognised against.
             */
            ORDER BY m.moved_on, m.id
            LIMIT 1
        SQL, [(string) $line->invoice_line_id]);

        if ($row === null) {
            $description = (string) DB::connection('tenant')->table('invoice_lines')
                ->where('id', (string) $line->invoice_line_id)
                ->value('description');

            throw ReturnRefused::aSaleThatNeverMovedStock($description);
        }

        return (string) $row->unit_cost;
    }

    /** The goods value, summed from the lines rather than carried alongside them. */
    private function restate(string $returnId): void
    {
        $row = DB::connection('tenant')->selectOne(
            'SELECT coalesce(sum(line_value), 0)::text AS goods FROM sales_return_lines WHERE return_id = ?',
            [$returnId],
        );

        $goods = (string) ($row->goods ?? '0');
        $fee = (string) DB::connection('tenant')->table('sales_returns')
            ->where('id', $returnId)
            ->value('restocking_fee');

        /*
         | A fee that no longer fits the goods is dropped to what fits.
         |
         | Which happens when a line is removed after the fee was set. The
         | alternative is a return whose credit is negative, and the
         | constraint refuses that - so the figure would simply be
         | unsaveable with no sentence saying why.
         */
        $fitting = BigDecimal::of($fee)->isGreaterThan(BigDecimal::of($goods))
            ? $goods
            : $fee;

        DB::connection('tenant')->table('sales_returns')->where('id', $returnId)->update([
            'goods_value' => $goods,
            'restocking_fee' => $fitting,
            'credit_value' => (string) BigDecimal::of($goods)->minus($fitting)->toScale(4),
            'updated_at' => now(),
        ]);
    }

    /** @throws ReturnRefused */
    private function issuedInvoice(string $invoiceId): Invoice
    {
        $invoice = Invoice::query()->find($invoiceId);

        if ($invoice === null) {
            throw ReturnRefused::because('There is no invoice with that id to return goods against.');
        }

        if ((string) $invoice->status !== 'issued') {
            throw ReturnRefused::anUnissuedInvoice((string) $invoice->status);
        }

        return $invoice;
    }

    /** @throws ReturnRefused */
    private function draftReturn(string $returnId): object
    {
        $return = DB::connection('tenant')->table('sales_returns')->where('id', $returnId)->first();

        if ($return === null) {
            throw ReturnRefused::because('There is no return with that id.');
        }

        if ((string) $return->state !== 'draft') {
            throw ReturnRefused::aReturnThatIsNotADraft((string) $return->state);
        }

        return $return;
    }

    /** @throws ReturnRefused */
    private function lineOf(object $return, string $invoiceLineId): object
    {
        $line = DB::connection('tenant')->table('invoice_lines')
            ->where('id', $invoiceLineId)
            ->where('invoice_id', (string) $return->invoice_id)
            ->first();

        if ($line === null) {
            throw ReturnRefused::aLineOfAnotherInvoice($invoiceLineId);
        }

        return $line;
    }
}
