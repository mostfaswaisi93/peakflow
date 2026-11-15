@extends('layouts.error', [
    'status' => 403,
    'title' => __('لا تملك صلاحية هذا الإجراء'),
    'body' => __('حسابك موجود وصلاحياته لا تشمل هذا. إن كان ينبغي أن تشمله، فالمسؤول في مساحتك هو من يمنحها - ولا يمكننا منحها نيابة عنه.'),
])

@section('actions')
    {{--
        No "request access" button. There is no such flow, and a button that
        does nothing on the page explaining that you cannot do something is
        worse than no button.
    --}}
    @if (request()->is('ops/*'))
        <a href="/ops/tenants">{{ __('إلى قائمة المساحات') }}</a>
    @else
        <a href="/">{{ __('إلى الصفحة الرئيسية') }}</a>
    @endif
@endsection
