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

    <form method="GET" class="mt-6">
        <label for="q" class="sr-only">{{ __('بحث') }}</label>
        <input id="q" name="q" type="search" value="{{ request('q') }}" placeholder="{{ __('بحث') }}"
               class="w-full max-w-sm rounded-lg border border-outline bg-surface-sunken px-3 py-2 text-on-surface
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
                                       class="w-44 rounded-lg border border-outline bg-surface-sunken px-2 py-1 text-xs text-on-surface
                                              focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                                <button type="submit"
                                        class="rounded-lg border border-outline px-3 py-1 text-xs font-medium text-on-surface
                                               transition-colors duration-(--duration-fast) hover:bg-surface-hover
                                               focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                                    {{ $suspending ? __('تعليق') : __('استئناف') }}
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
