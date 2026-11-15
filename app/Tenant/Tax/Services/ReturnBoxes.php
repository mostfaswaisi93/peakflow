<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Tenant\Tax\Exceptions\ReturnFormUnusable;
use App\Tenant\Tax\Models\TaxCode;

/**
 * The boxes of each return form, and which codes land in them - PF-085.
 *
 * ## Why the form is data
 *
 * A return is a form with numbered boxes, and an authority's portal takes
 * figures per box. Without a mapping the engine can total tax perfectly and
 * produce nothing anybody can submit, because "total output tax" is not a box
 * on any form.
 *
 * Forms change. When one does, the change is a row in
 * `config/tax_returns.php` and a mapping update on the affected codes - not a
 * migration and a deploy.
 *
 * ## A code with no box is reported here rather than discovered at filing
 *
 * {@see codesWithoutABox()} is the check that matters, and it is the one that
 * cannot be derived from anything else: a tax code is complete, correct and
 * unfileable if nobody said which box it belongs to, and the discovery
 * happens on the day of a deadline.
 */
final class ReturnBoxes
{
    private const SIDES = ['output', 'input', 'net', 'memo'];

    /** The form a jurisdiction files on. */
    public function formFor(string $jurisdiction): string
    {
        $map = config('tax_returns.by_jurisdiction');
        $form = \is_array($map) ? ($map[$jurisdiction] ?? null) : null;

        return \is_string($form) ? $form : (string) config('tax_returns.default', 'generic');
    }

    /**
     * Every box on a form, keyed by its own name.
     *
     * @return array<string, array{side: string, en: string, ar: string}>
     *
     * @throws ReturnFormUnusable
     */
    public function boxes(string $form): array
    {
        $definition = config('tax_returns.returns.'.$form);

        if (! \is_array($definition) || ! \is_array($definition['boxes'] ?? null) || $definition['boxes'] === []) {
            throw ReturnFormUnusable::unknownForm($form);
        }

        if (! \is_string($definition['source'] ?? null) || trim($definition['source']) === '') {
            throw ReturnFormUnusable::withoutASource($form);
        }

        $boxes = [];

        foreach ($definition['boxes'] as $name => $box) {
            $name = (string) $name;

            if (! \is_array($box) || ! \in_array($box['side'] ?? null, self::SIDES, true)) {
                throw ReturnFormUnusable::boxWithoutASide($form, $name, self::SIDES);
            }

            foreach (['en', 'ar'] as $locale) {
                if (! \is_string($box[$locale] ?? null) || trim($box[$locale]) === '') {
                    throw ReturnFormUnusable::boxWithoutALabel($form, $name, $locale);
                }
            }

            /** @var array{side: string, en: string, ar: string} $box */
            $boxes[$name] = $box;
        }

        return $boxes;
    }

    /** @return list<string> */
    public function boxesOnTheSide(string $form, string $side): array
    {
        $names = [];

        foreach ($this->boxes($form) as $name => $box) {
            if ($box['side'] === $side) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function sideOf(string $form, string $box): ?string
    {
        return $this->boxes($form)[$box]['side'] ?? null;
    }

    public function labelFor(string $form, string $box, ?string $locale = null): ?string
    {
        $boxes = $this->boxes($form);

        if (! isset($boxes[$box])) {
            return null;
        }

        return $boxes[$box][($locale ?? app()->getLocale()) === 'ar' ? 'ar' : 'en'];
    }

    public function sourceFor(string $form): string
    {
        $source = config('tax_returns.returns.'.$form.'.source');

        if (! \is_string($source) || trim($source) === '') {
            throw ReturnFormUnusable::withoutASource($form);
        }

        return $source;
    }

    /**
     * Tax codes that do not say which box they land in.
     *
     * Checked per entity, against the form of each code's OWN jurisdiction -
     * a Saudi code on a Jordanian form would be mapped to a box that does not
     * exist there, and the figure would simply not appear.
     *
     * @return list<string> a sentence per code
     */
    public function codesWithoutABox(string $entityId): array
    {
        $missing = [];

        $codes = TaxCode::query()
            ->where('entity_id', $entityId)
            ->orderBy('code')
            ->get();

        foreach ($codes as $code) {
            $form = $this->formFor((string) $code->jurisdiction);
            $boxes = $this->boxes($form);

            /*
             | At least one side, and every named box has to exist.
             |
             | Both sides are checked rather than one, because the same code
             | is used on sales and on purchases: a Saudi standard-rate code
             | lands in the standard sales box on an invoice and the standard
             | purchases box on a bill. Requiring both would refuse a code
             | that is only ever sold under; requiring neither is the gap this
             | method exists to find.
             */
            $mapped = [];

            foreach (['output', 'input'] as $side) {
                $box = $code->boxFor($form, $side);

                if ($box === null) {
                    continue;
                }

                $mapped[$side] = $box;

                if (! isset($boxes[$box])) {
                    $missing[] = sprintf(
                        'tax code %s maps its %s side to the box "%s", which the %s return does not '.
                        'have. The figure would be computed and then dropped',
                        (string) $code->code, $side, $box, $form,
                    );

                    continue;
                }

                if ($boxes[$box]['side'] !== $side) {
                    $missing[] = sprintf(
                        'tax code %s maps its %s side to the box "%s", which is a %s box on the %s '.
                        'return. Output tax reported as input tax is a refund request',
                        (string) $code->code, $side, $box, $boxes[$box]['side'], $form,
                    );
                }
            }

            if ($mapped === []) {
                $missing[] = sprintf(
                    'tax code %s (%s, %s) maps to no box on the %s return. The code is complete, correct '.
                    'and unfileable: the figure would simply not appear, and the discovery happens on '.
                    'the day of a deadline',
                    (string) $code->code, $code->category->value, (string) $code->jurisdiction, $form,
                );
            }
        }

        return $missing;
    }
}
