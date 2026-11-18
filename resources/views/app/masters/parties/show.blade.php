@extends('layouts.app', ['title' => $party->legal_name, 'wide' => true])

{{--
    One party - PF-059, PF-061, PF-062.

    Three scopes on one page, and the headings keep them apart: the party's own
    fields are shared across the whole workspace, the credit limit and the open
    documents belong to the company you are signed in to. A customer billed by
    two of your companies owes each of them separately, and a page that mixed
    the two would read as one balance that is true of neither.

    The bank accounts are MASKED, and this page has no way to unmask them. The
    full number is read through BankDetailDisclosure, which asks for
    `bank.manage`, asks WHY, and records the read before it returns anything -
    because the fraud this guards is somebody reading a supplier's account to
    redirect a payment, and the change log would show nothing, since nothing
    changed. A screen that printed the IBAN would be that disclosure repeated
    on every page load, with no actor and no reason attached to any of them.

    Read-only: there is no form on this page at all.
--}}

@section('content')
    @php
        $locale = app()->getLocale();

        /*
         | The display form of an amount: grouped, at the currency's exponent,
         | and the ACCOUNTING negative - parentheses, never a bare minus. The
         | same closure as the Overview, because a figure that is formatted one
         | way on one screen and another way on the next teaches people to
         | distrust both.
         */
        $figure = static function (string $amount, string $currency) use ($locale): string {
            $money = \App\Support\Money\Money::of($amount, $currency);
            $formatted = $money->abs()->format($locale, false);

            return $money->isNegative() ? '('.$formatted.')' : $formatted;
        };

        $isActive = (bool) $party->active;

        $roles = array_values(array_filter([
            $party->is_customer ? __('عميل') : null,
            $party->is_supplier ? __('مورد') : null,
            $party->is_employee ? __('موظف') : null,
        ]));

        $documentLabels = [
            'invoice' => __('فاتورة'),
            'bill' => __('فاتورة مورد'),
        ];

        $creditCurrency = $entity?->functional_currency;
        $creditLimit = $creditCurrency === null ? null : $setting?->creditLimit($creditCurrency);
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-headline font-bold text-on-surface">{{ $party->legal_name }}</h1>

            <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-label text-on-surface-muted">
                @if ($party->code)
                    <span dir="ltr" class="figure">{{ $party->code }}</span>
                @endif
                @if ($party->trading_name)
                    <span>{{ $party->trading_name }}</span>
                @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
            @foreach ($roles as $role)
                <span class="inline-flex items-center rounded-full bg-primary-subtle px-2.5 py-0.5 text-caption font-medium text-on-primary-subtle">
                    {{ $role }}
                </span>
            @endforeach

            @if ($roles === [])
                <span class="inline-flex items-center rounded-full border border-outline px-2.5 py-0.5 text-caption text-on-surface-muted">
                    {{ __('بلا دور') }}
                </span>
            @endif

            @if ($isActive)
                <span class="inline-flex items-center rounded-full bg-success-subtle px-2.5 py-0.5 text-caption font-medium text-on-success-subtle">
                    {{ __('نشط') }}
                </span>
            @else
                <span class="inline-flex items-center gap-1 rounded-full bg-warning-subtle px-2.5 py-0.5 text-caption font-medium text-on-warning-subtle">
                    <span aria-hidden="true">!</span>{{ __('غير نشط') }}
                </span>
            @endif
        </div>
    </div>

    @if ($party->isMerged())
        {{--
            A merged party is kept rather than deleted, so that a document
            issued years ago still resolves the name it was issued to. It is
            also the record somebody will pick off a list by mistake, so it
            says what it is before anything else on the page.
        --}}
        <div role="alert" class="mt-6 rounded-card border border-warning bg-warning-subtle px-4 py-3 text-label text-on-warning-subtle">
            {{ __('دُمج هذا الطرف في طرف آخر، فلا يصلح لمستند جديد. وما صدر باسمه سابقًا يبقى كما هو.') }}

            @if ($party->merged_at)
                <time datetime="{{ $party->merged_at->toIso8601String() }}" dir="ltr" class="figure ms-1">{{ $party->merged_at->toDateString() }}</time>
            @endif
        </div>
    @endif

    <section class="mt-6 rounded-card border border-outline bg-surface p-6 shadow-e1">
        <h2 class="text-title font-semibold text-on-surface">{{ __('بيانات الطرف') }}</h2>
        <p class="mt-1 text-caption text-on-surface-muted">{{ __('هذه البيانات مشتركة بين شركات المساحة كلها.') }}</p>

        <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <div>
                <dt class="text-caption text-on-surface-muted">{{ __('الاسم النظامي') }}</dt>
                <dd class="mt-0.5 text-label text-on-surface">{{ $party->legal_name }}</dd>
            </div>
            <div>
                <dt class="text-caption text-on-surface-muted">{{ __('الاسم التجاري') }}</dt>
                <dd class="mt-0.5 text-label text-on-surface">{{ $party->trading_name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-caption text-on-surface-muted">{{ __('الرمز') }}</dt>
                <dd dir="ltr" class="figure mt-0.5 text-label text-on-surface">{{ $party->code ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-caption text-on-surface-muted">{{ __('الدولة') }}</dt>
                <dd dir="ltr" class="figure mt-0.5 text-label text-on-surface">{{ $party->country }}</dd>
            </div>
            <div>
                <dt class="text-caption text-on-surface-muted">{{ __('العملة الافتراضية') }}</dt>
                <dd dir="ltr" class="figure mt-0.5 text-label text-on-surface">{{ $party->default_currency ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-caption text-on-surface-muted">{{ __('لغة المخاطبة') }}</dt>
                <dd dir="ltr" class="figure mt-0.5 text-label text-on-surface">{{ $party->default_locale ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-caption text-on-surface-muted">{{ __('رقم السجل') }}</dt>
                <dd dir="ltr" class="figure mt-0.5 text-label text-on-surface">{{ $party->registration_number ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-caption text-on-surface-muted">{{ __('المعاملة الضريبية') }}</dt>
                <dd dir="ltr" class="figure mt-0.5 text-label text-on-surface">{{ $party->tax_treatment ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-caption text-on-surface-muted">{{ __('المجموعة') }}</dt>
                <dd class="mt-0.5 text-label text-on-surface">{{ $party->party_group ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-caption text-on-surface-muted">{{ __('درجة المخاطر') }}</dt>
                <dd dir="ltr" class="figure mt-0.5 text-label text-on-surface">{{ $party->risk_grade ?? '—' }}</dd>
            </div>
        </dl>
    </section>

    <section class="mt-6 rounded-card border border-outline bg-surface p-6 shadow-e1">
        <h2 class="text-title font-semibold text-on-surface">{{ __('إعدادات هذه الشركة') }}</h2>
        <p class="mt-1 text-caption text-on-surface-muted">
            {{ __('حدّ الائتمان والإيقاف الائتماني يُحسبان لكل شركة على حدة، وما يظهر هنا هو إعداد الشركة التي تعمل فيها الآن.') }}
        </p>

        @if ($setting === null)
            {{-- No row is a meaningful answer rather than a gap: this company
                 has set no limit for this party, which is not the same as a
                 limit of zero. --}}
            <p class="mt-4 text-label text-on-surface-muted">{{ __('لا إعداد لهذا الطرف في هذه الشركة، فلا حدّ ائتماني ولا إيقاف.') }}</p>
        @else
            <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-caption text-on-surface-muted">{{ __('حدّ الائتمان') }}</dt>
                    <dd class="mt-0.5">
                        @if ($creditLimit === null)
                            <span class="text-label text-on-surface-muted">{{ __('بلا حدّ') }}</span>
                        @else
                            {{-- In the ENTITY's functional currency, which is
                                 what the limit is held in: labelling it with
                                 the party's own default currency would be the
                                 right number under the wrong unit. --}}
                            <span dir="ltr" class="figure text-title font-semibold text-figure">{{ $creditLimit->format($locale, false) }}</span>
                            <span class="figure-unit">{{ $creditCurrency }}</span>
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-caption text-on-surface-muted">{{ __('الإيقاف الائتماني') }}</dt>
                    <dd class="mt-0.5">
                        @if ($setting->credit_hold)
                            {{-- Its own wording, not the workspace's
                                 "suspended": a credit hold stops new business
                                 with one customer and nothing else, and one
                                 word for both states would have somebody
                                 reading this as an account closure. --}}
                            <span class="inline-flex items-center gap-1 rounded-full bg-danger-subtle px-2.5 py-0.5 text-caption font-medium text-on-danger-subtle">
                                <span aria-hidden="true">!</span>{{ __('موقوف ائتمانيًا') }}
                            </span>

                            @if ($setting->credit_hold_reason)
                                {{-- The reason is shown because a hold nobody
                                     can explain is a hold somebody lifts. --}}
                                <p class="mt-2 text-label text-on-surface">{{ $setting->credit_hold_reason }}</p>
                            @endif

                            @if ($setting->credit_hold_at)
                                <p class="mt-1 text-caption text-on-surface-muted">
                                    <time datetime="{{ $setting->credit_hold_at->toIso8601String() }}" dir="ltr" class="figure">{{ $setting->credit_hold_at->toDateString() }}</time>
                                </p>
                            @endif
                        @else
                            <span class="text-label text-on-surface">{{ __('غير موقوف ائتمانيًا') }}</span>
                        @endif
                    </dd>
                </div>
            </dl>
        @endif
    </section>

    <section class="mt-6 rounded-card border border-outline bg-surface p-6 shadow-e1">
        <h2 class="text-title font-semibold text-on-surface">{{ __('الحسابات البنكية') }}</h2>

        {{--
            The masked form, and a sentence saying where the full number
            lives. Without it somebody concludes the number is missing and
            goes looking for it in an email, which is the leak this whole
            arrangement exists to prevent.
        --}}
        <p class="mt-1 max-w-3xl text-caption text-on-surface-muted">
            {{ __('تظهر آخر أربعة أرقام فقط. الرقم الكامل يُقرأ عبر مسار يسجّل من قرأه ومتى ولماذا، ويشترط صلاحية إدارة الحسابات البنكية: من يرى حساب مورد يستطيع استخدامه في أي مكان، ولا يظهر في سجل التغييرات شيء لأن شيئًا لم يتغير.') }}
        </p>

        @if ($bankAccounts->isEmpty())
            <p class="mt-4 text-label text-on-surface-muted">{{ __('لا حسابات بنكية مسجّلة لهذا الطرف.') }}</p>
        @else
            <div class="mt-4 overflow-x-auto rounded-card border border-outline">
                <table class="w-full text-label">
                    <caption class="sr-only">{{ __('الحسابات البنكية') }}</caption>
                    <thead class="bg-surface-sunken text-on-surface-muted">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الوسم') }}</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('البنك') }}</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الدولة') }}</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('العملة') }}</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الحساب') }}</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الحالة') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-subtle bg-surface">
                        @foreach ($bankAccounts as $account)
                            @php
                                $masked = $account->masked();
                                $needsVerification = (bool) $account->requires_reverification;
                            @endphp

                            <tr @class(['bg-surface-sunken' => ! $account->active])>
                                <td class="px-4 py-3 text-on-surface">{{ $account->label ?? '—' }}</td>
                                <td class="px-4 py-3 text-on-surface-muted">{{ $account->bank_name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <span dir="ltr" class="figure text-on-surface">{{ $account->country }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span dir="ltr" class="figure text-on-surface">{{ $account->currency ?? '—' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    {{-- `masked()` is the only reader this page
                                         knows: four digits in clear, which is
                                         what lets a person confirm "the account
                                         ending 4471" on a phone call - the
                                         control that actually catches a
                                         substituted beneficiary. --}}
                                    <span dir="ltr" class="figure text-on-surface">{{ $masked }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="flex flex-wrap gap-1.5">
                                        @if ($needsVerification)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-warning-subtle px-2 py-0.5 text-caption font-medium text-on-warning-subtle">
                                                <span aria-hidden="true">!</span>{{ __('بانتظار التحقق') }}
                                            </span>
                                        @elseif ($account->verified_at)
                                            <span class="inline-flex items-center rounded-full bg-success-subtle px-2 py-0.5 text-caption font-medium text-on-success-subtle">
                                                {{ __('مُتحقَّق منه') }}
                                            </span>
                                        @endif

                                        @unless ($account->active)
                                            <span class="inline-flex items-center rounded-full border border-outline px-2 py-0.5 text-caption text-on-surface-muted">
                                                {{ __('غير نشط') }}
                                            </span>
                                        @endunless
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="mt-6 rounded-card border border-outline bg-surface p-6 shadow-e1">
        <h2 class="text-title font-semibold text-on-surface">{{ __('المستندات المفتوحة') }}</h2>
        <p class="mt-1 max-w-3xl text-caption text-on-surface-muted">
            {{ __('فواتير هذه الشركة وفواتير مورديها التي لم تُسدَّد بعد، والرصيد المفتوح هو الإجمالي بعد الإشعارات الدائنة والمخصومات والفروق والإعدامات.') }}
        </p>

        @if ($documents === [])
            <p class="mt-4 text-label text-on-surface-muted">{{ __('لا مستندات مفتوحة لهذا الطرف في هذه الشركة.') }}</p>
        @else
            <div class="mt-4 overflow-x-auto rounded-card border border-outline">
                <table class="w-full text-label">
                    <caption class="sr-only">{{ __('المستندات المفتوحة') }}</caption>
                    <thead class="bg-surface-sunken text-on-surface-muted">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('المستند') }}</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('المرجع') }}</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('التاريخ') }}</th>
                            <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('العملة') }}</th>
                            <th scope="col" data-figure class="px-4 py-3 font-medium">{{ __('الإجمالي') }}</th>
                            <th scope="col" data-figure class="px-4 py-3 font-medium">{{ __('الرصيد المفتوح') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-subtle bg-surface">
                        @foreach ($documents as $document)
                            @php
                                $currency = (string) $document->currency;
                                $reference = (string) $document->reference;
                            @endphp

                            <tr>
                                <td class="px-4 py-3 text-on-surface">{{ $documentLabels[$document->kind] ?? $document->kind }}</td>
                                <td class="px-4 py-3">
                                    <span dir="ltr" class="figure text-on-surface">{{ $reference === '' ? '—' : $reference }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <time datetime="{{ $document->document_date }}" dir="ltr" class="figure text-on-surface-muted">{{ $document->document_date }}</time>
                                </td>
                                <td class="px-4 py-3">
                                    <span dir="ltr" class="figure text-on-surface">{{ $currency }}</span>
                                </td>
                                <td data-figure class="px-4 py-3 text-on-surface-muted">{{ $figure($document->gross_amount, $currency) }}</td>
                                <td data-figure class="px-4 py-3 font-medium text-on-surface">{{ $figure($document->open_balance, $currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{--
                No total row, deliberately.

                The rows can be in several currencies, and a column summed
                across them is a figure that is not true in any of them. The
                statement is where a party's balance is stated, in one currency
                at a time, against the control account that agrees with the
                trial balance.
            --}}
            <p class="mt-3 text-caption text-on-surface-muted">
                {{ __('لا مجموع لهذا العمود: قد تكون المستندات بعملات مختلفة، وجمعها يعطي رقمًا غير صحيح بأي منها.') }}
            </p>
        @endif
    </section>
@endsection
