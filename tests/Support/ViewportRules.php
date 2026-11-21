<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * The product, measured in a browser at the widths people use - PF-276, PF-277.
 *
 * ## Why this suite exists separately
 *
 * Every other check here reads source or asks the database. These three
 * questions cannot be answered that way:
 *
 *   - does this page scroll sideways at 320 CSS pixels (WCAG 1.4.10)
 *   - is this control 44 CSS pixels in both directions where a finger is
 *     the pointer
 *   - is the gap between these two sections the one the scale declares
 *
 * All three are about the **computed** box. A page that reaches the right
 * spacing through three overrides passes a class-name check and breaks on
 * the next change, and a touch target sized by a class its parent shrinks
 * reads as 44px in the markup and 31px on the glass.
 *
 * ## It drives the real application
 *
 * `php artisan serve` on a port of its own, signed in as the showcase
 * owner, visiting the pages a person actually uses. A fixture page would
 * measure a fixture.
 *
 * ## And it is its own target
 *
 * It starts a server and a browser, so it is slow and it writes a session.
 * `bin/verify viewport`, never part of `all`.
 */
final class ViewportRules
{
    /** WCAG 1.4.10: content reflows at 320 CSS pixels without a second scrollbar. */
    private const REFLOW_WIDTH = 320;

    /** WCAG 2.5.8 is 24; the product commits to the 2.5.5 figure where a finger is the pointer. */
    private const TOUCH_TARGET = 44;

    /** The widths the task names, which are a phone, a tablet, a small laptop and a desktop. */
    private const WIDTHS = [320, 768, 1024, 1440];

    private const PORT = 8129;

    private ?Process $server = null;

