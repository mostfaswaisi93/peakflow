<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Lang\NumberingSystem;
use App\Support\Money\Money;
use App\Support\Ui\Density;
use App\Support\Ui\DensityPreference;
use App\Tenant\Tenancy\Settings\SettingsRegistry;
use FilesystemIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * The design foundations - PF-199, PF-200, PF-201, PF-202, PF-203.
 *
 * ## What this suite is for, next to `DesignTokenRules`
 *
 * `DesignTokenRules` measures the declared colour pairs. This one measures
 * the things around them that a palette check cannot see:
 *
 *   - the three token LAYERS, and the rule the architecture rests on - that
 *     a component never reads a primitive. The layers have to be tellable
 *     apart by name for that to be checkable at all, which is why there is
 *     a `--k-` prefix and a manifest that says so;
 *   - the focus indicator, measured against every fill a focused control can
 *     have rather than against the page alone. This found a 1.00:1 indicator
 *     in the dark theme: the ring and a primary button were both Glacier
 *     Teal, so focusing the main action showed nothing;
 *   - the pairs the TEMPLATES actually put on one element, compared against
 *     the declared matrix, because a pair nobody declared is a pair nobody
 *     measured - which is the whole failure PF-200 was written after;
 *   - typography, digit shaping and density: three settings that are read
 *     from the stylesheet, from ICU and from the user, and each of which had
 *     a half that nothing read.
 *
 * ## Measured, not asserted, wherever a measurement exists
 *
 * Nothing here holds a copy of a colour or a size. Each theme is resolved
 * from `resources/css/app.css` the way the cascade resolves it, down to the
 * primitive's OKLCH triple, converted to the sRGB a screen shows and
 * measured with the WCAG 2.2 formula. The scrollbar thumb is composited over
 * the surface behind it at its own alpha, because a thumb at 70% is a
 * different colour from the token it is mixed from.
 *
 * The density and digit-shaping checks go further and RENDER: a page is
 * rendered through its own controller with a signed-in user, and the answer
 * is read out of the HTML. A source-level check on either would pass a
 * layout that writes the attribute and a preference resolver that never
 * returns anything but the default.
 */
final class DesignFoundationRules
{
    use Concerns\EntersBills;

    /** The five themes, by the exact selectors each one puts on `<html>`. */
    private const THEMES = [
        'light' => [[':root'], 4.5, 3.0],
        'graphite' => [[':root', '.dark'], 4.5, 3.0],
        'midnight' => [[':root', '.dark', "[data-theme='midnight']"], 4.5, 3.0],
        'hc-light' => [[':root', "[data-theme='hc-light']"], 7.0, 4.5],
        'hc-dark' => [[':root', '.dark', "[data-theme='hc-dark']"], 7.0, 4.5],
    ];

    /**
     * Every fill a focused control, or the box immediately around one, can
     * have. Declared rather than derived: the point of the list is that
     * adding a fill is a decision somebody writes down, and WCAG 1.4.11
     * asks about the colours ADJACENT to the indicator - which are the
     * control's own fill and whatever it sits on.
     */
    private const FOCUSABLE_FILLS = [
        'background', 'surface', 'surface-raised', 'surface-sunken', 'surface-hover',
        'surface-selected', 'surface-container', 'surface-container-high', 'surface-container-highest',
        'primary', 'primary-hover', 'primary-subtle', 'tertiary', 'tertiary-subtle',
        'danger', 'danger-subtle', 'warning', 'warning-subtle', 'success', 'success-subtle',
        'insight', 'insight-subtle', 'sidebar', 'sidebar-raised', 'sidebar-selected',
        'brand-cta-start', 'brand-cta-end',
    ];

    /** The surfaces a scrollbar's track can sit on, and the token of each scroller's thumb. */
    private const SCROLLERS = [
        'the page shell' => ['background', '--k-scrollbar-thumb'],
        'a card or a dialog' => ['surface', '--k-scrollbar-thumb'],
        'the data grid' => ['surface-sunken', '--k-scrollbar-thumb'],
        'the sidebar' => ['sidebar', '--k-scrollbar-thumb-sidebar'],
    ];

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
            // PF-199 - the token architecture
            'a component token never reads a primitive' => $this->layersAreRespected($css),
            'every colour is an OKLCH triple, and no rule writes one as hex, rgb or hsl' => $this->coloursAreOklch($css),
            'every role is mapped into @theme exactly once' => $this->everyRoleIsMappedOnce($css),
            'no template themes itself with a dark: colour variant' => $this->nothingThemesAtTheCallSite(),
            'a token that does not invert says so, with a reason' => $this->nonInvertingTokensAreDocumented($css),
            'every token belongs to a stated role family, named for the role and not a colour' => $this->everyTokenHasAStatedRole($css),

            // PF-200 - the contrast matrix
            'the focus indicator is identifiable on every fill it can land on' => $this->focusIndicatorIsIdentifiable($css),
            'nothing eats the focus halo' => $this->nothingEatsTheHalo(),
            'every foreground and background written onto one element is a declared pair' => $this->renderedPairsAreDeclared($css),
            'a row\'s three states are three, and the selected one survives losing colour' => $this->rowStatesStayThree($css),

            // PF-277 - the spacing scale
            'every space on every page is a step of the declared scale' => $this->spacingIsOnTheScale($css),

            // PF-201 - typography
            'no font is fetched from a third party at runtime' => $this->fontsAreNotFetchedAtRuntime($css),
            'the figure face is a face with real tabular figures, and the fallbacks carry Arabic' => $this->figuresAreSetForFigures($css),
            'every type step has a line height, and the text steps keep Arabic\'s headroom' => $this->typeScaleHasArabicHeadroom($css),
            'a display size is only ever used on a figure' => $this->displaySizesAreFiguresOnly(),
            'no icon font, so no page can show the ligature word' => $this->noIconFont($css),

            // PF-202 - digit shaping
            'the digit shape is a per-tenant setting of latn or arab, latn by default' => $this->digitShapeIsATenantSetting(),
            'nothing shapes a digit by string replacement' => $this->noStringReplacedDigits(),
            'the shape reaches the formatter through ICU, and round-trips' => $this->shapedFiguresRoundTrip(),
            'what leaves the product is Western digits whatever the tenant chose' => $this->whatLeavesIsAlwaysWestern(),

            // PF-203 - density
            'three density modes, each defining every density token' => $this->threeDensityModes($css),
            'density is a user setting, never a breakpoint' => $this->densityIsNotABreakpoint($css),
            'every mode clears the 24px target minimum' => $this->everyModeClearsTheTargetMinimum($css),
            'a row takes its height and padding from the density tokens' => $this->rowsReadTheDensityTokens(),

