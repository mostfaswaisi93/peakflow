@extends('layouts.app', ['title' => $invoice->number ?? __('مسودة بلا رقم'), 'wide' => true])

{{--
    One invoice.

    The page is the document, in the order somebody asks about it: who it is
    for and when it is due, what it came to, what it was made of, what tax it
    carried, and what has been paid against it.

    Every amount here is read as a string and formatted through Money. The
    open balance is the DATABASE's answer, selected in the controller - this
    page never subtracts payments from a total, because a second arithmetic
    for "what is still owed" is a second answer to the question the ledger
    already answers.

    The names and the tax registration come from the SNAPSHOT frozen at issue,
    through the model's accessors. A reprint has to show what was issued, and
    the easiest way to lose that is a template that reads the live party
    because the snapshot looked like a cache.
--}}

@section('content')
    @php
        $locale = app()->getLocale();
        $today = today()->toDateString();
        $currency = $invoice->currency;

        // The display form of an amount: grouped, at the currency's exponent,
        // and the ACCOUNTING negative - parentheses, never a bare minus.
        $figure = static function (string $amount, string $currency) use ($locale): string {
            $money = \App\Support\Money\Money::of($amount, $currency);
            $formatted = $money->abs()->format($locale, false);

            return $money->isNegative() ? '('.$formatted.')' : $formatted;
        };

        /*
         | Quantity, unit price and tax rate are NOT money.
         |
         | A quantity and a unit price are held at eight decimals so that
         | per-litre, per-minute and per-thousand pricing needs no scaling
         | factor, and Money is held at four and REFUSES a fifth rather than
         | rounding it. So they are shown as stored, with only the trailing
         | zeros that carry no information trimmed - a string operation, never
         | a division.
         */
        $plain = static fn (string $value): string => str_contains($value, '.')
            ? rtrim(rtrim($value, '0'), '.')
            : $value;

        /*
         | THE due date of a schedule is the LAST instalment's - EN 16931
         | BT-9.
         |
         | Showing the first would tell a reader the whole amount falls due
         | then, and that is the date their ageing report would use.
         */
        $schedule = $invoice->dueDates();
        $last = end($schedule);
        $due = \is_array($last) && \is_string($last['date'] ?? null) ? $last['date'] : null;

        $overdue = $mayReadAmounts
            && $invoice->isIssued()
            && $openBalance !== null
            && \App\Support\Money\Money::of($openBalance, $currency)->isPositive()
            && $due !== null
            && $due < $today;

        /*
         | Component attributes carry plain variables only.
         |
         | The template-text rule reads tags with a plain parser: an `->`
         | inside an attribute ends the tag as far as it is concerned, so
         | everything a tile's heading needs is computed here.
         */
        $heading = [
            'document' => __('المستند'),
            'dates' => __('التواريخ'),
            'amounts' => __('المبالغ'),
        ];

        $grossDisplay = $mayReadAmounts ? $figure($invoice->gross_amount, $currency) : '';
    @endphp

    <div>
        <a href="{{ route('invoices.index') }}"
           class="text-label text-link underline-offset-2 hover:underline
                  focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
            {{ __('العودة إلى الفواتير') }}
        </a>
    </div>

    {{-- The figure face is for the NUMBER. A draft's placeholder is a
         sentence, and setting Arabic in the figure face would pick the
         fallback rather than the UI face it is matched against. --}}
    <x-page-header class="mt-3"
                   :title="$invoice->number ?? __('مسودة بلا رقم')"
                   :heading-class="$invoice->number !== null ? 'figure' : null">
        <x-slot:description>
            {{ $invoice->document_type->label() }} · {{ $invoice->customerName() }}
        </x-slot:description>

        <x-slot:aside>
            <div class="flex items-center gap-2">
            @if ($invoice->isDraft())
                <span class="inline-flex items-center gap-1 rounded-full bg-surface-container-high px-2.5 py-1 text-caption font-medium text-on-surface-muted">
                    <span aria-hidden="true">●</span>{{ __('مسودة') }}
                </span>
            @elseif ($overdue)
                <span class="inline-flex items-center gap-1 rounded-full bg-warning-subtle px-2.5 py-1 text-caption font-medium text-on-warning-subtle">
                    <span aria-hidden="true">!</span>{{ __('متأخرة') }}
                </span>
            @else
                <span class="inline-flex items-center gap-1 rounded-full bg-success-subtle px-2.5 py-1 text-caption font-medium text-on-success-subtle">
                    <span aria-hidden="true">✓</span>{{ __('مُصدرة') }}
                </span>
            @endif
            </div>
        </x-slot:aside>
    </x-page-header>

    @if ($invoice->isDraft())
        {{--
            A draft is marked with the WORD, on screen and on print.

            Nothing on it has been numbered, hashed or posted, and a reader
            who mistakes one for an issued document will chase a journal entry
            that was never written.
        --}}
        <p class="mt-4 rounded-card border-s-2 border-draft-stripe bg-surface-container-low px-4 py-3 text-label text-on-surface-muted">
            {{ __('مسودة: لم تُصدَر هذه الفاتورة ولم تُرحَّل إلى الدفاتر.') }}
        </p>
    @endif

    @if (! $mayReadAmounts)
        {{-- In the entity, on the right document, without `report.view`. --}}
        <div class="mt-8 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
            {{ __('لا تملك صلاحية عرض الأرقام المالية لهذه الشركة.') }}
        </div>
    @else
        <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-6">
            <x-bento.tile :heading="$heading['document']" class="md:col-span-3">
                <dl class="space-y-3 text-label">
                    <div>
                        <dt class="text-on-surface-muted">{{ __('العميل') }}</dt>
                        <dd class="mt-0.5 text-on-surface">{{ $invoice->customerName() }}</dd>
                    </div>
                    <div>
                        <dt class="text-on-surface-muted">{{ __('الرقم الضريبي للعميل') }}</dt>
                        <dd dir="ltr" class="figure mt-0.5 text-on-surface">
                            {{ $invoice->customerRegistration() ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-on-surface-muted">{{ __('نوع المستند') }}</dt>
                        <dd class="mt-0.5 text-on-surface">{{ $invoice->document_type->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-on-surface-muted">{{ __('العملة') }}</dt>
                        <dd dir="ltr" class="figure mt-0.5 text-on-surface">{{ $currency }}</dd>
                    </div>
                </dl>
            </x-bento.tile>

            <x-bento.tile :heading="$heading['dates']" class="md:col-span-3">
                <dl class="space-y-3 text-label">
                    <div>
                        <dt class="text-on-surface-muted">{{ __('تاريخ الإصدار') }}</dt>
                        <dd class="mt-0.5">
                            <time datetime="{{ $invoice->issue_date?->toDateString() }}" class="figure text-on-surface">
                                {{ $invoice->issue_date?->toDateString() }}
                            </time>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-on-surface-muted">{{ __('تاريخ الاستحقاق') }}</dt>
                        <dd class="mt-0.5">
                            @if ($due !== null)
                                <time datetime="{{ $due }}" class="figure text-on-surface">{{ $due }}</time>
                            @else
                                <span class="text-on-surface-subtle">—</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-on-surface-muted">{{ __('تاريخ الاستحقاق الضريبي') }}</dt>
                        <dd class="mt-0.5">
                            @if ($invoice->tax_point !== null)
                                <time datetime="{{ $invoice->tax_point->toDateString() }}" class="figure text-on-surface">
                                    {{ $invoice->tax_point->toDateString() }}
                                </time>
                            @else
                                <span class="text-on-surface-subtle">—</span>
                            @endif
                        </dd>
                    </div>
                </dl>

                @if (count($schedule) > 1)
                    <p class="mt-4 text-caption text-on-surface-muted">
                        {{ __('هذه الفاتورة على أقساط، والمعروض تاريخ آخر قسط - وهو تاريخ الاستحقاق في EN 16931.') }}
                    </p>
                @endif
            </x-bento.tile>

            <x-bento.tile :heading="$heading['amounts']" class="md:col-span-6">
                <x-figure.display :value="$grossDisplay" :currency="$currency" size="display"/>
                <p class="mt-1 text-caption text-on-surface-muted">{{ __('الإجمالي') }}</p>

                <dl class="mt-5 grid grid-cols-2 gap-4 text-label sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ([
                        [__('الصافي'), $figure($invoice->net_amount, $currency)],
                        [__('الضريبة'), $figure($invoice->tax_amount, $currency)],
                        [__('المدفوع مقدمًا'), $figure($invoice->prepaid_amount, $currency)],
                        [__('المطلوب'), $figure($invoice->payable_amount, $currency)],
                    ] as [$label, $amount])
                        <div>
                            <dt class="text-on-surface-muted">{{ $label }}</dt>
                            <dd dir="ltr" class="figure mt-0.5 font-medium text-on-surface">{{ $amount }}</dd>
                        </div>
                    @endforeach

                    {{--
                        A draft has no open balance, and the function cannot say
                        so: it answers "gross, less what has relieved it", and
                        nothing has relieved a document the customer has never
                        seen. Printing its gross here would read as an unpaid
                        debt.
                    --}}
                    <div>
                        <dt class="text-on-surface-muted">{{ __('الرصيد المفتوح') }}</dt>
                        <dd dir="ltr" class="figure mt-0.5 font-semibold text-on-surface">
                            @if ($invoice->isDraft() || $openBalance === null)
                                <span class="font-normal text-on-surface-subtle">—</span>
                            @else
                                {{ $figure($openBalance, $currency) }}
                            @endif
                        </dd>
                    </div>
                </dl>

                <p class="mt-4 text-caption text-on-surface-muted">
                    {{ __('الرصيد المفتوح هو الإجمالي بعد خصم الإشعارات الدائنة والدفعات المخصَّصة وفروق المطابقة والمبالغ المشطوبة.') }}
                </p>
            </x-bento.tile>
        </div>

        <section class="mt-8">
            <h2 class="text-title font-semibold text-on-surface">{{ __('السطور') }}</h2>

            @if ($invoice->lines->isEmpty())
                <p class="mt-3 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
                    {{ __('لا سطور على هذه الفاتورة.') }}
                </p>
            @else
                <div class="mt-3 overflow-x-auto rounded-card border border-outline bg-surface">
                    <table class="w-full text-start text-label">
                        <caption class="sr-only">{{ __('السطور') }}</caption>
                        <thead>
                            <tr class="border-b border-outline text-on-surface-muted">
                                <th scope="col" data-figure class="px-3 py-2.5 font-medium">{{ __('السطر') }}</th>
                                <th scope="col" class="px-3 py-2.5 text-start font-medium">{{ __('الوصف') }}</th>
                                <th scope="col" data-figure class="px-3 py-2.5 font-medium">{{ __('الكمية') }}</th>
                                <th scope="col" data-figure class="px-3 py-2.5 font-medium">{{ __('سعر الوحدة') }}</th>
                                <th scope="col" class="px-3 py-2.5 text-start font-medium">{{ __('فئة الضريبة') }}</th>
                                <th scope="col" data-figure class="px-3 py-2.5 font-medium">{{ __('النسبة') }}</th>
                                <th scope="col" data-figure class="px-3 py-2.5 font-medium">{{ __('الصافي') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoice->lines as $line)
                                <tr class="border-b border-outline-subtle align-top last:border-0">
                                    <td class="figure px-3 py-2.5 text-on-surface-subtle">{{ $line->line_no }}</td>
                                    <td class="px-3 py-2.5 text-on-surface">
                                        {{ $line->description }}
                                        @if ($line->item_code !== null)
                                            <span dir="ltr" class="mt-0.5 block font-mono text-caption text-on-surface-muted">
                                                {{ $line->item_code }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="figure px-3 py-2.5 text-on-surface">
                                        {{ $plain((string) $line->quantity) }}
                                        <span class="text-caption font-normal text-on-surface-muted">{{ $line->unit_code }}</span>
                                    </td>
                                    <td class="figure px-3 py-2.5 text-on-surface">{{ $plain((string) $line->unit_price) }}</td>
                                    <td class="px-3 py-2.5 text-on-surface">{{ $line->tax_category->label() }}</td>
                                    <td class="figure px-3 py-2.5 text-on-surface">{{ $plain((string) $line->tax_rate) }}%</td>
                                    <td class="figure px-3 py-2.5 font-medium text-on-surface">
                                        {{ $figure($line->net_amount, $currency) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="mt-8">
            <h2 class="text-title font-semibold text-on-surface">{{ __('توزيع الضريبة') }}</h2>

            @if ($taxBreakdown->isEmpty())
                <p class="mt-3 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
                    {{ __('لا توزيع ضريبي محفوظ لهذه الفاتورة.') }}
                </p>
            @else
                <div class="mt-3 overflow-x-auto rounded-card border border-outline bg-surface">
                    <table class="w-full text-start text-label">
                        <caption class="sr-only">{{ __('توزيع الضريبة') }}</caption>
                        <thead>
                            <tr class="border-b border-outline text-on-surface-muted">
                                <th scope="col" class="px-3 py-2.5 text-start font-medium">{{ __('فئة الضريبة') }}</th>
                                <th scope="col" data-figure class="px-3 py-2.5 font-medium">{{ __('النسبة') }}</th>
                                <th scope="col" data-figure class="px-3 py-2.5 font-medium">{{ __('الوعاء الضريبي') }}</th>
                                <th scope="col" data-figure class="px-3 py-2.5 font-medium">{{ __('الضريبة') }}</th>
                                <th scope="col" class="px-3 py-2.5 text-start font-medium">{{ __('سبب الإعفاء') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($taxBreakdown as $row)
                                <tr class="border-b border-outline-subtle align-top last:border-0">
                                    <td class="px-3 py-2.5 text-on-surface">{{ $row->category->label() }}</td>
                                    <td class="figure px-3 py-2.5 text-on-surface">{{ $plain((string) $row->rate) }}%</td>
                                    <td class="figure px-3 py-2.5 text-on-surface">{{ $figure($row->taxable_amount, $currency) }}</td>
                                    <td class="figure px-3 py-2.5 font-medium text-on-surface">{{ $figure($row->tax_amount, $currency) }}</td>
                                    <td class="px-3 py-2.5 text-on-surface-muted">
                                        {{ $row->exemption_reason_text ?? $row->exemption_reason_code ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{--
         | The place of supply, and why - PF-078.
         |
         | The criterion is that an accountant must be able to CHECK the
         | decision, which means the winning rule and what it cites, not a
         | country. Three things are on the panel for that reason: the rule's
         | id and its precedence, so the rule can be found in the book; the
         | legal source it quotes, which is what an authority's query will be
         | about; and whether the customer accounts for the tax, because that
         | is the difference between a zero in the tax column being right and
         | being an unpaid liability.
         |
         | Read from the FROZEN snapshot, never recomputed. The party's
         | registration will have lapsed, the rule book will have been
         | amended and the bloc will have gained or lost a member by the time
         | anybody asks - and the question is what the rule was then.
         |
         | An undecided line says so, in words, with the reason. It used to
         | say `domestic` for every document ever issued, because nothing
         | asked the rule book at all.
        --}}
        @php
            $place = $invoice->tax_decision_snapshot['place_of_supply'] ?? null;
            $placeLines = is_array($place) ? ($place['lines'] ?? []) : [];
        @endphp

        <section class="mt-8">
            <h2 class="text-title font-semibold text-on-surface">{{ __('مكان التوريد') }}</h2>

            @if (! is_array($place) || $placeLines === [])
                <p class="mt-3 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
                    {{ __('لا قرار محفوظ لمكان التوريد على هذه الفاتورة.') }}
                    @if (is_array($place) && is_string($place['why_ar'] ?? null))
                        {{ $place['why_ar'] }}
                    @endif
                </p>
            @else
                @if (($place['mixed'] ?? false) === true)
                    <p class="mt-3 text-label text-on-surface-muted">
                        {{ __('سطور هذه الفاتورة لا تخضع لقاعدة واحدة، وكل سطر مذكور بقاعدته.') }}
                    </p>
                @endif

                <div class="mt-3 flow-tight">
                    @foreach ($placeLines as $decision)
                        <article class="rounded-card border border-outline bg-surface p-6">
                            <header class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <h3 class="text-label font-medium text-on-surface">
                                    {{ __('سطر') }} <span class="figure">{{ $decision['line_no'] ?? '—' }}</span>
                                </h3>

                                @if (($decision['decided'] ?? false) === true)
                                    <p class="text-label text-on-surface">
                                        {{ app()->getLocale() === 'ar'
                                            ? ($decision['explanation_ar'] ?? '')
                                            : ($decision['explanation_en'] ?? '') }}
                                    </p>
                                @else
                                    <p class="text-label text-on-surface-muted">
                                        {{ __('لم يُحدَّد:') }}
                                        {{ app()->getLocale() === 'ar'
                                            ? ($decision['why_ar'] ?? '')
                                            : ($decision['why'] ?? '') }}
                                    </p>
                                @endif
                            </header>

                            @if (($decision['decided'] ?? false) === true)
                                <dl class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                                    <div>
                                        <dt class="text-label text-on-surface-muted">{{ __('الدولة') }}</dt>
                                        <dd class="figure text-on-surface">{{ $decision['country'] ?? '—' }}</dd>
                                    </div>

                                    <div>
                                        <dt class="text-label text-on-surface-muted">{{ __('القاعدة') }}</dt>
                                        <dd class="text-on-surface">
                                            {{ $decision['rule'] ?? '—' }}
                                            <span class="text-on-surface-muted">
                                                ({{ __('الأولوية') }}
                                                <span class="figure">{{ $decision['precedence'] ?? '—' }}</span>)
                                            </span>
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-label text-on-surface-muted">{{ __('الفئة') }}</dt>
                                        <dd class="text-on-surface">{{ $decision['category'] ?? '—' }}</dd>
                                    </div>

                                    <div>
                                        <dt class="text-label text-on-surface-muted">{{ __('من يحتسب الضريبة') }}</dt>
                                        <dd class="text-on-surface">
                                            {{ ($decision['reverse_charge'] ?? false) === true
                                                ? __('العميل، بالتحصيل العكسي')
                                                : __('المورّد') }}
                                        </dd>
                                    </div>

                                    @if (($decision['evidence_required'] ?? []) !== [])
                                        <div class="sm:col-span-2">
                                            <dt class="text-label text-on-surface-muted">{{ __('الإثبات المطلوب') }}</dt>
                                            <dd class="text-on-surface">
                                                {{ implode('، ', $decision['evidence_required']) }}
                                            </dd>
                                        </div>
                                    @endif

                                    <div class="sm:col-span-2">
                                        <dt class="text-label text-on-surface-muted">{{ __('المصدر القانوني') }}</dt>
                                        <dd class="text-on-surface">{{ $decision['source'] ?? '—' }}</dd>
                                    </div>
                                </dl>
                            @endif
                        </article>
                    @endforeach
                </div>

                <p class="mt-3 text-label text-on-surface-muted">
                    {{ __('القرار مثبّت على المستند بتاريخ') }}
                    <time datetime="{{ $place['decided_on'] ?? '' }}" class="figure">{{ $place['decided_on'] ?? '—' }}</time>{{ __('، وصفة العميل آنذاك') }}
                    {{ ($place['customer_status'] ?? '') === 'business' ? __('منشأة مسجّلة') : __('مستهلك') }}@if (is_string($place['delivery_country'] ?? null)){{ __('، ومكان التسليم') }} <span class="figure">{{ $place['delivery_country'] }}</span>@endif{{ __('. لا يُعاد حسابه: سجلات الأطراف وكتاب القواعد يتغيّران، والسؤال هو ما كانت القاعدة يومها.') }}
                </p>
            @endif
        </section>

        <section class="mt-8">
            <h2 class="text-title font-semibold text-on-surface">{{ __('الدفعات') }}</h2>

            @if ($allocations === [])
                <p class="mt-3 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
                    {{ __('لا دفعات مخصَّصة على هذه الفاتورة بعد.') }}
                </p>
            @else
                <div class="mt-3 overflow-x-auto rounded-card border border-outline bg-surface">
                    <table class="w-full text-start text-label">
                        <caption class="sr-only">{{ __('الدفعات') }}</caption>
                        <thead>
                            <tr class="border-b border-outline text-on-surface-muted">
                                <th scope="col" class="px-3 py-2.5 text-start font-medium">{{ __('الدفعة') }}</th>
                                <th scope="col" class="px-3 py-2.5 text-start font-medium">{{ __('تاريخ القيمة') }}</th>
                                <th scope="col" class="px-3 py-2.5 text-start font-medium">{{ __('تاريخ التخصيص') }}</th>
                                <th scope="col" data-figure class="px-3 py-2.5 font-medium">{{ __('المبلغ') }}</th>
                                <th scope="col" class="px-3 py-2.5 text-start font-medium">{{ __('الحالة') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($allocations as $allocation)
                                <tr class="border-b border-outline-subtle last:border-0">
                                    <td class="figure px-3 py-2.5 text-on-surface">{{ $allocation->payment_number }}</td>
                                    <td class="px-3 py-2.5">
                                        <time datetime="{{ $allocation->value_date }}" class="figure text-on-surface">
                                            {{ $allocation->value_date }}
                                        </time>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <time datetime="{{ $allocation->allocated_on }}" class="figure text-on-surface">
                                            {{ $allocation->allocated_on }}
                                        </time>
                                    </td>
                                    <td class="figure px-3 py-2.5 font-medium text-on-surface">
                                        {{ $figure((string) $allocation->amount, (string) $allocation->currency) }}
                                        <span class="text-caption font-normal text-on-surface-muted">{{ $allocation->currency }}</span>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        {{--
                                            A reversed allocation is listed with the
                                            word, not removed. A table that deleted
                                            could not answer "it was against this
                                            invoice and somebody changed it", which
                                            is the question a disputed statement
                                            opens with. It is outside the open
                                            balance either way.
                                        --}}
                                        @if ($allocation->reversed_at !== null)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-surface-container-high px-2 py-0.5 text-caption font-medium text-on-surface-muted">
                                                <span aria-hidden="true">↺</span>{{ __('مُلغاة') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-success-subtle px-2 py-0.5 text-caption font-medium text-on-success-subtle">
                                                <span aria-hidden="true">✓</span>{{ __('مخصَّصة') }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if ($invoice->note !== null)
            <section class="mt-8 rounded-card border border-outline bg-surface p-6">
                <h2 class="text-label font-medium text-on-surface-muted">{{ __('ملاحظة') }}</h2>
                <p class="mt-2 text-body text-on-surface">{{ $invoice->note }}</p>
            </section>
        @endif
    @endif
@endsection
