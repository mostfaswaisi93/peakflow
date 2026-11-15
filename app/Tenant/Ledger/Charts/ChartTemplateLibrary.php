<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Charts;

use App\Tenant\Ledger\Charts\Exceptions\InvalidChartTemplate;
use App\Tenant\Ledger\Charts\Exceptions\UnknownChartTemplate;
use JsonException;

/**
 * The chart templates this release ships - PF-039.
 *
 * ## A directory, not a registry
 *
 * Templates are discovered by reading `database/charts`, so adding one is
 * adding a file. A registry listing them would be a second place to forget,
 * and the thing forgotten would be the new jurisdiction nobody can select.
 *
 * ## The default is generic, deliberately
 *
 * An entity in a country with no template gets the generic IFRS chart rather
 * than the nearest-looking one. "Nearest" is a guess about statutory
 * structure, and a chart that is almost right is harder to correct than one
 * that is plainly generic: the tenant who sees a Saudi chart assumes somebody
 * checked it against Saudi requirements.
 */
final class ChartTemplateLibrary
{
    public const DEFAULT = 'ifrs-generic';

    /** @var array<string, ChartTemplateDefinition>|null */
    private ?array $loaded = null;

    public function __construct(private readonly string $directory) {}

    public static function shipped(): self
    {
        return new self(database_path('charts'));
    }

    /** @return array<string, ChartTemplateDefinition> keyed by template key */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $templates = [];

        /*
         | Sorted, so the order a template appears in is the order of its
         | file name rather than whatever order the filesystem returns. A
         | provisioning screen whose options move between deployments is a
         | screen people mis-click.
         */
        $files = glob(rtrim($this->directory, '/').'/*.json') ?: [];
        sort($files);

        foreach ($files as $file) {
            $template = $this->read($file);

            if (isset($templates[$template->key])) {
                throw InvalidChartTemplate::because(
                    $template->key,
                    sprintf('two files claim the key: %s and an earlier one', basename($file)),
                );
            }

            $templates[$template->key] = $template;
        }

        return $this->loaded = $templates;
    }

    public function get(string $key): ChartTemplateDefinition
    {
        $all = $this->all();

        if (! isset($all[$key])) {
            throw UnknownChartTemplate::key($key, array_keys($all));
        }

        return $all[$key];
    }

    public function default(): ChartTemplateDefinition
    {
        return $this->get(self::DEFAULT);
    }

    /**
     * The template for a country, or the generic one.
     *
     * Never the nearest-looking alternative. See the class note.
     */
    public function forCountry(?string $country): ChartTemplateDefinition
    {
        if ($country === null || $country === '') {
            return $this->default();
        }

        foreach ($this->all() as $template) {
            if ($template->key !== self::DEFAULT && $template->servesCountry($country)) {
                return $template;
            }
        }

        return $this->default();
    }

    /**
     * What to offer on a provisioning screen.
     *
     * The country's own template first, then the rest. Offered rather than
     * imposed: a Saudi company keeping its books on a group's generic chart
     * is an ordinary arrangement, and so is the reverse.
     *
     * @return list<array{key: string, version: int, name_ar: string, name_en: string, suggested: bool}>
     */
    public function choicesFor(?string $country): array
    {
        $suggested = $this->forCountry($country);
        $choices = [];

        foreach ($this->all() as $template) {
            $choices[] = [
                'key' => $template->key,
                'version' => $template->version,
                'name_ar' => $template->nameAr,
                'name_en' => $template->nameEn,
                'suggested' => $template->key === $suggested->key,
            ];
        }

        usort($choices, static fn (array $a, array $b): int => [$b['suggested'], $a['key']] <=> [$a['suggested'], $b['key']]);

        return $choices;
    }

    private function read(string $file): ChartTemplateDefinition
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            throw InvalidChartTemplate::unreadable($file, 'the file could not be opened');
        }

        try {
            $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidChartTemplate::unreadable($file, $e->getMessage());
        }

        if (! is_array($data)) {
            throw InvalidChartTemplate::unreadable($file, 'it does not contain an object');
        }

        $template = ChartTemplateDefinition::fromArray($data);

        /*
         | The file name has to match the key.
         |
         | Not pedantry: the key is what a tenant's provisioning record
         | stores, and a file whose name disagrees with it is a file nobody
         | finds when asked which template an entity was built from.
         */
        $expected = $template->key.'.json';

        if (basename($file) !== $expected) {
            throw InvalidChartTemplate::because(
                $template->key,
                sprintf('it lives in %s and should be in %s, so that the key leads to the file',
                    basename($file), $expected),
            );
        }

        return $template;
    }
}
