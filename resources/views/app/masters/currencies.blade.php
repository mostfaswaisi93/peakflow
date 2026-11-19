@extends('layouts.app', ['title' => __('العملات'), 'wide' => true])

{{--
    Currencies and FX rates - PF-067, PF-068, PF-069.

    Read-only, and every control on it is a GET. Nothing here enables a
    currency or records a rate: enabling one decides what the company may
    transact in at all, and a recorded rate is an immutable row that every
    posted amount already depends on. Neither belongs behind a button on a
    page somebody opened to read.

    The functional currency is the first thing on the page and the loudest
    thing on it. Every figure the product shows is measured in it, so a reader
    who takes it for something else reads every report wrong and nothing on
    the screen contradicts them - which is why it is a sentence at the top and
    not a tick in a column.

    A rate is shown with its as-of date, its provider and its rate set,
    always. Those three are what make the number attributable; without them it
    is a figure that reconciles to nothing, and the reader cannot tell that it
    does not. The age sits beside them and a rate past its set's staleness
    threshold is marked, because a translation done at a month-old rate is
    wrong in a way no total reveals: it balances.

    A pair with NO rate keeps its row. That is the case this page exists for -
    a missing rate is why a posting in that currency will be refused, and the
    reader needs to meet it here rather than on a document they have already
    written.
--}}

