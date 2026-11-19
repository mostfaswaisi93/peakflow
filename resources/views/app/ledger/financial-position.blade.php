@extends('layouts.app', ['title' => __('قائمة المركز المالي')])

{{--
    The statement of financial position - PF-184.

    ## The declaration is a banner, not a column

    An unclassified balance is the explanation for the difference in almost
    every case, and it is the one a person can act on. Putting it in a cell an
    export might drop, or a colour a printout loses, hides the only thing on
    the page that decides whether the rest of it is true - so it leads the
    page as the sentence the statement wrote itself, and the two unhappy
    forms carry `role="alert"` rather than `role="status"`.

    ## Nothing on this page adds a figure up

    Every amount, every section total, the unclosed result and the totals
    block all arrive from {@see \App\Tenant\Reporting\Services\StatementOfFinancialPosition}
    as decimal strings and are rendered through `Money::format()` - grouped,
    at the currency's own exponent. A total re-summed here would be a second
    answer to a question the statement already answers, and it would agree
    with the first until the day a rounding rule changed - at which point the
    page and the service would both be defensible and only one of them right.

    ## It is not laid out at the Bento width, and the trial balance is

    Four columns, not eight. At `max-w-7xl` the line label and its figure sit
    at opposite ends of a screen's width with nothing between them, and a
    reader tracking across that gap loses the row - which on a statement
    where two adjacent lines differ by one word is how a figure gets read
    against the wrong label.

    ## The accounts live inside the line, not in rows of their own

    A reader who disagrees with a line needs the accounts that made it, and
    `<details>` gives them that with no JavaScript and no second request. They
    cannot be extra table rows - a `<details>` may not wrap a `<tr>` - so they
    are a list inside the line's own cell, with the account's balance beside
    its name rather than in the amount column, which is deliberately reserved
    for the figures that add up to the total.

    ## The PHP here is always a block, never the parenthesised one-liner

    Blade takes a block of PHP by matching forward from its opening directive
    to the next closing one, so a single parenthesised one-liner above a later
    block swallows everything between the two and hands it back as raw PHP.
    The page then prints its own template source, every conditional in the
    swallowed region is left uncompiled, and the closing directive that
    outlives it closes somebody else's - a parse error a long way from its
    cause. It is the same reason no comment in this file writes those two
    directives out in full: the blocks are taken before the comments go.
--}}

