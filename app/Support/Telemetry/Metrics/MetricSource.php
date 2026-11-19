<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Metrics;

/**
 * Something a module can say about whether the product is working - PF-241.
 *
 * ## Why this is a registry in `Support` and not a reporting service
 *
 * The same decision as `LedgerCheck` and `ClosingCheck`, and the same reason.
 * "Unmatched bank lines by age" is a banking fact, "payment runs prepared and
 * not released" is a settlement fact, and a metrics service that asked each
 * module directly would depend on all of them - which inverts the layering
 * the whole architecture exists to protect, and the first module reached from
 * there would carry the rest in behind it.
 *
 * So the contract is infrastructure and each module registers its own.
 *
 * ## It declares its labels, and the registry checks them
 *
 * Not "the source is trusted to label sensibly". A metric labelled by
 * customer id is a customer list on whoever's dashboard, and the person who
 * adds it will be adding a diagnostic at 2am. Declaring the label names
 * up front means the refusal happens at boot, loudly, rather than on a
 * dashboard nobody audits.
 */
interface MetricSource
{
    /** A short, stable key: `unmatched_bank_lines`. */
    public function key(): string;

    /**
     * Who is shown this figure.
     *
     * @return list<Audience>
     */
    public function audiences(): array;

    /** What has to be established before `collect()` can answer. */
    public function scope(): MetricScope;

    /**
     * The label names this source will use, every one of them.
     *
     * @return list<string>
     */
    public function labels(): array;

    /** `count`, `money` or `days` - what the value means. */
    public function unit(): string;

    /** One line a person reads beside the figure, in `ar` and `en`. */
    public function describe(string $locale): string;

    /**
     * The current figures.
     *
     * @return list<MetricSample>
     */
    public function collect(?string $entityId): array;
}
