# Phase 14 — Stock, serials and the operational edges

**11 tasks · 58 points**

Everything so far answers *what happened to the money*. This phase answers *what happened to the things* — and the two have to agree, because a stock figure that does not reconcile to the cost of sales is a stock figure nobody can close a year on.

It comes from a gap analysis against [hawalaty](https://github.com/mostfaswaisi93/hawalaty-laravel), which runs real businesses and therefore knows what an invoicing core alone cannot do. The tasks below are the parts of it that belong in a product whose subject is invoices and banks. The industry-specific parts are [Phase 15](phase-15-verticals.md), which is a different decision.

> **Why these are not optional extras.** An invoice line that sells a tracked item and does not move stock leaves two records of one event. The first symptom is a margin report nobody believes; the second is a stock count that writes off the difference every quarter and calls it shrinkage.

---

### PF-247 · Items that carry stock
`Inventory` · **5 pts** · depends: PF-073, PF-047 · standards: IAS 2 · `type:schema` `risk:high`

- [x] A catalog item declares whether it is **stocked**, and only a `good` can be - a stocked service would demand a movement for something that has no units
- [x] Quantity on hand is **derived from movements**, never a column somebody updates. There is no such column, and a check asserts there is none: a stored quantity is a second place the truth lives, and it drifts the first time a movement is reversed or a job retries
- [x] Cost method per item - weighted average or FIFO - declared, with the constraint written against NULL explicitly
- [x] Negative stock is **refused by default**, in the service and in a trigger that takes the sum with the item row locked - because two tills selling the last unit in the same second is a race the service cannot see. Permitting it requires a recorded reason
- [ ] Fractional quantities where the unit of measure permits them, with the precision declared on the unit rather than assumed - the column carries six places, and the per-unit precision rule lands with PF-249's axes
- [x] **Found while checking this task:** the cost-method constraint was `NOT stocked OR cost_method IN (...)`, which evaluates to NULL for a null method - and PostgreSQL accepts a check that evaluates to NULL. The one row it existed to refuse was the one row it let through. Every `OR x IN (...)` in a check constraint has that shape
- [x] **And:** stock is not master data. The first draft put it in `Catalog`, and the layer rules refused it - Catalog may not post, deliberately, because a price list has no business writing journal entries. A movement is a document, so it lives in its own `Inventory` module beside Sales and Purchasing
