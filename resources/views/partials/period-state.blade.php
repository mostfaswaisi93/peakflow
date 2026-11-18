{{--
    Which period today falls in, and whether it will accept a posting - PF-204.

    ## The mistake this prevents

    A user types a document, submits it, and is refused because today is in
    a period somebody soft-closed on Friday. The refusal is correct and
    arrives after the work. Worse is the opposite: the user is in the right
    period and does not know it, so they hunt for a setting instead of
    posting. Either way the information was available the whole time and was
    not on screen.

    ## Why it is read here and not injected

    One query per page, against a tiny table, keyed by the entity already in
    the container. A view composer or a middleware would run it on the
    operator console and the login page too, where there is no entity and
    the question is meaningless.

    ## It is a statement of fact, not a warning

    An open period is stated quietly. Anything else is marked with the
    warning role, because the user is about to try something the period will
    refuse, and a tooltip is not enough for that.
--}}
@php
    $entity = app()->bound('currentEntity') ? app('currentEntity') : null;

    $period = $entity === null ? null : \App\Tenant\Ledger\Models\Period::query()
        ->where('entity_id', $entity->id)
        ->whereDate('starts_on', '<=', today())
        ->whereDate('ends_on', '>=', today())
        ->first();
@endphp

@if ($entity !== null && $period === null)
    {{--
        No period covers today.

        Not a cosmetic gap: nothing can be posted at all, and the usual
        cause is a fiscal calendar that was generated for one year and
        never extended. The symptom without this line is a product that
        refuses every document on the first working day of January with a
        message about the date, and an accountant who concludes the
        product is broken rather than that a calendar ran out.
    --}}
    <span role="alert"
          class="inline-flex items-center gap-1.5 rounded-(--radius-field) bg-warning-subtle px-2.5 py-1 text-caption text-on-warning-subtle">
        {{ __('لا توجد فترة محاسبية لتاريخ اليوم') }}
    </span>
@elseif ($period !== null)
    @php($accepts = $period->state->acceptsPostings())

    <span @class([
            'inline-flex items-center gap-1.5 rounded-(--radius-field) px-2.5 py-1 text-caption',
            $accepts ? 'bg-surface-sunken text-on-surface-muted' : 'bg-warning-subtle text-on-warning-subtle',
        ])
        {{--
            `title` carries the dates, the visible text carries the state.

            The header has room for one of them. The state is what changes
            what the user may do, so the state is the part that is always
            legible, and the span of the period is for anybody who needs to
            check which one they are looking at.
        --}}
        title="{{ $period->starts_on->toDateString() }} – {{ $period->ends_on->toDateString() }}">
        <span class="font-medium">{{ $period->label }}</span>
        <span>·</span>
        <span>{{ __(match ($period->state) {
            \App\Tenant\Ledger\Enums\PeriodState::Future => 'فترة مستقبلية',
            \App\Tenant\Ledger\Enums\PeriodState::Open => 'فترة مفتوحة',
            \App\Tenant\Ledger\Enums\PeriodState::SoftClosed => 'إقفال مبدئي',
            \App\Tenant\Ledger\Enums\PeriodState::Closed => 'فترة مقفلة',
            \App\Tenant\Ledger\Enums\PeriodState::Locked => 'فترة مُقدَّمة للجهة',
        }) }}</span>
    </span>
@endif
