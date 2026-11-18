# Phase 03 — Parties, catalog, currencies and FX — finished

The tasks of [phase-03-masters.md](../phase-03-masters.md) that have no open criterion left.
They are kept, not deleted: the notes under each one are the record of
why the thing was built the way it was, and that is the part somebody
needs when they come to change it. The phase file holds what remains.

---

### PF-059 · The party model
`Parties` · **5 pts** · depends: PF-026 · standards: — · `type:schema`

- [x] One `parties` table with **roles** (`customer`, `supplier`, `employee`, `both`), not separate customer and supplier tables - the same company is frequently both, and duplicating it guarantees the two copies diverge
- [x] Legal name, trading name, registration numbers, country, default currency, default language, payment terms, tax treatment
- [x] Parties are shared across the tenant; a party's **balances** are per entity, because a customer billed by two companies owes each of them separately
- [x] Soft deletes, so a party's name still resolves on a document issued years ago
- [x] Merge for duplicates: re-points documents, preserves both histories, is audited and is reversible

### PF-061 · Party bank details
`Parties` · **5 pts** · depends: PF-008, PF-035 · standards: ISO 13616, ISO 9362 · `risk:high` `type:security`

The highest-value fraud surface in the product.

- [x] IBAN and BIC validated on entry; domestic identifiers where IBAN does not apply
- [x] **Column-level encryption** at rest, separate from any disk or database encryption, with the key referenced from the KMS
- [x] Every change is audited with before and after, actor, time and source
- [x] A changed beneficiary account marks the party as **requiring re-verification**, and the next payment to it is blocked until a second user confirms the change through a channel the system did not supply
- [x] Paired by SoD with `payment.release` (`PF-033`), and that pair cannot be overridden
- [x] Bank details never appear in a log, an export, a webhook payload or an error message - asserted by test

### PF-062 · Credit control
`Parties` · **3 pts** · depends: PF-059 · standards: IFRS 9

- [x] Credit limit per party per entity, in the entity's functional currency
- [x] Exposure computed as open receivables plus uninvoiced orders, and shown against the limit before an order is confirmed
- [x] Credit hold blocks issuing new invoices, with an explicit override that is permissioned and audited
- [x] A **dispute flag** per invoice, which suspends dunning and moves the invoice to a dispute report rather than letting it escalate
- [x] A risk grade per party, used later by the IFRS 9 provisioning matrix (`PF-173`)

### PF-063 · Contacts and communication
`Parties` · **2 pts** · depends: PF-059 · standards: —

- [x] Multiple contacts per party with roles (`billing`, `ap`, `primary`) and per-contact locale
- [x] Document delivery preferences: which channel, which address, which language, which format
- [x] A document is rendered in the **recipient's** language, not the sender's, which is the whole reason locale lives on the contact

### PF-064 · Billable items
`Catalog` · **5 pts** · depends: PF-007, PF-026 · standards: UN/CEFACT Rec 20, UNSPSC, GS1 GPC, UNCL 7143 · `type:schema`

- [x] Code unique per tenant, bilingual name and description, unit of measure, default tax code, default revenue account, default dimensions
- [x] Classification codes with their scheme identifier, which EN 16931 requires as a coded pair rather than a bare string
- [x] Item types: `good`, `service`, `digital_service` - the distinction drives place-of-supply, so it is a first-class field and not a category
- [x] An item's **revenue recognition default** (immediate, over a period, on milestone), feeding `PF-107`
- [x] Items are shared across the tenant; their default accounts resolve per entity, because the chart differs

### PF-065 · Price lists
`Catalog` · **5 pts** · depends: PF-064 · standards: —

- [x] Per party, per party group, per currency, **effective-dated**, with quantity tiers
- [x] Resolution order is explicit and inspectable: a screen shows which list and which rule produced a given price
- [x] A price in a currency with three decimals keeps three; a unit price keeps the full eight-decimal scale
- [x] Historical prices resolve by the document's own date, not by today

