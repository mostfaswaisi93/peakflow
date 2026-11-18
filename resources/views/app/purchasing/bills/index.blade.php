@extends('layouts.app', ['title' => __('فواتير الموردين'), 'wide' => true])

{{--
    The supplier bills - PF-133.

    One row is one document, and the row answers the two questions somebody
    opens this page with: how much is still owed on it, and has anybody
    checked it. The approval is given a column of its own rather than being
    folded into the status, because the status of an approved bill and the
    status of an unapproved one can both read "draft" on the way through a
    chain - and an unapproved bill sitting in a list beside approved ones is
    how somebody comes to pay what nobody checked.

    Marked three ways, never by colour alone: the words in the chip, a glyph
    inside it, and a border down the start edge of the row. Forced-colours
    mode drops the background and the border colour; the words survive it.

    Two states are kept apart here, and the third is not this page's: a user
    without `report.view` gets a sentence instead of amounts, an entity with
    no bills gets a welcome rather than an empty grid, and a request that
    FAILED gets the error page - there is no filter on this screen, so "no
    rows" has exactly one cause.
--}}

@section('content')
    @php
        $locale = app()->getLocale();

        // The display form of an amount: grouped, at the currency's exponent,
        // and the ACCOUNTING negative - parentheses, never a bare minus.
        //
        // Null-tolerant because `peakflow_document_open_balance` returns NULL
        // for a document type it does not know. A template that printed zero
        // there would say the bill is settled.
        $figure = static function (?string $amount, string $currency) use ($locale): string {
            if ($amount === null) {
                return '—';
            }

            $money = \App\Support\Money\Money::of($amount, $currency);
            $formatted = $money->abs()->format($locale, false);

            return $money->isNegative() ? '('.$formatted.')' : $formatted;
        };

        // Computed HERE, not in an attribute: the template-text rule reads
        // tags with a plain parser, so attributes carry plain variables only.
        $statuses = [
            'draft' => __('مسودة'),
            'awaiting_approval' => __('بانتظار الاعتماد'),
            'approved' => __('معتمدة'),
            'posted' => __('مرحَّلة'),
            'cancelled' => __('ملغاة'),
        ];

        $types = [
            '380' => __('فاتورة مورد'),
            '381' => __('إشعار دائن'),
            '383' => __('إشعار مدين'),
        ];

        $approvedLabel = __('معتمدة');
        $unapprovedLabel = __('غير معتمدة');
        $pagerLabel = __('تنقّل بين الصفحات');

        /*
         | Cancelled documents are left out of the count.
         |
         | A cancelled bill is never going to be paid, so counting it as
         | unapproved would raise a warning about a document nobody can act
         | on - and a warning that is usually about nothing is a warning
         | people stop reading.
         */
        $unapproved = $bills === null ? 0 : $bills->getCollection()
            ->filter(static fn ($bill): bool => $bill->approved_at === null && $bill->status !== 'cancelled')
            ->count();
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-headline font-bold text-on-surface">{{ __('فواتير الموردين') }}</h1>
            <p class="mt-1 text-label text-on-surface-muted">
                {{ __('أنت تعمل الآن في :entity.', ['entity' => $entity?->legal_name]) }}
            </p>
        </div>

        @if ($bills !== null && $bills->total() > 0)
            <p class="text-caption text-on-surface-muted">
                {{ __('الأحدث ترحيلًا أولًا. تاريخ المستند هو تاريخ المورد، والاستحقاق آخر قسط في شروط السداد المثبَّتة.') }}
            </p>
        @endif
    </div>

    @if ($bills === null)
        {{-- Signed in, in an entity, without `report.view`. --}}
        <div class="mt-8 rounded-card border border-outline bg-surface p-6 text-label text-on-surface-muted">
            {{ __('لا تملك صلاحية عرض الأرقام المالية لهذه الشركة.') }}
        </div>
    @elseif ($bills->total() === 0)
        {{-- Nothing recorded yet: a page that says what to do, not "no data". --}}
        <section class="mt-8 rounded-tile border border-outline bg-surface p-8 sm:p-12">
            <h2 class="text-title font-semibold text-on-surface">{{ __('لا توجد فواتير موردين بعد') }}</h2>
            <p class="mt-3 max-w-xl text-body text-on-surface-muted">
                {{ __('أدخِل فاتورة مورد، أو وجِّه الموردين إلى بريد استلام الفواتير، أو استلمها عبر شبكة الفاتورة الإلكترونية. يظهر المستند هنا أول ما يُسجَّل، ومعه رصيده المفتوح وحالة اعتماده.') }}
            </p>
        </section>
    @else
        @if ($unapproved > 0)
            {{--
                The count above the table, before anything is read.

                A reader who scans the rows and stops at the first few has
                still been told. The sentence under it says what the
                consequence is, because "not approved" is a state and "do
                not pay this" is the instruction.
            --}}
            <div class="mt-6 flex items-start gap-3 rounded-card border border-warning bg-warning-subtle p-4 text-label text-on-warning-subtle">
                <span aria-hidden="true" class="font-semibold">!</span>
                <div>
                    <p class="font-medium">
                        {{ \App\Support\Lang\Message::of('bills.unapproved_on_page', ['count' => $unapproved]) }}
                    </p>
                    <p class="mt-1">{{ __('لا تُسدَّد فاتورة غير معتمدة. الاعتماد هو الضابط الوحيد على هذا الجانب.') }}</p>
                </div>
            </div>
        @endif

        {{-- The scroll is on the wrapper, not the page: the shell's content
             column is `min-w-0` precisely so a wide table cannot push the
             navigation off screen. --}}
        <div class="mt-6 overflow-x-auto rounded-card border border-outline bg-surface">
            <table class="w-full text-start text-label">
                <caption class="sr-only">{{ __('فواتير الموردين') }}</caption>
                <thead>
                    <tr class="border-b border-outline text-on-surface-subtle">
                        <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('رقم المستند عند المورد') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('المورد') }}</th>
                        <th scope="col" data-figure class="px-4 py-3 font-medium">{{ __('تاريخ المستند') }}</th>
                        <th scope="col" data-figure class="px-4 py-3 font-medium">{{ __('الاستحقاق') }}</th>
                        <th scope="col" data-figure class="px-4 py-3 font-medium">{{ __('الإجمالي') }}</th>
                        <th scope="col" data-figure class="px-4 py-3 font-medium">{{ __('الرصيد المفتوح') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الحالة') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الاعتماد') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bills as $bill)
                        @php
                            $approved = $bill->approved_at !== null;
                            $cancelled = $bill->status === 'cancelled';
                            $currency = (string) $bill->currency;
                            $number = (string) $bill->supplier_document_number;
                        @endphp

                        <tr class="border-b border-outline-subtle last:border-b-0">
                            {{-- The start edge carries the warning, so an
                                 unapproved row is visible while scanning the
                                 numbers rather than only on the far column. --}}
                            <th scope="row" @class([
                                    'border-s-2 px-4 py-3 text-start font-normal',
                                    ! $approved && ! $cancelled ? 'border-warning' : 'border-transparent',
                                ])>
                                <a href="{{ route('bills.show', $bill) }}"
                                   dir="ltr"
                                   class="figure rounded-sm font-medium text-link hover:underline
                                          focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                                    {{ $number }}
                                </a>
                                <span class="mt-0.5 block text-caption text-on-surface-muted">
                                    {{ $types[(string) $bill->document_type] ?? $bill->document_type }}
                                </span>
                            </th>

                            <td class="px-4 py-3 text-on-surface">
                                {{ $bill->supplierName() ?? __('غير مذكور') }}
                            </td>

                            <td data-figure dir="ltr" class="px-4 py-3 text-on-surface-muted">
                                {{ $bill->supplier_document_date?->toDateString() }}
                            </td>

                            <td data-figure dir="ltr" class="px-4 py-3 text-on-surface-muted">
                                {{ $bill->due_on }}
                            </td>

                            <td data-figure dir="ltr" class="px-4 py-3 text-on-surface">
                                {{ $figure((string) $bill->gross_amount, $currency) }}
                                <span class="figure-unit">{{ $currency }}</span>
                            </td>

                            <td data-figure dir="ltr" class="px-4 py-3 font-medium text-on-surface">
                                {{ $figure($bill->open_balance, $currency) }}
                                <span class="figure-unit">{{ $currency }}</span>
                            </td>

                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full bg-surface-container-low px-2 py-0.5 text-caption text-on-surface-muted">
                                    {{ $statuses[(string) $bill->status] ?? $bill->status }}
                                </span>
                            </td>

                            <td class="px-4 py-3">
                                {{-- A cancelled document gets a dash, not a
                                     chip: nobody is going to pay it, so its
                                     approval state is moot, and a warning
                                     raised over something nobody can act on
                                     is a warning people learn to read past. --}}
                                @if ($cancelled)
                                    <span class="text-on-surface-subtle">—</span>
                                @elseif ($approved)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-success-subtle px-2 py-0.5 text-caption font-medium text-on-success-subtle">
                                        <span aria-hidden="true">✓</span>{{ $approvedLabel }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-warning-subtle px-2 py-0.5 text-caption font-medium text-on-warning-subtle">
                                        <span aria-hidden="true">!</span>{{ $unapprovedLabel }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($bills->hasPages())
            {{--
                A pager written in the design tokens.

                The framework's own pagination view is written in palette
                colours with a `dark:` variant, and this product has five
                themes selected on `<html>` - two of them high contrast - so
                those colours cannot be re-pointed and the pager would be the
                one element on the page that ignores the user's choice.
            --}}
            <nav aria-label="{{ $pagerLabel }}" class="mt-4 flex flex-wrap items-center justify-between gap-3 text-label">
                <p class="text-on-surface-muted">
                    {{ __('صفحة :page من :pages', ['page' => $bills->currentPage(), 'pages' => $bills->lastPage()]) }}
                </p>

                <div class="flex items-center gap-2">
                    @if ($bills->onFirstPage())
                        <span class="rounded-button border border-outline px-3 py-1.5 text-on-surface-subtle opacity-60">
                            {{ __('السابقة') }}
                        </span>
                    @else
                        <a href="{{ $bills->previousPageUrl() }}"
                           rel="prev"
                           class="rounded-button border border-outline-strong px-3 py-1.5 text-on-surface
                                  hover:bg-surface-hover
                                  focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                            {{ __('السابقة') }}
                        </a>
                    @endif

                    @if ($bills->hasMorePages())
                        <a href="{{ $bills->nextPageUrl() }}"
                           rel="next"
                           class="rounded-button border border-outline-strong px-3 py-1.5 text-on-surface
                                  hover:bg-surface-hover
                                  focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                            {{ __('التالية') }}
                        </a>
                    @else
                        <span class="rounded-button border border-outline px-3 py-1.5 text-on-surface-subtle opacity-60">
                            {{ __('التالية') }}
                        </span>
                    @endif
                </div>
            </nav>
        @endif
    @endif
@endsection
