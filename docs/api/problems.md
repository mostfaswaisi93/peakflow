# The problem-type registry

Every error this API returns is an RFC 9457 problem document with a `type` from this registry — PF-219.

```json
{
  "type":     "https://peakflow.app/problems/idempotency-key-conflict",
  "title":    "The Idempotency-Key was reused with a different body",
  "status":   409,
  "detail":   "The key \"7c0f3d1e…\" was already used for a different request. …",
  "instance": "/api/v1/journal-entries"
}
```

> **An error type is a contract, not something invented at the call site.** A client that has to parse `detail` to tell a closed period from a missing permission has no contract at all — and the first time somebody rewords a sentence, that client takes the wrong branch.

The registry is `App\Support\Http\ProblemType`, and `tests/Support/ApiContractRules.php` asserts that this document names every case in it with the status it carries. A type in the code and not here is a failure a client cannot handle; one here and not in the code is a branch somebody wrote and will never reach.

## What is localized, and what must never be

| Member | Localized | Why |
|--------|-----------|-----|
| `type` | **Never** | It is the identity of the condition. A localized URI is a different URI. |
| `title` | **Never** | It is the machine-readable summary. A client switching on it would break the first time somebody sent `Accept-Language: ar`, and it would break by taking the wrong branch rather than by failing. |
| `status` | — | A number. |
| `detail` | **Yes**, by `Accept-Language` | It is for the person who will read the failure. |
| `errors[].detail` | **Where the condition has a sentence** | A line problem carries both languages, because the domain wrote both. Where the useful content is an identifier — a scope key, an entity id, the field that was rejected — `detail` IS that identifier and the client renders it; a translated identifier would be a different identifier. |
| `errors[].code`, `errors[].pointer`, `errors[].business_term` | **Never** | Addresses and keys. |

On the API, `Accept-Language` **outranks the tenant's default language**, which is the opposite of the application's own ordering. The reasoning for each is in `App\Http\Middleware\SetLocale` and `App\Http\Middleware\Api\NegotiateApiLanguage`: a browser's language setting is a guess about a person, and a header an integration sends per request is a statement about that request.

The response carries `Content-Language`, so a client that asked for English and received Arabic can tell a wrong header from a deployment that ignores it, rather than guessing from the script.

## How an error is addressed

| Kind | Addressed by | Example |
|------|--------------|---------|
| A field in the request | RFC 6901 JSON pointer, **zero-indexed** | `/lines/0/account_code` |
| A document rule | **EN 16931 business term** | `BT-110`, with `code` carrying the rule id `BR-CO-14` |

Document-rule failures are never addressed by XPath. `/Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount` is correct and useless: users do not read XPath, and neither do integrators. `BT-110` is in the standard they are being held to.

The pointer is zero-indexed because it addresses the JSON array the client sent. Handing a client a pointer that is off by one is worse than handing it none — it will highlight the wrong row with complete confidence.

## The registry

`type` is the last segment of `https://peakflow.app/problems/{type}`.

### Authentication and authorization

| `type` | Status | Title | When, and what to do |
|--------|--------|-------|----------------------|
| `unauthenticated` | 401 | Authentication is required | No bearer token, an unknown one, an expired or revoked one, **or a token from another plane**. All four answer identically: confirming that a token is real and simply not usable here is the one fact worth having. Send a valid token. |
| `forbidden` | 403 | Not permitted | The **user** behind the token does not hold the permission in this entity. Re-issuing the token will not help; the permission has to be granted. |
| `token-scope-missing` | 403 | The token does not carry this scope | The **token** was issued without the scope. Issue a new token with it — a live token is never widened, because that would leave no record of what it could do yesterday. |
| `entity-not-permitted` | 403 | This token may not act in that entity | `X-Entity` names an entity outside the token's list, outside the owner's roles, or one that is closed. A token is scoped to specific entities and never to the tenant. |
| `entity-not-yours` | 403 | You hold no role in that company | A signed-in user asked for an entity they hold no role in. Distinct from `entity-not-permitted`, which is a token's refusal: this one is answered by somebody granting a role, not by changing a header. Reached from the web session path, not from `/api/v1`. |
| `segregation-of-duties-conflict` | 403 | Segregation of duties conflict | The same person already performed the conflicting duty. Somebody else has to do this one. |
| `step-up-authentication-required` | 403 | Re-authentication is required for this action | The action needs proof that somebody is at the keyboard now. A bearer token cannot provide it, which is why such permissions can never be token scopes. |
| `mfa-enrolment-required` | 403 | A second factor must be enrolled first | The permission is marked `requires_mfa` and its holder has no enrolled factor. |
| `control-account-not-postable` | 403 | A control account cannot be posted to directly | Its balance is the total of a subledger. Post to the counter account, or correct the document that created the balance. |

### Tenancy

