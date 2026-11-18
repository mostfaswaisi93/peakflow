{{--
    <x-form.errors> - the error summary at the top of a form (PF-212,
    WCAG 3.3.1).

        bag       Laravel's $errors MessageBag, or any array of
                  field => message|messages.
        instance  the same instance key the fields were given, so the
                  links point at the right copy of a form used twice.
        title     the heading. Defaults to a sentence saying what to do.
        only      restrict the summary to these field names, for a page
                  with two forms sharing one error bag.

    ## Both ends, not one

    A summary alone makes the reader hunt for the field; an inline message
    alone makes a long form a scavenger hunt - you submit, nothing
    obvious happens, and the message is 400px below the fold. So errors
    appear in both places and the summary's entries are LINKS to the
    fields, which is the part that turns "three errors" into three
    keystrokes.

    ## It takes focus, because a live region would say nothing

    A live region is announced when its content CHANGES. This summary
    arrives with the response, so an `aria-live` wrapper around it is
    announced by nothing. Moving focus to it is what makes the refusal
    reach a screen-reader user - and `role="alert"` names it as the
    reason, so what they hear is the error and not just a heading.

    `autofocus` rather than a script: the whole point is that the form
    works without one.
--}}
@props([
    'bag' => null,
    'instance' => null,
    'title' => null,
    'only' => null,
])

@php
    $messages = [];

    if ($bag instanceof \Illuminate\Support\MessageBag) {
        $messages = $bag->messages();
    } elseif (is_array($bag)) {
        $messages = $bag;
    }

    if (is_array($only)) {
        $messages = array_intersect_key($messages, array_flip($only));
    }

    /*
     | The id is derived exactly as resources/views/components/form/field.blade.php
     | derives it. The two have to agree or every link here goes nowhere,
     | silently - an anchor to a missing id scrolls nothing and reports
     | nothing - so `bin/verify grid` renders a field and this summary for
     | the same name and compares them.
     |
     | Laravel names a nested field `lines.0.amount`; the id keeps the
     | same shape with the dots replaced, which is what the field
     | component does to the same name.
     */
    $idFor = static function (string $field) use ($instance): string {
        $id = 'field-'.($instance !== null && $instance !== '' ? trim((string) $instance).'-' : '').$field;

        return (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $id);
    };

    /*
     | A `class` given as an ARRAY is flattened before it is merged - see
     | the note in resources/views/components/grid/cell.blade.php. Without
     | it, `:class="['x' => $condition]"` throws "Array to string
     | conversion" from inside the attribute bag.
     */
    $extraClass = \Illuminate\Support\Arr::toCssClasses((array) $attributes->get('class', []));
    $attributes = $attributes->except('class');

    $entries = [];

    foreach ($messages as $field => $message) {
        foreach ((array) $message as $one) {
            $entries[] = ['id' => $idFor((string) $field), 'message' => (string) $one];
        }
    }
@endphp

@if ($entries !== [])
    <div role="alert" tabindex="-1" autofocus
         {{ $attributes->class([
             'rounded-card border border-danger bg-danger-subtle px-4 py-3 text-on-danger-subtle',
             'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring',
             $extraClass => $extraClass !== '',
         ]) }}>
        <h2 class="flex items-center gap-2 text-label font-semibold">
            <span aria-hidden="true">!</span>
            {{ $title ?? __('لم يُحفظ شيء. صحِّح ما يلي ثم أعد المحاولة.') }}
        </h2>

        <ul class="mt-2 flex list-inside list-disc flex-col gap-1 text-label">
            @foreach ($entries as $entry)
                <li>
                    <a href="#{{ $entry['id'] }}"
                       class="underline underline-offset-2
                              focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                        {{ $entry['message'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
