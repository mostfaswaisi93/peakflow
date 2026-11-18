@extends('layouts.app', ['title' => __('دليل الحسابات'), 'wide' => true])

{{--
    The chart of accounts - PF-037, PF-038.

    ## A real tree, nested by `parent`

    Nested lists, not an indented flat list. The meaning of the code `1.1.4`
    IS its position, so a reader has to be able to see which account it hangs
    under; indentation alone is a drawing of a hierarchy that the markup does
    not contain, and it disappears the moment the stylesheet does.

    The tree arrives from the controller already nested and is flattened here
    into a stream of open/close tokens, because a Blade template cannot call
    itself. One row of markup, rendered once, whatever the depth - the
    alternative is a copy of the row per level, and the copy somebody forgets
    to update is the one four levels down that nobody scrolls to.

    `role="list"` and nothing more. An ARIA `tree` promises arrow-key
    navigation between nodes, and this page implements none - a widget role
    whose keyboard contract is not kept is worse than no role, because a
    screen-reader user is told to expect it.

    ## The control role is shown, with its key

    An account wired to the wrong control role posts without error, balances,
    and puts the money where nobody looks. The role is therefore a chip on
    the row rather than a detail on another page, and it carries the machine
    key beside the words: the key is what a posting rule resolves, so it is
    what somebody checking the wiring has to read.

    ## `monetary` has three states and all three are shown

    IAS 21 restates monetary items at the closing rate and leaves non-monetary
    ones alone, and the classification cannot be derived from the type -
    property and receivables are both assets. `null` is "nobody has
    classified this", which `fx:revalue` reports; rendering it as
    non-monetary would be this page inventing the answer.
--}}

