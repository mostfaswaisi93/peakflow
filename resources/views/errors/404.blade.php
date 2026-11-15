@extends('layouts.error', [
    'status' => 404,
    'title' => __('لم نجد هذه الصفحة'),
    'body' => __('الرابط الذي فتحته لا يشير إلى شيء. قد يكون المستند حُذف، أو أن الرابط نُسخ ناقصًا.'),
])

@section('actions')
    {{--
        Two links and not a search box. A 404 under /ops is almost always an
        operator following a stale bookmark, and sending them to the tenant
        application is a second dead end.
    --}}
    @if (request()->is('ops/*'))
        <a href="/ops/tenants">{{ __('إلى قائمة المساحات') }}</a>
    @else
        <a href="/">{{ __('إلى الصفحة الرئيسية') }}</a>
    @endif
@endsection
