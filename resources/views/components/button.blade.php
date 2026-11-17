{{--
    <x-button> - UX-010.

    Flat 2.0 with a tactile press: a real edge or a real fill, pressed in 80ms
    and released on a slight rebound (`.tactile`). The label is the slot, so
    the caller translates it.

        variant  filled (default) | tonal | outline | ghost | danger | cta
        size     sm | md (default, follows the density setting) | lg
        type     button (default) | submit | reset

    `cta` is the one gradient a control may carry, and it is meant for the
    sign-in action only - its text is measured against both ends of the
    gradient in every theme (contrast-pairs.json: on-brand-cta). The high
    contrast themes make it solid.

    Buttons with text need no 3:1 boundary under WCAG 1.4.11, so `ghost` is
    allowed to have none; forced-colours mode gives every button a real
    border regardless (app.css).
--}}
@props([
    'variant' => 'filled',
    'size' => 'md',
    'type' => 'button',
])

@php
    $variants = [
        'filled' => 'bg-primary text-on-primary hover:bg-primary-hover',
        'tonal' => 'bg-primary-subtle text-on-primary-subtle hover:shadow-e1',
        'outline' => 'border border-outline-strong text-on-surface hover:bg-surface-hover',
        'ghost' => 'text-on-surface-muted hover:bg-surface-hover hover:text-on-surface',
        'danger' => 'bg-danger text-on-danger hover:shadow-e2',
        'cta' => 'bg-(image:--g-brand-cta) text-on-brand-cta shadow-e2 hover:shadow-e3',
    ];

    $sizes = [
        'sm' => 'min-h-8 px-3 text-label',
        'md' => 'min-h-(--control-height) px-4 text-label',
        'lg' => 'min-h-11 px-5 text-body',
    ];
@endphp

<button type="{{ $type }}"
        {{ $attributes->class([
            'tactile inline-flex items-center justify-center gap-2 rounded-button font-medium',
            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring',
            'disabled:cursor-not-allowed disabled:opacity-60',
            $variants[$variant] ?? $variants['filled'],
            $sizes[$size] ?? $sizes['md'],
        ]) }}>
    {{ $slot }}
</button>
