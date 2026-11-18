# Phase 12 — The REST API, webhooks and the Exchange plane

**14 tasks · 57 points**

The API is **spec-first**: the OpenAPI document is written before the route, and a contract test asserts the two agree in both directions. An API whose documentation is generated from its implementation documents whatever the implementation happens to do, including its mistakes.

The `Exchange` plane gets its own tasks here because a government endpoint being down must be a queue depth, never a 500 on a user's screen.

---

### PF-218 · API authentication and scopes
`—` · **5 pts** · depends: PF-028, PF-021 · standards: OWASP API Top 10 · `type:security`

- [ ] Sanctum bearer tokens; `X-Tenant` and `X-Entity` resolved before authentication
  - **Half, and the other half cannot be done in that order.** Sanctum bearer tokens are built, and `X-Tenant` is still pinned ahead of the guard - it has to be, because the token lives in the tenant's own database and there is nothing to authenticate against until the connection is pointed.
  - `X-Entity` is resolved **after**. Which entity a token may act in is read from the token and then re-read from the owner's own grants, and neither is knowable before the guard has run. Resolving it first would mean either trusting the header or consulting a second, weaker source - and the second source is precisely what criterion 2 below forbids. The reasoning is in `ResolveApiEntity`. Left open rather than ticked with an excuse, because the criterion names an ordering and the ordering is different.
- [x] **Token scopes mirror permissions exactly**, so there is one authorization model and not a second, weaker one for machines
  - A scope **is** a permission key, and a check refuses any route whose scope is not in the catalogue. A request carrying a token is the user's own permissions intersected with what the token was issued for, and the narrowing is folded into the one `Gate::before` callback rather than put on a route - because the gate is asked from everywhere: `can:` on a route, `Gate::authorize()` in a handler, `Gate::denies()` three layers down inside a service. A check that lived on the route would leave every deeper question answered by the user's full permissions, and a token issued to read invoices would reach any service that authorizes internally. That is the second model for machines this criterion forbids, arrived at by accident.
  - A missing scope and a missing permission are told apart in the refusal, because "you cannot do this" and "this token cannot do this" send the reader to different places.
- [x] A token is scoped to specific entities, not to the tenant, and a request for another entity is refused
  - A live token can never be widened: a database trigger and a CHECK forbidding a wildcard, both bitten. Delegation only narrows - an issuing token's own ceiling applies to what it can issue.
  - This check was another of the five first-pass misses, and the miss was instructive: the fixture's user held no role in the second entity, so the **grant** check refused the request before the token's entity list was ever consulted. It passed for the wrong reason. The user now holds roles in both, and the two refusals are told apart by the `token_entity_ids` member.
- [x] Token creation, last-used, rotation and revocation are visible to the tenant and audited
  - The audit check was a third first-pass miss: it was scoped by `created_at >= now() - 5 minutes`, and this suite deliberately leaves audit records behind, so an earlier run's rows satisfied it. Scoped to each token's own id now - which also meant `EstablishApiActor` had to start recording **which** token it refused, not just that it refused one.
- [ ] A platform token can never act on a tenant route and vice versa - both guards issue tokens against the same table, so this has its own test
  - **One direction is proved; the other has nothing to prove it against.** A planted `plane = 'platform'` row in a tenant's own table gets a 401 and an audited refusal, bitten. There is no platform API route yet, so the middleware takes the plane as a parameter and the reverse direction is untested. It stays open until there is one, because a half-tested boundary that reads as tested is how the untested half ships.

### PF-219 · Problem-details errors
`—` · **3 pts** · depends: PF-217, PF-117 · standards: RFC 9457

- [x] `application/problem+json` with `type`, `title`, `status`, `detail`, `instance`
  - All five on every refusal, bitten by dropping the registry's default `detail`.
  - Before this, an unhandled exception on an API route answered with Laravel's own JSON - `{"message", "exception", "trace"}` in debug and a bare `{"message": "Server Error"}` in production - so the one response an integrator most needs to recognise was the only one outside the contract. A last-registered `Throwable` renderer closes it, with the class and message as a `debug` member only when `app.debug`: an exception message in this product can carry a bound value or an account number.
- [ ] A field-addressed `errors` array; document-rule failures addressed by **EN 16931 business term** rather than by XPath or by an internal field name
  - **The field-addressed half is done and bitten** - a line problem carries a JSON pointer, and making the pointer 1-based is caught. The business-term half is a **mechanism with no caller**: `Problem::withBusinessTermError()`, the `ProblemError` schema and a worked example in the contract all exist, and no operation in this slice emits one, because invoice issuing over the API is a later task.
  - Left open on purpose. A mechanism nothing calls is the exact shape of the three worst defects found in this repository on 2026-11-18 - `TaxPoint::derive()`, `EvidenceGate` and `factor_asserted_at` were each built, ticked and never called, and each was invisible for months because the tests called them directly. Ticking this would put a fourth on the list.
- [x] A registry of problem `type` URIs, each documented, so an error type is a documented contract and not invented at the call site
  - Thirty-two types, and `docs/api/problems.md` documents each. Removing a row from that file fails the build, so the registry and its documentation cannot drift.
