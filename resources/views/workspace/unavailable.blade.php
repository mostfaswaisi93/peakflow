@extends('layouts.plain', ['title' => __('هذه المساحة غير متاحة حالياً')])

@section('content')
    {{--
        The page a user lands on when their workspace stopped being usable
        while they had the application open - PF-021.

        ## What it deliberately does not do

        It does not say "forbidden", and it does not look like a login failure.
        A user whose subscription lapsed between page loads has done nothing
        wrong, and sending them back to a login form teaches them that their
        password is broken - so they try it again, then reset it, then call
        support about the wrong problem entirely.

        It also does not offer a retry button. The condition is not transient:
        nothing the user can do from this page changes it, and a button that
        reloads the same refusal is worse than no button.
    --}}
    <div class="rounded-xl border border-outline bg-surface p-8 shadow-sm">
        <div class="flex items-start gap-4">
            <span aria-hidden="true"
                  class="mt-0.5 inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-warning-subtle text-on-warning-subtle">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
                     stroke-linecap="round" stroke-linejoin="round" class="size-5">
                    <path d="M12 9v4"/><path d="M12 17h.01"/>
                    <path d="M10.3 3.9 2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
                </svg>
            </span>

            <div class="min-w-0">
                <h1 class="text-xl font-semibold text-on-surface">
                    {{ __('هذه المساحة غير متاحة حالياً') }}
                </h1>

                <p class="mt-2 text-on-surface-muted">
                    {{ $reason ?? __('تم تعليق هذه المساحة أو انتهى اشتراكها.') }}
                </p>

                <p class="mt-4 text-sm text-on-surface-subtle">
                    {{ __('تم تسجيل خروجك للحفاظ على أمان بياناتك. لم يُفقد شيء، وتعود البيانات كما هي عند إعادة التفعيل.') }}
                </p>
            </div>
        </div>

        @if (($contact = config('app.support_email')) !== null)
            <div class="mt-6 border-t border-outline-subtle pt-6">
                <p class="text-sm text-on-surface-muted">
                    {{ __('للاستفسار عن حالة الاشتراك، راسل') }}
                    <a href="mailto:{{ $contact }}"
                       class="font-medium text-primary underline decoration-outline-strong underline-offset-4 hover:text-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">{{ $contact }}</a>
                </p>
            </div>
        @endif
    </div>

    <p class="mt-6 text-center text-sm text-on-surface-subtle">
        {{ config('app.name') }}
    </p>
@endsection
