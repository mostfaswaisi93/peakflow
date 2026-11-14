# Continuous integration and deployment

## ⚠️ No workflow is installed, deliberately

**There is nothing in `.github/workflows/`. Nothing runs on push.**

The complete pipeline is committed as **[`ci-pipeline.yml.example`](ci-pipeline.yml.example)** — valid GitHub Actions YAML, schema-checked, so enabling it is a file move rather than a rewrite.

This is a conscious choice for the specification phase, recorded as [`ADR-0010`](../architecture/decisions/ADR-0010-no-ci-during-specification.md) and tracked as `PF-013`, so **enabling it is a decision with a name on it rather than an oversight**. The reasoning: there is no code to lint, analyse or test; every job would be a no-op consuming minutes; and a pipeline that passes trivially trains the team to ignore it.

### The gap being accepted, and its mitigation

Nothing automatic enforces Pint, PHPStan, Deptrac or the test suite until `PF-013` is done. [`CONTRIBUTING.md`](../../CONTRIBUTING.md) lists the exact local commands, and `composer setup` installs a commit-message hook.

**The specific risk is one a sibling project demonstrated.** Its deployment workflow uploaded on every push to `main` with **no test job in front of it**, while its suite contained guards against defects that render perfectly and pass every other check — a missing translation key, a `<label>` naming nothing, a write route with no permission check, an N+1, a `<main>` that cannot take focus.

> **A guard nobody runs is not a guard.**

`PF-013`'s acceptance criteria therefore require that when CI is enabled, **any deploy gates on the suite**.

## Enabling it

```bash
mkdir -p .github/workflows
cp docs/operations/ci-pipeline.yml.example .github/workflows/ci.yml
git add .github/workflows/ci.yml && git commit -m "ci: enable the pipeline (PF-013)"
```

### Required secrets

| Secret | Used by | Note |
|--------|---------|------|
| `CODECOV_TOKEN` | Coverage upload | Optional |
| `SENTRY_AUTH_TOKEN` | Release and source maps | Optional until deploys exist |
| *(none for tests)* | — | **The test job needs no secrets.** `EXCHANGE_DRIVER=log` and `PDF_DRIVER=stub` are the committed defaults precisely so the suite runs against no third party |

### Expected first-run failures, and what each means

| Failure | Meaning |
|---------|---------|
| PHPStan: no files | Expected until there is code. Not a configuration error |
| Deptrac: no layers matched | Expected until the module directories exist |
| Pest: no tests | Expected. Exit code is 0 for an empty suite |
| `npm run build`: no entrypoint | Expected until `resources/css/app.css` exists |
| veraPDF not found | Expected until `PF-102`; the job is conditional on the PDF service |
| Infection: no mutations | Expected until `Ledger` has code |

None of these indicate a broken pipeline. They indicate a pipeline waiting for the code it will guard.

## The pipeline, and why the order is the point

```
┌─ lint & static ───────────────┐
│  pint --test                  │
│  phpstan (level max)          │
│  deptrac                      │
│  rector --dry-run             │
│  stylelint (no physical props)│
│  yamllint, markdownlint       │
└──────────────┬────────────────┘
               │
┌──────────────▼────────────────┐    ┌─ security (parallel) ─────────┐
│  build assets                 │    │  composer audit               │
│  npm ci && npm run build      │    │  npm audit                    │
└──────────────┬────────────────┘    │  CodeQL                       │
               │                     │  Trivy                        │
┌──────────────▼────────────────┐    │  CycloneDX SBOM               │
│  unit + arch  (no database)   │    └───────────────────────────────┘
└──────────────┬────────────────┘
               │
┌──────────────▼──────────────────────────────────────────┐
│  feature   services: postgres:18, redis:8               │
└──────────────┬──────────────────────────────────────────┘
               │
   ┌───────────┼───────────┬────────────┬─────────────────┐
   ▼           ▼           ▼            ▼                 ▼
concurrency  contract   golden-file  a11y + visual    performance
(parallel    (OpenAPI   (Schematron  (axe,            (query budgets,
 workers)     two-way)   + veraPDF)   screenshots)     EXPLAIN)
   └───────────┴───────────┴────────────┴─────────────────┘
               │
┌──────────────▼────────────────┐
│  mutation                     │
│  Ledger, Tax, Settlement      │
└──────────────┬────────────────┘
               │
┌──────────────▼────────────────┐
│  deploy  (gated on ALL above) │
└───────────────────────────────┘
```

