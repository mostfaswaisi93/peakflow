# Phase 00 — Foundations, tooling and guardrails

**17 tasks · 60 points**

The guardrails come first because they are nearly free now and nearly impossible later. An architecture test that forbids a `float` in the money path is a one-line rule applied to an empty repository and a three-week audit applied to a finished one. Nothing in this phase is a feature; everything in it is a constraint that the next thirteen phases are written inside.

---

### PF-001 · Laravel 13 skeleton on PHP 8.3, repository hygiene
`—` · **2 pts** · depends: — · standards: Conventional Commits, SemVer 2.0, Keep a Changelog

The application skeleton, plus the repository conventions that are annoying to introduce once there is history to rewrite.

- [x] Laravel 13 on PHP 8.3, `composer.json` pinning the extensions the app actually requires (`intl`, `pgsql`, `dom`, `xsl`, `zip`, `sodium`, `mbstring`). `bcmath`/`gmp` are under `suggest`: `brick/math` falls back to a pure-PHP calculator, exactly as correct and slower
- [x] `.editorconfig`, `.gitattributes` with `* text=auto eol=lf`, `.gitignore`
- [x] `CHANGELOG.md` in Keep a Changelog format; `CONTRIBUTING.md` stating the Conventional Commits types in use
- [ ] A commit-message check runs locally via a git hook installed by `composer setup`
- [ ] `composer setup` takes a fresh clone to a running application in one command

### PF-002 · Formatting and static analysis at maximum
`—` · **3 pts** · depends: PF-001 · standards: —

- [ ] Laravel Pint configured; `./vendor/bin/pint --test` is clean
- [ ] PHPStan 2 with Larastan at level `max`, clean, with an **empty** baseline
- [x] Rector 2 configured in `rector.php` for the PHP 8.3 and Laravel 13 rule sets. Moved to `suggest` because its Composer source could not be resolved through this environment's proxy; the config is committed and ready
- [x] Stylelint with a rule banning physical CSS properties (`left`, `right`, `margin-left`, `padding-right`, `text-align: left|right`, `border-radius` long-hand corners)
- [ ] A documented rule that a new baseline entry requires a reviewer's approval and a linked task

### PF-003 · Deptrac module layers
`—` · **3 pts** · depends: PF-001 · standards: —

The dependency table in the README is the configuration, not a description of it.

- [x] `deptrac.yaml` declares every module in `app/Tenant`, `app/Platform`, `app/Exchange` and `app/Support` as a layer
- [x] The permitted-dependency ruleset matches the README's bounded-context table exactly
- [x] `Ledger` may depend on `Tenancy` and `Support` only
- [x] `Reporting` may read every module and is forbidden from being depended **upon** by any domain module
- [ ] A deliberate violation added in a scratch branch fails the check

### PF-004 · Pest 4 with separated suites
`—` · **3 pts** · depends: PF-001 · standards: —

- [x] Suites declared separately: `unit`, `arch`, `feature`, `concurrency`, `browser`
- [ ] `unit` and `arch` need no database and run in under 10 seconds
- [ ] `feature` runs against a real PostgreSQL 16+ database, created and migrated by the harness, each test wrapped in a transaction that rolls back
- [ ] `concurrency` runs with parallel processes and is excluded from the default run
- [ ] A documented note that the tenant suite **cannot** run on SQLite, and why - deferrable constraint triggers, RLS, partitioning and `numeric` semantics are the thing under test

### PF-005 · The architecture tests that encode the non-negotiables
`—` · **5 pts** · depends: PF-004 · standards: — · `risk:high`

Each of these is a rule that a code review will eventually miss.

- [x] No `float` or `double` type declaration anywhere under the money path; no `(float)` cast; no `floatval`
- [ ] No Eloquent cast to `float`/`real` on a column in the money path
- [ ] Every migration creating a table in the financial schema group declares a non-nullable `entity_id`
- [x] Every model under `app/Tenant` declares `getConnectionName(): 'tenant'`
- [x] Nothing in `app/Tenant/Reporting` performs a write (`save`, `insert`, `update`, `delete`, `DB::statement`)
- [x] No module reaches into another module's `Models` namespace
- [ ] Blade and Livewire templates contain no physical CSS property and no untranslated literal string

