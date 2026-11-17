<?php

declare(strict_types=1);

namespace App\Support\Ui;

/**
 * The colour themes, and what each one puts on `<html>` - UX-002, UX-004.
 *
 * ## Why the dark ones all carry `.dark`
 *
 * `resources/css/app.css` redefines Tailwind's `dark:` variant to key on a
 * `.dark` class. Midnight and high-contrast dark are dark themes, so they carry
 * that class too and every `dark:` utility keeps working; their own
 * `[data-theme]` block then refines the roles on top of Graphite's.
 *
 * ## Automatic is resolved in the browser, before the first paint
 *
 * The server cannot know the device's `prefers-color-scheme` or
 * `prefers-contrast` on the first request. So for Automatic the server renders
 * `data-theme="auto"`, and `partials/theme-boot` - a blocking script in
 * `<head>`, ahead of the stylesheet and the body - resolves it to a concrete
 * theme before anything is painted, and follows the device if it changes
 * while the page is open. Every OTHER theme is rendered by the server outright
 * and needs no script at all.
 *
 * ## Why `color-scheme` follows the theme rather than saying "light dark"
 *
 * `light dark` lets the BROWSER choose scrollbars and native form controls
 * from the operating system's preference. With an explicit theme that would
 * put dark native controls on a light form - two themes on one screen. Only
 * Automatic, which follows the operating system by definition, says both.
 *
 * The value `dark` is Graphite's on purpose: it is what `session('theme')`
 * held before there were several themes, so an old preference survives.
 */
enum Theme: string
{
    case Automatic = 'auto';
    case Light = 'light';
    case Graphite = 'dark';
    case Midnight = 'midnight';
    case HighContrastLight = 'hc-light';
    case HighContrastDark = 'hc-dark';

    /**
     * Anything unknown, missing or malformed is Automatic - a preference is a
     * convenience, and a bad one must never stop a page from rendering.
     */
    public static function fromPreference(mixed $value): self
    {
        return \is_string($value) ? (self::tryFrom($value) ?? self::Automatic) : self::Automatic;
    }

    public function isDark(): bool
    {
        return match ($this) {
            self::Graphite, self::Midnight, self::HighContrastDark => true,
            self::Automatic, self::Light, self::HighContrastLight => false,
        };
    }

    /** The class Tailwind's `dark:` variant keys on. Automatic gets it from the boot script. */
    public function htmlClass(): string
    {
        return $this->isDark() ? 'dark' : '';
    }

    /** The `data-theme` attribute, for the themes that refine Light or Graphite. */
    public function dataTheme(): ?string
    {
        return match ($this) {
            self::Automatic, self::Midnight, self::HighContrastLight, self::HighContrastDark => $this->value,
            self::Light, self::Graphite => null,
        };
    }

    /** For `<meta name="color-scheme">`: native controls follow the page, not the OS. */
    public function colorScheme(): string
    {
        return match ($this) {
            self::Automatic => 'light dark',
            default => $this->isDark() ? 'dark' : 'light',
        };
    }

    /** The Arabic source string of the theme's name; translate it with `__()`. */
    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'تلقائي',
            self::Light => 'فاتح',
            self::Graphite => 'داكن',
            self::Midnight => 'منتصف الليل',
            self::HighContrastLight => 'تباين عالٍ فاتح',
            self::HighContrastDark => 'تباين عالٍ داكن',
        };
    }
}
