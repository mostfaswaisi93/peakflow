# Building an app on the PeakFlow API

The contract is `docs/api/openapi.yaml`, served at `GET /api/v1/openapi.yaml`.
Import that URL into Postman, or generate a client from it (`openapi-generator`
with `dart-dio` for Flutter). This page is the order of operations and the
rules that catch people out.

## 1. Sign in

```http
POST /api/v1/auth/sign-in
{"workspace": "demo", "email": "owner@demo.test", "password": "…", "device_name": "Layla's iPhone"}
```

- **201** returns a `device_session`. Keep:
  - `tenant.id`. Send it as `X-Tenant` from now on.
  - `entity_id`. Send it as `X-Entity`.
  - `access_token`. Send it as `Authorization: Bearer …`. It lives one hour.
  - `refresh_token`. Put it in the Keychain or Keystore, never in plain preferences.
- **202** returns a `second_factor_challenge`. Ask for the code, then:
  `POST /api/v1/auth/sign-in/second-factor` `{"workspace", "challenge", "code"}`. Success is the same 201.
- **401 `sign-in-refused`** is every failure, with one message. Do not try to tell the cases apart.
- **429** means wait for `Retry-After` seconds.

## 2. Call the API

Every call carries three headers:

```
Authorization: Bearer <access_token>
X-Tenant: <tenant.id>
X-Entity: <entity_id>
```

- `GET /me` returns who you are, the companies you can switch to, and this token's `scopes`.
  **Show an action only when its permission is in `scopes`.**
- Everything else:
  - `GET /overview`
  - `GET /invoices`, `GET /invoices/{id}`
  - `GET /bills`, `GET /bills/{id}`
  - `GET /parties`, `GET /parties/{id}`
  - `GET /bank-accounts`
  - `GET /periods`
  - `GET /reports/trial-balance`, `GET /reports/profit-or-loss`, `GET /reports/financial-position`
  - `GET /accounts`, `GET /journal-entries`
- Lists are cursor pages. Follow `links.next` until `meta.has_more` is false.
  `limit` must be one of 10, 25, 50 or 100.

## 3. Refresh before the hour is up

```http
POST /api/v1/auth/refresh        (X-Tenant header)
{"refresh_token": "pfrt_…"}                         – the next pair, same company
{"refresh_token": "pfrt_…", "entity_id": "…"}       – switch company
```

- **Store the new refresh token before you use the new access token.** A refresh token works once.
- If the app ever sends an old refresh token again, the server treats it as stolen. It closes the whole session, and the person signs in again.
- Refresh from one place in the app at a time. Two refreshes racing with the same token look exactly like theft.

## 4. Write

Every write that posts to the ledger needs an `Idempotency-Key`: a UUID made once per action, and reused unchanged on every retry of that same action.

- **Sell:**
  1. `GET /invoice-options` returns the revenue accounts, tax codes with today's rates, and payment terms.
  2. `POST /invoices` sends a customer and lines (quantity, unit price, tax code, revenue account).
     - The server computes every figure. Never send a total.
  3. `POST /invoices/{id}/issue` `{"payment_term": "NET30"}` (key required) numbers it, posts it and freezes it.
     - A draft that breaks a rule answers 422 `document-validation-failed`.
- **Record a payment:** `POST /payments`
  - It can apply the payment to invoices or bills in the same request. All or nothing.
  - A receipt settles an **issued** invoice, and a disbursement a **posted** bill, with the same party.
- **Bill approval:**
  1. `POST /bills/{id}/submit` (`bill.create`) opens the chain.
  2. `GET /bills/{id}` shows `approval.who_can_decide`.
  3. `POST /bills/{id}/approve` (`bill.approve`, key required): the **last** approval books the bill.
  4. `POST /bills/{id}/reject` `{"comment": "what to fix"}` returns it to draft.
- **Who may approve:**
  - The person who submitted cannot approve (403 `segregation-of-duties-conflict`). Show `who_can_decide` instead.
  - A bill edited during approval answers 412 `stale-write`, and its chain starts again.

Demo accounts for the chain (password `peakflow`):

| Account | Role |
|---|---|
| `ap@demo.test` | Submits bills |
| `controller@demo.test` | Approves bills |
| `owner@demo.test` | Second approver above 20,000 |

## 5. Sign out

- `POST /api/v1/auth/sign-out` ends this device's session.
- `GET /api/v1/auth/sessions` lists the person's devices.
- `DELETE /api/v1/auth/sessions/{id}` signs out the phone they lost.

## Rules that catch people out

- **Money is an object with a string amount:** `{"amount": "1234.5000", "currency": "SAR", "presentation_exponent": 2}`.
  - Parse `amount` as a decimal (Dart's `decimal` package), never as `double`.
  - Format it with `presentation_exponent` decimal places.
- **Quantities, unit prices and rates are decimal strings too.** They are not money, but they are exact.
- **Accounting dates** are `YYYY-MM-DD`. **Moments** are RFC 3339 in UTC.
- **Errors are RFC 9457 problem documents.**
  - Switch on `type`, never on `title` or `detail`.
  - `detail` follows `Accept-Language` (`ar` or `en`).
- **Every response has `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset`.** Slow down before you reach a 429.
- **Posting a journal entry needs an `Idempotency-Key` header,** a UUID per entry. Reuse the same key when you retry the same entry.
