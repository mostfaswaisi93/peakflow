<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turn a naked page into a page that says why it is naked - PF-244.
 *
 * ## The symptom points at the wrong thing
 *
 * When the stylesheet does not arrive, this application does not look
 * broken in the way a missing file usually looks broken. It looks like a
 * design that fell apart: the sign-in card's four logo variants all appear
 * at once, because the css that shows one per theme never came; the
 * decorative blob is drawn at its natural size in the middle of the page,
 * because it is inline svg and needs no request at all; and the form below
 * it is unstyled html. Nothing errors, the response is 200, and the server
 * log is clean.
 *
 * So the first guess is always the design, and the design is fine. There
 * are three causes and the page cannot tell you which, which is what this
 * middleware is for.
 *
 * ## The three causes
 *
 * 1. `public/hot` is left behind. `npm run dev` writes it and deletes it on
 *    a clean stop; a closed terminal or a killed container leaves it. While
 *    it exists `@vite` emits NO stylesheet link - it expects the dev server
 *    to inject the css - so the compiled css sitting in `public/build` is
 *    never asked for.
 *
 * 2. There is no build. `public/build` is in `.gitignore`, so a fresh
 *    clone has no compiled css until `npm run build` runs. This one usually
 *    throws rather than rendering, but a stale manifest naming files that
 *    were deleted renders instead.
 *
 * 3. `APP_URL` is not the address the browser is at. Every asset url is
 *    absolute and built from it - Laravel does not derive it from the
 *    request, because a queued job rendering a mail has no request - so a
 *    mismatch sends every stylesheet, logo and favicon to a host the
 *    visitor cannot reach. This is the one that also breaks the images, and
 *    the one `curl` from the server will not reproduce.
 *
 * ## Why a banner rather than an exception
 *
 * Because the page still works. The form submits, the session is set, the
 * sign-in succeeds - it is unstyled, not broken, and refusing to render it
 * would stop a developer from carrying on while they fix the build. The
 * banner carries its own inline styles for the obvious reason.
 *
 * ## Why it is registered but inert in production
 *
 * Registered unconditionally so there is one list of middleware rather than
 * a list that differs by environment - the kind of difference that makes a
 * production-only bug impossible to reproduce. It returns before doing any
 * work when the application is in production, where the same three causes
 * are refused outright by {@see \App\Support\Config\ProductionConfiguration}
 * at boot instead.
 */
final class SayWhyThePageIsUnstyled
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (app()->isProduction() || app()->runningInConsole()) {
            return $response;
        }

        $problem = $this->problem($request);

        if ($problem === null) {
            return $response;
        }

        $content = $response->getContent();

        /*
         | Only a complete html document.
         |
         | A JSON response, a redirect, a file download and a Livewire
         | fragment all pass through here, and appending markup to any of
         | them would break the thing it is appending to. The closing body
         | tag is the cheapest test that this is a page a person is looking
         | at, and its absence is the right reason to do nothing.
         */
        if (! is_string($content) || ! str_contains($content, '</body>')) {
            return $response;
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $response->setContent(str_replace(
            '</body>',
            $this->banner($problem).'</body>',
            $content,
        ));

        return $response;
    }

    /**
     * The first cause that applies, as a headline and a remedy.
     *
     * In order, because they stack: a left-behind `hot` file hides a missing
     * build, and both hide a wrong APP_URL. Reporting the first is reporting
     * the one to fix first.
     *
     * @return array{what: string, fix: string}|null
     */
    private function problem(Request $request): ?array
    {
        if (is_file(public_path('hot'))) {
            return [
                'what' => 'public/hot exists, so every page links NO stylesheet: @vite is waiting for '.
                    'the Vite dev server to inject the css, and the compiled css in public/build is '.
                    'never requested.',
                'fix' => 'Run `npm run dev` to start that server, or delete public/hot and run '.
                    '`npm run build` to use the compiled assets.',
            ];
        }

        if (! is_file(public_path('build/manifest.json'))) {
            return [
                'what' => 'public/build/manifest.json does not exist, so there is no compiled css to '.
                    'link. public/build is in .gitignore, so a fresh clone has none until it is built.',
                'fix' => 'Run `npm install && npm run build`.',
            ];
        }

        /*
         | The origin comparison, scheme, host and port.
         |
         | Compared against the url the REQUEST arrived on rather than the
         | Host header alone, because a scheme or a port mismatch produces the
         | same unreachable asset url as a wrong hostname - and the port is
         | the one that actually happens, when APP_URL says :80 and the
         | developer is on :8000.
         */
        $configured = (string) config('app.url', '');
        $declared = $this->originOf($configured);
        $actual = $this->originOf($request->getSchemeAndHttpHost());

        if ($declared !== '' && $actual !== '' && $declared !== $actual) {
            return [
                'what' => sprintf(
                    'APP_URL is %s and you are browsing %s. Every stylesheet, logo and favicon url on '.
                    'this page is absolute and built from APP_URL, so all of them point at an address '.
                    'this browser is not at.',
                    $declared,
                    $actual,
                ),
                'fix' => sprintf('Set APP_URL=%s in .env and run `php artisan config:clear`.', $actual),
            ];
        }

        return null;
    }

    /** Scheme, host and port, with the default port dropped so :80 and nothing compare equal. */
    private function originOf(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }

        return $scheme.'://'.strtolower((string) $parts['host']).($port === null ? '' : ':'.$port);
    }

    /**
     * The banner, with every style inline.
     *
     * It is announcing that the stylesheet did not arrive, so it cannot use
     * one. The text is English only: it is addressed to whoever is running
     * the application locally, and it names files and commands rather than
     * describing them, so there is nothing in it to translate.
     *
     * @param  array{what: string, fix: string}  $problem
     */
    private function banner(array $problem): string
    {
        $style = 'position:fixed;inset-inline:0;bottom:0;z-index:2147483647;'.
            'background:#7f1d1d;color:#fff;padding:12px 16px;'.
            'font:14px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;'.
            'direction:ltr;text-align:left;box-shadow:0 -2px 12px rgba(0,0,0,.4)';

        return sprintf(
            '<div role="status" dir="ltr" style="%s">'.
            '<strong>This page has no stylesheet.</strong> %s<br><strong>Fix:</strong> %s'.
            '<br><span style="opacity:.75">Local only - this notice is never rendered in production.</span>'.
            '</div>',
            $style,
            e($problem['what']),
            e($problem['fix']),
        );
    }
}
