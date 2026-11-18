@extends('layouts.app', ['title' => __('ميزان المراجعة'), 'wide' => true])

{{--
    The trial balance - PF-183.

    ## The verdict is a banner, not a column

    An unbalanced ENTRY is refused by a deferred constraint, so an unbalanced
    trial balance means a row was written around the posting path or a read
    model has drifted. Putting that in a cell an export might drop, or a
    colour a printout loses, hides the only thing on the page that matters -
    so it leads the page as a sentence the report wrote itself, and the
    unbalanced form carries `role="alert"` and the danger roles.

    ## The totals are summed from the column above them

    Deliberately not taken from the report's own `total_debit` and
    `total_credit`. Those are GROSS movements - an account with 42,000 of
    debits and 1,000 of credits contributes to both - and they are not
    translated when the report is presented in another currency. Either
    mistake prints a total that does not equal the column beneath it while
    still balancing, which is the one kind of wrong a totals check cannot
    catch.

    ## Every figure arrives as a string and stays exact

    The amounts come from the report as decimal strings and are rendered
    through `Money::toPresentation()`, at the currency's own exponent. A
    trial balance read through a float is out by a fraction that grows with
    the row count, still prints as balanced, and reconciles to nothing.
--}}

@section('content')
    @php
        $locale = app()->getLocale();

        /*
         | Where the sign becomes two columns, and nowhere else on this page.
         |
         | The ledger stores one signed amount; a debit and a credit column
         | are a presentation of it. An account appears in one of the two,
         | never both: a statement built so that one account can show a
         | figure in each prints inflated totals that still balance.
         */
        $split = static function (string $amount, string $currency): array {
            $money = \App\Support\Money\Money::of($amount, $currency);
            $figure = (string) $money->abs()->toPresentation();

            return [
                'debit' => $money->isPositive() ? $figure : null,
                'credit' => $money->isNegative() ? $figure : null,
            ];
        };

        /*
         | The indent is a fixed set of classes, not a computed one.
         |
         | Tailwind emits only the utilities it can SEE in the source, so a
         | class assembled from a variable compiles to a page whose
         | hierarchy is flat - and a flat trial balance reads as a chart with
         | no groups rather than as a missing stylesheet. Logical margins, so
         | the tree indents from the reading edge in both directions.
         */
        $indents = ['ms-0', 'ms-3', 'ms-6', 'ms-9', 'ms-12'];

        $columns = [
            'opening' => __('رصيد افتتاحي'),
            'movement' => __('الحركة'),
            'closing' => __('رصيد ختامي'),
        ];
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-headline font-bold text-on-surface">{{ __('ميزان المراجعة') }}</h1>
            <p class="mt-1 max-w-2xl text-label text-on-surface-muted">
                {{ __('الأرصدة الافتتاحية وحركة الفترة والأرصدة الختامية، لكل حساب تحرّك. الحساب الذي يفتح ويُقفل على صفر ليس في القائمة.') }}
            </p>
        </div>

        @if ($entity)
            <p class="inline-flex items-center gap-2 rounded-full border border-outline bg-surface px-3 py-1 text-caption text-on-surface-muted">
                {{ $entity->legal_name }} ·
                {{ __('العملة الوظيفية') }}
                <span dir="ltr" class="figure font-semibold text-on-surface">{{ $entity->functional_currency }}</span>
            </p>
        @endif
    </div>

    @if ($entity === null)
        <div class="mt-8 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
            {{ __('لم تُحدَّد شركة بعد، ولا يوجد ميزان مراجعة بلا شركة.') }}
        </div>
    @elseif ($period === null)
        <div class="mt-8 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
            {{ __('لا توجد فترات محاسبية لهذه الشركة بعد، فلا فترة يُقاس عليها ميزان.') }}
        </div>
    @else
        {{--
            A GET form, so the report is a URL.

            A trial balance somebody can send to their auditor, bookmark, or
            return to with the back button is worth more than a tidier POST:
            the figure being discussed has to be reachable by the person being
            asked about it.
        --}}
        <form method="GET" class="mt-6 flex flex-wrap items-end gap-3">
            <div>
                <label for="period" class="block text-label font-medium text-on-surface">{{ __('الفترة المحاسبية') }}</label>
                <select name="period" id="period" dir="ltr"
                        class="mt-1.5 block rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-body text-on-surface
                               focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                    @foreach ($periods as $option)
                        <option value="{{ $option->id }}" @selected((string) $option->id === (string) $period->id)>
                            {{ $option->label }} · {{ $option->starts_on->toDateString() }} – {{ $option->ends_on->toDateString() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="present" class="block text-label font-medium text-on-surface">{{ __('عملة العرض') }}</label>
                <select name="present" id="present" aria-describedby="why-present"
                        class="mt-1.5 block rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-body text-on-surface
                               focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                    <option value="">{{ __('العملة الوظيفية، كما تُحفظ الدفاتر') }}</option>
                    @foreach ($presentable as $code)
                        <option value="{{ $code }}" @selected($code === $present)>
                            {{ $code }} · {{ \App\Support\Iso\CurrencyRegistry::name($code, $locale) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <x-button type="submit">{{ __('اعرض') }}</x-button>
        </form>

        <p id="why-present" class="mt-2 max-w-2xl text-caption text-on-surface-muted">
            {{ __('تُعرَض فقط العملات التي يوجد لها سعر مسجَّل من العملة الوظيفية حتى نهاية الفترة. العرض بعملة أخرى ترجمةٌ للدفاتر وليس دفاتر ثانية.') }}
        </p>

        @if ($unknownPeriod)
            <p class="mt-4 rounded-card border border-outline bg-surface px-4 py-3 text-label text-on-surface-muted">
                {{ __('الفترة المطلوبة في الرابط ليست فترة في هذه الشركة، وما يظهر أدناه هو آخر فترة فيها قيود.') }}
            </p>
        @endif

        @if ($unknownCurrency)
            <p class="mt-4 rounded-card border border-outline bg-surface px-4 py-3 text-label text-on-surface-muted">
                {{ __('عملة العرض المطلوبة في الرابط ليست رمز عملة معروفًا، والأرقام أدناه بالعملة الوظيفية.') }}
            </p>
        @endif

        @if ($refusal !== null)
            {{--
                The report's refusal, in its own words.

                It names the pair, the date and what to record, and a
                paraphrase here would be a second explanation that drifts
                from the first the next time either is edited. A reader told
                "unavailable" opens a support ticket; a reader told which
                rate is missing records the rate.
            --}}
            <div role="alert" tabindex="-1"
                 class="mt-6 rounded-card border border-danger bg-danger-subtle px-4 py-3 text-label text-on-danger-subtle">
                <h2 class="font-medium">{{ __('لم يُصدَر التقرير') }}</h2>
                <p dir="ltr" lang="en" class="mt-2 text-start">{{ $refusal }}</p>
            </div>
        @elseif ($report !== null)
            @php
                $currency = (string) $report['currency'];
                $declaration = $report['declaration'];
                $balances = (bool) $report['balances'];
                $rows = $report['rows'];

                /*
                 | The totals, accumulated as Money in the currency on the
                 | page.
                 |
                 | Each column sums to zero across the whole ledger, so its
                 | debit side and its credit side are the same figure when
                 | the ledger is intact - and are visibly different when it
                 | is not, which is the second place a reader can see the
                 | breakage the banner already named.
                 */
                $zero = \App\Support\Money\Money::zero($currency);
                $totals = [];

                foreach (array_keys($columns) as $column) {
                    $debit = $zero;
                    $credit = $zero;

                    foreach ($rows as $row) {
                        $money = \App\Support\Money\Money::of((string) $row[$column], $currency);

                        if ($money->isPositive()) {
                            $debit = $debit->plus($money);
                        }

                        if ($money->isNegative()) {
                            $credit = $credit->plus($money->negated());
                        }
                    }

                    $totals[$column] = [
                        'debit' => (string) $debit->toPresentation(),
                        'credit' => (string) $credit->toPresentation(),
                    ];
                }

                $translation = $report['translation'] ?? null;
            @endphp

            {{--
                The verdict, before the figures.

                Both languages, always. This is the one sentence on the page
                that has to survive being printed and put in front of an
                auditor, and a page that rendered only the reader's language
                would drop the other from the paper the auditor is holding.
            --}}
            <section role="{{ $balances ? 'status' : 'alert' }}" tabindex="-1"
                     @class([
                        'avoid-break mt-6 rounded-card border px-4 py-4',
                        'border-success bg-success-subtle text-on-success-subtle' => $balances,
                        'border-danger bg-danger-subtle text-on-danger-subtle' => ! $balances,
                     ])>
                <h2 class="text-title font-semibold">
                    @if ($balances)
                        {{ __('الميزان متوازن') }}
                    @else
                        <span aria-hidden="true">!</span> {{ __('الميزان غير متوازن، وكل رقم أدناه غير موثوق') }}
                    @endif
                </h2>

                <p class="mt-2 text-body">{{ $declaration['ar'] }}</p>
                <p dir="ltr" lang="en" class="mt-1 text-start text-caption">{{ $declaration['en'] }}</p>
            </section>

            @if ($translation !== null)
                {{--
                    The rate, its date and its provider, beside the figures.

                    A figure in a currency the books are not kept in is a
                    translation, and a translation nobody can reproduce is a
                    number that reconciles to nothing - which is the only
                    thing a reader can do with it when it disagrees with
                    theirs. So the basis travels with the report rather than
                    living in a tooltip a printout drops.
                --}}
                <section class="avoid-break mt-4 rounded-card border border-insight bg-insight-subtle px-4 py-4 text-on-insight-subtle">
                    <h2 class="text-label font-semibold">{{ __('أساس الترجمة إلى عملة العرض') }}</h2>

                    <dl class="mt-3 grid grid-cols-2 gap-3 text-label sm:grid-cols-4">
                        <div>
                            <dt class="text-caption opacity-80">{{ __('سعر الصرف') }}</dt>
                            <dd dir="ltr" class="figure font-semibold">{{ $translation['rate'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-caption opacity-80">{{ __('تاريخ السعر') }}</dt>
                            <dd dir="ltr" class="figure">{{ $translation['as_of'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-caption opacity-80">{{ __('مصدر السعر') }}</dt>
                            <dd dir="ltr">{{ $translation['source'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-caption opacity-80">{{ __('مجموعة الأسعار') }}</dt>
                            <dd dir="ltr">{{ $translation['rate_set'] }}</dd>
                        </div>
                    </dl>

                    <p dir="ltr" lang="en" class="mt-3 text-start text-caption">{{ $translation['why'] }}</p>
                </section>
            @endif

            <div class="mt-6 overflow-x-auto rounded-card border border-outline">
                <table class="w-full text-label">
                    <caption class="sr-only">{{ __('ميزان المراجعة') }}</caption>

                    <thead class="bg-surface-sunken text-on-surface-muted">
                        <tr>
                            <th scope="col" rowspan="2" class="px-4 py-3 text-start font-medium">{{ __('رمز الحساب') }}</th>
                            <th scope="col" rowspan="2" class="px-4 py-3 text-start font-medium">{{ __('اسم الحساب') }}</th>
                            @foreach ($columns as $heading)
                                <th scope="colgroup" colspan="2" class="border-s border-outline-subtle px-4 py-3 text-center font-medium">
                                    {{ $heading }}
                                </th>
                            @endforeach
                        </tr>
                        <tr>
                            {{--
                                Each sub-header names its group for a screen
                                reader.

                                "Debit" six times is six identical headers, and
                                a reader moving across the row is told the same
                                word for the opening balance and the closing
                                one. The group name is visible to sighted
                                readers as the header above; it has to be IN
                                the cell for anybody who cannot see that.
                            --}}
                            @foreach ($columns as $heading)
                                <th scope="col" data-figure class="border-s border-outline-subtle px-4 py-2 font-medium">
                                    <span class="sr-only">{{ $heading }}</span>{{ __('مدين') }}
                                </th>
                                <th scope="col" data-figure class="px-4 py-2 font-medium">
                                    <span class="sr-only">{{ $heading }}</span>{{ __('دائن') }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-outline-subtle bg-surface">
                        @forelse ($rows as $row)
                            @php
                                $code = (string) $row['code'];
                                $depth = (int) ($depths[$code] ?? 0);
                                $indent = $indents[min($depth, count($indents) - 1)];
                                $name = $locale === 'ar' ? (string) $row['name_ar'] : (string) $row['name_en'];
                                $other = $locale === 'ar' ? (string) $row['name_en'] : (string) $row['name_ar'];
                            @endphp

                            <tr>
                                <td class="px-4 py-2.5">
                                    {{--
                                        The code is an identifier: monospace so
                                        the segments line up down the column,
                                        and `dir="ltr"` so `1.1.10` is not
                                        reordered by the Arabic around it.
                                    --}}
                                    <span dir="ltr" class="figure font-mono text-on-surface-muted">{{ $code }}</span>
                                </td>

                                <td class="px-4 py-2.5">
                                    <span @class(['inline-block', $indent])>
                                        <span class="text-on-surface">{{ $name !== '' ? $name : $code }}</span>
                                        @if ($other !== '' && $other !== $name)
                                            <span class="block text-caption text-on-surface-subtle">{{ $other }}</span>
                                        @endif
                                    </span>
                                </td>

                                @foreach (array_keys($columns) as $column)
                                    @php($figures = $split((string) $row[$column], $currency))

                                    <td data-figure class="border-s border-outline-subtle px-4 py-2.5 text-on-surface">
                                        {{ $figures['debit'] ?? '—' }}
                                    </td>
                                    <td data-figure class="px-4 py-2.5 text-on-surface">
                                        {{ $figures['credit'] ?? '—' }}
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-on-surface-muted">
                                    {{ __('لا حساب تحرّك في هذه الفترة ولا رصيد مفتوح فيها.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>

                    @if (count($rows) > 0)
                        <tfoot class="border-t-2 border-outline-strong bg-surface-sunken font-semibold text-on-surface">
                            <tr>
                                <th scope="row" colspan="2" class="px-4 py-3 text-start">{{ __('الإجمالي') }}</th>
                                @foreach (array_keys($columns) as $column)
                                    <td data-figure class="border-s border-outline-subtle px-4 py-3">{{ $totals[$column]['debit'] }}</td>
                                    <td data-figure class="px-4 py-3">{{ $totals[$column]['credit'] }}</td>
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            {{--
                What the figures were built from.

                A statement that does not say whether it includes the
                adjusting period cannot be compared to another one: the same
                entity, the same date, two different figures and nothing on
                either page to say why. The sentence also says whether the
                choice made any difference, which it does not on a range that
                holds no adjustments.
            --}}
            <div class="mt-4 flex flex-wrap items-baseline justify-between gap-3 text-caption text-on-surface-muted">
                <p class="max-w-3xl">{{ $report['basis'][$locale === 'ar' ? 'declaration_ar' : 'declaration_en'] }}</p>
                <p>
                    {{ __('عدد الحسابات') }}
                    <span dir="ltr" class="figure font-semibold text-on-surface">{{ count($rows) }}</span>
                </p>
            </div>
        @endif
    @endif
@endsection
