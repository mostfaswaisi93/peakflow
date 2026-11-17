{{--
    <x-trend> - which way a figure moved, never by colour alone (UX-013).

        direction  up | down | flat
        slot       the words, e.g. "4.2% vs last month" - translated by the caller

    An arrow AND a sign in the words AND the words themselves. Deliberately
    NEUTRAL in colour: money is not good news by default, and cash going up or
    receivables going up mean opposite things - a green arrow would be right
    on one tile and a lie on the next.
--}}
@props(['direction' => 'flat'])

@php
    $glyph = ['up' => '▲', 'down' => '▼', 'flat' => '●'][$direction] ?? '●';
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 text-caption text-on-surface-muted']) }}>
    <span aria-hidden="true" class="figure text-on-surface">{{ $glyph }}</span>
    <span>{{ $slot }}</span>
</span>