Three ordering decisions that are not arbitrary:

1. **The asset build runs before the PHP tests.** The translation-coverage and template-markup tests read compiled output, so a build failure would otherwise ship a broken page through a green suite.
2. **A real PostgreSQL 18 service is mandatory.** The tenant schema uses deferrable constraint triggers, row-level security and declarative partitioning; it cannot run on SQLite, and a suite that silently skipped those tests would be worse than no suite.
3. **The deploy gates on everything.** See above.

## Jobs and their gates

| Job | Gate | Runtime target |
|-----|------|----------------|
| Lint and static | Pint clean, PHPStan level `max` with an **empty baseline**, Deptrac clean, Rector dry-run clean, stylelint clean | < 3 min |
| Build | Vite build succeeds; bundle-size budget | < 2 min |
| Unit + arch | All pass; **no database** | < 1 min |
| Feature | All pass against PostgreSQL 18 + Redis 8 | < 10 min |
| Concurrency | Gapless numbering, release, idempotency, recurring, import, allocation | < 5 min |
| Contract | OpenAPI two-way: no undocumented route, no undelivered operation | < 2 min |
| Golden file | Official corpora byte-compared; full Schematron chain; **veraPDF verifies PDF/A** | < 5 min |
| Accessibility | Contrast matrix, axe on every route, template rules, browser keyboard tests | < 5 min |
| Visual | LTR/RTL × light/dark × three densities, plus print PDFs | < 8 min |
| Performance | Query budgets, `EXPLAIN` partition pruning, statement time budgets | < 5 min |
| Mutation | Minimum MSI on `Ledger`, `Tax`, `Settlement` | < 15 min, nightly on `main` |
| Security | `composer audit`, npm audit, CodeQL, Trivy, SBOM | < 5 min |

## Branch protection, when CI is enabled

- `main` requires a pull request; no direct pushes.
- Required checks: lint and static, build, unit + arch, feature, contract, security.
- Required on a `risk:high` label: concurrency, golden file, accessibility — and **two reviewers, one of whom did not write it**.
- Linear history; squash merge; the branch must be up to date.
- `CODEOWNERS` on `app/Tenant/Ledger`, `app/Tenant/Tax`, `app/Tenant/Settlement` and `database/migrations/tenant`.

## Deployment

### Zero-downtime migration — the expand-contract playbook

Every migration declares whether it is **online-safe**, and the pre-flight check from `PF-023` enforces the declaration.

| Refused in an online migration | Because |
|--------------------------------|---------|
| A non-nullable column with no default | Rewrites and locks the table |
| `ALTER TYPE` on a large column | Full table rewrite |
| An `ACCESS EXCLUSIVE` lock on a partitioned table | Blocks every posting across every partition |
| An unbatched backfill | One transaction over millions of rows |
| `CREATE INDEX` without `CONCURRENTLY` | Blocks writes for the duration |

```
release n     add the new column, nullable, with a default in the application
release n     backfill in batches, resumable, rate-limited
release n+1   switch reads to the new column, behind a feature flag
release n+2   switch writes; the old column is still maintained
release n+3   stop maintaining the old column
release n+4   drop it
```

Four releases to rename a column is not bureaucracy — it is the only sequence in which no deployed version ever sees a schema it was not written for.

### Tenant migration rollout

Batched, bounded concurrency, **per-tenant failure isolation** — one tenant's broken migration must not stop the other nine hundred. Each tenant carries a schema-version row, so a request can detect a tenant that is behind and respond rather than fail. A tenant mid-migration returns `503` with `Retry-After`, and the operator console shows progress per tenant.

### Feature flags

**Anything that changes existing behaviour ships behind a flag**, with a documented default and **a removal task**, so flags do not accumulate into a configuration matrix nobody can reason about.

### Rollback

Per release, **tested in staging**, including the hard case: the schema moved forward and the code must go back. Expand-contract is what makes that possible — a release that only ever adds is a release that can be reverted.
