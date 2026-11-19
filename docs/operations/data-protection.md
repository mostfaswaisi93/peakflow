# What personal data this product holds, why, and for how long

**PF-239.** One row per table that holds a personal-data shape. The table list
is **not maintained by hand**: `ArchitectureRules::everyPersonalTableIsRegistered()`
reads the live tenant schema for the column shapes below and fails if a table
holding one is missing from here, or if an entry names a table that no longer
exists.

That is the only way a register like this stays true. Written by hand it
describes the schema as it was on the day somebody wrote it, and the first
migration afterwards makes it a document that is believed and wrong.

## The shapes that make a table personal

`email`, `phone`, `mobile`, `iban`, `bic`, `legal_name`, `trade_name`,
`holder_legal_name`, `counterparty_name`, `actor_name`, `first_name`,
`last_name`, `full_name`, `address`, `street`, `city`, `postal_code`,
`tax_number`, `vat_number`, `registration_number`, `national_id`,
`ip_address`, `user_agent`, `date_of_birth`, `contact_name`,
`contact_email`, `account_number`, `last_four` - and a bare `name` on
`users`, `parties`, `party_contacts` and `operators`, where it is a person's
and not a file's.

A column shape is a weaker test than a human reading the schema, and it is
the one that can be enforced. It over-reports - `entities.legal_name` is the
customer's own company - and over-reporting costs a line in this file, while
under-reporting costs an unregistered category.

## The register

**Retention is the longest of the bases that apply, and statutory wins.** A
deletion request against an invoice does not delete the invoice: a tax
authority requiring seven years overrides it, and the product says so rather
than pretending. What erasure does to each category is the last column.

| Table | Category | Lawful basis | Retention | On an erasure request |
|---|---|---|---|---|
| `users` | Workspace account: name, email | Contract - the workspace is provided to this person | Life of the account, then 90 days | Account closed and the login removed. The **audit trail keeps the name**, because an action attributed to nobody is an audit trail that answers nothing. |
| `sessions` | IP address, user agent | Legitimate interest - detecting a stolen session | Session lifetime; rows pruned on expiry | Nothing to do. The data is gone when the session is. |
| `api_refresh_tokens` | A signed-in device: the name the person gave it, the IP address and user agent it signed in from (PF-260) | Legitimate interest - showing a person where they are signed in, and detecting a stolen refresh token | The device session's life, at most 90 days; spent and revoked rows are kept as evidence that a later presentation of the token was a copy | The person's sessions are closed and the device name, address and user agent cleared. The rows themselves stay, revoked: a spent token is what turns a later reuse into "this was stolen" rather than "unknown". |
| `audit_log` | Actor name, IP, user agent | Legal obligation and legitimate interest - it is the record of who did what to the books | **Not prunable.** The log is hash-chained and append-only; removing a record breaks every link after it | **Refused, and the reason given.** This is the clearest case where retention wins: the chain is the evidence that the accounts were not altered. |
| `parties` | Customer and supplier legal name, registration number | Legal obligation - an invoice must name its counterparty | Statutory invoice retention of the jurisdiction (7 years under ZATCA, 6-10 in the EU) | Refused for as long as a document naming them is retained. A party with no documents can be deleted. |
| `party_contacts` | Contact name, email, phone | Contract - sending the documents they are owed | As `parties`, because a contact appears on the documents | Contact details can be removed **once the party has no live documents**; the name on an issued invoice stays. |
| `party_addresses` | Street, city, postal code | Legal obligation - the place of supply decides the tax | As `parties` | Refused while any document whose tax decision used the address is retained. |
| `party_bank_accounts` | BIC, last four digits of the account | Legal obligation - executing and evidencing a payment | Statutory, as the payment | Refused while the payment is retained. The full account number is encrypted and never in the clear here. |
| `bank_accounts` | The tenant's own account: holder name, BIC | Contract | Life of the account, then statutory | Not a subject request: this is the tenant's own banking. |
| `bank_transactions`, `bank_transaction_details` | Counterparty name as the bank wrote it | Legal obligation - bookkeeping | Statutory | Refused. The statement is evidence and is reconciled against the ledger. |
| `entities` | The tenant's own company: legal name, registration number, address | Contract | Life of the tenancy, then statutory | Not a subject request. Personal only where the customer is a sole trader. |
| `messaging_attempts`, `messaging_consents`, `messaging_inbound`, `party_delivery_preferences` | Destination address - an email or a phone number | Consent for marketing; contract for a document somebody is owed | Consent: until withdrawn. Delivery evidence: statutory, because "we sent it" is part of the document's history | Consent is withdrawn immediately and recorded. The **delivery evidence stays**, because it is the proof the invoice was served. |
| `inbound_senders` | Address a document may arrive from | Legitimate interest - knowing who may send us documents | Life of the arrangement | Removed with the arrangement. |

## What this register is not

It is not a privacy notice. A notice is written for the person whose data it
is and lives on the marketing site; this is written for whoever has to answer
a request, and it names tables.

It is also not a claim that every row in these tables is personal data.
`entities.legal_name` is a company's, and a company has no privacy rights -
until the customer is a sole trader, when it is also a person's name. The
register is drawn at the level where the answer is the same for the whole
table, because an answer that depends on the row is an answer nobody can give
in the time a request allows.
