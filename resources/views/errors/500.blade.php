@extends('layouts.error', [
    'status' => 500,
    'title' => __('حدث خطأ عندنا'),
    'body' => __('هذا ليس خطأ منك. سُجّل الخطأ بالمرجع أدناه، ولم يُحفظ أي شيء ناقص - العمليات المالية تُرحَّل كاملة أو لا تُرحَّل.'),
])

@section('actions')
    {{--
        The second sentence is the one a user actually needs. On a financial
        system the question behind a 500 is always "did half of it happen",
        and the answer is no - every posting is one transaction. Saying it on
        the page is cheaper than answering it on the phone.
    --}}
    <a href="/">{{ __('إلى الصفحة الرئيسية') }}</a>
@endsection
