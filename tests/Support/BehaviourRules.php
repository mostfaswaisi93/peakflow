<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use Symfony\Component\Process\Process;

/**
 * The progressive enhancements, in a browser that actually runs them - PF-206, PF-055.
 *
 * ## Why this suite had to exist
 *
 * Because the keyboard model did not run, and nothing said so.
 *
 * `resources/views/partials/enhancements.blade.php` inlines the whole
 * behaviour layer - the grid's roving focus, type-to-edit, Enter, Tab, the
 * selection, the copy, the paste preview, the currency field's digit
 * normalisation - in a `<script>` tag. The product's `script-src` is a nonce
 * and nothing else: no `'self'`, no `'unsafe-inline'`, no hashes. The tag
 * carried no nonce, so **the browser refused it on every page that rendered
 * a grid or a currency field**, which is every list in the product.
 *
 * Nothing caught it, and the reasons are worth writing down because they are
 * the shape of this whole class of defect:
 *
 *   - `bin/verify grid` compares the markup against what the script reads.
 *     The markup was right. The hooks were all there.
 *   - The suites that cover the keyboard model read the SOURCE of the
 *     script - `str_contains($source, "case 'Enter'")` - which is true of a
 *     file that never executes.
 *   - A page whose arrow keys do nothing still renders, still scrolls,
 *     still submits and still passes every accessibility check that reads
 *     the DOM. The only symptom is that nothing happens, and nothing
 *     happening is indistinguishable from nobody having pressed the key.
 *
 * So this suite does the one thing the others cannot: it presses the keys.
 *
 * ## It drives the manual journal screen
 *
 * PF-055's last criterion is a keyboard contract on a grid somebody types an
 * entry into - type to edit, `Enter` commits and moves down, `Tab` commits
 * and moves right - and the manual journal is the screen that contract is
 * about. A read-only list would exercise the movement and not the editing.
 *
 * ## Direction is part of the contract
 *
 * The arrow keys are read from the computed inline direction, so in Arabic
 * `ArrowLeft` advances and `ArrowRight` retreats. A check written in English
 * and run in Arabic would report the correct behaviour as broken, which is
 * how a direction-aware keyboard model comes to be "fixed" into a
 * direction-blind one.
 *
 * ## Its own target
 *
 * It starts a server and a browser. `bin/verify behaviour`, never part of
 * `all` - the same bargain `viewport` makes.
 */
final class BehaviourRules
{
    private const PORT = 8133;

    private ?Process $server = null;

