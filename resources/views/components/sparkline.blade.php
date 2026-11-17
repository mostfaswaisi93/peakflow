{{--
    <x-sparkline> - a series as a line, drawn on the server (UX-023).

        points  the values, oldest first (numbers or numeric strings)
        label   what the line says in words - the trend a sighted reader takes
                from the shape; it is the image's accessible name
        area    fill under the line (for the cash position)

    Plain SVG, no chart library: a tile is not a chart, and a sparkline needs
    no axis, no tooltip and no script. It stretches to its box, keeps its
    stroke width (non-scaling-stroke), and marks the last point because "where
    is it now" is the question a sparkline answers.

    Values are compared as floats for DRAWING only; nothing here is money
    arithmetic, and no drawn value is ever shown as a figure.
--}}
@props([
    'points' => [],
    'label',
    'area' => false,
])

@php
    $values = array_values(array_map(static fn ($v): float => (float) $v, $points));
    $count = count($values);
    $width = 100;
    $height = 32;
    $line = '';
    $last = null;

    if ($count >= 2) {
        $min = min($values);
        $max = max($values);
        $span = $max - $min ?: 1.0;
        $coords = [];

        foreach ($values as $i => $value) {
            $x = round($i / ($count - 1) * $width, 2);
            $y = round($height - 2 - (($value - $min) / $span) * ($height - 4), 2);
            $coords[] = $x.','.$y;
            $last = [$x, $y];
        }

        $line = implode(' ', $coords);
    }
@endphp

@if ($count >= 2)
    <svg viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" role="img" aria-label="{{ $label }}"
         {{ $attributes->class(['block h-10 w-full overflow-visible text-accent']) }}>
        @if ($area)
            <polygon points="0,{{ $height }} {{ $line }} {{ $width }},{{ $height }}" class="fill-current opacity-15"/>
        @endif
        <polyline points="{{ $line }}" fill="none" stroke="currentColor" stroke-width="1.75"
                  stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
        {{-- A zero-length stroke with a round cap, not a <circle>: the drawing is
             stretched to its box, which would turn a circle into an ellipse,
             and a non-scaling stroke stays round. --}}
        <path d="M{{ $last[0] }} {{ $last[1] }}h0" stroke="currentColor" stroke-width="6"
              stroke-linecap="round" vector-effect="non-scaling-stroke"/>
    </svg>
@endif
