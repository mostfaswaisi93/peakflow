{{--
    <x-brand.logo> - the PeakFlow logo, from the brand kit (public/brand/).

        size  full (default) | small | mark | monogram
        tone  auto (default): follows the theme
              on-dark: the logo sits on a dark surface in EVERY theme
              (the navy sidebar, the operator console)
        decorative  true when words beside it already say "PeakFlow": the image
                    then has an empty alt, so a screen reader does not say it twice

    The kit's rules, applied here so a template cannot get them wrong:

    - Which artwork, by the width it will render at. `full` from 200 px wide
      (its flow line cuts through the name), `small` from 120 to 200 px (the
      flow line stays in the mark), `monogram` under 120 px. The caller sets
      the HEIGHT with a class; the width follows the artwork's own ratio, so
      the logo is never stretched.
    - One wordmark in every language. The Arabic artwork exists in the kit and
      is deliberately NOT used: the product is called PeakFlow in both, and a
      name that changes shape with the interface language is a second brand
      that has to be kept in step with the first.
    - One image per theme - colour on light, colour on dark, black for high
      contrast light, white for high contrast dark - and app.css shows exactly
      one. Never recoloured, never given a shadow or an effect.
    - Clear space is the caller's: at least the height of the front peak.

    The images are files, not inline SVG, so the kit's masters are used as
    delivered and the browser caches them once for every page.
--}}
@props([
    'size' => 'full',
    'tone' => 'auto',
    'decorative' => false,
])

@php
    /*
     | The same wordmark in every language.
     |
     | The kit ships Arabic artwork and this component used to switch to
     | it on an Arabic locale. It no longer does: the product is called
     | PeakFlow in both languages, and a logo that changes with the
     | interface is a second brand - two sets of files to keep in step,
     | two things to get wrong in an export, and a customer who sees a
     | different mark depending on who opened the document.
     |
     | Direction still flips, and the type still changes: it is the
     | MARK that stays put.
     */
    $base = match ($size) {
        'mark' => 'mark',
        'monogram' => 'monogram',
        'small' => 'logo-small',
        default => 'logo',
    };

    $variants = $base === 'monogram'
        ? ['light' => 'navy-teal', 'dark' => 'white-glacier', 'hc-light' => 'black', 'hc-dark' => 'white']
        : ['light' => 'color-on-light', 'dark' => 'color-on-dark', 'hc-light' => 'black', 'hc-dark' => 'white'];

    if ($tone === 'on-dark') {
        $variants['light'] = $variants['dark'];
        $variants['hc-light'] = $variants['hc-dark'];
    }

    $alt = $decorative ? '' : __('بيك فلو');
@endphp

<span {{ $attributes->class(['brand-logo inline-flex shrink-0']) }}>
    @foreach ($variants as $theme => $variant)
        <img src="{{ asset('brand/'.$base.'-'.$variant.'.svg') }}" alt="{{ $alt }}"
             class="brand-{{ $theme }} h-full w-auto" decoding="async" draggable="false">
    @endforeach
</span>