### PF-006 · The money support layer
`Support/Money` · **5 pts** · depends: PF-001 · standards: ISO 4217 · `risk:high`

- [x] `Money`, `Rate` and `Scale` value objects wrapping `brick/money` and `brick/math`
- [x] Every arithmetic operation requires an explicit `RoundingPolicy`; there is no default parameter
- [x] `RoundingPolicy` carries the mode (half-up, half-even, down) and the target scale, and is resolvable per jurisdiction. `forProfile()` reads `config('jurisdictions.rounding')`, so adding a country is a profile plus fixtures and never an edit to the money layer. An unknown profile falls back to the platform default rather than throwing - refusing to round because a profile forgot to declare a mode would make a new jurisdiction unusable until somebody noticed, and the noticing would happen on an invoice
- [x] Amount, unit-price and rate scales are the only three that exist, and a fourth cannot be constructed
- [x] Currencies with exponent 0 (`JPY`, `KRW`, `VND`, `CLP`, `ISK`), exponent 3 (`BHD`, `JOD`, `KWD`, `OMR`, `TND`, `LYD`, `IQD`) and the non-decimal `MGA`/`MRU` each have a passing test
- [x] Property-based test: for any amount and any currency, `format` then `parse` round-trips exactly. Generated amounts crossed with eight currencies and two locales, seed printed on failure - three-decimal currencies, zero-exponent currencies, negatives, and an Arabic locale whose formatted output carries bidirectional marks and a narrow non-breaking space that nothing else would think to type
- [x] The round trip is of the **displayed** value. `format()` renders the currency's own exponent, so 1234.5678 SAR displays as 1,234.57 and reads back as 1234.5700; claiming the stored value round-trips would make the property false for most amounts in a ledger that stores four places
- [x] `Money::parse()` exists because a form shows what `format()` produced and takes back what somebody typed, and those have to be the same value. ICU first - it knows the locale's separators, digit shape and accounting-negative form - then a normalising pass for what people actually type: Latin digits pasted into an Arabic form, a thousands separator left in, the whole field copied with its symbol
- [x] **ICU must consume the whole string.** It parses as far as it can and reports where it stopped, so `about 12k` read back as 12 and an Arabic-formatted amount under an English locale read back as its first two digits. Both measured. A form that accepts "about 12k" as twelve riyals is the worse of the two, because the figure is plausible, it posts, and nobody can trace where it came from
- [x] The fallback uses a **whitelist of permitted characters**, not a strip of everything else. Stripping non-digits turned `about 12k` into 12 as well, so anything outside the separators people and ICU actually produce now refuses the whole value
- [x] One implementation of "what counts as a number somebody typed". `ManualJournal` carried its own normaliser - the second copy of the same rules, and the copy that stripped letters

### PF-007 · ISO code registries
`Support/Iso` · **3 pts** · depends: PF-006 · standards: ISO 4217, ISO 3166-1, ISO 639-1, UN/CEFACT Rec 20 & 21

