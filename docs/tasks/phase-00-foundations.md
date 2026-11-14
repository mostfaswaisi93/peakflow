# Phase 00 — Foundations, tooling and guardrails

**17 tasks · 60 points**

The guardrails come first because they are nearly free now and nearly impossible later. An architecture test that forbids a `float` in the money path is a one-line rule applied to an empty repository and a three-week audit applied to a finished one. Nothing in this phase is a feature; everything in it is a constraint that the next thirteen phases are written inside.

---

### PF-001 · Laravel 13 skeleton on PHP 8.4, repository hygiene
`—` · **2 pts** · depends: — · standards: Conventional Commits, SemVer 2.0, Keep a Changelog

The application skeleton, plus the repository conventions that are annoying to introduce once there is history to rewrite.

- [ ] Laravel 13 on PHP 8.4, `composer.json` pinning the extensions the app actually requires (`bcmath`, `intl`, `pgsql`, `redis`, `dom`, `xsl`, `gmp`, `sodium`)
- [ ] `.editorconfig`, `.gitattributes` with `* text=auto eol=lf`, `.gitignore`
- [ ] `CHANGELOG.md` in Keep a Changelog format; `CONTRIBUTING.md` stating the Conventional Commits types in use
- [ ] A commit-message check runs locally via a git hook installed by `composer setup`
- [ ] `composer setup` takes a fresh clone to a running application in one command

### PF-002 · Formatting and static analysis at maximum
`—` · **3 pts** · depends: PF-001 · standards: —

- [ ] Laravel Pint configured; `./vendor/bin/pint --test` is clean
- [ ] PHPStan 2 with Larastan at level `max`, clean, with an **empty** baseline
- [ ] Rector 2 configured for the PHP 8.4 and Laravel 13 rule sets; `--dry-run` is clean
- [ ] Stylelint with a rule banning physical CSS properties (`left`, `right`, `margin-left`, `padding-right`, `text-align: left|right`, `border-radius` long-hand corners)
- [ ] A documented rule that a new baseline entry requires a reviewer's approval and a linked task

### PF-003 · Deptrac module layers
`—` · **3 pts** · depends: PF-001 · standards: —

The dependency table in the README is the configuration, not a description of it.

- [ ] `deptrac.yaml` declares every module in `app/Tenant`, `app/Platform`, `app/Exchange` and `app/Support` as a layer
- [ ] The permitted-dependency ruleset matches the README's bounded-context table exactly
- [ ] `Ledger` may depend on `Tenancy` and `Support` only
- [ ] `Reporting` may read every module and is forbidden from being depended **upon** by any domain module
- [ ] A deliberate violation added in a scratch branch fails the check

### PF-004 · Pest 4 with separated suites
`—` · **3 pts** · depends: PF-001 · standards: —

- [ ] Suites declared separately: `unit`, `arch`, `feature`, `concurrency`, `browser`
- [ ] `unit` and `arch` need no database and run in under 10 seconds
- [ ] `feature` runs against a real PostgreSQL 18 database, created and migrated by the harness, each test wrapped in a transaction that rolls back
- [ ] `concurrency` runs with parallel processes and is excluded from the default run
- [ ] A documented note that the tenant suite **cannot** run on SQLite, and why - deferrable constraint triggers, RLS, partitioning and `numeric` semantics are the thing under test

### PF-005 · The architecture tests that encode the non-negotiables
`—` · **5 pts** · depends: PF-004 · standards: — · `risk:high`

Each of these is a rule that a code review will eventually miss.

- [ ] No `float` or `double` type declaration anywhere under the money path; no `(float)` cast; no `floatval`
- [ ] No Eloquent cast to `float`/`real` on a column in the money path
- [ ] Every migration creating a table in the financial schema group declares a non-nullable `entity_id`
- [ ] Every model under `app/Tenant` declares `getConnectionName(): 'tenant'`
- [ ] Nothing in `app/Tenant/Reporting` performs a write (`save`, `insert`, `update`, `delete`, `DB::statement`)
- [ ] No module reaches into another module's `Models` namespace
- [ ] Blade and Livewire templates contain no physical CSS property and no untranslated literal string

### PF-006 · The money support layer
`Support/Money` · **5 pts** · depends: PF-001 · standards: ISO 4217 · `risk:high`