@section('content')
    @php
        $locale = app()->getLocale();

        /*
         | The tree, flattened into tokens.
         |
         | `node` opens a list item and renders the row, `open` and `close`
         | wrap a child list inside it, and `endnode` closes the item. The
         | pairs are emitted together so the nesting cannot come apart - a
         | template that closed its lists by counting depth differences gets
         | the arithmetic wrong once and produces markup a browser repairs
         | into a different tree.
         */
        $flatten = static function (array $nodes) use (&$flatten): array {
            $tokens = [];

            foreach ($nodes as $node) {
                $tokens[] = ['t' => 'node', 'account' => $node['account']];

                if ($node['children'] !== []) {
                    $tokens[] = ['t' => 'open'];
                    $tokens = array_merge($tokens, $flatten($node['children']));
                    $tokens[] = ['t' => 'close'];
                }

                $tokens[] = ['t' => 'endnode'];
            }

            return $tokens;
        };

        $tokens = $flatten($tree);

        $chip = 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-caption font-medium';

        /*
         | Every role in the enum, named in words.
         |
         | The list is exhaustive on purpose: a role added to the enum and
         | missing from here renders as its raw key, which is legible and is
         | not Arabic - and the reader who would report it is the one not
         | reading this page in English.
         */
        $roleLabels = [
            'accounts_receivable' => __('الذمم المدينة'),
            'accounts_payable' => __('الذمم الدائنة'),
            'vat_input' => __('ضريبة القيمة المضافة على المشتريات'),
            'vat_output' => __('ضريبة القيمة المضافة على المبيعات'),
            'bank' => __('البنك'),
            'payments_in_transit' => __('مدفوعات في الطريق'),
            'inventory' => __('المخزون'),
            'cost_of_sales' => __('تكلفة المبيعات'),
            'fx_gain_loss' => __('أرباح وخسائر فروق العملة'),
            'fx_revaluation' => __('إعادة تقييم العملات'),
            'rounding_difference' => __('فروق التقريب'),
            'suspense' => __('حساب معلّق'),
            'deferred_revenue' => __('إيرادات مؤجلة'),
            'retained_earnings' => __('الأرباح المبقاة'),
            'inter_entity' => __('حساب بين الشركات'),
            'withholding_receivable' => __('ضريبة استقطاع مستحقة لنا'),
            'withholding_payable' => __('ضريبة استقطاع مستحقة علينا'),
            'goods_received_not_invoiced' => __('بضاعة مستلمة غير مفوترة'),
            'employee_payable' => __('مستحقات الموظفين'),
            'salary_expense' => __('مصروف الرواتب'),
            'employer_contribution_expense' => __('مساهمات صاحب العمل'),
            'payroll_tax_payable' => __('ضريبة الرواتب المستحقة'),
            'social_insurance_payable' => __('التأمينات الاجتماعية المستحقة'),
            'bad_debt' => __('ديون معدومة'),
            'bad_debt_recovery' => __('متحصلات ديون معدومة'),
            'unapplied_cash' => __('مقبوضات غير مخصصة'),
            'bank_charges' => __('مصاريف بنكية'),
            'ecl_provision' => __('مخصص الخسائر الائتمانية المتوقعة'),
            'ecl_charge' => __('مصروف الخسائر الائتمانية المتوقعة'),
        ];
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-headline font-bold text-on-surface">{{ __('دليل الحسابات') }}</h1>
            <p class="mt-1 max-w-2xl text-label text-on-surface-muted">
                {{ __('شجرة حسابات هذه الشركة. لكل شركة دليلها الخاص، والحساب التجميعي لا يُرحَّل عليه - يُرحَّل على الحسابات التي تحته.') }}
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

    {{--
        A GET search, so the result is a URL.

        A filtered chart somebody can send to a colleague is worth more than
        a tidier POST - the account being argued about has to be reachable by
        the person being asked about it.
    --}}
    <form method="GET" class="mt-6 flex flex-wrap items-end gap-3">
        <div class="w-full max-w-sm">
            <label for="q" class="block text-label font-medium text-on-surface">{{ __('بحث بالرمز أو الاسم') }}</label>
            {{-- `dir="auto"`: the term may be an Arabic name or a Latin code,
                 and a field that imposes one direction puts the cursor at the
                 wrong end for half of what is typed into it. --}}
            <input id="q" name="q" type="search" dir="auto" value="{{ $term }}" maxlength="120"
                   aria-describedby="why-q"
                   class="mt-1.5 block w-full rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-body text-on-surface
                          focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
        </div>

        <x-button type="submit">{{ __('بحث') }}</x-button>

        @if ($term !== '')
            <a href="{{ url()->current() }}"
               class="rounded-button px-3 py-2 text-label text-on-surface-muted underline
                      hover:text-on-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                {{ __('إلغاء البحث') }}
            </a>
        @endif
    </form>

    <p id="why-q" class="mt-2 max-w-2xl text-caption text-on-surface-muted">
        {{ __('يبحث في الرمز والاسمين العربي والإنجليزي. تبقى الحسابات الأعلى في الشجرة ظاهرة حتى لو لم تطابق، لأن معنى الرمز هو موضعه.') }}
    </p>

    <div class="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-label text-on-surface-muted">
        <p>
            {{ __('الظاهر الآن') }}
            <span dir="ltr" class="figure font-semibold text-on-surface">{{ $shown }}</span>
        </p>
        <p>
            {{ __('كل حسابات الشركة') }}
            <span dir="ltr" class="figure font-semibold text-on-surface">{{ $total }}</span>
        </p>
    </div>

    <div class="mt-4 rounded-card border border-outline bg-surface p-4">
        @if ($tokens === [])
            <p class="px-2 py-8 text-center text-label text-on-surface-muted">
                @if ($term === '')
                    {{ __('لا توجد حسابات في هذه الشركة بعد.') }}
                @else
                    {{ __('لا يوجد حساب يطابق هذا البحث.') }}
                @endif
            </p>
        @else
            <ul role="list" class="space-y-0.5 text-label">
                @foreach ($tokens as $token)
                    @if ($token['t'] === 'open')
                        {{-- The child list, indented from the reading edge and
                             marked by a rule, so the branch is visible as a
                             branch rather than as a gap. --}}
                        <ul role="list" class="mt-0.5 ms-3 space-y-0.5 border-s border-outline-subtle ps-3">
                    @elseif ($token['t'] === 'close')
                        </ul>
                    @elseif ($token['t'] === 'endnode')
                        </li>
                    @else
                        @php
                            $account = $token['account'];
                            $name = $account->name($locale);
                            $other = $locale === 'ar' ? (string) $account->name_en : (string) $account->name_ar;
                            $monetary = $account->monetary;
                            $postable = (bool) $account->postable;
                        @endphp

                        <li>
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-1">
                                {{-- The code is an identifier: monospace so the
                                     segments line up down the branch, and
                                     `dir="ltr"` so `1.1.10` is not reordered by
                                     the Arabic beside it. --}}
                                <span dir="ltr" class="figure font-mono text-on-surface-muted">{{ $account->code }}</span>

                                <span @class(['text-on-surface', 'font-semibold' => ! $postable, 'font-medium' => $postable])>
                                    {{ $name !== '' ? $name : $account->code }}
                                </span>

                                @if ($other !== '' && $other !== $name)
                                    <span class="text-caption text-on-surface-subtle">{{ $other }}</span>
                                @endif

                                <span class="flex flex-wrap items-baseline gap-1.5">
                                    {{-- What the account IS, which decides its
                                         natural sign, whether it closes at year
                                         end, and which statement it belongs to.
                                         The words come from the enum that makes
                                         those three decisions, so there is one
                                         answer rather than a second copy here. --}}
                                    <span class="{{ $chip }} bg-surface-container text-on-surface-muted">
                                        {{ $account->type->label($locale) }}
                                    </span>

                                    @if ($postable)
                                        <span class="{{ $chip }} bg-primary-subtle text-on-primary-subtle">
                                            {{ __('حساب ترحيل') }}
                                        </span>
                                    @else
                                        {{-- A heading and a posting account look
                                             identical in a tree, and posting to a
                                             heading is refused by the ledger - so
                                             the difference is said in words rather
                                             than left to the reader to infer from
                                             having children. --}}
                                        <span class="{{ $chip }} bg-surface-container text-on-surface-muted">
                                            {{ __('حساب تجميعي') }}
                                        </span>
                                    @endif

                                    @if ($monetary === true)
                                        <span class="{{ $chip }} bg-insight-subtle text-on-insight-subtle">
                                            {{ __('بند نقدي') }}
                                        </span>
                                    @elseif ($monetary === false)
                                        <span class="{{ $chip }} bg-surface-container text-on-surface-muted">
                                            {{ __('بند غير نقدي') }}
                                        </span>
                                    @else
                                        <span class="{{ $chip }} bg-warning-subtle text-on-warning-subtle">
                                            <span aria-hidden="true">!</span>{{ __('غير مصنَّف نقديًا') }}
                                        </span>
                                    @endif

                                    @if ($account->currency)
                                        {{-- A currency restriction, not a
                                             currency: most accounts take any and
                                             are reported in the functional one. A
                                             bank account holds exactly one, and a
                                             posting in another is a mistake rather
                                             than a translation. --}}
                                        <span class="{{ $chip }} bg-tertiary-subtle text-on-tertiary-subtle">
                                            {{ __('بعملة واحدة') }}
                                            <span dir="ltr" class="figure">{{ $account->currency }}</span>
                                        </span>
                                    @endif

                                    @foreach ($account->roles as $assignment)
                                        <span class="{{ $chip }} bg-tertiary text-on-tertiary">
                                            {{ __('دور رقابي') }}
                                            <span>{{ $roleLabels[$assignment->role->value] ?? $assignment->role->value }}</span>
                                            <code dir="ltr" class="font-mono">{{ $assignment->role->value }}</code>
                                            @if ($assignment->jurisdiction)
                                                <span dir="ltr" class="figure">{{ $assignment->jurisdiction }}</span>
                                            @endif
                                        </span>
                                    @endforeach

                                    @if ($account->blocked)
                                        {{-- A blocked account that reads as a
                                             posting account is wrong information
                                             rather than missing information: the
                                             posting will be refused, and the
                                             person reading this page is the one
                                             who has to know why. --}}
                                        <span class="{{ $chip }} bg-danger-subtle text-on-danger-subtle">
                                            <span aria-hidden="true">!</span>{{ __('محجوب عن الترحيل') }}
                                        </span>
                                    @endif
                                </span>
                            </div>
                    @endif
                @endforeach
            </ul>
        @endif
    </div>
@endsection