### PF-066 · Payment terms and due dates
`Catalog` · **3 pts** · depends: PF-059 · standards: UNCL 2475, EN 16931

- [x] Terms as data: net days, end-of-month plus days, fixed day of month, instalments, and early-settlement discount terms
- [x] Due date derived from the terms and the document date, with the derivation shown rather than a bare date
- [x] Instalment terms produce multiple due dates on one invoice, each separately dunnable and separately allocatable - which the aging report must handle
- [x] Non-business-day handling is a per-entity policy with a holiday calendar, not a hard-coded weekend

### PF-067 · Currencies per entity
`Catalog` · **2 pts** · depends: PF-007, PF-025 · standards: ISO 4217

- [x] An entity enables the currencies it transacts in; one is its functional currency
- [x] A document in a currency the entity has not enabled is refused rather than silently converted
- [x] Display policy per currency: symbol or code, position, digit shape, and the exponent used for presentation

### PF-068 · The FX rate model
`Catalog` · **5 pts** · depends: PF-067 · standards: IAS 21 · `risk:high` `type:schema`

- [x] Rates are `(from, to, date, rate, provider, rate_set)` and **effective-dated**
- [x] **Direction is stored, never inferred.** `USD/EUR` and `EUR/USD` are separate rows if both are used, because `1/x` on a rounded rate does not round-trip and a one-cent consolidation difference is a question someone will ask
- [x] Multiple coexisting rate sets: a daily market rate, a monthly average, and a statutory central-bank rate - because tax authorities frequently mandate their own and it is not the one the treasury uses
- [x] Rates stored at the 12-decimal rate scale
- [x] A missing rate is an explicit, surfaced condition, never a silent fallback to 1

### PF-069 · Rate fetching
`Catalog` · **3 pts** · depends: PF-068 · standards: —

- [x] `fx:fetch-rates` with multiple configured providers and a defined precedence
- [x] A provider failure falls back to the next, then to the last known rate, and **raises an alert** - it does not block invoice issuance, because a rate feed outage must not stop a business trading
- [x] A rate older than a configured staleness threshold is flagged on every document that uses it
- [x] Idempotent per `(pair, date, rate_set, provider)`

### PF-070 · Period-end revaluation
`Catalog` · **5 pts** · depends: PF-068, PF-050 · standards: IAS 21 · `type:posting`

- [x] `fx:revalue` restates **monetary items only** at the closing rate - receivables, payables, bank balances. Non-monetary items are not revalued, which is the distinction IAS 21 turns on
- [x] The unrealized difference posts to a dedicated revaluation account, and is **reversed and recomputed** each period rather than accumulated
- [x] Re-runnable within an open period without double-posting
- [x] The posting is written out as debits and credits in [`../domain/general-ledger.md`](../domain/general-ledger.md)

### PF-071 · Numbering series
`Catalog` · **5 pts** · depends: PF-025 · standards: — · `risk:high` `type:schema`

The storage and configuration; the allocation algorithm is `PF-093`.

- [x] `number_series` per entity, per document type, per year, optionally per branch - so two entities or two document types never contend on one lock
- [x] Format template (`INV-{entity}-{YYYY}-{seq:6}`) with a preview
- [x] The format is **frozen once the series has issued its first document**, because changing the shape of a sequence mid-year breaks its own gaplessness
- [x] A series records its last issued number, its first number and its gap-check status
- [x] A gap report per series, which is what an auditor asks for

### PF-073 · Attachments and document storage
`—` · **5 pts** · depends: PF-026 · standards: — `type:security`

