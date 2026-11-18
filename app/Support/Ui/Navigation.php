<?php

declare(strict_types=1);

namespace App\Support\Ui;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * What the product has, declared once - PF-204.
 *
 * ## Why this is data and not markup
 *
 * A sidebar written as markup is a second list of what exists. It goes
 * out of date the first time somebody adds a page and forgets it, and
 * the symptom is a feature nobody can find - which is the same as a
 * feature that was never built.
 *
 * So the navigation is this array, a check asserts that every route in
 * the application appears in it or is deliberately excluded, and the
 * template renders whatever is here.
 *
 * ## Permissions decide what is SHOWN, not what is allowed
 *
 * Each item names the permission that opens it, and an item the user
 * cannot use is not rendered. That is a courtesy and not a control: the
 * route itself is authorized, and a menu that was the only thing
 * standing between somebody and a page would be a menu somebody types
 * past.
 *
 * A section whose every item is hidden disappears with them, because a
 * heading over nothing reads as something broken.
 *
 * ## Items that are not built yet say so
 *
 * `route` null means the page does not exist. It is still listed, with
 * its section, deliberately: a product whose menu shows only what is
 * finished tells a new user nothing about what it is FOR, and an
 * operator comparing it against the backlog cannot see where the gaps
 * are. They render as unavailable rather than as links, so nobody
 * clicks into a 404.
 */
final class Navigation
{
    /**
     * @return list<array{
     *     label_ar: string, label_en: string, icon: string,
     *     items: list<array{label_ar: string, label_en: string, route: ?string, permission: ?string}>
     * }>
     */
    public static function sections(): array
    {
        return [
            [
                'label_ar' => 'نظرة عامة',
                'label_en' => 'Overview',
                'icon' => 'home',
                'items' => [
                    ['label_ar' => 'نظرة عامة', 'label_en' => 'Overview', 'route' => 'overview', 'permission' => null],
                ],
            ],
            [
                'label_ar' => 'المبيعات',
                'label_en' => 'Sales',
                'icon' => 'sales',
                'items' => [
                    /*
                     | `report.view`, not `invoice.draft`.
                     |
                     | The list is a read. Naming the permission that
                     | DRAFTS an invoice meant an auditor - whose whole
                     | job is to read this list - was never shown it, and
                     | nothing anywhere else would have told them the page
                     | was there. Creating an invoice is a button on the
                     | page, gated separately.
                     */
                    ['label_ar' => 'الفواتير', 'label_en' => 'Invoices', 'route' => 'invoices.index', 'permission' => 'report.view'],
                    ['label_ar' => 'الإشعارات الدائنة', 'label_en' => 'Credit notes', 'route' => null, 'permission' => 'credit_note.issue'],
                    ['label_ar' => 'عروض الأسعار', 'label_en' => 'Quotes', 'route' => null, 'permission' => 'quote.manage'],
                    ['label_ar' => 'كشوف الحساب', 'label_en' => 'Statements', 'route' => 'statements.index', 'permission' => 'report.view'],
                ],
            ],
            [
                'label_ar' => 'المشتريات',
                'label_en' => 'Purchasing',
                'icon' => 'purchasing',
                'items' => [
                    ['label_ar' => 'فواتير الموردين', 'label_en' => 'Bills', 'route' => 'bills.index', 'permission' => 'report.view'],
                    ['label_ar' => 'أوامر الشراء', 'label_en' => 'Purchase orders', 'route' => null, 'permission' => 'purchase_order.manage'],
                    ['label_ar' => 'مطالبات المصروفات', 'label_en' => 'Expense claims', 'route' => null, 'permission' => 'expense_claim.submit'],
                ],
            ],
            [
                'label_ar' => 'البنوك والسداد',
                'label_en' => 'Banking and settlement',
                'icon' => 'bank',
                'items' => [
                    /*
                     | Importing a statement is NOT listed here.
                     |
                     | It is reached from a bank account, because a parsed
                     | statement without an account to attach it to is the
                     | one import mistake that cannot be undone from the
                     | screen: the lines land, matched against nothing.
                     */
                    ['label_ar' => 'الحسابات البنكية', 'label_en' => 'Bank accounts', 'route' => 'bank-accounts.index', 'permission' => 'bank.manage'],
                    ['label_ar' => 'المطابقة البنكية', 'label_en' => 'Reconciliation', 'route' => null, 'permission' => 'bank.reconcile'],
                    ['label_ar' => 'مسيّرات الدفع', 'label_en' => 'Payment runs', 'route' => 'payment-runs.index', 'permission' => 'payment.prepare'],
                ],
            ],
            [
                'label_ar' => 'الدفاتر',
                'label_en' => 'Ledger',
                'icon' => 'ledger',
                'items' => [
                    ['label_ar' => 'ميزان المراجعة', 'label_en' => 'Trial balance', 'route' => 'trial-balance.index', 'permission' => 'report.view'],
                    ['label_ar' => 'قائمة المركز المالي', 'label_en' => 'Financial position', 'route' => 'financial-position.index', 'permission' => 'report.view'],
                    ['label_ar' => 'قائمة الأرباح أو الخسائر', 'label_en' => 'Profit or loss', 'route' => 'profit-or-loss.index', 'permission' => 'report.view'],
                    ['label_ar' => 'دليل الحسابات', 'label_en' => 'Chart of accounts', 'route' => 'accounts.index', 'permission' => 'account.manage'],
                    ['label_ar' => 'قيود اليومية', 'label_en' => 'Journal entries', 'route' => null, 'permission' => 'journal.draft'],
                    ['label_ar' => 'الفترات', 'label_en' => 'Periods', 'route' => null, 'permission' => 'period.close'],
                ],
            ],
            [
                'label_ar' => 'البيانات الرئيسية',
                'label_en' => 'Master data',
                'icon' => 'masters',
                'items' => [
                    ['label_ar' => 'العملاء والموردون', 'label_en' => 'Customers and suppliers', 'route' => 'parties.index', 'permission' => 'party.manage'],
                    ['label_ar' => 'الأصناف', 'label_en' => 'Items', 'route' => 'items.index', 'permission' => 'item.manage'],
                    ['label_ar' => 'العملات', 'label_en' => 'Currencies', 'route' => 'currencies.index', 'permission' => 'report.view'],
                    ['label_ar' => 'قوائم الأسعار', 'label_en' => 'Price lists', 'route' => null, 'permission' => 'price_list.manage'],
                    ['label_ar' => 'الضرائب', 'label_en' => 'Tax', 'route' => null, 'permission' => 'tax.manage'],
                ],
            ],
        ];
    }

