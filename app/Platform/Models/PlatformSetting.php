<?php

declare(strict_types=1);

namespace App\Platform\Models;

/**
 * A typed operator setting.
 *
 * The ones that matter are the CEILINGS: a value a tenant may lower and can
 * never raise. "Write off anything under 50" is a policy decision, not a
 * developer's constant, and a generous tolerance quietly absorbs real losses -
 * so the tenant sets its own and the operator bounds it.
 */
final class PlatformSetting extends PlatformModel
{
    protected $table = 'platform_settings';

    protected $fillable = ['key', 'type', 'value', 'description_ar', 'description_en', 'is_ceiling'];

    public function typedValue(): string|int|bool|float|null
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            // A ceiling is money or a ratio, and money is a decimal STRING -
            // never a float. Casting it here would defeat the whole money
            // layer at the one point where the limit is read.
            'decimal', 'string' => $this->value === null ? null : (string) $this->value,
            default => $this->value,
        };
    }

    protected function casts(): array
    {
        return ['is_ceiling' => 'boolean'];
    }
}
