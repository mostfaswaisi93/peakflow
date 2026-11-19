<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A skeleton stands only where a wait was measured - PF-280.
 *
 * ## The criterion is "only where", and that is the hard half
 *
 * Building a placeholder is easy. Not putting it on a page that arrives in
 * 127ms is the part that takes a measurement and the discipline to read it,
 * because a placeholder shown for 120ms is a flicker: the eye registers
 * that something changed and has no time to read what, and the page arrives
 * feeling less settled than if nothing had been drawn.
 *
 * So these checks bind the component to `docs/design/wait-budget.md`. A
 * template that uses `<x-skeleton>` and is not in that table above the
 * threshold is refused - and the table is refused if it stops being a
 * measurement, because a number nobody re-took is a number that was true
 * once.
 */
final class SkeletonRules
{
    private const BUDGET = 'docs/design/wait-budget.md';

    private const THRESHOLD_MS = 200;

    private readonly string $base;

    public function __construct(?string $base = null)
    {
        $this->base = $base ?? \dirname(__DIR__, 2);
    }

    /**
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return [
            'the wait budget states a threshold and a measurement per screen' => $this->budgetIsAMeasurement(),
            'a skeleton stands only on a screen measured over the threshold' => $this->onlyWhereMeasured(),
            'the skeleton is the shape of its content, not a grey box' => $this->itHasTheContentsShape(),
            'the skeleton announces itself busy, and shimmers at nothing' => $this->itAnnouncesAndDoesNotShimmer(),
        ];
    }

    /** @return list<string> */
    private function budgetIsAMeasurement(): array
    {
        $path = $this->base.'/'.self::BUDGET;

        if (! is_file($path)) {
            return [self::BUDGET.' is missing, so "only where the wait is long enough" rests on nobody\'s '.
                'measurement'];
        }

        $budget = (string) file_get_contents($path);
        $problems = [];

        if (! str_contains($budget, (string) self::THRESHOLD_MS.' millisecond')
            && ! str_contains($budget, (string) self::THRESHOLD_MS.'ms')) {
            $problems[] = sprintf(
                'the wait budget does not state the %dms threshold the checks use. A threshold in code '.
                'and no threshold in the document is a rule nobody can argue with',
                self::THRESHOLD_MS,
            );
        }

        $measured = $this->measurements($budget);

        if (\count($measured) < 5) {
            $problems[] = sprintf(
                'the wait budget lists %d measured screen(s). A budget with a handful of rows is a '.
                'sample somebody took once, not a statement about the product',
                \count($measured),
            );
        }

        foreach ($measured as $screen => $median) {
            if ($median <= 0) {
                $problems[] = sprintf('%s is listed with no median, so it is a row rather than a measurement', $screen);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function onlyWhereMeasured(): array
    {
        $budget = is_file($this->base.'/'.self::BUDGET)
            ? (string) file_get_contents($this->base.'/'.self::BUDGET)
            : '';

        $measured = $this->measurements($budget);
        $problems = [];
        $users = 0;

        foreach ($this->templates() as $file) {
            $source = (string) file_get_contents($file);

            if (! preg_match('/<x-skeleton\b/', $source)) {
                continue;
            }

            $relative = substr($file, \strlen($this->base) + 1);

            // A component defining itself is not a screen using it.
            if (str_contains($relative, 'components/skeleton.blade.php')) {
                continue;
            }

            $users++;
            $screen = $this->screenFor($relative, $measured);

            if ($screen === null) {
                $problems[] = sprintf(
                    '%s uses <x-skeleton> and no screen in the wait budget maps to it. "Only where the '.
                    'wait is long enough to matter" needs the wait to have been measured, and this one '.
                    'has not been',
                    $relative,
                );

                continue;
            }

            if ($measured[$screen] <= self::THRESHOLD_MS) {
                $problems[] = sprintf(
                    '%s uses <x-skeleton> and %s is measured at %dms, under the %dms threshold. A '.
                    'placeholder shown for that long is a flicker: the eye registers that something '.
                    'changed and has no time to read what, so the page arrives feeling less settled '.
                    'than if nothing had been drawn',
                    $relative,
                    $screen,
                    $measured[$screen],
                    self::THRESHOLD_MS,
                );
            }
        }

        /*
         | Nothing using it is the expected state today, and is not a
         | failure. The component exists for the first screen that crosses
         | the threshold - and the budget says, in words, when that is.
         */
        if ($users === 0 && ! str_contains($budget, 'used nowhere')) {
            $problems[] = 'no screen uses a skeleton and the wait budget does not say so. A component '.
                'nobody uses and no document explains reads as something somebody forgot to finish';
        }

        return $problems;
    }

    /** @return list<string> */
    private function itHasTheContentsShape(): array
    {
        $component = $this->base.'/resources/views/components/skeleton.blade.php';

        if (! is_file($component)) {
            return ['there is no skeleton component'];
        }

        $source = (string) file_get_contents($component);
        $css = (string) file_get_contents($this->base.'/resources/css/app.css');
        $problems = [];

        foreach (['table', 'tile'] as $shape) {
            if (! str_contains($source, "'".$shape."'")) {
                $problems[] = sprintf(
                    'the skeleton has no `%s` shape. "The shape of the content that is coming" means a '.
                    'table skeleton has the table\'s columns - one grey box tells the person something '.
                    'is loading, which they knew',
                    $shape,
                );
            }
        }

        if (! str_contains($source, 'inline-size:')) {
            $problems[] = 'the skeleton draws no per-column widths, so its columns are identical bars. '.
                'A list of five figures and a name does not look like six equal rectangles';
        }

        /*
         | The row height is the content's own token, which is what stops
         | the layout shifting when the content lands. A literal here would
         | be right at one density and wrong at the other two.
         */
        if (preg_match('/\.pf-skeleton__row\s*\{[^}]*block-size:\s*var\(--row-height\)/s', $css) !== 1) {
            $problems[] = 'the skeleton\'s rows are not `var(--row-height)` tall, so they are not the '.
                'height of the rows they stand in for. The content then replaces the skeleton and moves '.
                'the line the person had started reading';
        }

        return $problems;
    }

    /** @return list<string> */
    private function itAnnouncesAndDoesNotShimmer(): array
    {
        $component = $this->base.'/resources/views/components/skeleton.blade.php';

        if (! is_file($component)) {
            return ['there is no skeleton component'];
        }

        $source = (string) file_get_contents($component);
        $css = (string) file_get_contents($this->base.'/resources/css/app.css');
        $problems = [];

        if (! str_contains($source, 'aria-busy="true"')) {
            $problems[] = 'the skeleton does not announce itself busy, so a screen reader is told nothing '.
                'is happening while the page waits';
        }

        if (! str_contains($source, 'aria-hidden="true"')) {
            $problems[] = 'the skeleton\'s shapes are not hidden from assistive technology. Reading out '.
                'eleven empty boxes is not a description of anything';
        }

        if (! str_contains($source, 'sr-only')) {
            $problems[] = 'the skeleton has no words for a screen reader. `aria-busy` says a region is '.
                'busy; it does not say what is coming';
        }

        /*
         | And the shimmer, which is the part that would need an exception.
         |
         | The loader has one because its repetition is information. A
         | shimmer is decoration by the task's own description - "the
         | shimmer is decoration and the placeholder is information" - so
         | there is nothing here to grant, and nothing to stop under
         | reduced motion either.
         */
        if (preg_match('/\.pf-skeleton[^{]*\{[^}]*animation/s', $css) === 1) {
            $problems[] = 'the skeleton animates. The shimmer is the decorative half of a skeleton and '.
                'the house rule is that nothing loops - and a shimmer that had to be stopped under '.
                'reduced motion would be two components kept in step by hand, where there is currently '.
                'one that is the same in both';
        }

        return $problems;
    }

    /**
     * The screens the budget measures, as path => median milliseconds.
     *
     * @return array<string, int>
     */
    private function measurements(string $budget): array
    {
        $found = [];

        if (preg_match_all('/^\|\s*`([^`]+)`\s*\|\s*(\d+)ms\s*\|/m', $budget, $rows, PREG_SET_ORDER) === 0) {
            return $found;
        }

        foreach ($rows as [, $screen, $median]) {
            $found[$screen] = (int) $median;
        }

        return $found;
    }

    /**
     * The measured screen a template belongs to, by its path.
     *
     * `app/sales/invoices/index.blade.php` is `/sales/invoices`. Derived
     * rather than mapped by hand, so a page added later needs no entry
     * anywhere but the budget.
     *
     * @param  array<string, int>  $measured
     */
    private function screenFor(string $relative, array $measured): ?string
    {
        $path = str_replace(['resources/views/app', '/index.blade.php', '.blade.php'], '', $relative);

        foreach ($measured as $screen => $median) {
            if ($screen === $path || str_starts_with($path, rtrim($screen, '/').'/')) {
                return $screen;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function templates(): array
    {
        $found = [];
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base.'/resources/views', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($directory as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }
}