    /**
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        $results = [
            'the behaviour layer runs in a browser at all' => [],
            'a printable character starts editing the cell' => [],
            'Enter commits and moves down the column' => [],
            'Tab commits and moves along the row, by reading order' => [],
            'the arrow keys move by reading order, not by screen order' => [],
            'the imbalance is the server\'s answer, and it arrives as you type' => [],
            'a manual journal posts from the keyboard alone' => [],
        ];

        $node = $this->nodeIsAvailable();

        if ($node !== null) {
            $results['the behaviour layer runs in a browser at all'] = [$node];

            return $results;
        }

        $started = $this->start();

        if ($started !== null) {
            $results['the behaviour layer runs in a browser at all'] = [$started];

            return $results;
        }

        try {
            $measured = $this->drive();
        } catch (\Throwable $e) {
            $results['the behaviour layer runs in a browser at all'] = [
                'the browser run failed: '.$e->getMessage(),
            ];

            return $results;
        } finally {
            $this->stop();
        }

        if (isset($measured['error'])) {
            $results['the behaviour layer runs in a browser at all'] = [(string) $measured['error']];

            return $results;
        }

        return [
            'the behaviour layer runs in a browser at all' => $this->layerRuns($measured),
            'a printable character starts editing the cell' => $this->typeToEdit($measured),
            'Enter commits and moves down the column' => $this->enterMovesDown($measured),
            'Tab commits and moves along the row, by reading order' => $this->tabMovesAlong($measured),
            'the arrow keys move by reading order, not by screen order' => $this->arrowsFollowDirection($measured),
            'the imbalance is the server\'s answer, and it arrives as you type' => $this->theImbalance($measured),
            'a manual journal posts from the keyboard alone' => $this->itPosts($measured),
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return list<string>
     */
    private function layerRuns(array $m): array
    {
        $problems = [];

        if (($m['pfUi'] ?? '') !== 'object') {
            $problems[] = 'window.pfUi is not defined, so the inlined behaviour layer did not execute. '.
                'The most likely cause is the one that hid for months: the script carries no CSP nonce '.
                'and `script-src` is a nonce and nothing else, so the browser refuses it - on every '.
                'page with a grid or a currency field, silently';
        }

        foreach ((array) ($m['cspBlocked'] ?? []) as $message) {
            $problems[] = 'the browser refused a script on the content security policy: '.(string) $message;
        }

        if (($m['grids'] ?? 0) < 1) {
            $problems[] = 'the manual journal screen rendered no grid, so nothing below this measured '.
                'anything';
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $m
     * @return list<string>
     */
    private function typeToEdit(array $m): array
    {
        $after = (array) ($m['typeToEdit'] ?? []);

        if (($after['tag'] ?? '') !== 'INPUT') {
            return [sprintf(
                'a printable character on a focused cell left the focus on a %s. Without type-to-edit '.
                'the keyboard model is "arrow to the cell, then find the key that begins editing", and '.
                'the figure somebody has already started typing is lost',
                (string) ($after['tag'] ?? 'nothing'),
            )];
        }

        if (($after['value'] ?? '') !== '5') {
            return [sprintf(
                'editing started and the character that started it is not in the field - it holds "%s". '.
                'A model that opens the input and drops the keystroke is worse than one that does not '.
                'open it: the person watches the first digit of every figure disappear',
                (string) ($after['value'] ?? ''),
            )];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return list<string>
     */
    private function enterMovesDown(array $m): array
    {
        $after = (array) ($m['enterLandedOn'] ?? []);

        /*
         | Enter lands on the CELL below, not inside its input.
         |
         | That is the model's own choice and it is the right one: Enter
         | commits and moves, and arriving in edit mode on the next row
         | would mean the next keystroke replaces whatever is there. Tab is
         | the one that moves INTO the next field, because moving along a
         | row is moving through a line somebody is still typing.
         */
        if (($after['cell'] ?? null) !== 'account_code' || ($after['row'] ?? null) !== 1) {
            return [sprintf(
                'Enter from the account cell of row 1 landed on the "%s" cell of row %s, and should '.
                'land on the same column of row 2. Down the column is how an entry is typed - '.
                'account, account, account - and a model that moves right instead makes somebody '.
                're-aim after every line',
                (string) ($after['cell'] ?? 'nothing'),
                (string) (($after['row'] ?? null) === null ? 'nothing' : (int) $after['row'] + 1),
            )];
        }

        if (($m['enterCommitted'] ?? '') !== '1.1.1.1') {
            return [sprintf(
                'Enter moved and the value it was editing is now "%s". A move that loses the cell it '.
                'left is a model that silently discards the last thing typed',
                (string) ($m['enterCommitted'] ?? ''),
            )];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return list<string>
     */
    private function tabMovesAlong(array $m): array
    {
        $after = (string) ($m['tabLandedOn'] ?? '');

        if ($after !== 'lines[0][amount]') {
            return [sprintf(
                'Tab from the account cell landed on "%s" and should land on the amount cell of the '.
                'same row. Across the row is how a line is typed, and in Arabic "across" is leftwards '.
                '- which is the whole reason the model reads the computed direction',
                $after,
            )];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return list<string>
     */
    private function arrowsFollowDirection(array $m): array
    {
        $problems = [];

        if ((string) ($m['arrowForward'] ?? '') !== 'amount') {
            $problems[] = sprintf(
                'in Arabic, ArrowLeft from the account cell reached "%s" and should reach the next '.
                'column. The next column is to the LEFT in a right-to-left reading order, and a grid '.
                'that moves by screen order instead moves backwards for half the product\'s users',
                (string) ($m['arrowForward'] ?? 'nothing'),
            );
        }

        if ((string) ($m['arrowBack'] ?? '') !== 'account_code') {
            $problems[] = sprintf(
                'ArrowRight did not come back to the account cell; it reached "%s"',
                (string) ($m['arrowBack'] ?? 'nothing'),
            );
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $m
     * @return list<string>
     */
    private function theImbalance(array $m): array
    {
        $problems = [];
        $short = (string) ($m['imbalanceShort'] ?? '');
        $balanced = (string) ($m['imbalanceBalanced'] ?? '');

        /*
         | The figure, not the wording.
         |
         | The sentence is translated and the test is not a translation
         | check: what matters is that the server's arithmetic reached the
         | page, and 90 is the arithmetic. A client-side sum of the two
         | fields would produce it too - which is why the check below reads
         | the response, not only the page.
         */
        if (! str_contains($short, '90.00')) {
            $problems[] = sprintf(
                'an entry out by 90 reported "%s". PF-055 asks for the imbalance as a figure with a '.
                'side, because 90 is a transposition, twice the amount is a sign and a round figure '.
                'is a missing line',
                $short,
            );
        }

        if (! str_contains($m['imbalanceCheckedBy'] ?? '', 'journals/check')) {
            $problems[] = 'the page did not ask the server for the imbalance. A browser summing the '.
                'amount column is a second implementation of the thing this product is for, and it '.
                'would agree on the easy rows and disagree on the ones somebody is about to post';
        }

        if ($balanced === $short) {
            $problems[] = 'the figure did not change when the entry was brought into balance, so the '.
                'page is showing one answer whatever is typed';
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $m
     * @return list<string>
     */
    private function itPosts(array $m): array
    {
        $problems = [];

        if (($m['posted'] ?? false) !== true) {
            $problems[] = sprintf(
                'a balanced entry typed with the keyboard did not post: %s',
                (string) ($m['postMessage'] ?? 'nothing was reported'),
            );
        }

        if (($m['postedNumber'] ?? '') === '') {
            $problems[] = 'the screen did not name the entry it posted. "Posted" with no number is a '.
                'confirmation nobody can follow up';
        }

        return $problems;
    }

    /** @return array<string, mixed> */
    private function drive(): array
    {
        $script = sys_get_temp_dir().'/peakflow-behaviour-'.bin2hex(random_bytes(4)).'.cjs';
        file_put_contents($script, $this->driver());

        $process = new Process(['node', $script], $this->basePath(), ['PF_PORT' => (string) self::PORT]);
        $process->setTimeout(600);
        $process->run();

        @unlink($script);

        if (! $process->isSuccessful()) {
            return ['error' => 'the browser run failed: '.trim($process->getErrorOutput() ?: $process->getOutput())];
        }

        $decoded = json_decode($process->getOutput(), true);

        return \is_array($decoded) ? $decoded : ['error' => 'the browser run returned nothing to read'];
    }

    private function nodeIsAvailable(): ?string
    {
        $node = new Process(['node', '-e', "require('playwright')"], $this->basePath());
        $node->run();

        return $node->isSuccessful()
            ? null
            : 'node with playwright is not available here, so nothing was pressed: '.trim($node->getErrorOutput());
    }

    private function start(): ?string
    {
        $tenant = Tenant::query()->where('slug', 'showcase')->first();

        if ($tenant === null) {
            return 'there is no `showcase` workspace to drive. Run DemoTenantSeeder.';
        }

        $this->server = new Process(
            ['php', 'artisan', 'serve', '--host=127.0.0.1', '--port='.self::PORT],
            $this->basePath(),
        );

        $this->server->setTimeout(null);
        $this->server->start();

        for ($attempt = 0; $attempt < 60; $attempt++) {
            if (@file_get_contents('http://127.0.0.1:'.self::PORT.'/up') !== false) {
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

    private function driver(): string
    {
        return <<<'JS'
/*
 | Presses the keys on the manual journal screen and prints what happened.
 |
 | Arabic, because the arrow keys are direction-aware and the Arabic case is
 | the one a check written in English gets wrong.
 */
const { chromium } = require('playwright');

const BASE = `http://127.0.0.1:${process.env.PF_PORT}`;
const out = { cspBlocked: [] };

const focused = (page) => page.evaluate(() => {
    const el = document.activeElement;

    return {
        tag: el ? el.tagName : null,
        name: el && 'name' in el ? el.name : null,
        value: el && 'value' in el ? el.value : null,
        cell: el && el.closest ? (el.closest('[data-pf-cell]') || {}).getAttribute?.call(
            el.closest('[data-pf-cell]'), 'data-pf-cell',
        ) ?? null : null,
    };
});

(async () => {
  const browser = await chromium.launch();

  try {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 950 }, locale: 'ar' });
    const page = await ctx.newPage();

    page.on('console', (message) => {
      if (message.type() === 'error' && /Content Security Policy/.test(message.text())) {
        out.cspBlocked.push(message.text().slice(0, 160));
      }
    });

    const requests = [];
    page.on('request', (request) => requests.push(request.url()));

    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('#workspace', 'showcase');
    await page.fill('input[type="email"]', 'owner@showcase.test');
    await page.fill('input[type="password"]', 'peakflow');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');

    if (page.url().includes('/login')) {
      out.error = 'the showcase owner could not sign in, so nothing was driven';
      console.log(JSON.stringify(out));

      return;
    }

    await page.goto(`${BASE}/ledger/journals/new`, { waitUntil: 'load' });

    out.pfUi = await page.evaluate(() => typeof window.pfUi);
    out.grids = await page.locator('[data-pf-grid]').count();

    const codes = await page.locator('#journal-accounts option').evaluateAll(
      (nodes) => nodes.map((node) => node.value),
    );

    /*
     | Two accounts the SERVER accepts, found by asking it.
     |
     | Hard-coding a code would make this a check about the showcase chart,
     | and taking the first two from the datalist is not enough: the list is
     | every postable account, and `validate()` refuses a control account
     | with an explanation - which is PF-055's own criterion and exactly
     | what the first version of this driver walked into. So each candidate
     | is offered to the check endpoint, and the ones it has nothing to say
     | about are the ones this entry is typed with.
     */
    const usable = [];

    for (const code of codes.slice(0, 40)) {
      const verdict = await page.evaluate(async (candidate) => {
        const form = document.querySelector('[data-pf-journal]');
        const body = new FormData();
        body.set('_token', form.querySelector('input[name="_token"]').value);
        body.set('lines[0][account_code]', candidate);
        body.set('lines[0][amount]', '1.00');

        const response = await fetch(form.getAttribute('data-pf-journal-check'), {
          method: 'POST',
          body,
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        });

        return response.ok ? response.json() : null;
      }, code);

      if (verdict && Array.isArray(verdict.problems) && verdict.problems.length === 0) {
        usable.push(code);
      }

      if (usable.length === 2) {
        break;
      }
    }

    out.usable = usable;

    if (usable.length < 2) {
      out.error = 'fewer than two accounts in this chart accept a manual posting, so nothing could '
        + 'be typed. Every candidate was refused: ' + JSON.stringify(codes.slice(0, 10));
      console.log(JSON.stringify(out));

      return;
    }

    const [debit, credit] = usable;

    const firstCell = page.locator('[data-pf-row]').first().locator('[data-pf-cell]').first();

    // --- type-to-edit -------------------------------------------------
    await firstCell.focus();
    await page.keyboard.press('5');
    out.typeToEdit = await focused(page);

    // --- the arrow keys, by reading order -----------------------------
    await page.keyboard.press('Escape');
    await firstCell.focus();
    await page.keyboard.press('ArrowLeft');
    out.arrowForward = (await focused(page)).cell;
    await page.keyboard.press('ArrowRight');
    out.arrowBack = (await focused(page)).cell;

    // --- Tab commits and moves along the row --------------------------
    await page.locator('input[name="lines[0][account_code]"]').fill('');
    await firstCell.focus();
    await page.keyboard.type(debit);
    await page.keyboard.press('Tab');
    out.tabLandedOn = (await focused(page)).name;

    await page.keyboard.type('1200');

    // --- Enter commits and moves down ---------------------------------
    await firstCell.focus();
    await page.keyboard.press('Enter');          // starts editing
    await page.keyboard.press('Enter');          // commits, moves down
    out.enterLandedOn = await page.evaluate(() => {
        const cell = document.activeElement.closest
            ? document.activeElement.closest('[data-pf-cell]')
            : null;

        if (!cell) {
            return null;
        }

        const row = cell.closest('[data-pf-row]');
        const rows = Array.from(document.querySelectorAll('[data-pf-row]'));

        return { cell: cell.getAttribute('data-pf-cell'), row: rows.indexOf(row) };
    });
    out.enterCommitted = await page.inputValue('input[name="lines[0][account_code]"]');

    // --- the imbalance, from the server -------------------------------
    await page.fill('input[name="lines[1][account_code]"]', credit);
    const amount = page.locator('input[name="lines[1][amount]"]');
    await amount.fill('-1110');
    await amount.dispatchEvent('input');
    await page.waitForTimeout(900);
    out.imbalanceShort = (await page.locator('[data-pf-journal-imbalance]').textContent() || '').trim();

    await amount.fill('-1200');
    await amount.dispatchEvent('input');
    await page.waitForTimeout(900);
    out.imbalanceBalanced = (await page.locator('[data-pf-journal-imbalance]').textContent() || '').trim();

    out.imbalanceCheckedBy = requests.filter((url) => url.includes('journals/check')).slice(-1)[0] || '';

    // --- and it posts -------------------------------------------------
    await page.click('button[value="post"]');
    await page.waitForLoadState('domcontentloaded');

    /*
     | The page's OWN banners, by their hooks.
     |
     | `[role="status"]` matches four things on this page - the grid's
     | result summary, the grid's keyboard status line, the navigation
     | loader and the posting banner - and `.first()` is whichever the
     | document happens to put first. Read that way, a successful post
     | reported an empty string and this check called it a failure to post.
     */
    const status = await page.locator('[data-pf-journal-posted]').first().textContent().catch(() => '');
    const alert = await page.locator('[data-pf-journal-refusal]').first().textContent().catch(() => '');
    out.postMessage = ((status || '') + ' ' + (alert || '')).replace(/\s+/g, ' ').trim().slice(0, 300);
    out.posted = (alert || '').trim() === '' && /\d/.test(status || '');
    out.postedNumber = ((status || '').match(/[A-Z0-9][A-Z0-9\/-]{3,}/) || [''])[0];
  } catch (error) {
    out.error = String(error).slice(0, 600);
  } finally {
    console.log(JSON.stringify(out));
    await browser.close();
  }
})();
JS;
    }
}
