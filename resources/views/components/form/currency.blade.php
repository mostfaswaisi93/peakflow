{{--
    <x-form.currency> - an amount input (PF-212).

        name       the control's name, and the root of its id.
        instance   an id namespace, as <x-form.field>.
        label      the control's name in words.
        currency   the ISO code. Shown beside the field, because an amount
                   with no currency is a number somebody will read as
                   their own.
        exponent   the currency's ISO 4217 exponent - 2 for most, 3 for the
                   Jordanian, Kuwaiti, Bahraini and Tunisian dinars and the
                   Omani rial, 0 for the yen and the won. Said in the hint,
                   NOT enforced in the browser; see below.
        value      the current value, as a string.
        hint       replaces the generated hint when the page has something
                   better to say.
        error      the message for this field, or null.
        required   marks it required, in the attribute and in words.

    ## What the browser does, and what it must not do

    It groups the integer part as you type, accepts Arabic-Indic digits
    (١٢٣ and ۱۲۳), accepts a pasted value carrying separators, and leaves
    everything after the decimal point exactly as entered.

    It does NOT round and it does NOT truncate. An input that quietly cut
    a third decimal would turn 1.234 into 1.23 on a three-decimal
    currency, and nothing anywhere would report it: the figure would be
    wrong by a tenth of a fils, consistently, in a field whose whole job
    is to be exact. Too many decimals is a REFUSAL, from the server, which
    is the only place the currency's exponent is really known - the client
    is told the exponent so it can say what is expected, not so it can
    enforce it.

    `type="text"` with `inputmode="decimal"` rather than `type="number"`,
    deliberately: a number input drops what it cannot parse on some
    browsers - including a pasted "1,234.56" - and its spinner steps a
    monetary value by one whole unit.
--}}
@props([
    'name' => null,
    'instance' => null,
    'label' => null,
    'currency' => null,
    'exponent' => 2,
    'value' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
])

@php
    if (! is_string($name) || trim($name) === '') {
        throw new \RuntimeException('<x-form.currency>: needs a `name`.');
    }

    if (! is_string($label) || trim($label) === '') {
        throw new \RuntimeException(sprintf('<x-form.currency>: the control "%s" has no `label`.', $name));
    }

    if (! is_string($currency) || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
        /*
         | A money field with no currency is the defect this refuses.
         |
         | The amount renders perfectly and the reader supplies the
         | currency from their own assumption, which on a page that holds
         | documents in four of them is a guess.
         */
        throw new \RuntimeException(sprintf(
            '<x-form.currency>: the control "%s" needs a three-letter `currency`. An amount with no '.
            'currency beside it is read in whichever one the reader assumes.',
            $name,
        ));
    }

    // Derived as <x-form.field> derives it - see the note there.
    $fieldId = 'field-'.($instance !== null && $instance !== '' ? trim((string) $instance).'-' : '').$name;
    $fieldId = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $fieldId);

    $exponent = max(0, (int) $exponent);

    /*
     | A count LABEL, not a counted sentence.
     |
     | Arabic agreement is on the LAST TWO DIGITS of the number, so
     | "خانتان" and "3 خانات" and "11 خانة" are three different endings
     | and no `:count` sentence is right for all of them. ICU is what
     | resolves that (App\Support\Lang\Message says why the pipe
     | pluraliser cannot), and a label sidesteps the question entirely -
     | which is the proportionate answer for a hint whose number is 0, 2
     | or 3 and never anything else.
     */
    $hint ??= __('الخانات العشرية في هذه العملة: :count', ['count' => $exponent]);

    /*
     | A `class` given as an ARRAY is flattened before it is merged - see
     | the note in resources/views/components/grid/cell.blade.php. Without
     | it, `:class="['x' => $condition]"` throws "Array to string
     | conversion" from inside the attribute bag.
     */
    $extraClass = \Illuminate\Support\Arr::toCssClasses((array) $attributes->get('class', []));
    $attributes = $attributes->except('class');

    $described = [$fieldId.'-hint'];

    if ($error !== null) {
        $described[] = $fieldId.'-error';
    }
@endphp

{{-- The masking behaviour. The partial dedupes itself. --}}
@include('partials.enhancements')

<div class="flex flex-col">
    <label for="{{ $fieldId }}" class="block text-label font-medium text-on-surface">
        {{ $label }}
        @if ($required)
            <span aria-hidden="true" class="text-danger">*</span>
            <span class="sr-only">{{ __('مطلوب') }}</span>
        @endif
    </label>

    <span class="mt-1.5 flex items-stretch gap-2">
        {{--
            `dir="ltr"` and the figure face on the field itself: digits
            read left to right in both languages, and a figure typed into
            an RTL field puts the caret and the grouping on the wrong
            side of the number.
        --}}
        <input type="text"
               id="{{ $fieldId }}"
               name="{{ $name }}"
               value="{{ $value }}"
               inputmode="decimal"
               dir="ltr"
               autocomplete="off"
               data-pf-currency="{{ $currency }}"
               aria-describedby="{{ implode(' ', $described) }}"
               @if ($required) required @endif
               @if ($error !== null) aria-invalid="true" @endif
               {{ $attributes->class([
                   'figure block w-full rounded-field border border-outline-control bg-surface-sunken',
                   'px-3 py-2 text-body text-on-surface',
                   'focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring',
                   'border-danger' => $error !== null,
                   $extraClass => $extraClass !== '',
               ]) }}>

        <span dir="ltr" class="figure inline-flex shrink-0 items-center rounded-field border border-outline bg-surface-container px-3 text-label font-semibold text-on-surface-muted">
            {{ $currency }}
        </span>
    </span>

    <p id="{{ $fieldId }}-hint" class="mt-1.5 text-caption text-on-surface-muted">{{ $hint }}</p>

    @if ($error !== null)
        <p id="{{ $fieldId }}-error" class="mt-1.5 flex items-start gap-1.5 text-label font-medium text-danger">
            <span aria-hidden="true">!</span>
            <span>{{ $error }}</span>
        </p>
    @endif
</div>
