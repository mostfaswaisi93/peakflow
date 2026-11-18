<?php

declare(strict_types=1);

namespace App\Tenant\Payroll\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Payroll\Exceptions\PayrollRefused;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Claiming the right to see a salary - PF-256.
 *
 * ## Why this is a claim and not a filter
 *
 * The payroll tables carry a RESTRICTIVE row policy requiring
 * `peakflow.payroll_visible`. Until a session claims it the tables
 * return NOTHING - not an error, no rows. So a report that forgot to
 * filter, a CSV export, a developer's query and a bug in a route all
 * see the same empty set, and none of them leaks a salary.
 *
 * Screen-level hiding fails in a way nobody tests: the figure is still
 * in the JSON the page fetched and in the file somebody exported. A
 * salary is the one number in a business that everybody wants and
 * almost nobody should have.
 *
 * ## Every claim is audited, like a bank detail read
 *
 * The same decision {@see \App\Tenant\Parties\Services\BankDetailDisclosure}
 * records: the question an investigation asks is not "was it read" but
 * "why", and a reason nobody typed is a question nobody can answer.
 *
 * The claim is per transaction (`set_config(..., true)`), so it cannot
 * leak into the next request on a pooled connection or a long-lived
 * worker - which is how a permission check granted once becomes a
 * permission held forever.
 */
final class PayrollVisibility
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Claim visibility for the current transaction, and record why.
     *
     * @throws PayrollRefused
     */
    public function claim(string $entityId, string $reason): void
    {
        if (Gate::denies('payroll.view')) {
            throw PayrollRefused::notPermittedToSee();
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw PayrollRefused::aReadWithNoReason();
        }

        if (DB::connection('tenant')->transactionLevel() === 0) {
            throw PayrollRefused::aClaimOutsideATransaction();
        }

        /*
         | `true` - local to the transaction.
         |
         | A claim that outlived its transaction would be held by
         | whatever ran next on the same connection: the following
         | request under a pooled connection, the next job in a worker.
         | That is how a permission checked once becomes a permission
         | held forever, and nothing about it looks wrong.
         */
        DB::connection('tenant')->statement(
            "SELECT set_config('peakflow.payroll_visible', 'yes', true)",
        );

        $this->audit->record(
            category: 'read',
            action: 'payroll.viewed',
            subjectType: 'payroll',
            subjectId: $entityId,
            subjectLabel: 'payroll figures',
            reason: trim($reason),
            entityId: $entityId,
        );
    }

    /** Whether this session can currently see payroll at all. */
    public function claimed(): bool
    {
        $row = DB::connection('tenant')->selectOne('SELECT peakflow_payroll_visible() AS visible');

        return (bool) ($row->visible ?? false);
    }
}
