<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use App\Support\Money\Money;
use App\Tenant\Tax\Models\WithholdingRate;
use JsonSerializable;

/**
 * What a payer withholds from one payment - PF-083.
 *
 * ## Deliberately not part of any document total
 *
 * There is no path from this class to a `TaxBreakdown`, and that is the
 * design. Withholding is deducted from the payment, not from the invoice: the
 * invoice is for 1,000, the payer sends 950 and a certificate for 50, and the
 * receivable is settled in full.
 *
 * An invoice total that does not equal the cash received is therefore CORRECT
 * when withholding applies. Allocation logic that treats the shortfall as a
 * short payment either leaves the invoice open forever or writes the
 * difference off as bad debt - and both look like housekeeping rather than
 * like the compliance failure they are, because the payee loses a credit it
 * was entitled to claim.
 */
final readonly class WithholdingDeduction implements JsonSerializable
{
    public function __construct(
        public Money $base,
        public Money $withheld,
        public string $ratePercent,
        public string $jurisdiction,
        public string $basis,
        public string $rateId,
        public string $source,
    ) {}

    /** What the payer transfers: the invoice less the deduction. */
    public function payable(): Money
    {
        return $this->base->minus($this->withheld);
    }

    public function isZero(): bool
    {
        return $this->withheld->isZero();
    }

    public function explain(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return $arabic
            ? sprintf(
                'استقطاع %s%% من %s (%s) في %s: %s محتجزة، والمحوّل %s.',
                $this->ratePercent, $this->base->format('ar'),
                $this->basis === 'net' ? 'الصافي' : 'الإجمالي',
                $this->jurisdiction, $this->withheld->format('ar'), $this->payable()->format('ar'),
            )
            : sprintf(
                'Withholding of %s%% on the %s of %s in %s: %s withheld, %s transferred.',
                $this->ratePercent, $this->basis, $this->base->format('en'),
                $this->jurisdiction, $this->withheld->format('en'), $this->payable()->format('en'),
            );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'base' => $this->base->jsonSerialize(),
            'withheld' => $this->withheld->jsonSerialize(),
            'payable' => $this->payable()->jsonSerialize(),
            'rate' => $this->ratePercent,
            'basis' => $this->basis,
            'jurisdiction' => $this->jurisdiction,
            'rate_id' => $this->rateId,
            'source' => $this->source,
        ];
    }

    public static function none(Money $base, string $jurisdiction, WithholdingRate $rate): self
    {
        return new self(
            $base,
            Money::zero($base->currency),
            (string) $rate->percentage()->strippedOfTrailingZeros(),
            $jurisdiction,
            $rate->base,
            (string) $rate->getKey(),
            $rate->source,
        );
    }
}
