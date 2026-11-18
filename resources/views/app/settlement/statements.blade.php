@extends('layouts.app', ['title' => __('كشوف الحساب'), 'wide' => true])

{{--
    A statement of account for one party - PF-181.

    Both forms, on one page, from one service. Balance-forward opens with
    the balance brought forward and lists what happened since, which is
    what somebody asks when the two sides disagree about the total.
    Open-item lists the documents that are not settled, which is the only
    form that can be reconciled line by line and the only form that can be
    paid from. A page with one of them cannot do the other's job.

    The proof is printed in both directions: brought forward, plus every
    movement, equals the closing balance computed independently - and when
    it does not, the page says so with the difference. A column that only
    showed its proof when the proof held would be silent exactly when it
    matters.

    Unapplied cash and credits keep their own rows rather than being
    netted against the oldest invoice, because netting silently is a
    decision made on the customer's behalf that they cannot see.

    No party in the query string is a question, not an error: it renders
    the picker. Everything here is GET.
--}}

@section('content')
    @php
        /*
         | The display form of an amount: at the currency's exponent, and
         | the ACCOUNTING negative - parentheses, never a bare minus. On a
         | statement the sign is the difference between a customer owing
         | and a customer being in credit, and a minus glyph at caption
         | size is a pixel wide.
         */
        $figure = static fn (\App\Support\Money\Money $money): string => $money->isNegative()
            ? '('.$money->abs()->toPresentation().')'
            : (string) $money->toPresentation();

        $sideNames = [
            'customer' => __('كشف عميل'),
            'supplier' => __('كشف مورد'),
        ];

        $sideBases = [
            'customer' => __('من فواتير المبيعات، مقابل حساب مراقبة الذمم المدينة.'),
            'supplier' => __('من فواتير الموردين، مقابل حساب مراقبة الذمم الدائنة.'),
        ];

        $creditKinds = [
            'unapplied_cash' => __('نقد لم يُخصَّص بعد'),
        ];

        /*
         | Component attributes carry plain variables, never a call.
         |
         | The template-text rule reads tags with a plain parser, so an
         | `->` or a `>` inside an attribute ends the tag as far as it is
         | concerned - and the convention that keeps it honest is to
         | compute everything an attribute needs here.
         */
        $headings = [
            'brought_forward' => __('الرصيد المنقول'),
            'closing' => __('رصيد الإقفال'),
            'open_total' => __('إجمالي ما لم يُسدَّد'),
        ];
    @endphp

    {{-- The name goes in the title SLOT, not the title attribute: the
         template-text rule reads tags with a plain parser, so it cannot
         see through an attribute value and refuses the whole thing as a
         name nobody translated. In the slot it is an ordinary echo. --}}
    <x-page-header>
        <x-slot:title>{{ __('كشوف الحساب') }}</x-slot:title>
        <x-slot:description>
            {{ __('أنت تعمل الآن في :entity.', ['entity' => $entity?->legal_name]) }}
        </x-slot:description>
    </x-page-header>

    @if ($party === null)
        {{-- The picker. Not an error: arriving with no party is how anybody
             reaches this page from a menu. --}}
        <section class="mt-8 rounded-tile border border-outline bg-surface p-6">
            <h2 class="text-title font-semibold text-on-surface">{{ __('اختر عميلًا أو موردًا') }}</h2>
            <p class="mt-2 max-w-2xl text-label text-on-surface-muted">
                {{ __('كشف العميل وكشف المورد مستندان مختلفان: الأول يُقرأ من فواتير المبيعات مقابل الذمم المدينة، والثاني من فواتير الموردين مقابل الذمم الدائنة. لا يوجد خيار افتراضي معقول بينهما، فالطرف الذي يبيع ويشتري له كشفان.') }}
            </p>

            <form method="GET" class="mt-5 flex flex-wrap items-end gap-3">
                <div class="min-w-64 grow">
                    <label for="q" class="block text-label font-medium text-on-surface">{{ __('ابحث بالاسم أو بالرمز') }}</label>
                    <input type="search" name="q" id="q" value="{{ $term }}" autocomplete="off"
                           class="mt-2 block w-full rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-label text-on-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                </div>

                <x-button type="submit" variant="outline">{{ __('ابحث') }}</x-button>
            </form>

            @if ($candidates === [])
                <p class="mt-5 text-label text-on-surface-muted">
                    @if ($term === '')
                        {{ __('لا عملاء ولا موردين مسجّلين في هذه المساحة بعد.') }}
                    @else
                        {{ __('لا يطابق ما كتبته أي عميل أو مورد.') }}
                    @endif
                </p>
            @else
                <div class="mt-5 overflow-x-auto">
                    <table class="w-full text-start text-label">
                        <caption class="sr-only">{{ __('العملاء والموردون المطابقون، ولكل منهم الكشف المتاح له') }}</caption>
                        <thead>
                            <tr class="border-b border-outline text-on-surface-muted">
                                <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('الرمز') }}</th>
                                <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('الاسم النظامي') }}</th>
                                <th scope="col" class="py-2 text-start font-medium">{{ __('الكشف') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-subtle">
                            @foreach ($candidates as $candidate)
                                @php
                                    $candidateId = (string) $candidate->getKey();
                                    $candidateCode = (string) $candidate->code;
                                    $candidateName = (string) $candidate->legal_name;
                                    $customerUrl = request()->fullUrlWithQuery(['party' => $candidateId, 'side' => 'customer', 'q' => null]);
                                    $supplierUrl = request()->fullUrlWithQuery(['party' => $candidateId, 'side' => 'supplier', 'q' => null]);
                                @endphp
                                <tr>
                                    <th scope="row" class="py-2 pe-4 text-start font-medium text-on-surface">
                                        <span dir="ltr" class="figure">{{ $candidateCode }}</span>
                                    </th>
                                    <td class="py-2 pe-4 text-on-surface">{{ $candidateName }}</td>
                                    <td class="py-2">
                                        <span class="flex flex-wrap gap-x-4 gap-y-1">
                                            @if ($candidate->is_customer)
                                                <a href="{{ $customerUrl }}"
                                                   class="rounded-sm text-link underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                                                    {{ __('كشف عميل') }}
                                                </a>
                                            @endif
                                            @if ($candidate->is_supplier)
                                                <a href="{{ $supplierUrl }}"
                                                   class="rounded-sm text-link underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                                                    {{ __('كشف مورد') }}
                                                </a>
                                            @endif
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($more)
                    <p class="mt-3 text-caption text-on-surface-muted">
                        {{ __('هناك أطراف أكثر مما تُعرض هنا. ضيّق البحث بالاسم أو بالرمز.') }}
                    </p>
                @endif
            @endif
        </section>
    @else
        @php
            $sideName = $sideNames[$side] ?? $side;
            $sideBasis = $sideBases[$side] ?? '';
            $partyName = (string) $party->legal_name;
            $partyCode = (string) $party->code;
            $broughtForward = $figure($brought_forward);
            $closingFigure = $figure($closing);
            $openTotalFigure = $figure($open_total);
            $otherSideName = $other_side === null ? null : ($sideNames[$other_side] ?? $other_side);
            $otherSideUrl = $other_side === null
                ? null
                : request()->fullUrlWithQuery(['side' => $other_side, 'currency' => null]);
            $pickerUrl = request()->fullUrlWithQuery(['party' => null, 'side' => null, 'currency' => null]);
        @endphp

        <div class="mt-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-title font-semibold text-on-surface">{{ $partyName }}</h2>
                <p class="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-1 text-label text-on-surface-muted">
                    <span dir="ltr" class="figure">{{ $partyCode }}</span>
                    <span>{{ $sideName }}</span>
                    <span class="text-caption">{{ $sideBasis }}</span>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-label">
                @if ($otherSideUrl !== null)
                    <a href="{{ $otherSideUrl }}"
                       class="rounded-sm text-link underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                        {{ __('اعرض :statement لهذا الطرف', ['statement' => $otherSideName]) }}
                    </a>
                @endif
                <a href="{{ $pickerUrl }}"
                   class="rounded-sm text-link underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                    {{ __('اختر طرفًا آخر') }}
                </a>
            </div>
        </div>

        {{-- The period and the currency, as a GET form: it reads, and the URL
             it produces is one somebody can keep or send. --}}
        <form method="GET" class="mt-4 flex flex-wrap items-end gap-3 rounded-card border border-outline bg-surface p-4">
            <input type="hidden" name="party" value="{{ (string) $party->getKey() }}">
            <input type="hidden" name="side" value="{{ $side }}">

            <div>
                <label for="from" class="block text-label font-medium text-on-surface">{{ __('من تاريخ') }}</label>
                <input type="date" name="from" id="from" value="{{ $from }}"
                       class="mt-2 block rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-label text-on-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
            </div>

            <div>
                <label for="as_of" class="block text-label font-medium text-on-surface">{{ __('حتى تاريخ') }}</label>
                <input type="date" name="as_of" id="as_of" value="{{ $as_of }}"
                       class="mt-2 block rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-label text-on-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
            </div>

            <div>
                <label for="currency" class="block text-label font-medium text-on-surface">{{ __('العملة') }}</label>
                <select name="currency" id="currency"
                        class="mt-2 block rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-label text-on-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                    @if ($currencies === [])
                        <option value="{{ $currency }}" selected>{{ $currency }}</option>
                    @else
                        @foreach ($currencies as $option)
                            <option value="{{ $option }}" @selected($option === $currency)>{{ $option }}</option>
                        @endforeach
                    @endif
                </select>
            </div>

            <x-button type="submit" variant="outline">{{ __('اعرض') }}</x-button>
        </form>

        @if ($currencies === [])
            <p class="mt-3 text-caption text-on-surface-muted">
                {{ __('لم يُقيَّد لهذا الطرف أي مستند أو دفعة على هذا الجانب، فالعملة المعروضة هي عملة التعامل الأساسية للشركة والكشف فارغ لأي عملة تختارها.') }}
            </p>
        @elseif (count($currencies) > 1)
            <p class="mt-3 text-caption text-on-surface-muted">
                {{ __('لهذا الطرف تعامل بأكثر من عملة. الكشف يُقرأ بعملة واحدة في كل مرة، لأن جمع العملات في رصيد واحد رقم بلا وحدة.') }}
            </p>
        @endif

        {{-- The four figures of the statement, with the proof beside them. --}}
        <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-6 xl:grid-cols-12">
            <x-bento.tile :heading="$headings['brought_forward']" class="md:col-span-3 xl:col-span-3">
                <x-figure.display :value="$broughtForward" :currency="$currency" size="headline"/>
                <p class="mt-2 text-caption text-on-surface-muted">
                    {{ __('الرصيد في نهاية اليوم السابق لبداية الفترة.') }}
                </p>
                <p class="mt-1 text-caption text-on-surface-subtle">
                    <span dir="ltr" class="figure">{{ $from }}</span>
                </p>
            </x-bento.tile>

            <x-bento.tile :heading="$headings['closing']" class="md:col-span-3 xl:col-span-3">
                <x-figure.display :value="$closingFigure" :currency="$currency" size="headline"/>
                <p class="mt-2 text-caption text-on-surface-muted">
                    {{ __('الرصيد في نهاية الفترة، محسوبًا باستعلام مستقل عن حركات الفترة.') }}
                </p>
                <p class="mt-1 text-caption text-on-surface-subtle">
                    <span dir="ltr" class="figure">{{ $as_of }}</span>
                </p>
            </x-bento.tile>

            <x-bento.tile :heading="$headings['open_total']" class="md:col-span-6 xl:col-span-6">
                <x-figure.display :value="$openTotalFigure" :currency="$currency" size="headline"/>
                <p class="mt-2 text-caption text-on-surface-muted">{{ $open_why }}</p>
            </x-bento.tile>
        </div>

        {{-- The reconciliation line: brought forward plus movement equals
             closing, said either way. --}}
        <section class="mt-4 rounded-card border p-4 {{ $adds_up ? 'border-success bg-success-subtle' : 'border-danger bg-danger-subtle' }}">
            <h3 class="flex items-center gap-2 text-label font-medium {{ $adds_up ? 'text-on-success-subtle' : 'text-on-danger-subtle' }}">
                <span aria-hidden="true">{{ $adds_up ? '●' : '!' }}</span>
                @if ($adds_up)
                    {{ __('الرصيد المنقول زائد الحركات يساوي رصيد الإقفال') }}
                @else
                    {{ __('الرصيد المنقول زائد الحركات لا يساوي رصيد الإقفال') }}
                @endif
            </h3>
            <p class="mt-1 text-caption {{ $adds_up ? 'text-on-success-subtle' : 'text-on-danger-subtle' }}">{{ $movement_why }}</p>
        </section>

        {{-- Balance-forward: how we got from one figure to the other. --}}
        <section class="mt-6 rounded-tile border border-outline bg-surface p-5">
            <h3 class="text-title font-semibold text-on-surface">{{ __('حركة الفترة') }}</h3>

            @if ($movements === [])
                <p class="mt-3 text-label text-on-surface-muted">{{ __('لا حركة على هذا الطرف خلال الفترة.') }}</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-start text-label">
                        <caption class="sr-only">{{ __('حركات الفترة مرتّبة بالتاريخ، ومعها الرصيد الجاري بعد كل حركة') }}</caption>
                        <thead>
                            <tr class="border-b border-outline text-on-surface-muted">
                                <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('التاريخ') }}</th>
                                <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('البيان') }}</th>
                                <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('المرجع') }}</th>
                                <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('المبلغ') }}</th>
                                <th scope="col" class="py-2 text-start font-medium">{{ __('الرصيد الجاري') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-subtle">
                            <tr>
                                <td dir="ltr" class="figure py-2 pe-4 text-on-surface-muted">{{ $from }}</td>
                                <th scope="row" class="py-2 pe-4 text-start font-medium text-on-surface">{{ __('الرصيد المنقول') }}</th>
                                <td class="py-2 pe-4"></td>
                                <td class="py-2 pe-4"></td>
                                <td dir="ltr" class="figure py-2 font-semibold text-on-surface">{{ $broughtForward }}</td>
                            </tr>
                            @foreach ($movements as $line)
                                @php
                                    $lineAmount = $figure($line['amount']);
                                    $lineRunning = $figure($line['running']);
                                @endphp
                                <tr>
                                    <td dir="ltr" class="figure py-2 pe-4 text-on-surface-muted">{{ $line['on'] }}</td>
                                    <th scope="row" class="py-2 pe-4 text-start font-normal text-on-surface">{{ $line['label'] }}</th>
                                    <td dir="ltr" class="figure py-2 pe-4 text-on-surface">{{ $line['reference'] }}</td>
                                    <td dir="ltr" class="figure py-2 pe-4 text-on-surface">{{ $lineAmount }}</td>
                                    <td dir="ltr" class="figure py-2 text-on-surface">{{ $lineRunning }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-outline-strong">
                                <td dir="ltr" class="figure py-2 pe-4 text-on-surface-muted">{{ $as_of }}</td>
                                <th scope="row" class="py-2 pe-4 text-start font-medium text-on-surface">{{ __('رصيد الإقفال') }}</th>
                                <td class="py-2 pe-4"></td>
                                <td class="py-2 pe-4"></td>
                                <td dir="ltr" class="figure py-2 font-semibold text-on-surface">{{ $closingFigure }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>

        {{-- Open-item: what is still outstanding, with its age. --}}
        <section class="mt-6 rounded-tile border border-outline bg-surface p-5">
            <h3 class="text-title font-semibold text-on-surface">{{ __('المستندات غير المسدَّدة') }}</h3>

            @if ($documents === [])
                <p class="mt-3 text-label text-on-surface-muted">{{ __('لا مستند غير مسدَّد على هذا الطرف في هذا التاريخ.') }}</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-start text-label">
                        <caption class="sr-only">{{ __('المستندات غير المسدَّدة، ولكل منها تاريخ الإصدار وتاريخ الاستحقاق والمتبقي وعمر التأخير') }}</caption>
                        <thead>
                            <tr class="border-b border-outline text-on-surface-muted">
                                <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('البيان') }}</th>
                                <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('المرجع') }}</th>
                                <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('تاريخ الإصدار') }}</th>
                                <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('تاريخ الاستحقاق') }}</th>
                                <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('أيام التأخير') }}</th>
                                <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('القيمة') }}</th>
                                <th scope="col" class="py-2 text-start font-medium">{{ __('المتبقي') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-subtle">
                            @foreach ($documents as $document)
                                @php
                                    $documentGross = $figure($document['gross']);
                                    $documentOpen = $figure($document['open']);
                                @endphp
                                <tr>
                                    <th scope="row" class="py-2 pe-4 text-start font-normal text-on-surface">
                                        {{ $document['label'] }}
                                        @if ($document['disputed'])
                                            {{-- A disputed document stays ON the statement,
                                                 labelled. Removing it would make the statement
                                                 disagree with the control account, and hiding
                                                 the dispute makes the customer raise it again
                                                 with somebody else. --}}
                                            <span class="ms-2 inline-flex items-center rounded-full bg-warning-subtle px-2 py-0.5 text-caption font-medium text-on-warning-subtle">
                                                {{ __('محل اعتراض') }}
                                            </span>
                                        @endif
                                    </th>
                                    <td dir="ltr" class="figure py-2 pe-4 text-on-surface">{{ $document['reference'] }}</td>
                                    <td dir="ltr" class="figure py-2 pe-4 text-on-surface-muted">{{ $document['issued_on'] }}</td>
                                    <td dir="ltr" class="figure py-2 pe-4 text-on-surface-muted">{{ $document['due_on'] ?? '—' }}</td>
                                    <td dir="ltr" class="figure py-2 pe-4 text-on-surface">{{ $document['days_overdue'] ?? '—' }}</td>
                                    <td dir="ltr" class="figure py-2 pe-4 text-on-surface-muted">{{ $documentGross }}</td>
                                    <td dir="ltr" class="figure py-2 font-semibold text-on-surface">{{ $documentOpen }}</td>
                                </tr>
                                @if ($document['note'])
                                    <tr>
                                        <td colspan="7" class="pb-2 text-caption text-on-surface-muted">{{ $document['note'] }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- Credits and cash, labelled rather than netted. --}}
        <section class="mt-6 rounded-tile border border-outline bg-surface p-5">
            <h3 class="text-title font-semibold text-on-surface">{{ __('نقد وأرصدة دائنة لم تُخصَّص') }}</h3>

            @if ($credits === [])
                <p class="mt-3 text-label text-on-surface-muted">
                    {{ __('لا نقد ولا رصيد دائن معلّق على هذا الطرف. كل ما وصل خُصّص إلى مستند.') }}
                </p>
            @else
                <ul class="mt-4 divide-y divide-outline-subtle">
                    @foreach ($credits as $credit)
                        @php
                            $creditKind = $creditKinds[$credit['kind']] ?? $credit['kind'];
                            $creditAmount = $figure($credit['open']);
                        @endphp
                        <li class="flex flex-wrap items-baseline justify-between gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="min-w-0">
                                <p class="text-label text-on-surface">{{ $credit['label'] }}</p>
                                <p class="mt-0.5 text-caption text-on-surface-muted">{{ $creditKind }}</p>
                                @if ($credit['note'])
                                    <p class="mt-1 max-w-2xl text-caption text-on-surface-muted">{{ $credit['note'] }}</p>
                                @endif
                            </div>
                            <span dir="ltr" class="figure shrink-0 text-title font-semibold text-figure">{{ $creditAmount }}</span>
                        </li>
                    @endforeach
                </ul>

                <p class="mt-3 text-caption text-on-surface-muted">
                    {{ __('تُدرج بسطرها ولا تُطرح من أقدم فاتورة. الكشف الذي يطرحها بصمت يدعو إلى الخلاف الذي قُصد منعه: يتصل الطرف بشأن فاتورة يعتقد أنه سدّدها، ولا يرى أحد أي دفعة قرّر النظام استخدامها.') }}
                </p>
            @endif
        </section>

        {{-- The entity-wide tie, which is the other half of the proof. --}}
        <section class="mt-6 rounded-card border p-4 {{ $tie['reconciles'] ? 'border-outline bg-surface-container-low' : 'border-warning bg-warning-subtle' }}">
            @php
                $tieSubsidiary = $figure($tie['subsidiary']);
                $tieControl = $figure($tie['control']);
                $tieDifference = $figure($tie['difference']);
            @endphp

            <h3 class="text-label font-medium {{ $tie['reconciles'] ? 'text-on-surface' : 'text-on-warning-subtle' }}">
                {{ __('مطابقة الأستاذ المساعد مع حساب المراقبة') }}
            </h3>

            <dl class="mt-3 grid grid-cols-1 gap-3 text-caption sm:grid-cols-3">
                <div>
                    <dt class="{{ $tie['reconciles'] ? 'text-on-surface-muted' : 'text-on-warning-subtle' }}">{{ __('مجموع المستندات غير المسدَّدة لكل الأطراف') }}</dt>
                    <dd dir="ltr" class="figure mt-0.5 font-semibold {{ $tie['reconciles'] ? 'text-on-surface' : 'text-on-warning-subtle' }}">{{ $tieSubsidiary }}</dd>
                </div>
                <div>
                    <dt class="{{ $tie['reconciles'] ? 'text-on-surface-muted' : 'text-on-warning-subtle' }}">{{ __('حساب المراقبة في دفتر اليومية') }}</dt>
                    <dd dir="ltr" class="figure mt-0.5 font-semibold {{ $tie['reconciles'] ? 'text-on-surface' : 'text-on-warning-subtle' }}">{{ $tieControl }}</dd>
                </div>
                <div>
                    <dt class="{{ $tie['reconciles'] ? 'text-on-surface-muted' : 'text-on-warning-subtle' }}">{{ __('الفرق') }}</dt>
                    <dd dir="ltr" class="figure mt-0.5 font-semibold {{ $tie['reconciles'] ? 'text-on-surface' : 'text-on-warning-subtle' }}">{{ $tieDifference }}</dd>
                </div>
            </dl>

            <p class="mt-3 text-caption {{ $tie['reconciles'] ? 'text-on-surface-muted' : 'text-on-warning-subtle' }}">{{ $tie['why'] }}</p>
        </section>
    @endif
@endsection
