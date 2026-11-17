# Phase 15 — The industry verticals

**8 tasks · 47 points**

> **Read this first: these tasks change what the product is.**
>
> Phases 00–14 build an accounting and invoicing platform. The tasks below make it a **restaurant system, a pharmacy system, a fuel-station system and a workshop system**, which is a different product with different competitors, a different sales motion and a different support burden.
>
> They come from a gap analysis against [hawalaty](https://github.com/mostfaswaisi93/hawalaty-laravel), which carries all four. That is the right answer for a product sold to those businesses; it is recorded here as a **decision to take deliberately** rather than a gap to close by default, because a platform that is adequate at four verticals loses to the product that is excellent at one.
>
> Each task below therefore opens with what it commits the product to. Nothing here is started until that is accepted.

---

### PF-258 · Menu, recipes and what a dish costs
`Catalog` · **8 pts** · depends: PF-247, PF-248 · standards: — · `risk:high`

**Commits to:** food service. Menus, kitchens, and a cost of sales that moves with ingredient prices.

- [ ] A menu item is composed of ingredients with quantities, and selling it issues **the ingredients** rather than the dish - a restaurant's stock is flour, not cake
- [ ] A recipe yields a quantity, so a batch of sauce costs once and portions out many times; the cost per portion is derived and never typed
- [ ] A sub-recipe is an ingredient, and a cycle is refused - sauce in a dish that is in the sauce is an infinite cost
- [ ] The cost of a dish is recomputed as ingredient costs move, and the **margin report says when it last changed**, because a menu priced against last year's cheese is a menu losing money quietly
- [ ] Modifiers (extra shot, no onions) carry their own price and their own stock effect, since "no onions" costs nothing and "extra shot" costs an ounce

### PF-259 · Allergens, and the answer that has to be right
`Catalog` · **5 pts** · depends: PF-258 · standards: EU FIC 1169/2011, SFDA labelling · `risk:high`

**Commits to:** regulated food labelling. Getting this wrong hurts somebody.

- [ ] Allergens are declared per ingredient and **derived up through recipes**, because a cook who declares the dish misses the allergen in the stock cube
- [ ] An ingredient with **no allergen declaration** is reported as unknown, never as free from them - absence of data is not absence of allergen, and the default has to fail loudly
- [ ] The customer-facing answer carries the regulatory wording of its jurisdiction, not a free-text note somebody typed
- [ ] A recipe change that introduces an allergen is flagged on the change rather than discovered on a label

### PF-260 · Pharmacy
`Catalog` · **8 pts** · depends: PF-250, PF-251 · standards: SFDA, GDP · `risk:high` `type:security`

**Commits to:** a regulated dispensary. Controlled substances, prescriptions and an inspector.

- [ ] Controlled substances are flagged, and dispensing one records the prescription, the prescriber and the patient - with the register reconciling to stock, because that register is what an inspector reads
- [ ] Batch and expiry are **mandatory**, not optional, for anything dispensed; PF-250's general case is a requirement here
- [ ] A recall is a query that returns the patients, not just the stock: the point of batch tracking in a pharmacy is reaching the people who have the medicine
- [ ] Patient data is minimised, access-controlled at the row and audited on read - the same decision bank details carry, for the same reason
- [ ] Substitution rules (generic for brand) are data with their own permission, and a substitution is recorded as one

### PF-261 · Fuel
`Catalog` · **5 pts** · depends: PF-248, PF-253 · standards: — · `risk:high`

**Commits to:** wet stock. Pumps, tanks and evaporation.

- [ ] Pump readings are cumulative and **monotonic**: a reading lower than the last is a meter reset or a mistake, and both need a person rather than a subtraction
- [ ] Tank dips reconcile against pump sales, with the variance reported as a **percentage of throughput** - a fixed tolerance in litres is meaningless across a tank and a jerrycan
- [ ] Temperature-corrected volumes where the jurisdiction requires them, because the litres sold and the litres delivered are measured at different temperatures and the difference is not loss
- [ ] A variance beyond tolerance blocks the shift close until somebody records what it was, since the commonest cause is a leak and the second is theft

### PF-262 · Vehicle fitment
`Catalog` · **5 pts** · depends: PF-249 · standards: — · —

**Commits to:** parts retail. A catalogue keyed on what a part fits, not on what it is.

- [ ] A part fits a set of vehicles by make, model, year range and engine, and the **search is by vehicle**, because that is the question a customer asks
- [ ] Fitment data is versioned and sourced: a wrong fitment sells a part that does not fit, and the return is the cheap consequence
- [ ] An interchange (this part supersedes that one) is navigable in both directions
- [ ] A part with no fitment data is findable by code and **never offered as a fit**

### PF-263 · Scheduling, work orders and labour
`Sales` · **8 pts** · depends: PF-248, PF-096 · standards: — · `risk:high`

**Commits to:** service delivery. Appointments, technicians and time that has to be billed.

- [ ] An appointment holds a resource for a duration, and a double-booking is refused by the **database** - two tills booking the last slot is this phase's version of the stock race
- [ ] A work order carries labour and parts, and issuing the parts moves stock; the invoice is built from the order rather than retyped
- [ ] Labour is billed from recorded time, and unbilled time is **reported** - a workshop's loss is almost always hours nobody invoiced
- [ ] A technician's schedule, utilisation and recovery rate read as one page, because that is the number a service manager runs the business on
- [ ] A cancellation inside the notice window carries its fee as a recorded decision

### PF-264 · The point of sale
`Sales` · **5 pts** · depends: PF-255, PF-248 · standards: — · `risk:high`

**Commits to:** a till. Cash drawers, shifts and a queue of customers who will not wait.

- [ ] A sale completes in one screen with the keyboard, offline, and prints - and **nothing in that path may call the network**, because a till that waits for a server is a till with a queue
- [ ] A shift opens with a counted float and closes with a counted drawer, and the variance is per payment method
- [ ] Partial and mixed payments - cash, card, wallet, credit - on one sale, each posting to its own account
- [ ] A receipt is reprinted from what was sold rather than regenerated from today's prices
- [ ] A sale made offline reaches the ledger exactly once, which is PF-255's idempotency contract doing the work

### PF-265 · Platform licensing and packages
`Platform` · **3 pts** · depends: PF-024, PF-026 · standards: — · —

**Commits to:** selling the product itself - which hawalaty does and PeakFlow's subscription model does differently.

- [ ] A package declares its limits as **data** the platform enforces, not as prose in a contract
- [ ] A licence has a term, an expiry and a grace period, and expiry degrades to read-only rather than locking people out of their own records
- [ ] A reseller or partner may hold licences on behalf of tenants, with their own margin recorded
- [ ] Nothing about licensing may reach into a tenant's ledger, which is the boundary Phase 01 already draws
