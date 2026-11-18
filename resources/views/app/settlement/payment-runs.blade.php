@extends('layouts.app', ['title' => __('مسيّرات الدفع'), 'wide' => true])

{{--
    The payment runs - PF-174, PF-175, PF-180.

    One card per run, and the state is carried by three things at once: a
    word, a glyph and an edge on the inline-start side. Not by colour
    alone - a released run and a draft run must be unmistakable to
    somebody who cannot tell the two colours apart, because the
    difference between them is whether the money has left.

    There is no release control anywhere on this page, and that is the
    design. A release needs a prepared run, a releaser who is not the
    preparer, the duty pairs per beneficiary, a fresh second factor for
    this run, and proof that nothing moved since preparation. A button
    here could carry none of those, so the only button it could offer is
    the one that skips all five.

    The preparer and the releaser are printed side by side because the
    one thing anybody checks at a glance is that they are two different
    people. An accepted duplicate warning is printed with the sentence
    somebody signed, because a run released over an accepted warning is
    the one an auditor asks about.
--}}

@section('content')
    @php
        /*
         | The display form of an amount: at the currency's exponent, and
         | the ACCOUNTING negative - parentheses, never a bare minus.
         */
        $figure = static fn (\App\Support\Money\Money $money): string => $money->isNegative()
            ? '('.$money->abs()->toPresentation().')'
            : (string) $money->toPresentation();

        /*
         | Three states, because the table permits exactly three.
         |
         | `payment_runs_state_is_known` names draft, prepared and
         | released, and the trigger permits three transitions between
         | them. What happens after the release - the file going out, the
         | bank accepting each instruction, the payment settling - is
         | recorded against the file and the instructions, never as a
         | further state of the run, because a run whose state could
         | advance after release would be an instruction that can still
         | change. A word on screen that nothing can ever set reads as a
         | step somebody forgot.
         */
        $stateNames = [
            'draft' => __('مسودة'),
            'prepared' => __('محضَّرة'),
            'released' => __('مُرسَلة'),
        ];

        $stateMeanings = [
            'draft' => __('اختيار لم يراجعه أحد. لا يمكن إرسالها قبل تحضيرها.'),
            'prepared' => __('محضَّرة وتنتظر شخصًا آخر. المال لم يخرج.'),
            'released' => __('خرج المال. لا رجوع عن هذه الحالة: الخطأ يُصحَّح بمسيّرة جديدة وطلب استرجاع.'),
        ];

        $stateGlyphs = [
            'draft' => '◻',
            'prepared' => '◐',
            'released' => '●',
        ];

        /*
         | The edge, the chip and the words, all three.
         |
         | A released run reads differently from a draft at a glance, at
         | a distance, in grayscale and to somebody who cannot tell green
         | from grey.
         */
        $stateEdges = [
            'draft' => 'border-s-4 border-draft-stripe',
            'prepared' => 'border-s-4 border-warning',
            'released' => 'border-s-4 border-success',
        ];

        $stateChips = [
            'draft' => 'bg-surface-container-high text-on-surface-muted',
            'prepared' => 'bg-warning-subtle text-on-warning-subtle',
            'released' => 'bg-success-subtle text-on-success-subtle',
        ];

        $warningReasons = [
            'same_amount_same_party_in_window' => __('نفس المبلغ لنفس الطرف خلال فترة قريبة'),
            'same_amount_another_entity' => __('نفس المبلغ لنفس الطرف من شركة أخرى في المجموعة'),
            'same_amount_already_released' => __('نفس المبلغ في مسيّرة أُرسلت بالفعل'),
            'same_amount_already_paid' => __('نفس المبلغ في دفعة سُجّلت بالفعل'),
        ];
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-headline font-bold text-on-surface">{{ __('مسيّرات الدفع') }}</h1>
            <p class="mt-1 text-label text-on-surface-muted">
                {{ __('أنت تعمل الآن في :entity.', ['entity' => $entity?->legal_name]) }}
            </p>
        </div>

        <p class="max-w-md text-caption text-on-surface-muted">
            {{ __('هذه الصفحة تقرأ ولا ترسل. الإرسال يحتاج شخصًا غير من حضّر، وفحص تعارض المهام، وعاملًا ثانيًا جديدًا لهذه المسيّرة، وإثباتًا أن شيئًا لم يتغيّر بعد التحضير - وزرٌّ في قائمة لا يحمل شيئًا من ذلك.') }}
        </p>
    </div>

    {{-- The filter is a GET form: it reads, and the URL it produces is one
         somebody can keep. --}}
    <form method="GET" class="mt-6 flex flex-wrap items-end gap-3 rounded-card border border-outline bg-surface p-4">
        <div>
            <label for="state" class="block text-label font-medium text-on-surface">{{ __('الحالة') }}</label>
            <select name="state" id="state"
                    class="mt-2 block rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-label text-on-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                <option value="">{{ __('كل الحالات') }}</option>
                @foreach ($states as $value)
                    @php
                        /*
                         | The block form of the PHP directive, not the
                         | single-expression one.
                         |
                         | Blade extracts raw PHP with a non-greedy pattern
                         | that pairs an opening directive with the next
                         | closing one. A single-expression directive in a
                         | file that also has a block therefore pairs with
                         | THAT block's close and swallows everything in
                         | between, and the template fails to compile with
                         | "unexpected endif" pointing at a line nowhere
                         | near the cause. Measured here, on this line.
                         */
                        $stateName = $stateNames[$value] ?? $value;
                    @endphp
                    <option value="{{ $value }}" @selected($state === $value)>{{ $stateName }}</option>
                @endforeach
            </select>
        </div>

        <x-button type="submit" variant="outline">{{ __('اعرض') }}</x-button>
    </form>

    @if ($totals !== [])
        {{-- Totals per currency, never across them: one figure over a riyal
             run and a euro run is a number with no unit, and it is the kind
             that gets quoted in a meeting. --}}
        <section class="mt-6 rounded-card border border-outline bg-surface p-4">
            <h2 class="text-label font-medium text-on-surface">{{ __('إجماليات المعروض، بحسب العملة') }}</h2>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-start text-label">
                    <caption class="sr-only">{{ __('إجمالي المسيّرات المعروضة لكل عملة، وما خرج منها فعلًا') }}</caption>
                    <thead>
                        <tr class="border-b border-outline text-on-surface-muted">
                            <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('العملة') }}</th>
                            <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('عدد المسيّرات') }}</th>
                            <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('عدد التحويلات') }}</th>
                            <th scope="col" class="py-2 pe-4 text-start font-medium">{{ __('الصافي المعروض') }}</th>
                            <th scope="col" class="py-2 text-start font-medium">{{ __('الصافي المُرسَل') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-subtle">
                        @foreach ($totals as $total)
                            @php
                                $totalNet = $figure($total['net']);
                                $totalReleased = $figure($total['released']);
                            @endphp
                            <tr>
                                <th scope="row" class="py-2 pe-4 text-start font-medium text-on-surface">
                                    <span class="figure">{{ $total['currency'] }}</span>
                                </th>
                                <td dir="ltr" class="figure py-2 pe-4 text-on-surface">{{ $total['runs'] }}</td>
                                <td dir="ltr" class="figure py-2 pe-4 text-on-surface">{{ $total['instructions'] }}</td>
                                <td dir="ltr" class="figure py-2 pe-4 text-on-surface">{{ $totalNet }}</td>
                                <td dir="ltr" class="figure py-2 font-semibold text-on-surface">{{ $totalReleased }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-caption text-on-surface-muted">
                {{ __('«المُرسَل» مفصول عن «المعروض» لأن المسودة اختيار ما زال يُحرَّر والمُرسَلة مال خرج، وجمعهما في رقم واحد يعرض نقدًا كأنه صُرف ولم يعتمده أحد.') }}
            </p>
        </section>
    @endif

    @if ($runs === [])
        <div class="mt-6 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
            @if ($state === null)
                {{ __('لا توجد مسيّرات دفع في هذه الشركة بعد.') }}
            @else
                {{ __('لا توجد مسيّرات في هذه الحالة.') }}
            @endif
        </div>
    @else
        <ol class="mt-6 space-y-4">
            @foreach ($runs as $run)
                @php
                    // Not `$state`: that is the page's filter, and shadowing it
                    // inside the loop is the kind of reuse that survives review
                    // and then breaks the control above when somebody moves it.
                    $runState = $run['state'];
                    $stateName = $stateNames[$runState] ?? $runState;
                    $stateMeaning = $stateMeanings[$runState] ?? '';
                    $stateGlyph = $stateGlyphs[$runState] ?? '○';
                    $edge = $stateEdges[$runState] ?? 'border-s-4 border-outline';
                    $chip = $stateChips[$runState] ?? 'bg-surface-container-high text-on-surface-muted';
                    $currency = $run['currency'];
                    $net = $figure($run['net']);
                    $gross = $figure($run['gross']);
                    $discount = $figure($run['discount']);
                @endphp

                <li class="rounded-tile border border-outline bg-surface p-5 shadow-e1 {{ $edge }}">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="flex flex-wrap items-center gap-3 text-title font-semibold text-on-surface">
                                <span dir="ltr" class="figure">{{ $run['number'] }}</span>
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-caption font-medium {{ $chip }}">
                                    <span aria-hidden="true">{{ $stateGlyph }}</span>{{ $stateName }}
                                </span>
                            </h2>
                            <p class="mt-1 text-caption text-on-surface-muted">{{ $stateMeaning }}</p>
                        </div>

                        <div class="text-end">
                            <x-figure.display :value="$net" :currency="$currency" size="title"/>
                            <p class="mt-1 text-caption text-on-surface-muted">{{ __('الصافي المستحق الدفع') }}</p>
                        </div>
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 text-label sm:grid-cols-4">
                        <div>
                            <dt class="text-caption text-on-surface-muted">{{ __('تاريخ الدفع') }}</dt>
                            <dd dir="ltr" class="figure mt-0.5 text-on-surface">{{ $run['pay_on'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-caption text-on-surface-muted">{{ __('الإجمالي قبل الخصم') }}</dt>
                            <dd dir="ltr" class="figure mt-0.5 text-on-surface">{{ $gross }}</dd>
                        </div>
                        <div>
                            <dt class="text-caption text-on-surface-muted">{{ __('خصم السداد المبكر') }}</dt>
                            <dd dir="ltr" class="figure mt-0.5 text-on-surface">{{ $discount }}</dd>
                        </div>
                        <div>
                            <dt class="text-caption text-on-surface-muted">{{ __('العملة') }}</dt>
                            <dd dir="ltr" class="figure mt-0.5 text-on-surface">{{ $currency }}</dd>
                        </div>
                    </dl>

                    {{-- Items and instructions are different things: four
                         invoices to one supplier are four items and one
                         transfer, and a single "payments" figure would be
                         whichever of the two the author happened to pick. --}}
                    <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 text-label sm:grid-cols-4">
                        <div>
                            <dt class="text-caption text-on-surface-muted">{{ __('عدد التحويلات') }}</dt>
                            <dd dir="ltr" class="figure mt-0.5 text-on-surface">{{ $run['instruction_count'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-caption text-on-surface-muted">{{ __('عدد الفواتير المختارة') }}</dt>
                            <dd dir="ltr" class="figure mt-0.5 text-on-surface">{{ $run['item_count'] }}</dd>
                        </div>
                    </dl>

                    {{-- Maker and checker, side by side, because the only
                         thing to check at a glance is that they are two
                         different people. --}}
                    <dl class="mt-4 grid grid-cols-1 gap-3 text-label sm:grid-cols-2">
                        <div class="rounded-card border border-outline-subtle bg-surface-container-low p-3">
                            <dt class="text-caption text-on-surface-muted">{{ __('حضّرها') }}</dt>
                            <dd class="mt-0.5 text-on-surface">
                                {{ $run['preparer'] ?? __('لا أحد بعد') }}
                                @if ($run['prepared_at'])
                                    <span dir="ltr" class="figure block text-caption text-on-surface-muted">{{ $run['prepared_at'] }}</span>
                                @endif
                            </dd>
                        </div>
                        <div class="rounded-card border border-outline-subtle bg-surface-container-low p-3">
                            <dt class="text-caption text-on-surface-muted">{{ __('أرسلها') }}</dt>
                            <dd class="mt-0.5 text-on-surface">
                                {{ $run['releaser'] ?? __('لم تُرسل') }}
                                @if ($run['released_at'])
                                    <span dir="ltr" class="figure block text-caption text-on-surface-muted">{{ $run['released_at'] }}</span>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    @if ($run['preparer'] !== null && $run['releaser'] !== null)
                        <p class="mt-2 text-caption text-on-surface-muted">
                            {{ __('من يحضّر لا يرسل أبدًا، وهذا هو جوهر الضابط: ترتيب جهة المال ثم إرساله هو أعلى احتيال قيمةً في المنتج.') }}
                        </p>
                    @endif

                    {{-- The file, where one was generated. --}}
                    @if ($run['file_reference'] !== null)
                        <div class="mt-4 rounded-card border border-outline-subtle bg-surface-container-low p-3">
                            <h3 class="text-label font-medium text-on-surface">{{ __('ملف التعليمات المُولَّد') }}</h3>
                            <dl class="mt-2 flex flex-wrap items-baseline gap-x-5 gap-y-1 text-caption">
                                <div class="flex items-baseline gap-2">
                                    <dt class="text-on-surface-muted">{{ __('مرجع الرسالة') }}</dt>
                                    <dd dir="ltr" class="figure text-on-surface">{{ $run['file_reference'] }}</dd>
                                </div>
                                @if ($run['file_scheme'])
                                    <div class="flex items-baseline gap-2">
                                        <dt class="text-on-surface-muted">{{ __('دفتر قواعد البنك') }}</dt>
                                        <dd dir="ltr" class="figure text-on-surface">{{ $run['file_scheme'] }}</dd>
                                    </div>
                                @endif
                                @if ($run['file_generated_at'])
                                    <div class="flex items-baseline gap-2">
                                        <dt class="text-on-surface-muted">{{ __('وُلّد في') }}</dt>
                                        <dd dir="ltr" class="figure text-on-surface">{{ $run['file_generated_at'] }}</dd>
                                    </div>
                                @endif
                                <div class="flex items-baseline gap-2">
                                    <dt class="text-on-surface-muted">{{ __('سُلّم للبنك') }}</dt>
                                    <dd class="text-on-surface">
                                        {{ $run['file_downloaded_at'] ?? __('لم يُسلَّم بعد') }}
                                    </dd>
                                </div>
                            </dl>

                            @if (! $run['file_matches_release'])
                                <p class="mt-2 text-caption text-on-warning-subtle">
                                    {{ __('بصمة هذا الملف لا تطابق ما اعتُمد عند الإرسال. الملف الذي خرج للبنك يجب أن يكون ملف ما وافق عليه المُرسِل بالحرف، والمقارنة بصمة ببصمة لا إعادة توليد.') }}
                                </p>
                            @endif
                        </div>
                    @elseif ($run['state'] === 'released')
                        <p class="mt-4 text-caption text-on-surface-muted">
                            {{ __('أُرسلت ولم يُولَّد لها ملف تعليمات بعد.') }}
                        </p>
                    @endif

                    {{-- Duplicate warnings, accepted ones included. --}}
                    @if ($run['warnings'] !== [])
                        <section class="mt-4">
                            <h3 class="text-label font-medium text-on-surface">{{ __('تحذيرات تكرار الدفع') }}</h3>
                            <ul class="mt-2 space-y-2">
                                @foreach ($run['warnings'] as $warning)
                                    @php
                                        $reasonName = $warningReasons[$warning['reason']] ?? $warning['reason'];
                                        $warningAmount = $figure($warning['amount']);
                                        $warningTone = $warning['accepted']
                                            ? 'border-outline bg-surface-container-low'
                                            : 'border-danger bg-danger-subtle';
                                        $warningText = $warning['accepted'] ? 'text-on-surface' : 'text-on-danger-subtle';
                                    @endphp
                                    <li class="rounded-card border p-3 text-caption {{ $warningTone }}">
                                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                                            <p class="font-medium {{ $warningText }}">
                                                <span aria-hidden="true">!</span>
                                                {{ $reasonName }}
                                            </p>
                                            <p class="inline-flex items-baseline gap-2">
                                                <span dir="ltr" class="figure font-semibold {{ $warningText }}">{{ $warningAmount }}</span>
                                                <span class="figure {{ $warningText }}">{{ $warning['currency'] }}</span>
                                            </p>
                                        </div>

                                        @if ($warning['party'])
                                            <p class="mt-1 {{ $warningText }}">{{ $warning['party'] }}</p>
                                        @endif

                                        <p class="mt-1 {{ $warningText }}">{{ $warning['detail'] }}</p>

                                        @if ($warning['accepted'])
                                            {{-- The sentence somebody signed, verbatim. A run
                                                 released over an accepted warning is the one an
                                                 auditor asks about, and the answer is this text
                                                 and the name beside it. --}}
                                            <dl class="mt-2 space-y-1">
                                                <div class="flex items-baseline gap-2">
                                                    <dt class="text-on-surface-muted">{{ __('قبلها') }}</dt>
                                                    <dd class="text-on-surface">{{ $warning['accepted_by'] ?? __('غير مذكور') }}</dd>
                                                </div>
                                                @if ($warning['accepted_at'])
                                                    <div class="flex items-baseline gap-2">
                                                        <dt class="text-on-surface-muted">{{ __('قُبلت في') }}</dt>
                                                        <dd dir="ltr" class="figure text-on-surface">{{ $warning['accepted_at'] }}</dd>
                                                    </div>
                                                @endif
                                                <div>
                                                    <dt class="text-on-surface-muted">{{ __('السبب الذي قُبل به') }}</dt>
                                                    <dd class="mt-0.5 text-on-surface">{{ $warning['acceptance'] }}</dd>
                                                </div>
                                            </dl>
                                        @else
                                            <p class="mt-2 font-medium text-on-danger-subtle">
                                                {{ __('لم يقبله أحد بعد، والإرسال موقوف حتى يقول شخص ما إن كان تكرارًا أو لا ويضع اسمه على ذلك.') }}
                                            </p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                </li>
            @endforeach
        </ol>

        @if (count($runs) >= $limit)
            <p class="mt-4 text-caption text-on-surface-muted">
                {{ __('تُعرض أحدث :limit مسيّرة بحسب تاريخ الدفع. القائمة تتوقف هنا، ولا تعني أن ما قبلها غير موجود.', ['limit' => $limit]) }}
            </p>
        @endif
    @endif
@endsection
