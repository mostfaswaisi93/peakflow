<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Parsers;

use App\Tenant\Banking\Exceptions\StatementUnreadable;

/**
 * A reader for one statement format - PF-150 to PF-154.
 *
 * ## One file can hold several statements
 *
 * A CAMT.053 document may carry a `Stmt` per account or per period, and an
 * OFX file routinely carries several `STMTRS` blocks. So a parse returns a
 * list, and the caller picks the one for the account it is importing into -
 * rather than the parser guessing, which is how a file silently loses the
 * statements after the first.
 */
interface StatementParser
{
    /** The `banking.formats` key this reader produces. */
    public function format(): string;

    /**
     * Whether this reader recognises the content, cheaply.
     *
     * Used to pick a reader when the caller did not name one. It looks at
     * the shape rather than the file extension, because a bank that serves
     * CAMT.053 as `statement.txt` is ordinary and an extension is whatever
     * the browser put there.
     */
    public function recognises(string $content): bool;

    /**
     * @return list<ParsedStatement>
     *
     * @throws StatementUnreadable
     */
    public function parse(string $content): array;
}
