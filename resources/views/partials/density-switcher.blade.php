{{--
    The density switcher - PF-203.

    The same shape as the theme switcher, and deliberately so: a <details>
    disclosure holding one form whose options are submit buttons. One click
    chooses, it works with no JavaScript, and the keyboard and screen-reader
    behaviour is the browser's own. The chosen option carries aria-pressed, so
    it is announced rather than only ticked.

    Each option says how many rows of a list it puts on a screen, because that
    is the decision somebody is actually making. "Compact" is a word; "about
    22 rows" is the thing they want to know, and it is the reason this is a
    user setting rather than a breakpoint - two people on the same 15-inch
    laptop want different answers.

    The choice is stored on the user and in a cookie, then the page reloads at
    it - rendered by the server, so the table never resizes after the paint.

    In the header: an icon button below `xl`, icon and words from `xl` up, and
    the words always present for a screen reader. See the theme switcher.
--}}
@php($densitySummary = __('الكثافة').': '.__($density->label()))
<details name="pf-header" class="group relative">
    <summary title="{{ $densitySummary }}"
             class="flex h-10 min-w-10 list-none items-center justify-center gap-2 rounded-button px-2.5 text-label text-on-surface-muted
                    transition-colors duration-(--duration-fast) ease-(--ease-standard)
                    hover:bg-surface-hover hover:text-on-surface group-open:bg-surface-selected group-open:text-on-surface
                    focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring
                    [&::-webkit-details-marker]:hidden">
        <x-icon name="density" class="size-5"/>
        <span class="sr-only xl:not-sr-only xl:whitespace-nowrap">
            {{ __('الكثافة') }}:
            <span class="font-medium text-on-surface">{{ __($density->label()) }}</span>
        </span>
    </summary>

    <form method="POST" action="{{ route('preferences.density') }}"
          class="absolute end-0 z-(--z-popover) mt-2 w-64 rounded-card border border-outline bg-surface-container-high p-2 shadow-e3">
        @csrf
        <fieldset>
            <legend class="px-3 pb-1 pt-1 text-xs text-on-surface-muted">{{ __('اختر الكثافة') }}</legend>

            @foreach (\App\Support\Ui\Density::cases() as $option)
                <button type="submit" name="density" value="{{ $option->value }}"
                        aria-pressed="{{ $option === $density ? 'true' : 'false' }}"
                        class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-start text-sm text-on-surface
                               transition-colors duration-(--duration-fast) ease-(--ease-standard)
                               hover:bg-surface-hover
                               focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring
                               aria-pressed:bg-surface-selected aria-pressed:font-medium">
                    <span>
                        {{ __($option->label()) }}
                        <span class="block text-xs font-normal text-on-surface-muted">
                            {{ __('نحو :count صفًا في الشاشة', ['count' => $option->rowsPerScreen()]) }}
                        </span>
                    </span>
                    @if ($option === $density)
                        <span aria-hidden="true" class="text-primary">✓</span>
                    @endif
                </button>
            @endforeach
        </fieldset>
    </form>
</details>