- [x] Currency registry with code, numeric code, minor unit and display symbol
- [x] Country registry with alpha-2, alpha-3, numeric, and whether an IBAN applies
- [x] Unit-of-measure registry on UN/CEFACT Rec 20 with the Rec 21 extensions, carrying the code, the symbol and a bilingual label
- [x] Language registry limited to the locales the application actually serves. Not ISO 639-1: a locale is a promise the product has been translated, and 184 codes would be 182 promises it cannot keep, each offerable in a picker that then renders English. A check asserts every served tag has its `lang/*.json`
- [x] The registry carries direction, numbering system, endonym and plural categories, because each had been a guess somewhere. Direction was a private constant inside `SetLocale`, which made the middleware the authority on a fact the whole view layer depends on
- [x] The plural categories are **verified against ICU**, not asserted: a six-category message is formatted across 0-220 plus fractional and large values and the reachable categories collected. Arabic reaches all six, English two. A file claiming otherwise lets a translator write four forms that never render
- [x] Registries are vendored data files with a documented provenance and version, not hard-coded arrays with no source. `database/iso/{currencies,countries,units,languages}.tsv` with `provenance.json`; the classes do nothing but read them, and a check fails if a `PACKED` constant comes back
- [x] The provenance record states what was **not** verified, and the field is required. Nothing here was downloaded - this environment cannot reach ISO, SWIFT or UNECE - and a record implying a fetch it never made converts an unknown into a false assurance that nobody re-examines
- [x] Where a second source exists in-process it was used: ICU/CLDR knows every ISO 4217 and ISO 3166-1 code, and all 174 currencies and 249 countries were confirmed against it with zero unknowns. Rec 20 has no second source, and its manifest entry says so rather than implying one
- [x] A digest per file, compared by `bin/verify iso`, so an edit nobody recorded is caught at the one place it would be noticed
- [x] The loader refuses rather than repairs: a short row, an extra field, a padded field, a duplicate key and a header with no rows each make the file unusable. A padded code is refused rather than trimmed, because trimming makes the file disagree with itself and the diff that added the space is invisible
- [x] Found and fixed on the way: CLDR deliberately differs from ISO 4217 on 21 currencies (`IQD` 3 vs 0 and ten more where inflation retired the subunit), so ICU's defaults would render an Iraqi invoice as `1,235`. The divergences are enumerated and frozen; a new one fails the build
- [x] Found and fixed on the way: appending `-u-nu-latn` was being skipped as a no-op, and CLDR's default for `ar` is `arab` - so a configuration that said Western digits produced `١٬٢٣٤٫٥٦`. The subtag is now always written out, and one class builds it
- [x] Found and fixed on the way: `config/app.php` shipped the skeleton's `env('APP_LOCALE', 'en')` while every comment in the codebase says Arabic-first, so a deployment that forgot the variable would serve English to every Arabic user

### PF-008 · IBAN and BIC value objects
`Support/Iso` · **3 pts** · depends: PF-007 · standards: ISO 13616, ISO/IEC 7064, ISO 9362

- [x] IBAN validated for per-country length and MOD-97-10 check digits; stored normalized without spaces, displayed in groups of four
- [x] BIC validated structurally for 8 and 11 characters, with the country segment cross-checked against the country registry
- [x] An invalid IBAN cannot be constructed - validation is in the constructor, not in a separate validator that a caller can forget
- [x] Test vectors from the ISO 13616 registry for at least 25 countries, including the three-decimal-currency countries. 80 countries, every one of the seven three-decimal markets (BH, IQ, JO, KW, LY, OM, TN) among them. The vector lives in `database/iso/iban.tsv` beside the length, because **the vector is the length column's only check**: a wrong length refuses the country's own published IBAN, and the message blames the customer's account rather than our table
- [x] Every candidate vector was put through MOD 97-10 before being committed - the check digits are a checksum over the whole string, so a validating one is right to about 1 part in 97. 80 of 81 passed; the failure was **dropped rather than corrected**, because a corrected vector is a computed one and a computed one cannot confirm the length it was computed from
- [x] Each vector is then broken the two ways a person breaks one - one character changed, two adjacent characters swapped - and must be refused both times, 80 countries each. The transposition picks the first two *adjacent and different* characters, because several vectors are long runs of zeros and swapping two identical characters asserts nothing
- [x] Seven countries have no sourced vector, so their lengths are unconfirmed. The list is asserted exactly: it may shrink and may not grow
- [x] The BBAN structure is deliberately **not** validated, and the reason is recorded: a structure table from memory would refuse real accounts, which stops a customer being paid, where accepting a structurally odd IBAN that passes MOD 97-10 costs a rejected payment message with a reason. Deferred to Phase 06, against the registry file itself
- [x] Domestic identifier shapes (UK sort code, US routing with its own ABA checksum, BSB, IFSC) validated where IBAN does not apply, each in a constructor so an invalid one cannot exist as an object
- [x] `DomesticScheme::hasCheckDigit()` says whether a scheme can detect a typo at all, because the answer changes what a form must do: without one, reading the number back is the only defence, and a form that believes the value was validated offers neither
- [x] The UK sort code is shape-only and says so. VocaLink modulus checking needs the published `valacdos` weight table; a table from memory would refuse genuine accounts. The honest version of a missing check is a documented absence, not a regular expression dressed up as one
- [x] Found on the way: the ABA checksum does **not** catch every adjacent transposition, which this code claimed it did. `322271627` with its 2 and 7 swapped passes. The adjacent weight differences in 3-7-1 are 4, -6 and 2 - all even - so a swap of digits differing by five moves the sum by a multiple of ten. Verified exhaustively over all 90 ordered pairs at all three weight positions: exactly the ten differing by five, and no others. The check now asserts the blind spot's exact shape and counts how often it was exercised
- [x] Found on the way: `000000000` passes the ABA checksum, so the Federal Reserve prefix ranges are enforced too - and the first version of that check used numbers which failed the checksum, so it proved nothing. Each case is now computed to pass the checksum and still sit in an unallocated range

