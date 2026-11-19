@php
    use App\Tenant\Sales\Enums\QuoteState;

    $locale = app()->getLocale();
    $money = static fn (string $amount): string =>
        \App\Support\Money\Money::of($amount, (string) $quote->currency)->format($locale);
@endphp

@extends('layouts.plain', ['title' => $title, 'brand' => false])

@section('content')
    {{--
        The customer's view of a quote - PF-089.

        One document, no navigation, nothing else reachable. A signed link is
        a key to one door, and a page that offers a way to a second door is a
        page where the signature protects nothing.
    --}}
    <main id="content" class="mx-auto w-full max-w-2xl space-y-8">
        <header class="space-y-2">
            <p class="text-sm text-on-surface-muted">{{ $quote->party?->legal_name }}</p>
            <h1 class="text-2xl font-semibold text-on-background">
                {{ __('عرض سعر') }} <span dir="ltr" class="inline-block">{{ $quote->label() }}</span>
            </h1>
            <p class="text-sm text-on-surface-muted">
                {{ __('صالح حتى') }}
                <time dir="ltr" datetime="{{ $quote->valid_until?->toDateString() }}">
                    {{ $quote->valid_until?->toDateString() }}
                </time>
                @unless ($actionable)
                    <span class="ms-2 rounded bg-surface-raised px-2 py-0.5 text-xs text-on-surface-muted">
                        {{ $quote->status->label($locale) }}
                    </span>
                @endunless
            </p>
        </header>

        @if (session('status'))
            <div role="status" class="rounded-lg border border-outline-subtle bg-success-subtle px-4 py-3 text-on-success-subtle">
                {{ session('status') }}
            </div>
        @endif

        @error('quote')
            <div role="alert" class="rounded-lg border border-outline-subtle bg-danger-subtle px-4 py-3 text-on-danger-subtle">
                {{ $message }}
            </div>
        @enderror

        <table class="w-full border-collapse text-sm">
            <caption class="sr-only">{{ __('سطور عرض السعر') }}</caption>
            <thead>
                <tr class="border-b border-outline text-start text-on-surface-muted">
                    <th scope="col" class="py-2 text-start font-medium">{{ __('الوصف') }}</th>
                    <th scope="col" class="py-2 text-end font-medium">{{ __('الكمية') }}</th>
                    <th scope="col" class="py-2 text-end font-medium">{{ __('السعر') }}</th>
                    <th scope="col" class="py-2 text-end font-medium">{{ __('الإجمالي') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr class="border-b border-outline-subtle">
                        <td class="py-2">{{ $line->description }}</td>
                        {{--
                            Numbers stay LTR inside an RTL page.

                            A quantity and a price are Latin digits read left
                            to right whatever the surrounding script, and a
                            bidi-isolated cell is the difference between
                            "10.5" and something a customer queries.
                        --}}
                        <td dir="ltr" class="py-2 text-end tabular-nums">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                        <td dir="ltr" class="py-2 text-end tabular-nums">{{ $money((string) $line->unit_price) }}</td>
                        <td dir="ltr" class="py-2 text-end tabular-nums">{{ $money((string) $line->net_amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row" colspan="3" class="py-1 text-end font-normal text-on-surface-muted">{{ __('الإجمالي قبل الضريبة') }}</th>
                    <td dir="ltr" class="py-1 text-end tabular-nums">{{ $money((string) $quote->net_amount) }}</td>
                </tr>
                <tr>
                    <th scope="row" colspan="3" class="py-1 text-end font-normal text-on-surface-muted">{{ __('الضريبة') }}</th>
                    <td dir="ltr" class="py-1 text-end tabular-nums">{{ $money((string) $quote->tax_amount) }}</td>
                </tr>
                <tr class="border-t border-outline">
                    <th scope="row" colspan="3" class="py-2 text-end font-semibold">{{ __('الإجمالي') }}</th>
                    <td dir="ltr" class="py-2 text-end font-semibold tabular-nums">{{ $money((string) $quote->gross_amount) }}</td>
                </tr>
            </tfoot>
        </table>

        @if ($quote->terms)
            <section class="space-y-1">
                <h2 class="text-sm font-medium text-on-surface-muted">{{ __('الشروط') }}</h2>
                <p class="text-sm text-on-surface">{{ $quote->terms }}</p>
            </section>
        @endif

        @if ($comparison !== null && $previous !== null)
            {{--
                What changed since the version the customer already has.

                They are holding the previous email. Sending a revision
                without saying what moved asks them to compare two PDFs line
                by line, which is how a revision gets accepted on the wrong
                figure.
            --}}
            <section class="space-y-2 rounded-lg border border-outline bg-surface p-4">
                <h2 class="text-sm font-medium text-on-surface">
                    {{ __('ما تغيّر عن النسخة :version', ['version' => (int) $previous->version]) }}
                </h2>

                <p class="text-sm text-on-surface-muted">
                    <span dir="ltr" class="tabular-nums">{{ $money($comparison['totals']['before']) }}</span>
                    →
                    <span dir="ltr" class="tabular-nums">{{ $money($comparison['totals']['after']) }}</span>
                </p>

                <ul class="space-y-1 text-sm text-on-surface">
                    @foreach ($comparison['changed'] as $change)
                        <li>
                            {{ $change['after']['description'] }}:
                            <span dir="ltr" class="tabular-nums">{{ $money((string) $change['before']['net_amount']) }}</span>
                            →
                            <span dir="ltr" class="tabular-nums">{{ $money((string) $change['after']['net_amount']) }}</span>
                        </li>
                    @endforeach
                    @foreach ($comparison['added'] as $added)
                        <li>+ {{ $added['description'] }}</li>
                    @endforeach
                    @foreach ($comparison['removed'] as $removed)
                        <li>− {{ $removed['description'] }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($actionable)
            <section class="grid gap-4 sm:grid-cols-2">
                <form method="POST" action="{{ url()->signedRoute('public.quote.accept', [
                    'tenant' => request()->route('tenant'),
                    'entity' => request()->route('entity'),
                    'quote' => $quote->getKey(),
                ]) }}" class="space-y-3 rounded-lg border border-outline bg-surface p-4">
                    @csrf
                    <h2 class="text-sm font-medium text-on-surface">{{ __('قبول العرض') }}</h2>

                    <label class="block space-y-1">
                        <span class="text-sm text-on-surface-muted">{{ __('الاسم') }}</span>
                        <input type="text" name="accepted_by" required minlength="2" maxlength="200"
                               autocomplete="name"
                               class="min-h-(--control-height) w-full rounded-md border border-outline-control bg-background px-3 py-2 text-on-background outline-offset-2 focus:outline-2 focus:outline-focus-ring">
                    </label>
                    @error('accepted_by')
                        <p class="text-sm text-on-danger-subtle">{{ $message }}</p>
                    @enderror

                    <button type="submit"
                            class="w-full rounded-md bg-primary px-4 py-2 font-medium text-on-primary focus:outline-2 focus:outline-offset-2 focus:outline-focus-ring">
                        {{ __('أقبل') }}
                    </button>
                </form>

                <form method="POST" action="{{ url()->signedRoute('public.quote.decline', [
                    'tenant' => request()->route('tenant'),
                    'entity' => request()->route('entity'),
                    'quote' => $quote->getKey(),
                ]) }}" class="space-y-3 rounded-lg border border-outline bg-surface p-4">
                    @csrf
                    <h2 class="text-sm font-medium text-on-surface">{{ __('رفض العرض') }}</h2>

                    <label class="block space-y-1">
                        <span class="text-sm text-on-surface-muted">{{ __('السبب (اختياري)') }}</span>
                        <textarea name="reason" rows="2" maxlength="2000"
                                  class="w-full rounded-md border border-outline-control bg-background px-3 py-2 text-on-background outline-offset-2 focus:outline-2 focus:outline-focus-ring"></textarea>
                    </label>

                    <button type="submit"
                            class="w-full rounded-md border border-outline px-4 py-2 font-medium text-on-surface focus:outline-2 focus:outline-offset-2 focus:outline-focus-ring">
                        {{ __('أرفض') }}
                    </button>
                </form>
            </section>
        @elseif ($quote->status === QuoteState::Accepted)
            <p class="rounded-lg border border-outline bg-surface px-4 py-3 text-sm text-on-surface">
                {{ __('تم قبول هذا العرض بواسطة :name.', ['name' => (string) $quote->accepted_by_name]) }}
            </p>
        @elseif ($quote->status === QuoteState::Superseded)
            <p class="rounded-lg border border-outline bg-surface px-4 py-3 text-sm text-on-surface">
                {{ __('صدرت نسخة أحدث من هذا العرض. تواصل معنا للحصول على الرابط الجديد.') }}
            </p>
        @else
            <p class="rounded-lg border border-outline bg-surface px-4 py-3 text-sm text-on-surface">
                {{ __('هذا العرض لم يعد قابلاً للقبول.') }}
            </p>
        @endif

        {{-- The platform signs the page quietly, in the footer: the document
             above is the issuer's, and their name leads it. The PF monogram,
             because at this size the kit says anything under 120 px is the
             monogram. --}}
        <footer class="flex items-center justify-center gap-2 pt-4 text-caption text-on-surface-muted">
            <x-brand.logo size="monogram" decorative class="h-5"/>
            <span>{{ __('صادر عبر :app', ['app' => __('بيك فلو')]) }}</span>
        </footer>
    </main>
@endsection