- [ ] `Money`, `Rate` and `Scale` value objects wrapping `brick/money` and `brick/math`
- [ ] Every arithmetic operation requires an explicit `RoundingPolicy`; there is no default parameter
- [ ] `RoundingPolicy` carries the mode (half-up, half-even, down) and the target scale, and is resolvable per jurisdiction
- [ ] Amount, unit-price and rate scales are the only three that exist, and a fourth cannot be constructed
- [ ] Currencies with exponent 0 (`JPY`, `KRW`, `VND`, `CLP`, `ISK`), exponent 3 (`BHD`, `JOD`, `KWD`, `OMR`, `TND`, `LYD`, `IQD`) and the non-decimal `MGA`/`MRU` each have a passing test
- [ ] Property-based test: for any amount and any currency, `format` then `parse` round-trips exactly

### PF-007 · ISO code registries
`Support/Iso` · **3 pts** · depends: PF-006 · standards: ISO 4217, ISO 3166-1, ISO 639-1, UN/CEFACT Rec 20 & 21

- [ ] Currency registry with code, numeric code, minor unit and display symbol
- [ ] Country registry with alpha-2, alpha-3, numeric, and whether an IBAN applies
- [ ] Unit-of-measure registry on UN/CEFACT Rec 20 with the Rec 21 extensions, carrying the code, the symbol and a bilingual label
- [ ] Language registry limited to the locales the application actually serves
- [ ] Registries are vendored data files with a documented provenance and version, not hard-coded arrays with no source

### PF-008 · IBAN and BIC value objects
`Support/Iso` · **3 pts** · depends: PF-007 · standards: ISO 13616, ISO/IEC 7064, ISO 9362

- [ ] IBAN validated for per-country length and MOD-97-10 check digits; stored normalized without spaces, displayed in groups of four
- [ ] BIC validated structurally for 8 and 11 characters, with the country segment cross-checked against the country registry
- [ ] An invalid IBAN cannot be constructed - validation is in the constructor, not in a separate validator that a caller can forget
- [ ] Test vectors from the ISO 13616 registry for at least 25 countries, including the three-decimal-currency countries
- [ ] Domestic identifier shapes (UK sort code, US routing with its own ABA checksum, BSB, IFSC) validated where IBAN does not apply

### PF-009 · Canonicalization and hash-chain support
`Support/Hashing` · **3 pts** · depends: PF-001 · standards: RFC 8785 (JCS), XML C14N 1.1, SHA-256

- [ ] JSON canonicalization per RFC 8785 for the JSON representation
- [ ] XML Canonical form (C14N 1.1) for the XML syntaxes
- [ ] `ChainLink` computing `canonical_hash` from a document's legally significant fields and linking `previous_hash`
- [ ] Canonicalization is stable across PHP versions, key insertion order and locale - asserted by test
- [ ] A chain walker that reports the **first** divergence rather than only that the chain is broken

### PF-010 · PostgreSQL roles and least privilege
`—` · **5 pts** · depends: PF-001 · standards: — · `risk:high` `type:security`

The application must not be able to do things it has no business doing, regardless of what the code says.

- [ ] Three roles: `peakflow_app` (the application), `peakflow_migrator` (migrations and nothing else), `peakflow_readonly` (reporting replicas, analytics)
- [ ] `peakflow_app` has no `ALTER`, no `DROP`, no `CREATE`, and no `DELETE` on tables holding posted financial rows
- [ ] `peakflow_app` cannot connect to the platform database at all
- [ ] Migrations run as `peakflow_migrator` from a separate process with its own credentials
- [ ] A test asserts the application connection is refused when it attempts a forbidden operation - proving the grant, not the intention

### PF-011 · Local development environment
`—` · **3 pts** · depends: PF-001 · standards: —

- [ ] `compose.yaml` with PostgreSQL 18, Redis 8, Meilisearch, Gotenberg, veraPDF and MailPit
- [ ] Volumes and healthchecks so `composer dev` can wait for readiness rather than racing it
- [ ] Documented resource expectations and a cut-down profile for a laptop
- [ ] A fresh clone reaches a seeded, logged-in application with `composer setup && composer dev` and nothing else

### PF-012 · Configuration skeleton and `.env.example`
`—` · **3 pts** · depends: PF-001 · standards: The Twelve-Factor App

