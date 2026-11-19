@extends('layouts.app', ['title' => __('الأصناف'), 'wide' => true])

{{--
    The catalogue - PF-064.

    Read-only, and the search is a GET for the same reason as the party list:
    the URL carries the search, so it can be sent to somebody else.

    `type` gets a column of its own, at the weight of a heading, with the
    consequence written underneath each value. It is not a category: it
    decides PLACE OF SUPPLY, and with it which country's VAT applies and at
    what rate. A digital service sold to a consumer in another member state is
    taxed where the consumer is; the same sale of a physical good is not.

    Getting it wrong produces no error anywhere. It produces a return filed in
    the wrong country, and the correction is a voluntary disclosure - which is
    why the type is the most prominent thing in the row and not a tag in the
    corner of it.
--}}

@section('content')
    @php
        $locale = app()->getLocale();

        /*
         | The labels, and what each type does to the tax.
         |
         | An unknown type is NOT mapped to the nearest label below: a value
         | the catalogue does not recognise is shown exactly as it is stored,
         | because a default label is how a wrong type stops being visible.
         */
        $typeLabels = [
            'good' => __('سلعة'),
            'service' => __('خدمة'),
            'digital_service' => __('خدمة رقمية'),
        ];

        $placeOfSupply = [
            'good' => __('مكان التوريد حيث تُسلَّم السلعة'),
            'service' => __('مكان التوريد بحسب قواعد الخدمات'),
            'digital_service' => __('مكان التوريد حيث يكون العميل'),
        ];

        $costMethods = [
            'weighted_average' => __('المتوسط المرجّح'),
            'fifo' => __('الوارد أولًا صادر أولًا'),
        ];

        $yes = __('نعم');
        $no = __('لا');
    @endphp

    <x-page-header :title="__('الأصناف')">
        <x-slot:description>
            {{ __('الأصناف مشتركة بين شركات المساحة كلها، أما الحسابات التي يُرحَّل إليها الصنف فهي لكل شركة بحسب دليل حساباتها.') }}
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

    <p class="mt-4 max-w-3xl rounded-card border border-outline bg-surface p-4 text-label text-on-surface">
        {{ __('النوع يحدد مكان التوريد، وبه تُحدَّد ضريبة أي دولة تنطبق وبأي نسبة. خطؤه لا يُظهر رسالة خطأ، بل يُنتج إقرارًا في الدولة الخطأ، وتصحيحه إفصاح طوعي.') }}
    </p>

    <form method="GET" class="mt-6 flex flex-wrap items-end gap-3">
        <div class="w-full max-w-sm">
            <label for="q" class="block text-label font-medium text-on-surface">{{ __('بحث بالرمز أو الاسم') }}</label>
            {{-- `dir="auto"`: the term may be an Arabic name or a Latin code,
                 and a field that imposes one direction puts the cursor at the
                 wrong end for half of what is typed into it. --}}
            <input id="q" name="q" type="search" dir="auto" value="{{ $term }}" maxlength="120"
                   class="min-h-(--control-height) mt-1.5 block w-full rounded-field border border-outline-control bg-surface-sunken px-3 py-2 text-body text-on-surface
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
        {{ \App\Support\Lang\Message::of('items.found', ['count' => $items->total()]) }}
    </p>

    <div class="mt-4 overflow-x-auto rounded-card border border-outline">
        <table class="w-full text-label">
            <caption class="sr-only">{{ __('الأصناف') }}</caption>
            <thead class="bg-surface-sunken text-on-surface-muted">
                <tr>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الرمز') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الاسم') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('النوع') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('وحدة القياس') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('مخزّن') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('طريقة التكلفة') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('قابل للبيع') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('قابل للشراء') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('الحالة') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-subtle bg-surface">
                @forelse ($items as $item)
                    @php
                        $isActive = (bool) $item->active;
                        $type = (string) $item->type;
                        $isStocked = (bool) $item->stocked;
                        $method = $item->cost_method === null ? null : (string) $item->cost_method;

                        $typeLabel = $typeLabels[$type] ?? $type;
                        $typeIsKnown = \array_key_exists($type, $typeLabels);
                        $supply = $placeOfSupply[$type] ?? null;

                        // The item's own contribution to the determination,
                        // asked of the model rather than re-derived here.
                        $followsCustomer = $item->placeOfSupplyFollowsCustomer();

                        $unitName = $item->unitName($locale);
                    @endphp

                    <tr @class(['bg-surface-sunken' => ! $isActive])>
                        <td class="px-4 py-3">
                            <span dir="ltr" class="figure text-on-surface-muted">{{ $item->code }}</span>
                        </td>

                        <td class="px-4 py-3">
                            <span @class(['block font-medium', 'text-on-surface' => $isActive, 'text-on-surface-muted' => ! $isActive])>{{ $item->name_ar }}</span>
                            <span dir="ltr" class="mt-0.5 block text-caption text-on-surface-subtle">{{ $item->name_en }}</span>
                        </td>

                        <td class="px-4 py-3">
                            {{-- The type whose tax follows the CUSTOMER is the
                                 one that is wrong most expensively, so it
                                 carries the heavier edge. The words beneath it
                                 say the same thing: the border is emphasis,
                                 never the information. --}}
                            <span @class([
                                'inline-flex items-center rounded-full border px-2.5 py-0.5 text-label font-semibold',
                                'border-outline-strong text-on-surface' => ! $followsCustomer,
                                'border-insight bg-insight-subtle text-on-insight-subtle' => $followsCustomer,
                            ])>{{ $typeLabel }}</span>

                            @if ($typeIsKnown)
                                <span class="mt-1 block text-caption text-on-surface-muted">{{ $supply }}</span>
                            @else
                                {{-- A type the catalogue does not know is shown
                                     as stored and said to be unknown, rather
                                     than labelled with the nearest guess. --}}
                                <span class="mt-1 block text-caption text-on-surface-muted">{{ __('نوع غير معروف، ومكان التوريد غير محدَّد') }}</span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <span dir="ltr" class="figure text-on-surface">{{ $item->unit_code }}</span>

                            {{-- The registry answers with the code itself for a
                                 unit it does not carry, and printing "ZZ ZZ"
                                 reads as a rendering fault rather than as an
                                 unknown unit. --}}
                            @if ($unitName !== (string) $item->unit_code)
                                <span class="mt-0.5 block text-caption text-on-surface-muted">{{ $unitName }}</span>
                            @endif
                        </td>

                        <td class="px-4 py-3 text-on-surface">{{ $isStocked ? $yes : $no }}</td>

                        <td class="px-4 py-3">
                            @if (! $isStocked)
                                {{-- A cost method on something that carries no
                                     stock would be a figure with nothing to
                                     measure, so the cell is empty rather than
                                     filled with a default. --}}
                                <span class="text-on-surface-subtle">—</span>
                            @elseif ($method === null)
                                {{-- Stocked and without one: the database
                                     refuses this, so seeing it means the row
                                     predates the constraint and every cost of
                                     sale on it is whichever method the code
                                     happened to pick. --}}
                                <span class="inline-flex items-center rounded-full bg-warning-subtle px-2 py-0.5 text-caption font-medium text-on-warning-subtle">
                                    {{ __('مخزّن بلا طريقة تكلفة') }}
                                </span>
                            @else
                                <span class="text-on-surface">{{ $costMethods[$method] ?? $method }}</span>
                            @endif
                        </td>

                        <td class="px-4 py-3 text-on-surface">{{ $item->sellable ? $yes : $no }}</td>
                        <td class="px-4 py-3 text-on-surface">{{ $item->purchasable ? $yes : $no }}</td>

                        <td class="px-4 py-3">
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
                        <td colspan="9" class="px-4 py-8 text-start text-on-surface-muted">
                            @if ($term === '')
                                {{ __('لا توجد أصناف بعد.') }}
                            @else
                                {{ __('لا يوجد صنف يطابق هذا البحث.') }}
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $items->links() }}</div>
@endsection
