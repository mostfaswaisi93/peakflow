@extends('layouts.app', ['title' => __('الأمان')])

{{--
    A user's own second factor - PF-246.

    ## Why this page exists at all

    `RoleGrantService::grant()` refuses any role carrying a permission marked
    `requires_mfa` unless the user already has a confirmed factor. The
    enrolment half of `MfaService` - beginTotpEnrolment, confirmTotpEnrolment,
    regenerateRecoveryCodes, removeAuthenticator - had no caller anywhere in
    the application. So no user could obtain a factor, and therefore no user
    could ever be granted `payment.release`.

    ## No QR code, and that is a decision rather than an omission

    There is no QR library in this project and adding a dependency to render
    one is not a decision a page makes. What is here instead is better on the
    device that matters: the `otpauth://` link opens the authenticator app
    directly on a phone, which is fewer steps than pointing a camera at a
    screen. The secret is also shown in groups for manual entry, for the
    desktop case.

    ## The secret is shown once

    It arrives in a one-request flash and the model will not give it back. A
    page that could redisplay it would turn a stolen session into a stolen
    factor - the attacker enrols their own app and the real user notices
    nothing, because theirs keeps working. Losing it costs one click.
--}}

@section('content')
    @php
        /*
         | The secret in groups of four.
         |
         | Somebody is typing this into a phone off a screen. Base32 in one
         | 32-character run is where a transcription error happens, and the
         | error surfaces as "the code is wrong" with nothing pointing at the
         | character that was missed.
         */
        $grouped = static fn (string $secret): string => trim(chunk_split($secret, 4, ' '));
    @endphp

    <x-page-header>
        <x-slot:title>{{ __('الأمان') }}</x-slot:title>
        <x-slot:description>
            {{ __('العامل الثاني الذي يحمي حسابك، ورموز الاسترداد التي تُستخدم حين لا يكون هاتفك بين يديك.') }}
        </x-slot:description>
    </x-page-header>

    {{-- Both read out of the session by the controller, not here. A template
         that calls session() or @error cannot render without a session, and
         this page needs none: with nothing flashed there is simply nothing in
         progress. --}}
    @if ($status)
        <p role="status"
           class="mt-6 rounded-card border border-success bg-success-subtle px-4 py-3 text-on-success-subtle">
            {{ $status }}
        </p>
    @endif

    @if ($factorError)
        <p role="alert"
           class="mt-6 rounded-card border border-danger bg-danger-subtle px-4 py-3 text-on-danger-subtle">
            {{ $factorError }}
        </p>
    @endif

    {{-- Why any of this matters to THIS user, named rather than described.
         "Two-factor authentication is recommended" is a sentence people
         close. "You hold payment.release, and it needs a factor" is one
         that gets acted on. --}}
    <section class="mt-8 rounded-card border border-outline bg-surface p-5">
        <h2 class="text-title font-semibold text-on-surface">{{ __('لماذا يَلزمك عامل ثانٍ') }}</h2>

        @if ($moneyMoving->isNotEmpty())
            <p class="mt-2 text-body text-on-surface-muted">
                {{ __('تحمل صلاحيات تُحرّك المال، وكل واحدة منها تشترط عاملًا ثانيًا مؤكَّدًا:') }}
            </p>
            <ul class="mt-3 flex flex-wrap gap-2">
                @foreach ($moneyMoving as $permission)
                    <li class="rounded-full border border-outline-subtle bg-surface-sunken px-3 py-1 font-mono text-caption text-on-surface" dir="ltr">
                        {{ $permission }}
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-2 text-body text-on-surface-muted">
                {{ __('لا تحمل حاليًا صلاحية تشترط عاملًا ثانيًا. سجّل عاملًا الآن على أي حال: منح أي دور يُحرّك المال مرفوضٌ قبل أن يكون لديك واحد، فالتسجيل مسبقًا يوفّر دورة كاملة من الطلب والانتظار.') }}
            </p>
        @endif
    </section>

    {{-- An enrolment in progress. It is first on the page because it is the
         one thing on it that expires with the response. --}}
    @if ($started)
        <section class="mt-8 rounded-card border-2 border-primary bg-surface p-5">
            <h2 class="text-title font-semibold text-on-surface">{{ __('أكمل التسجيل الآن') }}</h2>
            <p class="mt-2 text-body text-on-surface-muted">
                {{ __('هذا السرّ يُعرض مرّة واحدة فقط ولا يمكن إظهاره ثانيةً. إن غادرت هذه الصفحة قبل التأكيد، ابدأ التسجيل من جديد.') }}
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <h3 class="text-label font-medium text-on-surface">{{ __('على الهاتف') }}</h3>
                    <p class="mt-1 text-label text-on-surface-muted">
                        {{ __('افتح هذا الرابط من الهاتف نفسه، فيفتح تطبيق المصادقة مباشرةً.') }}
                    </p>
                    <a href="{{ $started['uri'] }}"
                       class="mt-2 inline-block break-all rounded-field border border-outline px-3 py-2 font-mono text-caption text-link underline"
                       dir="ltr">{{ \Illuminate\Support\Str::limit($started['uri'], 72) }}</a>
                </div>

                <div>
                    <h3 class="text-label font-medium text-on-surface">{{ __('أو بالإدخال اليدوي') }}</h3>
                    <p class="mt-1 text-label text-on-surface-muted">
                        {{ __('أضف حسابًا جديدًا في التطبيق واكتب هذا السرّ.') }}
                    </p>
                    <p class="mt-2 select-all rounded-field border border-outline bg-surface-sunken px-3 py-2 font-mono text-label font-medium tracking-widest text-on-surface"
                       dir="ltr">{{ $grouped($started['secret']) }}</p>
                </div>
            </div>

            <form method="POST"
                  action="{{ route('security.factors.confirm', ['authenticator' => $started['id']]) }}"
                  class="mt-5 flex flex-wrap items-end gap-3">
                @csrf
                {{-- Carried back on a wrong code, so a mistyped digit does not
                     cost the secret and strand a half-enrolled factor. --}}
                <input type="hidden" name="secret" value="{{ $started['secret'] }}">
                <input type="hidden" name="uri" value="{{ $started['uri'] }}">

                <div>
                    <label for="code" class="block text-label font-medium text-on-surface">{{ __('الرمز المعروض في التطبيق') }}</label>
                    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
                           maxlength="9" required dir="ltr"
                           class="mt-1.5 block w-40 rounded-field border border-outline-control bg-surface-sunken px-3 py-2 font-mono text-body tracking-widest text-on-surface focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
                </div>

                <x-button type="submit">{{ __('تأكيد') }}</x-button>
            </form>

            @if ($codeError)
                <p role="alert" class="mt-3 text-label text-danger">{{ $codeError }}</p>
            @endif
        </section>
    @endif

    {{-- Recovery codes, shown once, right after they are issued. --}}
    @if ($recoveryCodes)
        <section class="mt-8 rounded-card border-2 border-warning bg-surface p-5">
            <h2 class="text-title font-semibold text-on-surface">{{ __('رموز الاسترداد') }}</h2>
            <p class="mt-2 text-body text-on-surface-muted">
                {{ __('تُعرض مرّة واحدة. احفظها في مكان آمن خارج هاتفك — فهي ما يُدخلك حين يُفقد الهاتف. كل رمز يُستخدم مرّة واحدة.') }}
            </p>
            <ul class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3" dir="ltr">
                @foreach ($recoveryCodes as $code)
                    <li class="select-all rounded-field border border-outline bg-surface-sunken px-3 py-2 text-center font-mono text-label font-medium text-on-surface">
                        {{ $code }}
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="mt-8">
        <h2 class="text-title font-semibold text-on-surface">{{ __('عواملك') }}</h2>

        @if ($factors->isEmpty())
            <p class="mt-2 text-body text-on-surface-muted">
                {{ __('لا يوجد عامل مسجَّل بعد.') }}
            </p>
        @else
            <ul class="mt-4 space-y-3">
                @foreach ($factors as $factor)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-card border border-outline bg-surface px-4 py-3">
                        <div>
                            <p class="text-body font-medium text-on-surface">{{ $factor->name }}</p>
                            <p class="text-label text-on-surface-muted">
                                @if ($factor->isConfirmed())
                                    {{ __('مؤكَّد') }}
                                    @if ($factor->last_used_at)
                                        · {{ __('آخر استخدام') }} {{ $factor->last_used_at->diffForHumans() }}
                                    @endif
                                @else
                                    {{-- Named as unusable rather than "pending": a row that
                                         cannot authenticate anybody is not a factor, and a
                                         user who reads "pending" believes they are covered. --}}
                                    {{ __('غير مكتمل — لم يُقبل منه رمز بعد، فهو لا يحمي شيئًا') }}
                                @endif
                            </p>
                        </div>

                        <form method="POST"
                              action="{{ route('security.factors.destroy', ['authenticator' => $factor->getKey()]) }}">
                            @csrf
                            @method('DELETE')
                            <x-button type="submit" variant="ghost">{{ __('حذف') }}</x-button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('security.factors.begin') }}" class="mt-5 flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label for="name" class="block text-label font-medium text-on-surface">{{ __('اسم الجهاز') }}</label>
                <input id="name" name="name" maxlength="60" placeholder="{{ __('هاتف العمل') }}"
                       class="mt-1.5 block w-56 max-w-full rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-body text-on-surface focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
            </div>
            <x-button type="submit" variant="tonal">{{ __('تسجيل تطبيق مصادقة') }}</x-button>
        </form>
    </section>

    <section class="mt-8 rounded-card border border-outline bg-surface p-5">
        <h2 class="text-title font-semibold text-on-surface">{{ __('رموز الاسترداد') }}</h2>

        <p class="mt-2 text-body text-on-surface-muted">
            @if ($recoveryCodesLeft > 0)
                {{ __('لديك :count رمزًا لم يُستخدم.', ['count' => $recoveryCodesLeft]) }}
            @else
                {{ __('لا توجد رموز استرداد. بدونها، فقدان الهاتف يعني فقدان الوصول إلى الحساب.') }}
            @endif
        </p>

        <p class="mt-2 text-label text-on-surface-muted">
            {{ __('الرموز مُخزَّنة مُجزّأة، فلا يمكن إظهارها مرّة أخرى — وإصدار مجموعة جديدة يُبطل أي مجموعة سابقة فورًا، بما فيها المطبوعة.') }}
        </p>

        <form method="POST" action="{{ route('security.recovery-codes') }}" class="mt-4">
            @csrf
            <x-button type="submit" variant="outline">{{ __('إصدار مجموعة جديدة') }}</x-button>
        </form>
    </section>
@endsection
