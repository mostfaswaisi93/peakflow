# Phase 12 — The REST API, webhooks and the Exchange plane — finished

The tasks of [phase-12-api-exchange.md](../phase-12-api-exchange.md) that have no open criterion left.
They are kept, not deleted: the notes under each one are the record of
why the thing was built the way it was, and that is the part somebody
needs when they come to change it. The phase file holds what remains.

---

### PF-217 · The OpenAPI contract
`—` · **5 pts** · depends: PF-015 · standards: OpenAPI 3.1 · `risk:high`

- [x] `docs/api/openapi.yaml` authored by hand, versioned, and reviewed as part of any API change
  - "Reviewed as part of any API change" is not a process here, it is a check: the contract test asserts both directions, so a route added without its operation fails the build and an operation left behind after a route goes fails it too. A review somebody has to remember is a review that gets skipped on the day it matters.
  - The file is authored with JSON for every inline mapping and example - which is valid YAML - because this project vendors no YAML library, so the contract test carries its own reader. That reader refuses syntax it does not understand rather than skipping it, which is the only safe direction for a parser that decides whether the contract is honoured.
- [x] A contract test asserts **both directions**: every operation and schema in the spec exists in the application, and **no undocumented route exists** on the API surface
  - The committed contract described **eight operations with no routes behind them**, which is worse than describing none: a client writes code against them and gets a 404 with no way to tell a bug from a missing feature. The contract now documents what exists, the rule is stated in its header, and the undocumented-route direction is bitten with a planted `GET /api/v1/bite`.
  - Its ULID pattern was `^[0-9A-HJKMNP-TV-Z]{26}$` - upper case only - while every id the application returns is lower case, so a client validating ids against the published contract would have rejected all of them. Worse, the application emits **both**: `HasUlids` lower-cases and a direct `Str::ulid()` does not, so `Poster` writes an upper-case `journal_lines.id` beside a lower-case `journal_entries.id`. Ids are documented as opaque now and the pattern accepts either; the mixed casing is its own defect and wants its own task.
- [x] Every response, including every error shape, is described - an error that is not in the spec is not an error a client can handle
  - Bitten by documenting a 400 as `application/json`, which is the mistake that would actually be made.
- [x] Examples for every operation, used as test fixtures so they cannot go stale
  - **Not literally as fixtures, and the deviation is deliberate.** Two checks meet in the middle instead: every example is validated against its own schema, and every live response is validated against the schema the contract documents for that operation. So the example and the application are each pinned to the same third thing, which catches a stale example *and* a response that drifted - where an example used directly as a fixture only ever proves the example is still parseable.
  - This check was one of the five that missed on its first bite: it counted examples across the whole operation, and the shared error responses carry their own, so deleting an operation's 200 example left the count unchanged at four. Counted on the success response now.
- [x] The spec is published to tenants as the integration reference
  - With an ETag, and the check bites on its absence: an integration reference without one is re-downloaded on every client build.

### PF-220 · Idempotency
`—` · **5 pts** · depends: PF-218 · standards: — · `risk:high`

- [x] `Idempotency-Key` is **required** on every POST that posts to the ledger, and a request without one is refused rather than accepted
  - Bitten both ways: generating a key server-side when one is absent, and removing the middleware from a posting route. The second is the one that matters - the first failure is loud and the second is silent.
- [x] The response is stored and replayed for 24 hours, keyed on `(tenant, token, key)`
  - The table has row-level security, enabled and **forced**, with a policy - not an opt-out, and not a dropped `entity_id`. The row holds a stored response **body**, so the fingerprint comparison that already stops a cross-entity read is application code: one `hash_equals` away from a mistake, and bypassed by any raw query.
  - Two consequences, both written down where they bite. The unique index spans entities and the policy does not, so a claim can conflict with a row this entity cannot see - which is unambiguous, because an invisible conflicting row belongs to another entity, so it is reported as a conflict and not as "in flight", which would have told the client to retry something that will never finish. And an **expired** record in another entity keeps its key refused instead of freeing it after 24 hours, because hot-path pruning only sees its own entity's rows - hence `api:prune-idempotency`, which iterates entities, because a single `DELETE` with no entity established would match nothing and report success.
- [x] A replay with a **different body** is a `409`, because that is a client bug and silently returning the first response hides it
  - Bitten from both sides: not comparing the fingerprint, and altering the replayed bytes.
- [x] A request still in flight returns `409` with `Retry-After` rather than executing twice
- [x] A test fires the same create twice concurrently and asserts exactly one document and two identical responses
  - Two real worker processes, which is why this suite is keyword-only and not in `all`: the workers cannot see a transaction this process holds open, so the fixture has to be committed. Bitten by making the claim key unique per request, which is the form this bug takes in practice.

### PF-221 · Pagination
`—` · **3 pts** · depends: PF-217 · standards: RFC 8288

- [x] **Cursor-based by default**, because offset pagination over a growing journal both drifts and degrades
  - The check asserts the page **seeks** rather than counting rows, so a cursor implemented over an offset underneath would fail it.
- [x] An opaque, signed cursor that encodes the sort key, so it cannot be tampered with or hand-constructed
  - Two bites: skipping `hash_equals`, and dropping the listing's identity from the signed material - the second being the subtler one, because a cursor that is validly signed for *another* listing is still a cursor somebody else's page handed out.
- [x] `Link` headers per RFC 8288 plus a cursor in the body
- [x] Offset pagination available, capped, where a UI genuinely needs a page number, and documented as the slower path
  - The response says which mode answered, so a client that meant to be seeking can tell that it is not.
- [x] Page size capped by an allowlist, so a crafted value can never request an unbounded page
  - An allowlist and not a clamp, and the bite is clamping instead of refusing: a client that asks for 10,000 and silently gets 100 has a bug it will not find, and will build a loop around the wrong page size.

### PF-222 · Money and dates on the wire
`—` · **3 pts** · depends: PF-006 · standards: ISO 4217, ISO 8601, RFC 3339 · `risk:high`

- [x] Money is **always** an object: `{"amount": "1234.5600", "currency": "SAR"}`. A bare number is never returned, because a client parsing it as a float is the bug this prevents
- [x] The amount is a string at the amount scale; the currency's presentation exponent is a separate field where the client needs it
- [x] An **accounting date is `YYYY-MM-DD` and never a timestamp**; a timestamp is RFC 3339 UTC. Timezone-shifting an accounting date reallocates it to another period
  - Bitten by serialising an accounting date as a timestamp, which is the mistake a careless `->toIso8601String()` makes and which moves a document to another period for every client east or west of us.
- [x] A schema test asserts no response anywhere contains a bare numeric money value
  - **Anywhere**, walked over every response the suite produces rather than checked per field, because the field that will carry a bare number is the one nobody thought of. Bitten with money as a float.

