# Phase 03 — Parties, catalog, currencies and FX

**16 tasks · 66 points**

The master data every document references. Two parts of this phase carry more risk than their size suggests: **party bank details**, because beneficiary substitution is the highest-value attack in the entire product, and **FX rates**, because a rate stored with the wrong direction or inverted on the fly produces differences that are small, plausible and impossible to explain.

---

### PF-060 · Addresses and tax registrations
`Parties` · **3 pts** · depends: PF-059 · standards: ISO 3166-1, EN 16931, Peppol EAS

- [x] Multiple typed addresses per party: legal, billing, delivery, with the EN 16931 address terms as the shape
- [x] Tax registrations with jurisdiction, scheme and identifier, validated for structure where the scheme defines one
- [x] Electronic addressing identifiers with their **EAS** scheme code, which is what Peppol routing needs
- [ ] A party's billing address and tax registration are **copied onto a document at issue**, never referenced - an issued invoice is a historical statement

### PF-072 · Global search
`—` · **5 pts** · depends: PF-059, PF-064 · standards: WCAG 2.2 AA · `type:a11y`

- [ ] Meilisearch across documents, parties, accounts and items, scoped to the tenant and filtered by the user's entity roles and permissions **in the engine's own filter**, not after the fact
- [ ] Searching by phone number, tax registration, invoice number or amount all work, because those are what people actually have to hand
- [ ] Soft-deleted rows stay hidden; a permission a user lacks hides the result rather than returning a forbidden one
- [ ] Keyboard-navigable results with `Ctrl`/`⌘`+`K`, arrows and `Enter`; indexing is queued and a failure never blocks a write

### PF-281 · A demo fixture somebody can sell from, in shekels
`—` · **8 pts** · depends: PF-056, PF-068 · standards: ISO 4217 · `risk:high`

**Commits to:** the seeded workspace being the product's shop window rather than a smoke test.

The development seeder exists to prove the thing boots. What a demo needs is different: a company with a year of trading behind it, so that every screen has something true on it and a trial balance that actually balances. A product demonstrated on three invoices is a product the viewer has to imagine.

**The functional currency is the shekel.** That is a change of default, not a new option: `ILS` becomes the seeded entity's functional currency, and the fixture's foreign-currency work is measured against it rather than against the riyal.

- [x] The seeded entity's functional currency is **ILS**, and every derived figure - the trial balance, the ageing, the control accounts - is stated in it
  - The showcase entity is `ILS`/`PS`: 75 invoices, 57 bills, 97 payments and 324 journal entries over a year, trial balance 0.0000, receivables 297,332.50, payables 105,253.75.
  - Changing the currency alone would not have been enough. The VAT control roles are stamped with the **entity's country** when the chart is applied, so the registration, the party countries, the bank and the tax code had to move together or the books would have been a Saudi VAT registration billing in shekels. `ChartTemplate` is right to stamp them - a template cannot know where its user is registered - which is why the fix is in the seeder.
  - 16% is recorded as **the demo's assumption**, with its basis (the Paris Protocol band against Israel's 18%), not as a verified statutory citation: no authority source is readable from this environment, and `config/tax_returns.php` already marks the box numbers it could not verify the same way. `PS` needs no jurisdiction profile - it falls through to the `generic` EN 16931 return.
- [ ] Rates exist for the pairs the fixture actually uses, on the dates it uses them, so no document is posted at a rate nobody loaded - the seeder fails loudly rather than falling back
  - **The failing loudly half is proved, by accident.** The seeder was still posting SAR documents after the entity moved to ILS, and the product refused every one of them: *"The document is in SAR and the entity reports in ILS, so it needs a rate - with its source and date, frozen on the document. Nothing has been consumed - no number, no posting."* That is `IssueRefused`, not the seeder, which is the right place for it.
  - The other half is open with the criterion below: every document in the fixture is currently ILS, so there is no pair to load yet.
