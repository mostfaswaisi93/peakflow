@extends('layouts.error', [
    'status' => 419,
    'title' => __('انتهت صلاحية الصفحة'),
    'body' => __('بقيت الصفحة مفتوحة مدة طويلة فانتهى رمز الحماية الخاص بها. لم يُحفظ شيء ولم يُرسل شيء. افتح الصفحة من جديد وأعد المحاولة.'),
])

@section('actions')
    {{--
        "Nothing was saved" is the sentence that matters here. A 419 on a
        financial form reads as "did my entry post?", and the honest answer is
        that the request never reached the application - so the page says so
        before it offers a link.
    --}}
    <a href="{{ request()->is('ops/*') ? '/ops/login' : '/login' }}">{{ __('تسجيل الدخول من جديد') }}</a>
    <a href="/">{{ __('إلى الصفحة الرئيسية') }}</a>
@endsection
