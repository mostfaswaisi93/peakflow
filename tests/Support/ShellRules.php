<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Ui\Navigation;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Throwable;

/**
 * The application shell - PF-204.
 *
 * ## Why the shell is checked by rendering it
 *
 * Every claim PF-204 makes is structural, so every one of them can be read
 * out of the HTML: the skip link is first in the tab order or it is not;
 * `<main>` is focusable or the skip link only moves the viewport; the menu
 * offers a page the user cannot open or it does not. None of that is
 * visible by looking at the template, because the template is a loop over
 * a list assembled somewhere else.
 *
 * What this cannot check is whether the shell is pleasant to use. That
 * needs a person. What it can check is that it is not *unusable*, which is
 * the part that regresses silently - a hidden copy of the navigation
 * duplicating the landmark, a selected row marked only by a colour, a menu
 * item pointing at a route somebody renamed.
 *
 * ## The one that found something
 *
 * "Exactly one navigation landmark" was written because the shell renders
 * the menu twice - a column on a wide screen, a disclosure on a narrow one
 * - and the first version gave both of them `<nav aria-label>`. A screen
 * reader's landmark list then offers two identical "Main navigation"
 * entries with no way to tell which is the one that is hidden at the
 * current width.
 */
final class ShellRules
{
    use Concerns\IssuesInvoices;