            // The scrollbars - PF-199 and PF-203
            'both scrollbar syntaxes read the same token' => $this->scrollbarSyntaxesAgree($css),
            'a scrollbar is visible in every theme, on every surface it sits on' => $this->scrollbarsAreVisible($css),
            'every scroller keeps a stable gutter, on the side the writing direction puts it' => $this->scrollersKeepTheirGutter($css),
            'nothing animates a scrollbar' => $this->nothingAnimatesAScrollbar($css),
        ]
            // The rendered half: the page in its density, with its digits.
            + $this->rendered();
    }

    /* =======================================================================
     |  PF-199 - the token architecture
     * ==================================================================== */

    /**
     * The rule the architecture rests on - `design-system.md`: a component
     * never references a primitive.
     *
     * A component that reaches past the roles cannot be re-themed, and the
     * theme it breaks is whichever one nobody opened. The layers and what
     * each may read are declared in `token-architecture.json`, because a
     * rule about layers needs the layers to be tellable apart by name.
     *
     * @return list<string>
     */
    public function layersAreRespected(string $css): array
    {
        $manifest = $this->manifest();
        $violations = [];

        foreach (self::blocks($css) as $block) {
            foreach ($block['declarations'] as $name => $value) {
                $layer = $this->layerOf($name, $manifest);

                if ($layer === null) {
                    continue;
                }

                $allowed = $manifest['layers'][$layer]['may_read'];

                foreach (self::referencedProperties($value) as $referenced) {
                    $referencedLayer = $this->prefixOf($referenced, $manifest);

                    if ($referencedLayer === null || \in_array($referencedLayer, $allowed, true)) {
                        continue;
                    }

                    $violations[] = \sprintf(
                        'app.css: the %s token %s reads %s, which is the %s layer. %s may read: %s.',
                        $layer,
                        $name,
                        $referenced,
                        $this->layerNameOf($referencedLayer, $manifest),
                        $layer,
                        implode(', ', $allowed) ?: '(nothing)',
                    );
                }
            }
        }

        // And the other direction: a component the manifest names must exist.
        foreach ($manifest['components'] as $component => $declared) {
            foreach ($declared['tokens'] as $token) {
                if (! str_contains($css, $token.':')) {
                    $violations[] = \sprintf(
                        'token-architecture.json says the %s component owns %s, which app.css does not define.',
                        $component,
                        $token,
                    );
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * OKLCH, because it is perceptually uniform: a ramp stepped by lightness
     * looks evenly stepped, and a contrast target is hit by adjusting one
     * number instead of by trial and error. A hex or an `hsl()` in a rule is
     * a colour outside the ramps, which means outside the measurement.
     *
     * @return list<string>
     */
    public function coloursAreOklch(string $css): array
    {
        $manifest = $this->manifest();
        $violations = [];

        foreach (self::blocks($css) as $block) {
            if (isset($manifest['literal_colour_exceptions'][$block['selector']])) {
                continue;
            }

            foreach ($block['declarations'] as $name => $value) {
                // A system colour keyword in the forced-colours block is the
                // point of that block, and `transparent` is not a colour.
                if (preg_match('/(?<![\w-])(#[0-9a-f]{3,8}|(?:rgba?|hsla?)\()/i', $value, $m) !== 1) {
                    continue;
                }

                $violations[] = \sprintf(
                    'app.css: "%s" in "%s" writes a colour as %s. Every colour is an OKLCH ramp step read '.
                    'through a role; a literal is outside the ramps and therefore outside the contrast matrix.',
                    $name,
                    $block['selector'],
                    $m[1],
                );
            }
        }

        foreach ($manifest['literal_colour_exceptions'] as $selector => $reason) {
            if (! str_contains(self::withoutComments($css), $selector)) {
                $violations[] = \sprintf(
                    'token-architecture.json excuses a literal colour in "%s", which app.css no longer has. '.
                    'Remove the exception - an exception nobody needs is an exception nobody reads.',
                    $selector,
                );
            }
        }

        return $violations;
    }

    /**
     * Mapped ONCE inside `@theme`, which is what makes `text-primary` follow
     * the theme with no `dark:` at the call site. A role with no mapping has
     * no utility, so the call site reaches for a primitive or a palette
     * colour instead; a role mapped twice is two utilities that can disagree.
     *
     * @return list<string>
     */
    public function everyRoleIsMappedOnce(string $css): array
    {
        $theme = self::atTheme($css);
        $mapped = [];

        if (preg_match_all('/--color-([\w-]+):\s*oklch\(var\((--c-[\w-]+)\)[^;]*\)/', $theme, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $utility, $role]) {
                $mapped[$role][] = $utility;
            }
        }

        $violations = [];

        foreach (array_keys(self::rolesIn(self::blocks($css), ':root')) as $role) {
            if (! isset($mapped[$role])) {
                $violations[] = \sprintf(
                    '%s is defined by every theme and mapped by none, so there is no utility for it and a '.
                    'call site has to reach past the roles to use it.',
                    $role,
                );

                continue;
            }

            if (\count($mapped[$role]) > 1) {
                $violations[] = \sprintf(
                    '%s is mapped %d times in @theme (%s). Two utilities for one role can disagree.',
                    $role,
                    \count($mapped[$role]),
                    implode(', ', $mapped[$role]),
                );
            }
        }

        return $violations;
    }

    /**
     * The payoff of the mapping, stated as a prohibition: with the roles
     * following the theme, a `dark:` colour utility at the call site is a
     * second theme written by hand - and it can only ever know about two of
     * the five. The sibling project's 1.62:1 status pill was exactly a
     * `dark:` variant that did nothing.
     *
     * @return list<string>
     */
    public function nothingThemesAtTheCallSite(): array
    {
        $colourUtilities = 'bg|text|border|outline|ring|fill|stroke|divide|decoration|shadow|from|via|to|accent|caret|placeholder';
        $violations = [];

        foreach ($this->files('resources/views', '.blade.php') as $file) {
            $source = (string) file_get_contents($file);

            if (! preg_match_all('/(?<![\w-])dark:('.$colourUtilities.')-[\w\[\]().\/-]+/', $source, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($m[0] as [$hit, $offset]) {
                $violations[] = \sprintf(
                    '%s:%d uses "%s". The role follows the theme on its own; a dark: colour at the call site '.
                    'is a hand-written theme that knows about two of the five.',
                    $this->relative($file),
                    substr_count(substr($source, 0, $offset), "\n") + 1,
                    $hit,
                );
            }
        }

        return $violations;
    }

    /**
     * PF-199: a token that deliberately does not invert is documented as
     * such, with the reason.
     *
     * "Does not invert" is measured rather than asserted - the light theme's
     * resolved colour against Graphite's, which is the inversion that
     * matters; the high-contrast themes are allowed to move anything, since
     * choosing them is choosing different colours.
     *
     * Both directions, because each catches a different mistake: an
     * undocumented one is a colour nobody chose for the dark theme, and a
     * documented one that now inverts is a reason that has outlived the
     * decision it explains.
     *
     * @return list<string>
     */
    public function nonInvertingTokensAreDocumented(string $css): array
    {
        $blocks = self::blocks($css);
        $light = self::resolve($blocks, [':root']);
        $dark = self::resolve($blocks, [':root', '.dark']);
        $declared = $this->manifest()['non_inverting'];
        $violations = [];
        $fixed = [];

        foreach (array_keys(self::rolesIn($blocks, ':root')) as $role) {
            $name = substr($role, 4);

            try {
                [$inLight] = self::colour($light, $role);
                [$inDark] = self::colour($dark, $role);
            } catch (RuntimeException $e) {
                $violations[] = $e->getMessage();

                continue;
            }

            if ($inLight !== $inDark) {
                continue;
            }

            $fixed[$name] = true;

            if (! isset($declared[$name]) || trim($declared[$name]) === '') {
                $violations[] = \sprintf(
                    '%s is %s in the light theme AND in Graphite, so it does not invert - and '.
                    'token-architecture.json does not say why. A token that silently does not invert is one '.
                    'somebody will "fix" with a dark value, in the theme they happened to be looking at.',
                    $role,
                    $inLight,
                );
            }
        }

        foreach (array_keys($declared) as $name) {
            if (! isset($fixed[$name])) {
                $violations[] = \sprintf(
                    'token-architecture.json says --c-%s does not invert, and it does: the light theme and '.
                    'Graphite now resolve it differently. Either the change was not meant, or the reason has '.
                    'outlived the decision and should go.',
                    $name,
                );
            }
        }

        return $violations;
    }

    /**
     * PF-199: a token may only be added with a stated role, and a token
     * named after a colour rather than a role fails review.
     *
     * Enforced through role FAMILIES rather than one sentence per token: 67
     * sentences would be 67 things to keep true, and the decision that
     * actually needs writing down is the family - `--c-insight` means "what
     * the machine proposed", and every `-subtle`, `on-` and `-hover` of it
     * follows from that. A new family is a visible line in a diff.
     *
     * @return list<string>
     */
    public function everyTokenHasAStatedRole(string $css): array
    {
        $manifest = $this->manifest();
        $families = $manifest['role_families'];
        $violations = [];
        $used = [];

        $modifiers = [
            '', 'hover', 'subtle', 'strong', 'muted', 'control', 'selected', 'raised', 'sunken',
            'negative', 'start', 'end', 'accent', 'ring', 'halo', 'low', 'high', 'lowest', 'highest',
            'container', 'container-low', 'container-high', 'container-lowest', 'container-highest',
        ];

        foreach (array_keys(self::rolesIn(self::blocks($css), ':root')) as $role) {
            $name = substr($role, 4);

            foreach ($manifest['colour_words'] as $word) {
                if (preg_match('/(?<![a-z])'.preg_quote($word, '/').'(?![a-z])/', $name) === 1) {
                    $violations[] = \sprintf(
                        '%s is named after a colour ("%s"). A colour cannot be re-themed and a role can: '.
                        '--c-danger survives a palette change and --c-red does not.',
                        $role,
                        $word,
                    );
                }
            }

            // The longest declared family this token belongs to, plus one
            // modifier - which is how the families are meant to compose.
            $matched = null;

            foreach (array_keys($families) as $family) {
                if ($name !== $family && ! str_starts_with($name, $family.'-')) {
                    continue;
                }

                $modifier = $name === $family ? '' : substr($name, \strlen($family) + 1);

                if (! \in_array($modifier, $modifiers, true)) {
                    continue;
                }

                if ($matched === null || \strlen($family) > \strlen($matched)) {
                    $matched = $family;
                }
            }

            if ($matched === null) {
                $violations[] = \sprintf(
                    '%s belongs to no role family in token-architecture.json. State the role it plays - a '.
                    'token added without one is a token the next person guesses at.',
                    $role,
                );

                continue;
            }

            $used[$matched] = true;

            if (trim($families[$matched]) === '') {
                $violations[] = \sprintf('the role family "%s" is declared with an empty role.', $matched);
            }
        }

        foreach (array_keys($families) as $family) {
            if (! isset($used[$family])) {
                $violations[] = \sprintf(
                    'token-architecture.json states a role for "%s", which app.css defines no token for. A '.
                    'family nothing uses is a role statement nobody will notice has gone stale.',
                    $family,
                );
            }
        }

        return $violations;
    }

    /* =======================================================================
     |  PF-200 - the contrast matrix
     * ==================================================================== */

    /**
     * The steps the product uses, and nothing else - PF-277.
     *
     * Drawn from what was already there rather than invented: a scale
     * designed in the abstract and then imposed generates a hundred
     * exceptions on its first run, and a hundred exceptions is no scale.
     */
    private const SPACING_STEPS = [
        '0', '0.5', '1', '1.5', '2', '2.5', '3', '4', '5', '6', '8', '10', '12',
    ];

    /**
     * Steps off the scale that are allowed, each with the reason.
     *
     * The list is the point of the check as much as the scale is. A design
     * system with silent exceptions is one nobody trusts - the next person
     * cannot tell a decision from a slip, so they copy both.
     *
     * Keyed by `file:class`, so an exception is granted to one use and not
     * to a step everywhere.
     */
    private const SPACING_EXCEPTIONS = [
        'resources/views/app/ledger/trial-balance.blade.php:ms-9' =>
            'one rung of the account tree\'s indent ladder - ms-0, ms-3, ms-6, ms-9, ms-12 - which is '.
            'regular at a step of 3 and has to stay regular, because the indent is what says which '.
            'account is inside which',
        'resources/views/layouts/plain.blade.php:py-16' =>
            'the vertical breathing room of a page with one card centred in it - sign in, an error, a '.
            'confirmation - where the space IS the layout rather than a gap between two things',
    ];

    /**
     * Every spacing class in every template is a step of the scale - PF-277.
     *
     * ## What it refuses, and what it cannot
     *
     * An arbitrary value (`mt-[26px]`) and a step outside the list. It
     * reads the templates rather than a rendered page, which is the weaker
     * of the two measurements the task asks for - a page that reaches the
     * right spacing through three overrides still passes here. The computed
     * form needs a browser and is its own criterion.
     *
     * What this half does catch is the failure the task actually describes:
     * one `mt-7` among the `mt-6`s, invisible to its author and obvious on
     * the page beside it. That is a class, and a class is readable here.
     *
     * @return list<string>
     */
    public function spacingIsOnTheScale(string $css): array
    {
        if (! str_contains($css, '--spacing:')) {
            return ['the stylesheet declares no `--spacing` unit, so the scale is whatever Tailwind\'s '.
                'default arithmetic allows and nothing says which steps the product uses'];
        }

        $violations = [];
        $properties = 'mt|mb|ms|me|mx|my|pt|pb|ps|pe|px|py|gap-x|gap-y|gap|space-x|space-y|p|m';
        $files = $this->templates();

        if ($files === []) {
            return ['no templates were found at all, so this check just asserted something about an '.
                'empty list'];
        }

        foreach ($files as $file) {
            $relative = $this->relative($file);
            $source = (string) file_get_contents($file);

            // An arbitrary value is off the scale by construction.
            if (preg_match_all('/\b(?:'.$properties.')-\[([^\]]+)\]/', $source, $arbitrary) > 0) {
                foreach ($arbitrary[0] as $class) {
                    $violations[] = \sprintf(
                        '%s writes "%s". A space given as a literal is a space that belongs to one page '.
                        'and drifts from every other, and it is the one somebody later matches by eye',
                        $relative,
                        $class,
                    );
                }
            }

            if (preg_match_all('/\b((?:'.$properties.')-([0-9]+(?:\.[0-9]+)?))\b/', $source, $found, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($found as [, $class, $step]) {
                if (\in_array($step, self::SPACING_STEPS, true)) {
                    continue;
                }

                if (isset(self::SPACING_EXCEPTIONS[$relative.':'.$class])) {
                    continue;
                }

                $violations[] = \sprintf(
                    '%s writes "%s", and %s is not a step of the scale (%s). Either move it to the '.
                    'nearest step, or add it to SPACING_EXCEPTIONS with the reason - a design system '.
                    'with silent exceptions is one nobody trusts, because the next person cannot tell a '.
                    'decision from a slip and copies both',
                    $relative,
                    $class,
                    $step,
                    implode(', ', self::SPACING_STEPS),
                );
            }
        }

        // An exception granted to something that no longer exists is a
        // licence nobody revoked. It also makes the list read as longer
        // than the debt actually is.
        foreach (self::SPACING_EXCEPTIONS as $where => $reason) {
            [$file, $class] = explode(':', $where, 2);
            $path = $this->base.'/'.$file;

            if (! is_file($path) || ! preg_match('/\b'.preg_quote($class, '/').'\b/', (string) file_get_contents($path))) {
                $violations[] = \sprintf(
                    'the scale still grants "%s" an exception in %s, and it is not there any more. An '.
                    'exception nobody revoked is a licence for the next person who needs one',
                    $class,
                    $file,
                );
            }
        }

        return $violations;
    }

    /** Every Blade template in the product. @return list<string> */
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

    /**
     * Zebra, hover and selected are three states - PF-278.
     *
     * ## Why distinctness is the thing measured
     *
     * They answer different questions: which line am I on, which line is
     * the pointer over, which lines did I pick. Two of them rendering the
     * same tint is worse than having only one, because the person believes
     * the one they are reading - and in a payment run "which rows am I
     * about to pay" is not a question to be wrong about.
     *
     * The grid draws them from `surface-sunken`, `surface-hover` and
     * `surface-selected` over a row of `surface`, so this asserts all four
     * differ in every theme. It is an equality check rather than a contrast
     * one on purpose: a tint one step from its neighbour is a legitimate
     * design choice at 1.05:1, and a threshold here would either forbid
     * subtlety or pass two values that are literally the same.
     *
     * ## And the part that is not a colour
     *
     * A printed statement has no backgrounds and forced-colours mode
     * replaces every one of them, so a selection shown only as a tint
     * survives neither. The selected row carries a bar on its inline-start
     * edge, and this checks that the bar exists, that it is positioned
     * logically rather than with a physical offset, and that the
     * forced-colours block keeps it.
     *
     * @return list<string>
     */
    public function rowStatesStayThree(string $css): array
    {
        $blocks = self::blocks($css);
        $violations = [];

        $states = [
            'the row itself' => '--c-surface',
            'zebra' => '--c-surface-sunken',
            'hover' => '--c-surface-hover',
            'selected' => '--c-surface-selected',
        ];

        foreach (self::THEMES as $theme => [$selectors, , ]) {
            $tokens = self::resolve($blocks, $selectors);
            $seen = [];

            foreach ($states as $label => $role) {
                try {
                    [$hex] = self::colour($tokens, $role);
                } catch (RuntimeException $e) {
                    $violations[] = \sprintf('%s: %s', $theme, $e->getMessage());

                    continue 2;
                }

                if (isset($seen[$hex])) {
                    $violations[] = \sprintf(
                        '%s: %s and %s are both %s, so two of a row\'s states render identically. The '.
                        'person believes whichever one they are reading',
                        $theme,
                        $seen[$hex],
                        $label,
                        $hex,
                    );
                }

                $seen[$hex] = $label;
            }
        }

        // The mark that is not a colour.
        if (! str_contains($css, "tr[aria-selected='true'] > :first-child::before")) {
            $violations[] = 'the selected row has no mark other than its tint. A printed statement has no '.
                'backgrounds and forced-colours mode replaces every one of them, so a selection shown '.
                'only as a colour is a selection that survives neither';
        }

        if (preg_match('/tr\[aria-selected=.true.\] > :first-child::before\s*\{[^}]*inset-inline-start/s', $css) !== 1) {
            $violations[] = 'the selected row\'s mark is not placed with `inset-inline-start`, so it is '.
                'either on the wrong edge in Arabic or it is positioned with a physical offset - and a '.
                'selection bar down the far side of the row is a selection bar nobody reads as one';
        }

        $forced = self::forcedColoursBlock($css);

        if ($forced !== null && ! str_contains($forced, "tr[aria-selected='true'] > :first-child::before")) {
            $violations[] = 'the forced-colours block does not keep the selected row\'s mark. That is the '.
                'one mode where the tint is certainly gone, so it is the one mode where the mark has to '.
                'be there';
        }

        return $violations;
    }

    /** The one forced-colours block, or null if the stylesheet has none. */
    private static function forcedColoursBlock(string $css): ?string
    {
        $at = strpos($css, '@media (forced-colors: active)');

        if ($at === false) {
            return null;
        }

        $start = strpos($css, '{', $at);

        if ($start === false) {
            return null;
        }

        $depth = 0;

        for ($i = $start, $n = \strlen($css); $i < $n; $i++) {
            $depth += $css[$i] === '{' ? 1 : ($css[$i] === '}' ? -1 : 0);

            if ($depth === 0) {
                return substr($css, $start, $i - $start + 1);
            }
        }

        return null;
    }

    /**
     * WCAG 1.4.11 and 2.4.11: the focus indicator against the component AND
     * against what is adjacent to it.
     *
     * ## What this found
     *
     * A single-colour ring, measured against every fill rather than against
     * the page alone: in Graphite the ring and a primary button are both
     * Glacier Teal, so the indicator on the main action measured 1.00:1 -
     * you could not see where the focus was. Nine fills failed in the light
     * theme, twelve in high-contrast light.
     *
     * And a single colour cannot fix it: in the high-contrast themes the
     * constraint "4.5:1 from a near-white surface" and the constraint
     * "4.5:1 from a Glacier Teal fill" have an empty intersection. So the
     * indicator is two colours, and the rule is that for every fill at
     * least ONE of them clears the target, and the two clear it against each
     * other - which is what makes the indicator identifiable whatever is
     * underneath it.
     *
     * @return list<string>
     */
    public function focusIndicatorIsIdentifiable(string $css): array
    {
        $blocks = self::blocks($css);
        $violations = [];

        foreach (self::THEMES as $theme => [$selectors, , $target]) {
            $tokens = self::resolve($blocks, $selectors);

            try {
                [$ring] = self::colour($tokens, '--c-focus-ring');
                [$halo] = self::colour($tokens, '--c-focus-halo');
            } catch (RuntimeException $e) {
                $violations[] = \sprintf('%s: %s', $theme, $e->getMessage());

                continue;
            }

            $between = self::ratio($ring, $halo);

            if ($between + 1e-9 < $target) {
                $violations[] = \sprintf(
                    '%s: the two colours of the focus indicator are %.2f:1 apart (%s and %s) and need %s:1. '.
                    'Two colours that do not contrast with each other are one colour.',
                    $theme,
                    $between,
                    $ring,
                    $halo,
                    self::number($target),
                );
            }

            foreach (self::FOCUSABLE_FILLS as $fill) {
                try {
                    [$hex] = self::colour($tokens, '--c-'.$fill);
                } catch (RuntimeException $e) {
                    $violations[] = \sprintf('%s: %s', $theme, $e->getMessage());

                    continue;
                }

                $best = max(self::ratio($ring, $hex), self::ratio($halo, $hex));

                if ($best + 1e-9 < $target) {
                    $violations[] = \sprintf(
                        '%s: neither colour of the focus indicator reaches %s:1 against %s (%s) - the better '.
                        'of the two is %.2f:1. A control with that fill shows no indicator anybody can see.',
                        $theme,
                        self::number($target),
                        $fill,
                        $hex,
                        $best,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * The halo is drawn with `box-shadow`, so a `shadow-*` utility on the
     * same element replaces it and the indicator quietly goes back to one
     * colour. The rule in app.css is unlayered for exactly that reason;
     * this is the other half - a template must not then put the shadow
     * inside a `focus:`/`focus-visible:` variant, which WOULD win.
     *
     * `focus-within:` is deliberately NOT included, which the first draft of
     * this check got wrong: `focus-within:shadow-e2` lands on the ANCESTOR of
     * the focused control - the bento tile lifting when the link inside it is
     * focused - and the halo is on the control. Two different elements, no
     * conflict, and reporting it would have been a false refusal of a
     * perfectly good affordance.
     *
     * @return list<string>
     */
    public function nothingEatsTheHalo(): array
    {
        $violations = [];

        foreach ($this->files('resources/views', '.blade.php') as $file) {
            $source = (string) file_get_contents($file);

            if (! preg_match_all('/(?<![\w-])focus(?:-visible)?:(?:shadow|box-shadow)-[\w\[\]().-]+/', $source, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($m[0] as [$hit, $offset]) {
                $violations[] = \sprintf(
                    '%s:%d uses "%s", which replaces the focus halo on the element it focuses - leaving a '.
                    'one-colour indicator on exactly the control somebody is looking for.',
                    $this->relative($file),
                    substr_count(substr($source, 0, $offset), "\n") + 1,
                    $hit,
                );
            }
        }

        return $violations;
    }

    /**
     * PF-200: "a pair nobody measured is a pair that is wrong in one theme".
     *
     * The matrix measures DECLARED pairs, which leaves one question open:
     * are the pairs the product renders the pairs that were declared? This
     * harvests every foreground and background written into the same class
     * list in every template and asserts each one is in the matrix.
     *
     * ## What it measures, exactly
     *
     * One class list at a time. Variants are paired with their own kind - a
     * `hover:bg-*` with the element's text colour, a `file:bg-*` only with a
     * `file:text-*` - because the alternative pairs colours that never
     * appear together. Branches are not crossed: the two arms of a `@class`
     * ternary are separate class lists, which is how the first draft of this
     * check reported `on-sidebar-muted` on `sidebar-selected` at 2.50:1 for
     * a combination the markup cannot produce.
     *
     * A deprecated alias is resolved through the role it aliases, from the
     * stylesheet rather than from a list here: `--c-info: var(--c-insight)`
     * says they are the same colour, so measuring both would be measuring
     * one thing twice.
     *
     * @return list<string>
     */
    public function renderedPairsAreDeclared(string $css): array
    {
        $blocks = self::blocks($css);
        $roles = self::rolesIn($blocks, ':root');
        $utilities = self::themeUtilities($css);
        $declared = [];

        foreach ($this->declaredPairs() as [$foreground, $background]) {
            $declared[$this->canonicalRole($foreground, $roles).'|'.$this->canonicalRole($background, $roles)] = true;
        }

        $violations = [];

        foreach ($this->files('resources/views', '.blade.php') as $file) {
            $source = (string) file_get_contents($file);

            if (! preg_match_all('/<[a-zA-Z][^>]*>/s', $source, $tags, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($tags[0] as [$tag, $offset]) {
                foreach (self::classLists($tag) as $list) {
                    foreach (self::pairsIn($list, $utilities) as [$foreground, $background]) {
                        $key = $this->canonicalRole($foreground, $roles).'|'.$this->canonicalRole($background, $roles);

                        if (isset($declared[$key])) {
                            continue;
                        }

                        $violations[] = \sprintf(
                            '%s:%d renders text-%s on bg-%s, which contrast-pairs.json does not declare - so '.
                            'no theme has measured it. Declare it (and let the matrix measure it) or use a pair '.
                            'that is already measured.',
                            $this->relative($file),
                            substr_count(substr($source, 0, $offset), "\n") + 1,
                            $foreground,
                            $background,
                        );
                    }
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /* =======================================================================
     |  PF-201 - typography
     * ==================================================================== */

    /**
     * Nothing fetched from a third party at runtime, so an offline load
     * keeps its typography - and so no third party learns which pages a
     * customer's finance team opens.
     *
     * @return list<string>
     */
    public function fontsAreNotFetchedAtRuntime(string $css): array
    {
        $hosts = ['fonts.googleapis.com', 'fonts.gstatic.com', 'fonts.bunny.net', 'use.typekit.net', 'cdn.jsdelivr.net', 'fast.fonts.net', 'cloud.typography.com'];
        $violations = [];

        $files = [
            ...$this->files('resources/css', '.css'),
            ...$this->files('resources/views', '.blade.php'),
            ...$this->files('resources/js', '.js'),
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach ($hosts as $host) {
                if (str_contains($source, $host)) {
                    $violations[] = \sprintf(
                        '%s names %s. A face fetched at runtime is a face an offline load does not get, and a '.
                        'request that tells a third party which pages a finance team opens.',
                        $this->relative($file),
                        $host,
                    );
                }
            }
        }

        // An @font-face must point at a bundled file, never at a URL.
        if (preg_match_all('/@font-face\s*\{([^}]*)\}/', self::withoutComments($css), $m, PREG_SET_ORDER)) {
            foreach ($m as [, $body]) {
                if (preg_match('#url\(\s*[\'"]?(https?:)?//#i', $body) === 1) {
                    $violations[] = 'app.css has an @font-face whose src is an absolute URL. Vendor the file '.
                        'through the asset pipeline and reference it relatively.';
                }
            }
        }

        return $violations;
    }

    /**
     * PF-201: an Arabic face for the UI and a Latin face carrying the
     * numerals - and the figure face has to be one with real `tnum`.
     *
     * Measured in a browser rather than assumed, and the measurement is
     * recorded in app.css: with `tabular-nums` on, Plus Jakarta Sans set
     * "1111111" 131px wide and "8888888" 140px - the same as without it - so
     * a thousands column drifts. IBM Plex Sans has true tabular figures.
     * This holds the conclusion: the first family of `--font-figure` is one
     * of the faces that has them.
     *
     * The second half is the lesson of `1e57db0`: a stack's fallbacks are
     * NAMED Arabic faces, never the bare generic, or an Arabic interface is
     * rendered in whatever fontconfig substitutes.
     *
     * @return list<string>
     */
    public function figuresAreSetForFigures(string $css): array
    {
        $violations = [];
        $theme = self::atTheme($css);

        // Faces measured to have true tabular figures, with where it was measured.
        $tabular = ['IBM Plex Sans', 'IBM Plex Mono', 'IBM Plex Sans Arabic'];
        $arabic = ['Readex Pro', 'Noto Sans Arabic', 'Geeza Pro', 'Segoe UI', 'Tahoma', 'IBM Plex Sans Arabic'];

        foreach (['--font-sans', '--font-figure', '--font-mono'] as $stack) {
            if (preg_match('/'.preg_quote($stack, '/').':\s*([^;]+);/', $theme, $m) !== 1) {
                $violations[] = \sprintf('@theme no longer declares %s.', $stack);

                continue;
            }

            $families = array_map(
                static fn (string $family): string => trim(trim($family), "'\""),
                explode(',', (string) preg_replace('/\s+/', ' ', $m[1])),
            );

            if ($stack === '--font-figure' && ! \in_array($families[0] ?? '', $tabular, true)) {
                $violations[] = \sprintf(
                    '--font-figure is set in "%s" first, which is not one of the faces measured to have true '.
                    'tabular figures (%s). `tabular-nums` on a face without them does nothing, and the '.
                    'thousands place in a currency column stops lining up - silently.',
                    $families[0] ?? '(nothing)',
                    implode(', ', $tabular),
                );
            }

            if ($stack === '--font-mono') {
                continue; // a reference or a hash is Latin by construction
            }

            // The fallback before the generic must be an Arabic face.
            $generic = array_search('sans-serif', $families, true);

            if ($generic === false) {
                continue;
            }

            $before = \array_slice($families, 0, (int) $generic);

            if (array_intersect($before, $arabic) === []) {
                $violations[] = \sprintf(
                    '%s falls through to the generic sans with no named Arabic face before it. On Linux the '.
                    'generic resolves to a Latin-first face whose Arabic coverage is whatever fontconfig '.
                    'substitutes - which is how an Arabic interface ends up in a face nobody chose (1e57db0).',
                    $stack,
                );
            }
        }

        // Every figure hook sets both the face and tabular figures.
        $rules = self::innerRules($css);
        $figureRule = null;

        foreach ($rules as [$selector, $body]) {
            if (preg_match('/(^|,)\s*\.figure\s*(,|$)/', $selector) === 1) {
                $figureRule = [$selector, $body];
            }
        }

        if ($figureRule === null) {
            return [...$violations, 'app.css has no `.figure` rule at all, so nothing sets tabular figures.'];
        }

        [$selector, $body] = $figureRule;

        foreach (['font-variant-numeric: tabular-nums', 'var(--font-figure)'] as $needle) {
            if (! str_contains((string) preg_replace('/\s+/', ' ', $body), $needle)) {
                $violations[] = \sprintf('the `.figure` rule no longer sets %s.', $needle);
            }
        }

        foreach (['th[data-figure]', 'td[data-figure]'] as $hook) {
            if (! str_contains($selector, $hook)) {
                $violations[] = \sprintf(
                    'the `.figure` rule does not cover %s, so a figure COLUMN is not set in the figure face - '.
                    'and the grid marks its figure cells with that attribute, not with a class.',
                    $hook,
                );
            }
        }

        return $violations;
    }

    /**
     * A fluid scale with explicit steps, and a line height on every one of
     * them that accounts for Arabic: taller ascenders, deeper descenders,
     * and diacritics above and below the line that a scale tuned on Latin
     * crowds into the row above.
     *
     * The display steps are exempt from the headroom, and that exemption is
     * itself checked: they are for FIGURES, which are Latin digits with no
     * diacritics at all - so `displaySizesAreFiguresOnly()` proves the
     * exemption only applies where it was granted.
     *
     * @return list<string>
     */
    public function typeScaleHasArabicHeadroom(string $css): array
    {
        $theme = self::atTheme($css);
        $steps = [];

        if (preg_match_all('/--text-([\w-]+):\s*([^;]+);/', $theme, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $name, $value]) {
                if (str_ends_with($name, '--line-height')) {
                    $steps[substr($name, 0, -\strlen('--line-height'))]['leading'] = trim($value);

                    continue;
                }

                $steps[$name]['size'] = trim($value);
            }
        }

        if ($steps === []) {
            return ['@theme declares no --text-* steps at all.'];
        }

        $violations = [];
        $fluid = 0;

        foreach ($steps as $name => $step) {
            if (! isset($step['size'])) {
                $violations[] = \sprintf('--text-%s has a line height and no size.', $name);

                continue;
            }

            if (! isset($step['leading'])) {
                $violations[] = \sprintf(
                    '--text-%s declares no --line-height, so it inherits whatever the element had. Arabic '.
                    'needs the headroom stated per step, and a step without one is the step that clips a '.
                    'diacritic.',
                    $name,
                );

                continue;
            }

            if (str_contains($step['size'], 'clamp(')) {
                $fluid++;
            }

            $leading = (float) $step['leading'];
            $isDisplay = str_starts_with($name, 'display');
            $minimum = $isDisplay ? 1.1 : 1.35;

            if ($leading + 1e-9 < $minimum) {
                $violations[] = \sprintf(
                    '--text-%s has a line height of %s, under the %s this scale keeps for %s. Arabic\'s '.
                    'ascenders, descenders and diacritics need the room, and the symptom is a shadda touching '.
                    'the line above rather than a layout that looks wrong.',
                    $name,
                    $step['leading'],
                    self::number($minimum),
                    $isDisplay ? 'a figure-only display step' : 'a text step',
                );
            }
        }

        if ($fluid === 0) {
            $violations[] = 'no step of the type scale is fluid (a clamp()), so the display sizes are the same '.
                'on a phone and on a 27-inch monitor.';
        }

        return $violations;
    }

    /**
     * The display steps are tighter than Arabic can take, which is only
     * sound because they are for figures - Latin digits, `dir="ltr"`, no
     * diacritics. So a display size used on anything that is not a figure is
     * a line of Arabic set at 1.1.
     *
     * @return list<string>
     */
    public function displaySizesAreFiguresOnly(): array
    {
        $violations = [];

        foreach ($this->files('resources/views', '.blade.php') as $file) {
            $source = (string) file_get_contents($file);
            $relative = $this->relative($file);

            if (! preg_match_all('/<[a-zA-Z][^>]*>/s', $source, $tags, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($tags[0] as [$tag, $offset]) {
                foreach (self::classLists($tag) as $list) {
                    if (preg_match('/(?<![\w-])(?:[\w\[\]&>:.\'=-]+:)*text-display(?:-xl)?(?![\w-])/', $list) !== 1) {
                        continue;
                    }

                    // The figure hook is on the same element, or the element
                    // is the figure component's own root.
                    if (preg_match('/(?<![\w-])figure(?![\w-])|data-figure/', $tag) === 1
                        || str_contains($relative, 'components/figure/')) {
                        continue;
                    }

                    $violations[] = \sprintf(
                        '%s:%d sets a display type size on an element that is not a figure. The display steps '.
                        'have a line height of 1.1-1.15, which is tight for Latin digits and crops Arabic.',
                        $relative,
                        substr_count(substr($source, 0, $offset), "\n") + 1,
                    );
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * PF-201: "icon fonts, if used at all, are hidden until loaded so the
     * page never shows the literal ligature word".
     *
     * The decision taken instead was no icon font at all - inline SVG, which
     * inherits `currentColor` and cannot arrive late. That is a stronger
     * guarantee than hiding one, so what this holds is the decision: no icon
     * face is declared or named anywhere, and the icon component really does
     * render an SVG.
     *
     * @return list<string>
     */
    public function noIconFont(string $css): array
    {
        $families = ['material-icons', 'Material Icons', 'Material Symbols', 'FontAwesome', 'Font Awesome',
            'Glyphicons', 'icomoon', 'IcoMoon', 'Ionicons', 'themify', 'fa-solid', 'fa-regular', 'bi-'];
        $violations = [];

        $files = [
            ...$this->files('resources/css', '.css'),
            ...$this->files('resources/views', '.blade.php'),
            ...$this->files('resources/js', '.js'),
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach ($families as $family) {
                if (str_contains($source, $family)) {
                    $violations[] = \sprintf(
                        '%s names the icon font "%s". An icon font renders the literal ligature word - or a '.
                        'missing-glyph box - until it loads, and that looks like a bug in the product rather '.
                        'than a slow request.',
                        $this->relative($file),
                        $family,
                    );
                }
            }
        }

        $icon = $this->read('resources/views/components/icon.blade.php');

        foreach (['<svg', 'aria-hidden="true"', 'focusable="false"', 'currentColor'] as $needle) {
            if (! str_contains($icon, $needle)) {
                $violations[] = \sprintf('components/icon.blade.php no longer contains %s.', $needle);
            }
        }

        unset($css);

        return $violations;
    }

    /* =======================================================================
     |  PF-202 - digit shaping
     * ==================================================================== */

    /**
     * PF-202: the digit shape is a per-tenant setting of `latn` or `arab`,
     * with `latn` the default - because financial documents in the region
     * conventionally use Western digits even in Arabic text, and the setting
     * exists because institutional convention differs.
     *
     * @return list<string>
     */
    public function digitShapeIsATenantSetting(): array
    {
        if (! SettingsRegistry::has('display.digits')) {
            return ['there is no "display.digits" setting, so the digit shape is a platform default and a '.
                'tenant whose institution writes Arabic-Indic digits has nowhere to say so.'];
        }

        $definition = SettingsRegistry::get('display.digits');
        $violations = [];

        if ($definition->scope !== 'tenant') {
            $violations[] = \sprintf(
                '"display.digits" is scoped "%s". The digit shape is an institutional convention, not a '.
                'per-company one, so it belongs to the tenant.',
                $definition->scope,
            );
        }

        if ($definition->default !== 'latn') {
            $violations[] = \sprintf(
                '"display.digits" defaults to %s. The regional convention for a financial document is Western '.
                'digits even in Arabic text, so latn is the default and arab is the departure.',
                var_export($definition->default, true),
            );
        }

        $allowed = $definition->allowed ?? [];
        sort($allowed);

        if ($allowed !== ['arab', 'latn']) {
            $violations[] = \sprintf(
                '"display.digits" permits %s. PF-202 names exactly latn and arab: `arabext` is the Persian and '.
                'Urdu form, and a Saudi statement rendered in it is wrong in a way that reads as a font '.
                'problem, so nobody files it as a data bug.',
                $allowed === [] ? 'anything at all' : implode(' / ', $allowed),
            );
        }

        return $violations;
    }

    /**
     * SHAPED through ICU, never by string replacement.
     *
     * A replacement table is wrong in ways ICU is not: it shapes the digits
     * in a reference number and an IBAN as well, it does not touch the
     * decimal separator or the grouping separator, and it is applied by
     * whoever remembers to call it.
     *
     * ## Only the shaping direction
     *
     * The first draft of this check refused any file holding all ten
     * Arabic-Indic digits, and refused two that are right: `Money` turns what
     * somebody TYPED into something `BigDecimal` accepts, and
     * `RestrictedLatin` transliterates a trade name for a scheme whose digit
     * class is Latin. Both map non-Latin TO Latin, which is normalisation of
     * input and is required - a form that will not accept the digits a
     * keyboard produces is a form nobody can use.
     *
     * So the direction is what is refused: a non-Latin digit on the OUTPUT
     * side of a replacement - the value of a map whose key is a Latin digit,
     * or the replacement argument of a replace call.
     *
     * @return list<string>
     */
    public function noStringReplacedDigits(): array
    {
        $violations = [];
        $nonLatin = '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹';
        $class = '['.$nonLatin.']';

        $files = [...$this->phpFiles('app'), ...$this->files('resources/js', '.js'), ...$this->files('resources/views', '.blade.php')];

        foreach ($files as $file) {
            $source = self::withoutPhpComments((string) file_get_contents($file));

            if (preg_match('/[\'"][0-9][\'"]\s*(?:=>|:)\s*[\'"]'.$class.'/u', $source, $m) === 1) {
                $violations[] = \sprintf(
                    '%s maps a Latin digit to "%s" - a shaping table. Digit shaping goes through ICU '.
                    '(App\\Support\\Lang\\NumberingSystem): a replacement shapes the digits in an IBAN and a '.
                    'document number too, and leaves the separators Latin.',
                    $this->relative($file),
                    $m[0],
                );
            }

            // The replacement argument of a replace call: str_replace(from,
            // TO, subject), and `.replace(from, TO)` in a script.
            $calls = '/(?:str_i?replace|preg_replace|\.replace)\s*\(([^;]{0,400}?)\)\s*[;,)]/su';

            if (preg_match_all($calls, $source, $m, PREG_SET_ORDER)) {
                foreach ($m as [, $arguments]) {
                    $parts = self::topLevelArguments($arguments);

                    foreach (\array_slice($parts, 1, 1) as $replacement) {
                        if (preg_match('/'.$class.'/u', $replacement) === 1) {
                            $violations[] = \sprintf(
                                '%s replaces something WITH a non-Latin digit ("%s"), which is shaping by string '.
                                'replacement. The shape is ICU\'s to apply, from the locale\'s numbering system.',
                                $this->relative($file),
                                trim(\Illuminate\Support\Str::limit($replacement, 60)),
                            );
                        }
                    }
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * An argument list split on its top-level commas.
     *
     * @return list<string>
     */
    private static function topLevelArguments(string $arguments): array
    {
        $parts = [];
        $depth = 0;
        $buffer = '';
        $quote = null;

        foreach (str_split($arguments) as $char) {
            if ($quote !== null) {
                $buffer .= $char;

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        return $parts;
    }

    /**
     * The shape reaches the formatter, and a shaped figure reads back.
     *
     * Behavioural: the setting's value is pushed through the one place that
     * knows about `-u-nu-` and the result is measured in the output, because
     * a source-level check cannot tell a locale tag that carries the
     * numbering system from one that was built and dropped.
     *
     * The round trip is the half that matters for a form: a page shows what
     * `format()` produced and takes back what the user typed over it, so a
     * display the parser cannot read is a form that refuses its own output.
     *
     * @return list<string>
     */
    public function shapedFiguresRoundTrip(): array
    {
        $violations = [];
        $original = config('money.display.digits');

        try {
            $amount = Money::of('1234.56', 'SAR');

            config(['money.display.digits' => 'latn']);
            $western = $amount->format('ar', false);

            config(['money.display.digits' => 'arab']);
            $shaped = $amount->format('ar', false);

            if (preg_match('/[0-9]/', $shaped) === 1) {
                $violations[] = \sprintf(
                    'with the tenant setting at arab, a figure formatted as "%s" still contains Western digits, '.
                    'so the setting does not reach ICU.',
                    $shaped,
                );
            }

            if (preg_match('/[\x{0660}-\x{0669}]/u', $shaped) !== 1) {
                $violations[] = \sprintf(
                    'with the tenant setting at arab, a figure formatted as "%s" carries no Arabic-Indic digit. '.
                    'Either the subtag is not being appended or it is being appended to a locale that already '.
                    'carried one.',
                    $shaped,
                );
            }

            if (preg_match('/[\x{0660}-\x{0669}]/u', $western) === 1) {
                $violations[] = \sprintf(
                    'with the tenant setting at latn, a figure formatted as "%s" is in Arabic-Indic digits. '.
                    'The latn subtag has to be written out: NumberFormatter("ar") renders 1234.56 as '.
                    'Arabic-Indic, so a configuration that says latn and a bare tag disagree.',
                    $western,
                );
            }

            // The round trip, under the shaped locale.
            $read = Money::parse($shaped, 'SAR', NumberingSystem::apply('ar', 'arab'));

            if ($read === null) {
                $violations[] = \sprintf(
                    'an Arabic-shaped figure ("%s") does not parse back at all. A form shows what format() '.
                    'produced and takes back what the user typed over it.',
                    $shaped,
                );
            } elseif ($read->toPresentation()->compareTo($amount->toPresentation()) !== 0) {
                $violations[] = \sprintf(
                    'an Arabic-shaped figure ("%s") read back as %s rather than %s.',
                    $shaped,
                    (string) $read->toPresentation(),
                    (string) $amount->toPresentation(),
                );
            }
        } catch (Throwable $e) {
            $violations[] = 'the digit-shaping check could not run: '.$e->getMessage();
        } finally {
            config(['money.display.digits' => $original]);
        }

        return $violations;
    }

    /**
     * Display only: stored values, exports, XML payloads and API responses
     * are always Western digits.
     *
     * Enforced at the seam rather than by inspection - the shape is applied
     * by a middleware in the WEB group, so nothing that is not a rendered
     * page can see it - and then measured: with the setting at `arab`, the
     * canonical JSON of an amount, its wire form and its stored string are
     * all still Latin.
     *
     * @return list<string>
     */
    public function whatLeavesIsAlwaysWestern(): array
    {
        $violations = [];
        $original = config('money.display.digits');

        try {
            config(['money.display.digits' => 'arab']);
            $amount = Money::of('1234.56', 'SAR');

            $leaving = [
                'the stored string' => (string) $amount->toPresentation(),
                'the wire form' => json_encode($amount->jsonSerialize(), JSON_THROW_ON_ERROR),
                'the amount itself' => (string) $amount,
            ];

            foreach ($leaving as $what => $value) {
                if (preg_match('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $value) === 1) {
                    $violations[] = \sprintf(
                        'with the tenant setting at arab, %s is "%s" - shaped digits in something that leaves '.
                        'the product. A consumer parsing that gets nothing, and an XML payload carrying it is '.
                        'invalid against its schema.',
                        $what,
                        $value,
                    );
                }
            }
        } catch (Throwable $e) {
            $violations[] = 'the export-shape check could not run: '.$e->getMessage();
        } finally {
            config(['money.display.digits' => $original]);
        }

        // And the seam itself: the shape is applied where HTML is rendered.
        $middleware = 'app/Http/Middleware/ApplyDisplayDigits.php';

        if (! is_file($this->base.'/'.$middleware)) {
            $violations[] = $middleware.' does not exist, so nothing applies the tenant\'s digit shape.';

            return $violations;
        }

        $bootstrap = $this->read('bootstrap/app.php');

        if (preg_match('/->web\(\s*append:\s*\[[^\]]*ApplyDisplayDigits::class/s', $bootstrap) !== 1) {
            $violations[] = 'ApplyDisplayDigits is not appended to the WEB middleware group in bootstrap/app.php. '.
                'The group is the seam that makes shaping display-only: registered globally or in the api '.
                'group it would shape an API response too.';
        }

        if (str_contains($bootstrap, 'ApplyDisplayDigits::class')
            && preg_match('/->api\(|prepend\(ApplyDisplayDigits/', $bootstrap) === 1
            && preg_match('/->api\(\s*(?:prepend|append):\s*\[[^\]]*ApplyDisplayDigits/s', $bootstrap) === 1) {
            $violations[] = 'ApplyDisplayDigits is registered in the api middleware group, so an API response '.
                'would carry shaped digits.';
        }

        return $violations;
    }

    /* =======================================================================
     |  PF-203 - density
     * ==================================================================== */

    /**
     * Three modes - comfortable, compact, dense - changing row height, font
     * step and padding through tokens alone.
     *
     * Each mode must define EVERY density token. A mode that defines four of
     * five inherits the fifth from the default, which is the one combination
     * nobody designed: `dense` rows at the compact font step.
     *
     * @return list<string>
     */
    public function threeDensityModes(string $css): array
    {
        $modes = $this->densityModes($css);
        $violations = [];

        foreach (Density::cases() as $mode) {
            if (! isset($modes[$mode->value])) {
                $violations[] = \sprintf(
                    'app.css has no block for [data-density=\'%s\'], which Density::%s offers.',
                    $mode->value,
                    $mode->name,
                );
            }
        }

        if ($violations !== []) {
            return $violations;
        }

        $tokens = array_keys($modes[Density::Compact->value]);

        foreach ($modes as $mode => $declared) {
            foreach ($tokens as $token) {
                if (! isset($declared[$token])) {
                    $violations[] = \sprintf(
                        'the %s density does not set %s, so it inherits the default\'s - a combination nobody '.
                        'designed and nobody will see until a row is the wrong height on one setting.',
                        $mode,
                        $token,
                    );
                }
            }
        }

        // And the modes must actually differ: three names for one size is
        // a setting that appears to work.
        foreach (['--row-height', '--font-step', '--row-padding-block'] as $token) {
            $values = [];

            foreach ($modes as $declared) {
                $values[$declared[$token] ?? ''] = true;
            }

            if (\count($values) !== \count($modes)) {
                $violations[] = \sprintf(
                    'the three densities do not all differ in %s (%s). A setting whose modes are the same size '.
                    'is a setting that appears to work.',
                    $token,
                    implode(', ', array_keys($values)),
                );
            }
        }

        return $violations;
    }

    /**
     * A user setting, not a breakpoint: an accountant on a 27-inch monitor
     * wants 40 rows, and a default that serves one user serves neither.
     *
     * So no density token may be redefined inside a width media query - a
     * stylesheet that tightens the rows on a wide screen has taken the
     * decision away from the person who made it.
     *
     * @return list<string>
     */
    public function densityIsNotABreakpoint(string $css): array
    {
        $source = self::withoutComments($css);
        $violations = [];
        $tokens = array_keys($this->densityModes($css)[Density::Compact->value] ?? ['--row-height' => '']);

        if (preg_match_all('/@media\s*\(([^)]*)\)\s*\{/', $source, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $index => [$match, $offset]) {
                $feature = $m[1][$index][0];

                if (preg_match('/width|resolution|orientation|aspect-ratio/i', $feature) !== 1) {
                    continue;
                }

                $block = self::blockAt($source, $offset + \strlen($match) - 1);

                foreach ($tokens as $token) {
                    if (str_contains($block, $token.':')) {
                        $violations[] = \sprintf(
                            '@media (%s) sets %s. Density is the user\'s decision; a breakpoint that changes it '.
                            'is the stylesheet overruling them on the screen they chose it for.',
                            trim($feature),
                            $token,
                        );
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * WCAG 2.2 (2.5.8): 24x24 CSS pixels, in every mode including `dense` -
     * which is the constraint that bounds how dense `dense` can be.
     *
     * @return list<string>
     */
    public function everyModeClearsTheTargetMinimum(string $css): array
    {
        $violations = [];

        foreach ($this->densityModes($css) as $mode => $declared) {
            foreach (['--row-height', '--control-height'] as $token) {
                $value = $declared[$token] ?? null;

                if ($value === null) {
                    continue; // reported by threeDensityModes()
                }

                $pixels = self::pixels($value);

                if ($pixels === null) {
                    $violations[] = \sprintf('the %s density sets %s to "%s", which is not a length.', $mode, $token, $value);

                    continue;
                }

                if ($pixels + 1e-9 < 24.0) {
                    $violations[] = \sprintf(
                        'the %s density sets %s to %s (%.0fpx), under the 24x24 minimum of WCAG 2.5.8. That '.
                        'minimum is what bounds how dense dense can be.',
                        $mode,
                        $token,
                        $value,
                        $pixels,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Through tokens ALONE: a grid cell whose padding is a literal utility
     * is a cell the density setting does not reach, and the symptom is a
     * table that changes its row height and not its padding.
     *
     * @return list<string>
     */
    public function rowsReadTheDensityTokens(): array
    {
        $source = $this->read('resources/views/components/grid.blade.php');
        $violations = [];

        foreach (['--row-padding-inline', '--row-padding-block'] as $token) {
            if (! str_contains($source, $token)) {
                $violations[] = \sprintf('the grid no longer reads %s, so its cells do not follow the density.', $token);
            }
        }

        // A literal padding utility on a cell - the thing that silently
        // overrides the token beside it.
        //
        // A cell with a `colspan` is exempt and that is not a loophole: it
        // spans the table rather than sitting in a column, so it is an empty
        // state or a banner, and the grid's empty state is deliberately
        // roomier than a row. The rule is about the cells a figure lands in.
        if (preg_match_all('/<(t[hd])\b[^>]*>/s', $source, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $match) {
                [$tag, $offset] = $match[0];

                if (str_contains($tag, 'colspan')) {
                    continue;
                }

                if (preg_match('/(?<![\w-])(?:[\w\[\]&>:.\'=-]+:)*p[xy]?-(?:\d+(?:\.\d+)?|px)(?![\w-])/', $tag, $hit) !== 1) {
                    continue;
                }

                $violations[] = \sprintf(
                    'grid.blade.php:%d sets "%s" on a <%s>, a literal padding the density tokens cannot reach. '.
                    'The row height would change with the setting and the padding would not.',
                    substr_count(substr($source, 0, $offset), "\n") + 1,
                    $hit[0],
                    $match[1][0],
                );
            }
        }

        return $violations;
    }

    /* =======================================================================
     |  The scrollbars - PF-199 and PF-203
     * ==================================================================== */

    /**
     * Two syntaxes, one token.
     *
     * `scrollbar-color`/`scrollbar-width` is the standard and is what Firefox
     * reads; `::-webkit-scrollbar` is what Chrome and Safari read. Written as
     * two sets of colours they drift, and the product looks like two
     * products to anybody who uses both browsers - while looking perfect to
     * whoever checked one.
     *
     * @return list<string>
     */
    public function scrollbarSyntaxesAgree(string $css): array
    {
        /*
         | The base rules only, with every `@media` region removed first.
         |
         | The forced-colours block repaints `::-webkit-scrollbar-thumb` in
         | `ButtonBorder` and sets `scrollbar-color: auto` on `*`, both on
         | purpose - and both under selectors identical to the base rules'.
         | A map keyed by selector therefore ends up holding the
         | forced-colours values, and the first draft of this check compared
         | the standard syntax against them and reported a disagreement that
         | was two correct rules in two different media.
         */
        $rules = [];

        foreach (self::innerRules(self::withoutMediaBlocks($css)) as [$selector, $body]) {
            $rules[trim($selector)] ??= (string) preg_replace('/\s+/', ' ', $body);
        }

        $violations = [];

        $standard = null;

        foreach ($rules as $selector => $body) {
            if ($standard === null && preg_match('/(?<![\w-])scrollbar-color:\s*([^;]+)/', $body, $m) === 1) {
                $standard = [$selector, trim($m[1])];
            }
        }

        if ($standard === null) {
            return ['app.css sets no scrollbar-color, so Firefox draws the operating system\'s own grey bar - '.
                'the one element on the page that ignores the theme the user chose.'];
        }

        [, $colours] = $standard;
        $parts = preg_split('/\s+(?=var|color-mix|transparent|oklch|#)/', $colours) ?: [];

        if (\count($parts) !== 2) {
            $violations[] = \sprintf('scrollbar-color is "%s", which is not a thumb and a track.', $colours);

            return $violations;
        }

        [$thumb, $track] = array_map('trim', $parts);
        $webkit = [
            '::-webkit-scrollbar-thumb' => $thumb,
            '::-webkit-scrollbar-track' => $track,
            '::-webkit-scrollbar-corner' => $track,
        ];

        foreach ($webkit as $selector => $expected) {
            if (! isset($rules[$selector])) {
                $violations[] = \sprintf(
                    'app.css styles the standard scrollbar and has no %s rule, so Chrome and Safari keep the '.
                    'operating system\'s bar while Firefox gets the themed one.',
                    $selector,
                );

                continue;
            }

            if (preg_match('/(?<![\w-])background(?:-color)?:\s*([^;]+)/', $rules[$selector], $m) !== 1) {
                $violations[] = \sprintf('%s sets no background.', $selector);

                continue;
            }

            if (trim($m[1]) !== $expected) {
                $violations[] = \sprintf(
                    '%s is painted "%s" while the standard syntax says "%s". Both syntaxes must read the SAME '.
                    'token, or the bar is one colour in Firefox and another in Chrome.',
                    $selector,
                    trim($m[1]),
                    $expected,
                );
            }
        }

        // The size: a keyword in one syntax and a length in the other, so
        // the length has to come from the component token either way.
        if (! str_contains($rules['::-webkit-scrollbar'] ?? '', 'var(--k-scrollbar-size)')) {
            $violations[] = '::-webkit-scrollbar does not take its size from --k-scrollbar-size, so the two '.
                'syntaxes can be given different widths.';
        }

        if (! str_contains($standard[1].($rules[$standard[0]] ?? ''), 'thin') && ! str_contains($rules[$standard[0]] ?? '', 'scrollbar-width: thin')) {
            $violations[] = 'the standard syntax does not ask for a thin scrollbar, so Firefox draws the full-'.
                'width bar beside Chrome\'s 10px one.';
        }

        return $violations;
    }

    /**
     * A scrollbar styled into invisibility is a worse product, not a cleaner
     * one - so the thumb is measured as the UI component it is: 3:1 against
     * the surface it sits on, 4.5:1 in the high-contrast themes.
     *
     * Composited, not compared: the thumb is a role mixed toward transparent,
     * so what the eye sees is that colour at 70% over the scroller's own
     * background. Comparing the token against the surface would measure a
     * colour nothing paints.
     *
     * @return list<string>
     */
    public function scrollbarsAreVisible(string $css): array
    {
        $blocks = self::blocks($css);
        $violations = [];

        foreach (self::THEMES as $theme => [$selectors, , $target]) {
            $tokens = self::resolve($blocks, $selectors);

            foreach (self::SCROLLERS as $where => [$surface, $token]) {
                try {
                    [$behind] = self::colour($tokens, '--c-'.$surface);
                } catch (RuntimeException $e) {
                    $violations[] = \sprintf('%s: %s', $theme, $e->getMessage());

                    continue;
                }

                $mix = $this->scrollbarThumb($css, $token);

                if ($mix === null) {
                    $violations[] = \sprintf('app.css does not define the thumb colour for %s (%s).', $where, $token);

                    continue;
                }

                [$role, $percentage] = $mix;

                try {
                    [$thumb] = self::colour($tokens, $role);
                } catch (RuntimeException $e) {
                    $violations[] = \sprintf('%s: %s', $theme, $e->getMessage());

                    continue;
                }

                $painted = self::over($thumb, $percentage / 100, $behind);
                $ratio = self::ratio($painted, $behind);

                if ($ratio + 1e-9 < $target) {
                    $violations[] = \sprintf(
                        '%s: the scrollbar of %s is %.2f:1 against %s (%s on %s) and needs %s:1. A bar nobody '.
                        'can find is a bar nobody can drag.',
                        $theme,
                        $where,
                        $ratio,
                        $surface,
                        $painted,
                        $behind,
                        self::number($target),
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Every element that scrolls keeps its gutter, and the gutter is the
     * browser's `stable` rather than a side.
     *
     * Without it the bar appears when the content grows past the box and
     * everything inside shifts sideways by its width - the jump that happens
     * when a filter returns one row fewer. In the grid it is worse than
     * cosmetic: the column a figure sits under moves while somebody is
     * reading down it.
     *
     * The side is deliberately not ours to choose: `stable` reserves the
     * inline-end edge, which the browser puts on the left in an RTL document.
     * A rule naming a side here would be the RTL bug.
     *
     * @return list<string>
     */
    public function scrollersKeepTheirGutter(string $css): array
    {
        $gutter = [];

        foreach (self::innerRules($css) as [$selector, $body]) {
            if (preg_match('/(?<![\w-])scrollbar-gutter:\s*([^;]+)/', $body, $m) !== 1) {
                continue;
            }

            foreach (explode(',', $selector) as $one) {
                $gutter[trim($one)] = trim($m[1]);
            }
        }

        $violations = [];

        // Every element the templates make scroll, by the hook that names it.
        $scrollers = [
            'html' => 'the page shell',
            '.scrollbar-on-sidebar' => 'the sidebar\'s own scroller',
            '[data-pf-grid-scroller]' => 'the data grid\'s scroller',
        ];

        foreach ($scrollers as $selector => $what) {
            if (! isset($gutter[$selector])) {
                $violations[] = \sprintf(
                    'nothing gives %s (%s) a stable scrollbar gutter, so its contents shift sideways by the '.
                    'bar\'s width the moment it starts scrolling.',
                    $what,
                    $selector,
                );

                continue;
            }

            if (! str_starts_with($gutter[$selector], 'stable')) {
                $violations[] = \sprintf('%s has scrollbar-gutter: %s rather than stable.', $selector, $gutter[$selector]);
            }
        }

        // The hooks have to be on the elements that really scroll.
        $pairs = [
            'resources/views/partials/sidebar.blade.php' => ['scrollbar-on-sidebar', 'overflow-y-auto'],
            'resources/views/components/grid.blade.php' => ['data-pf-grid-scroller', 'overflow-auto'],
        ];

        foreach ($pairs as $file => [$hook, $overflow]) {
            $source = $this->read($file);

            if (preg_match('/<[^>]*'.preg_quote($hook, '/').'[^>]*'.preg_quote($overflow, '/').'[^>]*>/s', $source) !== 1
                && preg_match('/<[^>]*'.preg_quote($overflow, '/').'[^>]*'.preg_quote($hook, '/').'[^>]*>/s', $source) !== 1) {
                $violations[] = \sprintf(
                    '%s no longer puts %s on the element that scrolls (%s), so the gutter rule is addressing '.
                    'nothing.',
                    $file,
                    $hook,
                    $overflow,
                );
            }
        }

        // And nothing may name a physical side for a scrollbar.
        foreach (self::innerRules($css) as [$selector, $body]) {
            if (! str_contains($selector, 'scrollbar') && ! str_contains($body, 'scrollbar')) {
                continue;
            }

            if (preg_match('/(?<![\w-])(?:left|right)\s*:/', $body, $m) === 1) {
                $violations[] = \sprintf(
                    'a scrollbar rule ("%s") names a physical side. `stable` already puts the gutter on the '.
                    'inline-end edge, which is the left in Arabic.',
                    trim($selector),
                );
            }
        }

        return $violations;
    }

    /**
     * A scrollbar does not animate - `prefers-reduced-motion`.
     *
     * Stated as a prohibition and not a measurement, which is the honest
     * description: there is nothing to measure until something moves. A
     * transition on a thumb cannot be reduced by the reduced-motion block,
     * because that block zeroes `transition-duration` on elements and a
     * webkit scrollbar pseudo-element is not reached by `*`. So the rule is
     * that the scrollbar never animates in the first place, and that the
     * reduced-motion block still takes smooth scrolling away.
     *
     * @return list<string>
     */
    public function nothingAnimatesAScrollbar(string $css): array
    {
        $violations = [];

        foreach (self::innerRules($css) as [$selector, $body]) {
            if (! str_contains($selector, 'scrollbar')) {
                continue;
            }

            if (preg_match('/(?<![\w-])(transition|animation)(?:-[\w-]+)?\s*:\s*(?!none)([^;]+)/', $body, $m) === 1) {
                $violations[] = \sprintf(
                    '"%s" animates the scrollbar (%s: %s). The reduced-motion block cannot reach a webkit '.
                    'scrollbar pseudo-element, so an animation here is one somebody who asked for less motion '.
                    'still gets.',
                    trim($selector),
                    $m[1],
                    trim($m[2]),
                );
            }
        }

        $reduced = self::mediaBlock($css, 'prefers-reduced-motion: reduce');

        if ($reduced === null) {
            $violations[] = 'app.css has no (prefers-reduced-motion: reduce) block.';
        } elseif (! str_contains((string) preg_replace('/\s+/', ' ', $reduced), 'scroll-behavior: auto')) {
            $violations[] = 'the reduced-motion block does not force scroll-behavior to auto, so a smooth '.
                'scroll added later reaches somebody who asked for less motion.';
        }

        foreach (self::innerRules($css) as [$selector, $body]) {
            if (preg_match('/(?<![\w-])scroll-behavior:\s*smooth/', $body) === 1 && ! str_contains($selector, 'scroll-smooth')) {
                $violations[] = \sprintf('"%s" sets scroll-behavior: smooth outside a reduced-motion guard.', trim($selector));
            }
        }

        return $violations;
    }

    /* =======================================================================
     |  The rendered half - PF-203 and PF-202, read out of real HTML
     * ==================================================================== */

    /**
     * The checks that need a page rather than a file.
     *
     * A source-level check on density would pass a layout that writes the
     * attribute from a resolver that never returns anything but the default,
     * and that is precisely the shape the first version of this had: tokens
     * in the stylesheet for three modes, and nothing anywhere that ever set
     * `data-density` at all.
     *
     * @return array<string, list<string>>
     */
    private function rendered(): array
    {
        $tenant = \App\Platform\Models\Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['the page in its density (no tenant provisioned - skipped)' => []];
        }

        app(\App\Platform\Services\TenantConnectionResolver::class)->point($tenant);
        $this->connection = \Illuminate\Support\Facades\DB::connection('tenant');
        $this->connection->beginTransaction();

        /*
         | The web guard, explicitly - the lesson ShellRules records: the
         | suites that run before this one dispatch requests through other
         | guards, and `shouldUse()` leaves the process's default guard
         | wherever the last of them left it.
         */
        \Illuminate\Support\Facades\Auth::shouldUse('web');

        try {
            $this->seedThePayableSide();

            return $this->runEachInASavepoint([
                'a page is in the user\'s density before the first paint' => fn (): array => $this->pagesAreDense(),
                'the density a user chose is the density they are served' => fn (): array => $this->densityFollowsTheUser(),
                'every figure on a rendered page is marked as one' => fn (): array => $this->renderedFiguresAreMarked(),
            ]);
        } catch (Throwable $e) {
            return ['the rendered half could not be checked' => [
                \Illuminate\Support\Str::limit((string) preg_replace('/\s+/', ' ', $e->getMessage()), 300),
            ]];
        } finally {
            \Illuminate\Support\Facades\Auth::guard('web')->forgetUser();
            $this->connection->rollBack();
        }
    }

    /**
     * Applied before the first paint, so the layout does not reflow on load.
     *
     * The attribute is on `<html>` as the server writes it - ahead of the
     * stylesheet, not applied by a script afterwards - because a density
     * applied after the first paint is a table that resizes while somebody
     * is reading it.
     *
     * @return list<string>
     */
    private function pagesAreDense(): array
    {
        $this->signIn();
        $html = $this->renderShell();
        $violations = [];

        if (preg_match('/<html\b[^>]*>/s', $html, $m) !== 1) {
            return ['the shell rendered without an <html> element.'];
        }

        if (preg_match('/data-density="([\w-]+)"/', $m[0], $attribute) !== 1) {
            return ['the shell\'s <html> carries no data-density attribute, so the three density modes in '.
                'app.css are selected by nothing and every user gets the default.'];
        }

        if (Density::tryFrom($attribute[1]) === null) {
            $violations[] = \sprintf('<html> carries data-density="%s", which is not a density.', $attribute[1]);
        }

        // Before the stylesheet: the attribute is in the element the
        // stylesheet is matched against, which only holds if the server
        // wrote it rather than a script.
        $htmlTag = strpos($html, '<html');
        $stylesheet = strpos($html, '<link');

        if ($htmlTag === false || ($stylesheet !== false && $stylesheet < $htmlTag)) {
            $violations[] = 'the stylesheet is linked before <html> is written, which cannot be - but if it '.
                'were, the first paint would be at the wrong density.';
        }

        foreach (['resources/views/layouts/app.blade.php', 'resources/views/layouts/plain.blade.php'] as $layout) {
            $source = $this->read($layout);

            /*
             | To `<head>`, not to the first `>`.
             |
             | `class="{{ $theme->htmlClass() }}"` has a `>` in it, so
             | `[^>]*` stops inside the attribute and never reaches the
             | attribute after it - which is how the first draft of this
             | reported that a layout writing data-density does not write it.
             | The same trap `DesignTokenRules` records for the theme.
             */
            if (preg_match('/<html\b.*?data-density=.*?<head>/s', $source) !== 1) {
                $violations[] = $layout.' does not write data-density onto <html>, so a page it renders is at '.
                    'the default density whatever the user chose.';
            }
        }

        return $violations;
    }

    /**
     * Persisted per user: the stored value is what the page is served in.
     *
     * Through the resolver AND through the rendered page, because the two
     * failures are different - a resolver that ignores the column, and a
     * layout that ignores the resolver.
     *
     * @return list<string>
     */
    private function densityFollowsTheUser(): array
    {
        $violations = [];
        $user = $this->signIn();

        foreach ([Density::Dense, Density::Comfortable] as $chosen) {
            $user->forceFill(['density' => $chosen->value])->saveQuietly();
            $user->refresh();

            $resolved = DensityPreference::forUser(request(), $user);

            if ($resolved !== $chosen) {
                $violations[] = \sprintf(
                    'a user whose stored density is "%s" resolves to "%s". The preference is persisted per '.
                    'user and is not being read back.',
                    $chosen->value,
                    $resolved->value,
                );

                continue;
            }

            $html = $this->renderShell();

            if (preg_match('/<html\b[^>]*data-density="([\w-]+)"/s', $html, $m) !== 1 || $m[1] !== $chosen->value) {
                $violations[] = \sprintf(
                    'a user whose stored density is "%s" is served a page at "%s".',
                    $chosen->value,
                    $m[1] ?? '(nothing)',
                );
            }
        }

        // And an unknown value must not stop a page rendering.
        $user->forceFill(['density' => 'spacious'])->saveQuietly();
        $user->refresh();

        if (DensityPreference::forUser(request(), $user) !== Density::default()) {
            $violations[] = 'a stored density nobody offers any more does not fall back to the default. A '.
                'display preference must never be able to stop a page rendering.';
        }

        return $violations;
    }

    /**
     * Every figure on a real page is marked as a figure.
     *
     * Read out of the rendered DOM rather than out of the templates: the
     * question is whether the amount a person sees is in the figure face
     * with tabular numerals, and that depends on the element it ended up
     * in - which a template scan cannot see through a component, a slot and
     * a loop.
     *
     * A figure is recognised by its shape (grouped digits with the
     * currency's own two or three decimals), which is what a money value
     * looks like and what a date, a count and a percentage do not.
     *
     * @return list<string>
     */
    private function renderedFiguresAreMarked(): array
    {
        $this->signIn();
        $this->enterBill();

        $violations = [];

        foreach ($this->pages() as $route => $html) {
            $document = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $xpath = new \DOMXPath($document);
            $nodes = $xpath->query('//text()');

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                $text = trim($node->textContent);

                // A money value: at least one group separator or a leading
                // digit, then exactly the currency's own decimals.
                if (preg_match('/(?<![\d.,])\d{1,3}(?:,\d{3})+\.\d{2,3}(?![\d.,])/', $text) !== 1) {
                    continue;
                }

                if ($this->insideAFigure($node)) {
                    continue;
                }

                $violations[] = \sprintf(
                    '%s renders the figure "%s" in an element that carries no figure hook, so it is set in the '.
                    'UI face at proportional width: the thousands place does not line up with the row above and '.
                    'the column cannot be scanned.',
                    $route,
                    \Illuminate\Support\Str::limit($text, 40),
                );
            }
        }

        return array_values(array_unique($violations));
    }

    /** Whether a text node is inside an element marked as a figure. */
    private function insideAFigure(\DOMNode $node): bool
    {
        for ($element = $node->parentNode; $element instanceof \DOMElement; $element = $element->parentNode) {
            if ($element->hasAttribute('data-figure')) {
                return true;
            }

            $classes = preg_split('/\s+/', $element->getAttribute('class')) ?: [];

            if (\in_array('figure', $classes, true) || \in_array('font-mono', $classes, true)) {
                return true;
            }

            if ($element->tagName === 'code' || $element->tagName === 'pre') {
                return true;
            }
        }

        return false;
    }

    /**
     * The pages a figure can appear on, rendered through their own
     * controllers.
     *
     * @return array<string, string>
     */
    private function pages(): array
    {
        $pages = [];
        $original = app('request');

        foreach (['overview', 'invoices.index', 'bills.index', 'trial-balance.index'] as $name) {
            if (! \Illuminate\Support\Facades\Route::has($name)) {
                continue;
            }

            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);

            if ($route === null || $route->parameterNames() !== []) {
                continue;
            }

            try {
                $request = \Illuminate\Http\Request::create('/'.ltrim($route->uri(), '/'), 'GET');
                app()->instance('request', $request);
                app()->instance('currentEntity', $this->entity());

                $rendered = app()->call($route->getController(), ['request' => $request]);

                $pages[$name] = $rendered instanceof \Illuminate\Contracts\Support\Renderable
                    ? $rendered->render()
                    : (string) $rendered;
            } catch (Throwable) {
                // A page that cannot render is ShellRules' business, not this
                // suite's: reporting it here would report one defect twice.
            } finally {
                app()->instance('request', $original);
            }
        }

        return $pages;
    }

    /** The shell as a browser receives it, for the signed-in user. */
    private function renderShell(): string
    {
        app()->instance('currentEntity', $this->entity());

        return \Illuminate\Support\Facades\View::make('layouts.app')->render();
    }

    /** A signed-in user holding the owner role, so every page renders. */
    private function signIn(): \App\Tenant\Identity\Models\User
    {
        $user = \App\Tenant\Identity\Models\User::create([
            'name' => 'Design Probe',
            'email' => 'design-probe-'.bin2hex(random_bytes(6)).'@invalid.test',
            'password' => \Illuminate\Support\Str::password(32),
            'active' => true,
        ]);

        $role = \App\Tenant\Identity\Models\Role::query()->where('key', 'owner')->first();

        if ($role !== null) {
            \App\Tenant\Identity\Models\UserEntityRole::create([
                'user_id' => $user->getKey(),
                'entity_id' => $this->entityId,
                'role_id' => $role->getKey(),
            ]);
        }

        \Illuminate\Support\Facades\Auth::guard('web')->setUser($user);

        return $user;
    }

    /* =======================================================================
     |  Reading the stylesheet
     * ==================================================================== */

    /** @return array<string, array<string, string>> density mode => its tokens */
    private function densityModes(string $css): array
    {
        $modes = [];

        foreach (self::blocks($css) as $block) {
            if (preg_match_all("/\\[data-density='([\\w-]+)'\\]/", $block['selector'], $m) < 1) {
                continue;
            }

            foreach ($m[1] as $mode) {
                foreach ($block['declarations'] as $name => $value) {
                    if (str_starts_with($name, '--')) {
                        $modes[$mode][$name] = $value;
                    }
                }
            }
        }

        return $modes;
    }

    /**
     * The role and the percentage behind a scrollbar thumb token.
     *
     * The sidebar's thumb is the same token redefined for the subtree, which
     * is the point of the component layer - so it is found by looking for the
     * declaration inside `.scrollbar-on-sidebar` rather than for a token of
     * its own.
     *
     * @return array{string, float}|null
     */
    private function scrollbarThumb(string $css, string $token): ?array
    {
        $sidebar = str_ends_with($token, '-sidebar');
        $name = $sidebar ? substr($token, 0, -\strlen('-sidebar')) : $token;

        /*
         | Over the INNER rules, not the top-level blocks.
         |
         | `.scrollbar-on-sidebar` is inside `@layer base`, so it is nested -
         | and the first draft of this looked only at top-level blocks and
         | reported that the sidebar's thumb colour was not defined at all.
         */
        foreach (self::innerRules(self::withoutMediaBlocks($css)) as [$selector, $body]) {
            $isSidebarBlock = str_contains($selector, '.scrollbar-on-sidebar');

            if ($isSidebarBlock !== $sidebar) {
                continue;
            }

            $value = self::declarations($body)[$name] ?? null;

            if ($value === null) {
                continue;
            }

            if (preg_match('/color-mix\(\s*in\s+\w+\s*,\s*var\(--color-([\w-]+)\)\s*([\d.]+)%\s*,\s*transparent\s*\)/', $value, $m) === 1) {
                return ['--c-'.$m[1], (float) $m[2]];
            }

            if (preg_match('/var\(--color-([\w-]+)\)/', $value, $m) === 1) {
                return ['--c-'.$m[1], 100.0];
            }
        }

        return null;
    }

    /** @return list<array{string, string}> the declared pairs, text and ui together */
    private function declaredPairs(): array
    {
        $data = $this->json('resources/css/contrast-pairs.json');
        $pairs = [];

        foreach (['text', 'ui'] as $kind) {
            foreach ($data[$kind] ?? [] as $pair) {
                if (\is_array($pair) && \count($pair) === 2) {
                    $pairs[] = [(string) $pair[0], (string) $pair[1]];
                }
            }
        }

        return $pairs;
    }

    /**
     * A role, with a deprecated alias resolved to what it aliases.
     *
     * Read from the stylesheet (`--c-info: var(--c-insight)`) rather than
     * from a list here, so an alias that is removed stops being resolved on
     * its own.
     *
     * @param  array<string, string>  $roles
     */
    private function canonicalRole(string $role, array $roles): string
    {
        for ($hop = 0; $hop < 4; $hop++) {
            $value = $roles['--c-'.$role] ?? null;

            if ($value === null || preg_match('/^var\(--c-([\w-]+)\)$/', $value, $m) !== 1) {
                return $role;
            }

            $role = $m[1];
        }

        return $role;
    }

    /** @return list<string> the class lists inside one tag */
    private static function classLists(string $tag): array
    {
        $lists = [];

        if (preg_match_all('/"([^"]*)"|\'([^\']*)\'/', $tag, $m, PREG_SET_ORDER)) {
            foreach ($m as $literal) {
                $value = ($literal[1] ?? '') !== '' ? $literal[1] : ($literal[2] ?? '');

                if ($value !== '') {
                    $lists[] = $value;
                }
            }
        }

        return $lists;
    }

    /**
     * The foreground/background pairs inside one class list.
     *
     * A variant is paired with its own kind first and with the bare utility
     * only when there is none: `file:bg-primary` beside a bare
     * `text-on-surface` is the input's own text colour, not the file
     * button's, and pairing them measures a combination that is never drawn.
     *
     * @param  array<string, true>  $roles
     * @return list<array{string, string}>
     */
    private static function pairsIn(string $list, array $roles): array
    {
        $variant = '((?:[\w\[\]&>:.\'=~^$*-]+:)*)';
        $foregrounds = [];
        $backgrounds = [];

        if (preg_match_all('/(?<![\w-])'.$variant.'text-([a-z][\w-]*)(?![\w\[(-])/', $list, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (isset($roles[$hit[2]])) {
                    $foregrounds[$hit[1]] = $hit[2];
                }
            }
        }

        if (preg_match_all('/(?<![\w-])'.$variant.'bg-([a-z][\w-]*)(?![\w\[(-])/', $list, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (isset($roles[$hit[2]])) {
                    $backgrounds[$hit[1]] = $hit[2];
                }
            }
        }

        $pairs = [];

        foreach ($backgrounds as $prefix => $background) {
            $foreground = $foregrounds[$prefix] ?? $foregrounds[''] ?? null;

            if ($foreground !== null) {
                $pairs[] = [$foreground, $background];
            }
        }

        foreach ($foregrounds as $prefix => $foreground) {
            if ($prefix === '') {
                continue;
            }

            $background = $backgrounds[$prefix] ?? $backgrounds[''] ?? null;

            if ($background !== null) {
                $pairs[] = [$foreground, $background];
            }
        }

        return $pairs;
    }

    /** @return array<string, true> the colour utilities `@theme` defines */
    private static function themeUtilities(string $css): array
    {
        $utilities = [];

        if (preg_match_all('/--color-([\w-]+):/', self::atTheme($css), $m)) {
            foreach ($m[1] as $utility) {
                $utilities[$utility] = true;
            }
        }

        return $utilities;
    }

    /** The body of the `@theme` block. */
    private static function atTheme(string $css): string
    {
        $source = self::withoutComments($css);
        $start = strpos($source, '@theme');

        return $start === false ? '' : self::blockAt($source, strpos($source, '{', $start) ?: 0);
    }

    /** The body of the first `@media (<feature>)` block. */
    private static function mediaBlock(string $css, string $feature): ?string
    {
        $source = self::withoutComments($css);
        $start = strpos($source, '@media ('.$feature.')');

        if ($start === false) {
            return null;
        }

        $brace = strpos($source, '{', $start);

        return $brace === false ? null : self::blockAt($source, $brace);
    }

    /**
     * The stylesheet with every `@media` region removed.
     *
     * A check about the base rules has to read the base rules: the
     * forced-colours and reduced-motion blocks deliberately contradict them,
     * under identical selectors, and a check that cannot tell the two apart
     * measures whichever came last.
     */
    private static function withoutMediaBlocks(string $css): string
    {
        $source = self::withoutComments($css);

        while (($start = strpos($source, '@media')) !== false) {
            $brace = strpos($source, '{', $start);

            if ($brace === false) {
                break;
            }

            $depth = 0;
            $length = \strlen($source);
            $end = $length;

            for ($i = $brace; $i < $length; $i++) {
                if ($source[$i] === '{') {
                    $depth++;
                } elseif ($source[$i] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $end = $i + 1;

                        break;
                    }
                }
            }

            $source = substr($source, 0, $start).substr($source, $end);
        }

        return $source;
    }

    /** The text between a `{` at `$from` and its matching `}`. */
    private static function blockAt(string $source, int $from): string
    {
        $depth = 0;
        $length = \strlen($source);

        for ($i = $from; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $from + 1, $i - $from - 1);
                }
            }
        }

        return substr($source, $from + 1);
    }

    /**
     * Every innermost rule - a selector and a body with no nested block.
     *
     * @return list<array{string, string}>
     */
    private static function innerRules(string $css): array
    {
        $rules = [];

        if (preg_match_all('/([^{};]+)\{([^{}]*)\}/', self::withoutComments($css), $m, PREG_SET_ORDER)) {
            foreach ($m as [, $selector, $body]) {
                $rules[] = [trim((string) preg_replace('/\s+/', ' ', $selector)), $body];
            }
        }

        return $rules;
    }

    /** The custom properties a declaration reads. @return list<string> */
    private static function referencedProperties(string $value): array
    {
        $referenced = [];

        if (preg_match_all('/var\(\s*(--[\w-]+)/', $value, $m)) {
            $referenced = $m[1];
        }

        return $referenced;
    }

    /** @param array<string, mixed> $manifest */
    private function layerOf(string $property, array $manifest): ?string
    {
        foreach ($manifest['layers'] as $layer => $declared) {
            if (str_starts_with($property, $declared['prefix'])) {
                return $layer;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $manifest */
    private function prefixOf(string $property, array $manifest): ?string
    {
        $prefixes = ['--color-'];

        foreach ($manifest['layers'] as $declared) {
            $prefixes[] = $declared['prefix'];
        }

        // Longest first, so `--color-` is not read as a `--c-` token.
        usort($prefixes, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        foreach ($prefixes as $prefix) {
            if (str_starts_with($property, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $manifest */
    private function layerNameOf(string $prefix, array $manifest): string
    {
        foreach ($manifest['layers'] as $layer => $declared) {
            if ($declared['prefix'] === $prefix) {
                return $layer;
            }
        }

        return $prefix === '--color-' ? 'semantic (the @theme utility form)' : $prefix;
    }

    /** @return array{layers: array<string, array{prefix: string, role: string, may_read: list<string>}>, components: array<string, array{owner: string, tokens: list<string>}>, role_families: array<string, string>, non_inverting: array<string, string>, colour_words: list<string>, literal_colour_exceptions: array<string, string>} */
    private function manifest(): array
    {
        $data = $this->json('resources/css/token-architecture.json');

        foreach (['layers', 'components', 'role_families', 'non_inverting', 'colour_words', 'literal_colour_exceptions'] as $key) {
            if (! \is_array($data[$key] ?? null)) {
                throw new RuntimeException('resources/css/token-architecture.json is missing "'.$key.'".');
            }
        }

        foreach ($data['layers'] as $layer => $declared) {
            if (! \is_array($declared) || ! \is_string($declared['prefix'] ?? null) || ! \is_array($declared['may_read'] ?? null)
                || ! \is_string($declared['role'] ?? null) || trim($declared['role']) === '') {
                throw new RuntimeException(\sprintf('the "%s" layer in token-architecture.json is malformed.', (string) $layer));
            }
        }

        /** @var array{layers: array<string, array{prefix: string, role: string, may_read: list<string>}>, components: array<string, array{owner: string, tokens: list<string>}>, role_families: array<string, string>, non_inverting: array<string, string>, colour_words: list<string>, literal_colour_exceptions: array<string, string>} $data */
        return $data;
    }

    /* =======================================================================
     |  The cascade, and the colour science
     * ==================================================================== */

    private static function withoutComments(string $css): string
    {
        return (string) preg_replace_callback(
            '~("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')|/\*.*?\*/~s',
            static fn (array $m): string => ($m[1] ?? '') !== '' ? $m[1] : '',
            $css,
        );
    }

    /** PHP and JS comments out, so a comment naming a thing is not the thing. */
    private static function withoutPhpComments(string $source): string
    {
        return (string) preg_replace_callback(
            '~("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')|/\*.*?\*/|//[^\n]*|\#(?!\[)[^\n]*|\{\{--.*?--\}\}~s',
            static fn (array $m): string => ($m[1] ?? '') !== '' ? $m[1] : '',
            $source,
        );
    }

    /**
     * Top-level rule blocks with no nested block inside - every place a
     * token is declared.
     *
     * @return list<array{selector: string, declarations: array<string, string>}>
     */
    private static function blocks(string $css): array
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
        $depth = 0;
        $buffer = '';

        // Split on top-level semicolons only: a `color-mix(...)` has commas
        // and a `box-shadow` has several, but a `;` inside `()` is possible
        // in a data: URL.
        foreach (str_split($body) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ';' && $depth === 0) {
                self::declare($declarations, $buffer);
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        self::declare($declarations, $buffer);

        return $declarations;
    }

    /** @param array<string, string> $declarations */
    private static function declare(array &$declarations, string $line): void
    {
        $colon = strpos($line, ':');

        if ($colon === false) {
            return;
        }

        $declarations[trim(substr($line, 0, $colon))] = trim((string) preg_replace('/\s+/', ' ', substr($line, $colon + 1)));
    }

    /**
     * @param  list<array{selector: string, declarations: array<string, string>}>  $blocks
     * @param  list<string>  $selectors
     * @return array<string, string>
     */
    private static function resolve(array $blocks, array $selectors): array
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
     * @return array<string, string>
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
     * @param  array<string, string>  $tokens
     * @return array{string, string}
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

    /**
     * A translucent colour over an opaque one.
     *
     * `color-mix(in oklab, X 70%, transparent)` is X at alpha 0.7: mixing
     * with `transparent` premultiplies, so the hue is X's and only the alpha
     * moves. What the eye reads is that composited over the scroller's own
     * background, which is a different colour from the token.
     */
    private static function over(string $foreground, float $alpha, string $background): string
    {
        $channels = static fn (string $hex): array => [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];

        $front = $channels($foreground);
        $back = $channels($background);
        $out = '';

        foreach ([0, 1, 2] as $i) {
            $mixed = (int) round($front[$i] * $alpha + $back[$i] * (1 - $alpha));
            $out .= str_pad(strtoupper(dechex($mixed)), 2, '0', STR_PAD_LEFT);
        }

        return '#'.$out;
    }

    /** A CSS length in pixels, at the browser's default root size. */
    private static function pixels(string $value): ?float
    {
        if (preg_match('/^([\d.]+)(rem|em|px)$/', trim($value), $m) !== 1) {
            return null;
        }

        return (float) $m[1] * ($m[2] === 'px' ? 1.0 : 16.0);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }

    /* =======================================================================
     |  Files
     * ==================================================================== */

    private function read(string $relative): string
    {
        $contents = @file_get_contents($this->base.'/'.$relative);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('%s could not be read.', $relative));
        }

        return $contents;
    }

    /** @return array<string, mixed> */
    private function json(string $relative): array
    {
        try {
            $data = json_decode($this->read($relative), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException($relative.' is not valid JSON: '.$e->getMessage(), 0, $e);
        }

        if (! \is_array($data)) {
            throw new RuntimeException($relative.' is not a JSON object.');
        }

        return $data;
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

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        return $this->files($directory, '.php');
    }

    private function relative(string $file): string
    {
        return ltrim(str_replace(str_replace('\\', '/', $this->base), '', $file), '/');
    }
}
