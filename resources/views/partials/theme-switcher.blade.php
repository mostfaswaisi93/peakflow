{{--
    The theme switcher - UX-004.

    A <details> disclosure holding one form whose options are submit buttons:
    one click chooses, it works with no JavaScript, and the keyboard and screen
    reader behaviour is the browser's own. The chosen option carries
    aria-pressed, so it is announced, not only ticked.

    The choice is stored on the user and in a cookie, then the page reloads in
    it - rendered by the server, so it never flashes the previous theme.
--}}
<details class="relative">
    <summary class="flex cursor-pointer list-none items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm text-on-surface-muted
                    transition-colors duration-(--duration-fast) ease-(--ease-standard)
                    hover:bg-surface-hover hover:text-on-surface
                    focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring
                    [&::-webkit-details-marker]:hidden">
        {{ __('المظهر') }}:
        <span class="font-medium text-on-surface">{{ __($theme->label()) }}</span>
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
