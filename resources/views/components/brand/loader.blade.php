{{--
    <x-brand.loader> - the mark, as the thing that says "working" - PF-279.

        value  null (default) for an indeterminate loader - we do not know
               how long. A number 0-100 for a determinate one.
        label  what is being waited for, for the screen reader.

    ## Why this is inline SVG when the logo is files

    `<x-brand.logo>` loads the kit's masters as `<img>` on purpose, so the
    artwork is used exactly as delivered and cached once. This cannot: the
    motion is INSIDE the drawing - the flow line travels across the peaks -
    and a browser does not animate the inside of an `<img>`.

    So it is the one place the mark is redrawn, and the paths are copied
    from `public/brand/mark-color-on-light.svg` rather than reinterpreted.
    The colours come from the brand tokens, which `bin/verify design` holds
    to the kit's exact values.

    ## The motion is the product's name

    PeakFlow's mark is two peaks with a flow line cutting through them. The
    loader moves that line across the peaks and back. It is the one gesture
    in the mark that is already about movement, so the loader is recognisably
    the brand doing what the brand draws rather than a spinner wearing it.

    ## It loops, which the motion rules otherwise forbid

    "Nothing loops" exists to stop decoration. An indeterminate loader is not
    decoration: the repetition IS the information - it is what says the work
    has not finished - and one that stopped after 500ms would be a loader
    that freezes while the page is still loading, which reads as a broken
    page rather than a slow one. `motionStaysShort()` grants exactly this
    selector and nothing else.
--}}
@props([
    'value' => null,
    'label' => null,
])

@php
    $determinate = $value !== null;
    $percent = $determinate ? max(0, min(100, (int) $value)) : null;
    $label ??= __('جارٍ التحميل');
@endphp

<div class="pf-loader"
     @if ($determinate)
         role="progressbar"
         aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"
     @else
         role="status"
     @endif
     aria-label="{{ $label }}"
     {{ $attributes->class(['pf-loader--determinate' => $determinate]) }}>

    {{-- The mark. `aria-hidden`, because the role above already says it. --}}
    <svg class="pf-loader__mark" viewBox="4 12 116 90" aria-hidden="true" focusable="false">
        <defs>
            {{-- The flow line, as a mask - the same construction as the kit's
                 mark, so the peaks are cut by it exactly as they are in the
                 logo. The mask is what moves. --}}
            <mask id="pf-loader-flow-{{ $id = uniqid('l') }}" maskUnits="userSpaceOnUse" x="-10" y="0" width="140" height="130">
                <rect x="-10" y="0" width="140" height="130" fill="#fff"/>
                <path class="pf-loader__flow"
                      d="M0 90 C 26 74, 46 102, 70 84 S 104 68, 120 74"
                      fill="none" stroke="#000" stroke-width="8" stroke-linecap="round"/>
            </mask>
            <mask id="pf-loader-front-{{ $id }}" maskUnits="userSpaceOnUse" x="-20" y="-20" width="160" height="160">
                <rect x="-20" y="-20" width="160" height="160" fill="#fff"/>
                <path d="M44 100 L76 48 Q81 40 86 48 L118 100 Z"
                      fill="none" stroke="#000" stroke-width="5" stroke-linejoin="round"/>
            </mask>
        </defs>

        <g mask="url(#pf-loader-flow-{{ $id }})">
            <path d="M6 100 L50 28 Q56 18 62 28 L106 100 Z"
                  fill="var(--color-brand-peak-back)" mask="url(#pf-loader-front-{{ $id }})"/>
            <path d="M44 100 L76 48 Q81 40 86 48 L118 100 Z" fill="var(--color-brand-peak-front)"/>
            <circle cx="94" cy="22" r="7" fill="var(--color-brand-summit)"/>
        </g>
    </svg>

    @if ($determinate)
        {{-- A bar that cannot lie: its width is the number it was given.
             A determinate loader whose bar is a guess is the most irritating
             thing an interface does, so there is nowhere here for a guess to
             enter - no easing toward a target, no creep while waiting. --}}
        <div class="pf-loader__track">
            <div class="pf-loader__bar" style="inline-size: {{ $percent }}%"></div>
        </div>
    @endif

    {{-- Visible text, not only an aria-label: under reduced motion the mark
         stops, and a stopped mark with no words is a logo somebody is
         waiting beside without knowing they are waiting. --}}
    <p class="pf-loader__label">{{ $label }}</p>
</div>
