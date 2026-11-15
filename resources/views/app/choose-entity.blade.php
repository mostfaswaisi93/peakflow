@extends('layouts.plain', ['title' => __('اختر الشركة')])

@section('content')
    <div class="rounded-xl border border-outline bg-surface p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-on-surface">{{ __('اختر الشركة') }}</h1>
        <p class="mt-2 text-sm text-on-surface-muted">{{ __('لديك صلاحيات في أكثر من شركة. اختر التي ستعمل فيها.') }}</p>

        @error('entity')
            <div role="alert" class="mt-6 rounded-lg border border-danger bg-danger-subtle px-4 py-3 text-sm text-on-danger-subtle">
                {{ $message }}
            </div>
        @enderror

        {{-- One form per entity: a list of buttons is keyboard-navigable by
             default and needs no radio-plus-submit dance. --}}
        <ul class="mt-6 space-y-2">
            @foreach ($entities as $entity)
                <li>
                    <form method="POST" action="{{ route('entity.switch') }}">
                        @csrf
                        <input type="hidden" name="entity" value="{{ $entity->id }}">
                        <button type="submit"
                                class="flex w-full items-center justify-between rounded-lg border border-outline bg-surface-sunken px-4 py-3 text-start
                                       transition-colors duration-(--duration-fast) ease-(--ease-standard)
                                       hover:border-primary hover:bg-surface-hover
                                       focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                            <span class="font-medium text-on-surface">{{ $entity->legal_name }}</span>
                            <span class="figure text-sm text-on-surface-subtle">{{ $entity->code }}</span>
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
    </div>
@endsection
