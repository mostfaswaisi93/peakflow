# Testing strategy

Twelve layers. Each one exists because of a class of defect the others cannot see, and that is the organizing idea: **a layer whose failures another layer already catches is not worth its runtime.**

## The honest constraint, stated first

> **The tenant suite requires a real PostgreSQL. It cannot run on SQLite.**
>
> Deferrable constraint triggers, row-level security, declarative partitioning and `numeric` semantics **are the thing under test**. A suite that silently skipped them would report green while testing a different system, which is worse than no suite.
>
> With no server reachable, several hundred tests fail **identically** with a connection error. That looks alarming and is not a code failure. Start PostgreSQL before reading anything into a red run. `--testsuite=unit` and `--testsuite=arch` need no database and run in under ten seconds.

## The layers

| Layer | Tool | Catches what nothing else does |
|-------|------|-------------------------------|
| **`bin/verify`** | none | The architecture rules and worked examples with no test runner, so the checks run anywhere |
| **Unit** | Pest | Money arithmetic, rounding policies, IBAN/BIC validation, parsers, posting-rule output |
| **Property-based** | Pest + generators | Invariants across inputs nobody thought to write a case for |
| **Architecture** | Pest arch + Deptrac | Rules a code review misses consistently |
| **Feature** | Pest + real PostgreSQL | HTTP and Livewire behaviour **against the real constraints** |
| **Concurrency** | Parallel workers | Races. A single-process test of a race proves nothing |
| **Golden file** | Snapshots + official corpora | Serialization drift against a published standard |
| **Contract** | OpenAPI | The spec and the implementation diverging |
| **Mutation** | Infection | A test suite that passes against broken arithmetic |
| **Browser** | Pest 4 browser | Whether a workflow is completable by keyboard |
| **Accessibility** | axe + arch + manual | Defects invisible to everyone who can see the screen |
| **Visual regression** | Playwright screenshots | RTL, dark mode and density regressions |
| **Performance** | k6 + `EXPLAIN` assertions | An N+1, a missed index, a partition not pruned |

## The global proof

```php
// tests/TenantTestCase.php — runs after every feature test that posts.
protected function tearDown(): void
{
    if ($this->hasPostedAnything()) {
        $this->artisan('ledger:verify', ['entity' => $this->entity->id])
             ->assertExitCode(0);
    }
    parent::tearDown();
}
```

> **An individual assertion can be written to pass. A global invariant cannot.**

A test that creates an invoice, takes a payment and reconciles it ends by re-proving that every entry balances, every subledger equals its control account, in-transit equals released-and-unreconciled, inter-entity pairs net to zero, nothing posted into a non-accepting period, and no posted entry was mutated. It is **wired into the test case** rather than remembered per test, because the tests that most need it are the ones whose author was focused elsewhere.

## Property-based testing

The places where example-based tests give false confidence:

```php
it('keeps a balanced entry balanced across a storage round trip', function () {
    $lines = JournalLines::generateSummingToZero();      // random count, accounts, currencies
    $entry = JournalEntry::post($lines);
    expect($entry->fresh()->lines->sum('functional_amount'))->toEqualDecimal('0.0000');
})->repeat(500);

it('computes a document identically twice', function () {
    $doc = InvoiceDraft::generate();                      // random lines, rates, discounts
    expect($doc->computeTotals())->toEqual($doc->computeTotals());
})->repeat(500);

it('round-trips money exactly for any amount and currency', function () {
    [$amount, $currency] = MoneyGenerator::any();         // includes exponent 0, 2, 3 and MGA/MRU
    expect(Money::parse(Money::of($amount, $currency)->format()))->toEqual(Money::of($amount, $currency));
})->repeat(1000);
```

Invariants covered: a zero-sum line set commits and any other is refused; a document's line sum equals its net; the breakdown sums to the tax total; rounding is idempotent; allocation never exceeds the payment or the open balance; any sequence of allocations and reversals leaves the document balance and the control account in agreement.

## Architecture tests

Each of these is a rule a reviewer will eventually let through.

```php
arch('no float in the money path')
    ->expect('App')
    ->not->toUse(['float', 'floatval', 'doubleval']);

arch('reporting never writes')
    ->expect('App\Tenant\Reporting')
    ->not->toUse(['save', 'insert', 'update', 'delete', 'DB::statement']);

arch('tenant models declare their connection')
    ->expect('App\Tenant')
    ->classes()->extending(Model::class)
    ->toHaveMethod('getConnectionName');

test('every financial table has entity_id and an RLS policy', ...);
test('no template contains a physical CSS property', ...);
test('every label names a control, every for matches one id, no id is duplicated', ...);
test('every write route checks a permission', ...);
test('every __() key exists in both catalogs, sorted, with identical key sets', ...);
test('only three decimal scales exist', ...);
```

