<!--
  PeakFlow is a financial system. The cost of a defect here is not a bad user
  experience - it is a number someone files a tax return on. Fill in the
  sections that apply and delete the ones that do not.
-->

## What and why

Closes `PF-###`

<!-- One paragraph: what changed, and the reason it needed to change. -->

## Schema change

<!-- Delete this section if the PR touches no migration. -->

- [ ] `docs/domain/schema.md` is updated **in this pull request**
- [ ] Every new financial table has a non-nullable `entity_id` and a row-level security policy
- [ ] The migration declares whether it is **safe to run while serving traffic**, and the declaration is true
- [ ] No non-nullable column without a default, no `ALTER TYPE` on a large column, no `ACCESS EXCLUSIVE` lock on a partitioned table, no unbatched backfill, no `CREATE INDEX` without `CONCURRENTLY`
- [ ] Indexes match the queries that will actually run — composite `(filter, sort)` where both are needed

## Posting change

<!-- Delete this section if the PR adds or changes no posting rule. -->

`docs/domain/general-ledger.md` is updated, and the rule is:

| Dr/Cr | Account | Amount |
|-------|---------|--------|
|       |         |        |

- [ ] The rule's output is balanced in the entity's **functional** currency
- [ ] It does not post to a control account outside a registered posting rule
- [ ] `ledger:verify` passes at the end of every test that exercises it

## Compliance change

<!-- Delete this section if the PR implements no published standard. -->

Standard and clause:

> <!-- Quote the clause. "EN 16931 requires this" is not reviewable; `BR-CO-14` with its text is. -->

- [ ] Golden-file fixtures from the official corpus, byte-compared
- [ ] A negative fixture proving the rule fails **for the right reason**
- [ ] `docs/standards/` mapping updated

## Money change

<!-- Delete this section if the PR touches no money value. -->

- [ ] No `float`, no `(float)` cast, no cast to `float` in a model
- [ ] Every arithmetic operation passes an explicit `RoundingPolicy`
- [ ] The rounding behaviour is **specified**, not inferred — rounded once, where the value becomes a legal amount
- [ ] Tested against a three-decimal currency (`JOD`) **and** a zero-exponent currency (`JPY`)
- [ ] Mutation score gate passes for the touched module

## Tests

- [ ] The layers the [testing strategy](docs/operations/testing-strategy.md) prescribes for this kind of change
- [ ] Anything concurrent has a test in the `concurrency` suite with **real parallel workers**
- [ ] A test fails without the change, and passes with it

**The failure mode this guards against:**

<!-- What goes wrong in production if this is subtly wrong, and which test layer catches it. -->

## Accessibility

<!-- Delete this section if the PR adds no interactive UI. -->

- [ ] I operated this **once with the keyboard alone**, and it worked
- [ ] Every control has a real `<label>`; no `id` is duplicated per render
- [ ] No state conveyed by colour alone
- [ ] Contrast matrix passes; focus is visible and not obscured
- [ ] Any drag has a keyboard equivalent

## Localization

- [ ] Both `ar` and `en` catalogs complete; key sets identical
- [ ] No translated label concatenated with a value — Arabic adjectives agree in gender
- [ ] Logical CSS properties only; money, IBANs and identifiers bidi-isolated

## Local checks

```
./vendor/bin/pint --test      [ ]
./vendor/bin/phpstan analyse  [ ]   (level max, no new baseline entry)
./vendor/bin/deptrac analyse  [ ]
npm run build                 [ ]   (BEFORE the PHP tests)
php artisan test              [ ]
```

## Decision record

- [ ] An ADR exists for any decision that was not obvious, with the alternatives and why they lost
- [ ] Or: no such decision was made in this PR