- [x] Messages are localized by `Accept-Language`; the `type` and the machine-readable parts never are
  - Three bites: always answering in English, ignoring `Accept-Language`, and dropping `Content-Language`. `NegotiateApiLanguage` sits in the api group rather than on routes, because it has to apply to the **failures** too - a 401 from the guard and a 400 from the tenant resolver are both rendered before any route middleware runs, and a problem document in the wrong language is what this criterion is about.
  - It is in the group and not global because the inversion it performs - `Accept-Language` outranking the tenant default - is right for a machine and wrong for a browser.

### PF-223 · Optimistic concurrency
`—` · **3 pts** · depends: PF-217 · standards: RFC 9110

- [ ] `ETag` on every mutable resource; `If-Match` required on update
- [ ] A stale write gets `412` with the current representation, so the client can show the user what changed
- [ ] `If-None-Match` supported on reads for cheap polling
- [ ] An immutable resource - a posted entry, an issued invoice - returns a strong, permanently cacheable `ETag`, which is correct and also fast

### PF-224 · Filter and sort allowlists
`—` · **3 pts** · depends: PF-221 · standards: OWASP API Top 10 · `type:security`

- [ ] A documented allowlist of filterable and sortable fields per resource; anything else is a `400` naming what is permitted
- [ ] No arbitrary query interpolation, ever; `LIKE` wildcards and the escape character in user input are escaped so `%` and `_` are literals
- [ ] A test proves `%`, `_` and the escape character are searchable for themselves against real rows, and that an OR-group filter does not swallow a neighbouring filter
- [ ] Sparse fieldsets and relationship inclusion are also allowlisted, because an unbounded `include` is an N+1 a client can trigger

### PF-225 · Rate limiting
`—` · **3 pts** · depends: PF-218 · standards: — · `type:security`

- [ ] Limits per tenant and per token, with writes limited more tightly than reads
- [ ] `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset` headers, and `Retry-After` on a `429`
- [ ] Expensive operations - large reports, bulk exports - have their own tighter bucket and are queued rather than throttled into failure
- [ ] Authentication endpoints are limited per account and per IP with a response that does not reveal whether an account exists

### PF-226 · Webhook events and signing
`—` · **5 pts** · depends: PF-048 · standards: — · `risk:high` `type:security`

- [ ] A documented event catalogue with a stable schema per event and a version on the payload
- [ ] **HMAC-SHA256 signature over the raw body plus a timestamp**, with the timestamp inside the signed material so a captured request cannot be replayed later
- [ ] A tolerance window on the timestamp, and a documented verification procedure with a code sample, because a signature nobody verifies correctly is decoration
- [ ] Secret rotation with an overlap window, so a tenant can roll a secret without dropping deliveries
- [ ] Payloads carry identifiers and amounts, never bank details - asserted by test

### PF-227 · Webhook delivery
`—` · **5 pts** · depends: PF-226 · standards: —

- [ ] A documented retry schedule with exponential backoff and a defined give-up point
- [ ] A **delivery log the tenant can inspect**: event, endpoint, attempt, response status, response body excerpt, and a manual replay
- [ ] An endpoint failing consistently is disabled with a notification rather than retried forever
- [ ] Deliveries are ordered per tenant where ordering matters, and the payload carries a sequence so a consumer can detect a gap
- [ ] Outbound requests go through an allowlist that refuses private address ranges, so a webhook URL cannot be used for SSRF

### PF-228 · The Exchange plane's reliability
`Exchange` · **8 pts** · depends: PF-122, PF-124 · standards: — · `risk:high`

- [ ] Dedicated queues per transport with Horizon supervision and per-queue concurrency limits
- [ ] Exponential backoff with jitter, a **circuit breaker per endpoint**, and a dead-letter queue a human can inspect and re-drive
- [ ] `exchange:retry` re-drives failures and the dead letter selectively
- [ ] An outbound transmission is idempotent at the partner's identifier, so a retry after an ambiguous timeout cannot submit twice - which for a clearance platform is a duplicate legal document
- [ ] A transport outage is a **queue depth and an alert**, never a failed user request: the document is issued, and its transmission is pending
- [ ] Transmission state is visible per document in the UI, with the authority's own message where there is one

### PF-229 · Drivers that work with no credentials
`Exchange` · **3 pts** · depends: PF-228 · standards: —

- [ ] `EXCHANGE_DRIVER=log` writes the document that would have been transmitted into the log and marks the transmission succeeded, so the full state machine is exercised without a third party
- [ ] `PDF_DRIVER=stub` renders an HTML placeholder
- [ ] Both are the committed defaults, so a fresh clone issues an invoice end to end with no Peppol credentials, no clearance certificate and no PDF service
- [ ] Switching to a real driver is a configuration change and not a different code path - asserted by running the same feature test against both

### PF-230 · Versioning and deprecation
`—` · **3 pts** · depends: PF-217 · standards: SemVer 2.0, RFC 9745, RFC 8594

- [ ] `/api/v1`, with a documented policy: a version is supported for **18 months** after its successor ships
- [ ] Deprecated endpoints return `Deprecation` and `Sunset` headers and a `Link` to the migration note, for the whole window
- [ ] **A breaking change inside a version is a bug**, and the definition of breaking is written down: removing a field, narrowing a type, adding a required request field, changing an error code
- [ ] A changelog per API version, separate from the application changelog
