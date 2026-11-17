@extends('layouts.ops', ['title' => __('الخطط والحدود')])

@section('content')
    <h1 class="text-2xl font-semibold text-on-surface">{{ __('الخطط والحدود') }}</h1>

    <section class="mt-8">
        <h2 class="text-lg font-medium text-on-surface">{{ __('الخطط') }}</h2>
        <div class="mt-4 overflow-x-auto rounded-xl border border-outline">
            <table class="w-full text-sm">
                <thead class="bg-surface-sunken text-on-surface-muted">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الخطة') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الشركات') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('المستخدمون') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('المستندات شهرياً') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الميزات') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-subtle bg-surface">
                    @foreach ($plans as $plan)
                        <tr>
                            <td class="px-4 py-3 font-medium text-on-surface">{{ $plan->name() }}</td>
                            {{-- .figure: limits are numbers and must not flip in RTL. --}}
                            <td class="figure px-4 py-3 text-on-surface-muted">{{ $plan->max_entities ?? '∞' }}</td>
                            <td class="figure px-4 py-3 text-on-surface-muted">{{ $plan->max_users ?? '∞' }}</td>
                            <td class="figure px-4 py-3 text-on-surface-muted">{{ $plan->max_documents_per_month ?? '∞' }}</td>
                            <td class="px-4 py-3 text-xs text-on-surface-subtle">{{ count((array) $plan->features) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-10">
        <h2 class="text-lg font-medium text-on-surface">{{ __('حدود المخاطر') }}</h2>
        <p class="mt-1 max-w-2xl text-sm text-on-surface-muted">
            {{ __('هذه الحدود هي أقصى ما يمكن لأي مساحة الوصول إليه، ولا يمكن تجاوزها من داخل المساحة. تغييرها يؤثر على جميع العملاء.') }}
        </p>

        <div class="mt-4 space-y-3">
            @foreach ($ceilings as $setting)
                <form method="POST" action="{{ route('ops.settings.update', $setting) }}"
                      class="rounded-xl border border-outline bg-surface p-4"
                      onsubmit="return confirm(@js(__('سيؤثر هذا على جميع المساحات فوراً. هل تريد المتابعة؟')))">
                    @csrf
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="min-w-0 flex-1">
                            <label for="v-{{ $setting->id }}" class="figure block text-sm font-medium text-on-surface">
                                {{ $setting->key }}
                            </label>
                            <p class="mt-1 text-xs text-on-surface-subtle">
                                {{ app()->getLocale() === 'ar' ? $setting->description_ar : $setting->description_en }}
                            </p>
                        </div>
                        <input id="v-{{ $setting->id }}" name="value" value="{{ $setting->value }}" required
                               class="figure w-24 rounded-lg border border-outline-control bg-surface-sunken px-2 py-1 text-on-surface
                                      focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                        <label for="r-{{ $setting->id }}" class="sr-only">{{ __('السبب') }}</label>
                        <input id="r-{{ $setting->id }}" name="reason" required minlength="8" placeholder="{{ __('السبب') }}"
                               class="w-48 rounded-lg border border-outline-control bg-surface-sunken px-2 py-1 text-xs text-on-surface
                                      focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                        <button type="submit"
                                class="rounded-lg border border-outline px-3 py-1.5 text-xs font-medium text-on-surface
                                       transition-colors duration-(--duration-fast) hover:bg-surface-hover
                                       focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                            {{ __('حفظ') }}
                        </button>
                    </div>
                </form>
            @endforeach
        </div>
    </section>
@endsection
