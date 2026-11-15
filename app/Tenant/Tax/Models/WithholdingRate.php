<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Models;

use App\Support\Database\Concerns\HasValidityRange;
use App\Support\Database\TenantModel;
use App\Support\Money\Scale;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * One withholding rate, for a period - PF-083.
 *
 * @property string $jurisdiction
 * @property string|null $party_id
 * @property string|null $item_type
 * @property string $rate
 * @property string $base
 * @property string $source
 * @property string $validity
 */
final class WithholdingRate extends TenantModel
{
    use BelongsToEntity;
    use HasValidityRange;

    protected $table = 'withholding_rates';

    protected $fillable = [
        'entity_id', 'jurisdiction', 'party_id', 'item_type', 'rate', 'base', 'source', 'validity',
    ];

    /**
     * How specific this rate is: higher wins.
     *
     * A rule about THIS party beats a rule about this item type, and that
     * ordering is a decision rather than a convenience. A party-specific row
     * is normally an exemption certificate or a treaty rate - "this
     * non-resident consultant, 5% under the double-tax agreement" - and a
     * generic rule about professional services must not override it. The
     * reverse ordering would quietly withhold 20% from somebody holding a
     * certificate that says 5%.
     */
    public function specificity(): int
    {
        return ($this->party_id === null ? 0 : 2) + ($this->item_type === null ? 0 : 1);
    }

    public function appliesToEveryParty(): bool
    {
        return $this->party_id === null;
    }

    public function appliesToEveryItemType(): bool
    {
        return $this->item_type === null;
    }

    /** Is the base the net, excluding VAT? */
    public function isOnTheNet(): bool
    {
        return $this->base === 'net';
    }

    /** The rate as published: `5.000000` means five percent. */
    public function percentage(): BigDecimal
    {
        return BigDecimal::of($this->rate)->toScale(Scale::Rate->value);
    }

    /** The rate as a fraction, for arithmetic. Never `bcdiv`: bcmath is absent. */
    public function fraction(): BigDecimal
    {
        return $this->percentage()->dividedBy('100', Scale::Rate->value, RoundingMode::Unnecessary);
    }

    public function isZero(): bool
    {
        return $this->percentage()->isZero();
    }

    public function describe(): string
    {
        return sprintf(
            '%s%% of the %s in %s, %s, %s',
            (string) $this->percentage()->strippedOfTrailingZeros(),
            $this->base,
            $this->jurisdiction,
            $this->appliesToEveryParty() ? 'any party' : 'this party',
            $this->appliesToEveryItemType() ? 'any item type' : $this->item_type,
        );
    }
}
