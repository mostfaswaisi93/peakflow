@extends('layouts.app', ['title' => __('العملاء والموردون'), 'wide' => true])

{{--
    Customers and suppliers - PF-059.

    A read-only list. Nothing on this page writes, so there is no form here
    but the search, and the search is a GET: a list whose URL carries the
    search is a list somebody can send to a colleague, and the back button
    returns to the rows they were reading rather than to an empty table.

    Roles are columns of their own rather than one "type", because a party can
    be a customer AND a supplier AND an employee at once. Collapsing that into
    a single label is what makes somebody create a second record for the
    company they already trade with in the other direction.

    An INACTIVE party is drawn differently and says so in words: a sunken row,
    a quieter name and the status spelled out. Issuing an invoice to a party
    somebody deactivated is a mistake made by picking the wrong line out of a
    list, and a list where the wrong line looks like the right one is what
    makes it.
--}}

@section('content')
    @php
        /*
         | The detail page is linked only when its route is registered.
         |
         | `route()` on a name the application does not have throws, so a list
         | that assumed the detail route would be a 500 on the index for as
         | long as the two land apart - and the index is the page somebody
         | opens to find out whether any of this works.
         */
        $linksToDetail = \Illuminate\Support\Facades\Route::has('parties.show');

        $roleLabels = [
            'customer' => __('عميل'),
            'supplier' => __('مورد'),
            'employee' => __('موظف'),
        ];
    @endphp

    <x-page-header :title="__('العملاء والموردون')">
        <x-slot:description>
            {{ __('هذه القائمة مشتركة بين شركات المساحة كلها. ما يستحق على الطرف وحدّه الائتماني يُحسبان لكل شركة على حدة.') }}
        </x-slot:description>
        <x-slot:aside>
            @if ($entity)
                <p class="inline-flex items-center gap-2 rounded-full border border-outline bg-surface px-3 py-1 text-caption text-on-surface-muted">
                    {{ __('الشركة الحالية') }}
                    <span class="font-semibold text-on-surface">{{ $entity->legal_name }}</span>
                </p>
            @endif
        </x-slot:aside>
    </x-page-header>

    <form method="GET" class="mt-6 flex flex-wrap items-end gap-3">
        <div class="w-full max-w-sm">
            <label for="q" class="block text-label font-medium text-on-surface">{{ __('بحث بالاسم أو الرمز') }}</label>
            {{-- `dir="auto"` because the term may be Arabic or a Latin code,
                 and a field that imposes one direction puts the cursor at the
                 wrong end for half of what is typed into it. --}}
            <input id="q" name="q" type="search" dir="auto" value="{{ $term }}" maxlength="120"
                   class="mt-1.5 block w-full rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-body text-on-surface
                          focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus-ring">
        </div>

        <x-button type="submit">{{ __('بحث') }}</x-button>

        @if ($term !== '')
            <a href="{{ url()->current() }}"
               class="rounded-button px-3 py-2 text-label text-on-surface-muted underline
                      hover:text-on-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
                {{ __('إلغاء البحث') }}
            </a>
        @endif
    </form>

    <p class="mt-4 text-label text-on-surface-muted">
        {{ \App\Support\Lang\Message::of('parties.found', ['count' => $parties->total()]) }}
    </p>

    <div class="mt-4 overflow-x-auto rounded-card border border-outline">
        <table class="w-full text-label">
            <caption class="sr-only">{{ __('العملاء والموردون') }}</caption>
            <thead class="bg-surface-sunken text-on-surface-muted">
                <tr>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الرمز') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الاسم النظامي') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الاسم التجاري') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الأدوار') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الدولة') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('العملة الافتراضية') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الحالة') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-subtle bg-surface">
                @forelse ($parties as $party)
                    @php
                        $isActive = (bool) $party->active;

                        $roles = array_values(array_filter([
                            $party->is_customer ? $roleLabels['customer'] : null,
                            $party->is_supplier ? $roleLabels['supplier'] : null,
                            $party->is_employee ? $roleLabels['employee'] : null,
                        ]));

                        $name = (string) $party->legal_name;
                        $detailUrl = $linksToDetail ? route('parties.show', $party) : null;
                    @endphp

                    <tr @class(['bg-surface-sunken' => ! $isActive])>
                        <td class="px-4 py-3">
                            {{-- The code is an identifier: it does not reorder
                                 around its separators in Arabic. --}}
                            <span dir="ltr" class="figure text-on-surface-muted">{{ $party->code ?? '—' }}</span>
                        </td>

                        <td class="px-4 py-3">
                            @if ($detailUrl)
                                <a href="{{ $detailUrl }}"
                                   @class([
                                       'rounded-sm font-medium underline-offset-2 hover:underline',
                                       'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring',
                                       'text-on-surface' => $isActive,
                                       'text-on-surface-muted' => ! $isActive,
                                   ])>{{ $name }}</a>
                            @else
                                <span @class(['font-medium', 'text-on-surface' => $isActive, 'text-on-surface-muted' => ! $isActive])>{{ $name }}</span>
                            @endif
                        </td>

                        <td class="px-4 py-3 text-on-surface-muted">{{ $party->trading_name ?? '—' }}</td>

                        <td class="px-4 py-3">
                            @if ($roles === [])
                                {{-- A party holding no role at all is a record
                                     nothing can use, and worth seeing as such
                                     rather than as an empty cell. --}}
                                <span class="text-on-surface-subtle">{{ __('بلا دور') }}</span>
                            @else
                                <span class="flex flex-wrap gap-1.5">
                                    @foreach ($roles as $role)
                                        <span class="inline-flex items-center rounded-full bg-primary-subtle px-2 py-0.5 text-caption font-medium text-on-primary-subtle">
                                            {{ $role }}
                                        </span>
                                    @endforeach
                                </span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <span dir="ltr" class="figure text-on-surface">{{ $party->country }}</span>
                        </td>

                        <td class="px-4 py-3">
                            <span dir="ltr" class="figure text-on-surface">{{ $party->default_currency ?? '—' }}</span>
                        </td>

                        <td class="px-4 py-3">
                            {{-- The word, not only the shading: a row told
                                 apart by its background alone is a row that
                                 looks ordinary in forced-colours mode and on
                                 a printout. --}}
                            @if ($isActive)
                                <span class="inline-flex items-center rounded-full bg-success-subtle px-2 py-0.5 text-caption font-medium text-on-success-subtle">
                                    {{ __('نشط') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full bg-warning-subtle px-2 py-0.5 text-caption font-medium text-on-warning-subtle">
                                    <span aria-hidden="true">!</span>{{ __('غير نشط') }}
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-on-surface-muted">
                            @if ($term === '')
                                {{ __('لا توجد أطراف بعد.') }}
                            @else
                                {{ __('لا يوجد طرف يطابق هذا البحث.') }}
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $parties->links() }}</div>
@endsection
