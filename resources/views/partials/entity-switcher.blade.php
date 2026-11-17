@php
    /** @var \App\Tenant\Tenancy\Models\Entity|null $current */
    $current = app()->bound('currentEntity') ? app('currentEntity') : null;
    $available = auth()->user()?->entitiesForSwitcher() ?? collect();
@endphp

@if ($current !== null)
    @if ($available->count() <= 1)
        {{--
            One entity: no control, just the name.

            A switcher that can only switch to where you already are is a
            control that teaches people to ignore controls.
        --}}
        <span class="rounded-lg bg-surface-sunken px-3 py-1.5 text-sm font-medium text-on-surface">
            {{ $current->legal_name }}
        </span>
    @else
        <form method="POST" action="{{ route('entity.switch') }}" class="flex items-center gap-2">
            @csrf

            <label for="entity-switcher" class="sr-only">{{ __('الشركة النشطة') }}</label>

            {{--
                A native <select> inside a form.

                Keyboard operable and screen-reader correct without a line of
                JavaScript, and it still works if the script fails to load -
                which for the control that decides WHICH COMPANY you are
                posting into is the right level of robustness.
            --}}
            <select id="entity-switcher" name="entity" onchange="this.form.requestSubmit()"
                    class="rounded-lg border border-outline-control bg-surface-sunken px-3 py-1.5 text-sm font-medium text-on-surface
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
                        class="rounded-lg bg-primary px-3 py-1.5 text-sm text-on-primary">{{ __('تبديل') }}</button>
            </noscript>
        </form>
    @endif
@endif
