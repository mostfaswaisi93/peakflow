@extends('layouts.app', ['title' => __('الحسابات البنكية'), 'wide' => true])

{{--
    The bank accounts - PF-145, PF-146.

    One tile per account, and each tile carries all three balances under
    their own names. A tile with a single figure headed "balance" is how
    somebody authorises a payment against money that is already committed:
    a run released on Friday is in the ledger, absent from the statement,
    and already out of the available figure.

    So the three are side by side, the difference between the first two is
    itemised beneath them, and the date of the last statement sits with
    them - because a reconciliation measured against a month-old statement
    is a reconciliation against nothing, and the date is the only thing on
    the page that says whether the comparison means anything.

    No account number is rendered in full. The tail is stored in the clear
    for exactly this screen; the encrypted columns are never read here.
--}}

@section('content')
    @php
        /*
         | The display form of an amount: at the currency's exponent, and
         | the ACCOUNTING negative - parentheses, never a bare minus,
         | because a minus sign is one pixel wide at this size and the
         | column it is in is the difference between owing and being owed.
         */
        $figure = static fn (\App\Support\Money\Money $money): string => $money->isNegative()
            ? '('.$money->abs()->toPresentation().')'
            : (string) $money->toPresentation();

        /*
         | Five weeks, because that is one missed monthly cycle.
         |
         | Not a judgement the service can make - how often a statement
         | arrives depends on the bank - so the page states the age and
         | marks the ones that have missed a whole cycle.
         */
        $staleAfterDays = 35;

        $balanceNames = [
            'ledger' => __('الرصيد الدفتري'),
            'statement' => __('رصيد كشف البنك'),
            'available' => __('الرصيد المتاح'),
        ];

        $balanceNotes = [
            'ledger' => __('ما هو مرحَّل في دفاترنا على حساب البنك في دليل الحسابات.'),
            'statement' => __('ما يقوله البنك في آخر كشف مرحَّل استوردناه.'),
            'available' => __('رصيد الكشف ناقص ما أُرسل ولم يخصمه البنك بعد. هذا هو الرصيد الذي يُصرف منه.'),
        ];

        $componentNames = [
            'unpresented_payments' => __('مدفوعات أُرسلت ولم تظهر في الكشف بعد'),
            'unsettled_receipts' => __('مبالغ أُودعت ولم يقيّدها البنك بعد'),
            'unrecorded_bank_items' => __('حركات قيّدها البنك ولا يسجّلها شيء عندنا'),
            'unposted_opening_balance' => __('رصيد افتتاحي لم يُرحَّل إلى دفتر اليومية'),
            'unexplained' => __('فرق لا يفسّره شيء'),
        ];
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-headline font-bold text-on-surface">{{ __('الحسابات البنكية') }}</h1>
            <p class="mt-1 text-label text-on-surface-muted">
                {{ __('أنت تعمل الآن في :entity.', ['entity' => $entity?->legal_name]) }}
            </p>
        </div>

        <p class="max-w-md text-caption text-on-surface-muted">
            {{ __('ثلاثة أرصدة لكل حساب، ولا واحد منها اسمه «الرصيد». الدفتري والكشف والمتاح أرقام مختلفة، وصفحة تعرض واحدًا منها باسم «الرصيد» هي الطريقة التي يُعتمد بها دفع من مال مرتبط أصلًا.') }}
        </p>
    </div>

    @if ($accounts === [])
        <div class="mt-8 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
            {{ __('لا يوجد حساب بنكي مسجّل في هذه الشركة بعد.') }}
        </div>
    @else
        <div class="mt-8 grid grid-cols-1 gap-4 xl:grid-cols-2">
            @foreach ($accounts as $account)
                @php
                    $label = $account['label'];
                    $identifier = $account['identifier'];
                    $institution = $account['institution'];
                    $currency = $account['currency'];
                    $components = $account['components'];
                    $age = $account['statement_age_in_days'];
                    $isStale = $age !== null && $age > $staleAfterDays;
                @endphp

                <x-bento.tile :heading="$label">
                    <x-slot:aside>
                        <span class="inline-flex items-center gap-2 rounded-full border border-outline bg-surface px-3 py-1 text-caption text-on-surface-muted">
                            <span class="figure font-semibold text-on-surface">{{ $currency }}</span>
                            @if (! $account['active'])
                                <span>{{ __('مغلق') }}</span>
                            @endif
                        </span>
                    </x-slot:aside>

                    <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-label sm:grid-cols-2">
                        <div class="flex items-baseline justify-between gap-3 sm:block">
                            <dt class="text-on-surface-muted">{{ __('البنك') }}</dt>
                            <dd class="mt-0.5 text-on-surface">
                                {{ $institution }}
                                @if ($account['branch'])
                                    <span class="text-on-surface-muted">· {{ $account['branch'] }}</span>
                                @endif
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3 sm:block">
                            <dt class="text-on-surface-muted">
                                @if ($account['identifier_kind'] === 'iban')
                                    {{ __('رقم الحساب المصرفي الدولي') }}
                                @elseif ($account['identifier_kind'] === 'domestic')
                                    {{ __('رقم الحساب المحلي') }}
                                @else
                                    {{ __('معرّف الحساب') }}
                                @endif
                            </dt>
                            <dd class="mt-0.5">
                                @if ($identifier === null)
                                    <span class="text-on-surface-muted">{{ __('غير مذكور') }}</span>
                                @else
                                    {{-- The tail only. A full number on a list screen is a full
                                         number in a screenshot, a support ticket and a browser
                                         cache - and the encrypted column is not read here at all. --}}
                                    <span dir="ltr" class="figure text-on-surface">{{ $identifier }}</span>
                                    <span class="ms-2 text-caption text-on-surface-muted">{{ __('الأرقام الأربعة الأخيرة فقط') }}</span>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    {{-- The three, together and named. --}}
                    <dl class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        @foreach (['ledger', 'statement', 'available'] as $which)
                            @php
                                $name = $balanceNames[$which];
                                $note = $balanceNotes[$which];
                                $amount = $figure($account[$which]);
                            @endphp
                            <div class="rounded-card border border-outline-subtle bg-surface-container-low p-3">
                                <dt class="text-caption font-medium text-on-surface-muted">{{ $name }}</dt>
                                <dd class="mt-1">
                                    <x-figure.display :value="$amount" :currency="$currency" size="title"/>
                                </dd>
                                <p class="mt-2 text-caption text-on-surface-subtle">{{ $note }}</p>
                            </div>
                        @endforeach
                    </dl>

                    {{-- Said plainly when they disagree, and said when they do not. --}}
                    @php
                        $difference = $account['difference'];
                        $differenceFigure = $figure($difference);
                    @endphp

                    @if ($difference->isZero() && $components === [])
                        <p class="mt-4 inline-flex items-center gap-2 rounded-full bg-success-subtle px-3 py-1 text-caption font-medium text-on-success-subtle">
                            <span aria-hidden="true">●</span>
                            {{ __('الدفتري والكشف متطابقان، ولا فرق يحتاج تفسيرًا.') }}
                        </p>
                    @else
                        <div class="mt-4 rounded-card border p-4 {{ $account['agrees'] ? 'border-outline bg-surface-container-low' : 'border-warning bg-warning-subtle' }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-3">
                                <h3 class="text-label font-medium {{ $account['agrees'] ? 'text-on-surface' : 'text-on-warning-subtle' }}">
                                    @if ($account['agrees'])
                                        {{ __('الدفتري والكشف مختلفان، والفرق مفسَّر بالكامل') }}
                                    @else
                                        {{ __('الدفتري والكشف مختلفان، وبعض الفرق لا يفسّره شيء') }}
                                    @endif
                                </h3>
                                <p class="inline-flex items-baseline gap-2">
                                    <span class="text-caption {{ $account['agrees'] ? 'text-on-surface-muted' : 'text-on-warning-subtle' }}">{{ __('الفرق') }}</span>
                                    <span dir="ltr" class="figure font-semibold {{ $account['agrees'] ? 'text-figure' : 'text-on-warning-subtle' }}">{{ $differenceFigure }}</span>
                                </p>
                            </div>

                            @if ($components !== [])
                                <dl class="mt-3 space-y-1.5 text-caption">
                                    @foreach ($components as $key => $amount)
                                        @php
                                            $componentName = $componentNames[$key] ?? $key;
                                            $componentFigure = $figure($amount);
                                        @endphp
                                        <div class="flex items-baseline justify-between gap-3">
                                            <dt class="{{ $account['agrees'] ? 'text-on-surface-muted' : 'text-on-warning-subtle' }}">{{ $componentName }}</dt>
                                            <dd dir="ltr" class="figure {{ $account['agrees'] ? 'text-on-surface' : 'text-on-warning-subtle' }}">{{ $componentFigure }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif

                            @if ($account['why'])
                                <p class="mt-3 text-caption {{ $account['agrees'] ? 'text-on-surface-muted' : 'text-on-warning-subtle' }}">{{ $account['why'] }}</p>
                            @endif
                        </div>
                    @endif

                    {{-- The statement these figures are measured against. --}}
                    <div class="mt-auto pt-4">
                        @if ($account['statement_period_to'] === null)
                            <p class="text-caption text-on-surface-muted">
                                {{ __('لم يُستورد أي كشف لهذا الحساب، فرصيد الكشف هو الرصيد الافتتاحي ولا شيء يقابل الدفاتر.') }}
                            </p>
                        @else
                            <dl class="flex flex-wrap items-baseline gap-x-5 gap-y-1 text-caption">
                                <div class="flex items-baseline gap-2">
                                    <dt class="text-on-surface-muted">{{ __('آخر كشف') }}</dt>
                                    <dd dir="ltr" class="figure text-on-surface">{{ $account['statement_label'] }}</dd>
                                </div>
                                <div class="flex items-baseline gap-2">
                                    <dt class="text-on-surface-muted">{{ __('حتى تاريخ') }}</dt>
                                    <dd dir="ltr" class="figure text-on-surface">{{ $account['statement_period_to'] }}</dd>
                                </div>
                                @if ($account['statement_imported_at'])
                                    <div class="flex items-baseline gap-2">
                                        <dt class="text-on-surface-muted">{{ __('استُورد في') }}</dt>
                                        <dd dir="ltr" class="figure text-on-surface">{{ $account['statement_imported_at'] }}</dd>
                                    </div>
                                @endif
                                {{-- The age as a bare figure, deliberately.
                                     A sentence with a count in it would need
                                     Arabic's six plural forms, and the number
                                     is the only part a reader is scanning
                                     for. --}}
                                <div class="flex items-baseline gap-2">
                                    <dt class="text-on-surface-muted">{{ __('عمره بالأيام') }}</dt>
                                    <dd dir="ltr" class="figure text-on-surface">{{ $age }}</dd>
                                </div>
                            </dl>

                            @if ($isStale)
                                <p class="mt-2 rounded-field bg-warning-subtle px-3 py-2 text-caption text-on-warning-subtle">
                                    {{ __('مضى على آخر كشف أكثر من دورة شهرية كاملة. المطابقة أمام كشف بهذا العمر مطابقة أمام لا شيء: كل ما جرى بعده موجود في الدفاتر وغائب عن الكشف، ويظهر فرقًا لا يستطيع أحد إقفاله.') }}
                                </p>
                            @endif

                            @if ($account['statement_is_provisional'])
                                <p class="mt-2 rounded-field bg-info-subtle px-3 py-2 text-caption text-on-info-subtle">
                                    {{ __('آخر كشف وصل مبدئي خلال اليوم، وهو لا يصلح أساسًا للمطابقة: حركاته نفسها سيحملها الكشف المرحَّل. المطابقة أعلاه محسوبة على آخر كشف مرحَّل، وهو أقدم من هذا.') }}
                                </p>
                            @endif
                        @endif
                    </div>
                </x-bento.tile>
            @endforeach
        </div>
    @endif
@endsection
