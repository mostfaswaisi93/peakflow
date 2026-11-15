<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use App\Support\Money\Money;
use App\Tenant\Tax\Enums\TaxCategory;
use JsonSerializable;

/**
 * One entry of a VAT breakdown - EN 16931 `BG-23`, PF-082.
 *
 * One per unique combination of tax category and rate. The field numbers are
 * written down because this object IS the breakdown that goes on the wire, and
 * a receiving system validates it field by field:
 *
 * - `BT-116` the taxable amount for this category and rate
 * - `BT-117` the tax amount, rounded once per this entry
 * - `BT-118` the category code
 * - `BT-119` the rate, as a percentage
 * - `BT-120` the exemption reason as text
 * - `BT-121` the exemption reason as a code
 */
final readonly class TaxBreakdownEntry implements JsonSerializable
{
    public function __construct(
        public TaxCategory $category,
        public string $ratePercent,
        public Money $taxableAmount,
        public Money $taxAmount,
        public ?string $reasonCode = null,
        public ?string $reasonText = null,
    ) {}

    /** Does this entry carry tax, or is it one of the zero categories? */
    public function isTaxable(): bool
    {
        return ! $this->taxAmount->isZero();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'BT-118' => $this->category->value,
            'BT-119' => $this->ratePercent,
            'BT-116' => $this->taxableAmount->jsonSerialize(),
            'BT-117' => $this->taxAmount->jsonSerialize(),
            'BT-121' => $this->reasonCode,
            'BT-120' => $this->reasonText,
        ];
    }
}