### PF-009 · Canonicalization and hash-chain support
`Support/Hashing` · **3 pts** · depends: PF-001 · standards: RFC 8785 (JCS), XML C14N 1.1, SHA-256

- [x] JSON canonicalization per RFC 8785 for the JSON representation
- [x] XML Canonical form for the XML syntaxes - **C14N 1.0 and Exclusive C14N 1.0, and 1.1 is not reachable**. PHP's `DOMNode::C14N()` takes a boolean `exclusive` flag and nothing else; libxml 2.9.14 does not expose a 1.1 mode. 1.1's normative differences are `xml:base` fixup and inherited `xml:id`/`xml:lang`/`xml:space` when canonicalizing a document **subset**, which whole-document canonicalization never hits - but that is derived from the specifications' difference list and not verified against a 1.1 implementation, so a document carrying `xml:base` or `xml:id` is **refused** rather than canonicalized under an algorithm its signature did not claim
- [x] `xml:lang` and `xml:space` are deliberately **not** refused, asserted by a check, because both algorithms treat them identically for a whole document and refusing them would rule out every Arabic document that declares its language
- [x] A DOCTYPE is refused outright, and the reason is measured three ways. `LIBXML_NOENT` reads as "no entities" and means "substitute entities": with it, a document declaring `file:///...` canonicalized to **the contents of that file**. With a null external entity loader it was blocked - so the loader is a real second defence, not decoration. Without `NOENT` the entity canonicalizes to **nothing**, so a document whose content sits in an entity hashes as though the content were absent while a conformant processor includes it. Both are wrong, so neither is chosen
- [x] Asserted both directions: attribute order, whitespace between attributes, quote style, an XML declaration, a comment, CDATA and a numeric character reference all hash the **same**; a changed amount, a trailing zero, two elements swapped, a different namespace, whitespace between elements and a leading space in text all hash **differently**
- [x] Exclusive canonicalization drops the unused namespace declaration, keeps the used one, and differs from inclusive - because if the two agreed, one of them would not be doing what it claims. Fragment selection refuses an XPath matching nothing (a signature over nothing verifies), matching several nodes, or matching a non-element
- [x] `ChainLink` computing `canonical_hash` from a document's legally significant fields and linking `previous_hash`
- [x] Canonicalization is stable across key insertion order - the same payload shuffled 200 times must give one hash
- [x] Stable across locale, asserted structurally because only `C` and `C.UTF-8` are installed here: no locale-sensitive function (`strcoll`, `setlocale`, `localeconv`, `strftime`, ...) may appear anywhere in `Support/Hashing`. A check that silently skips is worse than one that asserts something else
- [x] Stable across PHP versions, in the only form one environment can assert it: a golden vector - a fixed payload and its SHA-256 written down as a literal, containing the three things that would move it (Arabic text, a U+2028, and keys whose sorted order is not their insertion order)
- [x] Found and fixed on the way: `json_encode` escapes U+2028 and U+2029 even under `JSON_UNESCAPED_UNICODE`, and **RFC 8785 requires them literal**. A description containing a LINE SEPARATOR produced a canonical form no conformant implementation would produce, so the chain would verify here and fail at a counterparty - the exact failure the chain exists to detect, arriving from the thing meant to detect it. The escaper is now written out, which also removes PHP's JSON extension from the stability question entirely
- [x] Found and fixed on the way: an integer beyond 2^53-1 was serialized unchanged. RFC 8785 processes numbers as IEEE-754 doubles, so a conformant implementation reading `9007199254740993` serializes `9007199254740992`. Refused, in the same safe direction as the float refusal
- [x] Invalid UTF-8 is refused rather than hashed: every other implementation either refuses it or substitutes U+FFFD, and both give a different hash
- [x] The UTF-16 ordering rule asserts the RFC's own example **and** that UTF-8 byte order still disagrees with it - otherwise the conversion would be dead code and the rule would test nothing
- [x] The refusal names the integer a conformant reader would use instead, computed with integer arithmetic because the architecture rules forbid a float in this path - and checked against `(float) $value` across 2,000 random values plus the edges, because the cast *is* the IEEE-754 rounding being reimplemented. 20,014 values agreed
- [x] Found on the way: without a 2^63 guard that arithmetic **hung the process** on `PHP_INT_MIN` - negating it overflows into a float and shifting a float never reaches zero. A wrong hash is bad; a request that never returns is worse. The exponent loop is now bounded as well as guarded, and the check wraps the call, because an unguarded call turns the rule into a crash rather than a finding
- [x] A chain walker that reports the **first** divergence rather than only that the chain is broken

