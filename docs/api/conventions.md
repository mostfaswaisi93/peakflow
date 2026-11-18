# API conventions

The API is **spec-first**: [`openapi.yaml`](openapi.yaml) is written before the route, and a contract test asserts the two agree **in both directions** — every operation in the spec exists, and no undocumented route exists on the API surface.

> An API whose documentation is generated from its implementation documents whatever the implementation happens to do, including its mistakes.

So **an operation appears in the contract when its route exists**, and the resource surface arrives with the tasks that implement it. An operation documented ahead of its route is worse than one not documented at all: a client writes code against it and gets a 404, which looks like an outage rather than a plan.

Base path: `/api/v1`. The version is in the path and is never negotiated by a header — a version an integrator can see in a log line, in a bug report and in the URL they pasted into a support ticket is worth more than one negotiated invisibly.

Identifiers are ULIDs, and they are **opaque**: this application emits them in both cases (a model-generated id is lower case, one from the framework helper is upper), Crockford base32 is case-insensitive, and the column behind them compares as a string. Echo back exactly the bytes you were given.

## Identity

| Header | Required | Purpose |
|--------|----------|---------|
| `Authorization: Bearer <token>` | Yes | Sanctum token |
| `X-Tenant` | Yes | Resolved **before** authentication, because the user table is in the tenant database |
| `X-Entity` | On entity-scoped resources | The legal entity to act in |
| `Idempotency-Key` | **On every POST that posts to the ledger** | See below |
| `If-Match` | On updates to mutable resources | Optimistic concurrency |
| `Accept-Language` | No | Localizes human-readable error text only. On the API it **outranks the tenant's default language**, which is the opposite of the application's ordering: a header an integration sends per request is a statement about that request. The response carries `Content-Language` |

**Token scopes mirror permissions exactly**, so there is one authorization model rather than a second, weaker one for machines. A token is scoped to **specific entities**, not to the tenant.

## Money on the wire

```json
{
  "net":   { "amount": "1000.0000", "currency": "SAR" },
  "tax":   { "amount": "150.0000",  "currency": "SAR" },
  "gross": { "amount": "1150.0000", "currency": "SAR" },
  "presentation_exponent": 2
}
```

**Money is always an object with a string amount.** A bare number is never returned — a client parsing it as a float is precisely the bug this prevents, and `JOD 1.234` arriving as JSON `1.234` will be reformatted to `1.23` by any client that treats it as a number.

The amount is at the storage scale (four decimals). `presentation_exponent` comes from ISO 4217, so the client formats; the server does not pre-format.

## Dates

| Kind | Format | Example |
|------|--------|---------|
| **Accounting date** | `YYYY-MM-DD`, a **date** | `"2026-11-14"` |
| Event timestamp | RFC 3339, UTC | `"2026-11-14T09:31:22Z"` |

> **An accounting date is not a timestamp and must never be timezone-shifted.** `2026-11-30T21:00:00Z` interpreted in Riyadh is 1 December — a different period, a different return, and a difference nobody will find for a quarter.

## Errors — RFC 9457

```json
{
  "type":     "https://peakflow.app/problems/document-validation-failed",
  "title":    "The invoice cannot be issued",
  "status":   422,
  "detail":   "Three business rules were not satisfied.",
  "instance": "/api/v1/invoices/01JD3X.../issue",
  "errors": [
    { "pointer": "/lines/0/quantity",  "code": "min",          "detail": "Quantity must be greater than zero." },
    { "business_term": "BT-31",        "code": "BR-CO-9",      "detail": "A seller VAT identifier is required when VAT is charged." },
    { "business_term": "BT-110",       "code": "BR-CO-14",     "detail": "The tax total does not equal the sum of the VAT breakdown." }
  ]
}
```

Content type `application/problem+json`, on **every** failure including a `500` — the one response an integrator most needs to recognise would otherwise be the only one not in the contract.

Document-rule failures are addressed by **EN 16931 business term**, never by XPath — users do not read XPath, and neither do integrators. Field errors carry an RFC 6901 pointer into the request body, **zero-indexed**, because a pointer that is off by one highlights the wrong row with complete confidence.

Every `type` is a URI in a documented registry: [`problems.md`](problems.md), which names every type with its status and what to do about it. An error type is a contract, not something invented at the call site.

`detail` is localized and the machine-readable members never are, so:

> **An exception's message is for the log; a problem document's `detail` is for the client.** The message is English, long, and names the reasoning. `detail` comes from the registry — or from a call site that wrote the sentence in both languages — and the specifics a client acts on travel as `errors` and extension members, so nothing has to be parsed out of prose that is Arabic half the time.

A refusal distinguishes **the token** from **the user**, because only one of them is the caller's to fix:

| `type` | Status | The fix |
|--------|--------|---------|
| `token-scope-missing` | 403 | Issue a new token carrying the scope. A live token is never widened. |
| `forbidden` | 403 | Grant the permission. Re-issuing the token cannot help. |
| `entity-not-permitted` | 403 | Issue a token for that entity, or get a role in it. |

A single "403 Forbidden" for all three is the refusal that generates a support ticket.

| Status | Used for |
|--------|----------|
| `400` | Malformed request, or a filter or sort field not on the allowlist |
| `401` | Missing or invalid token |
| `403` | Authenticated, lacking the permission or the entity scope |
| `404` | Not found, **or** found and not visible to this token — deliberately indistinguishable |
| `409` | Idempotency-key conflict, or a state-machine transition that is not allowed |
| `412` | `If-Match` did not match; the current representation is returned |
| `422` | Validation or business-rule failure |
| `423` | The period is closed or locked |
| `429` | Rate limited; `Retry-After` and `RateLimit-*` present |
| `503` | Tenant mid-migration; `Retry-After` present |

