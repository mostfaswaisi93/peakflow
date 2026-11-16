{{--
    The one button that writes.

    Separated from every "show me again" button on the page, and worded as
    what it does rather than as "confirm" - somebody who has pressed three
    buttons to get here should not have to remember which of them was the
    irreversible one.
--}}
<div class="mt-6 flex flex-wrap items-center gap-3">
    <button type="submit"
            class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-on-primary
                   transition-colors duration-(--duration-fast) ease-(--ease-standard)
                   hover:bg-primary-hover
                   focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
        {{ __('استورد هذا الكشف') }}
    </button>

    <a href="{{ route('banking.import.create', $account) }}"
       class="rounded-lg border border-outline px-4 py-2 text-sm font-medium text-on-surface
              hover:bg-surface-hover
              focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
        {{ __('ابدأ من جديد بملف آخر') }}
    </a>
</div>
