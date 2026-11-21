@extends('layouts.app', ['title' => __('قيد يدوي'), 'wide' => true])

{{--
    The manual journal screen - PF-055.

    A typing screen rather than a reading one, and that changes every
    decision on it. The grid is the subject of the page, the keyboard is how
    it is used, and the imbalance is the one figure a person looks at between
    keystrokes.

    ## The imbalance is a figure with a side

    "Short 90.00 on the credit side", not "-90.00". PF-055's own criterion
    says why: the amount an entry is out by is what tells somebody which
    mistake they made - 90 is a transposition, twice the amount is a sign, a
    round figure is a missing line. A signed number makes them work out which
    way round the convention goes before they can start.

    ## Where the figure comes from

    The server, on every keystroke, through `POST /ledger/journals/check`.
    Nothing on this page adds up the amount column: `ManualJournal::validate()`
    resolves the accounts, refuses a control account, reads Arabic-Indic
    digits and thousands separators, translates a foreign line at a rate with
    a provenance and rounds to the policy's scale. A browser summing
    `parseFloat` would agree on the easy rows and disagree on the ones
    somebody is about to post.

    ## With no script

    Two submit buttons on one form. "Check" revalidates and re-renders with
    the verdict; "Post" posts. Everything the live imbalance shows is on the
    page after a Check, in both languages, with every problem beside its
    line - because a finance screen that needs JavaScript to say an entry
    does not balance will one day post an unbalanced entry on a machine with
    a content blocker.
--}}

