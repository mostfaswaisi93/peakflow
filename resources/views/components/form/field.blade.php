{{--
    <x-form.field> - one labelled control (PF-212).

        name          the control's name, and the root of its id.
        instance      an id namespace, for a component used twice on one
                      page. See "ids are derived" below.
        label         the control's name in words. Required: a placeholder
                      is not a name, and a <label> that names no control
                      names nothing.
        as            input (default) | select | textarea
        type          the input type, when `as` is input.
        value         the current value. A page passes `old('name', $x)`.
        hint          one sentence under the control, linked by
                      aria-describedby - not a placeholder, which
                      disappears exactly when it is needed.
        error         the message for this field, or null. Linked by
                      aria-describedby too, so it is read when the field is
                      reached rather than only seen.
        required      marks the control required, in the attribute AND in
                      words.
        autocomplete  the autocomplete token. Pass one wherever the browser
                      can help; `off` only where it must not.
        inputmode     the on-screen keyboard. `decimal` for a figure,
                      `numeric` for a reference, `tel`, `email`, `url`.
        dir           `auto` for anything that may be Arabic or Latin, so
                      the caret starts at the right end of what is typed.
        options       for `as="select"`, a map of value => label. The slot
                      is used instead when an option needs markup.

    ## Ids are derived from an instance key, not generated

    A component used twice on one page with the same ids points its
    <label for> at whichever copy the browser saw first, so clicking the
    second label focuses the first field - and the screen-reader name of
    the second field is the first one's. Neither looks broken.

    A random id would avoid the collision and lose the other half: the
    errors summary (<x-form.errors>) links to `#field-...` and has to be
    able to work out the same id without being told it. So the id is
    `field-[instance-]name`, derived identically in both components and
    checked against each other by `bin/verify grid`.

    ## Why the error is not a live region here

    A live region announces a CHANGE. The error this field renders arrives
    with the page, so a live region around it says nothing at all. The
    announcement is <x-form.errors> taking focus on load; this end of it
    is `aria-invalid` plus `aria-describedby`, which is what a screen
    reader reads when the user reaches the field - and a glyph plus the
    word, because colour alone is not a message (WCAG 1.4.1).
--}}
@props([
    'name' => null,
    'instance' => null,
    'label' => null,
    'as' => 'input',
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
    'autocomplete' => null,
    'inputmode' => null,
    'dir' => null,
    'options' => null,
])

@php
    if (! is_string($name) || trim($name) === '') {
        throw new \RuntimeException('<x-form.field>: needs a `name`; it is the control\'s name and the root of its id.');
    }

    if (! is_string($label) || trim($label) === '') {
        throw new \RuntimeException(sprintf(
            '<x-form.field>: the control "%s" has no `label`. A placeholder is not a name: it is gone as soon '.
            'as the user types, and it is never read as the field\'s name.',
            $name,
        ));
    }

    /*
     | The id, derived the same way in resources/views/components/form/errors.blade.php.
     | Changing it here without changing it there breaks every link in the
     | errors summary, which is why the two are compared by a check rather
     | than by memory.
     */
    $fieldId = 'field-'.($instance !== null && $instance !== '' ? trim((string) $instance).'-' : '').$name;
    $fieldId = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $fieldId);

    $described = [];

    if ($hint !== null) {
        $described[] = $fieldId.'-hint';
    }

    if ($error !== null) {
        $described[] = $fieldId.'-error';
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

    $control = [
        'id' => $fieldId,
        'name' => $name,
        'class' => 'mt-1.5 block w-full rounded-field border border-outline-control bg-surface-sunken '
            .'px-3 py-2 text-body text-on-surface '
            .'focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring '
            .($error !== null ? 'border-danger ' : '')
            .$extraClass,
    ];
@endphp

{{--
    The attribute bag goes to the CONTROL, not to this wrapper: `maxlength`,
    `pattern`, `step`, `min`, `list` and `data-*` all belong on the thing
    being typed into, and a page that wants to lay the field out wraps it
    itself - which is what the three pages this replaces already do.
--}}
<div class="flex flex-col">
    <label for="{{ $fieldId }}" class="block text-label font-medium text-on-surface">
        {{ $label }}
        @if ($required)
            {{-- The glyph AND the word: an asterisk is a convention a
                 reader has to know, and a colour is not a message. --}}
            <span aria-hidden="true" class="text-danger">*</span>
            <span class="sr-only">{{ __('مطلوب') }}</span>
        @endif
    </label>

    @if ($as === 'select')
        <select {{ $attributes->merge($control) }}
                @if ($required) required @endif
                @if ($describedBy !== null) aria-describedby="{{ $describedBy }}" @endif
                @if ($error !== null) aria-invalid="true" @endif>
            @if ($options !== null)
                @foreach ($options as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" @selected((string) $optionValue === (string) $value)>{{ $optionLabel }}</option>
                @endforeach
            @else
                {{ $slot }}
            @endif
        </select>
    @elseif ($as === 'textarea')
        <textarea {{ $attributes->merge($control) }}
                  rows="4"
                  @if ($required) required @endif
                  @if ($dir !== null) dir="{{ $dir }}" @endif
                  @if ($describedBy !== null) aria-describedby="{{ $describedBy }}" @endif
                  @if ($error !== null) aria-invalid="true" @endif>{{ $value }}</textarea>
    @else
        {{--
            No field rejects a paste, and none of them ever will here: a
            field that blocks paste is a field somebody retypes an IBAN
            into, which is how a digit changes.
        --}}
        <input type="{{ $type }}"
               value="{{ $value }}"
               {{ $attributes->merge($control) }}
               @if ($required) required @endif
               @if ($autocomplete !== null) autocomplete="{{ $autocomplete }}" @endif
               @if ($inputmode !== null) inputmode="{{ $inputmode }}" @endif
               @if ($dir !== null) dir="{{ $dir }}" @endif
               @if ($describedBy !== null) aria-describedby="{{ $describedBy }}" @endif
               @if ($error !== null) aria-invalid="true" @endif>
    @endif

    @if ($hint !== null)
        <p id="{{ $fieldId }}-hint" class="mt-1.5 text-caption text-on-surface-muted">{{ $hint }}</p>
    @endif

    @if ($error !== null)
        <p id="{{ $fieldId }}-error" class="mt-1.5 flex items-start gap-1.5 text-label font-medium text-danger">
            <span aria-hidden="true">!</span>
            <span>{{ $error }}</span>
        </p>
    @endif
</div>
