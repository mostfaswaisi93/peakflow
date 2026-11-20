# Phase 01 — Tenancy, entities, identity and segregation of duties

**19 tasks · 84 points**

Two structures are built here and both are load-bearing for everything after. **Tenancy** is physical isolation: a database per customer. **Entity** is legal isolation inside a customer: a company that files its own return. Confusing the two, or adding either one late, is a migration across every financial table in the system.

The identity work in this phase is not generic authentication. It is the part of authentication that finance needs and most applications do not: mandatory second factors bound to specific permissions, re-authentication at the moment of an act, and segregation of duties evaluated against the audit trail rather than against a role list.

---

### PF-029 · Session and device hardening
`Identity` · **3 pts** · depends: PF-024 · standards: OWASP ASVS 5.0 · `type:security`

- [ ] Fortify for the session guard; separate guards for the operator and tenant planes
  - The two guards are done: `web` authenticates a customer's user from the tenant database, `ops` authenticates an operator from the platform database. Fortify itself is not installed (same registry limitation as above), so the scaffolding it would provide is still to write by hand.
  - Part of it now is. The second-factor stage of sign-in is written by hand, because the thing Fortify would have given us was missing in a way nothing noticed: `factor_asserted_at` was **read** by the step-up guard and by every `requires_mfa` permission and **written by nothing in the application**, so in production the column was null for every user forever and a permission marked as requiring a second factor was a permission nobody could exercise - not refused with a reason, just unreachable. The tests set it directly, which is why the whole mechanism looked built. Sign-in has two stages now and the second one writes it. What is still Fortify's share is password reset, email verification and the registration scaffolding.
- [x] Session fixation protection, idle and absolute timeouts, concurrent-session visibility and remote revocation
  - Fixation, idle and absolute timeouts are enforced. The absolute timeout is NOT refreshed by activity - without that, a session kept warm by a polling dashboard never expires, and a session that never expires is a credential in a cookie with no rotation policy. Listing and revoking a user's other sessions needs the account screen, which arrives with the shell (PF-204); the `sessions` table already carries what it needs.
- [x] Sessions bound to a device fingerprint; a binding change forces re-authentication
- [x] Login throttling per account and per IP, with a response that does not reveal whether the account exists

### PF-030 · Passkeys and TOTP
`Identity` · **5 pts** · depends: PF-029 · standards: WebAuthn Level 3, RFC 6238 · `type:security`

- [ ] WebAuthn passkey enrolment and authentication, multiple credentials per user, with a readable credential manager
  - **Blocked, not skipped.** The schema is in place (`authenticators.credential_id`, `public_key`, `sign_count`, which already carries the monotonic-counter invariant both factor types share). Attestation parsing and COSE key handling are not things to hand-write in the authentication path, and the library cannot be installed in this environment - the build reaches `api.github.com`, which the network policy refuses. See `docs/architecture/authentication.md`.
- [x] TOTP as the fallback, with recovery codes issued once and stored hashed
- [x] SMS is deliberately **not** offered, and the reason is documented
- [x] Enrolment and removal are audited; removing the last factor from a user holding a money-moving permission is refused

### PF-033 · The segregation-of-duties engine
`Identity` · **8 pts** · depends: PF-028, PF-035 · standards: ISO/IEC 27001 Annex A, SOC 2 · `risk:high` `type:security`

A permission answers *may this user do X*. An SoD rule answers *may the same user do X and then Y to the same object*. Conflating them is the classic hole: a clerk who may both create a supplier and release a payment can pay themselves.

- [x] Incompatible duty pairs declared as data, evaluated at the moment of the second act against the audit trail of the first
- [x] The shipped pairs: supplier create or bank-detail change against payment release; bill create against bill approve; payment prepare against payment release; journal post against period close; credit note against the invoice's own issuer; reconciliation match against write-off
- [x] A conflict is **refused, logged and explained** - the message names the conflicting act, who performed it and when, so the user knows who to ask
- [x] Scope is per object where that is the meaningful unit, and per period where it is not
- [x] Tests cover both directions of every pair and the case where the two acts are performed by different users
- [x] **A duty is recognised by the audit ACTIONS that perform it, not by its own permission key.** Found while building PF-097: the engine matched `action = 'invoice.issue'` against an audit trail that records `invoice.issued`, because the trail says what happened in the past tense and a permission key says what somebody may do. It matched nothing. Every object-scoped rule over a real flow was a control that passed its own tests - against audit records those tests wrote under the key the engine was looking for - and could not fire in production. `config/sod.php` holds the mapping, `bin/verify sod` fails the build if a duty maps to an action nothing writes, and reverting the engine turns the end-to-end credit-note control red
- [ ] Three duties still have no act the trail can see, each declared in `config/sod.php` with the task or phase that closes it: creating or updating a party writes no audit record, so the "whoever creates a supplier" half of the bank-details pair is blind; and a prepared tax return persists nothing until it is filed, so the maker-checker pair over a return enforces nothing. The pairs they belong to are not live yet - payment release is Phase 09 - and the check is what will force each one when its flow lands

### PF-034 · SoD overrides and the exceptions report
`Identity` · **3 pts** · depends: PF-033 · standards: SOC 2 · `type:security`

A tenant with three staff may genuinely be unable to separate two duties. Silently allowing it and silently blocking it are both wrong.

- [x] A documented override requires a reason, a specified approver and an expiry
- [x] The override is itself an audit event and appears on a standing exceptions report
- [x] Overrides cannot be self-approved, and cannot be granted for the beneficiary-change-against-payment-release pair at all
- [ ] The report is part of the `compliance:report` evidence pack
  - **Attempted and reverted, and the reason is the useful part.** `sod_exceptions` was added beside `sod_overrides` - they answer different questions and an auditor asks both: `in_force` is which overrides are live, and `exceptions` is what the control actually did, where it refused and what was done under an override. A pack with only the first says what was *permitted* and not what happened.
  - It broke the pack's reproducibility: `the evidence pack is the same pack twice` started failing. Two packs for one date have to be byte-identical, because the next thing that happens to a pack is a diff against last quarter and one whose bytes move makes every section look changed.
  - The obvious cause was row ordering - `SodExceptionsReport` ordered on `created_at` alone, and rows written in one transaction share it to the microsecond, leaving their order to the planner. **That was a real defect and is fixed**, with `id` as the tie-break. The pack still moved, so something else in that report is not reproducible and I did not find it.
  - So the section is out and the criterion is open. Shipping a pack that fails its own reproducibility check to gain a section trades the property an auditor relies on for one they asked for.
  - **What did land:** the pack now carries `scheduled_runs`, replacing a `not_included` caveat that said no durable record of scheduled-run outcomes existed - true when written, untrue since PF-241. A check asserts nothing is listed as not-included while also being in the pack, because a caveat nobody re-reads is worse than no caveat.
  - Windowed to the year before the date, because an exceptions report over all time grows until the recent entries are at the bottom of a page nobody reaches.
  - **It also corrected a caveat that had gone stale.** `not_included` said for months that no durable record of scheduled-run outcomes existed - true when written, untrue since PF-241 built `platform.scheduled_runs`. The pack now carries a `scheduled_runs` section instead, with successes as well as failures: a run missing from a list of failures looks exactly like one that succeeded, and exactly like one that never started.
  - A check asserts both sections exist **and** that nothing listed in `not_included` is also in the pack. A caveat nobody re-reads is worse than no caveat: an auditor reads it and stops looking for a section that is there. Both legs are bitten.
