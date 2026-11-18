{{--
    <x-icon name="..."> - the interface's line icons.

        name   home | sales | purchasing | bank | ledger | masters | menu
        class  the size; 20px (size-5) in the sidebar, 16px (size-4) inline

    Inline SVG rather than a sprite or a font, for three reasons that each
    cost something the other way:

    - It inherits `currentColor`, so an icon in the sidebar is the same
      colour as the label beside it in every theme, including the two
      high-contrast ones, with no second set of rules to keep in step.
    - A sprite needs a request, and the sidebar is on every page: the icon
      arriving after the text is a visible shuffle on a slow connection.
    - An icon font renders as a missing-glyph box when the font fails, and
      the box looks like a bug in the product rather than a missing file.

    They are decorative. The label is always beside them, so `aria-hidden`
    is correct and a screen reader is not told "home, Overview".
--}}
@props(['name'])

@php
    /*
     | One stroke weight, one grid, one cap style.
     |
     | Mixed icon sets are the most visible kind of inconsistency in an
     | interface, because the eye compares the six of them in a single
     | column. These are drawn on a 24-unit grid at 1.5 stroke, and a new
     | one belongs here rather than pasted into a page.
     */
    $paths = [
        'home' => 'M3 10.5 12 3l9 7.5M5.25 9.75V20.25h13.5V9.75M9.75 20.25v-6h4.5v6',
        'sales' => 'M6.75 3.75h10.5l1.5 3v13.5a.75.75 0 0 1-.75.75H6a.75.75 0 0 1-.75-.75V6.75l1.5-3ZM5.25 6.75h13.5M9 11.25h6M9 15h6',
        'purchasing' => 'M3.75 5.25h2.25l2.25 10.5h9.75l2.25-7.5H6.75M9.75 19.5a1.125 1.125 0 1 1-2.25 0 1.125 1.125 0 0 1 2.25 0ZM18 19.5a1.125 1.125 0 1 1-2.25 0 1.125 1.125 0 0 1 2.25 0Z',
        'bank' => 'M3 9.75 12 4.5l9 5.25M4.5 9.75v9.75h15V9.75M8.25 12.75v4.5M12 12.75v4.5M15.75 12.75v4.5M2.25 19.5h19.5',
        'ledger' => 'M5.25 3.75h10.5l3 3v13.5H5.25V3.75ZM8.25 8.25h6M8.25 11.25h7.5M8.25 14.25h7.5M8.25 17.25h4.5',
        'masters' => 'M9 11.25a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM2.25 19.5a6.75 6.75 0 0 1 13.5 0M16.5 11.25a2.25 2.25 0 1 0 0-4.5M18 19.5a5.25 5.25 0 0 0-2.25-4.3',
        'menu' => 'M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5',
    ];
@endphp

<svg {{ $attributes->class(['shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <path d="{{ $paths[$name] ?? $paths['home'] }}"/>
</svg>