    private ?User $user = null;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['the application shell (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->runEachInASavepoint([
                'the skip link is the first thing in the tab order' => fn (): array => $this->skipLinkIsFirst(),
                'and main is focusable, so it lands' => fn (): array => $this->mainIsFocusable(),
                'there is exactly one navigation landmark' => fn (): array => $this->oneLandmark(),
                'every route the menu names exists' => fn (): array => $this->everyRouteExists(),
                'a page that is not built is listed, and is not a link' => fn (): array => $this->unbuiltIsNotALink(),
                'a user is not offered a page they cannot open' => fn (): array => $this->permissionsFilterTheMenu(),
                'an empty section is not rendered at all' => fn (): array => $this->emptySectionsDisappear(),
                'the selected item is marked for a screen reader too' => fn (): array => $this->selectionReachesTheReader(),
                'a detail page keeps its section selected' => fn (): array => $this->detailPagesStaySelected(),
                'the active company and the period state are on every page' => fn (): array => $this->contextIsAlwaysVisible(),
                'the shell is written in logical properties only' => fn (): array => $this->noPhysicalDirections(),
            ]);
        } catch (Throwable $e) {
            return ['the application shell could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300),
            ]];
        } finally {
            Auth::guard('web')->forgetUser();
            $this->connection->rollBack();
        }
    }

    /**
     * A signed-in user holding exactly the roles named.
     *
     * No role at all is a real case and the one the empty-section rule
     * needs: a user who has been invited and not yet given a role sees the
     * shell, and what they see must not be five headings over nothing.
     *
     * @param  list<string>  $roleKeys
     */
    private function signIn(array $roleKeys): User
    {
        $user = User::create([
            'name' => 'Shell Probe',
            'email' => 'shell-probe-'.bin2hex(random_bytes(6)).'@invalid.test',
            'password' => Str::password(32),
            'active' => true,
        ]);

        foreach ($roleKeys as $key) {
            $role = Role::query()->where('key', $key)->first();

            if ($role === null) {
                throw new \RuntimeException(sprintf('the seeded "%s" role is missing', $key));
            }

            UserEntityRole::create([
                'user_id' => $user->getKey(),
                'entity_id' => $this->entityId,
                'role_id' => $role->getKey(),
            ]);
        }

        Auth::guard('web')->setUser($user);
        $this->user = $user;

        return $user;
    }

    /**
     * The shell as a browser receives it.
     *
     * `layouts.app` rather than a route, because the structure under test
     * is the layout's and dispatching a request would drag tenant
     * resolution and the session guard into a check about markup - and
     * those have their own.
     */
    private function render(): string
    {
        app()->instance('currentEntity', $this->entity());

        return View::make('layouts.app')->render();
    }

    /** @return list<string> */
    private function skipLinkIsFirst(): array
    {
        $this->signIn(['accountant']);
        $html = $this->render();

        /*
         | The first focusable thing, by position in the document.
         |
         | Not "is a skip link present" - a skip link that is present and
         | fourth is a skip link nobody reaches, and the shell puts a
         | theme switcher, an entity switcher and a whole sidebar ahead of
         | the content. So the question is its INDEX, and the only honest
         | way to ask is to find every focusable element and look at the
         | first one.
         */
        if (! preg_match('/<(a|button|select|input|textarea|summary)\b[^>]*>/i', $html, $first, PREG_OFFSET_CAPTURE)) {
            return ['the shell rendered with nothing focusable in it at all'];
        }

        $opening = $first[0][0];

        if (! str_contains($opening, 'href="#content"')) {
            return [sprintf(
                'the first focusable element in the shell is %s, not the skip link. A keyboard user then '.
                'tabs through the header and the whole sidebar before reaching the page, on every load.',
                Str::limit(preg_replace('/\s+/', ' ', $opening) ?? '', 120),
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function mainIsFocusable(): array
    {
        $this->signIn(['accountant']);
        $html = $this->render();

        if (! preg_match('/<main\b[^>]*>/i', $html, $main)) {
            return ['the shell has no <main> element, so the skip link points at nothing'];
        }

        $problems = [];

        if (! str_contains($main[0], 'id="content"')) {
            $problems[] = '<main> does not carry id="content", which is where the skip link points';
        }

        /*
         | `tabindex="-1"` on <main> - PF-204.
         |
         | A <main> is not focusable by default. Without this the browser
         | scrolls to the target and leaves focus where it was, so the
         | next Tab goes straight back into the navigation the user just
         | skipped. A skip link that only moves the viewport is worse than
         | none, because it looks like it worked.
         */
        if (! str_contains($main[0], 'tabindex="-1"')) {
            $problems[] = '<main> is not focusable (tabindex="-1" is missing), so the skip link moves the '.
                'viewport and leaves focus in the navigation - the next Tab returns to what was skipped';
        }

        return $problems;
    }

    /** @return list<string> */
    private function oneLandmark(): array
    {
        $this->signIn(['accountant']);
        $html = $this->render();

        $landmarks = preg_match_all('/<nav\b|role="navigation"/i', $html);

        if ($landmarks !== 1) {
            return [sprintf(
                'the shell rendered %d navigation landmarks. The menu is rendered twice - a column on a '.
                'wide screen, a disclosure on a narrow one - and only the one actually displayed may '.
                'carry the landmark, or a screen reader offers two identical entries with no way to tell '.
                'which is the hidden copy.',
                $landmarks,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function everyRouteExists(): array
    {
        $missing = [];

        foreach (Navigation::sections() as $section) {
            foreach ($section['items'] as $item) {
                if ($item['route'] === null || Route::has($item['route'])) {
                    continue;
                }

                $missing[] = sprintf(
                    '"%s" names the route %s, which the application does not have. A menu entry whose '.
                    'route was renamed is a link to a 500, and the person who renamed it will not be '.
                    'the person who finds out.',
                    $item['label_en'],
                    $item['route'],
                );
            }
        }

        return $missing;
    }

    /** @return list<string> */
    private function unbuiltIsNotALink(): array
    {
        $this->signIn(['accountant']);
        $html = $this->render();

        $problems = [];

        foreach (Navigation::forCurrentUser() as $section) {
            foreach ($section['items'] as $item) {
                if ($item['available']) {
                    continue;
                }

                $label = __($item['label_ar']);

                if (! str_contains($html, $label)) {
                    $problems[] = sprintf(
                        '"%s" is not built yet and is not listed either. A menu that shows only what is '.
                        'finished tells a new user nothing about what the product is for.',
                        $item['label_en'],
                    );

                    continue;
                }

                /*
                 | Listed, and not inside an anchor.
                 |
                 | The failure this names is a tab stop that goes nowhere:
                 | a focusable row for a page that does not exist, which a
                 | keyboard user reaches, activates, and lands on a 404
                 | with no way to tell whether they did something wrong.
                 */
                if (preg_match('/<a\b[^>]*>(?:(?!<\/a>).)*'.preg_quote($label, '/').'/su', $html) === 1) {
                    $problems[] = sprintf(
                        '"%s" has no route and is rendered as a link anyway, so it is a tab stop that '.
                        'ends in a 404.',
                        $item['label_en'],
                    );
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function permissionsFilterTheMenu(): array
    {
        /*
         | A role that cannot manage the chart of accounts.
         |
         | The accountant can, so the comparison is between two real roles
         | rather than between a role and a hand-made permission set: a
         | check built on an invented role proves the filter works on
         | invented roles.
         */
        $this->signIn(['accountant']);
        $withAccounts = $this->labelsIn($this->render());

        $this->connection->statement('SAVEPOINT shell_narrow_role');

        try {
            UserEntityRole::query()->where('user_id', $this->user?->getKey())->delete();
            Auth::guard('web')->forgetUser();
            $this->signIn(['auditor']);
            $withoutAccounts = $this->labelsIn($this->render());
        } finally {
            $this->connection->statement('ROLLBACK TO SAVEPOINT shell_narrow_role');
        }

        $problems = [];

        $chart = __('دليل الحسابات');

        if (! \in_array($chart, $withAccounts, true)) {
            $problems[] = 'an accountant was not offered the chart of accounts, so the menu is hiding '.
                'something the user may actually open';
        }

        if (\in_array($chart, $withoutAccounts, true)) {
            $problems[] = 'a user with only the auditor role was offered the chart of accounts. The route '.
                'would refuse them, which makes the menu a list of dead ends rather than of what they can do.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function emptySectionsDisappear(): array
    {
        $this->signIn([]);

        $sections = Navigation::forCurrentUser();
        $labels = array_map(static fn (array $s): string => $s['label_en'], $sections);

        /*
         | A user with no role at all still gets the Overview.
         |
         | That item names no permission, deliberately: the page itself
         | shows figures only to somebody who may see them, and a shell
         | with no navigation whatsoever reads as a broken deployment
         | rather than as an account waiting for a role.
         */
        if ($labels !== ['Overview']) {
            return [sprintf(
                'a user with no role saw the sections [%s]. A heading over no items reads as something '.
                'broken, and four of them reads as a broken product.',
                implode(', ', $labels),
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function selectionReachesTheReader(): array
    {
        $this->signIn(['accountant']);

        $problems = [];

        if (! Navigation::isCurrent('overview', 'overview')) {
            $problems[] = 'the menu does not recognise the page it is on as the current one';
        }

        if (Navigation::isCurrent('overview', 'invoices.index')) {
            $problems[] = 'the menu marked the Overview as current while on the invoice list';
        }

        /*
         | The attribute, not the colour.
         |
         | The selected row is marked by a background and a start border,
         | and neither of those reaches a screen reader or survives
         | forced-colours mode. `aria-current="page"` is the only part of
         | the marking that is readable by something other than an eye.
         */
        $template = (string) file_get_contents(base_path('resources/views/partials/nav-list.blade.php'));

        if (! str_contains($template, 'aria-current="page"')) {
            $problems[] = 'the selected menu item is marked only visually: aria-current="page" is absent, '.
                'so a screen-reader user cannot tell which of twenty rows they are on';
        }

        return $problems;
    }

    /** @return list<string> */
    private function detailPagesStaySelected(): array
    {
        $problems = [];

        if (! Navigation::isCurrent('invoices.index', 'invoices.show')) {
            $problems[] = 'an invoice detail page does not keep the Invoices entry selected. The user has '.
                'not left the section, and a sidebar that loses its highlight makes them think they have.';
        }

        /*
         | And the prefix must not reach across sections.
         |
         | `bank-accounts.index` and `banking.import.create` share no root,
         | and a looser match - "starts with the first few letters" - would
         | light up two sections at once, which is worse than lighting up
         | none.
         */
        if (Navigation::isCurrent('bank-accounts.index', 'bank-reconciliation.index')) {
            $problems[] = 'the current-page match reached across two different route roots, so two menu '.
                'entries would be marked current at once';
        }

        return $problems;
    }

    /** @return list<string> */
    private function contextIsAlwaysVisible(): array
    {
        $this->signIn(['accountant']);
        $html = $this->render();

        $problems = [];
        $entity = $this->entity();

        if (! str_contains($html, $entity->legal_name)) {
            $problems[] = sprintf(
                'the active company (%s) is not named on the page. Posting into the wrong company is the '.
                'mistake this prevents, and it is invisible until a report comes out wrong.',
                $entity->legal_name,
            );
        }

        /*
         | And the state of the period TODAY falls in.
         |
         | Not the fixture's period, which is dated for the books being
         | checked and is usually a different month. The shell is read by
         | somebody about to type a document now, so the question it has
         | to answer is about now - and when no period covers today it has
         | to say that too, because then nothing can be posted at all and
         | the cause is a calendar that ran out rather than anything the
         | user did.
         */
        $today = \App\Tenant\Ledger\Models\Period::query()
            ->where('entity_id', $this->entityId)
            ->whereDate('starts_on', '<=', today())
            ->whereDate('ends_on', '>=', today())
            ->first();

        $expected = $today?->label ?? __('لا توجد فترة محاسبية لتاريخ اليوم');

        if (! str_contains($html, $expected)) {
            $problems[] = sprintf(
                'the shell does not say "%s" anywhere, so a user cannot tell whether the books will '.
                'accept what they are about to type until the refusal arrives, after the work.',
                $expected,
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function noPhysicalDirections(): array
    {
        $problems = [];

        $files = [
            'resources/views/layouts/app.blade.php',
            'resources/views/partials/sidebar.blade.php',
            'resources/views/partials/nav-list.blade.php',
            'resources/views/partials/period-state.blade.php',
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents(base_path($file));

            /*
             | Comments are stripped first.
             |
             | These templates explain in prose why the start border is a
             | start border, and a scan that read its own explanation
             | would report the sentence that documents the rule as a
             | breach of it. That exact mistake was made in another check
             | in this suite's history.
             */
            $source = (string) preg_replace('/\{\{--.*?--\}\}|\/\*.*?\*\//su', '', $source);

            if (preg_match_all('/(?<![\w-])(?:[\w-]+:)*(ml-|mr-|pl-|pr-|text-left|text-right|border-l|border-r|left-|right-)/', $source, $m)) {
                foreach (array_unique($m[0]) as $match) {
                    $problems[] = sprintf(
                        '%s uses "%s". The interface is Arabic first, so a physical direction is the wrong '.
                        'side half the time; use the logical property (ms-, me-, ps-, pe-, text-start, '.
                        'text-end, border-s, border-e, start-, end-).',
                        $file,
                        $match,
                    );
                }
            }
        }

        return $problems;
    }

    /**
     * The menu labels present in a rendered shell.
     *
     * @return list<string>
     */
    private function labelsIn(string $html): array
    {
        $present = [];

        foreach (Navigation::sections() as $section) {
            foreach ($section['items'] as $item) {
                $label = __($item['label_ar']);

                if (str_contains($html, $label)) {
                    $present[] = $label;
                }
            }
        }

        return $present;
    }
}