@section('content')
    @php
        /*
         | The rate sets the migration seeds, and what each one is for in the
         | words somebody would ask the question in.
         |
         | A set this page does not know is NOT mapped to the nearest label.
         | The sets are a table rather than an enum precisely because a
         | jurisdiction can require a fourth, so an unknown code is shown in
         | the name and the purpose the tenant gave it - a default label is
         | how a set nobody recognises stops being visible.
         */
        $setNames = [
            'market' => __('سعر السوق'),
            'monthly_average' => __('المتوسط الشهري'),
            'statutory' => __('السعر النظامي'),
        ];

        $setPurposes = [
            'market' => __('سعر اليوم، وهو سعر المعاملات.'),
            'monthly_average' => __('لترجمة الأرباح والخسائر عن الفترة بمقتضى معيار المحاسبة الدولي 21. استخدام سعر الإقفال للإيرادات والمصروفات يضخّم الشهر الذي تحرّك فيه السعر.'),
            'statutory' => __('ما تفرضه جهة ضريبية، وهو في الغالب ليس السعر الذي تعاملت به الخزينة فعلًا.'),
        ];

        $setName = static fn (string $code, string $stored): string => $setNames[$code] ?? $stored;

        $enabledLabel = __('مفعّلة');
        $disabledLabel = __('غير مفعّلة');

        /*
         | Counted here so the consequence can be stated once beneath the
         | table instead of repeated in every row that needs it. A sentence
         | this long in a cell would push the figure columns off the screen,
         | and the figures are what the reader came to line up.
         */
        $missingRates = \count(\array_filter($rates, static fn (array $row): bool => $row['rate'] === null));
        $staleRates = \count(\array_filter($rates, static fn (array $row): bool => $row['is_stale'] === true));

        $functionalRow = collect($currencies)->firstWhere('is_functional', true);
    @endphp

    <x-page-header :title="__('العملات')">
        <x-slot:description>
            {{ __('العملات التي تتعامل بها هذه الشركة، ومعها أحدث سعر على الملف لكل زوج منها إلى عملتها الوظيفية.') }}
        </x-slot:description>
        <x-slot:aside>
            @if ($entity)
                <p class="inline-flex items-center gap-2 rounded-full border border-outline bg-surface px-3 py-1 text-caption text-on-surface-muted">
                    {{ __('الشركة الحالية') }}
                    <span class="font-semibold text-on-surface">{{ $entity->legal_name }}</span>
                </p>
            @endif
        </x-slot:aside>
    </x-page-header>

    @if ($entity === null)
        <div class="mt-6 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
            {{ __('لا توجد شركة محدَّدة الآن، فلا عملات ولا أسعار تُعرض.') }}
        </div>
    @else
        {{--
            The functional currency, said plainly.

            It is the measure of every figure in the product, and the reader
            who mistakes it mistakes all of them at once. So it gets the
            weight of a statement: the code at heading size, both names under
            it, and the two facts somebody needs to know before they read
            anything else on the page.
        --}}
        <section class="mt-6 rounded-card border-2 border-insight bg-insight-subtle p-5 text-on-insight-subtle">
            <h2 class="text-label font-semibold">{{ __('العملة الوظيفية') }}</h2>

            <p class="mt-2 flex flex-wrap items-baseline gap-3">
                <span dir="ltr" class="figure text-headline font-bold">{{ $functional }}</span>

                @if ($functionalRow !== null && $functionalRow['name_ar'] !== null)
                    <span class="text-title font-semibold">{{ $functionalRow['name_ar'] }}</span>
                    <span dir="ltr" class="text-label">{{ $functionalRow['name_en'] }}</span>
                @endif
            </p>

            <p class="mt-3 max-w-3xl text-label">
                {{ __('لهذه الشركة عملة وظيفية واحدة، وكل رقم في النظام مقيس بها: الأرصدة والتقارير والإقرارات كلها بهذه العملة.') }}
            </p>

            <p class="mt-2 max-w-3xl text-label">
                {{ __('ولا تتغير بعد أول ترحيل. تغيير العملة الوظيفية بمقتضى معيار المحاسبة الدولي 21 إعادةُ عرضٍ لتاريخ الشركة كله، لا تعديلَ حقلٍ في شاشة، ولذلك تُرفض هنا وفي قاعدة البيانات.') }}
            </p>
        </section>

        <section class="mt-8">
            <h2 class="text-title font-semibold text-on-surface">{{ __('عملات الشركة') }}</h2>

            <p class="mt-1 max-w-3xl text-label text-on-surface-muted">
                {{ __('العملة غير المفعّلة تُرفض على أي مستند جديد، ولا يُحوَّل المبلغ إليها بسعر لم يختره أحد. والمستندات القائمة بها تبقى كما هي، فإعادة كتابة التاريخ لتطابق تغييرًا في الإعدادات عكس ما يقوم عليه هذا النظام.') }}
            </p>

            <div class="mt-4 overflow-x-auto rounded-card border border-outline">
                <table class="w-full text-start text-label">
                    <caption class="sr-only">{{ __('عملات الشركة، والعملة الوظيفية أولًا') }}</caption>
                    <thead class="bg-surface-sunken text-on-surface-muted">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الرمز') }}</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الاسم') }}</th>
                            {{-- `data-figure` puts the header over its own column in
                                 both directions: digits do not flip, so the column
                                 stays right-aligned in Arabic, and a `text-end`
                                 header would sit on the far side of it in one of the
                                 two languages. --}}
                            <th scope="col" data-figure class="px-4 py-3 font-medium">{{ __('الرمز الرقمي') }}</th>
                            <th scope="col" data-figure class="px-4 py-3 font-medium">{{ __('الخانات العشرية') }}</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الحالة') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-subtle bg-surface">
                        @forelse ($currencies as $currency)
                            <tr @class(['bg-surface-sunken' => ! $currency['enabled']])>
                                <td @class([
                                    'px-4 py-3',
                                    'border-s-4 border-insight' => $currency['is_functional'],
                                ])>
                                    <span dir="ltr" class="figure font-semibold text-on-surface">{{ $currency['code'] }}</span>

                                    @if ($currency['is_functional'])
                                        {{-- The badge says it in words as well as in the
                                             edge. A cue that is only a coloured border is
                                             a cue that does not survive a high-contrast
                                             theme, a printout, or a reader who cannot see
                                             it. --}}
                                        <span class="mt-1 block text-caption font-semibold text-on-surface">{{ __('العملة الوظيفية') }}</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3">
                                    @if ($currency['known'])
                                        {{-- Both names, whichever language is being read.
                                             The reader who has the wrong currency in mind
                                             is the one reading the name in the other
                                             language, and this page is where that is
                                             caught. --}}
                                        <span class="block text-on-surface">{{ $currency['name_ar'] }}</span>
                                        <span dir="ltr" class="mt-0.5 block text-caption text-on-surface-subtle">{{ $currency['name_en'] }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-warning-subtle px-2 py-0.5 text-caption font-medium text-on-warning-subtle">
                                            {{ __('رمز لا تعرفه قائمة ISO 4217') }}
                                        </span>
                                        <span class="mt-1 block text-caption text-on-surface-muted">
                                            {{ __('لا يُفترض له أس عشري ولا رمز رقمي. افتراض خانتين يعرض 1.234 على أنه 1.23، وهو خطأ بألف ضعف تمرّ عليه العين.') }}
                                        </span>
                                    @endif
                                </td>

                                <td dir="ltr" class="figure px-4 py-3 text-on-surface">
                                    {{ $currency['numeric'] ?? '—' }}
                                </td>

                                <td dir="ltr" class="figure px-4 py-3 text-on-surface">
                                    {{ $currency['exponent'] ?? '—' }}
                                </td>

                                <td class="px-4 py-3">
                                    @if ($currency['enabled'])
                                        <span class="inline-flex items-center rounded-full bg-success-subtle px-2 py-0.5 text-caption font-medium text-on-success-subtle">
                                            {{ $enabledLabel }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-warning-subtle px-2 py-0.5 text-caption font-medium text-on-warning-subtle">
                                            {{ $disabledLabel }}
                                        </span>
                                    @endif

                                    @if ($currency['known'] && $currency['presentation_decimals'] !== $currency['exponent'])
                                        {{-- The entity shows this currency at a different
                                             number of decimals from the ISO exponent,
                                             which is a real arrangement - a few
                                             jurisdictions present a three-decimal
                                             currency at two while still settling at
                                             three - and is worth saying out loud, because
                                             the document and the ledger will not agree to
                                             the fils. --}}
                                        <span class="mt-1 block text-caption text-on-surface-muted">
                                            {{ __('تُعرض بعدد خانات مختلف عن الأس القياسي: :decimals.', ['decimals' => $currency['presentation_decimals']]) }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-on-surface-muted">
                                    {{ __('لا عملة مسجَّلة لهذه الشركة بعد.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <p class="mt-3 max-w-3xl text-caption text-on-surface-muted">
                {{ __('الخانات العشرية هي أس العملة في ISO 4217، وليست خانتين دائمًا: ثلاث خانات للدينار الأردني والكويتي والبحريني والريال العماني والدينار التونسي، وصفر للين الياباني والوون الكوري. ولا علاقة لها بما يُخزَّن، فالمبالغ تُخزَّن بأربع خانات في كل الحالات.') }}
            </p>
        </section>

        <section>
            <h2 class="text-title font-semibold text-on-surface">{{ __('أسعار الصرف') }}</h2>

            <p class="mt-1 max-w-3xl text-label text-on-surface-muted">
                {{ __('الاتجاه مخزَّن ولا يُستنتج: السعر من عملة المستند إلى العملة الوظيفية، لأن هذا هو السؤال الذي يسأله الترحيل. وقلب سعرٍ مقرَّب للحصول على الاتجاه الآخر هو كيف يكتسب التجميع فرقًا دائمًا يكبر مع كل حساب.') }}
            </p>

            <p class="mt-2 max-w-3xl text-label text-on-surface-muted">
                {{ __('المعروض لكل زوج هو السعر نفسه الذي سيختاره ترحيل اليوم: أحدث سعر قائم بتاريخ اليوم أو قبله، بترتيب أسبقية المزوّدين نفسه، دون سعر مسحوب أو سعر صحّحه سعر بعده.') }}
            </p>

            <div class="mt-4 rounded-card border border-outline bg-surface p-4">
                @if (count($sets) > 0)
                    <form method="GET" class="flex flex-wrap items-end gap-3">
                        <div>
                            <label for="set" class="block text-label font-medium text-on-surface">{{ __('مجموعة الأسعار') }}</label>
                            <select name="set" id="set"
                                    class="min-h-(--control-height) mt-1.5 block rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-label text-on-surface
                                           focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                                @foreach ($sets as $option)
                                    <option value="{{ $option->code }}" @selected((string) $option->code === $setCode)>
                                        {{ $setName((string) $option->code, (string) $option->name) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <x-button type="submit" variant="outline">{{ __('اعرض') }}</x-button>
                    </form>

                    <p class="mt-3 text-caption text-on-surface-muted">
                        {{ __('تفتح الصفحة على المجموعة التي تترجم بها هذه الشركة افتراضيًا. المجموعات تتعايش، والسعر الذي استُخدم على قيد يذكر مجموعته.') }}
                    </p>
                @endif

                @if ($unknownSet)
                    <p class="mt-3 rounded-field bg-warning-subtle px-3 py-2 text-caption text-on-warning-subtle">
                        {{ __('المجموعة المطلوبة في الرابط ليست مجموعة موجودة على الملف، والمعروض أدناه هو المجموعة الافتراضية لهذه الشركة.') }}
                    </p>
                @endif

                @if ($set !== null)
                    <p class="mt-3 max-w-3xl text-label text-on-surface">
                        {{ $setPurposes[(string) $set->code] ?? $set->purpose }}
                    </p>

                    <p class="mt-1 text-caption text-on-surface-muted">
                        {{ __('يُعدّ السعر في هذه المجموعة بائتًا بعد :days يومًا. الحد للمجموعة لا للصفحة: سعر سوق عمره ثلاثة أيام مشكلة، ومتوسط شهري بعد نهاية الشهر بثلاثة أيام هو ببساطة المتوسط الحالي.', ['days' => $staleAfterDays]) }}
                    </p>

                    @if (! $set->active)
                        <p class="mt-1 text-caption text-on-surface-muted">
                            {{ __('هذه المجموعة غير مفعّلة، والأسعار المعروضة فيها تاريخية.') }}
                        </p>
                    @endif
                @else
                    <p class="mt-1 text-caption text-on-surface-muted">
                        {{ __('لا تعريف لهذه المجموعة على الملف، فالحد المطبَّق على بيات أسعارها هنا :days يومًا. الأسعار تبقى بعد حذف تعريف مجموعتها، لأنها مستندات لا إعدادات.', ['days' => $staleAfterDays]) }}
                    </p>
                @endif
            </div>

            <div class="mt-4 overflow-x-auto rounded-card border border-outline">
                <table class="w-full text-start text-label">
                    <caption class="sr-only">{{ __('أحدث سعر لكل زوج من عملات الشركة المفعّلة إلى عملتها الوظيفية') }}</caption>
                    <thead class="bg-surface-sunken text-on-surface-muted">
                        <tr>
                            <th scope="col" data-figure class="px-4 py-3 font-medium">{{ __('من العملة') }}</th>
                            <th scope="col" data-figure class="px-4 py-3 font-medium">{{ __('إلى العملة') }}</th>
                            <th scope="col" data-figure class="px-4 py-3 font-medium">{{ __('سعر الصرف') }}</th>
                            <th scope="col" data-figure class="px-4 py-3 font-medium">{{ __('تاريخ السعر') }}</th>
                            <th scope="col" data-figure class="px-4 py-3 font-medium">{{ __('عمر السعر (أيام)') }}</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('مصدر السعر') }}</th>
                            <th scope="col" data-figure class="px-4 py-3 font-medium">{{ __('مجموعة الأسعار') }}</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الحالة') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-subtle bg-surface">
                        @forelse ($rates as $rate)
                            @php($hasRate = $rate['rate'] !== null)

                            <tr @class(['bg-surface-sunken' => ! $hasRate])>
                                <td dir="ltr" class="figure px-4 py-3 font-semibold text-on-surface">{{ $rate['from'] }}</td>
                                <td dir="ltr" class="figure px-4 py-3 text-on-surface">{{ $rate['to'] }}</td>

                                <td dir="ltr" class="figure px-4 py-3 text-on-surface">
                                    {{-- The rate exactly as stored, to all twelve of its
                                         decimals. A rate shown with fewer decimals than
                                         it was stored with is a different rate, and the
                                         first thing anybody does with the shortened one
                                         is multiply by it. --}}
                                    {{ $hasRate ? $rate['rate'] : '—' }}
                                </td>

                                <td dir="ltr" class="figure px-4 py-3 text-on-surface">
                                    @if ($rate['as_of'] !== null)
                                        <time datetime="{{ $rate['as_of'] }}">{{ $rate['as_of'] }}</time>
                                    @else
                                        —
                                    @endif
                                </td>

                                <td dir="ltr" class="figure px-4 py-3 text-on-surface">
                                    {{ $rate['age_in_days'] ?? '—' }}
                                </td>

                                <td class="px-4 py-3">
                                    @if ($rate['provider'] === null)
                                        <span class="text-on-surface-subtle">—</span>
                                    @else
                                        <span dir="ltr" class="figure block text-on-surface">{{ $rate['provider'] }}</span>

                                        @if ($rate['provider_name'] !== null)
                                            <span class="mt-0.5 block text-caption text-on-surface-muted">{{ $rate['provider_name'] }}</span>
                                        @else
                                            {{-- The feed's own row is gone and the code
                                                 stays, because the rate that was used is
                                                 still the rate that was used. Hiding it
                                                 would make a document that was booked at
                                                 it unexplainable. --}}
                                            <span class="mt-0.5 block text-caption text-on-surface-muted">{{ __('مزوّد لم يبق له سجل، والرمز كما خُزِّن مع السعر') }}</span>
                                        @endif
                                    @endif
                                </td>

                                <td dir="ltr" class="figure px-4 py-3 text-on-surface-muted">{{ $rate['rate_set'] }}</td>

                                <td class="px-4 py-3">
                                    @if (! $hasRate)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-warning-subtle px-2 py-0.5 text-caption font-medium text-on-warning-subtle">
                                            <span aria-hidden="true">!</span>{{ __('لا سعر') }}
                                        </span>
                                    @elseif ($rate['is_stale'])
                                        <span class="inline-flex items-center gap-1 rounded-full bg-warning-subtle px-2 py-0.5 text-caption font-medium text-on-warning-subtle">
                                            <span aria-hidden="true">!</span>{{ __('بائت') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-success-subtle px-2 py-0.5 text-caption font-medium text-on-success-subtle">
                                            {{ __('سارٍ') }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-on-surface-muted">
                                    {{ __('لا تتعامل هذه الشركة بغير عملتها الوظيفية، فلا يوجد زوج يحتاج سعرًا. السعر في العملة الوظيفية نفسها ليس سعرًا، والنظام يرفضه بدل أن يعطيه القيمة واحدًا.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($missingRates > 0)
                <p class="mt-4 max-w-3xl rounded-field bg-warning-subtle px-3 py-2 text-caption text-on-warning-subtle">
                    {{ __('زوج بلا سعر على الملف لا يُترجَم، وكل مستند بعملته سيُرفض حتى يُسجَّل له سعر. الصف معروض لهذا السبب: السعر الغائب لا يُقرأ واحدًا ولا يُحسب من الاتجاه المعاكس.') }}
                </p>
            @endif

            @if ($staleRates > 0)
                <p class="mt-2 max-w-3xl rounded-field bg-warning-subtle px-3 py-2 text-caption text-on-warning-subtle">
                    {{ __('السعر البائت لا يُظهر نفسه في أي مجموع: الترحيل على سعر عمره شهر يوازن ويمر، ويُقرأ الفرق بعد الإقفال فلا يستطيع أحد تعليله. وجود سعر أقدم أفضل من لا شيء، وهذا بالضبط ما يستوجب الإشارة إليه على كل مستند استخدمه.') }}
                </p>
            @endif
        </section>
    @endif
@endsection