- [ ] `config/tenancy.php`, `money.php`, `tax.php`, `banking.php`, `exchange.php`, `pdf.php`, `jurisdictions.php`, `telemetry.php`
- [ ] Every value has a documented default and a documented safe range; risk-bearing values document their platform-enforced ceiling
- [ ] `.env.example` is complete, is the file the test suite reads defaults from, and a test fails if a `config()` key is read that `.env.example` does not account for
- [ ] `EXCHANGE_DRIVER=log` and `PDF_DRIVER=stub` are the committed defaults, so a fresh clone works with no third-party credentials

### PF-013 · CI pipeline, authored and deliberately disabled
`—` · **3 pts** · depends: PF-002, PF-003, PF-004 · standards: —

The pipeline is specified now and switched on by a decision with a name on it.

- [ ] The complete workflow is committed as `docs/operations/ci-pipeline.yml.example` and is **not** in `.github/workflows/`, so nothing executes
- [ ] The file is valid GitHub Actions YAML, verified by a schema check, so enabling it is a move and not a rewrite
- [ ] Stage order is explicit and justified: lint and static analysis, then the asset build, then unit and architecture, then feature against real PostgreSQL and Redis, then concurrency, contract, golden-file, accessibility and visual regression, then mutation testing
- [ ] An enablement runbook in [`../operations/ci-cd.md`](../operations/ci-cd.md) lists the secrets required and the expected first-run failures
- [ ] A note recording that the deploy must gate on the suite, and why: a guard nobody runs is not a guard

### PF-014 · Structured logging with a field allowlist
`—` · **3 pts** · depends: PF-001 · standards: — · `type:security`

- [ ] JSON log formatter; a correlation id generated per request and propagated into queued jobs and outbound HTTP
- [ ] Context is built from an **allowlist** of permitted fields, never a redaction blocklist - a blocklist fails open the first time someone adds a field
- [ ] A test asserts that a payload containing an IBAN, a token and a password produces a log line containing none of them
- [ ] Tenant and entity ids are present on every line; personal data is not

### PF-015 · Error handling and guard-aware responses
`—` · **3 pts** · depends: PF-001 · standards: RFC 9457

- [ ] API errors render as `application/problem+json` with `type`, `title`, `status`, `detail`, `instance` and a field-addressed `errors` array
- [ ] Unauthenticated requests get a JSON 401 on the API, a redirect to the operator login under `/ops/*`, and the tenant login elsewhere
- [ ] All five error views render, offer a way back, and survive the database being the thing that broke - asserting a status code never reaches these templates, so they are rendered directly in the test
- [ ] A `problem` type URI registry, so an error type is documented rather than invented at the call site

### PF-016 · Localization skeleton and its coverage test
`—` · **5 pts** · depends: PF-001 · standards: ICU MessageFormat, CLDR

- [ ] `ar` and `en` catalogs; `ar` is the default locale and `en` the fallback
- [ ] ICU MessageFormat is the mechanism, so Arabic's six plural categories are expressible
- [ ] `SetLocale` resolves from the user, then the tenant default, then `Accept-Language`, and sets `dir` accordingly
- [ ] A test asserts: every `__()` key in the codebase exists in **both** catalogs, both files are sorted, their key sets are identical, and no English value has been left as Arabic text
- [ ] A documented rule, with an example, that a translated label is never concatenated with a value - because Arabic adjectives agree in gender and `":label جديدة"` is wrong for every masculine noun

### PF-017 · Vite, Tailwind v4 and the token scaffold
`—` · **5 pts** · depends: PF-001 · standards: WCAG 2.2 AA

Enough of the design system to build real screens against, with the full systematic pass deferred to Phase 11.

- [ ] Vite 8 with the Laravel plugin; fonts vendored and served from the build output, nothing fetched from a third party at runtime
- [ ] Tailwind CSS 4 configured **in CSS**; no `tailwind.config.js` exists and a comment records that adding one will not be read
- [ ] `@custom-variant dark (&:where(.dark, .dark *));` is present, with a comment explaining that without it every `dark:` utility silently does nothing
- [ ] OKLCH token scaffold: `--c-*` under `:root` and `.dark`, mapped once inside `@theme`
- [ ] `@source` declares the compiled-view directory explicitly, with a note never to build immediately after clearing the view cache
- [ ] `font-variant-numeric: tabular-nums` applied to a `.figure` utility used by every numeric cell
