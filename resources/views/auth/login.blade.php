@extends('layouts.plain', ['title' => __('تسجيل الدخول')])

@section('content')
    <div class="rounded-xl border border-outline bg-surface p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-on-surface">{{ __('تسجيل الدخول') }}</h1>
        <p class="mt-1 text-sm text-on-surface-muted">{{ config('app.name') }}</p>

        @if (session('session_ended'))
            {{-- Why they are back here. Without it, an absolute timeout is
                 indistinguishable from the application losing their session. --}}
            <div role="status"
                 class="mt-6 rounded-lg border border-outline-subtle bg-info-subtle px-4 py-3 text-sm text-on-info-subtle">
                {{ __('انتهت جلستك وتم تسجيل خروجك.') }}
            </div>
        @endif

        @error('email')
            {{-- aria-live, because a validation message that appears without
                 focus moving is invisible to a screen reader otherwise. --}}
            <div role="alert" aria-live="assertive"
                 class="mt-6 rounded-lg border border-danger bg-danger-subtle px-4 py-3 text-sm text-on-danger-subtle">
                {{ $message }}
            </div>
        @enderror

        <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-5" novalidate>
            @csrf

            @if ($needsWorkspace ?? true)
                <div>
                    <label for="workspace" class="block text-sm font-medium text-on-surface">
                        {{ __('المساحة') }}
                    </label>
                    <input id="workspace" name="workspace" type="text" required
                           autocomplete="organization" dir="ltr" value="{{ old('workspace') }}"
                           class="mt-1.5 block w-full rounded-lg border border-outline bg-surface-sunken px-3 py-2 text-on-surface
                                  placeholder:text-on-surface-subtle
                                  focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                    <p class="mt-1.5 text-xs text-on-surface-subtle">{{ __('اسم مساحة العمل الخاصة بشركتك.') }}</p>
                </div>
            @endif

            <div>
                <label for="email" class="block text-sm font-medium text-on-surface">
                    {{ __('البريد الإلكتروني') }}
                </label>
                <input id="email" name="email" type="email" required autofocus
                       autocomplete="username" dir="ltr" value="{{ old('email') }}"
                       @error('email') aria-invalid="true" @enderror
                       class="mt-1.5 block w-full rounded-lg border border-outline bg-surface-sunken px-3 py-2 text-on-surface
                              placeholder:text-on-surface-subtle
                              focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-on-surface">
                    {{ __('كلمة المرور') }}
                </label>
                <input id="password" name="password" type="password" required
                       autocomplete="current-password" dir="ltr"
                       @error('email') aria-invalid="true" @enderror
                       class="mt-1.5 block w-full rounded-lg border border-outline bg-surface-sunken px-3 py-2 text-on-surface
                              focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-primary px-4 py-2.5 font-medium text-on-primary
                           transition-colors duration-(--duration-fast) ease-(--ease-standard)
                           hover:bg-primary-hover
                           focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                {{ __('تسجيل الدخول') }}
            </button>
        </form>
    </div>
@endsection
