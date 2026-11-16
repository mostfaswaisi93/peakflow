<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an order, and where the quantities live - PF-131.
 *
 * ## Per line, because the alternative cannot answer the question
 *
 * An order for ten items of which six arrived and four were billed is the
 * ordinary case. "60% received" says nothing about which six, and the
 * three-way match has to compare a bill line against the quantity received
 * for *that* line. An order-level figure makes the match impossible and the
 * commitment report wrong.
 *
 * ## Goods and services are different
 *
 * A service line has nothing on a loading bay to count: the receipt is a
 * confirmation that the work was done. The quantity still exists - twelve
 * days of consultancy - but the evidence is a person's word rather than a
 * delivery note, and the match treats a missing confirmation as its own kind
 * of exception rather than as "nothing received".
 *
 * @property int $line_no
 * @property string $line_type
 * @property string $description
 * @property string $quantity_ordered
 * @property string $quantity_received
 * @property string $quantity_billed
 * @property string $unit_price
 * @property string $net_amount
 */
final class PurchaseOrderLine extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'purchase_order_lines';

    protected $fillable = [
        'entity_id', 'purchase_order_id', 'line_no', 'item_id', 'item_code', 'description',
        'line_type', 'unit_code', 'quantity_ordered', 'unit_price', 'net_amount',
        'quantity_received', 'quantity_billed',
        'expense_account_id', 'tax_code_id', 'tax_category', 'tax_rate', 'expected_on',
        'dimension_1', 'dimension_2', 'dimension_3', 'dimension_4',
    ];

    protected $casts = [
        'expected_on' => 'immutable_date',
    ];

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function isService(): bool
    {
        return $this->line_type === 'service';
    }

    public function ordered(): BigDecimal
    {
        return BigDecimal::of((string) $this->quantity_ordered);
    }

    public function received(): BigDecimal
    {
        return BigDecimal::of((string) $this->quantity_received);
    }

    public function billed(): BigDecimal
    {
        return BigDecimal::of((string) $this->quantity_billed);
    }

    public function price(): BigDecimal
    {
        return BigDecimal::of((string) $this->unit_price);
    }

    /** What has arrived and has not been billed - the GRNI quantity. */
    public function receivedNotBilled(): BigDecimal
    {
        $difference = $this->received()->minus($this->billed());

        return $difference->isNegative() ? BigDecimal::zero() : $difference;
    }

    public function isFullyReceived(): bool
    {
        return $this->received()->isGreaterThanOrEqualTo($this->ordered());
    }

    public function isFullyBilled(): bool
    {
        return $this->billed()->isGreaterThanOrEqualTo($this->ordered());
    }

    /**
     * The commitment still outstanding on this line.
     *
     * Against what has been **billed**, not what has been received: the
     * commitment is money not yet spent, and a receipt without a bill is
     * money already spent and not yet invoiced. That is the accrual's job,
     * not the commitment's, and a line that counted the receipt would
     * release the commitment before the obligation existed.
     */
    public function remainingCommitment(string $currency): Money
    {
        $outstanding = $this->ordered()->minus($this->billed());

        if ($outstanding->isNegative() || $outstanding->isZero()) {
            return Money::zero($currency);
        }

        return Money::ofRounded(
            (string) $outstanding->multipliedBy($this->price()),
            $currency,
            RoundingPolicy::halfUp(Scale::Amount),
        );
    }
}