- [x] Attachments on any document, with the content type **sniffed rather than trusted**, a size cap, and a virus scan before the file is readable. The browser's type is whatever the uploader's client said, and an uploader who renames `payload.html` to `invoice.pdf` gets `application/pdf` from every client there is
- [x] The order of operations *is* the security property: write to a temporary path, sniff, refuse an unpermitted type, refuse a name that disagrees with the content, refuse anything over the cap, hash, deduplicate, **then** scan and store. A scan that runs on a schedule afterwards tells you what you have already been serving
- [x] The permitted list is an **allowlist**, and SVG and HTML are absent deliberately: both execute script in a browser and no accounting document needs either. A denylist is a list of the dangerous formats somebody thought of
- [x] A name whose extension disagrees with the content is refused with a message that says so - "this file is named .pdf and is an HTML document" tells somebody they have been sent something odd, where "this type is not allowed" sends them to a settings page. An Office file legitimately sniffs as `application/zip`, which is not a mismatch
- [x] There is deliberately **no scanner driver that accepts everything**. `unavailable` stores the file and returns `failed`, which the read path refuses - so a machine with no ClamAV can exercise the whole upload path and still cannot serve a file nobody has looked at. That driver in production is a configuration problem the guard reports
- [x] And `failed` is not `clean`: a `clamdscan` exit of 2 - a daemon that is not running, a socket with the wrong permissions - is a scanner that has told you nothing, and treating nothing as fine is the one decision that makes a virus scan decorative
- [x] Served from a separate origin with `Content-Disposition: attachment` and a short-lived signed URL, so a stored HTML or SVG file cannot execute against the application's origin. Three layers - the origin, the disposition and `nosniff`, plus a `sandbox` CSP - because each of the others has been bypassed somewhere
- [x] The signature **is** the authorization on that route, deliberately: the download origin has no cookie for this application, so there is no session to authorize against, and adding one would be the thing a separate origin exists to avoid. The link is minted for somebody already authorized and expires in minutes, because a permanent URL ends up in a browser history, a chat message and a support ticket
- [x] A refusal on download is a **404, not a 403**: distinguishing "expired" from "infected" from "gone" would let somebody with a stale link learn whether a particular attachment exists
- [x] Object storage with per-tenant prefixes; the stored path is derived from the content hash and the entity and **never** from the name - `../../.env` is a name, and a path built from user input is a path traversal. The path fans out by the first two bytes of the hash so one directory does not hold a million entries
- [x] Deduplication by content hash, with reference counting so one tenant's delete does not remove another's copy - four bills carrying the same contract are one object and four claims, and releasing one leaves three
- [x] Deduplication is **within a tenant**, deliberately. Sharing one object across tenants would save a few megabytes and let a tenant learn that another holds a particular file by uploading it and watching the reference count - and the file a tenant attaches is frequently one somebody else sent them
- [x] The hash is recomputed on **every read**, and a stored object that no longer matches its row is refused. Storage corrupts, a restore puts back an older version, and a scan verdict applies to the bytes that were scanned rather than to a path
- [x] `attachments:verify` re-hashes every object against its row and exits non-zero on divergence, so a scheduled run is an alert rather than a log line nobody reads. The first symptom of a lost attachment is otherwise an inspector asking for a contract
- [x] Found on the way: the first version of the mismatch check passed an HTML file named `invoice.pdf` and `mismatch()` correctly said nothing - `text/html` is not on the allowlist at all, so there is no extension set to disagree with and the upload is refused by type instead. The case the message exists for is two *permitted* types: a PNG called `invoice.pdf`
- [x] Found on the way: the attachments disk is configured to throw on a missing read, which is right - a silent null is how a missing object becomes an empty file served to somebody - and it meant `verify()` had to ask `exists()` first rather than treat the return value as the answer

### PF-074 · Tenant settings with platform ceilings
`Tenancy` · **5 pts** · depends: PF-022 · standards: —

- [x] A typed settings registry: key, type, scope (tenant or entity), default, validation, bilingual description
- [x] A tenant may override anything it may legitimately decide - dunning policy, matching thresholds, numbering formats, rounding tolerance, enabled jurisdictions
- [x] Risk-bearing settings carry a **platform-enforced ceiling**: a tenant may lower a write-off tolerance but not raise it past the operator's maximum
- [x] Every change is audited; a settings diff is part of the compliance evidence pack
- [x] Resolution order is entity, then tenant, then platform config, resolved once per request
