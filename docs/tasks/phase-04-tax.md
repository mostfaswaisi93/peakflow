# Phase 04 — The tax engine

**14 tasks · 71 points**

Tax comes before sales, and that ordering is the most consequential sequencing decision in the whole plan. The tax point date, the rate version and the per-rate-group breakdown are **inside an issued document and frozen there**. Retrofitting them means migrating immutable rows that a customer has already filed a return on, which is to say it is not possible.

The engine's organising principle is that **tax is data, not code**. A rate that changes on a date is a row. A jurisdiction's rounding rule is a row. A place-of-supply decision is a rule evaluated from rows. Nothing about a specific country is compiled into the sales path.

---

Every task in this phase is finished. They are in
[done/phase-04-tax.md](done/phase-04-tax.md).