## Concurrency

Real parallel workers against real PostgreSQL. The suite is excluded from the default run because it is slow, and it gates the pipeline because it covers the failures that only appear under load.

| Test | Asserts |
|------|---------|
| **Gapless numbering** (`PF-094`) | 200 invoices, 20 workers, numbers exactly `1..200`. Variants: a third failing after the lock point, two series not blocking, a crashed worker consuming no number |
| Payment-run double release | Two concurrent releases produce one released run and one conflict |
| Recurring generation | The same occurrence fired from two workers produces exactly one invoice |
| Idempotency replay | The same key fired concurrently produces one document and two identical responses |
| Statement import | The same file imported concurrently produces no duplicate transactions |
| Allocation | Concurrent allocations against one invoice cannot over-allocate it |

## Golden files

Fixtures from the **official** EN 16931, Peppol and ZATCA corpora, with their expected serialized output committed. Snapshot tests byte-compare and run the output through the full four-stage validation chain.

**Negative fixtures** are as important: a document that must fail each major `BR-CO-*` rule, asserting it fails **for the right reason** rather than merely failing. A profile cannot be merged without a corpus.

## Mutation testing

A minimum MSI gate on `Support\Money`, `Ledger`, `Tax` and `Settlement`.

> **A money test suite that still passes when the arithmetic is mutated is not testing the arithmetic.** Infection changes a `+` to a `-`, a `>=` to a `>`, a rounding mode, a boundary — and any surviving mutant is a test that asserts the shape of the answer rather than its value.

This is the layer that catches a test like `expect($invoice->tax)->toBeFloat()`.

## Performance

```php
it('costs the same number of queries at 5 rows as at 500', function () {
    $five    = countQueries(fn () => $this->get('/invoices?limit=5'));
    $fivehun = countQueries(fn () => $this->get('/invoices?limit=500'));
    expect($fivehun)->toBe($five);
});

it('prunes partitions on the trial balance', function () {
    $plan = explain(fn () => TrialBalance::for($entity, '2026-11-30'));
    expect($plan)->toContain('Index Scan')
                 ->and($plan)->partitionsScanned()->toBeLessThan(3);
});
```

**An N+1 is invisible to every other kind of test** — the page renders, the data is right, and it is 400 queries. A 10-million-line seeded dataset is the fixture for the statement budgets, and a k6 profile covers month-end close with twenty accountants in one tenant.

## Accessibility

| Method | Cadence | Covers |
|--------|---------|--------|
| Contrast matrix | Every build | Every legitimate token pair, both themes |
| Template arch tests | Every build | Labels, ids, skip link, `<main tabindex="-1">` |
| axe scan | Every build, every route | ~40% of real issues — useful, not sufficient |
| Browser keyboard tests | Every build | Grid multi-row edit, reconciliation, dialog focus |
| **Screen reader walkthrough** | **Once per release, recorded** | Announcement quality, reading order, whether a workflow is completable |

An automated scan is a floor, not a conformance claim.

## Visual regression

Every screen in **LTR and RTL**, **light and dark**, at **all three densities** — plus every print view, diffed as a rendered PDF rather than eyeballed in a browser.

That matrix is twelve variants per screen, which sounds excessive until a logical-property regression left-aligns every figure column in Arabic and no functional test notices.

## What is deliberately not tested

- **Framework behaviour.** Laravel's own routing and validation are tested by Laravel.
- **Third-party library internals.** `brick/money`'s arithmetic is tested upstream; our use of it is tested here.
- **Government endpoints in CI.** Clearance is validated against a sandbox during development and stubbed in the pipeline. `EXCHANGE_DRIVER=log` exercises the state machine without a partner.
- **Exhaustive UI permutations.** Representative screens per pattern, not every screen in every state, with the state coverage test asserting each route *declares* its states.

## Running it

```bash
php bin/verify                                  # no runner, no database
php artisan test --testsuite=Unit,Architecture  # no database, under 10s
php artisan test --testsuite=feature            # needs PostgreSQL + Redis
php artisan test --testsuite=concurrency        # parallel workers
php artisan test --testsuite=browser            # needs the built assets
./vendor/bin/infection --min-msi=85 --filter=app/Tenant/Ledger
composer test                                   # clears config, then everything
```

**Build the assets before running the PHP tests.** The translation-coverage and template-markup tests read compiled output, so a build failure would otherwise let a broken page through a green suite.
