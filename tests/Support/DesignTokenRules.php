<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Ui\Theme;
use FilesystemIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * The colour tokens, and the contrast every theme must keep - UX-003, extending PF-200.
 *
 * ## Measured from the stylesheet itself
 *
 * Nothing here holds a copy of a colour. Each theme is resolved from
 * `resources/css/app.css` the way the cascade resolves it - the `:root` block,
 * then `.dark`, then the theme's own `[data-theme]` block, in source order -
 * down to the primitive's OKLCH triple, which is converted to the 8-bit sRGB a
 * screen shows and measured with the WCAG 2.2 formula. A copy would pass the
 * day someone changed the stylesheet and forgot the copy.
 *
 * The pairs are DECLARED in `resources/css/contrast-pairs.json` rather than
 * generated: a cartesian product of roles tests combinations nobody renders
 * and buries the ones that matter.
 *
 * ## Why it proves itself on every run
 *
 * A contrast check that has quietly stopped resolving a theme reports nothing,
 * and nothing is what a passing check reports too. So one rule feeds it the
 * stylesheet with the mistake this palette's first draft actually made - the
 * light theme's control border at neutral-400, 2.89:1 - and fails if the
 * check does not refuse it.
 */
final class DesignTokenRules
{
    /** The mistake the first draft made, which the check must keep refusing. */
    private const string BITE_FROM = 'var(--p-neutral-500)';

    private const string BITE_TO = 'var(--p-neutral-400)';

    private readonly string $base;

    public function __construct(?string $base = null)
    {
        $this->base = $base ?? \dirname(__DIR__, 2);
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $css = $this->read('resources/css/app.css');

        return [
            'every declared colour pair clears its target in every theme' => $this->contrastViolations($css),
            'the contrast check bites, proved on every run' => $this->theCheckBites($css),
            'every theme redefines every colour role' => $this->everyThemeDefinesEveryRole($css),
            'every colour role is measured, or says why it need not be' => $this->everyRoleIsAccountedFor($css),
            'nothing reaches past the roles to a primitive' => $this->noPrimitiveOutsideTheTokens($css),
            'a form control\'s border is the control role' => $this->controlsUseTheControlBorder(),
            'every theme a person can choose is measured' => $this->everyChoosableThemeIsMeasured(),
            'a page is in its theme before the first paint' => $this->pagesAreThemedBeforePaint(),
            'forced colours keep every control\'s edge' => $this->forcedColoursKeepEdges($css),
            'type sizes and layers come from the scale, never a literal' => $this->noLiteralSizesOrLayers(),
            'Arabic is never letter-spaced' => $this->arabicIsNeverLetterSpaced($css),
            'nothing moves for longer than 500ms, and nothing loops' => $this->motionStaysShort(),
            'glass is the only backdrop filter' => $this->glassIsTheOnlyBackdrop($css),
        ];
    }

