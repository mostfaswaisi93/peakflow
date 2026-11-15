<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Enums;

/**
 * Three states, and only one of them is reversible - PF-041.
 *
 * There is deliberately no `cancelled` or `void`. A posted entry that turned
 * out to be wrong is REVERSED, which leaves both the original and the
 * reversal visible; voiding it would leave a gap in the numbering and a
 * trial balance that no longer explains the statements printed from it.
 */
enum EntryStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return match ($this) {
            self::Draft => $locale === 'ar' ? 'مسودة' : 'draft',
            self::Posted => $locale === 'ar' ? 'مرحّل' : 'posted',
            self::Reversed => $locale === 'ar' ? 'معكوس' : 'reversed',
        };
    }
}
