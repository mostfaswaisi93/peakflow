# Webhooks

Outbound notifications, delivered by the `Exchange` plane. Signed, timestamped, replay-protected, retried on a documented schedule, and inspectable by the tenant.

## Event catalogue

Each event has a stable schema and a version on the payload. Adding a field is not breaking; changing or removing one is a new payload version.

| Event | Fires when |
|-------|-----------|
| `invoice.issued` | An invoice is issued — after commit, so the document is complete |
| `invoice.sent` | Delivery succeeded on at least one channel |
| `invoice.cleared` | An authority cleared it (ZATCA and similar) |
| `invoice.rejected` | An authority rejected it, **with the authority's own reason** |
| `invoice.paid` | Fully allocated |
| `invoice.overdue` | Passed its due date |
| `invoice.disputed` | A dispute flag was set |
| `credit_note.issued` | |
| `bill.received` | An inbound document became a draft bill |
| `bill.approved` | Cleared its approval chain |
| `payment.received` | Cash recorded |
| `payment.allocated` | Allocated against one or more documents |
| `payment_run.released` | **The instruction file was released.** The highest-consequence event in the catalogue |
| `payment_instruction.rejected` | A PAIN.002 rejection, per instruction |
| `statement.imported` | With a count and the continuity result |
| `reconciliation.completed` | |
| `period.closed` / `period.reopened` | The second one matters for monitoring |
| `tax_return.filed` | |
| `journal_entry.posted` | Opt-in; high volume |
| `ledger.invariant_breached` | **Drift, chain divergence or a subledger mismatch.** A tenant's own monitoring should see this |

## Payload

```json
{
  "id":         "01JD3XQ7F8K2M9N4P5R6S7T8V9",
  "type":       "invoice.issued",
  "version":    "1",
  "created_at": "2026-11-14T09:31:22Z",
  "tenant":     "01JD3X...",
  "entity":     "01JD3Y...",
  "sequence":   48213,
  "data": {
    "invoice_id": "01JD3Z...",
    "number":     "INV-ACME-2026-000418",
    "party_id":   "01JD40...",
    "issue_date": "2026-11-14",
    "due_date":   "2026-12-14",
    "gross":      { "amount": "1150.0000", "currency": "SAR" }
  }
}
```

- `sequence` is **monotonic per tenant**, so a consumer can detect a gap.
- `data` carries **identifiers and amounts, never bank details** — asserted by test. A consumer that needs more fetches the resource with its own token, which keeps authorization in one place.

## Signing

```
PeakFlow-Signature: t=1763112682,v1=5f3a9c...
```

```
signed_payload = "{timestamp}.{raw_request_body}"
v1             = HMAC-SHA256(secret, signed_payload)
```

> **The timestamp is inside the signed material**, which is what makes a captured request unusable later. A signature over the body alone can be replayed indefinitely.

Verification, which the documentation gives as runnable code because **a signature nobody verifies correctly is decoration**:

1. Reject if `|now − t|` exceeds the tolerance (default 300s).
2. Recompute `v1` over `"{t}.{raw body}"` — the **raw** body, before any JSON parsing or re-encoding.
3. Compare in **constant time**.
4. Reject a repeated `id` within the tolerance window.

**Secret rotation** has an overlap window: two secrets are valid, both signatures are sent, and the tenant removes the old one when ready. No delivery is dropped to rotate a secret.

## Delivery and retry

| Attempt | Delay after the previous |
|---------|--------------------------|
| 1 | immediate |
| 2 | 10s |
| 3 | 1m |
| 4 | 5m |
| 5 | 30m |
| 6 | 2h |
| 7 | 6h |
| 8 | 24h |

A `2xx` within 10 seconds is success. `4xx` other than `408` and `429` stops retrying — the endpoint is telling us it will never accept this. `5xx`, timeout, `408` and `429` retry.

After the final attempt the delivery is **dead-lettered**, and an endpoint failing consistently is **disabled with a notification** rather than retried forever.

## Inspectable and replayable

The tenant can see, for every delivery: the event, the endpoint, each attempt, the response status, a response body excerpt, and the signature sent. And can **replay** any delivery.

This exists because the common failure is a consumer bug, and the first question is always "what did you actually send me". Without a delivery log that question costs a support cycle.

## Ordering

Deliveries are ordered **per tenant** where ordering matters, and the `sequence` lets a consumer detect a gap. Consumers must still be idempotent on the event `id`: a retried delivery after an ambiguous timeout is normal, and at-least-once is the guarantee.

## Outbound security

- **An allowlist refuses private address ranges, link-local addresses and loopback**, so a webhook URL cannot be used for SSRF against internal services.
- HTTPS only; certificate verification is never disabled.
- Redirects are not followed — a redirect to an internal address is the obvious bypass.
- A per-endpoint request timeout and a per-tenant concurrency limit, so one slow consumer cannot starve the queue.
- Payloads are never logged in full; the delivery log stores an excerpt and a hash.

## Inbound documents

Peppol and clearance callbacks arrive through the same plane and are treated as **untrusted input**:

- Validated **before** they are parsed
- XXE, external entity resolution, DTD loading and entity expansion **disabled explicitly** on every reader
- Size-capped before parsing
- Authenticated by the transport's own mechanism, never by a field in the payload
- The original bytes archived as received, whatever happens next
