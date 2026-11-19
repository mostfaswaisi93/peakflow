# Threat model and abuse cases

**PF-232.** Written per trust boundary, and every abuse case names the
control that stops it **and the check that proves the control**. Those check
names are not decoration: `ArchitectureRules::everyAbuseCaseNamesALiveCheck()`
reads them out of this file and fails if one names a check that no longer
exists.

That is the difference between a threat model and a document about threats. A
model whose controls are described in prose decays the first time one is
refactored, and nobody finds out - because the thing it describes still
*sounds* true.

## What a threat model is for here

Not to enumerate attackers. The attackers in an accounting product are
ordinary: somebody who can already sign in, somebody who was given a
permission and keeps it, somebody who leaves. **The interesting failures are
authorised actions in the wrong order**, which is why the abuse cases below
are mostly pairs of legitimate acts rather than single illegitimate ones.

---

## The trust boundaries

### 1. Browser → application

Everything past the session cookie. The asset is the session itself: a signed-in
accountant can do everything the accountant can do.

**Controls.** Host-prefixed secure cookies; a per-request CSP nonce with no
`unsafe-inline` that can run; a second factor on money-moving permissions;
step-up re-proof per object; an absolute session timeout.

**Proved by:** `a secure session cookie is host-prefixed` ·
`the nonce in the header is the nonce in the page` ·
`money-moving permissions demand a second factor` ·
`a stolen session cannot reopen a closed period`

### 2. Application → tenant database

The application role is **subject to row-level security**, deliberately: the
application scope is not the only line of defence, because the application is
the thing most likely to have a bug.

**Controls.** Forced RLS on every entity-scoped table; no DDL for the
application role; an append-only audit log the role cannot amend.

**Proved by:** `every entity-scoped table has a forced RLS policy` ·
`row-level security refuses writes into another entity` ·
`the application role has no DDL` · `audit_log refuses UPDATE and DELETE`

### 3. Application → platform database

The registry holds every tenant's name, slug and plan. The asset is the
customer list.

**Controls.** A separate connection and role; the operator console reads no
tenant data without impersonation, which is recorded in both planes.

**Proved by:** `the tenant plane does not depend on the platform plane` ·
`a tenant sign-in authenticates nobody on the operator plane` ·
`every operator route is guarded`

### 4. Application → Exchange

The plane that signs documents and holds transport credentials. A leak here is
not one customer's data: it is the ability to issue documents in their name.

**Controls.** `Exchange` cannot read a credential from configuration at all;
XML parsing resolves no external entity; webhook delivery refuses private
ranges and follows no redirect.

**Proved by:** `the Exchange plane reads no secret from configuration` ·
`an external entity reads no file, at every reader` ·
`a billion-laughs payload is refused before it expands`

### 5. Exchange → partner

Outbound, to a tax authority or a trading partner. **Data leaves the region
here by law** - that is what clearance is - so the control is not containment
but evidence.

**Controls.** Every artefact archived with a retention basis and a hash; the
archive verified on a schedule; a chain that proves the document was not
amended after issue.

**Proved by:** `every archive kind the archive declares round-trips` ·
`a credit note has its own series and its own chain`

### 6. Operator → tenant

The one boundary where the insider is us. An operator who can read a tenant's
books unattributably is the worst case in the product.

**Controls.** Reading requires impersonation; impersonation requires the
tenant's prior consent and writes an event in both planes; the operator's own
activity log is append-only and the application role cannot amend it.

**Proved by:** `the operator's own activity log cannot be amended` ·
`and the matrix notices if UPDATE on it is granted back`

---

## Abuse cases

Each is a sequence of **authorised** acts. That is what makes them abuse cases
rather than misuse cases, and it is why none of them is stopped by
authentication.

### Beneficiary substitution

Change a supplier's bank account, then release the payment to it. Both acts
are ordinary; together they are the highest-value fraud in the system and need
no accomplice.

**Control.** `beneficiary_then_release`, scoped to the party, marked
`overridable = false` - nothing in the application grants an exception. The
pair is asserted at both ends, because the order is not fixed: releasing first
and changing the account afterwards is the same conflict arrived at backwards.

**Proved by:** `the beneficiary/release pair can never be overridden` ·
`a release by nobody is refused, not waved through`

