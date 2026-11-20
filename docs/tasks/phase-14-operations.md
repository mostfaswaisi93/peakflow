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
- [x] Fractional quantities where the unit of measure permits them, with the precision declared on the unit rather than assumed - the column carries six places, and the per-unit precision rule lands with PF-249's axes
  - `units.tsv` gained a `decimals` column, per unit. **The column is headroom; the unit is permission** - six places is what the database can store and says nothing about how an item is measured. "2.5" is a fine quantity of kilograms and a nonsense quantity of pairs, and nothing about the number itself says which.
  - **Per unit and not per dimension**, because the dimension is the wrong question: a minute and an hour are both time, and a quarter of an hour is an ordinary invoice line while half a minute is not a thing anybody bills. Counting units are 0 - there is no half of a pair.
  - Enforced in `Stock::write()`, which is the one place a quantity becomes a row. Putting it on `receive`, `issue`, `adjust` and `returnIn` would be four copies, and the fifth movement type somebody adds would be the one without it.
  - The consequence of accepting 2.5 EA is not a rounding error: **whatever the shelf holds, it is not two and a half of anything**, so the count that eventually runs can never reconcile and the difference is attributed to shrinkage by somebody who was not here when the line was typed.
  - The check has two legs and the second is the one that matters: 2.5 KGM must still be **accepted**. A rule that refuses every fraction meets half the criterion and is a product that cannot invoice anything sold by weight. Its bite - replacing the unit rule with "refuse any decimal point" - breaks other stock checks immediately, which is the better kind of evidence.
  - A unit declaring more than six places is refused at load time, where somebody is editing the file, rather than at the first invoice that uses it.
  - Editing the file tripped `bin/verify iso`'s digest check, which is that check doing its job: the new digest is recorded with a line saying what changed and that no code, name or dimension was touched.
- [x] **Found while checking this task:** the cost-method constraint was `NOT stocked OR cost_method IN (...)`, which evaluates to NULL for a null method - and PostgreSQL accepts a check that evaluates to NULL. The one row it existed to refuse was the one row it let through. Every `OR x IN (...)` in a check constraint has that shape
- [x] **And:** stock is not master data. The first draft put it in `Catalog`, and the layer rules refused it - Catalog may not post, deliberately, because a price list has no business writing journal entries. A movement is a document, so it lives in its own `Inventory` module beside Sales and Purchasing
