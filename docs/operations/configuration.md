# Configuration

> `.env.example` · `config/*.php` · `App\Support\Config\ProductionConfiguration` · PF-012 · `bin/verify config`

Three places, with one job each.

| Where | Holds |
|-------|-------|
| `.env.example` | Every key a deployment can set, and what a fresh clone uses. The index |
| `config/*.php` | The reasoning, beside the value. These files are 48–75% comment by line, on purpose |
| `ProductionConfiguration` | The safe ranges for the values whose wrong setting is **silent**, enforced by refusing to boot |

A documented range that nothing enforces is a sentence somebody read once
during onboarding. So the ranges that matter are code.

## `.env.example` is complete, and exclusion is declared

153 keys are read by `config/*.php`. 76 are set. The other 77 are mostly drivers
PeakFlow does not use — memcached, DynamoDB, SQS, Beanstalkd, S3, Postmark,
Papertrail — and setting them all to their own defaults would add ninety lines
that say nothing.

So the file carries two declaration blocks and `bin/verify config` requires
every key to be covered by one of them:

```
# UNUSED: MEMCACHED_HOST MEMCACHED_PORT ...     read by a config file, deliberately not set
# SET-ONLY: SCOUT_DRIVER  PF-072 ...            set here, read by something other than config/
```

That is the whole point. Leaving a key out becomes a visible decision in a diff
rather than an omission nobody can distinguish from an oversight — and a key
whose config entry was deleted cannot sit in the file for years waiting for
somebody to set it and wonder why nothing happens.

`DB_SSLMODE` is in neither list by name and **not set**, which is itself the
decision: `prefer` is libpq's default and the dangerous one, a local cluster has
no certificate, and a key present with an unsafe value reads as a reviewed
choice. Production must set it, and the boot guard refuses to start without it.

## The settings a production deployment may not get wrong

Every rule below is a value whose wrong setting **works**. That is the selection
criterion, and it is why the list is short: a wrong database host fails
immediately and loudly, while `sslmode=prefer` connects, serves every request
correctly, and sends every row in plaintext the one time somebody can answer for
the server.

`App\Support\Config\ProductionConfiguration::assert()` runs from
`AppServiceProvider::boot()` when `app()->isProduction()`, and **throws**. A
deployment that has not read this page does not start, and the message names
every wrong value at once — a deployment with four wrong values should learn
about four, not about one per restart.

| Key | Fresh clone | Production | What the wrong value does |
|-----|-------------|------------|---------------------------|
| `APP_DEBUG` | `true` | `false` | The debug page prints the failing query **with its bindings** — which here are amounts, IBANs and tax registration numbers — plus every file path |
| `SESSION_SECURE_COOKIE` | `false` | `true` | Unset resolves to `null`, which Laravel reads as not-secure, so the session id of somebody with payment-release rights travels over plain HTTP |
| `SESSION_HTTP_ONLY` | `true` | `true` | One cross-site scripting bug reads the session cookie out of JavaScript |
| `SESSION_SAME_SITE` | `lax` | `lax` | `none` sends the cookie on cross-site requests, which is what a CSRF needs |
| `SESSION_ENCRYPT` | `true` | `true` | Sessions live in the **platform** database, which no row-level security policy covers, and the payload holds the entity context and the second-factor assertion time |
| `DB_SSLMODE` | unset | `require` / `verify-full` | `prefer` tries TLS and silently uses plaintext if the server says no. Everything works and nothing logs it |
| `EXCHANGE_DRIVER` | `log` | a real transport | `log` transmits nothing. The invoice is marked sent, no tax authority receives it, nothing errors, and the discovery is a penalty notice |
| `PDF_DRIVER` | `stub` | `gotenberg` | `stub` renders a placeholder. Customers receive it |
| `pdf.verapdf.enforce` | `true` | `true` | An archival invoice that is not PDF/A is rejected by the receiving archive, months later, in bulk |
| `exchange.xml.allow_dtd` | `false` | `false` | A DOCTYPE is how an entity declaration gets in |
| `exchange.xml.allow_external_entities` | `false` | `false` | An external entity reads local files and reaches the network |
| `exchange.xml.allow_network` | `false` | `false` | A schema reference becomes an outbound request to whoever sent the document |
| `telemetry.database.record_bindings` | `false` | `false` | Every amount, IBAN and tax number is copied into the trace store, which has a different retention policy and a different audience |
| `exchange.webhooks.refuse_private_ranges` | `true` | `true` | A tenant points a webhook at `169.254.169.254` and the platform fetches its own cloud credentials |
| `exchange.webhooks.follow_redirects` | `false` | `false` | Defeats the check above: the first request goes to a public host and the redirect goes wherever it likes |
| `exchange.zatca.environment` | `sandbox` | `production`, when the profile is on | A sandbox clearance returns a valid-looking response with no legal effect, so every invoice appears cleared and none is |
| `tenancy.migrate.require_online_safety_declaration` | `true` | `true` | A migration taking an `ACCESS EXCLUSIVE` lock reaches a live tenant without anybody having said it is safe to |

