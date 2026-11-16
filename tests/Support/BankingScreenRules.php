<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Tenant\Banking\Parsers\CsvSniffer;
use App\Tenant\Banking\Parsers\CsvStatementReader;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

/**
 * The statement import screen - PF-154.
 *
 * ## Why this is checked by rendering rather than by looking
 *
 * "Keyboard-operable with a clear error summary" is a claim, and a claim
 * nobody checks is a claim that stops being true on the next change. The
 * things that make a form usable without a mouse are all structural - a
 * label bound to its control, an error summary that links to the field it
 * names, no positive `tabindex` reordering the page - so they can be read
 * out of the rendered HTML.
 *
 * What this cannot check is whether the screen is pleasant. That needs
 * somebody to use it. What it can check is that it is not unusable, which is
 * the part that regresses silently.
 *
 * ## The preview is the control, not the decoration
 *
 * The whole import flow exists so that a CSV's decimal separator is
 * confirmed by a person before a year of transactions is imported off by a
 * factor of a thousand. So the check asserts the preview pairs each raw row
 * with how it will be read: a preview of the raw file would prove only that
 * the file was uploaded.
 */
final class BankingScreenRules
{
    private const FIXTURE = __DIR__.'/../Fixtures/banking/csv-european.csv';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'the import routes exist' => $this->routesExist(),
            'only the writing route carries the permission' => $this->permissionIsOnTheWrite(),
            'every control has a label bound to it' => $this->controlsAreLabelled(),
            'the error summary links to what it names' => $this->errorSummaryLinks(),
            'nothing reorders the page with a tabindex' => $this->noPositiveTabIndex(),
            'the preview pairs each row with how it reads' => $this->previewPairsRawWithRead(),
            'the detector shows its evidence' => $this->evidenceIsShown(),
            'an ambiguous file says so on the screen' => $this->ambiguityIsOnScreen(),
            'the one button that writes is on its own' => $this->writingButtonIsDistinct(),
            'the table has a caption and header scopes' => $this->tableIsNavigable(),
        ];
    }

    /** @return list<string> */
    private function routesExist(): array
    {
        $expected = [
            'banking.import.create' => 'GET',
            'banking.import.preview' => 'POST',
            'banking.import.remap' => 'POST',
            'banking.import.store' => 'POST',
        ];

        $failures = [];

        foreach ($expected as $name => $method) {
            $route = Route::getRoutes()->getByName($name);

            if ($route === null) {
                $failures[] = sprintf('the route %s does not exist', $name);

                continue;
            }

            if (! \in_array($method, $route->methods(), true)) {
                $failures[] = sprintf('%s does not accept %s', $name, $method);
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function permissionIsOnTheWrite(): array
    {
        $failures = [];

        $store = Route::getRoutes()->getByName('banking.import.store');

        if ($store === null) {
            return ['the importing route does not exist'];
        }

        if (! \in_array('can:bank.import', $store->gatherMiddleware(), true)) {
            $failures[] = 'the importing route does not declare can:bank.import on the route itself';
        }

        /*
         | And the preview routes deliberately do NOT carry it.
         |
         | They write nothing, and requiring the permission there would mean
         | somebody who may not import cannot see why a file is being
         | refused - which is the question they are usually the one asked to
         | answer.
         */
        foreach (['banking.import.preview', 'banking.import.remap'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            if ($route !== null && \in_array('can:bank.import', $route->gatherMiddleware(), true)) {
                $failures[] = sprintf(
                    '%s requires the import permission although it writes nothing, so somebody who '.
                    'may not import cannot see why a file was refused',
                    $name,
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function controlsAreLabelled(): array
    {
        $failures = [];

        foreach ($this->screens() as $screen => $html) {
            $labelled = [];

            if (preg_match_all('/<label[^>]*\bfor="([^"]+)"/', $html, $labels) > 0) {
                $labelled = $labels[1];
            }

            /*
             | A control wrapped in its own label needs no `for`.
             |
             | Several of the checkboxes here are written that way, which is
             | valid and is what the browser's own hit area follows - so
             | those ids are collected rather than reported.
             */
            if (preg_match_all('/<label[^>]*>\s*<input[^>]*\bname="([^"]+)"/', $html, $wrapped) > 0) {
                $labelled = [...$labelled, ...$wrapped[1]];
            }

            foreach ($this->controlsIn($html) as [$tag, $attributes]) {
                if (preg_match('/\btype="(hidden|submit|button)"/', $attributes) === 1) {
                    continue;
                }

                $id = $this->attribute($attributes, 'id');
                $name = $this->attribute($attributes, 'name');

                if ($id !== null && \in_array($id, $labelled, true)) {
                    continue;
                }

                if ($name !== null && \in_array($name, $labelled, true)) {
                    continue;
                }

                if ($this->attribute($attributes, 'aria-label') !== null) {
                    continue;
                }

                $failures[] = sprintf(
                    '%s: a <%s> named "%s" has no label bound to it, so a screen reader announces it '.
                    'as an unnamed field and nobody can tell which column they are setting',
                    $screen,
                    $tag,
                    $name ?? $id ?? '(unnamed)',
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function errorSummaryLinks(): array
    {
        $html = $this->render('banking.import-upload', $this->uploadData(withErrors: true));

        $failures = [];

        if (! str_contains($html, 'role="alert"')) {
            $failures[] = 'the error summary is not announced as an alert, so it is never read out';
        }

        /*
         | The summary LINKS to the field.
         |
         | A message rendered beside a control twelve rows down is a message
         | a screen-reader user meets after passing it. The link is what
         | turns "something is wrong" into "and it is here".
         */
        if (preg_match('/role="alert"[\s\S]{0,900}?<a href="#statement"/', $html) !== 1) {
            $failures[] = 'the error summary does not link to the field it names';
        }

        if (! str_contains($html, 'id="statement"')) {
            $failures[] = 'the field the summary links to has no matching id, so the link goes nowhere';
        }

        if (! str_contains($html, 'aria-invalid="true"')) {
            $failures[] = 'the field in error is not marked invalid, so only sighted users know which it is';
        }

        return $failures;
    }

    /** @return list<string> */
    private function noPositiveTabIndex(): array
    {
        $failures = [];

        foreach ($this->screens() as $screen => $html) {
            if (preg_match_all('/tabindex="(-?\d+)"/', $html, $found) === 0) {
                continue;
            }

            foreach ($found[1] as $value) {
                /*
                 | `-1` is fine: it makes the error summary focusable
                 | without putting it in the tab order. A positive value
                 | reorders the whole page relative to every other element
                 | on it, which is the one thing nobody can recover from by
                 | pressing Tab more times.
                 */
                if ((int) $value > 0) {
                    $failures[] = sprintf(
                        '%s has tabindex="%s", which reorders the page against every other element '.
                        'on it',
                        $screen,
                        $value,
                    );
                }
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function previewPairsRawWithRead(): array
    {
        $html = $this->render('banking.import-preview', $this->csvData());

        $failures = [];

        /*
         | The raw cell and the interpretation, in the same row.
         |
         | "01.05.2026 → 2026-05-01" is checkable at a glance, and
         | "1.000,00 → 1000.0000" is the line where somebody sees that their
         | thousands are being read as decimals. Either one alone proves
         | nothing.
         */
        foreach (['01.05.2026', '1.000,00'] as $raw) {
            if (! str_contains($html, $raw)) {
                $failures[] = sprintf('the preview does not show the raw value "%s"', $raw);
            }
        }

        foreach (['2026-05-01', '1000.0000'] as $read) {
            if (! str_contains($html, $read)) {
                $failures[] = sprintf('the preview does not show "%s" as it will be read', $read);
            }
        }

        if (preg_match('/01\.05\.2026[\s\S]{0,600}?1000\.0000/', $html) !== 1) {
            $failures[] =
                'the raw cells and the interpretation are not in the same row, so comparing them '.
                'means reading two tables at once';
        }

        /*
         | And the debit column is shown as negative.
         |
         | Which is the single most useful thing on the screen: a European
         | export with separate debit and credit columns is the normal case,
         | and the failure mode is every outgoing payment imported as money
         | coming in.
         */
        if (! str_contains($html, '-500.0000')) {
            $failures[] = 'the preview does not show a debit-column row as a negative amount';
        }

        return $failures;
    }

    /** @return list<string> */
    private function evidenceIsShown(): array
    {
        $html = $this->render('banking.import-preview', $this->csvData());

        $failures = [];

        /*
         | Each setting says what it was decided from.
         |
         | "Detected" with nothing behind it is what somebody clicks past,
         | and the decimal separator is exactly the setting where seeing the
         | reasoning makes a wrong answer obvious.
         */
        if (! str_contains($html, 'only read correctly with')) {
            $failures[] = 'the decimal separator does not say what it was decided from';
        }

        foreach (['decimal_separator', 'date_format', 'sign_convention', 'delimiter'] as $field) {
            if (! str_contains($html, 'name="'.$field.'"')) {
                $failures[] = sprintf('%s cannot be corrected on the screen', $field);
            }
        }

        if (! str_contains($html, 'aria-describedby="why-decimal"')) {
            $failures[] = 'the evidence is not associated with its control, so it is not read out with it';
        }

        return $failures;
    }

    /** @return list<string> */
    private function ambiguityIsOnScreen(): array
    {
        $data = $this->csvData();
        $data['warnings'] = [
            'The decimal separator cannot be determined from this file: a wrong answer here is off '.
            'by a factor of a thousand and every row still looks right.',
        ];

        $html = $this->render('banking.import-preview', $data);

        return str_contains($html, 'factor of a thousand') && str_contains($html, 'role="alert"')
            ? []
            : [
                'a file whose decimal separator cannot be determined does not say so on the screen. '.
                'The warning exists in the detector, and a warning nobody is shown is a warning '.
                'that is not there',
            ];
    }

    /** @return list<string> */
    private function writingButtonIsDistinct(): array
    {
        $html = $this->render('banking.import-preview', $this->csvData());

        $failures = [];

        /*
         | The button that writes is worded as what it does.
         |
         | Somebody who has pressed three buttons to get here should not have
         | to remember which of them was the irreversible one, so none of
         | them says "confirm".
         */
        if (! str_contains($html, __('استورد هذا الكشف'))) {
            $failures[] = 'the importing button does not say what it does';
        }

        if (str_contains($html, __('أكّد')) || str_contains($html, 'Confirm')) {
            $failures[] = 'a button on this screen says "confirm", which says nothing about what it does';
        }

        /*
         | And it is in a different form from the one that re-previews.
         |
         | Otherwise the Enter key in any text field on the mapping form
         | submits whichever button the browser considers default - and on
         | this screen that would be an import.
         */
        $actions = [];

        if (preg_match_all('/<form\b[^>]*\baction="([^"]+)"/', $html, $found) > 0) {
            $actions = array_unique($found[1]);
        }

        $remaps = array_filter($actions, static fn (string $a): bool => str_ends_with($a, '/import/remap'));
        $stores = array_filter($actions, static fn (string $a): bool => str_ends_with($a, '/import'));

        if ($remaps === []) {
            $failures[] = 'there is no form that re-previews, so a correction cannot be seen before it is applied';
        }

        if ($stores === []) {
            $failures[] = 'there is no form that imports';
        }

        if ($remaps !== [] && $stores !== [] && \count($actions) < 2) {
            $failures[] =
                'the mapping and the import share one form, so pressing Enter in a text field '.
                'imports rather than re-previewing';
        }

        return $failures;
    }

    /** @return list<string> */
    private function tableIsNavigable(): array
    {
        $html = $this->render('banking.import-preview', $this->csvData());

        $failures = [];

        if (! str_contains($html, '<caption')) {
            $failures[] = 'the preview table has no caption, so it is announced as an unnamed table';
        }

        if (preg_match_all('/<th\b[^>]*scope="col"/', $html) < 3) {
            $failures[] = 'the preview table\'s headers do not declare their scope';
        }

        return $failures;
    }

    // ---- Rendering --------------------------------------------------------

    /** @return array<string, string> */
    private function screens(): array
    {
        /*
         | The upload screen is rendered WITH an error.
         |
         | Without one its error block never renders, so nothing structural
         | about it is ever inspected - a positive `tabindex` added there
         | changed no rendered output and every check stayed green. Found by
         | biting it.
         */
        return [
            'banking.import-upload' => $this->render('banking.import-upload', $this->uploadData(withErrors: true)),
            'banking.import-preview' => $this->render('banking.import-preview', $this->csvData()),
        ];
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, array $data): string
    {
        return View::make($view, $data)->render();
    }

    /** @return array<string, mixed> */
    private function uploadData(bool $withErrors = false): array
    {
        $errors = new \Illuminate\Support\MessageBag();

        if ($withErrors) {
            $errors->add('statement', 'This file is a ZIP container, not text.');
        }

        return [
            'account' => $this->account(),
            'formats' => array_keys((array) config('banking.formats', [])),
            'templates' => collect(),
            // Parenthesised: chaining off `new` without them is 8.4, and
            // this runs on 8.3.
            'errors' => (new \Illuminate\Support\ViewErrorBag())->put('default', $errors),
        ];
    }

    /** @return array<string, mixed> */
    private function csvData(): array
    {
        $content = (string) file_get_contents(self::FIXTURE);
        $sniffer = new CsvSniffer();
        $sniffed = $sniffer->sniff($content);

        $map = [
            'booking_date' => 0,
            'value_date' => 1,
            'remittance' => 2,
            'counterparty_name' => 3,
            'debit' => 4,
            'credit' => 5,
            'currency' => 6,
        ];

        return [
            'account' => $this->account(),
            'fileName' => 'export.csv',
            'key' => '01JXXXXXXXXXXXXXXXXXXXXXXX',
            'format' => 'csv',
            'isCsv' => true,
            'dialect' => $sniffed['dialect'],
            'evidence' => $sniffed['dialect']->explain($sniffed['evidence']),
            'warnings' => $sniffed['warnings'],
            'map' => $map,
            'preview' => (new CsvStatementReader($sniffer))->preview(
                $content,
                $sniffed['dialect'],
                $map,
                'SAR',
            ),
            'fields' => [
                'booking_date' => 'تاريخ القيد',
                'amount' => 'المبلغ',
                'debit' => 'مدين',
                'credit' => 'دائن',
                'remittance' => 'البيان',
            ],
            'template' => null,
            'errors' => new \Illuminate\Support\ViewErrorBag(),
        ];
    }

    /**
     * A stand-in account: the screens read two methods and nothing else.
     *
     * Not a real row, because rendering a template needs no database and a
     * check that opened one would be checking the fixture rather than the
     * screen.
     */
    private function account(): object
    {
        return new class implements \Illuminate\Contracts\Routing\UrlRoutable
        {
            public string $institution_name = 'Al Rajhi Bank';

            public function shortIdentifier(): string
            {
                return 'Main operating account ····7519';
            }

            /*
             | UrlRoutable, because the screens build routes from it.
             |
             | Without the interface `route()` tries to stringify the object
             | and the render fails - which is the right failure, since a
             | model that cannot be put in a URL is a model whose screens
             | cannot link to it.
             */
            public function getRouteKey(): string
            {
                return '01JYYYYYYYYYYYYYYYYYYYYYYY';
            }

            public function getRouteKeyName(): string
            {
                return 'id';
            }

            public function resolveRouteBinding($value, $field = null): ?self
            {
                return null;
            }

            public function resolveChildRouteBinding($childType, $value, $field): ?self
            {
                return null;
            }
        };
    }

    /**
     * Every form control in a rendered screen.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function controlsIn(string $html): array
    {
        $controls = [];

        if (preg_match_all('/<(input|select|textarea)\b([^>]*)>/i', $html, $found, PREG_SET_ORDER) === 0) {
            return $controls;
        }

        foreach ($found as $match) {
            $controls[] = [strtolower($match[1]), $match[2]];
        }

        return $controls;
    }

    private function attribute(string $attributes, string $name): ?string
    {
        return preg_match('/\b'.preg_quote($name, '/').'="([^"]*)"/', $attributes, $found) === 1
            ? $found[1]
            : null;
    }
}