### PF-010 · PostgreSQL roles and least privilege
`—` · **5 pts** · depends: PF-001 · standards: — · `risk:high` `type:security`

The application must not be able to do things it has no business doing, regardless of what the code says.

- [x] Three roles: `peakflow_app` (the application), `peakflow_migrator` (migrations and nothing else), `peakflow_readonly` (reporting replicas, analytics)
- [x] `peakflow_app` has no `ALTER`, no `DROP`, no `CREATE`, and no `DELETE` on tables holding posted financial rows
- [x] ~~`peakflow_app` cannot connect to the platform database at all~~ - **reframed, and the criterion as written is wrong.** A request resolves its tenant by reading the platform database, so the role the application connects as has to be able to connect to it; a role that cannot is a role that cannot serve a request. What the criterion reaches for is the separation, and the separation runs the other way round - which is achievable and stronger: the platform credential reaches the registry and **no tenant's data**, and a tenant credential reaches one customer and **not the registry**, and not another customer
- [x] **Both directions were open when first measured.** A PostgreSQL database with an empty ACL is not one nobody may connect to: an empty ACL means the built-in default applies, which grants `CONNECT` and `TEMPORARY` to `PUBLIC` - and `GRANT CONNECT TO <role>` does not change that, because there was never anything to revoke. So the grant script looked correct and every login role on the cluster could open a session in every database
- [x] Measured: the platform credential opened a session in a live tenant database and read **129 table names out of `pg_class` with their row estimates** - `accounts (~336 rows)`, `account_roles (~120 rows)`. The schema grants held, so no business row came back; how many accounts a customer has and whether they have posted anything are still facts about that customer, and `pg_class` is world-readable and cannot be revoked. The boundary has to be the connection
- [x] Fixed in three places: `REVOKE ALL ... FROM PUBLIC` before the grants in the init SQL; `TenantProvisioner` seals every database in the step that creates it (sealing afterwards is a window, not a fix); and `php artisan databases:seal` for the clusters that already have customers on them
- [x] `databases:seal` means *exactly* the named roles - a hand-made grant survives a PUBLIC revoke, and the first matrix run found two nobody could point to. It prints every role that loses access by name, because the one outcome nobody wants is it quietly revoking what an undocumented job depends on
- [x] Migrations run as `peakflow_migrator` from a separate process with its own credentials (`DB_MIGRATOR_USERNAME`/`DB_MIGRATOR_PASSWORD`), asserted **behaviourally**: `CREATE TABLE` is attempted on the tenant connection and must be refused, and on the migrator's and must succeed. Over-revoking is not caution - a migrator that cannot create a table is a tenant that can never be migrated again
- [x] The first version of that check grepped `TenantsMigrate.php` for the word "migrator" and failed - correctly, in that the word is not there, and uselessly, because the command delegates to the provisioner. A rule that reads files cannot tell delegation from absence
- [x] The connect matrix is built from `has_database_privilege()` and not by parsing `datacl`, because an empty `datacl` is the case that matters and parsing it reads as "nobody" - the misreading that produced the hole. And because a rule built only from the catalog asserts the catalog agrees with itself, the crossings are also attempted with a live PDO connection, for **every** tenant pair in a deterministic order
- [x] A test asserts the application connection is refused when it attempts a forbidden operation - proving the grant, not the intention