- [ ] At least one customer and one supplier trade in a **different** currency, so the FX difference account is non-zero and the revaluation has something to revalue
- [ ] A year of documents across the whole cycle: quotes that became orders, orders that became invoices, invoices part-paid and fully paid, a credit note, a dunning case, bills matched three ways, a payment run released, a bank statement imported and reconciled
- [x] The ledger **ties**: `bin/verify ledger` passes against the seeded data, and the receivables and payables ageing each equal their control account - a demo whose books do not reconcile is a demo that cannot be shown to an accountant
  - `bin/verify ledger` is 23/0 and `ledger:verify showcase` reports no subledger break. The clearing account washes to exactly **0.0000** across 97 allocations, which is the figure that proves it rather than the one that looks reassuring.
  - **It did not tie, and what it found were two production bugs**, not fixture mistakes. Both are below. A fixture that exercises the whole cycle is what found them; nothing in 180 settlement checks had ever posted a payment against a bill and then looked at the two accounts.
  - **1. Paying a supplier credited accounts payable.** `AllocationPostedRule` treated the payable case as "same shape, other control account" and swapped only the role, keeping the receivable's signs. A payable is a credit balance, so settling it is a debit - the entry still balanced, every row was individually valid, and the company appeared to owe **more** after paying. It put 524,860 into a clearing account that must wash to zero: twice the 262,430 paid, once for the sign on each leg. Caught now by `allocating to a bill closes the payable and washes the clearing`, whose bite reports both halves.
  - **2. The payables ageing could not see a payment.** `PayablesAging` asked `Bill::outstanding()`, which subtracts `bills.paid_amount` - a column **nothing writes**, because Settlement records an allocation in its own table and may not reach into Purchasing's models to denormalise it. So every posted bill aged at its full gross for ever: 41 of 56 bills paid, and the report still showed all 56. `BillListController` had already written down the cost - the screens read `peakflow_document_open_balance`, the ageing read the model, so "which bills are still open" had two answers and both looked right, and a payment run selecting from the wrong one pays a supplier twice. The ageing now asks the same function the screens do.
  - That function was itself incomplete. Its own docblock defines the open balance as "gross, less credit notes, less what has been allocated..." and the **bill branch implemented neither credit term**, on the reasoning that credit notes apply to invoices only. A bill is credited both by reference (`credits_bill_id`) and by allocation (`bill_credit_allocations`), and `Bill::effectiveCredit()`/`allocatedCredit()` sum exactly those. `2026_01_03_001400` adds them, so the function and the model finally mean the same thing.
  - A third thing turned up on the way: the check that was supposed to keep the open-item statement honest settled its bill by writing `paid_amount` directly. It passed against a state the product cannot reach, which is why it reported nothing while the statement listed every paid bill for ever. It now settles through a real credit note, and the paid case lives in the settlement suite where the services for it are.
- [x] Dates are relative to the run, not hard-coded, so the fixture is never "last year's demo" and the ageing buckets have something in each
  - 365 days back from the run, which is also what put something in the oldest bucket: at 150 days nothing could reach a 365-day column, and an ageing report whose last column is always empty reads as a broken report rather than as a company that collects its debts.
  - Spanning a year boundary needed two fiscal years and two years of return periods. The first version generated only the current year and stopped at "No accounting period covers 2025-11" - a fixture that would have been green for most of the year and broken every January.
- [x] It is **separate from** the development seeder and the two do not drift: the development one stays minimal and fast, the demo one is the one with a year in it, and a check asserts both still run
  - The showcase seeder now provisions **its own** workspace (`showcase`, entity `SHOW`, owner `owner@showcase.test`). It used to borrow `demo`, which made the two fixtures one: the development seeder had to stay in whatever shape the demo books needed.
  - The coupling had a second cost that is the more interesting one. `demo` is created once and re-seeded for months, so its chart is whatever template version was current the day it was provisioned - and `ChartTemplate::apply()` is a deliberate no-op on an entity that already has a chart. An entity created under chart v5 never receives the accounts v6 added, **there is no upgrade path and an entity records no chart version**, and the symptom is the books refusing a posting for want of an `unapplied_cash` account the template has had for versions. A workspace provisioned when the showcase is seeded has the current chart because it has no other. The upgrade path is its own task.
  - Two drift repairs went into `DevelopmentSeeder` while finding this, both the same shape - a guard too coarse to notice the state it was in. The chart was applied only inside the `entity === null` branch, so an entity that existed *without* a chart was never repaired; and `repoint()` returned early when the currency already matched although it also writes `country`, so an entity already on ILS kept the country it was created in - which is how its VAT control roles came to name a jurisdiction the entity had left.
- [x] Every person in it is invented, under a reserved domain, as PF-239 requires
  - `owner@showcase.test`, and `.test` is one of the domains RFC 2606 reserves - which `RepositoryRules` enforces rather than requests.
  - The bank is invented too, and deliberately so: `PS51DEMO...` and `DEMOPS22` carry **real check digits**, so the fixture exercises the IBAN validator instead of bypassing it, while `DEMO` is not an assigned bank code. The fixture used to carry a real Saudi bank's BIC. A demo that names a real institution names a company that had no say in it.
