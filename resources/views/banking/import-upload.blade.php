@extends('layouts.app', ['title' => __('استيراد كشف حساب')])

@section('content')
    <div class="mx-auto max-w-3xl">
        <h1 class="text-xl font-semibold text-on-surface">{{ __('استيراد كشف حساب') }}</h1>
        <p class="mt-2 text-sm text-on-surface-muted">
            {{ __('الحساب: :account', ['account' => $account->shortIdentifier()]) }}
        </p>

        @if (session('import_report'))
            @php($report = session('import_report'))
            <div role="status"
                 class="mt-6 rounded-lg border border-success bg-success-subtle px-4 py-3 text-sm text-on-success-subtle">
                <p class="font-medium">{{ $report['why'] }}</p>

                @if (! empty($report['skipped_detail']))
                    {{-- Every skipped line, named. "4 lines skipped" is a
                         figure nobody can check; the bank's own reference is
                         what somebody searches their statement for. --}}
                    <ul class="mt-3 space-y-1 ps-4">
                        @foreach ($report['skipped_detail'] as $skipped)
                            <li class="list-disc">
                                <span class="figure">{{ $skipped['booking_date'] }}</span>
                                <span class="figure">{{ $skipped['amount'] }}</span>
                                — {{ $skipped['why'] }}
                            </li>
                        @endforeach
                    </ul>
                @endif

                @unless ($report['account_was_checked'])
                    {{-- "No mismatch" and "nothing was checked" are not the
                         same assurance, so the second is said out loud. --}}
                    <p class="mt-3">
                        {{ __('هذا الملف لا يذكر رقم الحساب، فلم يُتحقق من أنه يخص هذا الحساب.') }}
                    </p>
                @endunless
            </div>
        @endif

        @if (session('import_expired'))
            <div role="alert"
                 class="mt-6 rounded-lg border border-warning bg-warning-subtle px-4 py-3 text-sm text-on-warning-subtle">
                {{ __('انتهت صلاحية الملف المرفوع. ارفعه مرة أخرى.') }}
            </div>
        @endif

        @if ($errors->any())
            {{-- The error summary: one region, at the top, with a link to
                 each field it names. A message beside a control twelve rows
                 down is a message a screen-reader user meets after passing
                 it. --}}
            <div role="alert" tabindex="-1" id="errors"
                 class="mt-6 rounded-lg border border-danger bg-danger-subtle px-4 py-3 text-sm text-on-danger-subtle">
                <h2 class="font-medium">{{ __('تعذّر قبول الملف') }}</h2>
                <ul class="mt-2 space-y-2">
                    @foreach ($errors->getMessages() as $field => $messages)
                        @foreach ($messages as $message)
                            <li>
                                <a href="#{{ $field }}" class="underline underline-offset-2">{{ $message }}</a>
                            </li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST"
              action="{{ route('banking.import.preview', $account) }}"
              enctype="multipart/form-data"
              class="mt-6 space-y-6 rounded-xl border border-outline bg-surface p-6 shadow-sm">
            @csrf

            <div>
                <label for="statement" class="block text-sm font-medium text-on-surface">
                    {{ __('ملف الكشف') }}
                </label>
                <input type="file" name="statement" id="statement" required
                       aria-describedby="statement-help"
                       @error('statement') aria-invalid="true" @enderror
                       class="mt-2 block w-full rounded-lg border border-outline bg-surface-sunken px-3 py-2 text-sm text-on-surface
                              file:me-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-sm file:text-on-primary
                              focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                <p id="statement-help" class="mt-2 text-sm text-on-surface-subtle">
                    {{ __('لن يُكتب شيء حتى تراجع ما سيُستورَد.') }}
                </p>
            </div>

            <div>
                <label for="format" class="block text-sm font-medium text-on-surface">
                    {{ __('صيغة الملف') }}
                </label>
                <select name="format" id="format"
                        aria-describedby="format-help"
                        class="mt-2 block w-full rounded-lg border border-outline bg-surface-sunken px-3 py-2 text-sm text-on-surface
                               focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                    <option value="">{{ __('تعرَّف عليها تلقائيًا') }}</option>
                    @foreach ($formats as $format)
                        <option value="{{ $format }}">{{ strtoupper($format) }}</option>
                    @endforeach
                </select>
                <p id="format-help" class="mt-2 text-sm text-on-surface-subtle">
                    {{ __('تُقارَن الصيغة المختارة بمحتوى الملف، ويُرفض ما لا يطابقها.') }}
                </p>
            </div>

            @if ($templates->isNotEmpty())
                <div>
                    <label for="template" class="block text-sm font-medium text-on-surface">
                        {{ __('قالب أعمدة محفوظ') }}
                    </label>
                    <select name="template" id="template"
                            aria-describedby="template-help"
                            class="mt-2 block w-full rounded-lg border border-outline bg-surface-sunken px-3 py-2 text-sm text-on-surface
                                   focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                        <option value="">{{ __('بدون قالب') }}</option>
                        @foreach ($templates as $saved)
                            <option value="{{ $saved->id }}">{{ $saved->name }}</option>
                        @endforeach
                    </select>
                    <p id="template-help" class="mt-2 text-sm text-on-surface-subtle">
                        {{ __('للملفات بصيغة CSV فقط. ستظل ترى المعاينة قبل الاستيراد.') }}
                    </p>
                </div>
            @endif

            <button type="submit"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-on-primary
                           transition-colors duration-(--duration-fast) ease-(--ease-standard)
                           hover:bg-primary-hover
                           focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                {{ __('اعرض ما سيُستورَد') }}
            </button>
        </form>
    </div>
@endsection