| `type` | Status | Title | When, and what to do |
|--------|--------|-------|----------------------|
| `tenant-required` | 400 | A tenant must be identified | No `X-Tenant`. It is not guessed: "they only have one tenant" is how an integration silently writes into the wrong company after the customer adds a second one. |
| `entity-required` | 400 | An entity must be identified | No `X-Entity`. Required even when the token names exactly one entity. |
| `subscription-inactive` | 402 | The subscription is not active | The workspace is not entitled to be served. A commercial condition, not a bug. |
| `tenant-migrating` | 503 | The workspace is being migrated | Retry after `Retry-After`. |

### Validation and document rules

| `type` | Status | Title | When, and what to do |
|--------|--------|-------|----------------------|
| `validation-failed` | 422 | The request could not be validated | A field could not be accepted. Each is addressed by a JSON pointer in `errors`. |
| `document-validation-failed` | 422 | The document does not satisfy its business rules | A document rule failed. Each is addressed by its EN 16931 business term. |
| `entry-not-balanced` | 422 | The journal entry does not balance | The lines do not sum to zero in the functional currency. `detail` carries the amount they are out by, and the response carries `imbalance` as a money object — the shape of that number says what went wrong: 90 is a transposition, twice a line is a sign, a round figure is a missing line. |
| `currency-not-enabled` | 422 | The currency is not enabled for this entity | Enable it first rather than converting at a rate nobody chose. |
| `exchange-rate-unavailable` | 422 | No exchange rate is available | No rate for that pair on that date, and one will not be invented. |

### Ledger state

| `type` | Status | Title | When, and what to do |
|--------|--------|-------|----------------------|
| `period-not-accepting-postings` | 423 | The accounting period does not accept postings | The period is closed or locked. A closed period has been reported on; posting into it changes a figure somebody has already acted upon. |
| `posted-record-immutable` | 409 | A posted record cannot be changed | Correct it with a reversal, which leaves both the original and the correction visible. |
| `state-transition-not-allowed` | 409 | That state transition is not allowed | The record is not in a state this operation applies to — revoking an already-revoked token, for instance. |

### Concurrency and idempotency

| `type` | Status | Title | When, and what to do |
|--------|--------|-------|----------------------|
| `idempotency-key-required` | 422 | An Idempotency-Key header is required | The write posts to the ledger. Send a unique key, 16–128 characters, and keep it across retries. |
| `idempotency-key-conflict` | 409 | The Idempotency-Key was reused with a different body | A client bug, reported rather than hidden. Returning the first response would mean the second write never happens, its id is recorded against the first document, and the discrepancy surfaces weeks later as a document nobody can find. Use a new key, or resend the original request unchanged. |
| `idempotency-key-in-flight` | 409 | The first request with this Idempotency-Key is still running | Carries `Retry-After`. Wait; the answer will then be the first request's own stored response. |
| `stale-write` | 412 | The record has changed since it was read | `If-Match` did not match. The current representation is in the body, so the client can show the user what changed. |

### Request shape

| `type` | Status | Title | When, and what to do |
|--------|--------|-------|----------------------|
| `not-found` | 404 | Not found | No such record is **visible to this token**. A record that exists and is hidden is reported identically to one that does not exist: distinguishing them tells an attacker which ids and which document numbers are real. |
| `filter-not-allowed` | 400 | That filter or sort field is not permitted | The response lists what is permitted. The allowlist is the set of fields that have an index — sorting by one that has not would ask the database for a full sort of the table. |
| `cursor-not-readable` | 400 | That cursor could not be read | Altered, truncated, or from a different listing. A cursor is opaque, signed, and bound to the sort that produced it. Send back exactly what was received. |
| `page-not-readable` | 400 | `page=latest`, `page=0`, `page=1.5`. Refused rather than coerced: `(int) 'latest'` is 0 and reaches page one, so a client paging by a value it computed wrongly would read the first page for ever while appearing to work. |
| `page-size-not-allowed` | 400 | That page size is not one of the permitted sizes | The permitted sizes are in `permitted_page_sizes`. An allowlist rather than a ceiling, because a ceiling answers `limit=99999` with a page of 100 and says nothing. |
| `offset-too-deep` | 400 | That page number is beyond the offset limit | Offset pagination is capped. Use the cursor, which seeks instead of counting. |
| `rate-limited` | 429 | Too many requests | Carries `Retry-After` and the `RateLimit-*` headers. |

### Us

| `type` | Status | Title | When, and what to do |
|--------|--------|-------|----------------------|
| `server-error` | 500 | Something went wrong | The request failed on our side. **Nothing was half-saved** — an idempotent write rolls its claim back with the transaction, so the same key may be retried. Quote the correlation id from the response headers when reporting it. |

## Adding a type

Adding one is a deliberate, reviewable act, and it is a **contract change**: a new type for a new condition is not breaking, but changing the type a condition returns is (see `docs/api/conventions.md`). The steps:

1. Add the case to `App\Support\Http\ProblemType`, with its status, its English title and both halves of its default `detail`.
2. Add a row here, in the right section, saying **when** and **what to do**.
3. Describe the response on every operation in `openapi.yaml` that can produce it. An error that is not in the spec is not an error a client can handle.

The first two are asserted by `ApiContractRules`; the third is asserted for the statuses the suite exercises.
