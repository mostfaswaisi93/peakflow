<?php

declare(strict_types=1);

namespace App\Tenant\Tenancy\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;

/**
 * A per-entity configuration value.
 *
 * Typed rather than guessed. A setting stored as text and cast on read by
 * whoever happens to read it is how `"false"` becomes `true`, so the type is
 * recorded alongside the value and the cast happens in exactly one place.
 *
 * @property string $key
 * @property string $type
 * @property ?string $value
 */
final class EntitySetting extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'entity_settings';

    protected $fillable = ['entity_id', 'key', 'type', 'value'];

    /** The value, cast according to its recorded type. */
    public function typed(): string|int|bool|float|array|null
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'bool', 'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'int', 'integer' => (int) $this->value,
            // Deliberately a string: a setting that is a money amount or a
            // rate must not pass through a float on its way out of the
            // database. Callers hand it to Money::of() or Rate::of().
            'decimal' => $this->value,
            'json', 'array' => json_decode($this->value, true, 512, JSON_THROW_ON_ERROR),
            default => $this->value,
        };
    }
}