### Self-approval through a delegate

Prepare a payment run, then release it - or have a delegate do one half while
you do the other.

**Control.** `prepare_then_release`, scoped to the object, also
non-overridable. A release with no identifiable actor is **refused** rather
than skipped, because "we cannot tell who" is not "no conflict".

**Proved by:** `whoever prepared a run does not release it` ·
`every duty pair is refused in both directions`

### Reopening a period to hide a posting

Close a period, then reopen it, post, and close it again - leaving a trial
balance that was signed off over different numbers.

**Control.** Reopening is a recorded act with an author and a reason, it
appears in the compliance pack, and a session that is not freshly proven
cannot do it.

**Proved by:** `a reopening is flagged and reaches the compliance report` ·
`a stolen session cannot reopen a closed period` ·
`a closed period refuses a posting`

### A credit note used to erase a receivable

Credit an invoice to zero rather than writing it off, so the write-off control
and its approval never run.

**Control.** A credit note carries the document it credits, has its own series
and chain, and reduces the ageing rather than vanishing from it - so the
receivable's history still shows what happened.

**Proved by:** `a credit note must say what it credits` ·
`a credit note reduces the aging rather than adding to it` ·
`a credit note has its own series and its own chain`

### Export as exfiltration

Ask for the tenant export, and receive another tenant's data with it - or use
an export to take out what a permission would not have given.

**Control.** The export reads the tenant connection only and never the
platform registry; the check takes a real identifier belonging to a different
tenant and asserts it appears nowhere in the archive.

**Proved by:** `no other tenant appears anywhere in the archive` ·
`every logical table is written, and no partition is written twice`

### A token outliving its purpose

An integration is switched off and its credential is not; the token keeps
working for as long as nobody looks.

**Control.** The access review surfaces tokens unused inside a window, by
name, and runs monthly.

**Proved by:** `the access review names who holds what, and what has gone unused`

---

## The review checklist

`CONTRIBUTING.md` requires this of any pull request touching money,
authorisation or segregation of duties. Each line is a question with a wrong
answer, not a reminder.

**Each is marked with what enforces it.** A line marked `[check]` names the
check that already refuses the mistake, so a reviewer does not have to look
for it; a line marked `[human]` is one no check can make, and it is the
reviewer's actual job. Saying which is which is the point: a checklist whose
items are all already automated wastes the reviewer's attention, and one that
pretends a machine is watching something it is not spends it in the wrong
place.

1. **Does this add a way to perform a duty?** Then it must `assert()` the
   duty, and pass the actor's id rather than relying on a session.
   `[check]` `a release by nobody is refused, not waved through` -
   `every duty pair is refused in both directions`
2. **Does it read an actor from `auth()`?** Then it does not work from a
   console command or a queued job, and the control it carries is skipped
   there rather than failing. `[human]` - the read is legitimate in a
   controller and wrong in a service, and nothing can tell them apart from
   the call site alone.
3. **Does it write a `*_by_user_id`?** Then what happens when there is no
   user - does it refuse, or write null and look like it worked?
   `[human]` - which of those is right depends on whether the column records
   a human decision or a machine posting, and only the author knows.
4. **Does it add a table with `entity_id`?** Then it needs forced RLS, or a
   `peakflow:tenant-scoped` comment saying why not.
   `[check]` `every entity-scoped table has a forced RLS policy`
5. **Does it add a column holding a personal-data shape?** Then it belongs in
   the data-protection register before it is merged.
   `[check]` `every table holding personal data is in the register`
6. **Does it widen what a permission can reach?** Then the SoD rules that
   mention that permission have been re-read, not assumed.
   `[human]` - "what this permission can now reach" is a question about
   intent, and a rule that still passes is not evidence that it still means
   what it meant.
7. **Does it log anything?** Then the payload has been checked against the
   allowlist - and the check is "what does the line contain", not "what did I
   intend to put in it".
   `[check]` `a payload of secrets produces a line containing none of them` -
   `an error report carries the diagnosis and none of the payload`
8. **Does it add a command?** Then it is scheduled, or it says why not.
   `[check]` `every command is either scheduled or says why it is not`
9. **Does it add a migration?** Then it declares whether it may run live.
   `[check]` `and every migration says whether it is`
