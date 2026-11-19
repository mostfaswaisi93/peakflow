@extends('layouts.ops', ['title' => __('تسجيل الدخول')])

@section('content')
    <div class="mx-auto max-w-md rounded-xl border border-outline bg-surface p-8">
        <h1 class="text-xl font-semibold text-on-surface">{{ __('وحدة التشغيل') }}</h1>

        @error('email')
            <div role="alert" aria-live="assertive"
                 class="mt-6 rounded-lg border border-danger bg-danger-subtle px-4 py-3 text-sm text-on-danger-subtle">
                {{ $message }}
            </div>
        @enderror

        <form method="POST" action="{{ route('ops.login.store') }}" class="mt-6 space-y-5" novalidate>
            @csrf
            <div>
                <label for="email" class="block text-sm font-medium text-on-surface">{{ __('البريد الإلكتروني') }}</label>
                <input id="email" name="email" type="email" required autofocus autocomplete="username" dir="ltr"
                       value="{{ old('email') }}"
                       class="min-h-(--control-height) mt-1.5 block w-full rounded-lg border border-outline-control bg-surface-sunken px-3 py-2 text-on-surface
                              focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-on-surface">{{ __('كلمة المرور') }}</label>
                <input id="password" name="password" type="password" required autocomplete="current-password" dir="ltr"
                       class="min-h-(--control-height) mt-1.5 block w-full rounded-lg border border-outline-control bg-surface-sunken px-3 py-2 text-on-surface
                              focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
            </div>
            <button type="submit"
                    class="w-full rounded-lg bg-primary px-4 py-2.5 font-medium text-on-primary
                           transition-colors duration-(--duration-fast) hover:bg-primary-hover
                           focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                {{ __('تسجيل الدخول') }}
            </button>
        </form>
    </div>
@endsection
