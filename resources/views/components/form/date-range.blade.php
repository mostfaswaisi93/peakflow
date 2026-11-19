{{--
    <x-form.date-range> - a date range that understands fiscal periods
    (PF-212).

        name      the root of the two field names: `{name}_from` and
                  `{name}_to`, and the root of their ids.
        instance  an id namespace, as <x-form.field>.
        legend    what the range is for, in words. It names the
                  <fieldset>, which is what groups the two dates into one
                  control for a screen reader.
        presets   the named ranges, each ALREADY RESOLVED by the server:
                      [['key' => 'last-period',
                        'label' => the translated name of the range,
                        'from' => '2026-09-01', 'to' => '2026-09-30',
                        'current' => false], ...]
                  (written without a call to the translator on purpose: a
                  `__()` in a comment is a catalog key as far as the check
                  that compares the two catalogs is concerned, and an
                  example is not a string anybody renders)
        from, to  the current custom range, as ISO dates.
        hint      one sentence under the control.
        error     the message for this control, or null.

    ## Why a preset must arrive resolved

    "This quarter" is not a property of the calendar on the user's
    computer. A fiscal year can start in April, a period can be a
    4-4-5 week, and a company's fourth quarter can end on a Saturday. A
    control that worked out "this quarter" in the browser would compute
    the Gregorian one, and the figures it returned would be right for a
    range nobody asked for - which is worse than an error, because it
    reconciles to nothing and looks fine.

    So each preset carries the two dates it stands for, computed against
    the entity's own calendar on the server, and this component refuses a
    preset that does not. The dates are also SHOWN beside the label: a
    reader can see which range they are about to ask for, and an
    accountant can tell at a glance that "last period" means September.

    ## The presets are radios, not a menu

    A <fieldset> of radios is a keyboard-native control with no script at
    all: arrow keys move between them, the group has one tab stop, and the
    legend names the whole thing. The two date inputs below stay usable
    for a range no preset covers, and both are `type="date"`, which gives
    the platform's own picker - including its locale and its calendar -
    rather than a hand-built one that only works with a mouse.
--}}
@props([
    'name' => null,
    'instance' => null,
    'legend' => null,
    'presets' => [],
    'from' => null,
    'to' => null,
    'hint' => null,
    'error' => null,
])

@php
    if (! is_string($name) || trim($name) === '') {
        throw new \RuntimeException('<x-form.date-range>: needs a `name`.');
    }

    if (! is_string($legend) || trim($legend) === '') {
        throw new \RuntimeException(sprintf(
            '<x-form.date-range>: the range "%s" has no `legend`. Two unlabelled date fields beside each '.
            'other are two dates; the legend is what makes them a range.',
            $name,
        ));
    }

    foreach ($presets as $position => $preset) {
        $resolved = is_array($preset)
            && is_string($preset['from'] ?? null)
            && is_string($preset['to'] ?? null)
            && is_string($preset['key'] ?? null)
            && is_string($preset['label'] ?? null);

        if (! $resolved) {
            throw new \RuntimeException(sprintf(
                '<x-form.date-range>: preset %s needs a key, a label and the two dates it resolves to. '.
                '"This quarter" is a question about the entity\'s fiscal calendar, and a preset that '.
                'arrives unresolved can only be answered against the Gregorian one.',
                is_string($preset['key'] ?? null) ? '"'.$preset['key'].'"' : (string) $position,
            ));
        }
    }

    $base = 'field-'.($instance !== null && $instance !== '' ? trim((string) $instance).'-' : '').$name;
    $base = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $base);

    $described = [];

    if ($hint !== null) {
        $described[] = $base.'-hint';
    }

    if ($error !== null) {
        $described[] = $base.'-error';
    }

    $describedBy = $described === [] ? null : implode(' ', $described);
    /*
     | A `class` given as an ARRAY is flattened before it is merged - see
     | the note in resources/views/components/grid/cell.blade.php. Without
     | it, `:class="['x' => $condition]"` throws "Array to string
     | conversion" from inside the attribute bag.
     */
    $extraClass = \Illuminate\Support\Arr::toCssClasses((array) $attributes->get('class', []));
    $attributes = $attributes->except('class');

@endphp

<fieldset {{ $attributes->class(['flex flex-col gap-3', $extraClass => $extraClass !== '']) }}
          @if ($describedBy !== null) aria-describedby="{{ $describedBy }}" @endif>
    <legend class="text-label font-medium text-on-surface">{{ $legend }}</legend>

    @if ($presets !== [])
        <div class="flex flex-wrap gap-x-4 gap-y-2">
            @foreach ($presets as $preset)
                <span class="flex items-start gap-2">
                    <input type="radio"
                           id="{{ $base }}-preset-{{ $preset['key'] }}"
                           name="{{ $name }}_preset"
                           value="{{ $preset['key'] }}"
                           @checked($preset['current'] ?? false)
                           class="mt-1 size-4 border border-outline-control
                                  focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                    <label for="{{ $base }}-preset-{{ $preset['key'] }}" class="text-label text-on-surface">
                        {{ $preset['label'] }}
                        {{-- The resolved dates, visible. They are the whole
                             reason the preset is trustworthy. --}}
                        <span dir="ltr" class="figure block text-caption text-on-surface-muted">
                            {{ $preset['from'] }} – {{ $preset['to'] }}
                        </span>
                    </label>
                </span>
            @endforeach
        </div>
    @endif

    <div class="flex flex-wrap items-end gap-3">
        <span class="flex flex-col">
            <label for="{{ $base }}-from" class="text-label text-on-surface">{{ __('من تاريخ') }}</label>
            <input type="date"
                   id="{{ $base }}-from"
                   name="{{ $name }}_from"
                   value="{{ $from }}"
                   dir="ltr"
                   @if ($error !== null) aria-invalid="true" @endif
                   class="min-h-(--control-height) figure mt-1.5 rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-body text-on-surface
                          focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
        </span>

        <span class="flex flex-col">
            <label for="{{ $base }}-to" class="text-label text-on-surface">{{ __('إلى تاريخ') }}</label>
            <input type="date"
                   id="{{ $base }}-to"
                   name="{{ $name }}_to"
                   value="{{ $to }}"
                   dir="ltr"
                   @if ($error !== null) aria-invalid="true" @endif
                   class="min-h-(--control-height) figure mt-1.5 rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-body text-on-surface
                          focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
        </span>
    </div>

    @if ($hint !== null)
        <p id="{{ $base }}-hint" class="text-caption text-on-surface-muted">{{ $hint }}</p>
    @endif

    @if ($error !== null)
        <p id="{{ $base }}-error" role="alert" class="flex items-start gap-1.5 text-label font-medium text-danger">
            <span aria-hidden="true">!</span>
            <span>{{ $error }}</span>
        </p>
    @endif
</fieldset>
