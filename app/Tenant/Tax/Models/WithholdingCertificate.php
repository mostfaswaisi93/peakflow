<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Models;

use App\Support\Money\Money;
use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;

/**
 * Evidence that tax was withheld from a payment - PF-083.
 *
 * ## Why this is a record and not a derived view
 *
 * The payee cannot claim the credit without it. A certificate is a document
 * the payer issues and the payee files with its own return, and the authority
 * matches the two - so it has a number, a date, a period and a status, and it
 * is not recomputed from a payment whose record may have been corrected since.
 *
 * ## The period is the deduction's, not the invoice's
 *
 * A credit is claimed in the period the deduction was made. An invoice raised
 * in March and paid in May produces a May certificate, and a system that
 * reported it in March gives the payee a credit the authority will not match.
 *
 * @property string $number
 * @property string $jurisdiction
 * @property string $period
 * @property string $currency
 * @property string $base_amount
 * @property string $withheld_amount
 * @property string $rate
 * @property string|null $payment_reference
 * @property string $status
 */
final class WithholdingCertificate extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'withholding_certificates';

    protected $fillable = [
        'entity_id', 'number', 'party_id', 'jurisdiction', 'issued_on', 'period',
        'currency', 'base_amount', 'withheld_amount', 'rate', 'payment_reference', 'status',
    ];

    protected $casts = ['issued_on' => 'date'];

    public function base(): Money
    {
        return Money::of($this->base_amount, $this->currency);
    }

    public function withheld(): Money
    {
        return Money::of($this->withheld_amount, $this->currency);
    }

    /** What the payer actually transferred. */
    public function paid(): Money
    {
        return $this->base()->minus($this->withheld());
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }
}
