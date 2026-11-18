@extends('layouts.app', ['title' => __('نظرة عامة'), 'wide' => true])

{{--
    The Overview - UX-032: Bento + Data-driven + Editorial.

    A 12-column grid on a wide screen, 6 on a medium one, one column on a
    phone; the grid flows in the page's direction, so RTL needs no rule of its
    own. Each tile is one question with one figure as its answer: the figure
    is the hero, the unit and the decimals are quieter, and a trend is an
    arrow AND words, never a colour.

    Three states, kept apart on purpose: no permission to see figures, an
    entity where nothing has happened yet (a welcome, not a grid of zeros that
    reads as "you have no money"), and the grid.

    Every figure here comes from OverviewQuery in a fixed number of queries,
    and `php bin/verify reporting` holds both the count and the figures.
--}}

@section('content')
    @php
        $locale = app()->getLocale();

        // The display form of an amount: grouped, at the currency's exponent,
        // and the ACCOUNTING negative - parentheses, never a bare minus.
        $figure = static function (string $amount, string $currency) use ($locale): string {
            $money = \App\Support\Money\Money::of($amount, $currency);
            $formatted = $money->abs()->format($locale, false);

            return $money->isNegative() ? '('.$formatted.')' : $formatted;
        };

        $buckets = [
            'current' => __('غير مستحقة بعد'),
            '1-30' => __('1–30 يومًا'),
            '31-60' => __('31–60 يومًا'),
            '61-90' => __('61–90 يومًا'),
            '90+' => __('أكثر من 90 يومًا'),
        ];
    @endphp

    <x-page-header :title="__('نظرة عامة')">
        <x-slot:description>
            {{ __('أنت تعمل الآن في :entity.', ['entity' => $entity?->legal_name]) }}
        </x-slot:description>
        <x-slot:aside>
            @if ($entity)
                <p class="inline-flex items-center gap-2 rounded-full border border-outline bg-surface px-3 py-1 text-caption text-on-surface-muted">
                    {{ __('العملة الوظيفية') }}
                    <span class="figure font-semibold text-on-surface">{{ $entity->functional_currency }}</span>
                </p>
            @endif
        </x-slot:aside>
    </x-page-header>

    @if ($overview === null)
        {{-- Signed in, in an entity, without `report.view`. --}}
        <div class="mt-8 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
            {{ __('لا تملك صلاحية عرض الأرقام المالية لهذه الشركة.') }}
        </div>
    @elseif ($overview->isEmpty())
        {{-- Nothing has happened yet: the welcome. --}}
        <section class="aurora relative mt-8 overflow-hidden rounded-tile border border-outline p-8 sm:p-12">
            <svg aria-hidden="true" focusable="false" viewBox="0 0 200 200"
                 class="pointer-events-none absolute -top-16 -end-16 size-72 text-accent opacity-20">
                <path fill="currentColor" transform="translate(100 100)"
                      d="M43.6-58.4C56.3-50.8 66.1-37.4 70.9-22.4 75.7-7.4 75.4 9.2 69.3 23 63.3 36.8 51.4 47.8 37.9 56.2 24.4 64.6 9.2 70.4-6.6 71.9-22.4 73.3-38.8 70.4-51.3 61.4-63.8 52.4-72.4 37.3-75.4 21.2-78.4 5.1-75.8-12-68.3-26.2-60.8-40.4-48.3-51.6-34.6-58.7-20.9-65.9-6-69 8.6-70.6 23.2-72.2 30.9-66 43.6-58.4Z"/>
            </svg>
            <div class="glass relative max-w-xl rounded-card p-6 sm:p-8">
                <x-brand.logo size="mark" decorative class="mb-5 h-10"/>
                <h2 class="text-headline font-bold text-on-surface">{{ __('مرحبًا بك في :app', ['app' => __('بيك فلو')]) }}</h2>
                <p class="mt-3 text-body text-on-surface-muted">
                    {{ __('لا توجد حركات لهذه الشركة بعد. حين تبدأ القيود والفواتير وكشوف البنك بالوصول، تمتلئ هذه الصفحة بالمركز النقدي والذمم والمطابقة.') }}
                </p>
            </div>
        </section>
    @else
        @php
            // Everything a component attribute needs is computed HERE. The
            // template-text rule reads tags with a plain parser: an `->` inside
            // an attribute ends the tag for it, and an untranslated-looking
            // `title=` is refused - so attributes carry plain variables only.
            $cash = $overview->cash;
            $change = $cash->changePercent();
            $currency = $overview->currency;
            $reconciliation = $overview->reconciliation;

            $heading = [
                'cash' => __('النقد في البنوك'),
                'receivables' => __('الذمم المدينة'),
                'payables' => __('الذمم الدائنة'),
                'reconciliation' => __('المطابقة البنكية'),
                'drafts' => __('مسودات غير مرحَّلة'),
                'tax' => __('الفترة الضريبية'),
                'activity' => __('النشاط الأخير'),
            ];

            $cashBalance = $figure($cash->balance, $currency);
            $cashDirection = $cash->direction();
            $cashSeries = $cash->series;
            $cashLabel = __('رصيد البنوك في الأشهر الثلاثة الأخيرة، من :from إلى :to', [
                'from' => $figure($cash->series[0] ?? '0', $currency),
                'to' => $cashBalance,
            ]);
            $receivablesTotal = $figure($overview->receivables->total(), $currency);
            $payablesTotal = $figure($overview->payables->total, $currency);
        @endphp

        <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-6 xl:grid-cols-12">
            {{-- Cash at bank: the hero tile. --}}
            <x-bento.tile :heading="$heading['cash']" class="md:col-span-6 xl:col-span-6 xl:row-span-2">
                <x-figure.display :value="$cashBalance" :currency="$currency" size="display-xl"/>

                @if ($change !== null)
                    <x-trend :direction="$cashDirection" class="mt-2">
                        <span dir="ltr" class="figure">{{ (str_starts_with($change, '-') ? '−' : '+').ltrim($change, '-') }}%</span>
                        {{ __('مقارنةً بالشهر الماضي') }}
                    </x-trend>
                @endif

                <div class="mt-auto pt-6" dir="ltr">
                    <x-sparkline :points="$cashSeries" :label="$cashLabel" area class="h-24"/>
                </div>
                <p class="mt-3 text-caption text-on-surface-muted">{{ __('حساب البنك وما يتفرع منه في دليل الحسابات.') }}</p>
            </x-bento.tile>

            {{-- Receivables, aged. --}}
            <x-bento.tile :heading="$heading['receivables']" class="md:col-span-3 xl:col-span-3">
                <x-figure.display :value="$receivablesTotal" :currency="$currency" size="headline"/>
                <p class="mt-1 text-caption text-on-surface-muted">
                    {{ \App\Support\Lang\Message::of('overview.open_documents', ['count' => $overview->receivables->documents]) }}
                </p>

                <dl class="mt-4 space-y-2">
                    @foreach ($overview->receivables->shares() as $bucket => $share)
                        <div>
                            <div class="flex items-baseline justify-between gap-3 text-caption">
                                <dt class="text-on-surface-muted">{{ $buckets[$bucket] }}</dt>
                                <dd dir="ltr" class="figure text-on-surface">{{ $figure($overview->receivables->buckets[$bucket], $currency) }}</dd>
                            </div>
                            <div aria-hidden="true" class="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-container-high">
                                <div class="h-full rounded-full bg-primary" style="inline-size: {{ $share }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </dl>

                @if ($overview->receivables->otherCurrencyDocuments > 0)
                    <p class="mt-3 text-caption text-on-surface-muted">
                        {{ \App\Support\Lang\Message::of('overview.other_currencies', ['count' => $overview->receivables->otherCurrencyDocuments]) }}
                    </p>
                @endif
            </x-bento.tile>

            {{-- Payables: what is owed, and how soon. --}}
            <x-bento.tile :heading="$heading['payables']" class="md:col-span-3 xl:col-span-3">
                <x-figure.display :value="$payablesTotal" :currency="$currency" size="headline"/>
                <p class="mt-1 text-caption text-on-surface-muted">
                    {{ \App\Support\Lang\Message::of('overview.open_documents', ['count' => $overview->payables->documents]) }}
                </p>

                <dl class="mt-4 space-y-3 text-label">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-on-surface-muted">{{ __('مستحقة هذا الأسبوع') }}</dt>
                        <dd dir="ltr" class="figure font-medium text-on-surface">{{ $figure($overview->payables->dueThisWeek, $currency) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-on-surface-muted">{{ __('متأخرة') }}</dt>
                        <dd dir="ltr" class="figure font-medium text-on-surface">{{ $figure($overview->payables->overdue, $currency) }}</dd>
                    </div>
                </dl>

                @if ($overview->payables->dueThisWeekDocuments > 0)
                    <p class="mt-3 text-caption text-on-surface-muted">
                        {{ \App\Support\Lang\Message::of('overview.due_this_week', ['count' => $overview->payables->dueThisWeekDocuments]) }}
                    </p>
                @endif

                @if ($overview->payables->otherCurrencyDocuments > 0)
                    <p class="mt-2 text-caption text-on-surface-muted">
                        {{ \App\Support\Lang\Message::of('overview.other_currencies', ['count' => $overview->payables->otherCurrencyDocuments]) }}
                    </p>
                @endif
            </x-bento.tile>

            {{-- Bank reconciliation. --}}
            <x-bento.tile :heading="$heading['reconciliation']" class="md:col-span-6 xl:col-span-6">
                <div class="flex items-baseline gap-3">
                    <span dir="ltr" class="figure figure-display text-display font-semibold text-figure">{{ $reconciliation->percentMatched() }}%</span>
                    <span class="text-label text-on-surface-muted">{{ __('من السطور مطابقة') }}</span>
                </div>

                @if ($reconciliation->total() > 0)
                    <div aria-hidden="true" class="mt-4 flex h-2.5 gap-0.5 overflow-hidden rounded-full bg-surface-container-high">
                        <div class="bg-primary" style="flex: {{ $reconciliation->matched }}"></div>
                        <div class="bg-insight" style="flex: {{ $reconciliation->suggested }}"></div>
                        <div class="bg-outline-strong" style="flex: {{ $reconciliation->waiting }}"></div>
                    </div>
                @endif

                <dl class="mt-4 grid grid-cols-3 gap-3 text-label">
                    @foreach ([
                        [__('مطابقة'), $reconciliation->matched, 'bg-primary'],
                        [__('مقترحة'), $reconciliation->suggested, 'bg-insight'],
                        [__('بانتظارك'), $reconciliation->waiting, 'bg-outline-strong'],
                    ] as [$label, $count, $swatch])
                        <div>
                            <dt class="flex items-center gap-2 text-on-surface-muted">
                                <span aria-hidden="true" class="size-2.5 rounded-full {{ $swatch }}"></span>
                                {{ $label }}
                            </dt>
                            <dd dir="ltr" class="figure mt-0.5 text-title font-semibold text-on-surface">{{ $count }}</dd>
                        </div>
                    @endforeach
                </dl>
                <p class="mt-3 text-caption text-on-surface-muted">{{ __('سطور كشوف البنك خلال آخر تسعين يومًا.') }}</p>
            </x-bento.tile>

            {{-- Drafts. --}}
            <x-bento.tile :heading="$heading['drafts']" class="md:col-span-3 xl:col-span-3">
                <span dir="ltr" class="figure figure-display text-display font-semibold text-figure">{{ $overview->drafts() }}</span>
                <dl class="mt-4 space-y-2 text-label">
                    @foreach ([
                        [__('فواتير مبيعات'), $overview->draftInvoices],
                        [__('فواتير موردين'), $overview->draftBills],
                        [__('قيود يومية'), $overview->draftJournals],
                    ] as [$label, $count])
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-on-surface-muted">{{ $label }}</dt>
                            <dd dir="ltr" class="figure text-on-surface">{{ $count }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-bento.tile>

            {{-- Tax period. --}}
            <x-bento.tile :heading="$heading['tax']" class="md:col-span-3 xl:col-span-3">
                @if ($overview->tax)
                    <x-slot:aside>
                        @if ($overview->tax->isOverdue())
                            <span class="inline-flex items-center gap-1 rounded-full bg-warning-subtle px-2 py-0.5 text-caption font-medium text-on-warning-subtle">
                                <span aria-hidden="true">!</span>{{ __('متأخرة') }}
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-success-subtle px-2 py-0.5 text-caption font-medium text-on-success-subtle">
                                {{ __('مفتوحة') }}
                            </span>
                        @endif
                    </x-slot:aside>

                    <span dir="ltr" class="figure text-headline font-semibold text-on-surface">{{ $overview->tax->label }}</span>
                    <dl class="mt-4 text-label">
                        <dt class="text-on-surface-muted">{{ __('تاريخ الاستحقاق') }}</dt>
                        <dd dir="ltr" class="figure mt-0.5 text-on-surface">{{ $overview->tax->dueOn?->toDateString() ?? '—' }}</dd>
                    </dl>
                @else
                    <p class="text-label text-on-surface-muted">{{ __('لا توجد فترة ضريبية مفتوحة.') }}</p>
                @endif
            </x-bento.tile>

            {{-- Recent activity. --}}
            <x-bento.tile :heading="$heading['activity']" class="md:col-span-6 xl:col-span-6">
                @if ($overview->activity === [])
                    <p class="text-label text-on-surface-muted">{{ __('لا نشاط خلال آخر تسعين يومًا.') }}</p>
                @else
                    <ol class="divide-y divide-outline-subtle">
                        @foreach ($overview->activity as $item)
                            <li class="flex items-baseline justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                                <div class="min-w-0">
                                    <p class="truncate text-label text-on-surface">{{ $item->subject ?? $item->action }}</p>
                                    <p class="mt-0.5 text-caption text-on-surface-muted">
                                        {{ $item->actor }} · <code dir="ltr" class="font-mono">{{ $item->action }}</code>
                                    </p>
                                </div>
                                <time datetime="{{ $item->at->toIso8601String() }}" class="shrink-0 text-caption text-on-surface-muted">
                                    {{ $item->at->locale($locale)->diffForHumans() }}
                                </time>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-bento.tile>
        </div>
    @endif
@endsection
