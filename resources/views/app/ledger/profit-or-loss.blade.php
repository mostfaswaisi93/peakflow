@extends('layouts.app', ['title' => __('قائمة الأرباح أو الخسائر')])

{{--
    The statement of profit or loss and other comprehensive income - PF-185.

    ## Both analyses are stacked, and neither is behind a tab

    IAS 1.99 lets an entity present its expenses by function or by nature,
    and 1.104 requires the one presenting by function to disclose them by
    nature as well. So these are not two views of a preference: an entity
    needs both, and a tab strip would make one of them a thing the reader
    turns off - which on paper means the printed statement carries whichever
    tab happened to be open, and the disclosure IAS 1.104 asks for is the one
    that is missing. Stacked sections cost a scroll and print whole.

    The order is the standard's: the functional statement down to profit for
    the period, then other comprehensive income after it and separated from
    it (1.82A), and the analysis by nature last, because it is a disclosure
    about the expenses above rather than a second bottom line.

    ## Nothing on this page adds a figure up

    Every line, every band subtotal, profit for the period and total
    comprehensive income arrive from
    {@see \App\Tenant\Reporting\Services\StatementOfProfitOrLoss} as decimal
    strings and are rendered through `Money::format()` - grouped, at the
    currency's own exponent. A subtotal re-summed here would be a second
    definition of the statement's shape, and the first line put on the wrong
    side of it would print a subtotal that is wrong under a total that is
    right - the hardest arithmetic error to see, because the bottom of the
    page still agrees with the ledger.

    ## Every figure is a contribution, so expenses arrive negative

    The service presents each line as what it ADDS to the result: income
    positive, expense negative, every subtotal a plain sum. So nothing here
    re-signs anything. A negative prints in the accounting convention -
    parentheses around the absolute value - because a minus sign one
    character wide is what a reader misses in a column of forty, and because
    the parentheses survive a monochrome print and a colour-blind reader,
    where the figure's colour does not (WCAG 1.4.1).

    ## It is not laid out at the Bento width, for the sibling page's reason

    Four columns, not eight. At the wide measure the line label and its
    figure sit at opposite ends of a screen with nothing between them, and a
    reader tracking across that gap loses the row - which on a statement
    where two adjacent lines differ by one word is how a figure gets read
    against the wrong label.

    ## The accounts live inside the line, not in rows of their own

    A reader who disagrees with a line needs the accounts that made it, and
    `<details>` gives them that with no JavaScript and no second request.
    They cannot be extra table rows - a `<details>` may not wrap a `<tr>` -
    so they are a list inside the line's own cell, with the account's
    movement beside its name rather than in the amount column, which is
    reserved for the figures that add up to the result.

    ## The PHP here is always a block, never the parenthesised one-liner

    Blade takes a block of PHP by matching forward from its opening directive
    to the next closing one, so a single parenthesised one-liner above a
    later block swallows everything between the two and hands it back as raw
    PHP. The page then prints its own template source, every conditional in
    the swallowed region is left uncompiled, and the closing directive that
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
         | statement of profit or loss is printed and handed to somebody who
         | may read either language, and a page that rendered only the
         | session's locale drops the other from the paper in their hands.
         |
         | The key prefix is a parameter because the service names a line's
         | label `label_ar` and a band's subtotal `subtotal_label_ar`. One
         | closure reading both is one rule about which language leads;
         | two closures would be two, and the second would be edited alone.
         */
        $naming = static fn (array $of, string $keys = 'label'): array => [
            'primary' => (string) ($locale === 'ar' ? $of[$keys.'_ar'] : $of[$keys.'_en']),
            'secondary' => (string) ($locale === 'ar' ? $of[$keys.'_en'] : $of[$keys.'_ar']),
        ];
    @endphp

    <x-page-header :title="__('قائمة الأرباح أو الخسائر')">
        <x-slot:description>
            {{ __('نتيجة الفترة: الإيرادات وما قابلها من تكاليف حتى ربح الفترة، ثم الدخل الشامل الآخر بعده ومنفصلًا عنه، والمصروفات محلَّلة بحسب الوظيفة وبحسب الطبيعة معًا لأن المعيار يطلب التحليلين.') }}
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
                            {{ __('الفترة المعروضة') }}
                            <span dir="ltr" class="figure font-semibold text-on-surface">{{ $statement['from'] }} – {{ $statement['until'] }}</span>
                        </p>
                    @endif
                </div>
            @endif
        </x-slot:aside>
    </x-page-header>

    @if ($entity === null)
        <div class="mt-8 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
            {{ __('لم تُحدَّد شركة بعد، ولا نتيجة فترة بلا شركة.') }}
        </div>
    @elseif ($period === null)
        <div class="mt-8 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
            {{ __('لا توجد فترات محاسبية لهذه الشركة بعد، فلا فترة تُقاس عليها نتيجة.') }}
        </div>
    @else
        {{--
            A GET form, so the statement is a URL.

            A statement somebody can send to their auditor, bookmark, or
            return to with the back button is worth more than a tidier POST:
            the figure being discussed has to be reachable by the person
            being asked about it.

            `no-print`, because on paper a dropdown prints as a box showing
            one of its options and says nothing about why that one. The
            windows both columns were measured over are in the column
            headers, which repeat on every printed page, and the basis
            sentence is under the figures - so nothing is lost by leaving the
            controls on screen.
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
                            {{ $option->label }} · {{ $option->starts_on->toDateString() }} – {{ $option->ends_on->toDateString() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <x-button type="submit">{{ __('اعرض') }}</x-button>
        </form>

        <p id="why-comparative" class="no-print mt-2 max-w-2xl text-caption text-on-surface-muted">
            {{ __('فترة المقارنة تنتهي قبل بداية الفترة المعروضة، لا قبل نهايتها: النتيجة حركة خلال فترة، وتداخل الفترتين يحتسب الإيراد نفسه في العمودين فيُقرأ نموًا لم يحدث. والافتراضي هو الفترة المقابلة من السنة السابقة.') }}
        </p>

        @if ($unknownPeriod)
            <p class="mt-4 rounded-card border border-outline bg-surface px-4 py-3 text-label text-on-surface-muted">
                {{ __('الفترة المطلوبة في الرابط ليست فترة في هذه الشركة، وما يظهر أدناه هو آخر فترة فيها قيود.') }}
            </p>
        @endif

        @if ($unknownComparative)
            <p class="mt-4 rounded-card border border-outline bg-surface px-4 py-3 text-label text-on-surface-muted">
                {{ __('فترة المقارنة المطلوبة في الرابط لا تنتهي قبل بداية الفترة المعروضة، وعمود المقارنة أدناه هو المقابل من السنة السابقة إن وُجد.') }}
            </p>
        @endif

        @if ($refusal !== null)
            {{--
                The statement's refusal, in its own words.

                It names what is wrong and what a reader would otherwise
                wrongly conclude, and a paraphrase here would be a second
                explanation that drifts from the first the next time either
                is edited. A reader told "unavailable" opens a support
                ticket; a reader told that the comparative period overlaps
                this one changes the column.
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
                $byFunction = $statement['by_function'];
                $byNature = $statement['by_nature'];

                $comparativeFrom = $comparative?->starts_on->toDateString();
                $comparativeUntil = $statement['comparative_until'] === null
                    ? null
                    : (string) $statement['comparative_until'];

                $hasComparative = $comparativeUntil !== null;
                $span = $hasComparative ? 4 : 3;

                /*
                 | One amount, read as a string and rendered at the
                 | currency's exponent.
                 |
                 | A result read through a float is out by a fraction that
                 | grows with the account count, still prints as a tidy
                 | figure, and reconciles to nothing. The sign is carried out
                 | of here as a flag rather than left to a
                 | `str_starts_with('-')`, which is wrong for the one
                 | currency whose minus sign is not a hyphen.
                 |
                 | The negative is wrapped in parentheses and NOT negated:
                 | the service already presents every line as its
                 | contribution to the result, so flipping an expense here
                 | would add it to profit instead of taking it off - the
                 | exact defect the check comparing the two analyses exists
                 | to catch.
                 |
                 | `figure-negative` is measured against `surface` and
                 | against nothing else, so only the line rows colour a
                 | negative. On a sunken or tinted ground the figure keeps
                 | that ground's own text role, and the parentheses carry the
                 | sign there on their own: an unmeasured pair is one that
                 | fails in one of the five themes, in the place nobody looks
                 | at in all five.
                 */
                $figure = static function (?string $amount) use ($currency): ?array {
                    if ($amount === null) {
                        return null;
                    }

                    $money = \App\Support\Money\Money::of($amount, $currency);
                    $negative = $money->isNegative();

                    return [
                        'text' => $negative
                            ? '('.$money->abs()->format(withSymbol: false).')'
                            : $money->format(withSymbol: false),
                        'negative' => $negative,
                    ];
                };

                /*
                 | The bands of the statement proper, and the OCI band apart
                 | from them.
                 |
                 | Other comprehensive income is not profit - IAS 1.82A - so
                 | it is not another band in the same table under the same
                 | running total. Splitting the list here is what lets the
                 | functional table end at profit for the period and the
                 | section after it begin at the figure that is not in it.
                 */
                $bands = [];
                $oci = null;
                $tax = null;

                foreach ($byFunction['bands'] as $band) {
                    if ($band['band'] === 'other_comprehensive_income') {
                        $oci = $band;

                        continue;
                    }

                    if ($band['band'] === 'tax') {
                        $tax = $band;
                    }

                    $bands[] = $band;
                }

                /*
                 | The split IAS 1.82A asks for, and the second group takes
                 | everything that is not the first.
                 |
                 | Written as two positive filters, a line whose flag was
                 | neither true nor false would be dropped from both groups
                 | and disappear from a statement whose total comprehensive
                 | income still counts it - a figure missing from the rows
                 | that make it, which is the one error on this page a reader
                 | cannot detect.
                 */
                $ociLines = $oci === null ? [] : $oci['lines'];

                $ociGroups = [
                    [
                        'heading' => __('قد يُعاد تصنيفها لاحقًا إلى الأرباح أو الخسائر'),
                        'lines' => array_values(array_filter(
                            $ociLines,
                            static fn (array $line): bool => $line['may_be_reclassified'] === true,
                        )),
                    ],
                    [
                        'heading' => __('لن يُعاد تصنيفها إلى الأرباح أو الخسائر'),
                        'lines' => array_values(array_filter(
                            $ociLines,
                            static fn (array $line): bool => $line['may_be_reclassified'] !== true,
                        )),
                    ],
                ];
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
                        {{ __('التحليلان يتفقان على نتيجة الفترة') }}
                    @elseif ($severity === 'warning')
                        <span aria-hidden="true">!</span> {{ __('التحليل بحسب الطبيعة غير مكتمل، وفيه مصروفات بلا تصنيف') }}
                    @else
                        <span aria-hidden="true">!</span> {{ __('التحليلان لا يتفقان على النتيجة، وكل رقم أدناه غير موثوق') }}
                    @endif
                </h2>

                <p class="mt-2 text-body">{{ $declaration['ar'] }}</p>
                <p dir="ltr" lang="en" class="mt-1 text-start text-caption">{{ $declaration['en'] }}</p>
            </section>

            {{--
                The functional statement, down to profit for the period.

                Each band's closing subtotal is the running total at its
                boundary and is printed as the service reports it. Profit for
                the period is the tax band's boundary and is in the
                `<tfoot>`, which the print stylesheet repeats on every page:
                a statement whose bottom line is only on the last sheet is a
                statement somebody will quote the wrong figure from.
            --}}
            <section class="mt-8" aria-labelledby="by-function">
                <h2 id="by-function" class="text-title font-semibold text-on-surface">{{ __('التحليل بحسب الوظيفة') }}</h2>

                <p class="mt-2 max-w-3xl text-label text-on-surface-muted">
                    {{ __('المصروفات مجمَّعة بحسب ما أُنفقت فيه: تكلفة المبيعات، التوزيع، الإدارة. والمجاميع الظاهرة هي الأرصدة الجارية عند حدود النطاقات لا صيغ مكتوبة، فالبند الذي يُضاف يدخل نطاقه ولا يتغيَّر شيء آخر.') }}
                </p>

                <div class="mt-3 overflow-x-auto rounded-card border border-outline">
                    <table class="w-full text-label">
                        <caption class="sr-only">{{ __('التحليل بحسب الوظيفة') }} · {{ __('قائمة الأرباح أو الخسائر') }}</caption>

                        <thead class="bg-surface-sunken text-on-surface-muted">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('البند') }}</th>

                                {{--
                                    Each figure column is headed by the
                                    window it was measured over.

                                    Two columns of amounts with one heading
                                    between them is two windows a reader has
                                    to guess at - and on a movement statement
                                    the guess is the whole comparison. The
                                    printed page repeats this row rather than
                                    the selector that chose them.
                                --}}
                                <th scope="col" class="figure border-s border-outline-subtle px-4 py-3 font-medium">
                                    {{ __('المبلغ') }}
                                    <span dir="ltr" class="block text-caption font-normal">{{ $statement['from'] }} – {{ $statement['until'] }}</span>
                                </th>

                                @if ($hasComparative)
                                    <th scope="col" class="figure border-s border-outline-subtle px-4 py-3 font-medium">
                                        {{ __('المقارنة') }}
                                        <span dir="ltr" class="block text-caption font-normal">{{ $comparativeFrom }} – {{ $comparativeUntil }}</span>
                                    </th>
                                @endif

                                <th scope="col" class="border-s border-outline-subtle px-4 py-3 text-start font-medium">{{ __('المرجع') }}</th>
                            </tr>
                        </thead>

                        @forelse ($bands as $band)
                            @php
                                $subtotal = $naming($band, 'subtotal_label');
                                $bandAmount = $figure((string) $band['amount']);
                                $bandPrevious = $figure($band['comparative'] === null ? null : (string) $band['comparative']);

                                /*
                                 | The tax band's boundary is profit for the
                                 | period, and it is drawn once, in the
                                 | `<tfoot>`.
                                 |
                                 | Drawn here as well it would be the same
                                 | figure twice under two rules, and the day
                                 | one of them changed a reader would have to
                                 | decide which of two bottom lines the
                                 | statement meant.
                                 */
                                $closes = $band['band'] !== 'tax';
                            @endphp

                            <tbody class="avoid-break divide-y divide-outline-subtle bg-surface">
                                @foreach ($band['lines'] as $line)
                                    @php
                                        $item = $naming($line);
                                        $amount = $figure((string) $line['amount']);
                                        $previous = $figure($line['comparative'] === null ? null : (string) $line['comparative']);
                                    @endphp

                                    <tr>
                                        <th scope="row" class="px-4 py-2.5 text-start font-normal">
                                            {{--
                                                The accounts that made the
                                                line, closed.

                                                A statement is read at the
                                                level of its lines, so a page
                                                of twenty open lists is a page
                                                nobody reads to the bottom.
                                            --}}
                                            <details class="rounded-field">
                                                <summary class="cursor-pointer rounded-field focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                                                    <span class="text-on-surface">{{ $item['primary'] }}</span>
                                                    <span dir="{{ $locale === 'ar' ? 'ltr' : 'rtl' }}" class="block text-caption text-on-surface-subtle">{{ $item['secondary'] }}</span>
                                                </summary>

                                                @if ($line['accounts'] === [])
                                                    <p class="mt-2 ms-4 text-caption text-on-surface-subtle">
                                                        {{ __('لا حساب على هذا البند في هذه الفترة، والبند معروض لأنه كانت عليه حركة في فترة المقارنة.') }}
                                                    </p>
                                                @else
                                                    <ul role="list" class="mt-2 ms-4 space-y-1 border-s border-outline-subtle ps-3">
                                                        @foreach ($line['accounts'] as $account)
                                                            @php
                                                                $movement = $figure((string) $account['amount']);
                                                                $accountName = $locale === 'ar' ? (string) $account['name_ar'] : (string) $account['name_en'];
                                                            @endphp

                                                            <li class="flex flex-wrap items-baseline justify-between gap-3 text-caption">
                                                                <span>
                                                                    <span dir="ltr" class="figure font-mono text-on-surface-muted">{{ $account['code'] }}</span>
                                                                    <span class="ms-2 text-on-surface-muted">{{ $accountName !== '' ? $accountName : $account['code'] }}</span>
                                                                </span>
                                                                <span @class(['figure', $movement['negative'] ? 'text-figure-negative' : 'text-on-surface-muted'])>{{ $movement['text'] }}</span>
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
                                            The paragraph the line comes from,
                                            quiet and in a column of its own.

                                            A statement line with no reference
                                            is a heading somebody chose, and
                                            nobody can check it. `dir="ltr"`
                                            so "IAS 1.82(a)" is not reordered
                                            by the Arabic around it.
                                        --}}
                                        <td dir="ltr" class="border-s border-outline-subtle px-4 py-2.5 text-start text-caption text-on-surface-subtle">
                                            {{ $line['reference'] }}
                                        </td>
                                    </tr>
                                @endforeach

                                @if ($closes)
                                    {{--
                                        The band's closing subtotal, on the
                                        sunken ground with a rule above it.

                                        A subtotal that looks like a line is a
                                        subtotal a reader adds into the next
                                        one, and the statement then reads as
                                        double the cost base.
                                    --}}
                                    <tr class="border-t-2 border-outline-strong bg-surface-sunken font-semibold text-on-surface">
                                        <th scope="row" class="px-4 py-3 text-start">
                                            {{ $subtotal['primary'] }}
                                            <span dir="{{ $locale === 'ar' ? 'ltr' : 'rtl' }}" class="ms-2 text-caption font-normal text-on-surface-muted">{{ $subtotal['secondary'] }}</span>
                                        </th>

                                        <td class="figure border-s border-outline-subtle px-4 py-3 text-on-surface">
                                            {{ $bandAmount['text'] }}
                                        </td>

                                        @if ($hasComparative)
                                            <td class="figure border-s border-outline-subtle px-4 py-3 text-on-surface">
                                                {{ $bandPrevious['text'] ?? '—' }}
                                            </td>
                                        @endif

                                        <td dir="ltr" class="border-s border-outline-subtle px-4 py-3 text-start text-caption font-normal text-on-surface-subtle">
                                            {{ $band['subtotal_reference'] }}
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        @empty
                            <tbody class="bg-surface">
                                <tr>
                                    <td colspan="{{ $span }}" class="px-4 py-8 text-center text-on-surface-muted">
                                        {{ __('لا حركة على أي حساب من حسابات الأرباح أو الخسائر في هذه الفترة.') }}
                                    </td>
                                </tr>
                            </tbody>
                        @endforelse

                        @if ($tax !== null)
                            @php
                                /*
                                 | The result, read from the key the service
                                 | names it by.
                                 |
                                 | `profit_for_the_period` is captured at the
                                 | tax boundary BEFORE other comprehensive
                                 | income is added to the running total, and
                                 | it is the figure the statement of changes
                                 | in equity reconciles to. Reading the band's
                                 | own amount would be the same number today
                                 | and the wrong one the day a band is added
                                 | after tax.
                                 */
                                $result = $naming($tax, 'subtotal_label');
                                $resultAmount = $figure((string) $byFunction['profit_for_the_period']);
                                $resultPrevious = $figure($byFunction['profit_for_the_period_comparative'] === null
                                    ? null
                                    : (string) $byFunction['profit_for_the_period_comparative']);
                            @endphp

                            <tfoot class="avoid-break border-t-2 border-outline-strong bg-surface-sunken text-title font-semibold text-on-surface">
                                <tr>
                                    <th scope="row" class="px-4 py-3 text-start">
                                        {{ $result['primary'] }}
                                        <span dir="{{ $locale === 'ar' ? 'ltr' : 'rtl' }}" class="ms-2 text-caption font-normal text-on-surface-muted">{{ $result['secondary'] }}</span>
                                    </th>

                                    <td class="figure border-s border-outline-subtle px-4 py-3 text-on-surface">
                                        {{ $resultAmount['text'] }}
                                    </td>

                                    @if ($hasComparative)
                                        <td class="figure border-s border-outline-subtle px-4 py-3 text-on-surface">
                                            {{ $resultPrevious['text'] ?? '—' }}
                                        </td>
                                    @endif

                                    <td dir="ltr" class="border-s border-outline-subtle px-4 py-3 text-start text-caption font-normal text-on-surface-subtle">
                                        {{ $tax['subtotal_reference'] }}
                                    </td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </section>

            {{--
                Other comprehensive income, after profit for the period and
                in a section of its own.

                Not another band in the table above: an OCI item folded into
                profit overstates the result and the error never unwinds,
                because the reclassification that would have corrected it is
                exactly what did not happen. A heading, a table and a total
                of its own is a separation that survives a printout, a
                screen reader's table navigation and a copy into a
                spreadsheet - which a tinted row does not.
            --}}
            <section class="mt-8" aria-labelledby="other-comprehensive-income">
                <h2 id="other-comprehensive-income" class="text-title font-semibold text-on-surface">{{ __('الدخل الشامل الآخر') }}</h2>

                <p class="mt-2 max-w-3xl text-label text-on-surface-muted">
                    {{ __('بنود ليست ربحًا، فتُعرض بعد ربح الفترة ومنفصلة عنه. وتقسيمها بحسب احتمال إعادة تصنيفها إلى الأرباح أو الخسائر جزء من المطلب لا تحسينٌ في العرض: عرضها مجتمعة يخبر القارئ أن كل بند فيها ما زال قادمًا إلى الأرباح أو الخسائر، والنصف الثاني منها لن يأتي أبدًا.') }}
                </p>

                @if ($oci === null)
                    {{--
                        No OCI, and total comprehensive income still shown.

                        IAS 1.81A(c) asks for the figure whether or not
                        anything was recognised outside profit, and a page
                        that omitted it when the two were equal would be a
                        page whose bottom line appears and disappears - so a
                        reader could not tell a period with no OCI from a
                        product that does not present it.
                    --}}
                    @php
                        $comprehensive = $figure((string) $byFunction['total_comprehensive_income']);
                        $comprehensivePrevious = $figure($byFunction['total_comprehensive_income_comparative'] === null
                            ? null
                            : (string) $byFunction['total_comprehensive_income_comparative']);
                    @endphp

                    <div class="avoid-break mt-3 rounded-card border border-outline-strong bg-surface-sunken px-4 py-4 print:border-2">
                        <p class="max-w-3xl text-label text-on-surface-muted">
                            {{ __('لم يقع في هذه الفترة أي بند دخل شامل آخر، فإجمالي الدخل الشامل يساوي ربح الفترة.') }}
                        </p>

                        <dl class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <div>
                                <dt class="text-caption text-on-surface-muted">{{ __('إجمالي الدخل الشامل') }}</dt>
                                <dd class="figure text-title font-semibold text-on-surface">{{ $comprehensive['text'] }}</dd>
                            </div>

                            @if ($hasComparative)
                                <div>
                                    <dt class="text-caption text-on-surface-muted">{{ __('إجمالي الدخل الشامل في فترة المقارنة') }}</dt>
                                    <dd class="figure text-title font-semibold text-on-surface">{{ $comprehensivePrevious['text'] ?? '—' }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                @else
                    <div class="mt-3 overflow-x-auto rounded-card border border-outline">
                        <table class="w-full text-label">
                            <caption class="sr-only">{{ __('الدخل الشامل الآخر') }} · {{ __('قائمة الأرباح أو الخسائر') }}</caption>

                            <thead class="bg-surface-sunken text-on-surface-muted">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('البند') }}</th>

                                    <th scope="col" class="figure border-s border-outline-subtle px-4 py-3 font-medium">
                                        {{ __('المبلغ') }}
                                        <span dir="ltr" class="block text-caption font-normal">{{ $statement['from'] }} – {{ $statement['until'] }}</span>
                                    </th>

                                    @if ($hasComparative)
                                        <th scope="col" class="figure border-s border-outline-subtle px-4 py-3 font-medium">
                                            {{ __('المقارنة') }}
                                            <span dir="ltr" class="block text-caption font-normal">{{ $comparativeFrom }} – {{ $comparativeUntil }}</span>
                                        </th>
                                    @endif

                                    <th scope="col" class="border-s border-outline-subtle px-4 py-3 text-start font-medium">{{ __('المرجع') }}</th>
                                </tr>
                            </thead>

                            @foreach ($ociGroups as $group)
                                @if ($group['lines'] !== [])
                                    <tbody class="avoid-break divide-y divide-outline-subtle bg-surface">
                                        <tr>
                                            <th scope="rowgroup" colspan="{{ $span }}"
                                                class="bg-surface-sunken px-4 py-2 text-start text-caption font-semibold text-on-surface-muted">
                                                {{ $group['heading'] }}
                                            </th>
                                        </tr>

                                        @foreach ($group['lines'] as $line)
                                            @php
                                                $item = $naming($line);
                                                $amount = $figure((string) $line['amount']);
                                                $previous = $figure($line['comparative'] === null ? null : (string) $line['comparative']);
                                            @endphp

                                            <tr>
                                                <th scope="row" class="px-4 py-2.5 text-start font-normal">
                                                    <details class="rounded-field">
                                                        <summary class="cursor-pointer rounded-field focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                                                            <span class="text-on-surface">{{ $item['primary'] }}</span>
                                                            <span dir="{{ $locale === 'ar' ? 'ltr' : 'rtl' }}" class="block text-caption text-on-surface-subtle">{{ $item['secondary'] }}</span>
                                                        </summary>

                                                        @if ($line['accounts'] === [])
                                                            <p class="mt-2 ms-4 text-caption text-on-surface-subtle">
                                                                {{ __('لا حساب على هذا البند في هذه الفترة، والبند معروض لأنه كانت عليه حركة في فترة المقارنة.') }}
                                                            </p>
                                                        @else
                                                            <ul role="list" class="mt-2 ms-4 space-y-1 border-s border-outline-subtle ps-3">
                                                                @foreach ($line['accounts'] as $account)
                                                                    @php
                                                                        $movement = $figure((string) $account['amount']);
                                                                        $accountName = $locale === 'ar' ? (string) $account['name_ar'] : (string) $account['name_en'];
                                                                    @endphp

                                                                    <li class="flex flex-wrap items-baseline justify-between gap-3 text-caption">
                                                                        <span>
                                                                            <span dir="ltr" class="figure font-mono text-on-surface-muted">{{ $account['code'] }}</span>
                                                                            <span class="ms-2 text-on-surface-muted">{{ $accountName !== '' ? $accountName : $account['code'] }}</span>
                                                                        </span>
                                                                        <span @class(['figure', $movement['negative'] ? 'text-figure-negative' : 'text-on-surface-muted'])>{{ $movement['text'] }}</span>
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

                                                <td dir="ltr" class="border-s border-outline-subtle px-4 py-2.5 text-start text-caption text-on-surface-subtle">
                                                    {{ $line['reference'] }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                @endif
                            @endforeach

                            @php
                                /*
                                 | Total comprehensive income: profit for the
                                 | period plus everything above it in this
                                 | table, which is the OCI band's own
                                 | boundary.
                                 |
                                 | Taken from the key the service names
                                 | rather than from the band, for the reason
                                 | the result above is: this is the figure the
                                 | statement presents under IAS 1.81A(c),
                                 | whether or not a band happens to close it.
                                 */
                                $comprehensive = $figure((string) $byFunction['total_comprehensive_income']);
                                $comprehensivePrevious = $figure($byFunction['total_comprehensive_income_comparative'] === null
                                    ? null
                                    : (string) $byFunction['total_comprehensive_income_comparative']);
                                $comprehensiveLabel = $naming($oci, 'subtotal_label');
                            @endphp

                            <tfoot class="avoid-break border-t-2 border-outline-strong bg-surface-sunken text-title font-semibold text-on-surface">
                                <tr>
                                    <th scope="row" class="px-4 py-3 text-start">
                                        {{ $comprehensiveLabel['primary'] }}
                                        <span dir="{{ $locale === 'ar' ? 'ltr' : 'rtl' }}" class="ms-2 text-caption font-normal text-on-surface-muted">{{ $comprehensiveLabel['secondary'] }}</span>
                                    </th>

                                    <td class="figure border-s border-outline-subtle px-4 py-3 text-on-surface">
                                        {{ $comprehensive['text'] }}
                                    </td>

                                    @if ($hasComparative)
                                        <td class="figure border-s border-outline-subtle px-4 py-3 text-on-surface">
                                            {{ $comprehensivePrevious['text'] ?? '—' }}
                                        </td>
                                    @endif

                                    <td dir="ltr" class="border-s border-outline-subtle px-4 py-3 text-start text-caption font-normal text-on-surface-subtle">
                                        {{ $oci['subtotal_reference'] }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </section>

            {{--
                The analysis by nature - IAS 1.104.

                It stops at the result and carries no other comprehensive
                income, because IAS 1.102 analyses EXPENSES and an OCI item
                is neither revenue nor an expense. Adding it here would make
                the two analyses disagree on purpose, and the check that
                compares them - the one that found the double negation in the
                service's first draft - would have nothing left to say.
            --}}
            <section class="mt-8" aria-labelledby="by-nature">
                <h2 id="by-nature" class="text-title font-semibold text-on-surface">{{ __('التحليل بحسب الطبيعة') }}</h2>

                <p class="mt-2 max-w-3xl text-label text-on-surface-muted">
                    {{ __('المصروفات مجمَّعة بحسب ما هي عليه: مواد، منافع موظفين، إهلاك. والمعيار 1.104 يطلب هذا التحليل إلى جانب التحليل بحسب الوظيفة، فليس أحدهما بديلًا عن الآخر ولا خيارًا يُطفئه القارئ، وهما تجميعان لنفس الصفوف فلا بد أن يتفقا على النتيجة.') }}
                </p>

                @if ((bool) $byNature['has_unclassified'])
                    {{--
                        The unclassified expenses, named before the table and
                        marked inside it.

                        The by-nature analysis does not carry its accounts,
                        so nothing here can be opened to find out which they
                        are - which is why the row is marked AND the sentence
                        above it says what to do. A reader who skims past it
                        concludes that the product does not do IAS 1.104,
                        when the truth is that a handful of accounts need a
                        nature in the chart.
                    --}}
                    <p class="avoid-break mt-3 rounded-card border border-warning bg-warning-subtle px-4 py-3 text-label text-on-warning-subtle print:border-2">
                        {{ __('بعض المصروفات بلا تصنيف طبيعي، فالتحليل الذي يطلبه المعيار 1.104 غير مكتمل حتى تُصنَّف هذه الحسابات في دليل الحسابات. مبالغها ظاهرة في الصف الموسوم أدناه ومحتسبة في مجموع التحليلين كليهما، فالناقص هو التصنيف لا الرقم.') }}
                    </p>
                @endif

                <div @class(['mt-3 overflow-x-auto rounded-card border', (bool) $byNature['has_unclassified'] ? 'border-warning' : 'border-outline'])>
                    <table class="w-full text-label">
                        <caption class="sr-only">{{ __('التحليل بحسب الطبيعة') }} · {{ __('قائمة الأرباح أو الخسائر') }}</caption>

                        <thead class="bg-surface-sunken text-on-surface-muted">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('البند') }}</th>

                                <th scope="col" class="figure border-s border-outline-subtle px-4 py-3 font-medium">
                                    {{ __('المبلغ') }}
                                    <span dir="ltr" class="block text-caption font-normal">{{ $statement['from'] }} – {{ $statement['until'] }}</span>
                                </th>

                                @if ($hasComparative)
                                    <th scope="col" class="figure border-s border-outline-subtle px-4 py-3 font-medium">
                                        {{ __('المقارنة') }}
                                        <span dir="ltr" class="block text-caption font-normal">{{ $comparativeFrom }} – {{ $comparativeUntil }}</span>
                                    </th>
                                @endif

                                <th scope="col" class="border-s border-outline-subtle px-4 py-3 text-start font-medium">{{ __('المرجع') }}</th>
                            </tr>
                        </thead>

                        <tbody class="avoid-break divide-y divide-outline-subtle bg-surface">
                            @foreach ($byNature['lines'] as $line)
                                @php
                                    $item = $naming($line);
                                    $amount = $figure((string) $line['amount']);
                                    $previous = $figure($line['comparative'] === null ? null : (string) $line['comparative']);

                                    /*
                                     | The unclassified line is loud, and it
                                     | is the only one that is.
                                     |
                                     | Marking every line would teach the
                                     | reader to ignore the marking, which is
                                     | the state in which this row - the only
                                     | one that says the disclosure is
                                     | incomplete - goes unread.
                                     */
                                    $loud = $line['nature'] === 'unclassified';
                                @endphp

                                <tr @class(['bg-warning-subtle text-on-warning-subtle' => $loud])>
                                    <th scope="row" class="px-4 py-2.5 text-start font-normal">
                                        @if ($loud)
                                            <span aria-hidden="true">!</span>
                                        @endif
                                        <span @class(['font-semibold' => $loud, 'text-on-surface' => ! $loud])>{{ $item['primary'] }}</span>
                                        <span dir="{{ $locale === 'ar' ? 'ltr' : 'rtl' }}" @class(['block text-caption', 'text-on-surface-subtle' => ! $loud])>{{ $item['secondary'] }}</span>
                                    </th>

                                    <td @class([
                                        'figure border-s border-outline-subtle px-4 py-2.5',
                                        'font-semibold' => $loud,
                                        'text-figure-negative' => ! $loud && $amount['negative'],
                                        'text-on-surface' => ! $loud && ! $amount['negative'],
                                    ])>
                                        {{ $amount['text'] }}
                                    </td>

                                    @if ($hasComparative)
                                        <td @class([
                                            'figure border-s border-outline-subtle px-4 py-2.5',
                                            'text-figure-negative' => ! $loud && $previous !== null && $previous['negative'],
                                            'text-on-surface-muted' => ! $loud && ($previous === null || ! $previous['negative']),
                                        ])>
                                            {{ $previous['text'] ?? '—' }}
                                        </td>
                                    @endif

                                    <td dir="ltr" @class(['border-s border-outline-subtle px-4 py-2.5 text-start text-caption', 'text-on-surface-subtle' => ! $loud])>
                                        {{ $line['reference'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>

                        @php
                            $natural = $figure((string) $byNature['profit_for_the_period']);
                        @endphp

                        <tfoot class="avoid-break border-t-2 border-outline-strong bg-surface-sunken text-title font-semibold text-on-surface">
                            <tr>
                                <th scope="row" class="px-4 py-3 text-start">{{ __('نتيجة الفترة بحسب الطبيعة') }}</th>

                                <td class="figure border-s border-outline-subtle px-4 py-3 text-on-surface">
                                    {{ $natural['text'] }}
                                </td>

                                @if ($hasComparative)
                                    {{--
                                        The comparative total is a dash, on
                                        purpose.

                                        The statement reports a comparative
                                        for every line of this analysis and no
                                        comparative TOTAL for it, and adding
                                        the column up here would be a second
                                        definition of the result sitting
                                        beside the one the ledger proves. The
                                        comparative result is in the
                                        functional statement above, where it
                                        is reported.
                                    --}}
                                    <td class="figure border-s border-outline-subtle px-4 py-3 text-on-surface-muted">—</td>
                                @endif

                                {{--
                                    The reference cell is empty here, and the
                                    paragraph above the table carries IAS
                                    1.104 instead.

                                    Every other reference on this page is the
                                    service's own, read from the line it
                                    belongs to. A paragraph number typed into
                                    the template would be the one citation
                                    nobody could check against the chart, and
                                    the first standard amendment would leave
                                    it silently wrong.
                                --}}
                                <td class="border-s border-outline-subtle px-4 py-3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if ($hasComparative)
                    {{--
                        The dash in the total's comparative cell, explained
                        where the reader meets it.

                        The reasoning is in the markup above as well, and a
                        reader does not read markup: an unexplained dash under
                        a column of real comparative figures reads as a
                        calculation that failed, which is the one thing it is
                        not.
                    --}}
                    <p class="mt-2 max-w-3xl text-caption text-on-surface-muted">
                        {{ __('القائمة تُصدر مقارنة لكل بند في هذا التحليل ولا تُصدر مجموعًا لعمود المقارنة فيه، وجمع العمود هنا كان سيصبح تعريفًا ثانيًا للنتيجة. نتيجة فترة المقارنة في التحليل بحسب الوظيفة أعلاه، حيث تُصدرها القائمة.') }}
                    </p>
                @endif
            </section>

            {{--
                What the figures were built from.

                A statement that does not say whether it is a movement or a
                balance, and whether the adjusting period is in it, cannot be
                compared to another one: the same entity, the same month, two
                different results and nothing on either page to say why.
            --}}
            <p class="mt-4 max-w-3xl text-caption text-on-surface-muted">
                @if ((bool) $statement['includes_adjusting'])
                    {{ __('الأرقام أعلاه حركة الفترة لا الأرصدة الختامية، وتشمل قيود فترة التسوية المحمَّلة على آخر يوم من السنة المالية.') }}
                @else
                    {{ __('الأرقام أعلاه حركة الفترة لا الأرصدة الختامية، ولا تشمل قيود فترة التسوية.') }}
                @endif
            </p>
        @endif
    @endif
@endsection