### PF-011 · Local development environment
`—` · **3 pts** · depends: PF-001 · standards: —

- [x] `compose.yaml` with PostgreSQL 16, Redis 8, Meilisearch, Gotenberg, veraPDF and MailPit, with profiles for `laptop`, `documents`, `search` and `full`
- [ ] Volumes and healthchecks so `composer dev` can wait for readiness rather than racing it
- [ ] Documented resource expectations and a cut-down profile for a laptop
- [ ] A fresh clone reaches a seeded, logged-in application with `composer setup && composer dev` and nothing else

### PF-012 · Configuration skeleton and `.env.example`
`—` · **3 pts** · depends: PF-001 · standards: The Twelve-Factor App

- [x] `config/tenancy.php`, `money.php`, `tax.php`, `banking.php`, `exchange.php`, `pdf.php`, `jurisdictions.php`, `telemetry.php`
- [ ] Every value has a documented default and a documented safe range; risk-bearing values document their platform-enforced ceiling
- [ ] `.env.example` is complete, is the file the test suite reads defaults from, and a test fails if a `config()` key is read that `.env.example` does not account for
- [x] `EXCHANGE_DRIVER=log` and `PDF_DRIVER=stub` are the committed defaults, so a fresh clone works with no third-party credentials

### PF-013 · CI pipeline, authored and deliberately disabled
`—` · **3 pts** · depends: PF-002, PF-003, PF-004 · standards: —

The pipeline is specified now and switched on by a decision with a name on it.

- [x] The complete workflow is committed as `docs/operations/ci-pipeline.yml.example` and is **not** in `.github/workflows/`, so nothing executes
- [x] The file is valid GitHub Actions YAML, verified by a schema check, so enabling it is a move and not a rewrite
- [x] Stage order is explicit and justified: lint and static analysis, then the asset build, then unit and architecture, then feature against real PostgreSQL and Redis, then concurrency, contract, golden-file, accessibility and visual regression, then mutation testing
- [x] An enablement runbook in [`../operations/ci-cd.md`](../operations/ci-cd.md) lists the secrets required and the expected first-run failures
- [x] A note recording that the deploy must gate on the suite, and why: a guard nobody runs is not a guard

### PF-014 · Structured logging with a field allowlist
`—` · **3 pts** · depends: PF-001 · standards: — · `type:security`

