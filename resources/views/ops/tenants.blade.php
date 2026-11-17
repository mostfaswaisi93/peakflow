@extends('layouts.ops', ['title' => __('المساحات')])

@section('content')
    <h1 class="text-2xl font-semibold text-on-surface">{{ __('المساحات') }}</h1>

    {{--
        Metadata only. No document, no figure, no count that would reveal a
        customer's trading volume. Reading a tenant's data requires
        impersonation, which requires their prior consent and is recorded in
        both planes - that is what makes operator access attributable.
    --}}
    <p class="mt-2 max-w-2xl text-sm text-on-surface-muted">
        {{ __('تظهر هنا بيانات المساحة فقط. لقراءة بيانات العميل يلزم الدخول بالنيابة، وهو يتطلب موافقته المسبقة ويُسجَّل لديه ولدينا.') }}
    </p>

    {{--
        Provisioning on screen, not only `tenants:provision`.

        The command needs a shell on the production box, which makes
        onboarding a customer something one person can do from a machine
        nobody audits with no row anywhere saying it happened. The command is
        still right for a scripted migration of many tenants; a single
        customer belongs where the rest of the lifecycle is.
    --}}
    <details class="mt-6 rounded-xl border border-outline">
        <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-on-surface">
            {{ __('إنشاء مساحة جديدة') }}
        </summary>

        <form method="POST" action="{{ route('ops.tenants.store') }}" class="border-t border-outline px-4 py-4"
              {{-- The confirmation names what is created on the cluster and that billing starts. --}}
              onsubmit="return confirm(@js(__('سيُنشأ على العنقود دور وقاعدة بيانات جديدة، وتُرحَّل 131 جدولاً، ويبدأ اشتراك محسوب على الخطة المختارة. العملية تستغرق ثانية تقريباً ولا يمكن التراجع عنها بزر - الحذف مسار منفصل بموافقة شخصين. هل تريد المتابعة؟')))">
            @csrf

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="new-name" class="block text-sm font-medium text-on-surface">{{ __('اسم العميل') }}</label>
                    <input id="new-name" name="name" required minlength="2" maxlength="160" value="{{ old('name') }}"
                           @error('name') aria-invalid="true" @enderror
                           class="mt-1.5 block w-full rounded-lg border border-outline-control bg-surface-sunken px-3 py-2 text-on-surface
                                  focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                    @error('name')
                        <p role="alert" class="mt-1.5 text-xs text-on-danger-subtle">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="new-plan" class="block text-sm font-medium text-on-surface">{{ __('الخطة') }}</label>
                    <select id="new-plan" name="plan" required
                            class="mt-1.5 block w-full rounded-lg border border-outline-control bg-surface-sunken px-3 py-2 text-on-surface
                                   focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->key }}" @selected(old('plan') === $plan->key)>{{ $plan->name() }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="new-slug" class="block text-sm font-medium text-on-surface">{{ __('المعرّف') }}</label>
                    {{--
                        `dir="ltr"`, because it becomes a database name and a
                        role name on the cluster. An identifier rendered
                        right-to-left reorders around the hyphens, and what the
                        operator reads back is not what will be created.
                    --}}
                    <input id="new-slug" name="slug" dir="ltr" value="{{ old('slug') }}"
                           pattern="[a-z0-9][a-z0-9-]*[a-z0-9]" maxlength="40"
                           class="mt-1.5 block w-full rounded-lg border border-outline-control bg-surface-sunken px-3 py-2 text-on-surface
                                  focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                    <p class="mt-1.5 text-xs text-on-surface-subtle">{{ __('اختياري. يُشتق من الاسم إن تُرك فارغاً، ويصبح اسم قاعدة البيانات.') }}</p>
                </div>

                <div>
                    <label for="new-reason" class="block text-sm font-medium text-on-surface">{{ __('السبب') }}</label>
                    <input id="new-reason" name="reason" required minlength="8" maxlength="500" value="{{ old('reason') }}"
                           class="mt-1.5 block w-full rounded-lg border border-outline-control bg-surface-sunken px-3 py-2 text-on-surface
                                  focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                    <p class="mt-1.5 text-xs text-on-surface-subtle">{{ __('يُسجَّل في سجل النشاط. الشخص الذي يقرأه بعد ستة أشهر ليس من كتبه.') }}</p>
                </div>
            </div>

            <button type="submit"
                    class="mt-4 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-on-primary
                           transition-colors duration-(--duration-fast) hover:bg-primary-hover
                           focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                {{ __('إنشاء') }}
            </button>
        </form>
    </details>

    <form method="GET" class="mt-6">
        <label for="q" class="sr-only">{{ __('بحث') }}</label>
        <input id="q" name="q" type="search" value="{{ request('q') }}" placeholder="{{ __('بحث') }}"
               class="w-full max-w-sm rounded-lg border border-outline-control bg-surface-sunken px-3 py-2 text-on-surface
                      focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
    </form>

    <div class="mt-6 overflow-x-auto rounded-xl border border-outline">
        <table class="w-full text-sm">
            <caption class="sr-only">{{ __('المساحات') }}</caption>
            <thead class="bg-surface-sunken text-on-surface-muted">
                <tr>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('المساحة') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الخطة') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الحالة') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('إجراء') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-subtle bg-surface">
                @forelse ($tenants as $tenant)
                    @php $subscription = $tenant->currentSubscription(); @endphp
                    <tr>
                        <td class="px-4 py-3">
                            <span class="font-medium text-on-surface">{{ $tenant->name }}</span>
                            <span class="figure ms-2 text-xs text-on-surface-subtle">{{ $tenant->slug }}</span>
                        </td>
                        <td class="px-4 py-3 text-on-surface-muted">{{ $subscription?->plan?->key ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-success-subtle text-on-success-subtle' => $tenant->status->value === 'active',
                                'bg-warning-subtle text-on-warning-subtle' => $tenant->status->value !== 'active',
                            ])>{{ $tenant->status->value }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @php $suspending = $tenant->status->value === 'active'; @endphp
                            <form method="POST" action="{{ route('ops.tenants.status', $tenant) }}"
                                  class="flex flex-wrap items-center gap-2"
                                  {{-- The confirmation NAMES the consequence. "Are you sure?" is a
                                       dialog people click through without reading. --}}
                                  onsubmit="return confirm(@js($suspending
                                      ? __('سيتم تسجيل خروج جميع مستخدمي :name فوراً وستظهر لهم صفحة تفيد بتعليق المساحة. لن تُحذف أي بيانات. هل تريد المتابعة؟', ['name' => $tenant->name])
                                      : __('ستعود :name للعمل ويستطيع مستخدموها تسجيل الدخول مرة أخرى. هل تريد المتابعة؟', ['name' => $tenant->name])))">
                                @csrf
                                <input type="hidden" name="status" value="{{ $suspending ? 'suspended' : 'active' }}">
                                <label for="reason-{{ $tenant->id }}" class="sr-only">{{ __('السبب') }}</label>
                                <input id="reason-{{ $tenant->id }}" name="reason" required minlength="8"
                                       placeholder="{{ __('السبب') }}"
                                       class="w-44 rounded-lg border border-outline-control bg-surface-sunken px-2 py-1 text-xs text-on-surface
                                              focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                                <button type="submit"
                                        class="rounded-lg border border-outline px-3 py-1 text-xs font-medium text-on-surface
                                               transition-colors duration-(--duration-fast) hover:bg-surface-hover
                                               focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                                    {{ $suspending ? __('تعليق') : __('استئناف') }}
                                </button>
                            </form>

                            {{--
                                Expiry is COMMERCIAL and is an end state;
                                suspension is operational and reversible. Both
                                stop the tenant working, and conflating them is
                                how a payment dispute ends with a workspace
                                suspended for "non-payment" and nobody able to
                                say whether the contract actually ended.
                            --}}
                            <form method="POST" action="{{ route('ops.tenants.expire', $tenant) }}"
                                  class="mt-2 flex flex-wrap items-center gap-2"
                                  onsubmit="return confirm(@js(__('سينتهي اشتراك :name اليوم ويتوقف تسجيل الدخول إليها. لن تُحذف قاعدة البيانات ولا أي بيانات، ويعود العمل إذا جُدّد الاشتراك. هذا إنهاء تجاري وليس تعليقاً. هل تريد المتابعة؟', ['name' => $tenant->name])))">
                                @csrf
                                <label for="expire-reason-{{ $tenant->id }}" class="sr-only">{{ __('سبب الإنهاء') }}</label>
                                <input id="expire-reason-{{ $tenant->id }}" name="reason" required minlength="8"
                                       placeholder="{{ __('سبب الإنهاء') }}"
                                       class="w-44 rounded-lg border border-outline-control bg-surface-sunken px-2 py-1 text-xs text-on-surface
                                              focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                                <button type="submit"
                                        class="rounded-lg border border-outline px-3 py-1 text-xs font-medium text-on-surface
                                               transition-colors duration-(--duration-fast) hover:bg-surface-hover
                                               focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                                    {{ __('إنهاء الاشتراك') }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-on-surface-muted">{{ __('لا توجد مساحات.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $tenants->links() }}</div>
@endsection