    /**
     * Every declared pair, in every theme.
     *
     * @return list<string>
     */
    public function contrastViolations(string $css): array
    {
        $spec = $this->pairs();
        $blocks = self::topLevelBlocks($css);
        $violations = [];

        foreach ($spec['themes'] as $theme => $definition) {
            $tokens = self::resolveTheme($blocks, $definition['selectors']);

            foreach (['text', 'ui'] as $kind) {
                $target = $definition[$kind];

                foreach ($spec[$kind] as [$foreground, $background]) {
                    try {
                        [$fgHex, $fgFrom] = self::colour($tokens, '--c-'.$foreground);
                        [$bgHex, $bgFrom] = self::colour($tokens, '--c-'.$background);
                    } catch (RuntimeException $e) {
                        $violations[] = \sprintf('%s: %s', $theme, $e->getMessage());

                        continue;
                    }

                    $ratio = self::ratio($fgHex, $bgHex);

                    if ($ratio + 1e-9 < $target) {
                        $violations[] = \sprintf(
                            '%s: %s on %s is %.2f:1 and %s needs %s:1 (%s %s on %s %s).',
                            $theme,
                            $foreground,
                            $background,
                            $ratio,
                            $kind === 'text' ? 'text' : 'a control or graphic',
                            rtrim(rtrim(number_format($target, 1), '0'), '.'),
                            $fgFrom,
                            $fgHex,
                            $bgFrom,
                            $bgHex,
                        );
                    }
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function theCheckBites(string $css): array
    {
        // The first `--c-outline-control` declaration is the light theme's.
        $mutated = preg_replace(
            '/(--c-outline-control:\s*)'.preg_quote(self::BITE_FROM, '/').'/',
            '${1}'.self::BITE_TO,
            $css,
            1,
            $count,
        );

        if (! \is_string($mutated) || $count !== 1) {
            return ['The light theme no longer sets --c-outline-control to '.self::BITE_FROM.', so this rule '.
                'cannot reproduce the mistake it guards against. Point it at the new value rather than deleting it.'];
        }

        foreach ($this->contrastViolations($mutated) as $violation) {
            if (str_starts_with($violation, 'light: outline-control on surface is 2.89:1')) {
                return [];
            }
        }

        return ['Setting the light control border to neutral-400 (2.89:1 on white, the first draft\'s mistake) '.
            'was NOT refused. The contrast check has stopped resolving the light theme, or stopped measuring '.
            'outline-control - and a check in that state passes everything.'];
    }

    /**
     * A theme that forgets a role inherits the light value silently - the
     * 1.62:1 status pill in `design-system.md` was exactly that.
     *
     * @return list<string>
     */
    private function everyThemeDefinesEveryRole(string $css): array
    {
        $blocks = self::topLevelBlocks($css);
        $light = self::rolesIn($blocks, ':root');
        $violations = [];

        foreach (['.dark', "[data-theme='midnight']", "[data-theme='hc-light']", "[data-theme='hc-dark']"] as $selector) {
            $defined = self::rolesIn($blocks, $selector);

            if ($defined === []) {
                $violations[] = \sprintf('app.css has no %s block.', $selector);

                continue;
            }

            foreach (array_keys($light) as $role) {
                if (! isset($defined[$role]) && ! str_starts_with($light[$role], 'var(--c-')) {
                    $violations[] = \sprintf(
                        '%s does not redefine %s, so it silently inherits the value of the theme beneath it '.
                        '(light, or Graphite for a dark theme) - a colour nobody chose for this one.',
                        $selector,
                        $role,
                    );
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function everyRoleIsAccountedFor(string $css): array
    {
        $spec = $this->pairs();
        $paired = [];

        foreach ([...$spec['text'], ...$spec['ui']] as [$foreground, $background]) {
            $paired[$foreground] = true;
            $paired[$background] = true;
        }

        $violations = [];

        foreach (array_keys(self::rolesIn(self::topLevelBlocks($css), ':root')) as $role) {
            $name = substr($role, 4);

            if (! isset($paired[$name]) && ! isset($spec['unpaired'][$name])) {
                $violations[] = \sprintf(
                    '%s is in no pair in contrast-pairs.json and not listed under "unpaired" with a reason. '.
                    'A role nobody pairs is a role nobody has checked.',
                    $role,
                );
            }
        }

        foreach ([...array_keys($paired), ...array_keys($spec['unpaired'])] as $name) {
            if (! isset(self::rolesIn(self::topLevelBlocks($css), ':root')['--c-'.$name])) {
                $violations[] = \sprintf('contrast-pairs.json names "%s", which app.css does not define.', $name);
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * A component never references a primitive - the rule `design-system.md`
     * states and this enforces. Custom properties may (that is what the
     * semantic layer is); a real property, a template or a script may not.
     *
     * @return list<string>
     */
    private function noPrimitiveOutsideTheTokens(string $css): array
    {
        $violations = [];
        $source = self::withoutComments($css);

        if (preg_match_all('/(?<![\w-])(-{0,2}[a-z][\w-]*)\s*:\s*([^;{}]*var\(--p-[^;{}]*)/i', $source, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $property]) {
                if (! str_starts_with($property, '--')) {
                    $violations[] = \sprintf(
                        'resources/css/app.css: the property "%s" reads a primitive directly. Give it a role in '.
                        'the semantic layer and read that instead.',
                        $property,
                    );
                }
            }
        }

        foreach ([...$this->files('resources/views', '.blade.php'), ...$this->files('resources/js', '.js')] as $file) {
            if (str_contains((string) file_get_contents($file), '--p-')) {
                $violations[] = \sprintf('%s references a primitive (--p-*). Use a role (--c-*) or its utility.', $this->relative($file));
            }
        }

        return $violations;
    }

    /**
     * WCAG 1.4.11: the boundary of something you operate needs 3:1. The
     * decorative divider role does not have it, and is not meant to.
     *
     * @return list<string>
     */
    private function controlsUseTheControlBorder(): array
    {
        $violations = [];

        foreach ($this->files('resources/views', '.blade.php') as $file) {
            $source = (string) file_get_contents($file);

            if (! preg_match_all('/<(input|select|textarea)\b/i', $source, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($m[0] as [, $offset]) {
                $end = self::tagEnd($source, $offset + 1);
                $tag = substr($source, $offset, $end === null ? 0 : $end - $offset + 1);

                if (preg_match('/\btype\s*=\s*["\']hidden["\']/i', $tag) === 1) {
                    continue;
                }

                if (preg_match('/(?<![\w-])border-outline(?:-subtle|-strong)?(?![\w-])/', $tag) === 1) {
                    $violations[] = \sprintf(
                        '%s:%d draws a form control\'s border with a decorative outline role. Use '.
                        'border-outline-control - the decorative roles do not reach 3:1.',
                        $this->relative($file),
                        substr_count(substr($source, 0, $offset), "\n") + 1,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * A theme in the switcher that the contrast matrix does not measure is a
     * theme nobody has checked - so each one must map, by the exact selectors
     * it puts on `<html>`, onto a theme in contrast-pairs.json.
     *
     * @return list<string>
     */
    private function everyChoosableThemeIsMeasured(): array
    {
        $measured = array_map(
            static fn (array $theme): array => $theme['selectors'],
            $this->pairs()['themes'],
        );

        $violations = [];

        foreach (Theme::cases() as $theme) {
            if ($theme === Theme::Automatic) {
                continue; // resolves to one of the others in the browser
            }

            $selectors = [':root'];

            if ($theme->isDark()) {
                $selectors[] = '.dark';
            }

            if ($theme->dataTheme() !== null) {
                $selectors[] = \sprintf("[data-theme='%s']", $theme->dataTheme());
            }

            if (! \in_array($selectors, $measured, true)) {
                $violations[] = \sprintf(
                    'Theme::%s renders as %s, and no theme in contrast-pairs.json is measured with those selectors.',
                    $theme->name,
                    implode(' + ', $selectors),
                );
            }
        }

        return $violations;
    }

    /**
     * The theme is on `<html>` when the server sends it, and Automatic is
     * resolved by a blocking script in `<head>` ahead of the stylesheet - so
     * no frame is ever painted in the wrong theme. A layout that loses either
     * half flashes, and a flash of white is what a person who chose high
     * contrast or dark at night notices first.
     *
     * @return list<string>
     */
    private function pagesAreThemedBeforePaint(): array
    {
        $violations = [];

        foreach (['resources/views/layouts/app.blade.php', 'resources/views/layouts/plain.blade.php'] as $layout) {
            $source = $this->read($layout);
            $html = strpos($source, '<html');
            $preference = strpos($source, 'ThemePreference::');
            $boot = strpos($source, "@include('partials.theme-boot')");
            $vite = strpos($source, '@vite(');
            $headEnd = strpos($source, '</head>');

            if ($preference === false || $html === false || $preference > $html) {
                $violations[] = $layout.' does not resolve the theme through ThemePreference before <html>.';
            }

            // To <head> rather than to the first `>`: `$theme->` has one in it.
            if (preg_match('/<html\b.*?\$theme->htmlClass\(\).*?data-theme=.*?<head>/s', $source) !== 1) {
                $violations[] = $layout.' does not write the theme\'s class and data-theme onto <html>.';
            }

            if ($boot === false || $vite === false || $headEnd === false || $boot > $vite || $boot > $headEnd) {
                $violations[] = $layout.' must include partials.theme-boot inside <head> and BEFORE @vite, '.
                    'or Automatic paints a frame before it knows which theme it is.';
            }
        }

        $script = $this->read('resources/js/theme-boot.js');

        foreach (['(prefers-color-scheme: dark)', '(prefers-contrast: more)', "addEventListener('change'"] as $needle) {
            if (! str_contains($script, $needle)) {
                $violations[] = \sprintf('resources/js/theme-boot.js no longer contains %s.', $needle);
            }
        }

        return $violations;
    }

    /**
     * In forced-colours mode backgrounds and shadows are discarded, so an
     * edge drawn only with them disappears. The stylesheet must put a real
     * border on every operable element there, and a system-coloured focus
     * ring.
     *
     * @return list<string>
     */
    private function forcedColoursKeepEdges(string $css): array
    {
        $source = self::withoutComments($css);
        $start = strpos($source, '@media (forced-colors: active)');

        if ($start === false) {
            return ['app.css has no @media (forced-colors: active) block, so every button whose edge is its '.
                'background vanishes in Windows high contrast.'];
        }

        $block = substr($source, $start, 1500);
        $violations = [];

        foreach (['button', 'select', 'input', 'textarea', 'summary', 'ButtonText', 'Highlight'] as $needle) {
            if (! str_contains($block, $needle)) {
                $violations[] = \sprintf('The forced-colours block does not mention %s.', $needle);
            }
        }

        return $violations;
    }

    /**
     * UX-005 and UX-006: a `text-[17px]` is a size nobody chose for any
     * other screen, and a `z-50` is a layer nobody can place relative to the
     * others. Both have named steps; a template uses those.
     *
     * @return list<string>
     */
    private function noLiteralSizesOrLayers(): array
    {
        $violations = [];
        $patterns = [
            '/(?<![\w-])(?:[\w-]+:)*text-\[[\d.]+(?:px|rem|em)\]/' => 'an arbitrary type size; use a step of the type scale (text-label, text-title, ...)',
            '/(?<![\w-])(?:[\w-]+:)*z-(?:\d+|\[[^\]]*\])/' => 'a literal z-index; use a layer token, e.g. z-(--z-popover)',
        ];

        foreach ($this->files('resources/views', '.blade.php') as $file) {
            $source = (string) file_get_contents($file);

            foreach ($patterns as $pattern => $why) {
                if (preg_match_all($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[0] as [$match, $offset]) {
                        $violations[] = \sprintf(
                            '%s:%d uses "%s", %s.',
                            $this->relative($file),
                            substr_count(substr($source, 0, $offset), "\n") + 1,
                            $match,
                            $why,
                        );
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Letter-spacing pulls Arabic letters apart at the joins that make them
     * words. It is allowed only where the text is Latin by construction: a
     * figure, or something marked `:lang(en)` / `dir="ltr"`.
     *
     * @return list<string>
     */
    private function arabicIsNeverLetterSpaced(string $css): array
    {
        $violations = [];

        // Innermost rule blocks: the selector right before a `{` with no
        // nested block inside.
        if (preg_match_all('/([^{};]+)\{([^{}]*)\}/', self::withoutComments($css), $m, PREG_SET_ORDER)) {
            foreach ($m as [, $selector, $body]) {
                if (preg_match('/(?<![\w-])letter-spacing\s*:/', $body) !== 1) {
                    continue;
                }

                if (preg_match('/figure|:lang\(en\)|\[dir=.?ltr/', $selector) !== 1) {
                    $violations[] = \sprintf(
                        'app.css letter-spaces "%s", which is not marked Latin. Letter-spacing breaks the joins in '.
                        'Arabic; limit it to a .figure or :lang(en) selector.',
                        trim((string) preg_replace('/\s+/', ' ', $selector)),
                    );
                }
            }
        }

        foreach ($this->files('resources/views', '.blade.php') as $file) {
            $source = (string) file_get_contents($file);

            if (! preg_match_all('/<[a-z][\w-]*\b[^>]*\btracking-[\w\[\]().-]+[^>]*>/i', $source, $tags, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($tags[0] as [$tag, $offset]) {
                if (preg_match('/\bfigure\b|dir="ltr"|lang="en"/', $tag) !== 1) {
                    $violations[] = \sprintf(
                        '%s:%d letter-spaces an element that is not marked Latin (figure, dir="ltr" or lang="en").',
                        $this->relative($file),
                        substr_count(substr($source, 0, $offset), "\n") + 1,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * UX-007, read from the BUILT stylesheet - the one the browser gets, with
     * every utility a template asked for - because a slow duration utility or
     * a looping animation utility in a template never appears in app.css
     * itself. (Not named literally here: Tailwind reads source files for class
     * names, and naming them in this comment once put them in the build.)
     *
     * @return list<string>
     */
    private function motionStaysShort(): array
    {
        $manifest = $this->base.'/public/build/manifest.json';

        if (! is_file($manifest)) {
            return []; // not built is a deployment concern; a fresh clone has no build
        }

        $entries = json_decode((string) file_get_contents($manifest), true);
        // Indexed directly: the key itself contains dots, which data_get() would split on.
        $entry = \is_array($entries) ? ($entries['resources/css/app.css'] ?? null) : null;
        $built = \is_array($entry) ? ($entry['file'] ?? null) : null;

        if (! \is_string($built) || ! is_file($this->base.'/public/build/'.$built)) {
            return ['public/build/manifest.json names no stylesheet for resources/css/app.css. Run `npm run build`.'];
        }

        $source = (string) file_get_contents($this->base.'/public/build/'.$built);
        $violations = [];

        if (preg_match_all('/[^;{}]*\binfinite\b[^;{}]*/', $source, $m)) {
            foreach ($m[0] as $declaration) {
                $violations[] = \sprintf('The built CSS loops: "%s". Nothing loops - see interaction-and-motion.md.', trim($declaration));
            }
        }

        $timing = '/(?<![\w-])(?:transition|transition-duration|animation|animation-duration|--duration-[\w-]+|--default-transition-duration)\s*:\s*([^;{}]*)/';

        if (preg_match_all($timing, $source, $m, PREG_SET_ORDER)) {
            foreach ($m as [$declaration, $value]) {
                if (! preg_match_all('/(?<![\w.-])(\d*\.?\d+)(ms|s)\b/', $value, $times, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($times as [, $number, $unit]) {
                    $milliseconds = (float) $number * ($unit === 's' ? 1000 : 1);

                    if ($milliseconds > 500) {
                        $violations[] = \sprintf('The built CSS has "%s" - %dms is past the 500ms ceiling.', trim($declaration), (int) $milliseconds);
                    }
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * UX-014: glass only through `.glass`, whose fallbacks - reduced
     * transparency, more contrast, the HC themes, no support - live next to
     * it. A blur written anywhere else has none of them.
     *
     * @return list<string>
     */
    private function glassIsTheOnlyBackdrop(string $css): array
    {
        $violations = [];

        if (preg_match_all('/([^{};]+)\{([^{}]*)\}/', self::withoutComments($css), $m, PREG_SET_ORDER)) {
            foreach ($m as [, $selector, $body]) {
                if (preg_match('/(?<![\w-])backdrop-filter\s*:/', $body) === 1 && ! str_contains($selector, '.glass')) {
                    $violations[] = \sprintf('app.css applies backdrop-filter to "%s". Use the .glass class.', trim((string) preg_replace('/\s+/', ' ', $selector)));
                }
            }
        }

        foreach ($this->files('resources/views', '.blade.php') as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match_all('/(?<![\w-])(?:[\w-]+:)*backdrop-(?:blur|saturate|brightness|filter)[\w\[\]().-]*/', $source, $hits, PREG_OFFSET_CAPTURE)) {
                foreach ($hits[0] as [$hit, $offset]) {
                    $violations[] = \sprintf(
                        '%s:%d uses "%s". Glass has one recipe, with its fallbacks: the .glass class.',
                        $this->relative($file),
                        substr_count(substr($source, 0, $offset), "\n") + 1,
                        $hit,
                    );
                }
            }
        }

        return $violations;
    }

    /* -----------------------------------------------------------------------
     |  Reading the stylesheet
     * -------------------------------------------------------------------- */

    /**
     * Comments out, strings kept. Inside a string `/*` is not a comment - and
     * `@source '../views/*.php'` is exactly that string, which a naive strip
     * reads as the start of a comment that swallows the primitives.
     */
    private static function withoutComments(string $css): string
    {
        return (string) preg_replace_callback(
            '~("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')|/\*.*?\*/~s',
            static fn (array $m): string => ($m[1] ?? '') !== '' ? $m[1] : '',
            $css,
        );
    }

    /**
     * Top-level rule blocks with no nested block inside - which is every place
     * a token is declared. A `:root` inside `@media print` is nested, so the
     * print overrides never leak into a theme.
     *
     * @return list<array{selector: string, declarations: array<string, string>}>
     */
    private static function topLevelBlocks(string $css): array
    {
        $source = self::withoutComments($css);
        $blocks = [];
        $length = \strlen($source);
        $start = 0;
        $depth = 0;
        $open = 0;
        $nested = false;
        $selector = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];

            // A brace or semicolon inside a string is text, not structure.
            if ($char === '"' || $char === "'") {
                $close = strpos($source, $char, $i + 1);
                $i = $close === false ? $length : $close;

                continue;
            }

            if ($char === '{') {
                if ($depth === 0) {
                    $selector = trim(substr($source, $start, $i - $start));
                    $open = $i + 1;
                    $nested = false;
                } else {
                    $nested = true;
                }

                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    if (! $nested) {
                        $blocks[] = [
                            'selector' => (string) preg_replace('/\s+/', ' ', $selector),
                            'declarations' => self::declarations(substr($source, $open, $i - $open)),
                        ];
                    }

                    $start = $i + 1;
                }
            } elseif ($char === ';' && $depth === 0) {
                $start = $i + 1;
            }
        }

        return $blocks;
    }

    /** @return array<string, string> */
    private static function declarations(string $body): array
    {
        $declarations = [];

        foreach (explode(';', $body) as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $declarations[trim(substr($line, 0, $colon))] = trim((string) preg_replace('/\s+/', ' ', substr($line, $colon + 1)));
        }

        return $declarations;
    }

    /**
     * @param  list<array{selector: string, declarations: array<string, string>}>  $blocks
     * @param  list<string>  $selectors
     * @return array<string, string>
     */
    private static function resolveTheme(array $blocks, array $selectors): array
    {
        $tokens = [];

        foreach ($blocks as $block) {
            if (\in_array($block['selector'], $selectors, true)) {
                $tokens = [...$tokens, ...$block['declarations']];
            }
        }

        return $tokens;
    }

    /**
     * @param  list<array{selector: string, declarations: array<string, string>}>  $blocks
     * @return array<string, string> the `--c-*` roles declared by blocks with this selector
     */
    private static function rolesIn(array $blocks, string $selector): array
    {
        $roles = [];

        foreach ($blocks as $block) {
            if ($block['selector'] !== $selector) {
                continue;
            }

            foreach ($block['declarations'] as $name => $value) {
                if (str_starts_with($name, '--c-')) {
                    $roles[$name] = $value;
                }
            }
        }

        return $roles;
    }

    /**
     * Follows `var()` down to an `L% C H` triple.
     *
     * @param  array<string, string>  $tokens
     * @return array{string, string} the 8-bit hex, and the token the triple came from
     */
    private static function colour(array $tokens, string $name): array
    {
        $from = $name;

        for ($hops = 0; $hops < 8; $hops++) {
            $value = $tokens[$name] ?? throw new RuntimeException(\sprintf('%s is not defined in this theme', $name));

            if (preg_match('/^var\((--[\w-]+)\)$/', $value, $m) === 1) {
                $name = $from = $m[1];

                continue;
            }

            if (preg_match('/^([\d.]+)%\s+([\d.]+)\s+([\d.]+)$/', $value, $m) === 1) {
                return [self::hex((float) $m[1] / 100, (float) $m[2], (float) $m[3]), substr($from, 4)];
            }

            throw new RuntimeException(\sprintf('%s is "%s", which is neither var() nor an L%% C H triple', $name, $value));
        }

        throw new RuntimeException(\sprintf('%s does not resolve within eight var() hops', $from));
    }

    /**
     * The end of a tag that starts before `$from`, skipping quoted attribute
     * values and Blade `{{ }}`, either of which may contain a `>`.
     */
    private static function tagEnd(string $source, int $from): ?int
    {
        $quote = null;
        $length = \strlen($source);

        for ($i = $from; $i < $length; $i++) {
            if ($quote === null && substr($source, $i, 2) === '{{') {
                $close = strpos($source, '}}', $i + 2);

                if ($close === false) {
                    return null;
                }

                $i = $close + 1;

                continue;
            }

            $char = $source[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '>') {
                return $i;
            }
        }

        return null;
    }

    /* -----------------------------------------------------------------------
     |  Colour science - OKLCH to the sRGB a screen shows, and WCAG contrast
     * -------------------------------------------------------------------- */

    /** OKLCH to 8-bit sRGB hex, reducing chroma until the colour is displayable. */
    private static function hex(float $lightness, float $chroma, float $hue): string
    {
        if (! self::inGamut(self::linearRgb($lightness, $chroma, $hue))) {
            [$low, $high] = [0.0, $chroma];

            for ($i = 0; $i < 30; $i++) {
                $mid = ($low + $high) / 2;
                self::inGamut(self::linearRgb($lightness, $mid, $hue)) ? $low = $mid : $high = $mid;
            }

            $chroma = $low;
        }

        return '#'.implode('', array_map(
            static function (float $channel): string {
                $channel = min(1.0, max(0.0, $channel));
                $encoded = $channel <= 0.0031308 ? 12.92 * $channel : 1.055 * ($channel ** (1 / 2.4)) - 0.055;

                return str_pad(strtoupper(dechex((int) round($encoded * 255))), 2, '0', STR_PAD_LEFT);
            },
            self::linearRgb($lightness, $chroma, $hue),
        ));
    }

    /** @return array{float, float, float} */
    private static function linearRgb(float $lightness, float $chroma, float $hue): array
    {
        $a = $chroma * cos(deg2rad($hue));
        $b = $chroma * sin(deg2rad($hue));

        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    /** @param array{float, float, float} $rgb */
    private static function inGamut(array $rgb): bool
    {
        foreach ($rgb as $channel) {
            if ($channel < -1e-4 || $channel > 1 + 1e-4) {
                return false;
            }
        }

        return true;
    }

    private static function luminance(string $hex): float
    {
        $value = (int) hexdec(substr($hex, 1));
        $weights = [0.2126, 0.7152, 0.0722];
        $sum = 0.0;

        foreach ([($value >> 16) & 255, ($value >> 8) & 255, $value & 255] as $i => $channel) {
            $c = $channel / 255;
            $sum += $weights[$i] * ($c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4);
        }

        return $sum;
    }

    private static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /* -----------------------------------------------------------------------
     |  Files
     * -------------------------------------------------------------------- */

    /**
     * @return array{
     *     themes: array<string, array{selectors: list<string>, text: float, ui: float}>,
     *     text: list<array{string, string}>,
     *     ui: list<array{string, string}>,
     *     unpaired: array<string, string>
     * }
     */
    private function pairs(): array
    {
        try {
            $data = json_decode($this->read('resources/css/contrast-pairs.json'), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('resources/css/contrast-pairs.json is not valid JSON: '.$e->getMessage(), 0, $e);
        }

        if (! \is_array($data) || ! \is_array($data['themes'] ?? null) || ! \is_array($data['text'] ?? null)
            || ! \is_array($data['ui'] ?? null) || ! \is_array($data['unpaired'] ?? null)) {
            throw new RuntimeException('resources/css/contrast-pairs.json needs "themes", "text", "ui" and "unpaired".');
        }

        $themes = [];

        foreach ($data['themes'] as $name => $theme) {
            if (! \is_string($name) || ! \is_array($theme) || ! \is_array($theme['selectors'] ?? null)
                || ! is_numeric($theme['text'] ?? null) || ! is_numeric($theme['ui'] ?? null)) {
                throw new RuntimeException(\sprintf('Theme "%s" in contrast-pairs.json is malformed.', (string) $name));
            }

            $themes[$name] = [
                'selectors' => array_values(array_map(strval(...), array_filter($theme['selectors'], \is_string(...)))),
                'text' => (float) $theme['text'],
                'ui' => (float) $theme['ui'],
            ];
        }

        $list = static function (array $pairs, string $kind): array {
            $out = [];

            foreach ($pairs as $pair) {
                if (! \is_array($pair) || \count($pair) !== 2 || ! \is_string($pair[0] ?? null) || ! \is_string($pair[1] ?? null)) {
                    throw new RuntimeException(\sprintf('A "%s" pair in contrast-pairs.json is not two role names.', $kind));
                }

                $out[] = [$pair[0], $pair[1]];
            }

            return $out;
        };

        $unpaired = [];

        foreach ($data['unpaired'] as $role => $reason) {
            if (! \is_string($role) || ! \is_string($reason) || trim($reason) === '') {
                throw new RuntimeException('Every "unpaired" role in contrast-pairs.json needs a reason.');
            }

            $unpaired[$role] = $reason;
        }

        return ['themes' => $themes, 'text' => $list($data['text'], 'text'), 'ui' => $list($data['ui'], 'ui'), 'unpaired' => $unpaired];
    }

    private function read(string $relative): string
    {
        $contents = @file_get_contents($this->base.'/'.$relative);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('%s could not be read.', $relative));
        }

        return $contents;
    }

    /** @return list<string> */
    private function files(string $directory, string $suffix): array
    {
        $root = $this->base.'/'.$directory;

        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getPathname(), $suffix)) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        return ltrim(str_replace(str_replace('\\', '/', $this->base), '', $file), '/');
    }
}
