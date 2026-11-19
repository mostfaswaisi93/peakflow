<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Platform\Enums\SubscriptionStatus;
use App\Platform\Enums\TenantStatus;
use App\Platform\Models\ActivityLog;
use App\Platform\Models\Operator;
use App\Platform\Models\Plan;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

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
        /*
         | What schema every tenant SHOULD be on - PF-246.
         |
         | Read once rather than per row: it is the same answer for the whole
         | list and it comes from the migration files on disk. A per-tenant
         | lookup would also make the slowest page in the console scale with
         | the fleet.
         |
         | The console needs it because "migrating" is a status a tenant can
         | be stuck in - `tenants:migrate --unstick` exists for exactly that -
         | and a list that shows only the word tells an operator a migration
         | is happening, not whether it is progressing or which tenants it has
         | reached. The version beside the status is what turns the first into
         | the second.
         */
        $expected = app(TenantProvisioner::class)->currentSchemaVersion();

        return view('ops.tenants', [
            'expectedSchemaVersion' => $expected,
            /*
             | `sort_order`, not `name`.
             |
             | There is no `name` column: a plan carries `name_ar` and
             | `name_en`, because the plan names are shown to customers and a
             | single column would show one language to both. Ordering by a
             | localised column would also put the list in a different order
             | per locale, which is worse than either - so the explicit
             | `sort_order` the table carries is what orders it.
             */
            'plans' => Plan::query()->where('active', true)->orderBy('sort_order')->orderBy('key')->get(),
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
    /**
     * @no-permission-required: KNOWN LIMITATION, not an exemption on merit.
     *
     * The operator plane has no role model - every operator is a full
     * administrator, so any of them can suspend any customer. That is a real
     * gap and it is recorded as an open item in PF-036 rather than hidden
     * behind this annotation. What exists today: `auth:ops`, a mandatory
     * written reason, and an activity-log row naming who did it.
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

    /**
     * Provision a tenant from the console - PF-036.
     *
     * ## Why this is on screen and not only a command
     *
     * `tenants:provision` has existed since PF-022 and needs a shell on the
     * production box. That makes onboarding a customer a thing one person can
     * do, from a machine nobody audits, with no row anywhere saying it
     * happened. The command is still the right tool for a scripted migration
     * of many tenants; a single customer being onboarded belongs where the
     * rest of the lifecycle is, next to the activity log that records it.
     *
     * ## Synchronous, and that is a measured decision
     *
     * Provisioning creates a role, creates a database, runs 131 tables of
     * migrations and seeds the baseline. Measured locally: **1.00 second**. A
     * loaded cluster will be slower, and the honest ceiling is a request
     * timeout rather than a progress bar - so if this starts taking long
     * enough to matter, the fix is a queued job and a `provisioning` status,
     * not a longer timeout.
     *
     * A failure is NOT half a tenant: the provisioner is a reversible
     * sequence that tears down the role and the database it created, which
     * PF-024's rollback checks prove by injecting a failure at each step.
     */
    /**
     * @no-permission-required: KNOWN LIMITATION, not an exemption on merit.
     *
     * The same gap as setStatus: the operator plane has no role model, so
     * every operator is a full administrator and any of them can provision or
     * expire. That is recorded as an open item in PF-036 rather than hidden
     * behind this annotation. What exists today: `auth:ops`, a mandatory
     * written reason of at least eight characters, and an activity-log row
     * naming who did it.
     */
    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'plan' => ['required', 'string', 'exists:platform.plans,key'],
            /*
             | The slug is optional and validated anyway.
             |
             | It becomes a database name and a role name on the cluster, so
             | the characters it may contain are not a cosmetic question. The
             | provisioner normalises and refuses too, which is where the
             | authority lives; this exists so the refusal arrives as a field
             | error rather than as a 500.
             */
            'slug' => ['nullable', 'string', 'min:2', 'max:40', 'regex:/\A[a-z0-9][a-z0-9-]*[a-z0-9]\z/'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $operator = auth()->guard('ops')->user();
        $slug = \is_string($validated['slug'] ?? null) && $validated['slug'] !== '' ? $validated['slug'] : null;

        try {
            $tenant = $provisioner->create((string) $validated['name'], (string) $validated['plan'], $slug);
        } catch (Throwable $e) {
            /*
             | Reported as a field error, not a 500.
             |
             | Every refusal the provisioner makes - a slug already taken, an
             | unknown plan, a cluster that would not create the role - is a
             | thing the operator can act on, and a stack trace is not.
             */
            throw ValidationException::withMessages([
                'name' => $e->getMessage(),
            ]);
        }

        ActivityLog::create([
            'operator_id' => $operator instanceof Operator ? $operator->getKey() : null,
            'tenant_id' => $tenant->getKey(),
            'category' => 'tenant',
            'action' => 'tenant.provisioned',
            'context' => [
                'slug' => $tenant->slug,
                'plan' => (string) $validated['plan'],
                'reason' => $validated['reason'],
            ],
            'ip_address' => $request->ip(),
        ]);

        return back()->with('ops_notice', __('تم إنشاء المساحة :name.', ['name' => $tenant->name]));
    }

    /**
     * End a tenant's subscription now - PF-036.
     *
     * ## Expiry is not suspension, and the difference is the point
     *
     * Suspension is operational and reversible: a tenant is suspended because
     * something is wrong, with a reason, and resumed when it is not. Expiry is
     * COMMERCIAL and is an end state - the subscription is over, and the
     * workspace stops accepting sign-ins because nobody is paying for it.
     *
     * Both stop the tenant working, and conflating them is how a payment
     * dispute ends with a customer's workspace suspended for "non-payment"
     * and nobody able to say whether the contract actually ended.
     *
     * `subscriptions:expire` sweeps on dates, nightly. This is the deliberate
     * single act, which is a different thing and wants a different record.
     *
     * ## Nothing is deleted
     *
     * The databases stay, the subscription row stays with its new status, and
     * a resumed subscription brings the workspace back. Deleting a tenant is
     * irreversible and deserves the two-person flow the SoD engine already
     * provides - that is a task of its own and not a button added quietly
     * here.
     */
    /**
     * @no-permission-required: KNOWN LIMITATION, not an exemption on merit.
     *
     * The same gap as setStatus: the operator plane has no role model, so
     * every operator is a full administrator and any of them can provision or
     * expire. That is recorded as an open item in PF-036 rather than hidden
     * behind this annotation. What exists today: `auth:ops`, a mandatory
     * written reason of at least eight characters, and an activity-log row
     * naming who did it.
     */
    public function expire(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $subscription = $tenant->subscriptions()
            ->whereIn('status', [
                SubscriptionStatus::Trial->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->latest('starts_on')
            ->first();

        if ($subscription === null) {
            return back()->with('ops_notice', __('لا يوجد اشتراك سارٍ لإنهائه.'));
        }

        $from = $subscription->status;

        $subscription->update([
            'status' => SubscriptionStatus::Expired,
            /*
             | `ends_on` is set to today as well as the status.
             |
             | Without it the nightly sweep sees a row with no end date and
             | leaves it alone, so the status and the dates disagree - and the
             | question "when did this contract end" has no answer in the data
             | that answers every other such question.
             */
            'ends_on' => now()->startOfDay(),
        ]);

        ActivityLog::create([
            'operator_id' => ($operator = auth()->guard('ops')->user()) instanceof Operator ? $operator->getKey() : null,
            'tenant_id' => $tenant->getKey(),
            'category' => 'subscription',
            'action' => 'subscription.expired',
            'context' => [
                'from' => $from->value,
                'to' => SubscriptionStatus::Expired->value,
                'reason' => $validated['reason'],
            ],
            'ip_address' => $request->ip(),
        ]);

        return back()->with('ops_notice', __('تم إنهاء اشتراك :name.', ['name' => $tenant->name]));
    }
}
