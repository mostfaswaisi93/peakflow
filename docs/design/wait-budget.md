# The wait budget

> **A skeleton is for a wait somebody notices.** Below that, it is a flash
> that makes a fast product look busy — the same failure as a loader on
> every click, in a different place.

## The threshold

**200 milliseconds**, measured as the median of five loads of the real page
in a real browser.

It is the figure below which a response reads as immediate rather than as
fast. A placeholder shown for 120ms is seen as a flicker rather than as
information: the eye registers that something changed and has no time to
read what, so the page arrives feeling less settled than if nothing had
been drawn at all.

The measurement is the **median**, not the best or the worst. The best is
a warm cache answering a question nobody asked; the worst on a development
machine is usually the first compile of a view.

## What is measured, and when

`bin/verify viewport` drives the application in a browser. The figures below
were taken against the `showcase` workspace — a year of trading, 79
invoices, 60 bills, 97 bank lines — which is a demonstrable company rather
than an empty one, and small compared with a real tenant.

| Screen | Median | Slowest of five | Over the threshold |
|---|---|---|---|
| `/overview` | 171ms | 206ms | no |
| `/banking/accounts` | 176ms | 192ms | no |
| `/sales/invoices` | 127ms | 153ms | no |
| `/purchasing/bills` | 123ms | 133ms | no |
| `/ledger/financial-position` | 117ms | 133ms | no |
| `/settlement/statements` | 102ms | 109ms | no |
| `/ledger/profit-or-loss` | 100ms | 105ms | no |
| `/ledger/accounts` | 97ms | 448ms | no |
| `/ledger/trial-balance` | 96ms | 156ms | no |
| `/masters/currencies` | 92ms | 104ms | no |
| `/masters/items` | 91ms | 114ms | no |
| `/masters/parties` | 84ms | 91ms | no |
| `/settlement/payment-runs` | 84ms | 94ms | no |

## So no screen uses one

Every screen is under the threshold, so the skeleton component exists and
is used nowhere. `SkeletonRules` enforces that: a template that uses
`<x-skeleton>` and is not listed here above the threshold is refused.

That is the criterion doing its job rather than the criterion being dodged.
The alternative — putting a placeholder on a page that arrives in 127ms
because placeholders are a thing products have — is how a product acquires
the feeling of being slower than it is.

## Why the numbers are this shape

**There is no asynchronous loading in the product.** Not one `fetch` or
`XMLHttpRequest`: every screen is rendered by the server and arrives whole.
So there is no moment where a page exists and its content does not, which
is the moment a skeleton is for.

The wait that *does* exist is between pages, and that is the loader's
(PF-279) — which is why the loader waits 400ms before it shows, for the
same reason this threshold is 200ms.

## When this changes

A skeleton earns its place the first time one of these is true, and the
table above is where the evidence goes:

- A screen's median crosses 200ms **on real tenant data** rather than on
  the showcase. The showcase is deliberately small; a tenant with 50,000
  invoices is the case this table does not yet cover.
- A screen starts loading part of itself after the rest — the first
  `fetch` in the product is the first real skeleton.
- A long operation gets its own screen: an import, an export, a close.
  Those already have a determinate loader available, which is the honest
  shape when the end is known.

Re-measure with `bin/verify viewport` and put the figure in the table. A
number in a document nobody re-took is a number that was true once.
