# Authentication and second factors

PF-029 through PF-032. Three questions that look like one, and a deliberate omission.

## Three questions, kept apart

| Question | Answered by | Failure if collapsed into another |
|----------|-------------|-----------------------------------|
| **May** this user do X? | Roles and permissions, via the Gate | — |
| May the **same** user do X given what they already did? | `SodEngine`, against the audit trail | A clerk who may both change a supplier's bank details and release a payment can pay themselves |
| Has the user **proved they are present**? | `StepUpGuard` | A stolen session cookie carries every money-moving capability its owner holds, and the password that protected it was phished weeks ago |

The second and third cannot be answered from roles. Both duties are legitimately inside the user's role — that is *why* the controls exist.

## `requires_mfa` and `requires_step_up` are different demands

`requires_mfa` is a property of the **session**: the user must have a confirmed factor and must have used it to establish this session.

`requires_step_up` is a property of the **act**: they must prove themselves again, now, for this specific thing. Releasing a payment is the canonical case — a session that was legitimately MFA-authenticated an hour ago says nothing about who is at the keyboard now.

The step-up set is a strict subset of the MFA set, and a check enforces that. A permission demanding re-authentication but not enrolment would be incoherent: there would be no factor to re-authenticate with.

### Scoped to the act, and spent

An assertion carries `authorized_for` (a permission key) and `subject_id` (the thing it applies to), and `consumed_at` is set when the act happens.

Most applications implement a session-wide "recently authenticated" flag instead. That means a user who proved themselves to approve one invoice has, for the next fifteen minutes, silently proved themselves for every other thing re-authentication protects — including the one an attacker with their unlocked laptop wants. Two payments require two proofs.

## The factor is required at the point of GRANTING

A role including `payment.release` cannot be granted to a user with no second factor. `RoleGrantService` refuses, and the message is addressed to the administrator doing the granting.

Enforcing this only at the point of **use** is easier to implement and much worse. The administrator finds out while they are doing administration, with the user in front of them and nothing urgent happening; the user would otherwise find out on a Friday afternoon with a payment run to release and the person who could help them gone. The control is identical either way — the cost of enforcing it in the wrong place is a support call and a strong incentive to switch it off.

The guard at the point of use still runs, because a factor can be removed after a grant. It exists so that it almost never fires.

## The last factor cannot be removed

`MfaService::removeAuthenticator()` refuses to remove a user's only confirmed factor while they hold any permission that requires one.

Without that refusal, the route around every MFA requirement in the system is self-service: remove your own authenticator, and the permission that demanded one has nothing left to demand. A user who genuinely lost their device uses a recovery code to enrol a replacement, or an administrator revokes the permissions first. Both leave a trail; deleting the factor silently would not.

## TOTP

RFC 6238, implemented in `app/Support/Mfa` rather than taken from a package: it is forty lines of HMAC, the standard has not changed since 2011, and a dependency in the authentication path is a dependency that can take the authentication path down.

Verified against the **published test vectors** from RFC 6238 Appendix B, including one past 2038. An authentication primitive verified only against itself is verified against nothing — a consistent off-by-one in the counter packing produces codes that are stable, self-consistent, and rejected by every authenticator app in the world.

| Property | Value | Why not otherwise |
|----------|-------|-------------------|
| Digits | 6 | What every app expects |
| Period | 30s | Same |
| Window | ±1 step | Widening it is the obvious response to "codes don't work" tickets and the wrong one: each extra step multiplies guessable codes and extends how long a shoulder-surfed code stays useful. A clock minutes out is a clock problem, and saying so fixes it permanently |
| Replay | Last accepted step stored; anything at or below it refused | A correct code stays correct for its whole step, so a code relayed by a phishing proxy works again seconds later unless the step is remembered |

Enrolment is **two steps**. `beginTotpEnrolment()` creates an unconfirmed authenticator; `confirmTotpEnrolment()` requires a working code first. Confirming on creation is the tempting shortcut and it locks people out — a user who scans a QR code into an app with a wrong clock would have MFA enabled on an authenticator that can never produce an accepted code.

## Recovery codes

Ten, hashed, single-use, grouped as `XXXXX-XXXXX` because someone reads them off a screen and types them into a phone a month later.

Regeneration replaces the **whole set**. A user who has spent three of ten has a problem that adding three more does not address, and partial regeneration makes "how many are left" unanswerable. Using one is audited with the number remaining, because a run of them is either a lost phone or an attacker working through a list.

## SMS is deliberately not offered

Not as a factor, not as a fallback, not "temporarily until passkeys ship". The reasons, in the order they matter:

1. **SIM swap is a service-desk procedure, not an exploit.** An attacker with a target's name, date of birth and a plausible story can have the number moved to their own SIM. There is no cryptographic step to attack because the carrier's identity check *is* the security boundary, and it is a person who wants to be helpful.
2. **SS7 and SMS routing are not confidential.** The messages traverse networks the sender does not choose and cannot audit. Interception at scale has been demonstrated repeatedly and does not require access to either endpoint.
3. **It is phishable in exactly the way a factor must not be.** A relay page asks for the code and forwards it within the 30–60 seconds it stays valid. A passkey is bound to the origin and cannot be replayed to a different one; an SMS code has no idea which site asked for it.
4. **Offering it as a fallback defeats the factor it falls back from.** An attacker chooses the weakest enrolled method. A user with a passkey *and* SMS is protected by SMS, so adding it makes the account less safe, not more.
5. **NIST SP 800-63B restricted it in 2017** and the reasoning has only strengthened since.

The cost of this decision is real and worth stating: some users have no smartphone, and for them TOTP on a desktop authenticator plus printed recovery codes is the path. That is a worse experience than a text message. It is not a worse experience than having a supplier's bank details changed by someone who called a phone shop.

## Passkeys

WebAuthn Level 3, planned as the primary factor with TOTP as the fallback; the schema (`authenticators.credential_id`, `public_key`, `sign_count`) is in place and `sign_count` already carries the monotonic-counter invariant that both factor types share.

**Not yet implemented**, and honestly so: WebAuthn attestation parsing and COSE key handling are not something to write by hand in the authentication path, and the library is not installable in this environment (the package registry route the build uses is blocked — see the Composer note in `docs/operations/`). The gap is recorded as open in PF-030 rather than ticked.
