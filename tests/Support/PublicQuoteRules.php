<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Sales\Enums\QuoteState;
use App\Tenant\Sales\Models\Quote;
use App\Tenant\Sales\Services\Quotes;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Services\TaxCodes;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * The customer-facing quote link - PF-089.
 *
 * ## Why this suite commits, and tears down afterwards
 *
 * It drives real HTTP requests through the kernel, and the first thing the
 * tenant middleware does is purge the tenant connection - which abandons any
 * transaction this suite was holding and takes its fixture with it. So the
 * fixture is committed, the requests run against it, and the rows are removed
 * at the end. The same reasoning as the concurrency suite, for the same
 * mechanical reason.
 *
 * ## What is being claimed
 *
 * That a link nobody was given opens nothing, and that a link somebody was
 * given opens exactly one document and no more. A customer-facing endpoint
 * that accepts a document and creates an order is the most attractive surface
 * this system has, and it has no authentication at all by design.
 */
final class PublicQuoteRules
{
    private string $entityId = '';

    private ?Entity $entity = null;

    private ?Party $party = null;

    private string $tenantSlug = '';

    private string $taxCodeId = '';

    private string $revenueAccountId = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->with('connection')->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['the public quote link (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->tenantSlug = (string) ($tenant->slug ?? $tenant->getKey());

        try {
            $this->seed();

            return [
                'the route is signed and rate-limited' => $this->theRouteIsGuarded(),
                'a signed link opens the quote' => $this->aSignedLinkOpens(),
                'an unsigned or tampered link opens nothing' => $this->anUnsignedLink(),
                'an expired link opens nothing' => $this->anExpiredLink(),
                'another entity\'s quote is not reachable' => $this->anotherEntity(),
                'accepting through the link records who accepted' => $this->accepting(),
                'accepting does not issue an invoice' => $this->acceptingIssuesNothing(),
                'declining records the answer' => $this->declining(),
                'a closed quote offers no buttons' => $this->closed(),
            ];
        } catch (Throwable $e) {
            return ['the public quote link could not be checked' => [$e::class.': '.$e->getMessage()]];
        } finally {
            $this->teardown();
        }
    }

    /** @return list<string> */
    public function theRouteIsGuarded(): array
    {
        $violations = [];
        $route = Route::getRoutes()->getByName('public.quote.show');

        if ($route === null) {
            return ['there is no named route for the customer-facing quote'];
        }

        $middleware = $route->gatherMiddleware();

        foreach (['signed', 'throttle'] as $needed) {
            $present = array_filter(
                $middleware,
                static fn (mixed $m): bool => \is_string($m) && str_starts_with($m, $needed),
            );

            if ($present === []) {
                $violations[] = sprintf(
                    'the route is not %s. A link to a document with no authentication at all needs '.
                    'both: the signature is what stops somebody reading other people\'s quotes by '.
                    'changing an id, and the rate limit is what stops them trying',
                    $needed,
                );
            }
        }

        foreach (['auth', 'auth:web'] as $unwanted) {
            if (\in_array($unwanted, $middleware, true)) {
                $violations[] = 'the customer-facing route requires a login, which is the thing it '.
                    'exists to avoid';
            }
        }

        /*
         | And the signature is checked BEFORE the tenant is resolved.
         |
         | Resolving a tenant named in an unsigned URL lets anybody probe
         | which slugs exist by watching which ones take longer.
         */
        $order = array_values(array_filter(
            $middleware,
            static fn (mixed $m): bool => \is_string($m) && ($m === 'signed' || $m === 'tenant.path'),
        ));

        if ($order !== [] && $order[0] !== 'signed') {
            $violations[] = 'the tenant is resolved before the signature is checked';
        }

        return $violations;
    }

