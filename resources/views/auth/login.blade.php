@extends('layouts.plain', ['title' => __('تسجيل الدخول'), 'aurora' => true])

{{--
    Sign in - UX-031: Dark Gradient + Aurora + Glass + Organic.

    The aurora is the page (layouts.plain, `aurora`), static, and solid in the
    high-contrast themes. The glass is the FRAME only: every field inside it
    is a solid surface, because text typed into a translucent box is text on
    whatever is behind it. The organic shapes are decoration, aria-hidden,
    and carry no meaning.

    What the form does is unchanged: the same fields, names, autocomplete
    hints and the same two announcements (the session ended; the sign-in was
    refused).
--}}
@section('content')
    <div class="relative mx-auto w-full max-w-md">
        <svg aria-hidden="true" focusable="false" viewBox="0 0 200 200"
             class="pointer-events-none absolute -top-20 -end-24 size-64 text-tertiary opacity-20">
            <path fill="currentColor" transform="translate(100 100)"
                  d="M43.6-58.4C56.3-50.8 66.1-37.4 70.9-22.4 75.7-7.4 75.4 9.2 69.3 23 63.3 36.8 51.4 47.8 37.9 56.2 24.4 64.6 9.2 70.4-6.6 71.9-22.4 73.3-38.8 70.4-51.3 61.4-63.8 52.4-72.4 37.3-75.4 21.2-78.4 5.1-75.8-12-68.3-26.2-60.8-40.4-48.3-51.6-34.6-58.7-20.9-65.9-6-69 8.6-70.6 23.2-72.2 30.9-66 43.6-58.4Z"/>
        </svg>
        <svg aria-hidden="true" focusable="false" viewBox="0 0 200 200"
             class="pointer-events-none absolute -bottom-16 -start-20 size-56 text-primary opacity-15">
            <path fill="currentColor" transform="translate(100 100) rotate(140) scale(0.9)"
                  d="M43.6-58.4C56.3-50.8 66.1-37.4 70.9-22.4 75.7-7.4 75.4 9.2 69.3 23 63.3 36.8 51.4 47.8 37.9 56.2 24.4 64.6 9.2 70.4-6.6 71.9-22.4 73.3-38.8 70.4-51.3 61.4-63.8 52.4-72.4 37.3-75.4 21.2-78.4 5.1-75.8-12-68.3-26.2-60.8-40.4-48.3-51.6-34.6-58.7-20.9-65.9-6-69 8.6-70.6 23.2-72.2 30.9-66 43.6-58.4Z"/>
        </svg>

        <div class="glass relative rounded-sheet p-8 sm:p-10">
            <div class="flex items-center gap-3">
                <svg aria-hidden="true" focusable="false" viewBox="0 0 32 32" class="size-10 shrink-0">
                    <rect width="32" height="32" rx="9" class="fill-primary"/>
                    <path d="M6.5 21.5 13 11.5l4 6 3-4 5.5 8" fill="none" stroke-width="2.4"
                          stroke-linecap="round" stroke-linejoin="round" class="stroke-on-primary"/>
                    <path d="M6.5 25.5c3.2-1.5 6.3-1.5 9.5 0s6.3 1.5 9.5 0" fill="none" stroke-width="1.8"
                          stroke-linecap="round" class="stroke-on-primary opacity-60"/>
                </svg>
                <span class="text-title font-semibold text-on-surface">{{ config('app.name') }}</span>
            </div>

            <h1 class="mt-8 text-headline font-semibold text-on-surface">{{ __('تسجيل الدخول') }}</h1>
            <p class="mt-1 text-label text-on-surface-muted">{{ __('ادخل إلى مساحة عمل شركتك.') }}</p>

            @if (session('session_ended'))
                {{-- Why they are back here. Without it, an absolute timeout is
                     indistinguishable from the application losing their session. --}}
                <div role="status"
                     class="mt-6 rounded-field border border-outline-subtle bg-insight-subtle px-4 py-3 text-label text-on-insight-subtle">
                    {{ __('انتهت جلستك وتم تسجيل خروجك.') }}
                </div>
            @endif

            @error('email')
                {{-- aria-live, because a validation message that appears without
                     focus moving is invisible to a screen reader otherwise. --}}
                <div role="alert" aria-live="assertive"
                     class="mt-6 rounded-field border border-danger bg-danger-subtle px-4 py-3 text-label text-on-danger-subtle">
                    {{ $message }}
                </div>
            @enderror

            <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-5" novalidate>
                @csrf

                @if ($needsWorkspace ?? true)
                    <div>
                        <label for="workspace" class="block text-label font-medium text-on-surface">
                            {{ __('المساحة') }}
                        </label>
                        <input id="workspace" name="workspace" type="text" required
                               autocomplete="organization" dir="ltr" value="{{ old('workspace') }}"
                               class="mt-1.5 block min-h-11 w-full rounded-field border border-outline-control bg-surface px-3.5 py-2 text-on-surface
                                      transition-colors duration-(--duration-fast) ease-(--ease-standard)
                                      placeholder:text-on-surface-subtle
                                      focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                        <p class="mt-1.5 text-caption text-on-surface-muted">{{ __('اسم مساحة العمل الخاصة بشركتك.') }}</p>
                    </div>
                @endif

                <div>
                    <label for="email" class="block text-label font-medium text-on-surface">
                        {{ __('البريد الإلكتروني') }}
                    </label>
                    <input id="email" name="email" type="email" required autofocus
                           autocomplete="username" dir="ltr" value="{{ old('email') }}"
                           @error('email') aria-invalid="true" @enderror
                           class="mt-1.5 block min-h-11 w-full rounded-field border border-outline-control bg-surface px-3.5 py-2 text-on-surface
                                  transition-colors duration-(--duration-fast) ease-(--ease-standard)
                                  placeholder:text-on-surface-subtle
                                  focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                </div>

                <div>
                    <label for="password" class="block text-label font-medium text-on-surface">
                        {{ __('كلمة المرور') }}
                    </label>
                    <input id="password" name="password" type="password" required
                           autocomplete="current-password" dir="ltr"
                           @error('email') aria-invalid="true" @enderror
                           class="mt-1.5 block min-h-11 w-full rounded-field border border-outline-control bg-surface px-3.5 py-2 text-on-surface
                                  transition-colors duration-(--duration-fast) ease-(--ease-standard)
                                  focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                </div>

                <x-button type="submit" variant="cta" size="lg" class="mt-2 w-full">
                    {{ __('تسجيل الدخول') }}
                </x-button>
            </form>
        </div>
    </div>
@endsection
