@extends('layouts.app', ['title' => $bill->supplier_document_number])

{{--
    One supplier bill - PF-133, PF-134, PF-136.

    The narrow measure, not the Overview's wide one: this is a document
    somebody reads top to bottom against the paper in their hand, and a
    1280-pixel line of a supplier's name is harder to read, not easier.

    The order of the page is the order of the questions. What document is
    this, has anybody accepted it, what does it come to, what is on it, how
    was it taxed, and what has already been set against it. The approval sits
    second rather than last on purpose: everything below it is only
    interesting once somebody has checked the document.

    Amounts are gated on `report.view`, the same split the Overview makes.
    What the document IS - its numbers, its dates, its supplier, and whether
    it was approved - is not a figure, and a user who can see the bill exists
    and not whether anybody checked it would be worse off than one who sees
    neither.
--}}

@section('content')
    @php
        $locale = app()->getLocale();

        // The display form of an amount: grouped, at the currency's exponent,
        // and the ACCOUNTING negative - parentheses, never a bare minus.
        //
        // Null-tolerant because `peakflow_document_open_balance` returns NULL
        // for a document type it does not know. A template that printed zero
        // there would say the bill is settled.
        $figure = static function (?string $amount, string $currency) use ($locale): string {
            if ($amount === null) {
                return '—';
            }

            $money = \App\Support\Money\Money::of($amount, $currency);
            $formatted = $money->abs()->format($locale, false);

            return $money->isNegative() ? '('.$formatted.')' : $formatted;
        };

        $currency = (string) $bill->currency;
        $approved = $bill->approved_at !== null;
        $cancelled = $bill->status === 'cancelled';

        $statuses = [
            'draft' => __('مسودة'),
            'awaiting_approval' => __('بانتظار الاعتماد'),
            'approved' => __('معتمدة'),
            'posted' => __('مرحَّلة'),
            'cancelled' => __('ملغاة'),
        ];

        $types = [
            '380' => __('فاتورة مورد'),
            '381' => __('إشعار دائن'),
            '383' => __('إشعار مدين'),
        ];

        $actions = [
            'approval.requested' => __('طُلب الاعتماد'),
            'approval.approved' => __('اعتُمدت'),
            'approval.rejected' => __('رُفضت'),
        ];

        $unknown = __('غير مذكور');

        $openBalance = $figure($bill->open_balance, $currency);

        /*
         | Who approved it: the LAST approval in the chain.
         |
         | A chain can have several steps, and the one that mattered is the
         | one that closed it. Naming the first approver on a document that
         | needed three would credit a decision to somebody who only started
         | it.
         */
        $approvedBy = null;

        foreach ($decisions as $decision) {
            if ($decision->action === 'approval.approved') {
                $approvedBy = $decision->actor_name;
            }
        }
    @endphp

    <a href="{{ route('bills.index') }}"
       class="inline-flex items-center gap-1 rounded-sm text-label text-link hover:underline
              focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
        {{ __('إلى قائمة فواتير الموردين') }}
    </a>

    <x-page-header class="mt-4 items-start" heading-class="figure">
        <x-slot:eyebrow>{{ $types[(string) $bill->document_type] ?? $bill->document_type }}</x-slot:eyebrow>

        <x-slot:title><span dir="ltr">{{ $bill->supplier_document_number }}</span></x-slot:title>

        <x-slot:description>{{ $bill->supplierName() ?? $unknown }}</x-slot:description>

        <x-slot:aside>
            <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center rounded-full bg-surface-container-low px-2.5 py-1 text-caption text-on-surface-muted">
                {{ $statuses[(string) $bill->status] ?? $bill->status }}
            </span>

            {{--
                Marked by the words, a glyph and a fill: forced-colours mode
                keeps the first two.

                A cancelled document gets no chip here at all. Its approval
                state is moot - nobody is going to pay it - and a warning
                raised over something nobody can act on is a warning people
                learn to read past.
            --}}
            @unless ($cancelled)
                @if ($approved)
                    <span class="inline-flex items-center gap-1 rounded-full bg-success-subtle px-2.5 py-1 text-caption font-medium text-on-success-subtle">
                        <span aria-hidden="true">✓</span>{{ __('معتمدة') }}
                    </span>
                @else
                    <span class="inline-flex items-center gap-1 rounded-full bg-warning-subtle px-2.5 py-1 text-caption font-medium text-on-warning-subtle">
                        <span aria-hidden="true">!</span>{{ __('غير معتمدة') }}
                    </span>
                @endif
            @endunless
            </div>
        </x-slot:aside>
    </x-page-header>

    {{--
        The document's identity.

        Both numbers and all three dates, because each pair is routinely
        collapsed into one and the collapse is invisible afterwards.
    --}}
    <section class="mt-8 rounded-card border border-outline bg-surface p-6">
        <h2 class="text-title font-semibold text-on-surface">{{ __('المستند') }}</h2>

        <dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <dt class="text-caption text-on-surface-subtle">{{ __('رقم المستند عند المورد') }}</dt>
                <dd dir="ltr" class="figure mt-0.5 text-label text-on-surface">{{ $bill->supplier_document_number }}</dd>
            </div>

            <div>
                <dt class="text-caption text-on-surface-subtle">{{ __('رقم المقارنة') }}</dt>
                <dd dir="ltr" class="figure mt-0.5 text-label text-on-surface">{{ $bill->supplier_document_key }}</dd>
                <p class="mt-1 text-caption text-on-surface-muted">
                    {{ __('بهذه الصورة يُقارَن الرقم عند البحث عن تكرار: تختلف الكتابة ويبقى المستند واحدًا عند المورد.') }}
                </p>
            </div>

            <div>
                <dt class="text-caption text-on-surface-subtle">{{ __('المورد') }}</dt>
                <dd class="mt-0.5 text-label text-on-surface">{{ $bill->supplierName() ?? $unknown }}</dd>
            </div>

            <div>
                <dt class="text-caption text-on-surface-subtle">{{ __('تاريخ المستند عند المورد') }}</dt>
                <dd dir="ltr" class="figure mt-0.5 text-label text-on-surface">{{ $bill->supplier_document_date?->toDateString() }}</dd>
            </div>

            <div>
                <dt class="text-caption text-on-surface-subtle">{{ __('تاريخ الاستحقاق الضريبي') }}</dt>
                <dd dir="ltr" class="figure mt-0.5 text-label text-on-surface">{{ $bill->tax_point?->toDateString() }}</dd>
                <p class="mt-1 text-caption text-on-surface-muted">
                    {{ __('هو ما يحدد الإقرار الذي تقع فيه ضريبة المدخلات وسعر الصرف المستخدم.') }}
                </p>
            </div>

            <div>
                <dt class="text-caption text-on-surface-subtle">{{ __('تاريخ الترحيل') }}</dt>
                <dd dir="ltr" class="figure mt-0.5 text-label text-on-surface">{{ $bill->posting_date?->toDateString() }}</dd>
                <p class="mt-1 text-caption text-on-surface-muted">
                    {{ __('هو ما يحدد الفترة المحاسبية التي يقع فيها القيد.') }}
                </p>
            </div>

            <div>
                <dt class="text-caption text-on-surface-subtle">{{ __('العملة') }}</dt>
                <dd dir="ltr" class="figure mt-0.5 text-label text-on-surface">{{ $currency }}</dd>
            </div>

            @if ($bill->note)
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-caption text-on-surface-subtle">{{ __('ملاحظة') }}</dt>
                    <dd class="mt-0.5 text-label text-on-surface">{{ $bill->note }}</dd>
                </div>
            @endif
        </dl>
    </section>

    {{--
        The approval record, second on the page.

        Who and when, from the audit trail rather than from a join onto the
        user table: `Purchasing` may not reach `Identity`, and the log
        carries the actor's name denormalised precisely so a screen can name
        a person without the module learning what a user is. It is also the
        same row an investigation would read six months from now.
    --}}
    <section class="mt-6 rounded-card border border-outline bg-surface p-6">
        <h2 class="text-title font-semibold text-on-surface">{{ __('سجل الاعتماد') }}</h2>

        @if ($approved)
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-caption text-on-surface-subtle">{{ __('اعتمدها') }}</dt>
                    <dd class="mt-0.5 text-label text-on-surface">{{ $approvedBy ?? $unknown }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-on-surface-subtle">{{ __('تاريخ الاعتماد') }}</dt>
                    <dd dir="ltr" class="figure mt-0.5 text-label text-on-surface">
                        {{ $bill->approved_at?->toDateTimeString() }}
                    </dd>
                </div>
            </dl>
        @else
            <p class="mt-4 flex items-start gap-3 rounded-card border border-warning bg-warning-subtle p-4 text-label text-on-warning-subtle">
                <span aria-hidden="true" class="font-semibold">!</span>
                <span>{{ __('لم تُعتمد هذه الفاتورة بعد. لا تُسدَّد فاتورة لم يراجعها أحد.') }}</span>
            </p>
        @endif

        @if ($decisions === [])
            <p class="mt-4 text-label text-on-surface-muted">
                {{ __('لا قرارات مسجَّلة لهذا المستند في سجل التدقيق.') }}
            </p>
        @else
            <ol class="mt-4 divide-y divide-outline-subtle">
                @foreach ($decisions as $decision)
                    <li class="flex flex-wrap items-baseline justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="text-label text-on-surface">
                                {{ $actions[(string) $decision->action] ?? $decision->action }}
                            </p>
                            <p class="mt-0.5 text-caption text-on-surface-muted">
                                {{ $decision->actor_name ?? $unknown }}
                            </p>
                            @if ($decision->reason)
                                <p class="mt-1 text-caption text-on-surface-muted">
                                    {{ __('السبب') }}: {{ $decision->reason }}
                                </p>
                            @endif
                        </div>
                        <span dir="ltr" class="figure shrink-0 text-caption text-on-surface-muted">{{ $decision->at }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    @if (! $maySeeFigures)
        {{-- Signed in, in an entity, without `report.view`. --}}
        <div class="mt-6 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
            {{ __('لا تملك صلاحية عرض الأرقام المالية لهذه الشركة.') }}
        </div>
    @else
        <section class="mt-6 rounded-card border border-outline bg-surface p-6">
            <h2 class="text-title font-semibold text-on-surface">{{ __('إجماليات المستند') }}</h2>

            <dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    [__('الإجمالي قبل الضريبة'), (string) $bill->net_amount],
                    [__('الضريبة'), (string) $bill->tax_amount],
                    [__('الإجمالي'), (string) $bill->gross_amount],
                    [__('الضريبة القابلة للخصم'), (string) $bill->recoverable_tax_amount],
                    [__('الضريبة غير القابلة للخصم'), (string) $bill->non_recoverable_tax_amount],
                    [__('المسدَّد'), (string) $bill->paid_amount],
                ] as [$label, $amount])
                    <div>
                        <dt class="text-caption text-on-surface-subtle">{{ $label }}</dt>
                        <dd dir="ltr" class="figure mt-0.5 text-label text-on-surface">
                            {{ $figure($amount, $currency) }}
                            <span class="figure-unit">{{ $currency }}</span>
                        </dd>
                    </div>
                @endforeach
            </dl>

            {{-- The open balance last and larger: it is the one figure on
                 this page somebody is about to act on. --}}
            <div class="mt-6 border-t border-outline-subtle pt-4">
                <p class="text-caption text-on-surface-subtle">{{ __('الرصيد المفتوح') }}</p>
                <x-figure.display :value="$openBalance" :currency="$currency" size="headline" class="mt-1"/>
                <p class="mt-2 text-caption text-on-surface-muted">
                    {{ __('الإجمالي، منقوصًا منه ما خُصِّص عليه من مدفوعات وإشعارات دائنة، وما أعفته فروق المطابقة، وما أُعدم من الدين.') }}
                </p>
            </div>
        </section>

        <section class="mt-6 rounded-card border border-outline bg-surface p-6">
            <h2 class="text-title font-semibold text-on-surface">{{ __('سطور الفاتورة') }}</h2>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-start text-label">
                    <caption class="sr-only">{{ __('سطور الفاتورة') }}</caption>
                    <thead>
                        <tr class="border-b border-outline text-on-surface-subtle">
                            <th scope="col" data-figure class="py-2 pe-4 font-medium">{{ __('الصف') }}</th>
                            <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('البيان') }}</th>
                            <th scope="col" data-figure class="py-2 pe-4 font-medium">{{ __('الكمية') }}</th>
                            <th scope="col" data-figure class="py-2 pe-4 font-medium">{{ __('السعر') }}</th>
                            <th scope="col" data-figure class="py-2 pe-4 font-medium">{{ __('الإجمالي قبل الضريبة') }}</th>
                            <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('فئة الضريبة') }}</th>
                            <th scope="col" data-figure class="py-2 pe-4 font-medium">{{ __('النسبة') }}</th>
                            <th scope="col" data-figure class="py-2 font-medium">{{ __('الضريبة') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bill->lines as $line)
                            <tr class="border-b border-outline-subtle align-top last:border-b-0">
                                <td data-figure dir="ltr" class="py-2 pe-4 text-on-surface-subtle">{{ $line->line_no }}</td>
                                <td class="py-2 pe-4 text-on-surface">
                                    {{ $line->description }}
                                    @if ($line->reverse_charge)
                                        <span class="mt-0.5 block text-caption text-on-surface-muted">{{ __('الاحتساب العكسي') }}</span>
                                    @endif
                                    @if ((string) $line->recoverable_percent !== '100.000000')
                                        <span class="mt-0.5 block text-caption text-on-surface-muted">
                                            {{ __('قابل للخصم :percent٪', ['percent' => rtrim(rtrim((string) $line->recoverable_percent, '0'), '.')]) }}
                                        </span>
                                    @endif
                                </td>
                                <td data-figure dir="ltr" class="py-2 pe-4 text-on-surface">{{ $line->quantity }}</td>
                                <td data-figure dir="ltr" class="py-2 pe-4 text-on-surface">{{ $line->unit_price }}</td>
                                <td data-figure dir="ltr" class="py-2 pe-4 text-on-surface">{{ $figure((string) $line->net_amount, $currency) }}</td>
                                <td class="py-2 pe-4 text-on-surface-muted">{{ $line->tax_category->label($locale) }}</td>
                                <td data-figure dir="ltr" class="py-2 pe-4 text-on-surface-muted">{{ $line->tax_rate }}</td>
                                <td data-figure dir="ltr" class="py-2 text-on-surface">{{ $figure((string) $line->tax_amount, $currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{--
            The tax, grouped by rate rather than summed from the lines.

            Tax summed per line and tax summed per rate group differ by cents
            on any document with a few lines, and the return is filed from
            the group - so the group is what is shown.
        --}}
        <section class="mt-6 rounded-card border border-outline bg-surface p-6">
            <h2 class="text-title font-semibold text-on-surface">{{ __('تفصيل الضريبة بحسب الفئة والنسبة') }}</h2>

            @if ($bill->taxBreakdown->isEmpty())
                <p class="mt-4 text-label text-on-surface-muted">{{ __('لا تفصيل ضريبي على هذا المستند.') }}</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-start text-label">
                        <caption class="sr-only">{{ __('تفصيل الضريبة بحسب الفئة والنسبة') }}</caption>
                        <thead>
                            <tr class="border-b border-outline text-on-surface-subtle">
                                <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('فئة الضريبة') }}</th>
                                <th scope="col" data-figure class="py-2 pe-4 font-medium">{{ __('النسبة') }}</th>
                                <th scope="col" data-figure class="py-2 pe-4 font-medium">{{ __('الوعاء الضريبي') }}</th>
                                <th scope="col" data-figure class="py-2 pe-4 font-medium">{{ __('الضريبة') }}</th>
                                <th scope="col" data-figure class="py-2 pe-4 font-medium">{{ __('القابل للخصم') }}</th>
                                <th scope="col" class="py-2 text-start font-medium">{{ __('خانة الإقرار') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bill->taxBreakdown as $group)
                                <tr class="border-b border-outline-subtle last:border-b-0">
                                    <td class="py-2 pe-4 text-on-surface">
                                        {{ $group->category->label($locale) }}
                                        @if ($group->reverse_charge)
                                            <span class="mt-0.5 block text-caption text-on-surface-muted">{{ __('الاحتساب العكسي') }}</span>
                                        @endif
                                    </td>
                                    <td data-figure dir="ltr" class="py-2 pe-4 text-on-surface-muted">{{ $group->rate }}</td>
                                    <td data-figure dir="ltr" class="py-2 pe-4 text-on-surface">{{ $figure((string) $group->taxable_amount, $currency) }}</td>
                                    <td data-figure dir="ltr" class="py-2 pe-4 text-on-surface">{{ $figure((string) $group->tax_amount, $currency) }}</td>
                                    <td data-figure dir="ltr" class="py-2 pe-4 text-on-surface">{{ $figure((string) $group->recoverable_amount, $currency) }}</td>
                                    <td class="py-2 text-on-surface-muted">{{ $group->report_box ?? $unknown }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{--
            What has been set against the bill.

            The allocations of a supplier credit note, which post NOTHING:
            both documents sit in `accounts_payable` against the same party,
            so netting them changes no balance anywhere. The row is what
            makes the open-item statement right, which is why it is shown
            here beside the credit notes that name this bill by reference -
            the two reach the same place by different routes and somebody
            reconciling the supplier's statement needs to see both.
        --}}
        <section class="mt-6 rounded-card border border-outline bg-surface p-6">
            <h2 class="text-title font-semibold text-on-surface">{{ __('ما خُصم من هذه الفاتورة') }}</h2>

            @if ($bill->creditAllocations->isEmpty() && $bill->creditNotes->isEmpty())
                <p class="mt-4 text-label text-on-surface-muted">{{ __('لا تخصيصات على هذه الفاتورة.') }}</p>
            @endif

            @if ($bill->creditAllocations->isNotEmpty())
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-start text-label">
                        <caption class="sr-only">{{ __('ما خُصم من هذه الفاتورة') }}</caption>
                        <thead>
                            <tr class="border-b border-outline text-on-surface-subtle">
                                <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('إشعار دائن') }}</th>
                                <th scope="col" data-figure class="py-2 pe-4 font-medium">{{ __('المبلغ') }}</th>
                                <th scope="col" data-figure class="py-2 pe-4 font-medium">{{ __('تاريخ التخصيص') }}</th>
                                <th scope="col" class="py-2 text-start font-medium">{{ __('الملاحظة') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bill->creditAllocations as $allocation)
                                <tr class="border-b border-outline-subtle last:border-b-0">
                                    <td dir="ltr" class="figure py-2 pe-4 text-on-surface">
                                        {{ $allocation->creditNote?->supplier_document_number ?? $unknown }}
                                    </td>
                                    <td data-figure dir="ltr" class="py-2 pe-4 text-on-surface">
                                        {{ $figure((string) $allocation->amount, (string) $allocation->currency) }}
                                        <span class="figure-unit">{{ $allocation->currency }}</span>
                                    </td>
                                    <td data-figure dir="ltr" class="py-2 pe-4 text-on-surface-muted">
                                        {{ $allocation->allocated_at?->toDateString() }}
                                    </td>
                                    <td class="py-2 text-on-surface-muted">{{ $allocation->note }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($bill->creditNotes->isNotEmpty())
                <h3 class="mt-6 text-label font-medium text-on-surface">{{ __('إشعارات دائنة تشير إلى هذه الفاتورة') }}</h3>

                <ul class="mt-2 divide-y divide-outline-subtle">
                    @foreach ($bill->creditNotes as $note)
                        <li class="flex flex-wrap items-baseline justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
                            <span dir="ltr" class="figure text-label text-on-surface">{{ $note->supplier_document_number }}</span>
                            <span class="text-caption text-on-surface-muted">
                                {{ $statuses[(string) $note->status] ?? $note->status }}
                            </span>
                            <span dir="ltr" class="figure text-label text-on-surface">
                                {{ $figure((string) $note->gross_amount, (string) $note->currency) }}
                                <span class="figure-unit">{{ $note->currency }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <p class="mt-4 text-caption text-on-surface-muted">
                {{ __('المدفوعات لا تُسرد هنا: السداد شأن وحدة السداد، والرصيد المفتوح أعلاه يَحسِبها أصلًا.') }}
            </p>
        </section>
    @endif
@endsection
