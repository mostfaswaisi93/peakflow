<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Models;

use App\Support\Database\Concerns\HasValidityRange;
use App\Support\Database\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A party's tax registration in one jurisdiction, for a period - PF-079.
 *
 * ## The identifier is not the registration
 *
 * A registration is an identifier, a scheme, a jurisdiction and a period. The
 * scheme matters because an identifier without it is a number rather than
 * something routable: `eas_code` is the Peppol Electronic Address Scheme, and
 * an invoice addressed by identifier alone cannot be delivered.
 *
 * The period matters because a reverse charge may only be issued to a customer
 * that can account for the tax itself, which means a registration valid on the
 * tax point date - not one that was valid when somebody typed it in.
 *
 * @property string $jurisdiction
 * @property string $scheme
 * @property string $identifier
 * @property string|null $eas_code
 * @property bool $is_primary
 * @property string $validity
 */
final class PartyTaxRegistration extends TenantModel
{
    use HasValidityRange;

    protected $table = 'party_tax_registrations';

    /*
     | No BelongsToEntity, deliberately.
     |
     | A party is tenant-wide and so is its registration; the per-entity view
     | of a party is party_entity_settings. These rows are reachable only
     | through parties, whose policy scopes them.
     */

    protected $fillable = [
        'party_id', 'jurisdiction', 'scheme', 'identifier', 'eas_code', 'is_primary', 'validity',
    ];

    protected $casts = ['is_primary' => 'boolean'];

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    /**
     * The internal, fully qualified reference: `SA:TIN:310000000000003`.
     *
     * Jurisdiction, scheme and number, so two registrations under different
     * schemes in the same country can be told apart. It is NOT what appears
     * on a document: EN 16931's BT-31 is the identifier with its ISO country
     * prefix and no scheme, and a recipient reading this triple rejects the
     * invoice. `SemanticInvoiceBuilder::vatIdentifier()` is what a document
     * carries, and an `Exchange` check requires no colon to reach one.
     */
    public function qualified(): string
    {
        return sprintf('%s:%s:%s', $this->jurisdiction, $this->scheme, $this->identifier);
    }
}