@section('content')
    @php
        $locale = app()->getLocale();

        /*
         | The label in the reader's language, and the other one beneath it.
         |
         | Both, always, for the same reason the declaration carries both: a
         | statement of financial position is printed and handed to somebody
         | who may read either language, and a page that rendered only the
         | session's locale drops the other from the paper in their hands.
         */
        $naming = static fn (array $of): array => [
            'primary' => (string) ($locale === 'ar' ? $of['label_ar'] : $of['label_en']),
            'secondary' => (string) ($locale === 'ar' ? $of['label_en'] : $of['label_ar']),
        ];
    @endphp

    <x-page-header :title="__('قائمة المركز المالي')">
        <x-slot:description>
            {{ __('ما تملكه الشركة وما عليها وما يتبقى لأصحابها في تاريخ واحد، مجموعًا من تصنيف دليل الحسابات نفسه لا من أرقام حسابات مكتوبة في التقرير.') }}
        </x-slot:description>
        <x-slot:aside>
            @if ($entity)
                <div class="flex flex-wrap items-center gap-2">
                    <p class="inline-flex items-center gap-2 rounded-full border border-outline bg-surface px-3 py-1 text-caption text-on-surface-muted">
                        {{ $entity->legal_name }} ·
                        {{ __('العملة الوظيفية') }}
                        <span dir="ltr" class="figure font-semibold text-on-surface">{{ $entity->functional_currency }}</span>
                    </p>

                    @if ($statement !== null)
                        <p class="inline-flex items-center gap-2 rounded-full border border-outline bg-surface px-3 py-1 text-caption text-on-surface-muted">
                            {{ __('المركز كما في') }}
                            <span dir="ltr" class="figure font-semibold text-on-surface">{{ $statement['as_at'] }}</span>
                        </p>
                    @endif
                </div>
            @endif
        </x-slot:aside>
    </x-page-header>

    @if ($entity === null)
        <div class="mt-8 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
            {{ __('لم تُحدَّد شركة بعد، ولا مركز مالي بلا شركة.') }}
        </div>
    @elseif ($period === null)
        <div class="mt-8 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
            {{ __('لا توجد فترات محاسبية لهذه الشركة بعد، فلا تاريخ يُقاس عليه مركز.') }}
        </div>
    @else
        {{--
            A GET form, so the statement is a URL.

            A statement somebody can send to their auditor, bookmark, or
            return to with the back button is worth more than a tidier POST:
            the figure being discussed has to be reachable by the person being
            asked about it.

            `no-print`, because on paper a dropdown prints as a box showing
            one of its options and says nothing about why that one. The dates
            both columns were read at are in the column headers, which repeat
            on every printed page, and the basis sentence is under the
            figures - so nothing is lost by leaving the controls on screen.
        --}}
        <form method="GET" class="no-print mt-6 flex flex-wrap items-end gap-3">
            <div class="min-w-0">
                <label for="period" class="block text-label font-medium text-on-surface">{{ __('الفترة المحاسبية') }}</label>
                <select name="period" id="period" dir="ltr"
                        class="mt-1.5 block min-h-11 w-full max-w-full rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-body text-on-surface
                               focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                    @foreach ($periods as $option)
                        <option value="{{ $option->id }}" @selected((string) $option->id === (string) $period->id)>
                            {{ $option->label }} · {{ $option->starts_on->toDateString() }} – {{ $option->ends_on->toDateString() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="comparative" class="block text-label font-medium text-on-surface">{{ __('فترة المقارنة') }}</label>
                <select name="comparative" id="comparative" dir="ltr" aria-describedby="why-comparative"
                        class="min-h-(--control-height) mt-1.5 block rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-body text-on-surface
                               focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                    {{--
                        "No comparative" carries a word rather than an empty
                        value: a GET form submits the empty option as
                        `comparative=`, which cannot be told apart from a
                        parameter nobody set, so choosing it would be undone
                        by the default the next time the reader pressed the
                        button.
                    --}}
                    <option value="none" @selected($comparative === null)>{{ __('بلا عمود مقارنة') }}</option>
                    @foreach ($comparatives as $option)
                        <option value="{{ $option->id }}" @selected($comparative !== null && (string) $option->id === (string) $comparative->id)>
                            {{ $option->label }} · {{ $option->ends_on->toDateString() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <x-button type="submit">{{ __('اعرض') }}</x-button>
        </form>

        <p id="why-comparative" class="no-print mt-2 max-w-2xl text-caption text-on-surface-muted">
            {{ __('فترة المقارنة تنتهي قبل الفترة المعروضة دائمًا، والافتراضي هو الفترة المقابلة من السنة السابقة. إن لم توجد فترة أسبق فلا عمود مقارنة أصلاً.') }}
        </p>

        @if ($unknownPeriod)
            <p class="rounded-card border border-outline bg-surface px-4 py-3 text-label text-on-surface-muted">
                {{ __('الفترة المطلوبة في الرابط ليست فترة في هذه الشركة، وما يظهر أدناه هو آخر فترة فيها قيود.') }}
            </p>
        @endif

        @if ($unknownComparative)
            <p class="rounded-card border border-outline bg-surface px-4 py-3 text-label text-on-surface-muted">
                {{ __('فترة المقارنة المطلوبة في الرابط ليست فترة أسبق من الفترة المعروضة، وعمود المقارنة أدناه هو المقابل من السنة السابقة إن وُجد.') }}
            </p>
        @endif

        @if ($refusal !== null)
            {{--
                The statement's refusal, in its own words.

                It names what is wrong and what a reader would otherwise
                wrongly conclude, and a paraphrase here would be a second
                explanation that drifts from the first the next time either is
                edited. A reader told "unavailable" opens a support ticket; a
                reader told that the comparative column is not earlier changes
                the column.
            --}}
            <div role="alert" tabindex="-1"
                 class="mt-6 rounded-card border border-danger bg-danger-subtle px-4 py-3 text-label text-on-danger-subtle">
                <h2 class="font-medium">{{ __('لم تُصدَر القائمة') }}</h2>
                <p dir="ltr" lang="en" class="mt-2 text-start">{{ $refusal }}</p>
            </div>
        @elseif ($statement !== null)
            @php
                $currency = (string) $statement['currency'];
                $declaration = $statement['declaration'];
                $severity = (string) $declaration['severity'];
                $totals = $statement['totals'];
                $result = $statement['result_for_the_period'];
                $hasComparative = $statement['comparative_as_at'] !== null;
                $span = $hasComparative ? 4 : 3;

                /*
                 | One amount, read as a string and rendered at the
                 | currency's exponent.
                 |
                 | A statement of financial position read through a float is
                 | out by a fraction that grows with the account count, still
                 | prints as balanced, and reconciles to nothing - and the
                 | sign is carried out of here as a flag rather than left to
                 | a `str_starts_with('-')`, which is wrong for the one
                 | currency whose minus sign is not a hyphen.
                 |
                 | `figure-negative` is measured against `surface` and
                 | against nothing else, so only the table rows colour a
                 | negative. On a sunken or tinted ground the figure keeps
                 | that ground's own text role: an unmeasured pair is one
                 | that fails in one of the five themes, in the place nobody
                 | looks at in all five.
                 */
                $figure = static function (?string $amount) use ($currency): ?array {
                    if ($amount === null) {
                        return null;
                    }

                    $money = \App\Support\Money\Money::of($amount, $currency);

                    return [
                        'text' => ($money->isNegative() ? '-' : '').$money->abs()->format(withSymbol: false),
                        'negative' => $money->isNegative(),
                    ];
                };

                $isZero = static fn (string $amount): bool => \App\Support\Money\Money::of($amount, $currency)->isZero();

                /*
                 | A section with nothing on it in either column is left out,
                 | which is the rule the statement applies to its own lines.
                 |
                 | An entity that has never issued share capital gets an
                 | Equity section with no lines in it, and an empty table
                 | under a heading reads as a figure that failed to load
                 | rather than as a section that has nothing in it. The
                 | unclassified section is in this list on exactly the same
                 | terms as the other three: it disappears when it is empty
                 | and is impossible to miss when it is not.
                 */
                $sections = array_values(array_filter(
                    $statement['sections'],
                    static fn (array $section): bool => $section['maturities'] !== []
                        || ! $isZero((string) $section['total'])
                        || ($section['comparative_total'] !== null && ! $isZero((string) $section['comparative_total'])),
                ));
            @endphp

            {{--
                The declaration, before any figure.

                Both languages, always. This is the one sentence on the page
                that has to survive being printed and put in front of an
                auditor, and a page that rendered only the reader's language
                would drop the other from the paper the auditor is holding.

                `print:border-2`, because a browser drops background colours
                on paper unless the reader has turned them on - so the tint
                that makes this a banner on screen is gone, and the edge is
                all that is left to say that the first paragraph is a verdict
                rather than an introduction.
            --}}
            <section role="{{ $severity === 'ok' ? 'status' : 'alert' }}" tabindex="-1"
                     @class([
                        'avoid-break mt-6 rounded-card border px-4 py-4 print:border-2',
                        'border-success bg-success-subtle text-on-success-subtle' => $severity === 'ok',
                        'border-warning bg-warning-subtle text-on-warning-subtle' => $severity === 'warning',
                        'border-danger bg-danger-subtle text-on-danger-subtle' => $severity === 'critical',
                     ])>
                <h2 class="text-title font-semibold">
                    @if ($severity === 'ok')
                        {{ __('القائمة متوازنة، وكل حساب مصنَّف') }}
                    @elseif ($severity === 'warning')
                        <span aria-hidden="true">!</span> {{ __('القائمة غير مكتملة، وفيها أرصدة بلا تصنيف') }}
                    @else
                        <span aria-hidden="true">!</span> {{ __('الأصول لا تساوي الالتزامات وحقوق الملكية، وكل رقم أدناه غير موثوق') }}
                    @endif
                </h2>

                <p class="mt-2 text-body">{{ $declaration['ar'] }}</p>
                <p dir="ltr" lang="en" class="mt-1 text-start text-caption">{{ $declaration['en'] }}</p>
            </section>

            @forelse ($sections as $section)
                @php
                    $name = (string) $section['section'];
                    $label = $naming($section);
                    $unclassified = $name === 'unclassified';

                    /*
                     | The unclassified section is loud, and only when it has
                     | something on it.
                     |
                     | It is the reason the statement does not balance, and a
                     | reader who skims past it concludes that the ledger is
                     | broken when the truth is that three accounts need a
                     | line in the chart. Marking it on every statement,
                     | including the ones where it is empty, is how a reader
                     | learns to ignore the marking.
                     */
                    $loud = $unclassified && ! $isZero((string) $section['total']);
                @endphp

                <section class="mt-8" aria-labelledby="section-{{ $name }}">
                    <h2 id="section-{{ $name }}" class="text-title font-semibold text-on-surface">
                        {{ $label['primary'] }}
                        <span dir="{{ $locale === 'ar' ? 'ltr' : 'rtl' }}" class="ms-2 text-caption font-normal text-on-surface-subtle">{{ $label['secondary'] }}</span>
                    </h2>

                    @if ($loud)
                        <p class="avoid-break mt-3 rounded-card border border-warning bg-warning-subtle px-4 py-3 text-label text-on-warning-subtle print:border-2">
                            {{ __('أرصدة هذه الحسابات ليست في أي مجموع أعلاه، لأن لا بند في المعيار يحملها. صنِّفها في دليل الحسابات، وحتى ذلك الحين الفرق في المجاميع أدناه هو هذه الأرصدة وليس خللاً في الدفاتر.') }}
                        </p>
                    @endif

                    <div @class(['mt-3 overflow-x-auto rounded-card border', $loud ? 'border-warning' : 'border-outline'])>
                        <table class="w-full text-label">
                            <caption class="sr-only">{{ $label['primary'] }} · {{ __('قائمة المركز المالي') }}</caption>

                            <thead class="bg-surface-sunken text-on-surface-muted">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('البند') }}</th>

                                    {{--
                                        Each figure column is headed by the
                                        date it was read at.

                                        Two columns of amounts with one
                                        heading between them is two dates a
                                        reader has to guess at, and the
                                        printed page repeats this row rather
                                        than the selector that chose them.
                                    --}}
                                    <th scope="col" class="figure border-s border-outline-subtle px-4 py-3 font-medium">
                                        {{ __('المبلغ') }}
                                        <span dir="ltr" class="block text-caption font-normal">{{ $statement['as_at'] }}</span>
                                    </th>

                                    @if ($hasComparative)
                                        <th scope="col" class="figure border-s border-outline-subtle px-4 py-3 font-medium">
                                            {{ __('المقارنة') }}
                                            <span dir="ltr" class="block text-caption font-normal">{{ $statement['comparative_as_at'] }}</span>
                                        </th>
                                    @endif

                                    <th scope="col" class="border-s border-outline-subtle px-4 py-3 text-start font-medium">{{ __('المرجع') }}</th>
                                </tr>
                            </thead>

                            @forelse ($section['maturities'] as $group)
                                @php
                                    $maturity = $naming($group);
                                @endphp

                                <tbody class="avoid-break divide-y divide-outline-subtle bg-surface">
                                    {{--
                                        The current / non-current heading -
                                        IAS 1.60 - except where it is the
                                        section's only group and says it does
                                        not apply.

                                        Equity has no maturity, and a row
                                        reading "Not applicable" above the
                                        only group in the section answers a
                                        question the section never raised:
                                        the reader looks for the thing it
                                        does not apply to. The enum is
                                        explicit so the DATA never reads as
                                        unclassified; on screen the section's
                                        own heading already says Equity.
                                    --}}
                                    @if (count($section['maturities']) > 1 || $group['maturity'] !== 'not_applicable')
                                        <tr>
                                            <th scope="rowgroup" colspan="{{ $span }}"
                                                class="bg-surface-sunken px-4 py-2 text-start text-caption font-semibold text-on-surface-muted">
                                                {{ $maturity['primary'] }}
                                                <span dir="{{ $locale === 'ar' ? 'ltr' : 'rtl' }}" class="ms-2 font-normal">{{ $maturity['secondary'] }}</span>
                                            </th>
                                        </tr>
                                    @endif

                                    @foreach ($group['lines'] as $line)
                                        @php
                                            $item = $naming($line);
                                            $amount = $figure((string) $line['amount']);
                                            $previous = $figure($line['comparative'] === null ? null : (string) $line['comparative']);
                                        @endphp

                                        <tr>
                                            <th scope="row" class="px-4 py-2.5 text-start font-normal">
                                                {{--
                                                    The accounts that made the
                                                    line, closed on every line
                                                    but the unclassified one.

                                                    A statement is read at the
                                                    level of its lines, so
                                                    twenty open lists is a
                                                    page nobody reads to the
                                                    bottom. The unclassified
                                                    line is the exception
                                                    because naming the
                                                    accounts IS the point of
                                                    it: a reader who has to
                                                    click to find out which
                                                    account needs classifying
                                                    is a reader who closes the
                                                    page instead.
                                                --}}
                                                <details @if ($unclassified) open @endif
                                                         class="rounded-field">
                                                    <summary class="flex min-h-(--control-height) cursor-pointer items-center rounded-field focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                                                        <span class="text-on-surface">{{ $item['primary'] }}</span>
                                                        <span dir="{{ $locale === 'ar' ? 'ltr' : 'rtl' }}" class="block text-caption text-on-surface-subtle">{{ $item['secondary'] }}</span>
                                                    </summary>

                                                    @if ($line['accounts'] === [])
                                                        <p class="mt-2 ms-4 text-caption text-on-surface-subtle">
                                                            {{ __('لا حساب على هذا البند في هذا التاريخ، والبند معروض لأنه كان عليه رصيد في فترة المقارنة.') }}
                                                        </p>
                                                    @else
                                                        <ul role="list" class="mt-2 ms-4 space-y-1 border-s border-outline-subtle ps-3">
                                                            @foreach ($line['accounts'] as $account)
                                                                @php
                                                                    $balance = $figure((string) $account['amount']);
                                                                    $accountName = $locale === 'ar' ? (string) $account['name_ar'] : (string) $account['name_en'];
                                                                @endphp

                                                                <li class="flex flex-wrap items-baseline justify-between gap-3 text-caption">
                                                                    <span>
                                                                        <span dir="ltr" class="figure font-mono text-on-surface-muted">{{ $account['code'] }}</span>
                                                                        <span class="ms-2 text-on-surface-muted">{{ $accountName !== '' ? $accountName : $account['code'] }}</span>
                                                                    </span>
                                                                    <span @class(['figure', $balance['negative'] ? 'text-figure-negative' : 'text-on-surface-muted'])>{{ $balance['text'] }}</span>
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    @endif
                                                </details>
                                            </th>

                                            <td @class(['figure border-s border-outline-subtle px-4 py-2.5', $amount['negative'] ? 'text-figure-negative' : 'text-on-surface'])>
                                                {{ $amount['text'] }}
                                            </td>

                                            @if ($hasComparative)
                                                <td @class(['figure border-s border-outline-subtle px-4 py-2.5', $previous !== null && $previous['negative'] ? 'text-figure-negative' : 'text-on-surface-muted'])>
                                                    {{ $previous['text'] ?? '—' }}
                                                </td>
                                            @endif

                                            {{--
                                                The paragraph the line comes
                                                from, quiet and in a column of
                                                its own.

                                                A statement line with no
                                                reference is a heading
                                                somebody chose, and nobody can
                                                check it. `dir="ltr"` so
                                                "IAS 1.54(h)" is not reordered
                                                by the Arabic around it.
                                            --}}
                                            <td dir="ltr" class="border-s border-outline-subtle px-4 py-2.5 text-start text-caption text-on-surface-subtle">
                                                {{ $line['reference'] }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            @empty
                                <tbody class="bg-surface">
                                    <tr>
                                        <td colspan="{{ $span }}" class="px-4 py-8 text-center text-on-surface-muted">
                                            {{ __('لا بند على هذا القسم في هذا التاريخ.') }}
                                        </td>
                                    </tr>
                                </tbody>
                            @endforelse

                            @php
                                $total = $figure((string) $section['total']);
                                $previousTotal = $figure($section['comparative_total'] === null ? null : (string) $section['comparative_total']);
                            @endphp

                            <tfoot class="border-t-2 border-outline-strong bg-surface-sunken font-semibold text-on-surface">
                                <tr>
                                    <th scope="row" class="px-4 py-3 text-start">
                                        {{ __('مجموع :section', ['section' => $label['primary']]) }}
                                    </th>

                                    <td class="figure border-s border-outline-subtle px-4 py-3 text-on-surface">
                                        {{ $total['text'] }}
                                    </td>

                                    @if ($hasComparative)
                                        <td class="figure border-s border-outline-subtle px-4 py-3 text-on-surface">
                                            {{ $previousTotal['text'] ?? '—' }}
                                        </td>
                                    @endif

                                    <td class="border-s border-outline-subtle px-4 py-3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>
            @empty
                <div class="mt-8 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
                    {{ __('لا رصيد على أي حساب من حسابات المركز المالي في هذا التاريخ، فلا قائمة تُعرض.') }}
                </div>
            @endforelse

            {{--
                The result for the period, between the sections and the
                totals, because that is where it enters the arithmetic.

                Revenue and expense accounts close into retained earnings at
                year end, and until they do their balances are real and are on
                no line above. A page that showed the figure without the
                sentence would leave the reader to work out why equity in the
                totals block is larger than the equity section they just read.
            --}}
            @php
                $resultNow = $figure((string) $result['amount']);
                $previousResult = $figure($result['comparative'] === null ? null : (string) $result['comparative']);
            @endphp

            <section class="avoid-break mt-8 rounded-card border border-insight bg-insight-subtle px-4 py-4 text-on-insight-subtle print:border-2"
                     aria-labelledby="result-for-the-period">
                <h2 id="result-for-the-period" class="text-label font-semibold">{{ __('نتيجة الفترة، محمَّلة على حقوق الملكية') }}</h2>

                <p class="mt-2 max-w-3xl text-body">
                    {{ __('الإيرادات والمصروفات تُقفل في الأرباح المبقاة عند نهاية السنة، وقبل ذلك أرصدتها حقيقية وليست على أي بند من بنود هذه القائمة. فلولا تحميل نتيجة الفترة على حقوق الملكية لزادت الأصول عن الالتزامات وحقوق الملكية بمقدار الربح بالضبط، في كل تاريخ إلا اليوم التالي لإقفال السنة.') }}
                </p>

                <dl class="mt-3 grid grid-cols-2 gap-3 text-label sm:grid-cols-4">
                    <div>
                        <dt class="text-caption opacity-80">{{ __('نتيجة الفترة') }}</dt>
                        <dd class="figure font-semibold">{{ $resultNow['text'] }}</dd>
                    </div>

                    @if ($hasComparative)
                        <div>
                            <dt class="text-caption opacity-80">{{ __('نتيجة فترة المقارنة') }}</dt>
                            <dd class="figure">{{ $previousResult['text'] ?? '—' }}</dd>
                        </div>
                    @endif
                </dl>
            </section>

            {{--
                The totals, and the difference between them.

                `equity` here is the equity section plus the result above it,
                taken from the statement rather than added up here: the
                equation is asserted AFTER the result is carried in, and a
                page that added the two itself would be a second definition of
                equity sitting next to the one the statement balances against.
            --}}
            @php
                $balanced = $isZero((string) $totals['difference']);
                $difference = $figure((string) $totals['difference']);

                $block = [
                    ['heading' => __('الأصول'), 'amount' => (string) $totals['assets']],
                    ['heading' => __('الالتزامات'), 'amount' => (string) $totals['liabilities']],
                    ['heading' => __('حقوق الملكية، بعد نتيجة الفترة'), 'amount' => (string) $totals['equity']],
                    ['heading' => __('الالتزامات وحقوق الملكية'), 'amount' => (string) $totals['liabilities_and_equity']],
                ];
            @endphp

            <section class="avoid-break mt-8 rounded-card border border-outline-strong bg-surface-sunken px-4 py-4 print:border-2"
                     aria-labelledby="statement-totals">
                <h2 id="statement-totals" class="text-label font-semibold text-on-surface">{{ __('المجاميع') }}</h2>

                <dl class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    @foreach ($block as $entry)
                        @php
                            $money = $figure($entry['amount']);
                        @endphp

                        <div>
                            <dt class="text-caption text-on-surface-muted">{{ $entry['heading'] }}</dt>
                            <dd class="figure text-title font-semibold text-on-surface">{{ $money['text'] }}</dd>
                        </div>
                    @endforeach
                </dl>

                <p @class([
                    'avoid-break mt-4 flex flex-wrap items-baseline justify-between gap-3 rounded-card border px-4 py-3 text-label print:border-2',
                    'border-success bg-success-subtle text-on-success-subtle' => $balanced,
                    'border-danger bg-danger-subtle text-on-danger-subtle' => ! $balanced,
                ])>
                    <span class="font-semibold">
                        {{ $balanced ? __('الفرق صفر: الأصول تساوي الالتزامات وحقوق الملكية') : __('الفرق بين الأصول والالتزامات وحقوق الملكية') }}
                    </span>
                    <span class="figure text-title font-semibold">{{ $difference['text'] }}</span>
                </p>
            </section>

            {{--
                What the figures were built from.

                A statement that does not say whether it includes the
                adjusting period cannot be compared to another one: the same
                entity, the same date, two different figures and nothing on
                either page to say why.
            --}}
            <p class="flow-tight max-w-3xl text-caption text-on-surface-muted">
                {{ $statement['basis'][$locale === 'ar' ? 'declaration_ar' : 'declaration_en'] }}
            </p>
        @endif
    @endif
@endsection
