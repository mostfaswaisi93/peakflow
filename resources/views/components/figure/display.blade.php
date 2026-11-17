{{--
    <x-figure.display> - an editorial figure (UX-005).

    For the figures that are the HERO of a screen - the balance on a welcome
    card, the KPI in a dashboard tile. Never for a table cell, which stays at
    the density's size.

        value      the amount, ALREADY formatted (grouping, digits, sign)
        currency   the ISO code, shown smaller and quieter: the figure is the
                   hero, the unit is context
        size       display-xl | display (default) | headline | title
        separator  the decimal separator in `value` - the decimals are set
                   lighter, so the important part is read first without any
                   precision being dropped

    Tabular figures and `dir="ltr"` always: digits read left to right in both
    languages, and a figure must never be reordered by the Arabic around it.
--}}
@props([
    'value',
    'currency' => null,
    'size' => 'display',
    'separator' => '.',
])

@php
    $value = (string) $value;
    $cut = mb_strrpos($value, $separator);
    $whole = $cut === false ? $value : mb_substr($value, 0, $cut);
    $fraction = $cut === false ? null : mb_substr($value, $cut);

    $sizes = [
        'display-xl' => 'text-display-xl',
        'display' => 'text-display',
        'headline' => 'text-headline',
        'title' => 'text-title',
    ];
@endphp

<span dir="ltr" {{ $attributes->class(['figure figure-display inline-flex items-baseline gap-2 font-semibold text-figure', $sizes[$size] ?? $sizes['display']]) }}>
    <span>{{ $whole }}@if ($fraction !== null)<span class="font-normal text-on-surface-muted">{{ $fraction }}</span>@endif</span>
    @if ($currency)
        <span class="figure-unit">{{ $currency }}</span>
    @endif
</span>
