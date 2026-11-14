# ADR-0010 — No CI workflow during the specification phase

- **Status:** Accepted
- **Date:** 2026-11-14
- **Tasks:** PF-013

## Context

The repository currently contains a specification and a backlog. There is no application code. The standing engineering instinct - and the right instinct once code exists - is to set up continuous integration first.

The project owner asked explicitly that no GitHub Actions workflow be created for now.

## Decision

**Nothing is installed in `.github/workflows/`.** Nothing runs on push.

The complete pipeline is authored and committed as **`docs/operations/ci-pipeline.yml.example`** - valid GitHub Actions YAML, schema-checked, so enabling it is a file move rather than a rewrite. The enablement runbook, the required secrets and the expected first-run failures are documented in [`../../operations/ci-cd.md`](../../operations/ci-cd.md).

Enabling it is tracked as `PF-013`, so it is **a decision with a name on it rather than an oversight**.

## Alternatives considered

**Install the full pipeline now.** The usual default. It loses on three counts at this point in the project: there is no code to lint, analyse or test, so every job is a no-op that still consumes minutes; a pipeline that passes trivially trains the team to ignore it, which is worse than no pipeline; and the feature-test job needs a PostgreSQL service, a Redis service and secrets that do not exist yet, so it would be red from the first commit for reasons unrelated to quality.

**Install a reduced pipeline - markdown lint and link checking only.** Genuinely defensible, and the closest alternative. Rejected because the owner's instruction was explicit and unconditional, and because a partial pipeline named `ci.yml` creates the impression that CI is covering the repository when it is checking prose. The link checking is instead available as a local command.

**Keep the pipeline only in someone's notes.** Rejected: the pipeline's *order* encodes real decisions - the asset build must run before the PHP tests because the translation-coverage and template-markup tests read compiled output; the feature job needs a real PostgreSQL because deferrable constraint triggers and RLS are the thing under test; the deploy must gate on the suite. Those decisions should be written down while they are fresh, in the form they will eventually run in.

## Consequences

**Easy:** no wasted minutes, no red badge that means nothing, and no false confidence; the pipeline's design is reviewed as a document now, which is a better review than it would get as a config file later.

**Hard — and the mitigation matters:** nothing automatic enforces Pint, PHPStan, Deptrac or the test suite until `PF-013` is done. [`CONTRIBUTING.md`](../../../CONTRIBUTING.md) lists the exact local commands, and `composer setup` installs a commit hook. This is an explicitly accepted gap, not an unnoticed one.

**The specific risk being accepted** is the one a sibling project demonstrated: its deployment workflow uploaded on every push to `main` with **no test job in front of it**, while its suite contained guards against defects that render perfectly and pass every other check - a missing translation key, a `<label>` naming nothing, a write route with no permission check, an N+1, a `<main>` that cannot take focus. **A guard nobody runs is not a guard.** That sentence is in the README for this reason, and `PF-013`'s acceptance criteria require that when CI is enabled, any deploy gates on the suite.

## Revisit when

The first application code is merged. At that point `PF-013` should be done in the same pull request or the one immediately after - the window between "there is code" and "CI runs" should be measured in hours, not sprints.
