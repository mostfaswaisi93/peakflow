<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Platform\Enums\TenantStatus;
use App\Platform\Models\ActivityLog;
use App\Platform\Models\Operator;
use App\Platform\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The tenant list and lifecycle - PF-036.
 *
 * ## It shows metadata, never contents
 *
 * Name, slug, plan, status, schema version, when it was provisioned. No
 * invoice, no journal line, no party, no document - not even a count that
 * would reveal a customer's trading volume to someone browsing a list.
 *
 * That is not a UI choice, it is the control: an operator who can read a
 * tenant's data from here reads it unattributably, because nothing in that
 * tenant's own audit log says they did. Reading REQUIRES impersonation, which
 * requires the tenant's prior consent and writes an event in both planes. The
 * inconvenience is the feature.
 */
final class OpsTenantController
{
    public function index(Request $request): View
    {
        return view('ops.tenants', [
            'tenants' => Tenant::query()
                ->with(['subscriptions.plan'])
                ->when($request->filled('q'), fn ($q) => $q->where(
                    fn ($w) => $w->where('name', 'ilike', '%'.$request->string('q').'%')
                        ->orWhere('slug', 'ilike', '%'.$request->string('q').'%'),
                ))
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }

    /**
     * Suspend or resume a tenant.
     *
     * The confirmation naming the consequence lives in the view. The
     * consequence itself is stated here so it stays with the code that causes
     * it: a suspended tenant's users are signed out on their next request and
     * see the workspace-unavailable page, and nothing of theirs is deleted or
     * altered.
     */
    public function setStatus(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,suspended'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $operator = auth()->guard('ops')->user();
        $from = $tenant->status;
        $to = TenantStatus::from((string) $validated['status']);

        if ($from === $to) {
            return back()->with('ops_notice', __('لا تغيير: المساحة بهذه الحالة بالفعل.'));
        }

        $tenant->update(['status' => $to]);

        ActivityLog::create([
            'operator_id' => $operator instanceof Operator ? $operator->getKey() : null,
            'tenant_id' => $tenant->getKey(),
            'category' => 'tenant',
            'action' => 'tenant.'.$to->value,
            'context' => [
                'from' => $from->value,
                'to' => $to->value,
                // A reason is REQUIRED and minimum length enforced. "test" is
                // not a reason, and the person reading this in six months is
                // not the person who typed it.
                'reason' => $validated['reason'],
            ],
            'ip_address' => $request->ip(),
        ]);

        return back()->with('ops_notice', __('تم تحديث حالة المساحة.'));
    }
}