    /**
     * The sections this user can see, with the unusable items removed.
     *
     * @return list<array<string, mixed>>
     */
    public static function forCurrentUser(): array
    {
        $visible = [];

        foreach (self::sections() as $section) {
            $items = array_values(array_filter(
                $section['items'],
                static fn (array $item): bool => $item['permission'] === null
                    || Gate::allows($item['permission']),
            ));

            /*
             | A section with nothing left in it disappears entirely.
             |
             | A heading over an empty space reads as something broken,
             | and a user who sees four of those concludes the product
             | is.
             */
            if ($items === []) {
                continue;
            }

            $visible[] = array_merge($section, [
                'items' => array_map(static fn (array $item): array => array_merge($item, [
                    /*
                     | Resolved here, because a route named in this list
                     | and absent from the application is a link to a
                     | 500 - and the check that catches that reads this
                     | same method.
                     */
                    'url' => $item['route'] !== null && Route::has($item['route'])
                        ? route($item['route'])
                        : null,
                    'available' => $item['route'] !== null && Route::has($item['route']),
                ]), $items),
            ]);
        }

        return $visible;
    }

    /**
     * Whether a route is the one being looked at, or inside it.
     *
     * `$current` is a parameter rather than only a lookup so that the
     * prefix rule below can be checked as the arithmetic it is. Reaching
     * for the router instead would mean a check could only ask the
     * question from inside a dispatched request, which is exactly the
     * setup cost that stops a rule like this from being checked at all.
     */
    public static function isCurrent(?string $name, ?string $current = null): bool
    {
        if ($name === null) {
            return false;
        }

        $current ??= Route::currentRouteName();

        if ($current === null) {
            return false;
        }

        /*
         | A prefix match, so a detail page keeps its section open.
         |
         | `invoices.show` is inside `invoices.index` as far as a person
         | navigating is concerned, and a sidebar that lost its
         | highlight on every detail page would make the user think
         | they had left the section.
         */
        $root = explode('.', $name)[0];

        return $current === $name || str_starts_with($current, $root.'.');
    }
}
