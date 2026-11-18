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
    use Concerns\EntersBills;

    /**
     * The templates the shell is made of.
     *
     * Named here rather than globbed, because the point of a list is that
     * adding a template to the shell is a decision somebody writes down.
     */
    private const SHELL_TEMPLATES = [
        'resources/views/layouts/app.blade.php',
        'resources/views/partials/sidebar.blade.php',
        'resources/views/partials/nav-list.blade.php',
        'resources/views/partials/period-state.blade.php',
        'resources/views/partials/breadcrumbs.blade.php',
    ];

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

        /*
         | The web guard, explicitly.
         |
         | `bin/verify all` runs the operator console and the error-response
         | suites before this one, and both dispatch real requests through
         | `auth:ops` and `auth:sanctum`. Laravel's Authenticate middleware
         | calls `shouldUse()`, so the process's DEFAULT guard is whatever
         | the last of those requests used - and `Gate` resolves its user
         | from the default guard. Every authorization check here then asks
         | about an operator, who holds no permission in any tenant, and the
         | whole suite fails on pages that are fine.
         |
         | It passed alone and failed in `all`, which is the shape of a
         | check that depends on state it did not set.
         */
        Auth::shouldUse('web');

        try {
            $this->seedThePayableSide();

            return $this->runEachInASavepoint([
                'the skip link is the first thing in the tab order' => fn (): array => $this->skipLinkIsFirst(),
                'and main is focusable, so it lands' => fn (): array => $this->mainIsFocusable(),
                'every navigation landmark has its own name' => fn (): array => $this->landmarksAreNamed(),
                'the breadcrumb trail is derived, not declared' => fn (): array => $this->theTrailIsDerived(),
                'every route the menu names exists' => fn (): array => $this->everyRouteExists(),
                'a page that is not built is listed, and is not a link' => fn (): array => $this->unbuiltIsNotALink(),
                'a user is not offered a page they cannot open' => fn (): array => $this->permissionsFilterTheMenu(),
                'an empty section is not rendered at all' => fn (): array => $this->emptySectionsDisappear(),
                'the selected item is marked for a screen reader too' => fn (): array => $this->selectionReachesTheReader(),
                'a detail page keeps its section selected' => fn (): array => $this->detailPagesStaySelected(),
                'the active company and the period state are on every page' => fn (): array => $this->contextIsAlwaysVisible(),
                'the shell is written in logical properties only' => fn (): array => $this->noPhysicalDirections(),
                'every page the menu offers actually renders' => fn (): array => $this->everyPageRenders(),
                'and opens for exactly the permission it is offered under' => fn (): array => $this->theMenuNeverOffersARefusal(),
                'and is offered to everybody who can open it' => fn (): array => $this->theMenuHidesNothingReadable(),
                'every page has one top-level heading, from the one component' => fn (): array => $this->oneHeadingPerPage(),
                'and every detail page renders too' => fn (): array => $this->everyDetailPageRenders(),
                'the route names the same permission the menu does' => fn (): array => $this->theRouteAgreesWithTheMenu(),
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
    private function landmarksAreNamed(): array
    {
        $this->signIn(['accountant']);
        $html = $this->render();

        /*
         | Every navigation landmark must be labelled, and labelled
         | DIFFERENTLY.
         |
         | More than one is fine and normal - the menu is one, a
         | breadcrumb trail is another - and a screen reader lists them
         | for the user to jump between. What is not fine is two with the
         | same name, which is what the first version of the shell
         | produced: the menu renders twice, a column on a wide screen and
         | a disclosure on a narrow one, and both were given
         | `<nav aria-label="Main navigation">`. The landmark list then
         | offers two identical entries and one of them is the copy that
         | is hidden at the current width, with nothing to say which.
         |
         | An unlabelled landmark is the same problem a step earlier: it
         | reads as "navigation" and so does the next one.
         */
        preg_match_all('/<nav\b([^>]*)>/i', $html, $tags);

        $labels = [];
        $problems = [];

        foreach ($tags[1] as $attributes) {
            if (! preg_match('/aria-label(?:ledby)?="([^"]+)"/', $attributes, $label)) {
                $problems[] = 'the shell has a <nav> with no accessible name, so it is announced as '.
                    '"navigation" - and so is the next one';

                continue;
            }

            $labels[] = $label[1];
        }

        foreach (array_count_values($labels) as $label => $count) {
            if ($count > 1) {
                $problems[] = sprintf(
                    '%d navigation landmarks are named "%s". A screen reader\'s landmark list then offers '.
                    'identical entries, and one of them is the copy hidden at the current width.',
                    $count,
                    $label,
                );
            }
        }

        /*
         | And the templates are read as well as the output.
         |
         | Rendering the layout outside a dispatched request means the
         | breadcrumb trail has no current route and so renders nothing -
         | which is correct, and leaves its `<nav>` unexercised. A shell
         | landmark that only appears on a real page would be the one
         | nobody notices has lost its name, so the question is asked of
         | the source too.
         */
        foreach (self::SHELL_TEMPLATES as $file) {
            $source = (string) preg_replace(
                '/\{\{--.*?--\}\}/su',
                '',
                (string) file_get_contents(base_path($file)),
            );

            if (! preg_match_all('/<nav\b([^>]*)>/i', $source, $found)) {
                continue;
            }

            foreach ($found[1] as $attributes) {
                if (! preg_match('/aria-label(?:ledby)?=/', $attributes)) {
                    $problems[] = sprintf(
                        '%s has a <nav> with no accessible name. It is announced as "navigation", and so '.
                        'is every other one, so the landmark list stops being a way to get anywhere.',
                        $file,
                    );
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theTrailIsDerived(): array
    {
        $this->signIn(['accountant']);

        $problems = [];

        /*
         | The Overview gets no trail.
         |
         | A breadcrumb whose whole content is the page you are already on
         | is furniture. The trail exists to say where a page sits
         | relative to the top, and on the top there is nothing to say.
         */
        $html = $this->render();

        if (str_contains($html, __('مسار التنقل'))) {
            $problems[] = 'the Overview rendered a breadcrumb trail. A trail whose only crumb is the page '.
                'you are on is furniture, and it pushes the content down on every load.';
        }

        /*
         | A page in no section gets no trail either.
         |
         | Derived means derived: if a page is not in the menu the trail
         | cannot say where it sits, and inventing a parent for it would
         | be a claim nothing checks. An empty trail is visible and
         | truthful; a guessed one is neither.
         */
        $template = (string) file_get_contents(base_path('resources/views/partials/breadcrumbs.blade.php'));

        if (! str_contains($template, 'Navigation::sections()')) {
            $problems[] = 'the breadcrumb trail does not read the navigation declaration, so it is a third '.
                'list of the product\'s shape - and the one nobody notices has drifted, because the page '.
                'still works and only claims to live somewhere it does not';
        }

        return $problems;
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

        foreach (self::SHELL_TEMPLATES as $file) {
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
     * Every page the menu links to, rendered.
     *
     * ## Why this is worth more than every other check in this file
     *
     * The rest of them are about structure. This one runs the page: the
     * query against a real PostgreSQL, the money formatting, the Blade
     * template, and every `route()` call inside it. A page that was written
     * against a column that does not exist, or that formats a `numeric`
     * as a float, or that names a route nobody registered, fails here and
     * nowhere else - and the alternative is finding out from the person
     * who clicked the menu item.
     *
     * ## The controller, not a dispatched request
     *
     * The middleware stack resolves a tenant from the host and a session
     * from a cookie, and reproducing both inside a transaction that gets
     * rolled back would make this a check about middleware. `bin/verify
     * wiring` already asserts the stack's order and that every writing
     * route is authorized. What is unproven is whether the page, given a
     * request and an entity, produces HTML - so that is what is asked.
     *
     * @return list<string>
     */
    private function everyPageRenders(): array
    {
        $this->signIn(['owner']);

        /*
         | With documents on the books, not on an empty entity.
         |
         | An empty state renders happily while the row loop beside it is
         | broken - a column that does not exist, a money value handed to
         | `number_format`, a relation that was never eager-loaded. Two
         | issued invoices and a bill make every page here take the branch
         | that actually reads the data.
         */
        $this->issue();
        $this->issue();

        $problems = [];
        $original = app('request');

        foreach (Navigation::sections() as $section) {
            foreach ($section['items'] as $item) {
                if ($item['route'] === null || ! Route::has($item['route'])) {
                    continue;
                }

                $route = Route::getRoutes()->getByName($item['route']);

                /*
                 | Pages that need an id are left to their own suites.
                 |
                 | Inventing one here would mean either a 404 (which proves
                 | nothing about the template) or seeding a document of
                 | every kind in a check about navigation.
                 */
                if ($route === null || $route->parameterNames() !== []) {
                    continue;
                }

                try {
                    $request = \Illuminate\Http\Request::create('/'.ltrim($route->uri(), '/'), 'GET');
                    app()->instance('request', $request);

                    $rendered = app()->call($route->getController(), ['request' => $request]);

                    $html = $rendered instanceof \Illuminate\Contracts\Support\Renderable
                        ? $rendered->render()
                        : (string) $rendered;

                    if (trim($html) === '') {
                        $problems[] = sprintf('%s rendered nothing at all', $item['route']);
                    }
                } catch (Throwable $e) {
                    $problems[] = sprintf(
                        '%s (%s) threw %s: %s',
                        $item['label_en'],
                        $item['route'],
                        class_basename($e),
                        Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 220),
                    );
                } finally {
                    app()->instance('request', $original);
                }
            }
        }

        return $problems;
    }

    /**
     * A page opened by somebody holding only the permission that offered it.
     *
     * ## The gap this closes
     *
     * `Navigation` decides what to SHOW from a permission, and the page
     * decides what to ALLOW from its own. Nothing made the two agree, so
     * they drifted the moment several people wrote pages at once: one
     * screen authorized the permission its menu entry named, another left
     * the page open and gated only the figures. Both are defensible; a
     * menu entry naming a permission the page does not accept is not,
     * because then the product offers a link that 403s - and the user's
     * only reading of that is that it is broken.
     *
     * So the direction asserted is the one that matters: holding the
     * permission the menu named is ENOUGH. A page open to more people
     * than the menu offers it to is fine and sometimes deliberate, since
     * a direct link is how work gets shared.
     *
     * ## Exactly that permission, through a role made for the question
     *
     * Not "the accountant can open it" - the accountant holds nineteen
     * permissions and any one of them could be the one the page is really
     * reading. A role holding precisely one permission is the only way to
     * learn which.
     *
     * @return list<string>
     */
    private function theMenuNeverOffersARefusal(): array
    {
        $problems = [];
        $original = app('request');

        foreach (Navigation::sections() as $section) {
            foreach ($section['items'] as $item) {
                if ($item['route'] === null || $item['permission'] === null || ! Route::has($item['route'])) {
                    continue;
                }

                $route = Route::getRoutes()->getByName($item['route']);

                if ($route === null || $route->parameterNames() !== []) {
                    continue;
                }

                $this->connection->statement('SAVEPOINT shell_one_permission');

                try {
                    $user = $this->userHoldingOnly($item['permission']);
                    Auth::guard('web')->setUser($user);

                    $request = \Illuminate\Http\Request::create('/'.ltrim($route->uri(), '/'), 'GET');
                    app()->instance('request', $request);

                    app()->call($route->getController(), ['request' => $request]);
                } catch (\Illuminate\Auth\Access\AuthorizationException) {
                    $problems[] = sprintf(
                        '"%s" is offered in the menu to anybody holding %s, and the page then refuses '.
                        'them. The user reads that as a broken product, and the menu is the only place '.
                        'that said they could.',
                        $item['label_en'],
                        $item['permission'],
                    );
                } catch (Throwable $e) {
                    $problems[] = sprintf(
                        '"%s" threw %s for a user holding only %s: %s',
                        $item['label_en'],
                        class_basename($e),
                        $item['permission'],
                        Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 180),
                    );
                } finally {
                    app()->instance('request', $original);
                    Auth::guard('web')->forgetUser();
                    $this->connection->statement('ROLLBACK TO SAVEPOINT shell_one_permission');
                }
            }
        }

        return $problems;
    }

    /**
     * The other direction: a page a reader can open is a page they are offered.
     *
     * ## The bug this names
     *
     * The menu offered the invoice list under `invoice.draft` - a WRITE
     * permission - while the page itself is a read and opens for anybody
     * with a role. So an auditor, whose whole job is to read the invoice
     * list, was not shown it. The product had the page, the auditor was
     * allowed to open it, and nothing anywhere told them it existed:
     * a feature nobody can find is the same as a feature that was never
     * built, which is the sentence `Navigation` opens with.
     *
     * ## `report.view` as the stand-in for "a reader"
     *
     * It is the permission every reading role in the seeded set holds -
     * auditor, both clerks, accountant, controller - and no write role
     * holds it on its own. So "can a reader open this page" is asked of
     * exactly one permission rather than of five roles, and the answer
     * does not change when a role's list is edited.
     *
     * @return list<string>
     */
    private function theMenuHidesNothingReadable(): array
    {
        $problems = [];
        $original = app('request');

        foreach (Navigation::sections() as $section) {
            foreach ($section['items'] as $item) {
                if ($item['route'] === null || $item['permission'] === null || ! Route::has($item['route'])) {
                    continue;
                }

                $route = Route::getRoutes()->getByName($item['route']);

                if ($route === null || $route->parameterNames() !== []) {
                    continue;
                }

                $this->connection->statement('SAVEPOINT shell_reader');

                try {
                    $reader = $this->userHoldingOnly('report.view');
                    Auth::guard('web')->setUser($reader);

                    $request = \Illuminate\Http\Request::create('/'.ltrim($route->uri(), '/'), 'GET');
                    app()->instance('request', $request);

                    app()->call($route->getController(), ['request' => $request]);

                    /*
                     | It opened. So it has to be in their menu.
                     |
                     | `Gate::forUser`, not `Gate::allows`, because the
                     | signed-in user is this probe and the question is
                     | about them specifically - and a check that asked
                     | the ambient gate would answer about whoever the
                     | previous check left behind.
                     */
                    if (! \Illuminate\Support\Facades\Gate::forUser($reader)->allows($item['permission'])) {
                        $problems[] = sprintf(
                            '"%s" opens for a user holding only report.view, and the menu offers it under '.
                            '%s, which they do not hold. The page exists, they may read it, and nothing '.
                            'tells them it is there.',
                            $item['label_en'],
                            $item['permission'],
                        );
                    }
                } catch (\Illuminate\Auth\Access\AuthorizationException) {
                    // The page is not a plain read. Nothing to answer for.
                } catch (Throwable $e) {
                    $problems[] = sprintf(
                        '"%s" threw %s for a reader: %s',
                        $item['label_en'],
                        class_basename($e),
                        Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 180),
                    );
                } finally {
                    app()->instance('request', $original);
                    Auth::guard('web')->forgetUser();
                    $this->connection->statement('ROLLBACK TO SAVEPOINT shell_reader');
                }
            }
        }

        return $problems;
    }

    /** A signed-out user whose single role grants one permission and nothing else. */
    private function userHoldingOnly(string $permission): User
    {
        $permissionId = $this->connection->table('permissions')->where('key', $permission)->value('id');

        if ($permissionId === null) {
            throw new \RuntimeException(sprintf(
                'the menu names the permission "%s", which is not in the catalogue at all',
                $permission,
            ));
        }

        $role = Role::create([
            'key' => 'shell-probe-'.bin2hex(random_bytes(4)),
            'name_ar' => 'دور فحص',
            'name_en' => 'Shell probe role',
            'system' => false,
        ]);

        $this->connection->table('role_permissions')->insert([
            'id' => (string) Str::ulid(),
            'role_id' => $role->getKey(),
            'permission_id' => $permissionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::create([
            'name' => 'One Permission',
            'email' => 'one-permission-'.bin2hex(random_bytes(6)).'@invalid.test',
            'password' => Str::password(32),
            'active' => true,
        ]);

        UserEntityRole::create([
            'user_id' => $user->getKey(),
            'entity_id' => $this->entityId,
            'role_id' => $role->getKey(),
        ]);

        return $user;
    }

    /**
     * The detail pages, each handed a real document.
     *
     * ## Why these are listed by hand
     *
     * A detail page needs an id, and an invented one is a 404 - which
     * renders the "not found" path and proves nothing about the template
     * that was the point. So each one is paired with the fixture that
     * makes a row it can show, written down here, and the check below
     * refuses to let a parameterised page exist without a pairing. A new
     * detail page with no entry fails the build rather than going
     * unrendered, which is the only way a list like this stays true.
     *
     * @return list<string>
     */
    private function everyDetailPageRenders(): array
    {
        $this->signIn(['owner']);

        $subjects = [
            'invoices.show' => fn (): string => (string) $this->issue()->getKey(),
            'bills.show' => fn (): string => (string) $this->enterBill()->getKey(),
            'parties.show' => fn (): string => (string) $this->party?->getKey(),
        ];

        /*
         | Rendered by a suite of its own, and named here so it is not
         | merely absent.
         |
         | The statement-import screen is the one page in this group whose
         | whole purpose is a form, and `BankingScreenRules` renders it
         | with a parsed CSV and asserts the things that make it usable
         | without a mouse. Opening it here with an empty account would
         | render the state nobody imports from.
         */
        $elsewhere = ['banking.import.create'];

        $problems = [];
        $original = app('request');

        /*
         | Every parameterised page in the group is accounted for.
         |
         | Checked before anything is rendered, because the failure worth
         | naming is the page that is NOT in the list: it renders for
         | nobody on every run, and the person who finds out is the one
         | who clicked the row.
         */
        foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
            if (! \is_string($name) || $route->parameterNames() === []) {
                continue;
            }

            if (! \in_array('entity.scope', $route->gatherMiddleware(), true)) {
                continue;
            }

            if (! \in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (! \array_key_exists($name, $subjects) && ! \in_array($name, $elsewhere, true)) {
                $problems[] = sprintf(
                    '%s takes a parameter and is not in this check\'s list, so nothing renders it. Pair '.
                    'it with a fixture that makes a row it can show.',
                    $name,
                );
            }
        }

        foreach ($subjects as $name => $subject) {
            if (! Route::has($name)) {
                $problems[] = sprintf('%s is listed here and is not a route', $name);

                continue;
            }

            $route = Route::getRoutes()->getByName($name);

            $this->connection->statement('SAVEPOINT shell_detail');

            try {
                $id = $subject();

                if ($id === '') {
                    $problems[] = sprintf('no subject could be made for %s', $name);

                    continue;
                }

                $parameter = $route->parameterNames()[0];
                $request = \Illuminate\Http\Request::create('/'.ltrim(str_replace(
                    '{'.$parameter.'}',
                    $id,
                    $route->uri(),
                ), '/'), 'GET');

                app()->instance('request', $request);

                $rendered = app()->call($route->getController(), [
                    'request' => $request,
                    $parameter => $id,
                ]);

                $html = $rendered instanceof \Illuminate\Contracts\Support\Renderable
                    ? $rendered->render()
                    : (string) $rendered;

                if (trim($html) === '') {
                    $problems[] = sprintf('%s rendered nothing at all', $name);
                }
            } catch (Throwable $e) {
                $problems[] = sprintf(
                    '%s threw %s: %s',
                    $name,
                    class_basename($e),
                    Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 220),
                );
            } finally {
                app()->instance('request', $original);
                $this->connection->statement('ROLLBACK TO SAVEPOINT shell_detail');
            }
        }

        return $problems;
    }

    /**
     * One `<h1>` per page, and it comes from `<x-page-header>`.
     *
     * ## Why a check and not only a component
     *
     * Nine screens were written in parallel against the same guidance and
     * produced nine copies of the header block. They happened to agree.
     * The tenth would not have, and the difference nobody would notice is
     * the heading LEVEL: a screen-reader user navigates by headings, so a
     * page whose title is an `<h2>` because somebody copied a card has no
     * top level at all - and it looks exactly the same.
     *
     * Two `<h1>`s is the same problem from the other side. The heading
     * outline then has two tops, and "jump to the first heading" lands on
     * whichever the author wrote first.
     *
     * @return list<string>
     */
    private function oneHeadingPerPage(): array
    {
        $problems = [];
        $pages = glob(base_path('resources/views/app/*/*.blade.php')) ?: [];
        $pages = array_merge($pages, glob(base_path('resources/views/app/*/*/*.blade.php')) ?: []);

        foreach ($pages as $page) {
            $source = (string) preg_replace(
                '/\{\{--.*?--\}\}/su',
                '',
                (string) file_get_contents($page),
            );

            $relative = 'resources/views/app/'.Str::after($page, 'resources/views/app/');
            $headings = preg_match_all('/<h1\b/i', $source);
            $component = preg_match_all('/<x-page-header\b/i', $source);

            if ($headings + $component === 0) {
                $problems[] = sprintf(
                    '%s has no top-level heading at all, so nothing on it says where the reader is.',
                    $relative,
                );

                continue;
            }

            if ($headings + $component > 1) {
                $problems[] = sprintf(
                    '%s has %d top-level headings. The outline then has two tops, and "jump to the first '.
                    'heading" lands on whichever the author happened to write first.',
                    $relative,
                    $headings + $component,
                );
            }

            if ($headings > 0) {
                $problems[] = sprintf(
                    '%s writes its own <h1> instead of using <x-page-header>. That is how the heading '.
                    'level drifts: the copy looks right and is an <h2> on the next page somebody writes '.
                    'from it.',
                    $relative,
                );
            }
        }

        return $problems;
    }

    /**
     * The permission, where it is stated three times, says the same thing.
     *
     * The menu uses it to decide what to offer, the handler to decide what
     * to allow, and the route states it so that a route listing shows it.
     * Three is a decision this codebase makes on purpose - the listing is
     * what an auditor reads, and the handler is what still holds when
     * somebody adds a route to the group and forgets the middleware - and
     * the price of saying a thing three times is that two of them can
     * drift. The two render checks above cover the menu against the
     * handler; this one covers the menu against the route.
     *
     * @return list<string>
     */
    private function theRouteAgreesWithTheMenu(): array
    {
        $problems = [];

        foreach (Navigation::sections() as $section) {
            foreach ($section['items'] as $item) {
                if ($item['route'] === null || ! Route::has($item['route'])) {
                    continue;
                }

                $route = Route::getRoutes()->getByName($item['route']);

                if ($route === null) {
                    continue;
                }

                $stated = [];

                foreach ($route->gatherMiddleware() as $middleware) {
                    if (\is_string($middleware) && str_starts_with($middleware, 'can:')) {
                        $stated[] = Str::after($middleware, 'can:');
                    }
                }

                /*
                 | A route with no `can:` is not a finding.
                 |
                 | The invoice and bill lists are open to anybody with a
                 | role and gate their figures inside the handler, which
                 | is a deliberate choice for a reading page an auditor
                 | should not need a drafting permission to see. What is a
                 | finding is a route that states a DIFFERENT permission
                 | from the one the menu offered it under, because then
                 | the route listing an auditor reads and the menu a user
                 | reads disagree, and neither says so.
                 */
                foreach ($stated as $permission) {
                    if ($permission !== $item['permission']) {
                        $problems[] = sprintf(
                            '"%s" is offered in the menu under %s and its route demands %s. The route '.
                            'listing and the menu then disagree, and nothing says which is the product\'s '.
                            'intent.',
                            $item['label_en'],
                            $item['permission'] ?? 'no permission',
                            $permission,
                        );
                    }
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
