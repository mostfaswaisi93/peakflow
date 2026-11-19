<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

/**
 * What a span is a span OF - PF-240.
 *
 * The four that matter in this product, and they are the four the task names:
 * the HTTP request, the queued job, the outbound transport, and the database
 * statement. `Internal` is the fifth and is the one a business operation gets
 * - `invoice.issue` is not a server span, and calling it one would make the
 * request duration and the issue duration indistinguishable in a viewer.
 *
 * An enum rather than a free string because the kind is what a trace viewer
 * groups by, and a second spelling of "database" is a second row in every
 * aggregate.
 */
enum SpanKind: string
{
    case Server = 'server';
    case Consumer = 'consumer';
    case Client = 'client';
    case Database = 'database';
    case Internal = 'internal';
}
