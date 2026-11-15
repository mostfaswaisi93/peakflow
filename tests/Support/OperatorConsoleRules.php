<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Enums\SubscriptionStatus;
use App\Platform\Models\ActivityLog;
use App\Platform\Models\Operator;
use App\Platform\Models\Tenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The tenant lifecycle, on screen - PF-036.
 *
 * ## What "a confirmation that names the consequence" means
 *
 * Not "are you sure?". That is a dialog people click through without reading,
 * and the reason they click through it is that it carries no information: it
 * asks a question whose answer is already yes, because they pressed the
 * button.
 *
 * A confirmation that names the consequence is one that tells somebody
 * something they might not know - that suspending signs every user out
 * immediately, that provisioning starts billing, that expiry deletes nothing.
 * So the rules below assert the confirmations are long enough to say something
 * and are translated keys present in both catalogs, which means somebody read
 * them.
 *
 * ## Export and delete are deliberately absent, and that is checked
 *
 * Deleting a tenant is irreversible and deserves the two-person flow the SoD
 * engine already provides. A check asserts no delete control exists, so the
 * deferral is a decision the codebase holds rather than a note in a task file
 * - the quiet addition of a delete button is exactly what it prevents.
 */
final class OperatorConsoleRules
{
    /** @var list<string> */
    private array $cleanup = [];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        try {
            return [
                'the lifecycle is on screen, not only in a command' => $this->theLifecycleIsOnScreen(),
                'every confirmation names the consequence' => $this->confirmationsNameTheConsequence(),
                'provisioning from the console works and is recorded' => $this->provisioningIsRecorded(),
                'expiry is commercial, recorded, and deletes nothing' => $this->expiryDeletesNothing(),
                'every lifecycle action requires a written reason' => $this->aReasonIsRequired(),
                'there is no delete control, and that is on purpose' => $this->thereIsNoDeleteControl(),
            ];
        } finally {
            $this->tearDown();
        }
    }

    private function view(): string
    {
        return (string) file_get_contents(
            \dirname(__DIR__, 2).'/resources/views/ops/tenants.blade.php',
        );
    }

    private function signIn(): Operator
    {
        $operator = Operator::query()->first();

        if ($operator === null) {
            throw new \RuntimeException('There is no operator to sign in as. Run the development seeder.');
        }

        auth()->guard('ops')->setUser($operator);

        return $operator;
    }


    /**
     * A POST through the real pipeline, with a real CSRF token.
     *
     * The first version posted without one and every request came back 419,
     * which is the CSRF middleware doing its job - and a check that cannot
     * tell a refusal from a token mismatch is a check that reports whichever
     * it expected. So the token and the session cookie come from a GET of the
     * page the form lives on, exactly as a browser's would, which has the
     * side benefit of exercising the protection rather than bypassing it.
     *
     * @param  array<string, string>  $payload
     */
    private function post(string $uri, array $payload): \Symfony\Component\HttpFoundation\Response
    {
        $this->signIn();

        $page = app(Kernel::class)->handle(Request::create('/ops/tenants', 'GET'));

        preg_match('/name="_token" value="([^"]+)"/', (string) $page->getContent(), $matches);

        $request = Request::create($uri, 'POST', $payload + ['_token' => $matches[1] ?? '']);

        foreach ($page->headers->getCookies() as $cookie) {
            $request->cookies->set($cookie->getName(), (string) $cookie->getValue());
        }

        $this->signIn();

        return app(Kernel::class)->handle($request);
    }

    /** @return list<string> */
    private function theLifecycleIsOnScreen(): array
    {
        $problems = [];

        try {
            $this->signIn();
        } catch (Throwable $e) {
            return [$e->getMessage()];
        }

        $response = app(Kernel::class)->handle(Request::create('/ops/tenants', 'GET'));

        if ($response->getStatusCode() !== 200) {
            return [sprintf(
                'GET /ops/tenants returned %d for a signed-in operator.',
                $response->getStatusCode(),
            )];
        }

        $html = (string) $response->getContent();

        $expected = [
            'name="plan"' => 'a plan selector, so provisioning does not need a shell on the production box',
            'name="slug"' => 'the identifier field. It becomes a database name, which is why it is validated rather than guessed',
            'expire-reason-' => 'an expiry control. `subscriptions:expire` sweeps on dates nightly; the deliberate single act is a different thing and wants a different record',
            'route(\'ops.tenants.status\'' => 'the suspend and resume control',
        ];

        foreach ($expected as $needle => $why) {
            if (! str_contains($html, $needle) && ! str_contains($this->view(), $needle)) {
                $problems[] = sprintf('The console offers no %s.', $why);
            }
        }

        /*
         | The identifier is rendered left-to-right.
         |
         | It becomes a database name and a role name on the cluster. An
         | identifier rendered right-to-left reorders around its hyphens, and
         | what the operator reads back is not what will be created.
         */
        if (! str_contains($this->view(), 'name="slug" dir="ltr"')) {
            $problems[] = 'The identifier field is not dir="ltr". It becomes a database name, and an '.
                'identifier in a right-to-left paragraph reorders around its hyphens - so what the '.
                'operator reads back is not what will be created.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function confirmationsNameTheConsequence(): array
    {
        $problems = [];
        $view = $this->view();

        preg_match_all('/onsubmit="return confirm\(/', $view, $confirms);

        /*
         | Three lifecycle forms: provision, suspend/resume, expire. The
         | search form is a GET and needs none.
         */
        if (\count($confirms[0]) < 3) {
            $problems[] = sprintf(
                'Only %d lifecycle form(s) carry a confirmation and there are three - provision, '.
                'suspend/resume and expire. A lifecycle action with no confirmation is one somebody '.
                'performs on the wrong row.',
                \count($confirms[0]),
            );
        }

        /*
         | Each confirmation must be a translated key, which means both
         | catalogs carry it and somebody read it.
         */
        preg_match_all("/confirm\(@js\(__\('((?:[^'\\\\]|\\\\.)*)'/", $view, $messages);

        /** @var array<string, string> $catalog */
        $catalog = json_decode(
            (string) file_get_contents(\dirname(__DIR__, 2).'/lang/en.json'),
            true,
        ) ?: [];

        foreach ($messages[1] as $message) {
            $key = str_replace("\\'", "'", $message);

            if (mb_strlen($key) < 60) {
                $problems[] = sprintf(
                    'The confirmation "%s" is %d characters. "Are you sure?" is a dialog people click '.
                    'through without reading, because it carries no information - it asks a question '.
                    'whose answer is already yes. A confirmation has to tell somebody something they '.
                    'might not know.',
                    $key,
                    mb_strlen($key),
                );
            }

            if (! isset($catalog[$key])) {
                $problems[] = sprintf(
                    'The confirmation "%s" is not in the English catalog, so an operator working in '.
                    'English gets the Arabic text - on the dialog that is supposed to make them stop '.
                    'and read.',
                    mb_strimwidth($key, 0, 60, '…'),
                );
            }
        }

        if ($messages[1] === []) {
            $problems[] = 'No confirmation message is a translated key, so none of them is in either '.
                'catalog and none has been reviewed.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function provisioningIsRecorded(): array
    {
        $problems = [];

        try {
            $operator = $this->signIn();
        } catch (Throwable $e) {
            return [$e->getMessage()];
        }

        $slug = 'probe-'.Str::lower(Str::random(8));
        $this->cleanup[] = $slug;

        $response = $this->post('/ops/tenants', [
            'name' => 'Console probe '.$slug,
            'plan' => 'starter',
            'slug' => $slug,
            'reason' => 'provisioning probe for PF-036',
        ]);

        if ($response->getStatusCode() !== 302) {
            return [sprintf(
                'POST /ops/tenants returned %d rather than a redirect. Body: %s',
                $response->getStatusCode(),
                mb_strimwidth(strip_tags((string) $response->getContent()), 0, 200, '…'),
            )];
        }

        $tenant = Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            return ['The console accepted a provisioning request and created no tenant.'];
        }

        if ($tenant->connection === null) {
            $problems[] = 'The tenant was created with no database connection record, so it would fail '.
                'at its first query with a message about a missing password.';
        }

        if ($tenant->subscriptions()->count() === 0) {
            $problems[] = 'The tenant was provisioned with no subscription, so nothing bills and the '.
                'plan ceilings have nothing to read.';
        }

        /*
         | The activity log is the point of doing this on screen.
         |
         | `tenants:provision` needs a shell on the production box, which makes
         | onboarding a customer something one person can do from a machine
         | nobody audits with no row anywhere saying it happened.
         */
        $log = ActivityLog::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('action', 'tenant.provisioned')
            ->first();

        if ($log === null) {
            $problems[] = 'Provisioning from the console wrote no activity-log row, which is the whole '.
                'reason it is on screen rather than only in a command.';

            return $problems;
        }

        if ($log->operator_id !== $operator->getKey()) {
            $problems[] = 'The activity-log row does not name the operator who did it.';
        }

        $context = (array) $log->context;

        if (! isset($context['reason']) || ! str_contains((string) $context['reason'], 'PF-036')) {
            $problems[] = 'The activity-log row does not carry the written reason. The person reading it '.
                'in six months is not the person who typed it.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function expiryDeletesNothing(): array
    {
        $problems = [];

        try {
            $this->signIn();
        } catch (Throwable $e) {
            return [$e->getMessage()];
        }

        $slug = 'probe-'.Str::lower(Str::random(8));
        $this->cleanup[] = $slug;

        $this->post('/ops/tenants', [
            'name' => 'Expiry probe '.$slug,
            'plan' => 'starter',
            'slug' => $slug,
            'reason' => 'expiry probe for PF-036',
        ]);

        $tenant = Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            return ['Could not provision a tenant to expire.'];
        }

        $database = $tenant->connection?->database;
        $statusBefore = $tenant->status;

        $response = $this->post('/ops/tenants/'.$tenant->getKey().'/expire', [
            'reason' => 'expiry probe for PF-036',
        ]);

        if ($response->getStatusCode() !== 302) {
            return [sprintf('POST expire returned %d rather than a redirect.', $response->getStatusCode())];
        }

        $subscription = $tenant->subscriptions()->latest('starts_on')->first();

        if ($subscription === null || $subscription->status !== SubscriptionStatus::Expired) {
            $problems[] = sprintf(
                'The subscription status is %s rather than expired.',
                $subscription?->status->value ?? 'missing',
            );
        }

        /*
         | `ends_on` as well as the status.
         |
         | Without it the nightly sweep sees a row with no end date and leaves
         | it alone, so the status and the dates disagree - and "when did this
         | contract end" has no answer in the data that answers every other
         | such question.
         */
        if ($subscription !== null && $subscription->ends_on === null) {
            $problems[] = 'The subscription was expired without setting ends_on, so the status and the '.
                'dates disagree and the nightly sweep will leave the row alone.';
        }

        /*
         | Expiry is NOT suspension.
         |
         | Both stop the tenant working, and conflating them is how a payment
         | dispute ends with a workspace suspended for "non-payment" and
         | nobody able to say whether the contract actually ended.
         */
        if ($tenant->refresh()->status !== $statusBefore) {
            $problems[] = sprintf(
                'Expiring the subscription also changed the tenant status from %s to %s. Expiry is '.
                'commercial and suspension is operational; conflating them means a payment dispute ends '.
                'with a workspace suspended for "non-payment" and nobody able to say whether the '.
                'contract ended.',
                $statusBefore->value,
                $tenant->status->value,
            );
        }

        // And nothing was deleted.
        if ($database !== null) {
            $exists = DB::connection('migrator')->selectOne(
                'SELECT 1 AS yes FROM pg_database WHERE datname = ?',
                [$database],
            );

            if ($exists === null) {
                $problems[] = sprintf(
                    'Expiring the subscription dropped the database %s. Expiry stops sign-ins; it '.
                    'deletes nothing, and renewing brings the workspace back.',
                    $database,
                );
            }
        }

        $log = ActivityLog::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('action', 'subscription.expired')
            ->exists();

        if (! $log) {
            $problems[] = 'Expiry wrote no activity-log row.';
        }

        return $problems;
    }

    /**
     * A reason is required, and the refusal is detected by its absence of effect.
     *
     * A Laravel validation failure on a form request is a 302 back with a
     * flashed error bag, and so is a success - so the status cannot tell them
     * apart, and the first version of this rule read `session('errors')`,
     * which is not the session the kernel wrote. So each case asserts the
     * SIDE EFFECT did not happen, which is unambiguous and is what actually
     * matters.
     *
     * @return list<string>
     */
    private function aReasonIsRequired(): array
    {
        $problems = [];

        $tenant = Tenant::query()->whereNotNull('provisioned_at')->first();

        if ($tenant === null) {
            return ['There is no provisioned tenant to test the reason requirement against.'];
        }

        // Provisioning, detected by no tenant appearing.
        foreach (['no reason at all' => [], 'a four-character reason' => ['reason' => 'test']] as $description => $extra) {
            $slug = 'probe-'.Str::lower(Str::random(8));
            $this->cleanup[] = $slug;

            $this->post('/ops/tenants', $extra + [
                'name' => 'Reasonless '.$slug,
                'plan' => 'starter',
                'slug' => $slug,
            ]);

            if (Tenant::query()->where('slug', $slug)->exists()) {
                $problems[] = sprintf(
                    'A tenant was provisioned with %s. "test" is not a reason, and the person reading '.
                    'the activity log in six months is not the person who typed it.',
                    $description,
                );
            }
        }

        // Expiry, detected by the subscription not moving.
        $subscription = $tenant->subscriptions()->latest('starts_on')->first();

        if ($subscription !== null) {
            $before = $subscription->status;

            foreach (['no reason at all' => [], 'a four-character reason' => ['reason' => 'test']] as $description => $payload) {
                $this->post('/ops/tenants/'.$tenant->getKey().'/expire', $payload);

                if ($subscription->refresh()->status !== $before) {
                    $problems[] = sprintf(
                        'A subscription was expired with %s.',
                        $description,
                    );

                    break;
                }
            }
        }

        // Suspension, detected by the tenant status not moving.
        $statusBefore = $tenant->status;

        $this->post('/ops/tenants/'.$tenant->getKey().'/status', ['status' => 'suspended']);

        if ($tenant->refresh()->status !== $statusBefore) {
            $problems[] = 'A tenant was suspended with no reason. Suspending signs every user out '.
                'immediately, and the operator who did it is the only person who knows why.';

            // Put it back, or the rest of this environment is suspended.
            $tenant->update(['status' => $statusBefore]);
        }

        return $problems;
    }

    /** @return list<string> */
    private function thereIsNoDeleteControl(): array
    {
        $problems = [];
        $view = $this->view();

        foreach (['tenants.destroy', 'method="DELETE"', "'DELETE'", 'tenants.export'] as $needle) {
            if (str_contains($view, $needle)) {
                $problems[] = sprintf(
                    'The console carries %s. Deleting a tenant is irreversible and deserves the '.
                    'two-person flow the SoD engine already provides - that is a task of its own, not a '.
                    'button added quietly. If this is deliberate, the task is the place to say so.',
                    $needle,
                );
            }
        }

        $routes = (string) file_get_contents(\dirname(__DIR__, 2).'/routes/web.php');

        if (preg_match('/Route::delete\([^)]*tenants/', $routes) === 1) {
            $problems[] = 'routes/web.php declares a DELETE route for a tenant.';
        }

        return $problems;
    }

    private function tearDown(): void
    {
        $migrator = DB::connection('migrator');
        $grammar = $migrator->getQueryGrammar();

        foreach ($this->cleanup as $slug) {
            try {
                $tenant = Tenant::withTrashed()->where('slug', $slug)->first();

                if ($tenant === null) {
                    continue;
                }

                $connection = $tenant->connection;

                if ($connection !== null) {
                    $migrator->statement(
                        'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ?',
                        [$connection->database],
                    );
                    $migrator->statement(sprintf('DROP DATABASE IF EXISTS %s', $grammar->wrap($connection->database)));
                    $migrator->statement(sprintf('DROP ROLE IF EXISTS %s', $grammar->wrap($connection->username)));
                    $connection->forceDelete();
                }

                ActivityLog::query()->where('tenant_id', $tenant->getKey())->forceDelete();
                $tenant->subscriptions()->forceDelete();
                $tenant->forceDelete();
            } catch (Throwable) {
                // A probe left behind is noise, not a failure of the rule.
            }
        }

        $this->cleanup = [];
    }
}