@section('content')
    @php
        $locale = app()->getLocale();

        $columns = [
            [
                'key' => 'account_code',
                'label' => __('الحساب'),
                'editable' => true,
                'rowheader' => true,
                'minWidth' => '12rem',
            ],
            [
                'key' => 'amount',
                'label' => __('المبلغ'),
                'editable' => true,
                'figure' => true,
            ],
            [
                'key' => 'currency',
                'label' => __('العملة'),
                'editable' => true,
                'width' => '7rem',
            ],
            [
                'key' => 'narration',
                'label' => __('الشرح'),
                'editable' => true,
                'minWidth' => '16rem',
            ],
        ];

        /*
         | Problems indexed by line and field, so a cell can find its own.
         |
         | PF-055: every problem names its line AND its field, so the
         | message lands next to the input that caused it. A flat list at
         | the top of the page is the version that gets built by default,
         | and it makes somebody count rows.
         */
        $problems = [];

        foreach ($verdict?->problems ?? [] as $problem) {
            $problems[$problem->line][$problem->field][] = $problem->message($locale);
        }
    @endphp

    <x-page-header :title="__('قيد يدوي')"
                   :description="__('حسابان على الأقل، والطرفان متساويان. الفرق يظهر أثناء الكتابة، بجانبه الطرف الذي ينقص.')" />

    @if ($posted !== null)
        <p class="mt-6 rounded-card border border-outline bg-surface p-4 text-label text-on-surface"
           data-pf-journal-posted
           role="status">
            {{ __('تم ترحيل القيد') }} <span class="figure font-medium">{{ $posted }}</span>.
        </p>
    @endif

    @if ($refusal !== null)
        <p class="mt-6 rounded-card border border-outline-strong bg-surface p-4 text-label text-on-surface"
           data-pf-journal-refusal
           role="alert">
            {{ $refusal }}
        </p>
    @endif

    <form method="POST"
          id="journal-form"
          action="{{ route('journals.store') }}"
          data-pf-journal
          data-pf-journal-check="{{ route('journals.check') }}"
          class="mt-8 flow-tight">
        @csrf

        <div class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
            <div>
                <label for="journal-date" class="block text-label text-on-surface-muted">
                    {{ __('تاريخ القيد') }}
                </label>
                <input type="date"
                       id="journal-date"
                       name="accounting_date"
                       value="{{ $accountingDate }}"
                       class="min-h-(--control-height) mt-1 w-full rounded-control border border-outline-control bg-surface px-3 text-on-surface
                              focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
            </div>

            <div>
                <label for="journal-narration" class="block text-label text-on-surface-muted">
                    {{ __('شرح القيد') }}
                </label>
                <input type="text"
                       id="journal-narration"
                       name="narration"
                       value="{{ $narration }}"
                       class="min-h-(--control-height) mt-1 w-full rounded-control border border-outline-control bg-surface px-3 text-on-surface
                              focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
            </div>
        </div>
    </form>

    {{--
        The grid is a SIBLING of the form, not a child of it.

        It renders forms of its own - the column-visibility control among
        them - and a form inside a form is not nested by any parser: the
        inner start tag is ignored and the inner `</form>` closes the outer
        one. Measured here with the grid inside the form: the imbalance
        paragraph and both submit buttons ended up OUTSIDE it, and the
        script that reads the form found no output to write into. Nothing in
        the markup looked wrong.

        Every editable cell carries `form="journal-form"` instead, which is
        HTML's own way of saying which form a field submits with, and so do
        the two buttons.
    --}}
    <x-grid name="lines"
            owned-by="journal-form"
            :caption="__('سطور القيد')"
            :columns="$columns"
            :total="count($rows)">
        @foreach ($rows as $index => $row)
            <x-grid.row :index="$index" :row-key="$index">
                @foreach (['account_code', 'amount', 'currency', 'narration'] as $field)
                    <x-grid.cell :column="$field"
                                 :value="$row[$field]"
                                 :input="[
                                     'value' => $row[$field],
                                     'list' => $field === 'account_code' ? 'journal-accounts' : null,
                                     'aria-invalid' => isset($problems[$index + 1][$field]) ? 'true' : null,
                                 ]">
                        {{ $row[$field] }}

                        @foreach ($problems[$index + 1][$field] ?? [] as $message)
                            <span class="mt-1 block text-caption text-on-surface-muted">{{ $message }}</span>
                        @endforeach
                    </x-grid.cell>
                @endforeach
            </x-grid.row>
        @endforeach
    </x-grid>

    {{--
        The account codes, as a datalist.

        A `<datalist>` rather than a `<select>`: the cell is a text input
        because PF-055 asks that a code somebody is half way through be
        representable, and a select cannot hold "1.1". The list suggests;
        the input accepts anything and `validate()` explains what it cannot
        resolve.

        Control accounts are NOT in the list, and a code typed in anyway is
        refused with the reason rather than ignored.
    --}}
    <datalist id="journal-accounts">
        @foreach ($accounts as $account)
            <option value="{{ $account['code'] }}">{{ $account['label'] }}</option>
        @endforeach
    </datalist>

    <div class="flex flex-wrap items-center justify-between gap-4">
        {{--
            The running imbalance.

            `aria-live="polite"` because it changes as somebody types and
            interrupting them mid-figure is worse than telling them a
            moment later. The server's sentence goes in here verbatim.
        --}}
        <p class="text-label text-on-surface"
           data-pf-journal-imbalance
           aria-live="polite"
           role="status">
            @if ($verdict === null)
                {{ __('اكتب سطرين على الأقل.') }}
            @elseif ($verdict->imbalanceSide() === null)
                {{ __('الطرفان متساويان.') }}
            @else
                {{ __('ناقص') }}
                <span class="figure font-medium">{{ $verdict->imbalance->abs()->toPresentation() }}</span>
                {{ $verdict->imbalanceSide() === 'credit' ? __('في طرف الدائن.') : __('في طرف المدين.') }}
            @endif
        </p>

        <div class="flex flex-wrap gap-3">
            <x-button type="submit" form="journal-form" name="intent" value="check" variant="outline">
                {{ __('تحقّق') }}
            </x-button>

            <x-button type="submit" form="journal-form" name="intent" value="post">
                {{ __('ترحيل') }}
            </x-button>
        </div>
    </div>

    @if ($verdict !== null)
        <dl class="grid gap-x-6 gap-y-2 text-label sm:grid-cols-3">
            <div>
                <dt class="text-on-surface-muted">{{ __('مجموع المدين') }}</dt>
                <dd class="figure text-on-surface" data-pf-journal-debits>
                    {{ $verdict->debits->toPresentation() }}
                </dd>
            </div>

            <div>
                <dt class="text-on-surface-muted">{{ __('مجموع الدائن') }}</dt>
                <dd class="figure text-on-surface" data-pf-journal-credits>
                    {{ $verdict->credits->toPresentation() }}
                </dd>
            </div>

            <div>
                <dt class="text-on-surface-muted">{{ __('سطور فارغة') }}</dt>
                <dd class="figure text-on-surface">{{ $verdict->blankLines }}</dd>
            </div>
        </dl>
    @endif

    {{--
        The keystroke listener, inlined for the same reason as the keyboard
        layer: `@vite()` on a missing manifest entry throws, which turns a
        forgotten build into a 500 on a page whose only loss should have
        been a figure that updates a moment later.
    --}}
    @once('pf-journal-imbalance')
        <script id="pf-journal-imbalance" @cspNonce>{!! file_get_contents(resource_path('js/journal-imbalance.js')) !!}</script>
    @endonce
@endsection
