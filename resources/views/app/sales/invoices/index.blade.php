@extends('layouts.app', ['title' => __('الفواتير'), 'wide' => true])

{{--
    The invoice list.

    A reading screen, and a dense one: somebody reconciling a customer's
    remittance against this page is scanning a column of figures, not
    admiring a layout. So the figures are at the density's own size rather
    than the editorial size, every numeric cell is tabular, and the only
    colour in the table is on the status pill.

    Three states, kept apart on purpose: no permission to see the amounts, an
    entity with no invoices yet (which says what produces the first one, not
    "no results"), and the table.

    The open balance in the last column is the DATABASE's answer, selected in
    the controller. Nothing on this page recomputes it.
--}}

@section('content')
    @php
        $locale = app()->getLocale();
        $today = today()->toDateString();

        // The display form of an amount: grouped, at the currency's exponent,
        // and the ACCOUNTING negative - parentheses, never a bare minus.
        $figure = static function (string $amount, string $currency) use ($locale): string {
            $money = \App\Support\Money\Money::of($amount, $currency);
            $formatted = $money->abs()->format($locale, false);

            return $money->isNegative() ? '('.$formatted.')' : $formatted;
        };

        /*
         | THE due date of a schedule is the LAST instalment's - EN 16931
         | BT-9.
         |
         | Showing the first would tell a reader the whole amount falls due
         | then, and that is the date their ageing report would use. An
         | invoice on four instalments has one payment due date in the
         | standard and a schedule behind it.
         */
        $dueDate = static function (array $schedule): ?string {
            $last = end($schedule);

            return \is_array($last) && \is_string($last['date'] ?? null) ? $last['date'] : null;
        };
    @endphp

    <x-page-header :title="__('الفواتير')">
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

    @if ($invoices === null)
        {{-- Signed in, in an entity, without `report.view`. --}}
        <div class="mt-8 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
            {{ __('لا تملك صلاحية عرض الأرقام المالية لهذه الشركة.') }}
        </div>
    @elseif ($invoices->total() === 0)
        {{--
            Nothing issued yet.

            It says what makes the first invoice rather than reporting an
            empty result, because "no results" on a page somebody opened for
            the first time reads as a product that is not working.
        --}}
        <section class="mt-8 rounded-tile border border-outline bg-surface-container-low p-8 sm:p-12">
            <h2 class="text-title font-semibold text-on-surface">{{ __('لم تُسجَّل فواتير لهذه الشركة بعد') }}</h2>
            <p class="mt-3 max-w-xl text-body text-on-surface-muted">
                {{ __('أول فاتورة تُنشَأ من المبيعات، أو من عرض سعر قبله العميل، وتظهر هنا بمجرد حفظها كمسودة.') }}
            </p>
        </section>
    @else
        @php
            /*
             | The column declaration is the ONLY place a column exists.
             |
             | The grid numbers every column from this list, hidden ones
             | included, and every cell inherits its accessible name from
             | the label here through `headers`. A heading written in the
             | markup instead would be a second list, and the first column
             | somebody reordered would leave the cells naming the wrong one.
             */
            $columns = [
                ['key' => 'number', 'label' => __('الرقم'), 'rowheader' => true, 'pinned' => true],
                ['key' => 'customer', 'label' => __('العميل')],
                ['key' => 'issued', 'label' => __('تاريخ الإصدار')],
                ['key' => 'due', 'label' => __('تاريخ الاستحقاق')],
                ['key' => 'gross', 'label' => __('الإجمالي'), 'figure' => true],
                ['key' => 'status', 'label' => __('الحالة')],
                ['key' => 'open', 'label' => __('الرصيد المفتوح'), 'figure' => true],
            ];
        @endphp

        <x-grid name="invoices"
                class="mt-8"
                :caption="__('فواتير هذه الشركة، الأحدث أولًا')"
                :columns="$columns"
                :paginator="$invoices"
                :filtered="request()->filled('q')">
            @foreach ($invoices as $invoice)
                @php
                    $due = $dueDate($invoice->dueDates());
                    $open = \App\Support\Money\Money::of($invoice->open_balance, $invoice->currency);
                    $overdue = $invoice->isIssued() && $open->isPositive() && $due !== null && $due < $today;
                @endphp

                <x-grid.row :index="$loop->index" :row-key="$invoice->id">
                    {{--
                        A draft carries a stripe on the leading edge AND the
                        word. The word is what carries it: a cue that is only
                        a stripe does not survive a photocopy, a high-contrast
                        theme or a reader who cannot see it.
                    --}}
                    <x-grid.cell column="number"
                                 :class="['border-s-2 border-draft-stripe' => $invoice->isDraft()]">
                        <a href="{{ route('invoices.show', $invoice) }}"
                           class="figure font-medium text-link underline-offset-2 hover:underline
                                  focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                            {{ $invoice->number ?? __('مسودة بلا رقم') }}
                        </a>
                        <span class="mt-0.5 block text-caption text-on-surface-muted">
                            {{ $invoice->document_type->label() }}
                        </span>
                    </x-grid.cell>

                    <x-grid.cell column="customer" class="whitespace-nowrap">{{ $invoice->customerName() }}</x-grid.cell>

                    <x-grid.cell column="issued" :value="$invoice->issue_date?->toDateString()">
                        <time datetime="{{ $invoice->issue_date?->toDateString() }}" class="figure">
                            {{ $invoice->issue_date?->toDateString() }}
                        </time>
                    </x-grid.cell>

                    <x-grid.cell column="due" :value="$due">
                        @if ($due !== null)
                            <time datetime="{{ $due }}" class="figure">{{ $due }}</time>
                        @else
                            <span class="text-on-surface-subtle">—</span>
                        @endif
                    </x-grid.cell>

                    {{--
                        `:value` is what the clipboard and the TSV carry, so
                        it is the plain figure. Copying a formatted one pastes
                        into a spreadsheet as text, and the column somebody
                        wanted to total becomes a column of strings.
                    --}}
                    <x-grid.cell column="gross" :value="$invoice->gross_amount">
                        {{ $figure($invoice->gross_amount, $invoice->currency) }}
                        <span class="text-caption font-normal text-on-surface-muted">{{ $invoice->currency }}</span>
                    </x-grid.cell>

                    <x-grid.cell column="status">
                        @if ($invoice->isDraft())
                            <span class="inline-flex items-center gap-1 rounded-full bg-surface-container-high px-2 py-0.5 text-caption font-medium text-on-surface-muted">
                                <span aria-hidden="true">●</span>{{ __('مسودة') }}
                            </span>
                        @elseif ($overdue)
                            <span class="inline-flex items-center gap-1 rounded-full bg-warning-subtle px-2 py-0.5 text-caption font-medium text-on-warning-subtle">
                                <span aria-hidden="true">!</span>{{ __('متأخرة') }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full bg-success-subtle px-2 py-0.5 text-caption font-medium text-on-success-subtle">
                                <span aria-hidden="true">✓</span>{{ __('مُصدرة') }}
                            </span>
                        @endif
                    </x-grid.cell>

                    {{--
                        A draft has no open balance, and the function cannot
                        say so: it answers "gross, less what has relieved it",
                        and nothing has relieved a document the customer has
                        never seen. Printing its gross would read as an unpaid
                        debt, so the cell carries no `:value` either - an empty
                        clipboard cell is honest and a zero is not.
                    --}}
                    <x-grid.cell column="open"
                                 :value="$invoice->isDraft() ? null : $invoice->open_balance">
                        @if ($invoice->isDraft())
                            <span class="font-normal text-on-surface-subtle">—</span>
                        @else
                            {{ $figure($invoice->open_balance, $invoice->currency) }}
                            <span class="text-caption font-normal text-on-surface-muted">{{ $invoice->currency }}</span>
                        @endif
                    </x-grid.cell>
                </x-grid.row>
            @endforeach

            <x-slot:empty>
                {{ __('أول فاتورة تُنشَأ من المبيعات، أو من عرض سعر قبله العميل، وتظهر هنا بمجرد حفظها كمسودة.') }}
            </x-slot:empty>
        </x-grid>

        <p class="mt-3 text-caption text-on-surface-muted">
            {{ __('الرصيد المفتوح هو الإجمالي بعد خصم الإشعارات الدائنة والدفعات المخصَّصة وفروق المطابقة والمبالغ المشطوبة.') }}
        </p>
        <p class="mt-1 text-caption text-on-surface-muted">
            {{ __('ولا يظهر إلا للفواتير المُصدَرة؛ المسودة لم تُطالَب بها بعد، فلا رصيد عليها.') }}
        </p>

        {{--
            The paginator is built from the paginator's own methods rather than
            rendered by the framework's view.

            That view is Latin-first: it spaces its arrows with physical
            margins, so they sit on the wrong side of the row under RTL, and
            its labels come from a `pagination.*` catalog this product does
            not have, so it prints the key instead of a word. Both are
            invisible to whoever adds the one-liner and obvious to every
            Arabic reader.

            A page the reader is already on is a <span>, not a disabled link:
            a link that goes nowhere is still a tab stop.
        --}}
        @if ($invoices->hasPages())
            <nav aria-label="{{ __('صفحات الفواتير') }}" class="mt-6 flex flex-wrap items-center justify-between gap-3">
                <p class="text-caption text-on-surface-muted">
                    {{ __('تُعرض :first–:last من أصل :total', [
                        'first' => $invoices->firstItem(),
                        'last' => $invoices->lastItem(),
                        'total' => $invoices->total(),
                    ]) }}
                </p>

                <div class="flex items-center gap-2">
                    @if ($invoices->onFirstPage())
                        <span class="inline-flex min-h-11 items-center rounded-button border border-outline px-3 py-1.5 text-label text-on-surface-subtle">
                            {{ __('السابق') }}
                        </span>
                    @else
                        <a href="{{ $invoices->previousPageUrl() }}" rel="prev"
                           class="inline-flex min-h-11 items-center rounded-button border border-outline-strong px-3 py-1.5 text-label font-medium text-on-surface
                                  hover:bg-surface-hover
                                  focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                            {{ __('السابق') }}
                        </a>
                    @endif

                    @if ($invoices->hasMorePages())
                        <a href="{{ $invoices->nextPageUrl() }}" rel="next"
                           class="inline-flex min-h-11 items-center rounded-button border border-outline-strong px-3 py-1.5 text-label font-medium text-on-surface
                                  hover:bg-surface-hover
                                  focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                            {{ __('التالي') }}
                        </a>
                    @else
                        <span class="inline-flex min-h-11 items-center rounded-button border border-outline px-3 py-1.5 text-label text-on-surface-subtle">
                            {{ __('التالي') }}
                        </span>
                    @endif
                </div>
            </nav>
        @endif
    @endif
@endsection
