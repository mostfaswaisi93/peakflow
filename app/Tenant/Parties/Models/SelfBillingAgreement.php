<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Models;

use App\Support\Database\Concerns\HasValidityRange;
use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The agreement under which a buyer issues a supplier's invoices - PF-100.
 *
 * Every regime that allows self-billing requires a prior agreement between
 * the two parties, and most require it to be produced on request. So it has
 * a reference, a date it was signed and a validity period: the question an
 * auditor asks is which agreement was in force on the date of the document,
 * which a flag on the party cannot answer.
 *
 * @property string $reference
 */
final class SelfBillingAgreement extends TenantModel
{
    use BelongsToEntity;
    use HasValidityRange;

    protected $table = 'party_self_billing_agreements';

    protected $fillable = [
        'entity_id', 'party_id', 'reference', 'signed_on', 'notes', 'validity',
    ];

    protected $casts = ['signed_on' => 'date'];

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