One rule in that set is an outage rather than a hole, and it is there because the
symptom is misleading: a secure cookie with an `http://` `APP_URL` means the
browser never sends the cookie, so nobody can sign in — and what a reader sees
is a login form that keeps reloading, not anything mentioning cookies.

### `.env.example` is deliberately a configuration the guard rejects

A secure cookie cannot be sent over `http://localhost`, and `verify-full` needs
certificates nobody has on a laptop. So a fresh clone trips the guard, and a
check asserts that it does — if the local configuration ever passed, either
every rule had stopped firing or the clone was configured for production, in
which case nobody could sign in.

That check matches a **subset**, not an exact list. The first version required
exactly eight problems by name and was wrong: it reads the live configuration,
which comes from an uncommitted `.env`, so a developer who set
`SESSION_ENCRYPT=true` locally would have seen it fail for being more secure
than expected. The three `sslmode` rules are the part that cannot drift, because
`DB_SSLMODE` is unset in the committed file.

## Safe ranges for the values with a range

These are not enforced at boot — a wrong one is slow, noisy or wasteful rather
than silent — so they are documented here and their defaults are checked against
the live configuration by `bin/verify config`.

| Key | Default | Safe range | Why the edges |
|-----|---------|-----------|---------------|
| `tenancy.migrate.concurrency` | `4` | 1–16 | Each one holds a connection and a migrator session. Above the cluster's `max_connections` headroom, a migration run locks out the application it is migrating for |
| `tenancy.migrate.batch_size` | `50` | 10–500 | Smaller means more orchestration overhead per tenant; larger means a failure report that arrives after thousands have already run |
| `tenancy.connections.max_per_tenant` | `5` | 1–20 | Multiplied by tenant count, not by request count — this is the number that exhausts a cluster |
| `tenancy.connections.idle_timeout_seconds` | `60` | 10–300 | Below 10 a long request loses its own connection mid-transaction |
| `tenancy.grace.past_due_days` | `14` | 0–90 | A commercial decision with a legal floor: a tenant cut off while a period is open cannot close its books |
| `tenancy.grace.soft_delete_days` | `30` | 7–365 | The window for "we deleted the wrong tenant". Below a week it is shorter than a holiday |
| `tenancy.migrating_retry_after_seconds` | `30` | 5–300 | The `Retry-After` a request gets while its tenant migrates. Too low is a thundering herd on a database already under DDL |
| `money.scales.amount` | `4` | **fixed** | Changing any of the three scales changes what every stored number means. There is no migration path that does not restate history |
| `money.scales.unit_price` | `8` | **fixed** | As above |
| `money.scales.rate` | `12` | **fixed** | As above |
| `money.default_rounding` | `half_up` | `half_up`, `half_even`, `down` | A change applies to documents issued *after* it. Two documents in one period rounded differently is a difference nobody can explain |
| `tax.ceilings.writeoff_tolerance` | `50.0000` | 0–1000, in the entity's currency | The amount a user may write off without approval. This is the ceiling the platform enforces; a tenant may set lower and never higher |
| `tax.ceilings.rounding_tolerance` | `1.0000` | 0–5 | Above a unit of currency it stops being rounding and starts being a difference |
| `banking.matching.auto_apply_threshold` | `0.9` | 0.75–0.95 | Below 0.75 the system applies payments to the wrong invoice; the entries are reversible and the customer's statement is not |
| `banking.matching.auto_apply_ceiling` | `0.95` | ≥ `auto_apply_threshold` | The platform's ceiling on what a tenant may raise the threshold to |
| `banking.matching.min_reference_match_length` | `5` | 4–12 | Below 4 a reference matches by coincidence: `INV` is in every invoice number |
| `banking.matching.date_window_days` | `5` | 1–30 | Wider catches slow banks and also matches last month's identical amount |
| `banking.matching.subset_sum.candidate_cap` | `24` | 8–32 | Subset-sum is exponential. 32 candidates is four billion combinations and the time budget below becomes the only thing stopping it |
| `banking.matching.subset_sum.time_budget_ms` | `500` | 100–5000 | The ceiling that makes the cap safe. A statement import with a hundred unmatched lines pays this per line |
| `banking.differences.tolerance_ceiling` | `50.0000` | 0–1000 | The largest difference that may be written off as a bank charge or a rounding difference without approval |
| `banking.payments.duplicate_detection.window_days` | `30` | 7–90 | The window in which the same payment to the same beneficiary is flagged. Narrower misses a monthly duplicate |
| `banking.payments.sepa.remittance_max_length` | `140` | **fixed by ISO 20022** | Raising it produces a message the scheme rejects; lowering it truncates a reference the beneficiary needs to reconcile |
| `banking.payments.sepa.beneficiary_name_max_length` | `70` | **fixed by ISO 20022** | As above |
| `exchange.retry.max_attempts` | `8` | 3–12 | With the backoff schedule, 8 spans about a day. Fewer gives up inside a provider's maintenance window |
| `exchange.retry.jitter_ratio` | `0.2` | 0–0.5 | Zero means every failed job in a batch retries in the same millisecond |
| `exchange.circuit_breaker.failure_threshold` | `5` | 2–20 | Too low trips on noise; too high keeps sending into a dead provider and fills the dead-letter queue |
| `exchange.circuit_breaker.open_seconds` | `120` | 30–900 | How long before a probe. Longer delays recovery nobody is watching for |
| `exchange.dead_letter.retain_days` | `90` | 30–365 | A document in the dead-letter queue is one that was never filed. The retention has to outlast a quarter-end |
| `exchange.webhooks.timestamp_tolerance_seconds` | `300` | 60–900 | The replay window on a signed webhook. Wider is a longer replay window; narrower breaks on ordinary clock drift |
| `exchange.webhooks.secret_rotation_overlap_days` | `14` | 1–30 | Both secrets are accepted during the overlap. Zero means rotation is a cutover with no window for the receiver to update |
| `exchange.webhooks.request_timeout_seconds` | `10` | 2–30 | A tenant's slow endpoint holding a worker. Longer is a queue backed up by one customer |
| `exchange.webhooks.disable_after_consecutive_failures` | `20` | 5–100 | When a dead endpoint stops being retried. Too low disables on a deploy |
| `exchange.webhooks.delivery_log_retain_days` | `90` | 30–365 | What answers "did you send it". Shorter than a dispute takes to arrive is no answer |
| `exchange.peppol.smp_cache_ttl_seconds` | `3600` | 300–86400 | A receiver's capabilities change when they change provider. A day's cache sends to the old one |
| `exchange.zatca.reporting_window_hours` | `24` | **fixed by the authority** | Simplified invoices must be reported inside this window. It is not ours to choose |
| `exchange.xml.max_entity_expansion` | `0` | **fixed at 0** | Any other value re-opens entity expansion |
| `exchange.xml.max_depth` | `100` | 20–500 | A real UBL invoice nests about 12 deep. 500 is a stack somebody chose for a reason that was not an invoice |
| `exchange.xml.max_bytes` | `16777216` | 1 MiB–64 MiB | 16 MiB is a very large invoice. Above that the parse is a memory decision, not a correctness one |
| `pdf.gotenberg.timeout_seconds` | `60` | 10–300 | A 200-page statement is slow. Longer holds a worker; shorter fails the render that mattered |
| `telemetry.sampling.ratio` | `0.1` | 0–1 | Financial events are always sampled regardless (`telemetry.sampling.always_sample`), so this governs the ordinary traffic around them |
| `jurisdictions.tax_point.generic` | `invoice_date` | one of five bases | Which date fixes the **tax point**, and so which return period a supply belongs to. A profile naming a basis the engine does not implement throws rather than falling back, because guessing applies a rule nobody wrote; a profile that is absent falls back to the default's, because the alternative is a document nobody can issue over a misspelled key |
| `fx.fallback_window_days` | `30` | 1–90 | How stale a rate may be when every provider is down. "The last known rate" from eleven months ago is not resilience, it is a wrong number with a long paper trail |
| `pdf.page.margin_mm.inline_start` | `14` | 8–30 | Logical, not physical: `inline_start` is the right-hand side in Arabic. A physical `left` here would put an Arabic invoice's margin on the wrong edge |

