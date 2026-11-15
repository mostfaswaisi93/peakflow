@extends('layouts.app', ['title' => __('نظرة عامة')])

@section('content')
    <h1 class="text-2xl font-semibold text-on-surface">{{ __('نظرة عامة') }}</h1>

    <p class="mt-2 text-on-surface-muted">
        {{ __('أنت تعمل الآن في :entity.', ['entity' => app('currentEntity')?->legal_name]) }}
    </p>

    <div class="mt-8 grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-outline bg-surface p-5">
            <h2 class="text-sm font-medium text-on-surface-muted">{{ __('العملة الوظيفية') }}</h2>
            {{-- .figure: Latin numerals, and numbers do not flip in RTL. --}}
            <p class="figure mt-1 text-2xl font-semibold text-on-surface">
                {{ app('currentEntity')?->functional_currency }}
            </p>
        </div>

        <div class="rounded-xl border border-outline bg-surface p-5">
            <h2 class="text-sm font-medium text-on-surface-muted">{{ __('صلاحياتك هنا') }}</h2>
            <p class="figure mt-1 text-2xl font-semibold text-on-surface">
                {{ auth()->user()?->permissionsIn(app('currentEntity')?->id ?? '')->count() }}
            </p>
        </div>
    </div>
@endsection
