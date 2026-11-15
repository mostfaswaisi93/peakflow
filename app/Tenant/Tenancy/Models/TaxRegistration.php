<?php

declare(strict_types=1);

namespace App\Tenant\Tenancy\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * How one entity identifies itself to one tax authority.
 *
 * Dated, because registrations change. An invoice carries the registration
 * that was effective on its issue date, not the one effective today - a
 * reprint of a two-year-old invoice that silently shows a new VAT number is a
 * different document from the one that was filed.
 *
 * @property string $jurisdiction
 * @property string $scheme
 * @property string $identifier
 * @property bool $is_primary
 */
final class TaxRegistration extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'tax_registrations';

    protected $fillable = [
        'entity_id', 'jurisdiction', 'scheme', 'identifier',
        'effective_from', 'effective_to', 'is_primary',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_primary' => 'boolean',
    ];

    /**
     * The scheme identifier as an e-invoicing document expects it.
     *
     * UBL and CII both qualify a party's tax identifier with the scheme it
     * belongs to, and a bare number is not interoperable: `300000000000003`
     * means nothing without `VAT` and `SA` beside it.
     */
    protected function qualifiedIdentifier(): Attribute
    {
        return Attribute::get(fn (): string => sprintf(
            '%s:%s:%s',
            $this->jurisdiction,
            $this->scheme,
            $this->identifier,
        ));
    }

    public function isEffectiveOn(\DateTimeInterface $date): bool
    {
        if ($this->effective_from->greaterThan($date)) {
            return false;
        }

        return $this->effective_to === null || $this->effective_to->greaterThanOrEqualTo($date);
    }
}