Every key in that table is checked to exist, and every **Default** column value
is compared against the live configuration — so the table cannot drift from the
code. Adding a key to the config files does not fail the build; changing a
documented default without changing the table does.

## How this is checked

`bin/verify config` — eight rules, six proved by breaking what they guard:

| Bite | Fired |
|------|-------|
| A key removed from `.env.example` and from the `# UNUSED:` list | The completeness rule, naming the key and the file that reads it |
| A key set in `.env.example` that nothing reads | The dead-key rule |
| A key both set and declared unused | The contradiction rule |
| The session-encryption rule deleted from the guard | The per-value rule, and the all-at-once rule |
| The guard gated on `runningInConsole()` instead of `isProduction()` | The wiring rule |
| `ProductionConfiguration::assert()` commented out | The wiring rule — *after* it was fixed to blank comments first |

That last one is worth keeping in view. The wiring rule used the raw source, and
commenting the call out left it passing, because
`// ProductionConfiguration::assert();` still contains the string. A textual
rule fooled by a comment is the shape of bug it exists to catch, so it
tokenises the file and blanks comments before looking.

Inverting the gate the other way — running the guard everywhere *except*
production — is not in the table because it needs no check: the guard then
throws during every local boot, and nothing works at all. That is the loudest
possible failure and it is fixed in seconds.
