<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Iso\LanguageRegistry;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Throwable;

/**
 * What a failure looks like from outside - PF-015.
 *
 * ## The templates are rendered directly, and here is why that is necessary
 *
 * PF-015 says a status-code assertion never reaches these templates. Measured,
 * with `APP_DEBUG=true`:
 *
 *     GET /boom         -> 500, 865,055 bytes, errors/500 NOT rendered
 *     GET /unavailable  -> 503,   3,074 bytes, errors/503 rendered
 *     GET /forbidden    -> 403,   3,155 bytes, errors/403 rendered
 *     GET /nope         -> 404,   3,055 bytes, errors/404 rendered
 *
 * A request that returns 500 hands back the debug page, so a test asserting
 * `status === 500` passes while the template is never touched - and that
 * template is the one that matters most, because a 500 is the moment a user
 * most needs to be told that nothing was half-saved.
 *
 * So the five views are rendered through the view factory, by name, and the
 * HTTP pipeline is used only for the thing it can actually prove: which login
 * an unauthenticated request is sent to.
 *
 * ## And why they must survive the database
 *
 * The 500 and 503 pages exist for the incidents where the platform database is
 * the thing that broke. The session store IS that database, so a view reading
 * `session()` throws inside the handler that was rendering the explanation -
 * and Laravel falls back to a bare "Server Error" string with no way back and
 * no incident reference. That is why these pages have their own layout rather
 * than sharing `layouts.plain`, and why the rule below points every connection
 * at a dead host before rendering.
 */
