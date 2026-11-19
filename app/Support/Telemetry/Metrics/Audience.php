<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Metrics;

/**
 * Who a dashboard is for - PF-241.
 *
 * ## Why the audience is a property of the METRIC
 *
 * Because a single dashboard serves none of them. The three ask different
 * questions and a figure that answers one is noise to the other two:
 *
 *   operator    is the platform healthy, and which tenant is the outlier
 *   controller  is MY company's money accounted for, this month
 *   engineer    is the machinery working, and where is the backlog
 *
 * Unmatched bank lines by age is the leading indicator of a reconciliation
 * drifting, which is a controller's question and a number an operator cannot
 * act on. Queue depth is the reverse. Putting both on one screen produces a
 * screen that is read by nobody, and the failure is invisible because the
 * screen exists.
 *
 * Declaring it on the metric rather than on the dashboard means the dashboard
 * is derived. A new metric appears on the right dashboards by saying who it
 * is for, and cannot appear on none - `Metrics::register()` refuses a source
 * that names no audience, because a metric nobody is shown is a query that
 * runs for nothing.
 */
enum Audience: string
{
    case Operator = 'operator';
    case Controller = 'controller';
    case Engineer = 'engineer';

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    public function describe(string $locale = 'en'): string
    {
        return match ($this) {
            self::Operator => $locale === 'ar' ? 'مشغّل المنصة' : 'Platform operator',
            self::Controller => $locale === 'ar' ? 'المراقب المالي للمنشأة' : 'Tenant controller',
            self::Engineer => $locale === 'ar' ? 'مهندس' : 'Engineer',
        };
    }
}