    /** @return list<string> */
    public function aSignedLinkOpens(): array
    {
        $violations = [];
        $quote = $this->sentQuote();
        $response = $this->get(app(Quotes::class)->linkFor($quote, $this->tenantSlug));

        if ($response->getStatusCode() !== 200) {
            return [sprintf('a signed link answered %d', $response->getStatusCode())];
        }

        $body = (string) $response->getContent();

        foreach ([
            'the reference' => $quote->label(),
            'the line' => 'Supply and install',
            'the accept button' => 'أقبل',
            'the decline button' => 'أرفض',
        ] as $what => $needle) {
            if (! str_contains($body, (string) $needle)) {
                $violations[] = sprintf('the page does not show %s', $what);
            }
        }

        /*
         | And nothing else is reachable from it.
         |
         | A signed link is a key to one door. A page with navigation on it
         | is a page where the signature protects nothing.
         */
        foreach (['/overview', '/login', 'href="/ops'] as $wayOut) {
            if (str_contains($body, $wayOut)) {
                $violations[] = sprintf('the page links to %s, which is a second door', $wayOut);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function anUnsignedLink(): array
    {
        $violations = [];
        $quote = $this->sentQuote();
        $link = app(Quotes::class)->linkFor($quote, $this->tenantSlug);

        $unsigned = explode('?', $link)[0];
        $tampered = (string) preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $link);

        foreach ([
            'unsigned' => $unsigned,
            'with a forged signature' => $tampered,
        ] as $what => $url) {
            $status = $this->get($url)->getStatusCode();

            if ($status !== 403) {
                $violations[] = sprintf(
                    'a link %s answered %d rather than 403. The signature is the only thing between a '.
                    'stranger and every quote in the database',
                    $what, $status,
                );
            }
        }

        /*
         | And a different quote id under the same signature is refused,
         | because the id is inside what was signed.
         */
        $other = $this->sentQuote();
        $swapped = str_replace(
            (string) $quote->getKey(),
            (string) $other->getKey(),
            $link,
        );

        if ($this->get($swapped)->getStatusCode() !== 403) {
            $violations[] = 'swapping the quote id in a signed link reached another document';
        }

        return $violations;
    }

    /** @return list<string> */
    public function anExpiredLink(): array
    {
        $quote = $this->sentQuote();

        /*
         | A link whose window has closed.
         |
         | Built by signing with an expiry in the past rather than by waiting:
         | the claim is that Laravel's expiry is actually in the URL, and a
         | link that never expires is a key somebody forwards.
         */
        $expired = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'public.quote.show',
            Carbon::now()->subDay(),
            [
                'tenant' => $this->tenantSlug,
                'entity' => $this->entityId,
                'quote' => (string) $quote->getKey(),
            ],
        );

        $status = $this->get($expired)->getStatusCode();

        return $status === 403
            ? []
            : [sprintf('an expired link answered %d rather than 403', $status)];
    }

    /** @return list<string> */
    public function anotherEntity(): array
    {
        $quote = $this->sentQuote();
        $link = app(Quotes::class)->linkFor($quote, $this->tenantSlug);

        /*
         | The entity in the path is inside the signature, so this is a
         | forged link - and it has to fail at the signature rather than at
         | the row-level security, because a 404 would confirm the quote
         | exists in some other entity.
         */
        $elsewhere = str_replace($this->entityId, str_repeat('0', 26), $link);
        $status = $this->get($elsewhere)->getStatusCode();

        return $status === 403
            ? []
            : [sprintf('a link naming another entity answered %d rather than 403', $status)];
    }

    /** @return list<string> */
    public function accepting(): array
    {
        $violations = [];
        $quote = $this->sentQuote();

        $response = $this->post(
            $this->actionLink($quote, 'accept'),
            ['accepted_by' => 'Fahd al-Qahtani'],
        );

        if ($response->getStatusCode() >= 400) {
            return [sprintf('accepting answered %d', $response->getStatusCode())];
        }

        $quote->refresh();

        if ($quote->status !== QuoteState::Accepted) {
            $violations[] = sprintf('the quote is %s after being accepted', $quote->status->value);
        }

        if ((string) $quote->accepted_by_name !== 'Fahd al-Qahtani') {
            $violations[] = 'the acceptance does not record the name the customer gave, which is what '.
                'a dispute about "we never agreed to this" is argued with';
        }

        if ((string) $quote->accepted_from_ip === '') {
            $violations[] = 'the acceptance does not record where it came from';
        }

        // An acceptance with no name is refused by validation, not recorded.
        $second = $this->sentQuote();
        $this->post($this->actionLink($second, 'accept'), ['accepted_by' => '']);

        if ($second->refresh()->status === QuoteState::Accepted) {
            $violations[] = 'an anonymous acceptance was recorded';
        }

        return $violations;
    }

    /** @return list<string> */
    public function acceptingIssuesNothing(): array
    {
        $violations = [];
        $issued = DB::connection('tenant')->table('invoices')
            ->where('entity_id', $this->entityId)
            ->where('status', 'issued')
            ->count();

        $quote = $this->sentQuote();
        $this->post($this->actionLink($quote, 'accept'), ['accepted_by' => 'Fahd al-Qahtani']);

        $after = DB::connection('tenant')->table('invoices')
            ->where('entity_id', $this->entityId)
            ->where('status', 'issued')
            ->count();

        if ($after !== $issued) {
            $violations[] = 'accepting a quote through a public link ISSUED an invoice: a number '.
                'consumed from a gapless series, a posting written and a place taken in a hash chain, '.
                'at the moment somebody clicked a link on a phone';
        }

        return $violations;
    }

    /** @return list<string> */
    public function declining(): array
    {
        $quote = $this->sentQuote();

        $this->post(
            $this->actionLink($quote, 'decline'),
            ['reason' => 'the budget moved to next year'],
        );

        $quote->refresh();

        if ($quote->status !== QuoteState::Declined) {
            return [sprintf('the quote is %s after being declined', $quote->status->value)];
        }

        return (string) $quote->declined_reason === 'the budget moved to next year'
            ? []
            : ['the reason the customer gave was not recorded'];
    }

    /** @return list<string> */
    public function closed(): array
    {
        $violations = [];
        $quote = app(Quotes::class)->decline($this->sentQuote(), 'not this year');
        $body = (string) $this->get(app(Quotes::class)->linkFor($quote, $this->tenantSlug))->getContent();

        if (str_contains($body, 'أقبل')) {
            $violations[] = 'a declined quote still offers an accept button';
        }

        // And the endpoint refuses even if somebody keeps the form.
        $this->post($this->actionLink($quote, 'accept'), ['accepted_by' => 'Somebody']);

        if ($quote->refresh()->status !== QuoteState::Declined) {
            $violations[] = 'a declined quote was accepted by posting to the endpoint directly. A '.
                'disabled button is a courtesy; the refusal has to be in the service';
        }

        return $violations;
    }

    private function actionLink(Quote $quote, string $action): string
    {
        return \Illuminate\Support\Facades\URL::signedRoute('public.quote.'.$action, [
            'tenant' => $this->tenantSlug,
            'entity' => $this->entityId,
            'quote' => (string) $quote->getKey(),
        ]);
    }

    private function get(string $url): \Symfony\Component\HttpFoundation\Response
    {
        return app(Kernel::class)->handle(Request::create($url, 'GET'));
    }

    /** @param array<string, string> $payload */
    private function post(string $url, array $payload): \Symfony\Component\HttpFoundation\Response
    {
        /*
         | CSRF is not asserted here.
         |
         | The form carries a token and the middleware checks it; driving
         | that from a check means scraping the token out of the page, which
         | measures the scraper. What this suite is for is the signature, the
         | refusals and what gets recorded.
         */
        $request = Request::create($url, 'POST', $payload);
        $request->headers->set('X-CSRF-TOKEN', csrf_token());

        return app(Kernel::class)->handle($request);
    }

    private function sentQuote(): Quote
    {
        \assert($this->entity !== null && $this->party !== null);

        return app(Quotes::class)->send(app(Quotes::class)->draft(
            $this->entity,
            $this->party,
            [[
                'description' => 'Supply and install',
                'quantity' => '10.00000000',
                'unit_price' => '100.00000000',
                'tax_code_id' => $this->taxCodeId,
                'tax_rate' => '15.000000',
                'tax_category' => 'S',
                'revenue_account_id' => $this->revenueAccountId,
            ]],
            Carbon::now()->addDays(30),
            Carbon::now(),
            'Net 30',
        ));
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'PQ'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(6)),
            'legal_name' => 'Peakflow Quoting Co.',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $this->entity = $entity;
        $context->use($entity);

        $this->party = Party::create([
            'code' => 'PQ-C-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(6)),
            'legal_name' => 'Acme Industrial LLC',
            'is_customer' => true,
            'country' => 'SA',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        $this->taxCodeId = (string) app(TaxCodes::class)->introduce(
            entityId: $this->entityId,
            code: 'SA-S-15',
            category: TaxCategory::Standard,
            jurisdiction: 'SA',
            rate: '15.000000',
            from: CarbonImmutable::parse('2020-07-01'),
            labelAr: 'النسبة الأساسية',
            labelEn: 'Standard rate',
            source: 'the public quote check',
            reportingBoxes: ['sa' => ['output' => 'standard_sales']],
        )->getKey();

        $this->revenueAccountId = (string) \App\Tenant\Ledger\Models\Account::query()
            ->where('entity_id', $this->entityId)
            ->where('type', 'revenue')
            ->where('postable', true)
            ->orderBy('code')
            ->value('id');
    }

    /**
     * Remove the committed fixture, as the role that owns the tables.
     *
     * The application role cannot delete an issued invoice, and this suite
     * does not create one - but it does create quotes, a party, a tax code
     * and an entity, and leaving them behind would make the dev database a
     * pile of probe entities.
     */
    private function teardown(): void
    {
        if ($this->entityId === '') {
            return;
        }

        try {
            config(['database.connections.probe_owner' => array_merge(
                (array) config('database.connections.tenant'),
                [
                    'username' => config('database.connections.migrator.username'),
                    'password' => config('database.connections.migrator.password'),
                ],
            )]);

            DB::purge('probe_owner');
            $owner = DB::connection('probe_owner');

            foreach ([
                'quote_lines', 'quotes', 'sales_order_lines', 'sales_orders',
                'invoice_tax_breakdown', 'invoice_lines', 'invoice_references', 'invoices',
                'tax_rates', 'tax_codes', 'account_roles', 'accounts', 'periods', 'fiscal_years',
                'entity_currencies', 'entity_settings', 'number_series',
            ] as $table) {
                if ($owner->getSchemaBuilder()->hasTable($table)
                    && $owner->getSchemaBuilder()->hasColumn($table, 'entity_id')) {
                    $owner->table($table)->where('entity_id', $this->entityId)->delete();
                }
            }

            $owner->table('parties')->where('id', (string) $this->party?->getKey())->delete();
            $owner->table('entities')->where('id', $this->entityId)->delete();

            DB::purge('probe_owner');
        } catch (Throwable $e) {
            fprintf(\STDERR, "        (teardown left entity %s behind: %s)\n", $this->entityId, $e->getMessage());
        }
    }
}
