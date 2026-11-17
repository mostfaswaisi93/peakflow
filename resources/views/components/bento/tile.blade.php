{{--
    <x-bento.tile> - one tile of the Overview grid (UX-013, UX-032).

        heading  the tile's name, translated by the caller
        href     where the figure drills down to; when set, the WHOLE tile is
                 one link (the title's link is stretched over the tile), so
                 there is one tab stop and no link nested inside another
        class    the grid span, e.g. "xl:col-span-6 xl:row-span-2"

    Slots: the default slot is the body; `aside` sits beside the title (a
    status chip, a period). A tile never animates its CONTENT - only the lift
    on hover, which needs a hover-capable pointer and is dropped under reduced
    motion.
--}}
@props([
    'heading',
    'href' => null,
])

<article {{ $attributes->class([
    'group relative flex flex-col rounded-tile border border-outline bg-surface p-5 shadow-e1',
    'transition-[translate,box-shadow] duration-(--duration-base) ease-(--ease-standard)',
    'hover:shadow-e2 motion-safe:hover:-translate-y-0.5' => $href !== null,
    'focus-within:shadow-e2' => $href !== null,
]) }}>
    <header class="flex items-start justify-between gap-3">
        <h2 class="text-label font-medium text-on-surface-muted">
            @if ($href)
                <a href="{{ $href }}"
                   class="rounded-sm after:absolute after:inset-0 after:rounded-tile after:content-['']
                          focus-visible:outline-none focus-visible:after:outline-2 focus-visible:after:outline-offset-2 focus-visible:after:outline-focus-ring
                          group-hover:text-on-surface">
                    {{ $heading }}
                </a>
            @else
                {{ $heading }}
            @endif
        </h2>

        @isset($aside)
            <div class="relative shrink-0">{{ $aside }}</div>
        @endisset
    </header>

    <div class="mt-3 flex flex-1 flex-col">
        {{ $slot }}
    </div>
</article>
