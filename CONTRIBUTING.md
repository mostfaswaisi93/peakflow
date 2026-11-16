# Contributing to PeakFlow

PeakFlow is a financial system. The cost of a defect here is not a bad user experience - it is a number someone files a tax return on. These conventions exist to make that cost less likely, and most of them are enforced rather than requested.

## Before you write code

1. **Find the task.** Work is tracked as `PF-###` in [`docs/tasks/`](docs/tasks/). If what you are doing has no task, write one first - not as bureaucracy, but because a task whose acceptance criteria cannot be written is a task whose scope is not understood.
2. **Check the Definition of Ready** in [`docs/tasks/README.md`](docs/tasks/README.md). In particular: if the work touches money, the rounding behaviour must be specified before it is implemented; if it touches the ledger, the posting rule must be written out as debits and credits.
3. **Read the standard, do not recall it.** Where a task names a standard, quote the clause you are implementing in the pull request. "EN 16931 requires this" is not reviewable; `BR-CO-14` with its text is.

## Branches and commits

- Branch from `main` as `pf-###-short-slug`.
- **Conventional Commits**, enforced by a hook installed by `composer setup`:

  ```
  feat(ledger): enforce the balanced-entry invariant as a deferred constraint

  Refs PF-043
  ```

- Types in use: `feat`, `fix`, `refactor`, `perf`, `test`, `docs`, `build`, `ci`, `chore`, `revert`. The scope is the module.
- A commit that changes behaviour references its task. A commit that changes the schema says so in the subject.
- **Never rewrite history on a shared branch.**

## Pull requests

A pull request is expected to carry all of the following. The template asks for them; a reviewer will ask for any that is missing.

- The task id, and a one-paragraph statement of what changed and why.
- **For a schema change:** the migration, the [`docs/domain/schema.md`](docs/domain/schema.md) update **in the same pull request**, and a statement of whether the migration is safe to run while serving traffic.
- **For a posting change:** the debits and credits, written out, plus the [`docs/domain/general-ledger.md`](docs/domain/general-ledger.md) update.
- **For a compliance change:** the clause being implemented, quoted, and the golden-file fixtures that prove it.
- **For a decision that was not obvious:** an ADR in [`docs/architecture/decisions/`](docs/architecture/decisions/), including the alternatives and why they lost. An ADR is cheap now and irreplaceable in two years.
- **For new interactive UI:** a note confirming you operated it once with the keyboard alone, and what happened.

## What the pipeline will refuse

Run these before you push. They are the same checks the pipeline runs, and running them locally is faster than discovering them in review:

```bash
./vendor/bin/pint --test          # formatting
./vendor/bin/phpstan analyse      # level max, empty baseline
./vendor/bin/deptrac analyse      # module boundaries
npm run build                     # must run before the PHP tests
php artisan test                  # unit, arch, feature
```

> **Run the full Pint pass, not `--dirty`.** `--dirty` only inspects files you changed, which is convenient locally and hides drift.

### An ordered relation is `reorder()`ed, never given a second order

Seventeen relations here impose their own order on purpose — a document's lines come back in line order, a fiscal year's periods in ordinal order — so the caller does not have to remember. The trap is the caller who wants the other end:

```php
$account->statements()->orderByDesc('sequence')->first()   // WRONG
```

compiles to `ORDER BY sequence ASC, sequence DESC`. The first clause wins, so `first()` returns the **oldest** row while reading as though it asked for the newest. Nothing errors, nothing logs, and the value is of the right type and shape.

It was found in PF-146's reconciliation: the service asked for the latest statement, got the first one, and every reconciliation after the second import measured against the account's opening statement for ever. The figures were plausible, internally consistent and wrong.

Use `reorder('sequence', 'desc')`, or query the model directly — which is what the fix did, because a `reorder()` is one word away from being forgotten by the next caller. `ArchitectureRules::orderedRelationsAreReordered()` enforces this and `@arch-allow` opts a line out.

