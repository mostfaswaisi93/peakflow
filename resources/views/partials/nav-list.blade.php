{{--
    The navigation, as a list - PF-204.

    Included twice by `partials/sidebar`: once in the column that stands
    beside the content on a wide screen, once inside the disclosure that
    replaces it on a narrow one. Twice, so that the LIST is written once:
    two copies of the sections would be two things to update when a page
    is added, and the one that got missed would be the one on phones.

    `$sections` comes from `App\Support\Ui\Navigation::forCurrentUser()`.
--}}
<ul role="list" class="space-y-6">
    @foreach ($sections as $section)
        <li>
            <div class="flex items-center gap-2 px-3 pb-2 text-caption font-medium text-on-sidebar-muted">
                <x-icon :name="$section['icon']" class="size-4"/>
                <span>{{ __($section['label_ar']) }}</span>
            </div>

            <ul role="list" class="space-y-0.5">
                @foreach ($section['items'] as $item)
                    @php($current = $item['available'] && \App\Support\Ui\Navigation::isCurrent($item['route']))

                    <li>
                        @if ($item['available'])
                            {{--
                                `aria-current="page"` as well as the colour.

                                The selected row is marked by a background and
                                a border, and neither reaches a screen reader
                                or survives forced-colours mode. Without this
                                attribute a blind user has no way to tell which
                                of twenty rows they are on.
                            --}}
                            <a href="{{ $item['url'] }}"
                               @if ($current) aria-current="page" @endif
                               @class([
                                   'flex items-center justify-between gap-2 rounded-(--radius-field) px-3 py-2 text-body',
                                   'transition-colors duration-(--duration-fast)',
                                   'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring',
                                   'border-s-2',
                                   $current
                                       ? 'border-sidebar-accent bg-sidebar-selected font-medium text-on-sidebar'
                                       : 'border-transparent text-on-sidebar-muted hover:bg-sidebar-raised hover:text-on-sidebar',
                               ])>
                                <span>{{ __($item['label_ar']) }}</span>
                            </a>
                        @else
                            {{--
                                A page that does not exist yet is listed, and
                                is not a link.

                                Hiding it would be tidier and would tell a new
                                user nothing about what the product is for -
                                and an operator comparing the menu against the
                                backlog could not see where the gaps are. It is
                                not focusable, so nobody tabs into a 404, and
                                the word beside it says why it does nothing.
                            --}}
                            <span class="flex items-center justify-between gap-2 border-s-2 border-transparent px-3 py-2 text-body text-on-sidebar-muted opacity-60">
                                <span>{{ __($item['label_ar']) }}</span>
                                <span class="rounded-(--radius-field) bg-sidebar-raised px-1.5 py-0.5 text-caption">
                                    {{ __('قريباً') }}
                                </span>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </li>
    @endforeach
</ul>
