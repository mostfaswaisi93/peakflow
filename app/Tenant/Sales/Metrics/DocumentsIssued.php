<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Metrics;

use App\Support\Telemetry\Metrics\Audience;
use App\Support\Telemetry\Metrics\MetricSample;
use App\Support\Telemetry\Metrics\MetricScope;
use App\Support\Telemetry\Metrics\MetricSource;
use Illuminate\Support\Facades\DB;

/**
 * Documents by jurisdiction profile and state - PF-241.
 *
 * ## Why per profile and not per country
 *
 * Because the thing that breaks is a profile. A jurisdiction profile is the
 * serializer, the validation chain and the clearance rules for one country's
 * e-invoicing regime, and when one of them starts refusing documents it
 * affects that country and looks like nothing in aggregate: a tenant with
 * four countries and one broken profile shows a 25% dip in a total that
 * nobody watches to the percent.
 *
 * The label is therefore the profile, which is also what `invoices.profile`
 * records on the document itself - so the metric and the document agree
 * about which rules were applied.
 *
 * ## What this metric does NOT say, and why that is stated rather than faked
 *
 * PF-241 asks for issued, FAILED and REJECTED per jurisdiction, and for
 * clearance latency percentiles per authority. Failed and rejected are
 * clearance outcomes, and clearance latency is the interval between a
 * submission and an authority's answer: this product has no transmission
 * table, no submission timestamps and no authority responses, because the
 * Exchange transport is Phase 12 and unbuilt.
 *
 * A `rejected` series derived from something else - a validation failure, a
 * draft that was never issued - would be a number on a dashboard that does
 * not mean what its name says, which is worse than an absent series. So the
 * series are absent and the task's criterion stays open.
 */
final class DocumentsIssued implements MetricSource
{
    public function key(): string
    {
        return 'documents_issued';
    }

    /** @return list<Audience> */
    public function audiences(): array
    {
        return [Audience::Operator, Audience::Controller, Audience::Engineer];
    }

    public function scope(): MetricScope
    {
        return MetricScope::Entity;
    }

    /** @return list<string> */
    public function labels(): array
    {
        return ['jurisdiction_profile', 'document_type', 'state'];
    }

    public function unit(): string
    {
        return 'count';
    }

    public function describe(string $locale): string
    {
        return $locale === 'ar'
            ? 'المستندات بحسب ملف الاختصاص وحالتها'
            : 'Documents by jurisdiction profile and state';
    }

    /** @return list<MetricSample> */
    public function collect(?string $entityId): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT
                coalesce(i.profile, '(none)') AS profile,
                i.document_type,
                i.status,
                count(*) AS documents
            FROM invoices i
            WHERE (cast(? AS char(26)) IS NULL OR i.entity_id = cast(? AS char(26)))
            GROUP BY 1, 2, 3
            ORDER BY 1, 2, 3
        SQL, [$entityId, $entityId]);

        return array_map(static fn (object $row): MetricSample => MetricSample::count([
            'jurisdiction_profile' => (string) $row->profile,
            'document_type' => (string) $row->document_type,
            'state' => (string) $row->status,
        ], (int) $row->documents), $rows);
    }
}