A **redundant** order — the same column, the same direction as the relation's — is also refused. It is harmless today and is exactly the line somebody later edits into a contradictory one.

### A PHPStan baseline entry needs a reviewer and a task

There is no `baseline.neon` and `phpstan.neon` deliberately includes none, so
generating one is a visible change to a tracked file rather than a side effect of
a command. If a finding genuinely cannot be fixed now:

1. **A reviewer approves the entry**, by name, in the pull request. Not the
   author - the point is that somebody else agreed the debt is worth taking.
2. **The entry carries a `PF-###`** for the task that removes it. A baseline
   line with no task is a line nobody will ever delete, because nobody can tell
   whether it is still needed.
3. **The level stays at `max`.** Lowering the level to pass is the same decision
   as a baseline entry, taken silently and applied to the whole codebase.

A baseline is a record of debt, not a way to pass. The failure mode is not that
it hides one problem: it is that the file grows, nobody reads it, and the
analyser's output stops meaning anything.

> **Never run `npm run build` straight after `php artisan view:clear`.** The stylesheet scans compiled Blade via `@source`. With that directory emptied, the build succeeds, says nothing, and ships a stylesheet missing every utility that only appears in compiled templates. Run `php artisan view:cache` first, or build before clearing.

Hard refusals, each from an architecture test rather than from a reviewer's attention:

| Refusal | Why |
|---------|-----|
| A `float` or `double` anywhere in the money path | IEEE 754 cannot represent `0.1`; the drift is small, non-deterministic and unexplainable to an auditor |
| A new baseline entry in PHPStan | A baseline is a record of debt, not a way to pass |
| A financial table without `entity_id` | Cross-company data leakage inside one tenant |
| A financial table without a row-level security policy | The application scope is not the only line of defence |
| A write from `app/Tenant/Reporting` | A report is a query over the journal, never a second source of truth |
| A physical CSS property (`left`, `margin-right`, …) | One of them is an RTL bug waiting for a customer to find it |
| A `__()` key missing from either catalog | A missing key does not throw - it silently prints the source string |
| A `<label>` that names no control | It renders perfectly and is noticed only by someone using a screen reader |
| A module reaching into another module's models | Deptrac compiles the boundary table; bypassing it dissolves the architecture |

## Testing expectations

The full reasoning is in [`docs/operations/testing-strategy.md`](docs/operations/testing-strategy.md). The short version:

- **Anything in `Ledger`, `Tax` or `Settlement`** must pass the mutation-score gate. A money test suite that still passes against mutated arithmetic is not testing the arithmetic.
- **Anything that posts** ends its feature test with `ledger:verify`. It is wired into the test case, so you get it for free - and if your change breaks a global invariant you will find out immediately rather than in a report three months later.
- **Anything concurrent** - numbering, payment release, scheduled runs, idempotency - needs a test in the `concurrency` suite with real parallel workers. A single-process test of a race proves nothing.
- **Anything serialized to a standard** needs a golden file from the official corpus, byte-compared, run through the full validation chain.
- **The tenant suite needs a real PostgreSQL.** It cannot run on SQLite, because deferrable constraint triggers, row-level security, partitioning and `numeric` semantics are the thing under test. If 600 tests fail identically with a connection error, start PostgreSQL before reading anything into it.

## Reviewing

- Two reviewers for anything labelled `risk:high`. One of them should not have been involved in writing it.
- Review the **test**, not only the change. A test that asserts the code does what it does is worse than no test, because it makes the next person think the behaviour is intentional.
- For a money change, re-derive one worked example by hand. If you cannot, the change is not reviewable yet and the right comment says so.
- Ask what happens when the input is zero, negative, in a three-decimal currency, in a closed period, and submitted twice.

## Security

Do not open a public issue for a vulnerability. See [`SECURITY.md`](SECURITY.md).
