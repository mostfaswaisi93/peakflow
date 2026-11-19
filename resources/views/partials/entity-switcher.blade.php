@php
    /** @var \App\Tenant\Tenancy\Models\Entity|null $current */
    $current = app()->bound('currentEntity') ? app('currentEntity') : null;
    $available = auth()->user()?->entitiesForSwitcher() ?? collect();
    $currentName = $current?->legal_name;
@endphp

@if ($current !== null)
    @if ($available->count() <= 1)
        {{--
            One entity: no control, just the name.

            A switcher that can only switch to where you already are is a
            control that teaches people to ignore controls.

            A chip the same height as the period beside it. The name
            truncates rather than wraps, and `title` keeps the whole of it
            one hover away: a long legal name must not push the header onto
            a second line.
        --}}
        <span title="{{ $currentName }}"
              class="inline-flex h-11 min-w-0 max-w-64 shrink items-center gap-2 rounded-button border border-outline-subtle bg-surface-sunken px-3 text-label font-medium text-on-surface">
            <x-icon name="building" class="size-4 text-on-surface-muted"/>
            {{-- `dir="auto"`: a Latin legal name in an Arabic page otherwise
                 truncates from its first letters ("...mo Trading Co"). --}}
            <span dir="auto" class="truncate">{{ $currentName }}</span>
        </span>
    @else
        <form method="POST" action="{{ route('entity.switch') }}" class="flex min-w-0 shrink items-center gap-2">
            @csrf

            <label for="entity-switcher" class="sr-only">{{ __('الشركة النشطة') }}</label>

            {{--
                A native <select> inside a form.

                Keyboard operable and screen-reader correct without a line of
                JavaScript, and it still works if the script fails to load -
                which for the control that decides WHICH COMPANY you are
                posting into is the right level of robustness.

                It submits on change through `js/header.js`, which listens
                for `data-submit-on-change`. Not an `onchange=` attribute: the
                CSP sends `script-src-attr 'none'`, which refuses every
                inline handler, so the attribute this used to carry never ran
                and choosing a company did nothing at all.
            --}}
            <select id="entity-switcher" name="entity" data-submit-on-change
                    class="h-11 min-w-0 max-w-64 rounded-button border border-outline-control bg-surface-sunken px-3 text-label font-medium text-on-surface
                           focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                @foreach ($available as $entity)
                    <option value="{{ $entity->id }}" @selected($entity->id === $current->id)>
                        {{ $entity->legal_name }}
                    </option>
                @endforeach
            </select>

            {{-- The fallback for a browser with scripting off. --}}
            <noscript>
                <button type="submit"
                        class="h-11 rounded-button bg-primary px-3 text-label text-on-primary">{{ __('تبديل') }}</button>
            </noscript>
        </form>
    @endif
@endif