## Idempotency

**`Idempotency-Key` is required on every POST that posts to the ledger**, and a request without one is **refused** rather than accepted. An endpoint that creates money movement must not be callable twice by accident.

| Case | Response |
|------|----------|
| First request | Executed; the response is stored for 24 hours |
| Replay, identical body | The **stored response**, replayed byte-identically |
| Replay, **different** body | `409`. That is a client bug, and silently returning the first response hides it |
| Replay while the first is still in flight | `409` with `Retry-After` |
| A `5xx` | **Not stored.** A replayed 500 would mean the client could never succeed with that key, and the key is the only thing it may retry with |

A replayed response carries `Idempotent-Replay: true`, so a client can tell whether its retry executed.

Keyed on `(tenant, token, key)` — the tenant is the database, so the unique index is the other two. The **entity** is part of the request fingerprint rather than the key, so the same key sent with a different `X-Entity` is the `409` it should be and not a replay telling a client its document exists in a company where it does not.

Two requests arriving together are answered by the database rather than by us: the claim is inserted inside the same transaction as the work, so the second request blocks on the unique index and replays the first's response once it commits. A test fires the same create twice from two OS processes and asserts exactly one document and two identical responses.

## Pagination

**Cursor-based by default.** Offset pagination over a growing journal both drifts — rows shift between pages as new ones are inserted — and degrades, because `OFFSET 50000` reads 50,000 rows to discard them.

```
GET /api/v1/invoices?limit=50&cursor=eyJrIjoiMDFKRDNY...
```

```
Link: </api/v1/invoices?limit=50&cursor=eyJrIjoi...>; rel="next"
```

```json
{
  "data": [ … ],
  "meta": { "limit": 50, "has_more": true },
  "links": { "next": "…", "self": "…" }
}
```

The cursor is **opaque and signed**, so it cannot be tampered with or hand-constructed. Offset pagination is available, capped, where a UI genuinely needs a page number, and documented as the slower path. `limit` is allowlisted — a crafted value can never request an unbounded page.

## Filtering and sorting

**A documented allowlist per resource.** Anything else is a `400` **naming what is permitted**, which is more useful than a generic rejection.

```
GET /api/v1/invoices?filter[status]=overdue&filter[party_id]=01JD...&sort=-due_date
```

- No arbitrary query interpolation, ever
- `LIKE` wildcards and the escape character in user input are escaped, so `%` and `_` are **literals** — with a test proving each is searchable for itself against real rows, and that an OR-group filter does not swallow a neighbouring filter
- Sparse fieldsets and relationship inclusion are **also** allowlisted, because an unbounded `include` is an N+1 a client can trigger

## Concurrency

`ETag` on every mutable resource; `If-Match` required on update. A stale write returns `412` **with the current representation**, so the client can show the user what changed rather than just failing.

An **immutable** resource — a posted entry, an issued invoice — returns a strong, permanently cacheable `ETag`. That is both correct and fast.

## Rate limits

| Bucket | Limited by |
|--------|-----------|
| Reads | Per tenant and per token |
| Writes | Per tenant and per token, **more tightly** |
| Reports and bulk exports | Their own tighter bucket; **queued** rather than throttled into failure |
| Authentication | Per account **and** per IP, with a response that does not reveal whether an account exists |

`RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset` on every response; `Retry-After` on a `429`.

## Versioning and deprecation

- `/api/v1`. A version is supported for **18 months** after its successor ships.
- Deprecated endpoints return `Deprecation` and `Sunset` headers and a `Link` to the migration note, **for the whole window** — not for the last month.
- **A breaking change inside a version is a bug**, and the definition is written down:

| Breaking | Not breaking |
|----------|--------------|
| Removing a field or an endpoint | Adding an optional response field |
| Narrowing a type or an enum's accepted values | Adding an enum value to a **response** |
| Adding a required request field | Adding an optional request field |
| Changing a status code or an error `type` | Adding a new error `type` for a new condition |
| Changing pagination semantics | Adding a new filter to the allowlist |
| Tightening a rate limit without notice | Relaxing one |

A changelog per API version, separate from the application changelog.

## Resource shape, in brief

| Resource | Notes |
|----------|-------|
| `/entities` | Read-only through the API; created in the application |
| `/accounts` | The chart; control accounts are marked and not postable |
| `/journal-entries` | POST creates a **draft**; `/journal-entries/{id}/post` posts. **Idempotency required** |
| `/parties`, `/items`, `/price-lists` | Masters |
| `/invoices` | POST creates a draft; `/invoices/{id}/issue` issues. **Idempotency required.** Issued invoices are immutable |
| `/invoices/{id}/document` | The serialized XML or PDF, with `Accept` selecting the syntax |
| `/credit-notes`, `/bills`, `/expense-claims` | As above |
| `/payments`, `/payments/{id}/allocations` | Payment and allocation are **separate resources**, because they are separate events |
| `/payment-runs` | `/prepare` and `/release` are separate operations with separate permissions and SoD |
| `/bank-accounts`, `/statements`, `/transactions` | Statement import is a POST with a file; transactions are read-only |
| `/reconciliations` | Including match proposals and their confidence |
| `/reports/{key}` | Parameterized, with the parameters echoed on the response |
| `/tax-returns` | A filed return is immutable |
| `/webhooks` | Endpoint management, delivery log, replay |

Collections are cursor-paginated. Nothing exposes another entity's data, and nothing exposes bank details — asserted by a schema test.
