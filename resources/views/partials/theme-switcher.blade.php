{{--
    The theme switcher - UX-004.

    A <details> disclosure holding one form whose options are submit buttons:
    one click chooses, it works with no JavaScript, and the keyboard and screen
    reader behaviour is the browser's own. The chosen option carries
    aria-pressed, so it is announced, not only ticked.

    The choice is stored on the user and in a cookie, then the page reloads in
    it - rendered by the server, so it never flashes the previous theme.

    In the header it is an icon button below `xl` and an icon with its words
    from `xl` up. The words are always there for a screen reader - `sr-only`,
    not removed - so the button is announced as "المظهر: فاتح" at every width.
--}}
@php($themeSummary = __('المظهر').': '.__($theme->label()))
<details name="pf-header" class="group relative">
    <summary title="{{ $themeSummary }}"
             class="flex h-10 min-w-10 list-none items-center justify-center gap-2 rounded-button px-2.5 text-label text-on-surface-muted
                    transition-colors duration-(--duration-fast) ease-(--ease-standard)
                    hover:bg-surface-hover hover:text-on-surface group-open:bg-surface-selected group-open:text-on-surface
                    focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring
                    [&::-webkit-details-marker]:hidden">
        <x-icon name="theme" class="size-5"/>
        <span class="sr-only xl:not-sr-only xl:whitespace-nowrap">
            {{ __('المظهر') }}:
            <span class="font-medium text-on-surface">{{ __($theme->label()) }}</span>
        </span>
    </summary>

    <form method="POST" action="{{ route('preferences.theme') }}"
          class="absolute end-0 z-(--z-popover) mt-2 w-64 rounded-card border border-outline bg-surface-container-high p-2 shadow-e3">
        @csrf
        <fieldset>
            <legend class="px-3 pb-1 pt-1 text-xs text-on-surface-muted">{{ __('اختر المظهر') }}</legend>

            @foreach (\App\Support\Ui\Theme::cases() as $option)
                <button type="submit" name="theme" value="{{ $option->value }}"
                        aria-pressed="{{ $option === $theme ? 'true' : 'false' }}"
                        class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-start text-sm text-on-surface
                               transition-colors duration-(--duration-fast) ease-(--ease-standard)
                               hover:bg-surface-hover
                               focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring
                               aria-pressed:bg-surface-selected aria-pressed:font-medium">
                    <span>
                        {{ __($option->label()) }}
                        @if ($option === \App\Support\Ui\Theme::Automatic)
                            <span class="block text-xs font-normal text-on-surface-muted">{{ __('يتبع إعدادات جهازك') }}</span>
                        @endif
                    </span>
                    @if ($option === $theme)
                        <span aria-hidden="true" class="text-primary">✓</span>
                    @endif
                </button>
            @endforeach
        </fieldset>
    </form>
</details>
