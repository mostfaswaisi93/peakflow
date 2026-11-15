<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Services;

use App\Support\Iso\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenant\Catalog\Exceptions\CurrencyNotEnabled;
use App\Tenant\Catalog\Models\EntityCurrency;
use App\Tenant\Tenancy\Models\Entity;
use NumberFormatter;

/**
 * Which currencies an entity deals in, and how they are shown - PF-067.
 *
 * ## Enabling is a gate, not a convenience
 *
 * The question this service answers is "may this entity issue a document in
 * EUR", and the answer being "no" is the useful case. A system that converts
 * instead produces a figure at a rate nobody chose, on a document that goes
 * to a customer, and the error is only found when the payment arrives for a
 * different amount.
 *
 * The gate is enforced three times over, deliberately:
 *
 *   - here, at the document, with a message a person can act on
 *   - at the journal line, by a database trigger, because imports and
 *     correction scripts never pass through here
 *   - by the translation invariant, which refuses a line whose functional
 *     amount does not follow from a stored rate
 *
 * The first is the one users meet; the other two are the ones that hold.
 *
 * ## Display policy changes nothing stored
 *
 * `format()` is presentation. Every amount stays at the amount scale in the
 * database regardless of what is displayed, because a document that rounds a
 * 3-decimal currency to 2 and a ledger that sums the document are how a set
 * of books stops balancing by a few fils a month.
 */
final class CurrencyPolicy
{
    /**
     * Refuse a document in a currency the entity has not enabled - PF-067.
     *
     * @throws CurrencyNotEnabled
     */
    public function assertEnabled(Entity $entity, string $currency): EntityCurrency
    {
        $currency = CurrencyRegistry::assertExists($currency);

        $enabled = EntityCurrency::query()
            ->where('entity_id', $entity->getKey())
            ->where('currency', $currency)
            ->where('enabled', true)
            ->first();

        if ($enabled === null) {
            throw CurrencyNotEnabled::forEntity(
                $currency,
                (string) $entity->legal_name,
                (string) $entity->functional_currency,
            );
        }

        return $enabled;
    }

    public function isEnabled(Entity $entity, string $currency): bool
    {
        if (! CurrencyRegistry::exists($currency)) {
            return false;
        }

        return EntityCurrency::query()
            ->where('entity_id', $entity->getKey())
            ->where('currency', strtoupper($currency))
            ->where('enabled', true)
            ->exists();
    }

    /**
     * Start transacting in a currency.
     *
     * @param  array<string, mixed>  $display
     *
     * @throws CurrencyNotEnabled if the code is not an active ISO 4217 code
     */
    public function enable(Entity $entity, string $currency, array $display = []): EntityCurrency
    {
        if (! CurrencyRegistry::exists($currency)) {
            throw CurrencyNotEnabled::unknownCode($currency);
        }

        $currency = strtoupper($currency);

        /** @var EntityCurrency $row */
        $row = EntityCurrency::query()->firstOrNew([
            'entity_id' => (string) $entity->getKey(),
            'currency' => $currency,
        ]);

        $row->fill($display);
        $row->enabled = true;

        // Never set here. The functional currency is mirrored from the
        // entity by a trigger, and letting this method claim it would give
        // an entity two of them or none.
        $row->is_functional = $currency === (string) $entity->functional_currency;

        $row->save();

        return $row;
    }

    /**
     * Stop transacting in a currency.
     *
     * The functional currency cannot be disabled - a database CHECK refuses
     * it, because an entity whose own books are switched off is not a state
     * worth representing. Existing documents in a disabled currency are
     * untouched: disabling stops new ones, and rewriting history to match a
     * settings change is the opposite of what this system is for.
     */
    public function disable(Entity $entity, string $currency): void
    {
        EntityCurrency::query()
            ->where('entity_id', $entity->getKey())
            ->where('currency', strtoupper($currency))
            ->where('is_functional', false)
            ->update(['enabled' => false, 'updated_at' => now()]);
    }

    /** @return list<string> */
    public function enabledCodes(Entity $entity): array
    {
        return EntityCurrency::query()
            ->where('entity_id', $entity->getKey())
            ->where('enabled', true)
            ->orderByDesc('is_functional')
            ->orderBy('currency')
            ->pluck('currency')
            ->all();
    }

    /**
     * Format an amount under this entity's display policy for its currency.
     *
     * Falls back to the plain ICU currency format when the entity has no
     * policy for the currency - which happens when formatting a historical
     * document in a currency since disabled, and is the one case where
     * refusing would be wrong.
     */
    public function format(Entity $entity, Money $money, ?string $locale = null): string
    {
        $policy = EntityCurrency::query()
            ->where('entity_id', $entity->getKey())
            ->where('currency', $money->currency)
            ->first();

        if ($policy === null) {
            return $money->format($locale);
        }

        $decimals = $policy->presentationDecimals();

        /*
         | The digit shape goes through ICU's numbering system, not through
         | replacing the digit symbols.
         |
         | `setSymbol(DIGIT_SYMBOL, ...)` looks like it would do this and does
         | not: in a decimal format the digits come from the locale's
         | numbering system, and the symbol is only consulted for pattern
         | characters. The three shapes are three real numbering systems:
         |
         |   latn     0123456789   Latin, what most of the world reads
         |   arab     ٠١٢٣٤٥٦٧٨٩   Arabic-Indic, used across the Gulf
         |   arabext  ۰۱۲۳۴۵۶۷۸۹   Extended Arabic-Indic (Persian, Urdu)
         |
         | `arab` and `arabext` are frequently treated as the same thing and
         | are not; a Saudi statement rendered in `arabext` is wrong in a way
         | that reads as a font problem and is not one.
         */
        $numbering = match ($policy->digit_shape) {
            'arabic_indic' => 'arab',
            'eastern_arabic_indic' => 'arabext',
            default => 'latn',
        };

        $locale = ($locale ?? (string) app()->getLocale()).'-u-nu-'.$numbering;

        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        // @arch-allow: ICU's NumberFormatter takes a float and has no
        // decimal-aware alternative. Safe here for the same reason it is safe
        // in Money::format - the value has already been rounded for
        // presentation, so the float is never read back, summed or stored.
        $digits = (string) $formatter->format((float) (string) $money->toPresentation()); // @arch-allow

        $marker = match ($policy->symbol_display) {
            'none' => '',
            'code' => $money->currency,
            default => CurrencyRegistry::symbol($money->currency, $locale),
        };

        if ($marker === '') {
            return $digits;
        }

        return $policy->symbol_position === 'after'
            ? $digits.' '.$marker
            : $marker.' '.$digits;
    }
}
