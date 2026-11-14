# Phase 12 — The REST API, webhooks and the Exchange plane

**14 tasks · 57 points**

The API is **spec-first**: the OpenAPI document is written before the route, and a contract test asserts the two agree in both directions. An API whose documentation is generated from its implementation documents whatever the implementation happens to do, including its mistakes.

The `Exchange` plane gets its own tasks here because a government endpoint being down must be a queue depth, never a 500 on a user's screen.

---

### PF-217 · The OpenAPI contract
`—` · **5 pts** · depends: PF-015 · standards: OpenAPI 3.1 · `risk:high`

- [ ] `docs/api/openapi.yaml` authored by hand, versioned, and reviewed as part of any API change
- [ ] A contract test asserts **both directions**: every operation and schema in the spec exists in the application, and **no undocumented route exists** on the API surface
- [ ] Every response, including every error shape, is described - an error that is not in the spec is not an error a client can handle
- [ ] Examples for every operation, used as test fixtures so they cannot go stale
- [ ] The spec is published to tenants as the integration reference

### PF-218 · API authentication and scopes
`—` · **5 pts** · depends: PF-028, PF-021 · standards: OWASP API Top 10 · `type:security`

- [ ] Sanctum bearer tokens; `X-Tenant` and `X-Entity` resolved before authentication
- [ ] **Token scopes mirror permissions exactly**, so there is one authorization model and not a second, weaker one for machines
- [ ] A token is scoped to specific entities, not to the tenant, and a request for another entity is refused
- [ ] Token creation, last-used, rotation and revocation are visible to the tenant and audited
- [ ] A platform token can never act on a tenant route and vice versa - both guards issue tokens against the same table, so this has its own test

### PF-219 · Problem-details errors
`—` · **3 pts** · depends: PF-217, PF-117 · standards: RFC 9457

- [ ] `application/problem+json` with `type`, `title`, `status`, `detail`, `instance`
- [ ] A field-addressed `errors` array; document-rule failures addressed by **EN 16931 business term** rather than by XPath or by an internal field name
- [ ] A registry of problem `type` URIs, each documented, so an error type is a documented contract and not invented at the call site
- [ ] Messages are localized by `Accept-Language`; the `type` and the machine-readable parts never are

### PF-220 · Idempotency
`—` · **5 pts** · depends: PF-218 · standards: — · `risk:high`

- [ ] `Idempotency-Key` is **required** on every POST that posts to the ledger, and a request without one is refused rather than accepted
- [ ] The response is stored and replayed for 24 hours, keyed on `(tenant, token, key)`
- [ ] A replay with a **different body** is a `409`, because that is a client bug and silently returning the first response hides it
- [ ] A request still in flight returns `409` with `Retry-After` rather than executing twice
- [ ] A test fires the same create twice concurrently and asserts exactly one document and two identical responses

### PF-221 · Pagination
`—` · **3 pts** · depends: PF-217 · standards: RFC 8288

- [ ] **Cursor-based by default**, because offset pagination over a growing journal both drifts and degrades
- [ ] An opaque, signed cursor that encodes the sort key, so it cannot be tampered with or hand-constructed
- [ ] `Link` headers per RFC 8288 plus a cursor in the body
- [ ] Offset pagination available, capped, where a UI genuinely needs a page number, and documented as the slower path
- [ ] Page size capped by an allowlist, so a crafted value can never request an unbounded page

### PF-222 · Money and dates on the wire
`—` · **3 pts** · depends: PF-006 · standards: ISO 4217, ISO 8601, RFC 3339 · `risk:high`

- [ ] Money is **always** an object: `{"amount": "1234.5600", "currency": "SAR"}`. A bare number is never returned, because a client parsing it as a float is the bug this prevents
- [ ] The amount is a string at the amount scale; the currency's presentation exponent is a separate field where the client needs it
- [ ] An **accounting date is `YYYY-MM-DD` and never a timestamp**; a timestamp is RFC 3339 UTC. Timezone-shifting an accounting date reallocates it to another period
- [ ] A schema test asserts no response anywhere contains a bare numeric money value

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
