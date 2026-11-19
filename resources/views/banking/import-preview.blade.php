@extends('layouts.app', ['title' => __('معاينة الاستيراد')])

@section('content')
    <div class="mx-auto max-w-5xl">
        <h1 class="text-xl font-semibold text-on-surface">{{ __('معاينة الاستيراد') }}</h1>
        <p class="mt-2 text-sm text-on-surface-muted">
            {{ __(':file إلى :account', ['file' => $fileName, 'account' => $account->shortIdentifier()]) }}
        </p>

        @if ($errors->any())
            <div role="alert" tabindex="-1" id="errors"
                 class="mt-6 rounded-lg border border-danger bg-danger-subtle px-4 py-3 text-sm text-on-danger-subtle">
                <h2 class="font-medium">{{ __('تعذّر الاستيراد') }}</h2>
                <ul class="mt-2 space-y-2">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (! $isCsv)
            {{-- A format that names its own columns needs no mapping: what
                 is worth showing is what the file says, before it is
                 believed. --}}
            @foreach ($statements as $statement)
                <section class="mt-6 rounded-xl border border-outline bg-surface p-6 shadow-sm">
                    <h2 class="text-base font-medium text-on-surface">
                        {{ $statement->statementNumber ?? __('كشف بلا رقم من البنك') }}
                    </h2>

                    <dl class="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                        <div>
                            <dt class="text-on-surface-subtle">{{ __('الحساب') }}</dt>
                            <dd class="figure text-on-surface">{{ $statement->identification() ?? __('غير مذكور') }}</dd>
                        </div>
                        <div>
                            <dt class="text-on-surface-subtle">{{ __('الفترة') }}</dt>
                            <dd class="figure text-on-surface">
                                {{ $statement->periodFrom->toDateString() }} – {{ $statement->periodTo->toDateString() }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-on-surface-subtle">{{ __('رصيد الافتتاح') }}</dt>
                            <dd class="figure text-on-surface">
                                {{ $statement->openingBalance ?? __('غير مذكور') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-on-surface-subtle">{{ __('رصيد الإقفال') }}</dt>
                            <dd class="figure text-on-surface">
                                {{ $statement->closingBalance ?? __('غير مذكور') }}
                            </dd>
                        </div>
                    </dl>

                    <p class="mt-4 text-sm text-on-surface-muted">
                        {{ __(':booked حركة مرحَّلة، :pending معلّقة.', [
                            'booked' => count($statement->bookedLines()),
                            'pending' => count($statement->pendingLines()),
                        ]) }}
                    </p>

                    @if (count($statement->pendingLines()) > 0)
                        <p class="mt-2 text-sm text-on-surface-subtle">
                            {{ __('الحركات المعلّقة ليست ضمن رصيد الإقفال، وتُستورَد موسومة بأنها مبدئية.') }}
                        </p>
                    @endif
                </section>
            @endforeach

            <form method="POST" action="{{ route('banking.import.store', $account) }}" class="mt-6">
                @csrf
                <input type="hidden" name="format" value="{{ $format }}">
                @include('banking.partials.import-submit')
            </form>
        @else
            @if (! empty($warnings))
                {{-- A warning is not an error: the file is readable, and
                     what it says cannot be settled from the file alone. The
                     only safe place to settle it is the table below. --}}
                <div role="alert"
                     class="mt-6 rounded-lg border border-warning bg-warning-subtle px-4 py-3 text-sm text-on-warning-subtle">
                    <h2 class="font-medium">{{ __('يحتاج تأكيدك') }}</h2>
                    <ul class="mt-2 space-y-2">
                        @foreach ($warnings as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('banking.import.remap', $account) }}" class="mt-6">
                @csrf

                <fieldset class="rounded-xl border border-outline bg-surface p-6 shadow-sm">
                    <legend class="px-2 text-base font-medium text-on-surface">{{ __('كيف يُقرأ الملف') }}</legend>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="delimiter" class="block text-sm font-medium text-on-surface">{{ __('الفاصل') }}</label>
                            <select name="delimiter" id="delimiter" aria-describedby="why-delimiter"
                                    class="min-h-(--control-height) mt-2 block w-full rounded-lg border border-outline-control bg-surface-sunken px-3 py-2 text-sm text-on-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                                @foreach ([',' => ',', ';' => ';', 'tab' => __('مسافة جدولة'), '|' => '|'] as $value => $label)
                                    <option value="{{ $value }}"
                                        @selected(($value === 'tab' ? "\t" : $value) === $dialect->delimiter)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p id="why-delimiter" class="mt-2 text-sm text-on-surface-subtle">{{ $evidence['delimiter'] ?? '' }}</p>
                        </div>

                        <div>
                            <label for="decimal_separator" class="block text-sm font-medium text-on-surface">{{ __('الفاصلة العشرية') }}</label>
                            <select name="decimal_separator" id="decimal_separator" aria-describedby="why-decimal"
                                    class="min-h-(--control-height) mt-2 block w-full rounded-lg border border-outline-control bg-surface-sunken px-3 py-2 text-sm text-on-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                                @foreach (['.' => '.', ',' => ','] as $value => $label)
                                    <option value="{{ $value }}" @selected($value === $dialect->decimalSeparator)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p id="why-decimal" class="mt-2 text-sm text-on-surface-subtle">{{ $evidence['decimal_separator'] ?? '' }}</p>
                        </div>

                        <div>
                            <label for="thousands_separator" class="block text-sm font-medium text-on-surface">{{ __('فاصل الآلاف') }}</label>
                            <select name="thousands_separator" id="thousands_separator"
                                    class="min-h-(--control-height) mt-2 block w-full rounded-lg border border-outline-control bg-surface-sunken px-3 py-2 text-sm text-on-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                                @foreach (['' => __('بلا'), ',' => ',', '.' => '.'] as $value => $label)
                                    <option value="{{ $value }}" @selected($value === $dialect->thousandsSeparator)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="date_format" class="block text-sm font-medium text-on-surface">{{ __('صيغة التاريخ') }}</label>
                            <input type="text" name="date_format" id="date_format" value="{{ $dialect->dateFormat }}"
                                   aria-describedby="why-date" dir="ltr"
                                   class="mt-2 block w-full rounded-lg border border-outline-control bg-surface-sunken px-3 py-2 text-sm text-on-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                            <p id="why-date" class="mt-2 text-sm text-on-surface-subtle">{{ $evidence['date_format'] ?? '' }}</p>
                        </div>

                        <div>
                            <label for="sign_convention" class="block text-sm font-medium text-on-surface">{{ __('اتجاه المبالغ') }}</label>
                            <select name="sign_convention" id="sign_convention" aria-describedby="why-sign"
                                    class="min-h-(--control-height) mt-2 block w-full rounded-lg border border-outline-control bg-surface-sunken px-3 py-2 text-sm text-on-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                                <option value="signed" @selected($dialect->signConvention === 'signed')>{{ __('عمود واحد بإشارة') }}</option>
                                <option value="debit_credit_columns" @selected($dialect->signConvention === 'debit_credit_columns')>{{ __('عمودا مدين ودائن') }}</option>
                                <option value="indicator_column" @selected($dialect->signConvention === 'indicator_column')>{{ __('عمود يحدد الاتجاه') }}</option>
                            </select>
                            <p id="why-sign" class="mt-2 text-sm text-on-surface-subtle">{{ $evidence['sign_convention'] ?? '' }}</p>
                        </div>

                        <div class="flex items-end">
                            <label class="flex items-center gap-2 text-sm text-on-surface">
                                <input type="checkbox" name="has_header" value="1" @checked($dialect->hasHeader)
                                       class="rounded border-outline text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                                {{ __('الصف الأول أسماء أعمدة') }}
                            </label>
                        </div>
                    </div>

                    <input type="hidden" name="encoding" value="{{ $dialect->encoding }}">
                </fieldset>

                <fieldset class="mt-6 rounded-xl border border-outline bg-surface p-6 shadow-sm">
                    <legend class="px-2 text-base font-medium text-on-surface">{{ __('أي عمود يقابل ماذا') }}</legend>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($fields as $field => $label)
                            <div>
                                <label for="map-{{ $field }}" class="block text-sm font-medium text-on-surface">{{ $label }}</label>
                                <select name="map[{{ $field }}]" id="map-{{ $field }}"
                                        class="min-h-(--control-height) mt-2 block w-full rounded-lg border border-outline-control bg-surface-sunken px-3 py-2 text-sm text-on-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                                    <option value="">{{ __('لا شيء') }}</option>
                                    @foreach ($preview['header'] as $index => $column)
                                        <option value="{{ $index }}" @selected(($map[$field] ?? null) === $index)>
                                            {{ $column !== '' ? $column : __('عمود :n', ['n' => $index + 1]) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </div>

                    <button type="submit" formaction="{{ route('banking.import.remap', $account) }}"
                            class="mt-6 rounded-lg border border-outline bg-surface-sunken px-4 py-2 text-sm font-medium text-on-surface
                                   hover:bg-surface-hover
                                   focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                        {{ __('حدِّث المعاينة') }}
                    </button>
                </fieldset>

                <section class="mt-6 rounded-xl border border-outline bg-surface p-6 shadow-sm">
                    <h2 class="text-base font-medium text-on-surface">{{ __('أول الصفوف كما ستُقرأ') }}</h2>
                    <p class="mt-2 text-sm text-on-surface-muted">
                        {{ __(':total صف في الملف. قارن العمود الأيمن بالكشف الذي بين يديك.', ['total' => $preview['total_rows']]) }}
                    </p>

                    @if (! empty($preview['problems']))
                        <div role="alert" class="mt-4 rounded-lg border border-danger bg-danger-subtle px-4 py-3 text-sm text-on-danger-subtle">
                            <p class="font-medium">{{ __('صفوف لن تُقرأ، والاستيراد سيتوقف عندها') }}</p>
                            <ul class="mt-2 space-y-1">
                                @foreach (array_slice($preview['problems'], 0, 10) as $problem)
                                    <li>{{ __('الصف :row: :why', ['row' => $problem['row'], 'why' => $problem['why']]) }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-start text-sm">
                            <caption class="sr-only">{{ __('أول الصفوف كما ستُقرأ') }}</caption>
                            <thead>
                                <tr class="border-b border-outline text-on-surface-subtle">
                                    <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('الصف') }}</th>
                                    <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('كما في الملف') }}</th>
                                    <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('كما ستُقرأ') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($preview['rows'] as $row)
                                    <tr class="border-b border-outline-subtle align-top">
                                        <td class="figure py-2 pe-4 text-on-surface-subtle">{{ $row['row'] }}</td>
                                        <td class="py-2 pe-4 text-on-surface-muted" dir="ltr">
                                            {{ implode(' | ', array_map(static fn ($cell) => (string) $cell, $row['raw'])) }}
                                        </td>
                                        <td class="py-2 pe-4 text-on-surface">
                                            <span class="figure">{{ $row['as_read']['booking_date'] }}</span>
                                            <span class="figure font-medium">{{ $row['as_read']['amount'] }}</span>
                                            <span class="figure">{{ $row['as_read']['currency'] }}</span>
                                            @if ($row['as_read']['counterparty_name'])
                                                <span>· {{ $row['as_read']['counterparty_name'] }}</span>
                                            @endif
                                            @if ($row['as_read']['remittance'])
                                                <span class="text-on-surface-muted">· {{ $row['as_read']['remittance'] }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            </form>

            <form method="POST" action="{{ route('banking.import.store', $account) }}" class="mt-6">
                @csrf
                <input type="hidden" name="format" value="csv">
                <input type="hidden" name="delimiter" value="{{ $dialect->delimiter === "\t" ? 'tab' : $dialect->delimiter }}">
                <input type="hidden" name="decimal_separator" value="{{ $dialect->decimalSeparator }}">
                <input type="hidden" name="thousands_separator" value="{{ $dialect->thousandsSeparator }}">
                <input type="hidden" name="date_format" value="{{ $dialect->dateFormat }}">
                <input type="hidden" name="encoding" value="{{ $dialect->encoding }}">
                <input type="hidden" name="sign_convention" value="{{ $dialect->signConvention }}">
                @if ($dialect->hasHeader)
                    <input type="hidden" name="has_header" value="1">
                @endif
                @foreach ($map as $field => $index)
                    <input type="hidden" name="map[{{ $field }}]" value="{{ $index }}">
                @endforeach

                <div class="rounded-xl border border-outline bg-surface p-6 shadow-sm">
                    <label for="save_template_as" class="block text-sm font-medium text-on-surface">
                        {{ __('احفظ هذا التخطيط باسم') }}
                    </label>
                    <input type="text" name="save_template_as" id="save_template_as"
                           value="{{ $template?->name }}"
                           aria-describedby="save-template-help"
                           class="mt-2 block w-full rounded-lg border border-outline-control bg-surface-sunken px-3 py-2 text-sm text-on-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                    <p id="save-template-help" class="mt-2 text-sm text-on-surface-subtle">
                        {{ __('اختياري. التخطيط المحفوظ يجعل استيراد الشهر القادم تأكيدًا بدل إعادة إدخال.') }}
                    </p>

                    <label class="mt-4 flex items-center gap-2 text-sm text-on-surface">
                        <input type="checkbox" name="share_template" value="1"
                               class="rounded border-outline-control text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                        {{ __('أتِح هذا التخطيط لبقية الشركات في المساحة') }}
                    </label>
                </div>

                @include('banking.partials.import-submit')
            </form>
        @endif
    </div>
@endsection
