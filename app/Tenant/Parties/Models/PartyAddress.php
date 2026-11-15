<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Models;

use App\Support\Database\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a party's addresses - PF-060.
 *
 * Shaped as EN 16931's address terms, because that is what an e-invoice
 * needs and translating a free-text address into them at issue time is
 * guesswork - whichever line happens to hold the city is a property of who
 * typed it.
 *
 * @property string $type
 */
final class PartyAddress extends TenantModel
{
    protected $table = 'party_addresses';

    protected $fillable = [
        'party_id', 'type', 'line_1', 'line_2', 'line_3',
        'city', 'postal_code', 'country_subdivision', 'country', 'is_default',
    ];

    protected $casts = ['is_default' => 'boolean'];

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * A snapshot to COPY onto a document - PF-060.
     *
     * @return array<string, string|null>
     */
    public function snapshot(): array
    {
        return [
            'line_1' => $this->line_1,
            'line_2' => $this->line_2,
            'line_3' => $this->line_3,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'country_subdivision' => $this->country_subdivision,
            'country' => $this->country,
        ];
    }
}
