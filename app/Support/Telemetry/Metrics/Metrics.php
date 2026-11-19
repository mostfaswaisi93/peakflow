<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Metrics;

use App\Support\Telemetry\LogContext;
use InvalidArgumentException;
use Throwable;

/**
 * The registered business metrics - PF-241.
 *
 * A singleton each module registers into, the same shape as `ClosingChecks`
 * and `LedgerChecks`. The dashboards are DERIVED from what is registered, so
 * a new metric appears on the right ones by saying who it is for.
 *
 * ## Registration REFUSES a label that must not exist
 *
 * Two refusals, and they are the point of the class.
 *
 * A label name must be on the allowlist in `config/telemetry.php`, because a
 * metric's labels are written as log context and are filtered by the same
 * processor - so a label that is not on the list would be silently dropped
 * and the metric would arrive unlabelled. Refusing at boot turns a dashboard
 * that is quietly wrong into a deployment that does not start.
 *
 * And a label name must not be a per-record identifier. The allowlist
 * legitimately carries `party_id`, `document_id` and `document_number`
 * because a LOG LINE about one document should name it. A metric is the
 * opposite: it is a time series, one series per label combination, kept for
 * weeks. Labelled by party it is a customer list on whoever's dashboard, and
 * it is also a chart no browser can draw.
 *
 * ## A source that throws becomes a finding, not a blank dashboard
 *
 * The same reasoning as `LedgerChecks`. A metric that could not be computed
 * must not read as zero: "unmatched bank lines: 0" because the query failed
 * is the most dangerous number on the page.
 */
final class Metrics
{
    /**
     * Label names that are fine on a log line and fatal on a metric.
     *
     * Every one of them is on the allowlist, deliberately, because a log
     * line about a document should name the document. A time series must not.
     */
    public const PER_RECORD = [
        'party_id',
        'document_id',
        'document_number',
        'journal_id',
        'user_id',
        'operator_id',
        'reference',
        'first_divergence',
        'sample',
        'correlation_id',
        'trace_id',
        'span_id',
        'parent_span_id',
        'run_id',
    ];

    /** @var array<string, MetricSource> */
    private array $sources = [];

    public function register(MetricSource $source): void
    {
        $key = $source->key();

        if ($source->audiences() === []) {
            throw new InvalidArgumentException(sprintf(
                'The "%s" metric names no audience. A metric nobody is shown is a query that runs for '.
                'nothing, and the dashboards are derived from this rather than written out.',
                $key,
            ));
        }

        $allowlist = LogContext::allowlist();

        foreach ($source->labels() as $label) {
            if (! in_array($label, $allowlist, true)) {
                throw new InvalidArgumentException(sprintf(
                    'The "%s" metric labels by "%s", which is not on the field allowlist in '.
                    'config/telemetry.php. A metric\'s labels are written as log context and are '.
                    'filtered by the same processor, so the label would be dropped and the metric '.
                    'would arrive unlabelled - correct-looking and useless.',
                    $key,
                    $label,
                ));
            }

            if (in_array($label, self::PER_RECORD, true)) {
                throw new InvalidArgumentException(sprintf(
                    'The "%s" metric labels by "%s", which identifies a single record. A metric is a '.
                    'time series with one series per label combination: labelled by one of these it '.
                    'is a customer list on whoever\'s dashboard, retained for weeks, and a chart no '.
                    'browser can draw. The name is on the allowlist because a LOG LINE about one '.
                    'document should name the document; a metric is the opposite case.',
                    $key,
                    $label,
                ));
            }
        }

        $this->sources[$key] = $source;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->sources);
    }

    public function has(string $key): bool
    {
        return isset($this->sources[$key]);
    }

    public function source(string $key): ?MetricSource
    {
        return $this->sources[$key] ?? null;
    }

    /**
     * The metrics on one audience's dashboard.
     *
     * @return list<MetricSource>
     */
    public function forAudience(Audience $audience): array
    {
        return array_values(array_filter(
            $this->sources,
            static fn (MetricSource $source): bool => in_array($audience, $source->audiences(), true),
        ));
    }

    /** @return list<MetricSource> */
    public function inScope(MetricScope $scope): array
    {
        return array_values(array_filter(
            $this->sources,
            static fn (MetricSource $source): bool => $source->scope() === $scope,
        ));
    }

    /**
     * Collect one metric, reporting a failure as a failure.
     *
     * @return array{key: string, unit: string, samples: list<MetricSample>, error: ?string}
     */
    public function collect(MetricSource $source, ?string $entityId): array
    {
        try {
            $samples = $source->collect($entityId);
            $error = null;
        } catch (Throwable $e) {
            /*
             | An empty list and a stated error, never silently zero.
             |
             | "Unmatched bank lines: 0" because the query failed is the most
             | dangerous number on the page: it is the one figure somebody
             | would act on by doing nothing.
             */
            $samples = [];
            $error = sprintf('%s: %s', $e::class, $e->getMessage());
        }

        return [
            'key' => $source->key(),
            'unit' => $source->unit(),
            'samples' => $samples,
            'error' => $error,
        ];
    }
}