    /**
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        $results = [
            'the application boots for a browser to drive' => [],
            'no page scrolls sideways at 320 pixels, in either direction' => [],
            'a touch target is 44 by 44, measured on the glass' => [],
            'the gaps between sections are steps of the scale' => [],
        ];

        $node = $this->nodeIsAvailable();

        if ($node !== null) {
            $results['the application boots for a browser to drive'] = [$node];

            return $results;
        }

        $started = $this->start();

        if ($started !== null) {
            $results['the application boots for a browser to drive'] = [$started];

            return $results;
        }

        try {
            $measured = $this->measure();
        } catch (\Throwable $e) {
            $results['the application boots for a browser to drive'] = [
                'the browser run failed: '.$e->getMessage(),
            ];

            return $results;
        } finally {
            $this->stop();
        }

        if (isset($measured['error'])) {
            $results['the application boots for a browser to drive'] = [(string) $measured['error']];

            return $results;
        }

        $results['the application boots for a browser to drive'] = $this->everyPageLoaded($measured);
        $results['no page scrolls sideways at 320 pixels, in either direction'] = $this->reflow($measured);
        $results['a touch target is 44 by 44, measured on the glass'] = $this->touchTargets($measured);
        $results['the gaps between sections are steps of the scale'] = $this->spacing($measured);

        return $results;
    }

    /**
     * Every page in the list actually loaded.
     *
     * Without this a wrong path is a screen that is never measured and
     * never missed: the suite reports green and the check that mattered
     * ran on three pages instead of four.
     *
     * @param  array<string, mixed>  $measured
     * @return list<string>
     */
    private function everyPageLoaded(array $measured): array
    {
        $problems = [];

        foreach ((array) ($measured['unreachable'] ?? []) as $page) {
            $problems[] = \sprintf(
                '%s answered %d in %s, so it was not measured. A screen the suite skips is a screen the '.
                'suite reports nothing about, in green',
                (string) $page['path'],
                (int) $page['status'],
                (string) $page['direction'],
            );
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $measured
     * @return list<string>
     */
    private function reflow(array $measured): array
    {
        $problems = [];
        $seen = 0;

        foreach ((array) ($measured['pages'] ?? []) as $page) {
            foreach ((array) ($page['widths'] ?? []) as $at) {
                if ((int) $at['width'] !== self::REFLOW_WIDTH) {
                    continue;
                }

                $seen++;

                if ((bool) $at['scrollsSideways'] !== true) {
                    continue;
                }

                $problems[] = \sprintf(
                    '%s (%s) scrolls sideways at %dpx: the content is %dpx wide in a %dpx viewport, and '.
                    '%s is what overflows. Reflow is WCAG 1.4.10, and the failure is not that the page '.
                    'breaks - it is that it works while costing four horizontal scrolls, and the person '.
                    'stops using it rather than complaining',
                    (string) $page['path'],
                    (string) $at['direction'],
                    self::REFLOW_WIDTH,
                    (int) $at['scrollWidth'],
                    (int) $at['width'],
                    (string) ($at['widest'] ?? 'nothing identifiable'),
                );
            }
        }

        if ($seen === 0) {
            return ['no page was measured at 320px, so this check asserted nothing'];
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $measured
     * @return list<string>
     */
    private function touchTargets(array $measured): array
    {
        $problems = [];
        $measuredCount = 0;

        foreach ((array) ($measured['pages'] ?? []) as $page) {
            foreach ((array) ($page['widths'] ?? []) as $at) {
                foreach ((array) ($at['smallTargets'] ?? []) as $target) {
                    $measuredCount++;

                    $problems[] = \sprintf(
                        '%s at %dpx: %s is %dx%d, under %dx%d. Measured on the glass rather than from '.
                        'its class, because a target sized by a class its parent shrinks reads as %d in '.
                        'the markup and lands under a finger at %d',
                        (string) $page['path'],
                        (int) $at['width'],
                        (string) $target['what'],
                        (int) $target['width'],
                        (int) $target['height'],
                        self::TOUCH_TARGET,
                        self::TOUCH_TARGET,
                        self::TOUCH_TARGET,
                        min((int) $target['width'], (int) $target['height']),
                    );
                }
            }
        }

        if (($measured['targetsSeen'] ?? 0) === 0) {
            return ['no interactive control was found on any page, so this check asserted nothing'];
        }

        return array_slice($problems, 0, 25);
    }

    /**
     * @param  array<string, mixed>  $measured
     * @return list<string>
     */
    private function spacing(array $measured): array
    {
        $problems = [];

        /*
         | The declared rhythm, not merely the scale - PF-277.
         |
         | Being on the scale and agreeing with each other are different
         | properties, and only the second makes a product look like one
         | product. Before this was tightened, seven screens had gaps of
         | 32, 24, 16, 12, 8 and 4 - every one a step of the scale, every
         | one passing a check that only asked about the scale.
         |
         |   0   the first thing on the page, and anything with no box
         |   8   a note that belongs to the thing above it (`.flow-tight`)
         |  24   between sections (`--section-gap`)
         |  32   under the page header (`--page-header-gap`)
         |
         | A fifth value is a page setting its own rhythm, which is the
         | thing the rhythm was moved into one place to stop.
         */
        $steps = [0.0, 8.0, 24.0, 32.0];

        foreach ((array) ($measured['pages'] ?? []) as $page) {
            foreach ((array) ($page['widths'] ?? []) as $at) {
                foreach ((array) ($at['gaps'] ?? []) as $gap) {
                    $pixels = (float) $gap['pixels'];

                    foreach ($steps as $step) {
                        if (abs($pixels - $step) < 0.51) {
                            continue 2;
                        }
                    }

                    $problems[] = \sprintf(
                        '%s at %dpx: the gap above %s computes to %.1fpx, and the page rhythm is 0, 8, '.
                        '24 or 32. It is probably an `mt-` on a direct child of <main>, which wins over '.
                        'the rhythm because a Tailwind utility is in a later cascade layer - so the '.
                        'rhythm is in one place and this page is in another',
                        (string) $page['path'],
                        (int) $at['width'],
                        (string) $gap['what'],
                        $pixels,
                    );
                }
            }
        }

        if (($measured['gapsSeen'] ?? 0) === 0) {
            return ['no section gap was measured, so this check asserted nothing'];
        }

        return array_slice($problems, 0, 25);
    }

    /** @return array<string, mixed> */
    private function measure(): array
    {
        $script = $this->writeDriver();

        $process = new Process(['node', $script], $this->basePath(), [
            'PF_PORT' => (string) self::PORT,
            'PF_WIDTHS' => implode(',', self::WIDTHS),
            'PF_TOUCH' => (string) self::TOUCH_TARGET,
        ]);

        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            return ['error' => 'the browser run failed: '.trim($process->getErrorOutput() ?: $process->getOutput())];
        }

        $decoded = json_decode($process->getOutput(), true);

        return \is_array($decoded) ? $decoded : ['error' => 'the browser run returned no measurements'];
    }

    private function nodeIsAvailable(): ?string
    {
        $node = new Process(['node', '-e', "require('playwright')"], $this->basePath());
        $node->run();

        return $node->isSuccessful()
            ? null
            : 'node with playwright is not available here, so nothing was measured: '.trim($node->getErrorOutput());
    }

    private function start(): ?string
    {
        $tenant = Tenant::query()->where('slug', 'showcase')->first();

        if ($tenant === null) {
            return 'there is no `showcase` workspace to measure. Run DemoTenantSeeder.';
        }

        $this->server = new Process(
            ['php', 'artisan', 'serve', '--host=127.0.0.1', '--port='.self::PORT],
            $this->basePath(),
        );

        $this->server->setTimeout(null);
        $this->server->start();

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $probe = @file_get_contents('http://127.0.0.1:'.self::PORT.'/up');

            if ($probe !== false) {
                return null;
            }

            usleep(250_000);
        }

        $this->stop();

        return 'the application did not answer on port '.self::PORT.' within 15 seconds';
    }

    private function stop(): void
    {
        $this->server?->stop(5);
        $this->server = null;
    }

    private function basePath(): string
    {
        return \dirname(__DIR__, 2);
    }

    private function writeDriver(): string
    {
        $path = sys_get_temp_dir().'/peakflow-viewport-'.bin2hex(random_bytes(4)).'.cjs';
        file_put_contents($path, $this->driver());

        return $path;
    }

    private function driver(): string
    {
        return <<<'JS'
/*
 | Drives the real application and prints measurements as JSON.
 |
 | Everything here reads the COMPUTED box. Nothing reads a class name: the
 | whole reason this runs in a browser is that a class and a rendered box
 | are different claims.
 */
const { chromium } = require('playwright');

const PORT = process.env.PF_PORT;
const WIDTHS = (process.env.PF_WIDTHS || '320').split(',').map(Number);
const TOUCH = Number(process.env.PF_TOUCH || 44);
const BASE = `http://127.0.0.1:${PORT}`;

const PAGES = ['/overview', '/sales/invoices', '/purchasing/bills', '/banking/accounts',
               '/ledger/trial-balance', '/ledger/financial-position', '/masters/parties',
               '/masters/currencies', '/settlement/payment-runs', '/settlement/statements',
               /*
                | The one screen in the product that is TYPED into - PF-055.
                |
                | Every other page here is read. A grid of editable cells at
                | 320 pixels is the hardest case the reflow rule has: four
                | inputs in a row, each with a minimum width, inside a table
                | that may scroll but must not take the page with it.
                */
               '/ledger/journals/new'];

(async () => {
  const browser = await chromium.launch();
  const out = { pages: [], targetsSeen: 0, gapsSeen: 0, unreachable: [] };

  try {
    for (const direction of ['rtl', 'ltr']) {
      const ctx = await browser.newContext({
        viewport: { width: 1280, height: 900 },
        locale: direction === 'rtl' ? 'ar' : 'en',
      });
      const page = await ctx.newPage();

      await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
      await page.fill('#workspace', 'showcase');
      await page.fill('input[type="email"]', 'owner@showcase.test');
      await page.fill('input[type="password"]', 'peakflow');
      await page.click('button[type="submit"]');
      await page.waitForLoadState('domcontentloaded');

      if (page.url().includes('/login')) {
        console.error('could not sign in as the showcase owner');
        process.exit(1);
      }

      for (const path of PAGES) {
        const record = { path: `${path} (${direction})`, widths: [] };

        for (const width of WIDTHS) {
          await page.setViewportSize({ width, height: 900 });
          const response = await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });

          /*
           | A page that does not load is a measurement that did not happen,
           | and skipping it silently is how a suite reports four screens as
           | green while one of them is a 404.
           |
           | That is not hypothetical: this list carried `/purchases/bills`
           | for its first few runs. Every check passed and that screen was
           | never measured - found when a bite on another page failed to
           | bite, because the page it was on had never been visited either.
           */
          if (response && response.status() >= 400) {
            out.unreachable.push({ path, status: response.status(), direction });
            continue;
          }

          await page.waitForTimeout(150);

          const at = await page.evaluate(({ touch, width }) => {
            const doc = document.documentElement;
            const scrollWidth = Math.ceil(doc.scrollWidth);

            /*
             | What actually pushes the page out, which is not the widest
             | element.
             |
             | The widest element on a ledger page is a 951px table - and it
             | is scrolled correctly inside its own container, which is why
             | it is 951px. Naming it sends somebody to fix a table that is
             | behaving. The culprit is an element that extends past the
             | viewport edge AND has no scrolling ancestor to contain it.
             */
            let widest = null;
            if (scrollWidth > width + 1) {
              const contained = (el) => {
                /*
                 | A collapsed <details> is the other kind of containment.
                 |
                 | Its contents still have boxes - a header menu measures
                 | 256px and 240px past the edge while closed - and none of
                 | it is on screen or reachable. This check reported one of
                 | those as the cause of a 2px overflow, which sent me to
                 | reposition a menu that was never visible.
                 */
                if (el.closest('details:not([open])')) return true;

                for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
                  const ox = getComputedStyle(p).overflowX;
                  if (ox === 'auto' || ox === 'scroll' || ox === 'hidden') return true;
                }
                return false;
              };

              let worst = 0;
              for (const el of document.querySelectorAll('body *')) {
                const box = el.getBoundingClientRect();
                const past = Math.max(box.right - width, -box.left);
                if (past > worst && past > 1 && !contained(el)) {
                  worst = past;
                  widest = el.tagName.toLowerCase()
                    + (el.id ? '#' + el.id : '')
                    + (el.className && typeof el.className === 'string'
                        ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.')
                        : '')
                    + ` (${Math.round(past)}px past the edge)`;
                }
              }

              if (widest === null) {
                widest = 'nothing outside a scroller - the overflow is '
                  + (scrollWidth - width) + 'px and every candidate is contained';
              }
            }

            // Touch targets, only where a finger is the pointer.
            const smallTargets = [];
            let targetsSeen = 0;
            if (width <= 768) {
              const controls = document.querySelectorAll(
                'a[href], button, input:not([type=hidden]), select, textarea, summary, [role=button]'
              );
              for (const el of controls) {
                const box = el.getBoundingClientRect();
                if (box.width === 0 || box.height === 0) continue;       // not rendered
                if (el.closest('[hidden]')) continue;

                /*
                 | Only what a finger can actually reach.
                 |
                 | A collapsed <details> still lays its contents out, a skip
                 | link lives at -9999px until it is focused, and a
                 | `visibility: hidden` subtree has boxes. All three have
                 | measurable rectangles and none of them is a touch target,
                 | so reporting them is how a check becomes a list people
                 | scroll past.
                 */
                const cs = getComputedStyle(el);
                const closed = el.closest('details:not([open])');
                if (closed && closed.querySelector('summary') !== el && !closed.contains(el.closest('summary'))) continue;
                if (box.right <= 0 || box.bottom <= 0 || box.left >= window.innerWidth) continue;
                if (cs.visibility === 'hidden' || cs.display === 'none') continue;
                if (el.closest('[aria-hidden="true"]')) continue;

                /*
                 | WCAG 2.5.8's "inline" exception, applied rather than
                 | argued with.
                 |
                 | A link inside a sentence or a block of text is exempt,
                 | because its size is set by the text it sits in and
                 | enlarging it would break the line. A breadcrumb is a row
                 | of links in a line of text and is exactly that case.
                 |
                 | Matched on the computed display rather than on the tag, so
                 | a link made into a block or a flex item - which a button
                 | usually is - is still measured.
                 */
                if (el.tagName === 'A' && (cs.display === 'inline' || cs.display === 'contents')) continue;

                targetsSeen++;
                if (Math.round(box.width) < touch || Math.round(box.height) < touch) {
                  const text = (el.textContent || '').trim().slice(0, 28);
                  smallTargets.push({
                    what: el.tagName.toLowerCase() + (text ? ` "${text}"` : '')
                      + (el.id ? '#' + el.id : ''),
                    width: Math.round(box.width),
                    height: Math.round(box.height),
                  });
                }
              }
            }

            // The gap above each direct child of <main> - the section rhythm.
            const gaps = [];
            const main = document.getElementById('content');
            if (main) {
              for (const child of main.children) {
                const cs = getComputedStyle(child);
                const pixels = parseFloat(cs.marginBlockStart || '0');
                if (!Number.isFinite(pixels)) continue;
                gaps.push({
                  what: child.tagName.toLowerCase()
                    + (child.className && typeof child.className === 'string'
                        ? '.' + child.className.trim().split(/\s+/)[0]
                        : ''),
                  pixels,
                });
              }
            }

            return {
              width,
              direction: document.documentElement.getAttribute('dir') || 'ltr',
              scrollWidth,
              scrollsSideways: scrollWidth > width + 1,
              widest,
              smallTargets,
              targetsSeen,
              gaps,
            };
          }, { touch: TOUCH, width });

          out.targetsSeen += at.targetsSeen;
          out.gapsSeen += at.gaps.length;
          delete at.targetsSeen;
          record.widths.push(at);
        }

        out.pages.push(record);
      }

      /*
       | The loader's frame rate is NOT measured here, and the reason is
       | worth keeping - PF-279.
       |
       | A first version counted requestAnimationFrame ticks over a second
       | with the CPU throttled 6x, and reported 59.9fps. The bite - a
       | full-screen blur and hue-rotate on the overlay, which is about as
       | expensive as a page animation gets - did not move the number at
       | all.
       |
       | It does not move because rAF in headless Chromium is scheduled on
       | a timer rather than by the compositor: an EMPTY page throttled 6x
       | also reports 62fps. So the measurement was of the scheduler, not
       | of the paint, and a check built on it would have been green
       | whatever the loader did.
       |
       | The throttling itself works - an 8-million-iteration loop goes
       | from 16ms to 102ms - so the gap is in what rAF reports, not in the
       | emulation. Counting real frames needs the compositor's own
       | numbers, which is a headed browser or a screencast, and that is
       | the work this criterion is still waiting on.
       */

      await ctx.close();
    }
  } finally {
    await browser.close();
  }

  process.stdout.write(JSON.stringify(out));
})().catch((e) => { console.error(e.message); process.exit(1); });
JS;
    }
}
