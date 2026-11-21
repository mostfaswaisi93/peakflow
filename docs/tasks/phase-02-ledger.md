# Phase 02 — The general ledger

**22 tasks · 104 points**

This is the phase that decides whether PeakFlow is a financial system or a database of documents. Everything after it posts into what is built here.

The organising idea is that **the ledger's invariants belong to the database**. An application can be bypassed by a console command, a migration, a future developer's REPL session or a bug in an ORM upgrade. A deferred constraint trigger cannot. Every rule in this phase that can be expressed in PostgreSQL is expressed in PostgreSQL, and the application layer checks it too only so that the error message is useful.

---

Every task in this phase is finished. They are in
[done/phase-02-ledger.md](done/phase-02-ledger.md).