- [x] JSON log formatter; a correlation id generated per request and propagated into queued jobs and outbound HTTP. The job's id is **adopted from the payload**, so a request that queues a PDF render and a webhook produces one chain rather than three; the outbound header goes on through a global HTTP middleware, so no call site can forget it - and the id comes back on the response, including the error response, because the error page is exactly when somebody needs it
- [x] Context is built from an **allowlist** of permitted fields, never a redaction blocklist. The allowlist applies at the top level and recursively; below the top level a key survives when it is allowlisted **or** its value is not a string, because `['kinds' => ['missing' => 2]]` is a map of classification to count and filtering its keys against a field allowlist is a category error - the first version dropped the whole map and the drift alert said "3 findings" with no breakdown
- [x] A second layer inspects **value shapes**, because a field allowlist cannot know what a caller put in a legitimate field: an IBAN passed under the allowlisted name `reference` survived every field check and reached the line. Only shapes with a check digit are scrubbed - IBAN by mod-97, card numbers by Luhn - so document numbers, account codes and ULIDs survive. A scrubber that eats the diagnostics is one somebody turns off
- [x] A test asserts that a payload containing an IBAN, a token, a password, an email, an API key and a card number produces a log line containing none of them - through the real processor and the real formatter, reading the bytes a collector would read. The message is scrubbed too: "could not validate `<iban>`" is an ordinary exception message, and the exception message **is** the log line
- [x] Tenant and entity ids are present on every line; personal data is not. The ambient ids cannot be overridden by a caller - letting it pass its own would let it pass the wrong one, and a line attributed to the wrong customer is worse than one attributed to none. The entity is read from the **database session variable** rather than the container, because that is the value row-level security is actually using
- [x] Personal data is absent **by construction**: `email`, `name`, `phone` and `address` are simply not on the list, which is the whole reason it is an allowlist
- [x] A check asserts the processor is **attached**, not that the tap is declared. The first version of the tap named a Monolog constant that does not exist in this version; Laravel catches errors while resolving a log channel - reasonably - so the tap never ran, the formatter stayed line-based, and every secret went to the log. It was configured, it looked configured, and the probe that found it reported five leaks that were all real
- [x] The correlation id is sanitised and bounded. The header is attacker-controlled and lands in log lines, so a newline in it forges a log entry - which a JSON formatter does **not** stop on its own, because the line is valid JSON holding a string that a line-based collector then splits

### PF-015 · Error handling and guard-aware responses
`—` · **3 pts** · depends: PF-001 · standards: RFC 9457

- [x] API errors render as `application/problem+json` with `type`, `title`, `status`, `detail`, `instance` and a field-addressed `errors` array
- [ ] Unauthenticated requests get a JSON 401 on the API, a redirect to the operator login under `/ops/*`, and the tenant login elsewhere
- [ ] All five error views render, offer a way back, and survive the database being the thing that broke - asserting a status code never reaches these templates, so they are rendered directly in the test
- [x] A `problem` type URI registry, so an error type is documented rather than invented at the call site

### PF-016 · Localization skeleton and its coverage test
`—` · **5 pts** · depends: PF-001 · standards: ICU MessageFormat, CLDR

- [x] `ar` and `en` catalogs; `ar` is the default locale and `en` the fallback
- [ ] ICU MessageFormat is the mechanism, so Arabic's six plural categories are expressible
- [x] `SetLocale` resolves from the user, then the tenant default, then `Accept-Language`, and sets `dir` accordingly
- [x] A test asserts: every `__()` key in the codebase exists in **both** catalogs, both files are sorted, their key sets are identical, and no English value has been left as Arabic text
- [ ] A documented rule, with an example, that a translated label is never concatenated with a value - because Arabic adjectives agree in gender and `":label جديدة"` is wrong for every masculine noun

### PF-017 · Vite, Tailwind v4 and the token scaffold
`—` · **5 pts** · depends: PF-001 · standards: WCAG 2.2 AA

Enough of the design system to build real screens against, with the full systematic pass deferred to Phase 11.

- [ ] Vite 8 with the Laravel plugin; fonts vendored and served from the build output, nothing fetched from a third party at runtime
- [x] Tailwind CSS 4 configured **in CSS**; no `tailwind.config.js` exists and a comment records that adding one will not be read
- [x] `@custom-variant dark (&:where(.dark, .dark *));` is present, with a comment explaining that without it every `dark:` utility silently does nothing
- [x] OKLCH token scaffold: `--c-*` under `:root` and `.dark`, mapped once inside `@theme`
- [x] `@source` declares the compiled-view directory explicitly, with a note never to build immediately after clearing the view cache
- [x] `font-variant-numeric: tabular-nums` applied to a `.figure` utility used by every numeric cell
