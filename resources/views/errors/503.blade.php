@extends('layouts.error', [
    'status' => 503,
    'title' => __('النظام تحت الصيانة'),
    'body' => __('نُجري تحديثًا الآن وسيعود النظام قريبًا. لم يُفقد شيء، والعمليات التي أُكملت قبل الصيانة محفوظة.'),
])

@section('actions')
    {{--
        A link to the same URL, not to the home page: a maintenance window
        ends, and the thing the user wanted is still where they left it. A
        link home would make them find it again.
    --}}
    <a href="{{ request()->fullUrl() }}">{{ __('إعادة المحاولة') }}</a>
@endsection
