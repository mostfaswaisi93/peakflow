<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Metrics;

/**
 * One set of age buckets, for every metric that ages something - PF-241.
 *
 * ## Why they are not configurable, and why there is only one set
 *
 * The same argument `Suspense::ageing()` makes about its own buckets. An
 * unmatched bank line, a document awaiting approval and a payment run
 * prepared and not released are four different things with one question
 * behind them - how long has this been sitting - and buckets that differed
 * between them could not be read side by side.
 *
 * The boundaries are operational rather than financial: 0-1 is today and
 * yesterday, 2-7 is this week, 8-30 is this month and anything beyond is a
 * process that has stopped. A receivables ageing's 30/60/90 is the wrong
 * frame here, because a bank line unmatched for 29 days is not "current" by
 * any reading an accountant would accept.
 */
final class AgeBucket
{
    public const ALL = ['0-1', '2-7', '8-30', '31+'];

    public static function of(int $days): string
    {
        return match (true) {
            $days <= 1 => '0-1',
            $days <= 7 => '2-7',
            $days <= 30 => '8-30',
            default => '31+',
        };
    }
}