final class ErrorResponseRules
{
    /** @var array<int, array{string, string}> */
    private const PAGES = [
        [404, 'لم نجد هذه الصفحة'],
        [403, 'لا تملك صلاحية هذا الإجراء'],
        [419, 'انتهت صلاحية الصفحة'],
        [500, 'حدث خطأ عندنا'],
        [503, 'النظام تحت الصيانة'],
    ];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'all five error views render, with the status and a way back' => $this->allFiveRender(),
            'they render with every database connection dead' => $this->theySurviveABrokenDatabase(),
            'they run no query at all' => $this->theyRunNoQuery(),
            'they depend on no build artifact and no session' => $this->theyDependOnNothing(),
            'an unauthenticated request reaches the right login' => $this->theRightLogin(),
            'an API error is problem+json and names its type' => $this->apiErrorsAreProblemJson(),
        ];
    }

    /** @return list<string> */
    private function allFiveRender(): array
    {
        $problems = [];

        foreach (self::PAGES as [$status, $title]) {
            try {
                $html = View::make('errors.'.$status)->render();
            } catch (Throwable $e) {
                $problems[] = sprintf('errors/%d did not render: %s', $status, $e->getMessage());

                continue;
            }

            if (! str_contains($html, $title)) {
                $problems[] = sprintf(
                    'errors/%d does not contain its own title. A page that does not say what happened is '.
                    'a blank wall with a number on it.',
                    $status,
                );
            }

            if (! str_contains($html, (string) $status)) {
                $problems[] = sprintf(
                    'errors/%d does not show the status code. It is the one thing a user can repeat back '.
                    'to support that means the same to both of them.',
                    $status,
                );
            }

            /*
             | A way back, which the criterion asks for by name.
             |
             | An error page with no link is a dead end, and the browser's
             | back button goes to the request that just failed.
             */
            if (preg_match('/<a\s+href=/', $html) !== 1) {
                $problems[] = sprintf(
                    'errors/%d offers no link. The back button returns to the request that just failed, '.
                    'so a page with no way out is a loop.',
                    $status,
                );
            }

            // And the direction follows the locale, both ways.
            foreach (LanguageRegistry::served() as $locale) {
                $previous = app()->getLocale();
                app()->setLocale($locale);

                try {
                    $localised = View::make('errors.'.$status)->render();
                } finally {
                    app()->setLocale($previous);
                }

                $expected = LanguageRegistry::direction($locale);

                if (! str_contains($localised, 'dir="'.$expected.'"')) {
                    $problems[] = sprintf(
                        'errors/%d under locale %s does not declare dir="%s". An Arabic error message in '.
                        'a left-to-right document has its punctuation at the wrong end, which is how a '.
                        'page looks machine-translated.',
                        $status,
                        $locale,
                        $expected,
                    );
                }
            }
        }

        return $problems;
    }

    /**
     * Every connection pointed at a dead host, then rendered.
     *
     * This is the condition the 500 page exists for, so it is the condition it
     * is tested in. A port nothing listens on rather than a bad password: a
     * refused connection is what a database being down actually looks like,
     * and it fails fast instead of waiting for a timeout.
     *
     * @return list<string>
     */
    private function theySurviveABrokenDatabase(): array
    {
        $problems = [];
        $saved = config('database.connections');

        try {
            foreach (array_keys((array) $saved) as $name) {
                config(['database.connections.'.$name.'.host' => '127.0.0.1']);
                config(['database.connections.'.$name.'.port' => 1]);
                config(['database.connections.'.$name.'.database' => 'peakflow_does_not_exist']);
                DB::purge((string) $name);
            }

            foreach (self::PAGES as [$status, $title]) {
                try {
                    $html = View::make('errors.'.$status)->render();
                } catch (Throwable $e) {
                    $problems[] = sprintf(
                        'errors/%d could not render with the database down: %s. That is the incident this '.
                        'page exists for - and a view that throws inside the handler leaves Laravel to '.
                        'fall back to a bare "Server Error" string with no way back and no reference.',
                        $status,
                        $e->getMessage(),
                    );

                    continue;
                }

                if (! str_contains($html, $title)) {
                    $problems[] = sprintf('errors/%d rendered without its title while the database was down.', $status);
                }
            }
        } finally {
            config(['database.connections' => $saved]);

            foreach (array_keys((array) $saved) as $name) {
                DB::purge((string) $name);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theyRunNoQuery(): array
    {
        $problems = [];
        $queries = [];

        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        foreach (self::PAGES as [$status]) {
            $queries = [];

            try {
                View::make('errors.'.$status)->render();
            } catch (Throwable $e) {
                $problems[] = sprintf('errors/%d did not render: %s', $status, $e->getMessage());

                continue;
            }

            if ($queries !== []) {
                $problems[] = sprintf(
                    'errors/%d ran %d query/queries (%s). On the page that explains a database outage, a '.
                    'query is the thing that stops the explanation rendering.',
                    $status,
                    \count($queries),
                    implode('; ', array_slice($queries, 0, 2)),
                );
            }
        }

        return $problems;
    }

    /**
     * The structural reasons they survive, asserted in the source.
     *
     * The rule above proves they render with the database down. These assert
     * WHY, so that somebody adding `@vite` or a `session()` read to the layout
     * is stopped at the change rather than during the next incident - a build
     * manifest is present in this environment, so a `@vite` added here would
     * pass the render test and fail in the deploy that half-finished.
     *
     * @return list<string>
     */
    private function theyDependOnNothing(): array
    {
        $problems = [];
        $base = \dirname(__DIR__, 2).'/resources/views/';

        $files = ['layouts/error.blade.php'];

        foreach (self::PAGES as [$status]) {
            $files[] = 'errors/'.$status.'.blade.php';
        }

        foreach ($files as $file) {
            $path = $base.$file;

            if (! is_file($path)) {
                $problems[] = sprintf('resources/views/%s is missing.', $file);

                continue;
            }

            /*
             | Blade comments blanked first.
             |
             | The layout's own docblock explains why it uses neither `@vite`
             | nor `session()`, and the first version of this rule flagged
             | that explanation - the third time in this codebase a textual
             | rule has reported the prose describing it. Blanked rather than
             | removed, so a violation's offset still points at the right line.
             */
            $source = (string) preg_replace_callback(
                '/\{\{--.*?--\}\}/s',
                static fn (array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]),
                (string) file_get_contents($path),
            );

            $forbidden = [
                '@vite' => 'the build manifest. A deploy that failed between pushing code and building '.
                    'assets is exactly the kind of incident that produces a 500, and an error page that '.
                    'throws because the manifest is missing is an error page that does not exist when it '.
                    'is needed',
                'session(' => 'the session, whose store is the PLATFORM database - so if that is what '.
                    'broke, this read throws inside the handler that was rendering the explanation',
                'auth(' => 'the authenticated user, which needs the tenant database and a session',
                'Auth::' => 'the authenticated user, which needs the tenant database and a session',
                'currentTenant' => 'a resolved tenant, which is the thing that may have failed to resolve',
            ];

            foreach ($forbidden as $needle => $why) {
                if (str_contains($source, $needle)) {
                    $problems[] = sprintf(
                        'resources/views/%s uses `%s`, which depends on %s.',
                        $file,
                        $needle,
                        $why,
                    );
                }
            }
        }

        return $problems;
    }

    /**
     * Three unauthenticated requests, through the real pipeline.
     *
     * @return list<string>
     */
    private function theRightLogin(): array
    {
        $problems = [];

        $this->signEverybodyOut();

        /*
         | Probe routes, because the API and the authenticated application are
         | Phase 12 and Phase 11.
         |
         | Registered on the real router with the real middleware, so what is
         | under test is the exception handler's guard awareness rather than a
         | reimplementation of it.
         */
        Route::middleware('auth:sanctum')->get('api/pf015-probe', static fn (): array => ['ok' => true]);
        Route::middleware('auth')->get('ops/pf015-probe', static fn (): string => 'ok');
        Route::middleware('auth')->get('pf015-probe', static fn (): string => 'ok');

        $kernel = app(Kernel::class);

        $cases = [
            ['api/pf015-probe', 401, null, 'an API client gets a status it can act on, not a redirect to a login form it cannot render'],
            ['ops/pf015-probe', 302, '/ops/login', 'the operator console has its own login, and sending an operator to the tenant login is a dead end'],
            ['pf015-probe', 302, '/login', 'everything else is the application'],
        ];

        foreach ($cases as [$uri, $status, $location, $why]) {
            try {
                $response = $kernel->handle(Request::create('/'.$uri, 'GET'));
            } catch (Throwable $e) {
                $problems[] = sprintf('GET /%s threw %s: %s', $uri, $e::class, $e->getMessage());

                continue;
            }

            if ($response->getStatusCode() !== $status) {
                $problems[] = sprintf(
                    'GET /%s returned %d and should return %d - %s.',
                    $uri,
                    $response->getStatusCode(),
                    $status,
                    $why,
                );

                continue;
            }

            if ($location === null) {
                continue;
            }

            $actual = (string) $response->headers->get('location');

            if (! str_ends_with($actual, $location)) {
                $problems[] = sprintf(
                    'GET /%s redirected to %s and should reach %s - %s.',
                    $uri,
                    $actual,
                    $location,
                    $why,
                );
            }
        }

        return $problems;
    }

    /**
     * Forget every guard's user before asking what an unauthenticated request gets.
     *
     * Run on its own this suite passed, and inside `bin/verify all` all three
     * probe routes returned 200: an earlier suite signs a user in to test
     * step-up authentication, and the container's guards keep that user for
     * the rest of the process. A check whose result depends on which other
     * checks ran before it is not a check.
     *
     * `forgetUser()` rather than `logout()`: logout writes to the session,
     * whose store is the platform database, and this suite also runs with
     * that database deliberately unreachable.
     */
    private function signEverybodyOut(): void
    {
        /** @var array<string, mixed> $guards */
        $guards = (array) config('auth.guards');

        foreach (array_keys($guards) as $guard) {
            try {
                $instance = auth()->guard((string) $guard);

                if (method_exists($instance, 'forgetUser')) {
                    $instance->forgetUser();
                }
            } catch (Throwable) {
                // A guard that cannot be built has nobody signed in to it.
            }
        }

        if (app()->bound('session')) {
            try {
                app('session')->driver()->flush();
            } catch (Throwable) {
                // No session store, which is the state this wants anyway.
            }
        }
    }

    /** @return list<string> */
    private function apiErrorsAreProblemJson(): array
    {
        $problems = [];

        $this->signEverybodyOut();

        Route::middleware('auth:sanctum')->get('api/pf015-probe-2', static fn (): array => ['ok' => true]);

        $response = app(Kernel::class)->handle(Request::create('/api/pf015-probe-2', 'GET'));

        $contentType = (string) $response->headers->get('content-type');

        if (! str_contains($contentType, 'application/problem+json')) {
            $problems[] = sprintf(
                'An unauthenticated API request answered with Content-Type %s. RFC 9457 names its own '.
                'media type, and a client switching on it cannot tell a problem document from a payload '.
                'that happens to have a `title`.',
                $contentType,
            );
        }

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true) ?: [];

        foreach (['type', 'title', 'status'] as $member) {
            if (! isset($body[$member])) {
                $problems[] = sprintf(
                    'The problem document has no `%s`. RFC 9457 requires a `type` a client can switch on '.
                    '- a human-readable title alone means every client parses English prose.',
                    $member,
                );
            }
        }

        if (isset($body['status']) && $body['status'] !== 401) {
            $problems[] = sprintf(
                'The problem document says status %s and the response says 401. A client that trusts the '.
                'body retries the wrong thing.',
                var_export($body['status'], true),
            );
        }

        if (isset($body['type']) && ! str_contains((string) $body['type'], 'unauthenticated')) {
            $problems[] = sprintf(
                'The problem type for an unauthenticated request is %s. The type URI is the part a client '.
                'switches on, so it has to name the condition rather than restate the status.',
                (string) $body['type'],
            );
        }

        return $problems;
    }
}
