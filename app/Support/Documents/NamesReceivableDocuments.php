<?php

declare(strict_types=1);

namespace App\Support\Documents;

/**
 * Which document types leave a customer owing money, from any module.
 *
 * ## Why this interface is in Support
 *
 * The answer is `Sales\Enums\DocumentType`'s, which is right: what a
 * document type means, and whether a prepayment or a corrected invoice
 * creates a receivable, is the sales module's business.
 *
 * But `Banking` may depend only on `Support`, `Tenancy`, `Ledger` and
 * `Parties` - deliberately, so the matching engine cannot learn what an
 * invoice is - and it still has a legitimate need for this list.
 * `Matching\OpenDocuments` queries the `invoices` table directly, by raw
 * SQL, and has to bound the query to the types that can be owed.
 *
 * It bounded it with `IN ('380', '383', '386')`, a literal written before
 * the partial invoice and the corrected invoice existed. The consequence was
 * worse here than in the two reporting copies of the same list: a partial
 * invoice was not a matching CANDIDATE, so a customer's payment against one
 * had nothing to match and landed in the unexplained column of a bank
 * reconciliation - where somebody then hunted for a document the engine had
 * declined to offer them.
 *
 * ## Why an interface rather than a constant in Support
 *
 * A constant here would be a second definition, and the two would come
 * apart in the same way the three literals did. The enum decides and this
 * carries the decision across a wall the enum may not cross - the same
 * argument `TranslatesMoney` makes for a rate, and `LedgerCheck` makes for
 * an assertion about the ledger.
 */
interface NamesReceivableDocuments
{
    /**
     * The document type codes a customer can owe, for a SQL `IN` list.
     *
     * @return list<string>
     */
    public function receivableCodes(): array;
}
