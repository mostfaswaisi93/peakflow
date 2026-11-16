<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * The sentence a database refusal is trying to say.
 *
 * ## Why a service translates one at all
 *
 * Structural rules live in the database here, so the message a user sees for
 * "this statement opens 500 away from the last one" is written in PL/pgSQL
 * and arrives wrapped in a `QueryException`. The wrapping is useless to the
 * person reading it and harmful to show: it carries the SQL, every bound
 * value, the host, the port and the database name.
 *
 * ## What the first three copies of this got wrong
 *
 * They stopped at `(SQL:` and kept everything before it - which includes
 * PostgreSQL's `CONTEXT:` block:
 *
 *     ... is how a cash figure comes to include a receivable.
 *     CONTEXT:  PL/pgSQL function peakflow_bank_account_gl_link_is_fixed()
 *     line 22 at RAISE
 *
 * So a refusal shown to a user named an internal function and a line number
 * in it. That is noise to them, a map of the schema to anybody else, and it
 * moves whenever the function is edited - which also makes it impossible for
 * a check to assert what a refusal says.
 *
 * ## And why it is one function rather than three
 *
 * Because it was three, in three services, each with the same bug. The
 * second copy was written by reading the first.
 */
final class DatabaseRefusal
{
    /**
     * The database's own sentence, with the plumbing removed.
     *
     * Everything from `ERROR:` up to the first `CONTEXT:`, `DETAIL:`, `HINT:`
     * or `(SQL:`, with its whitespace collapsed so a message raised across
     * several PL/pgSQL string literals reads as one sentence.
     *
     * A message this cannot parse is returned unchanged: a refusal whose
     * wording is unexpected is still better than an empty string, and the
     * caller is about to put it in front of somebody.
     */
    public static function sentence(string $message): string
    {
        $pattern = '/ERROR:\s+(.+?)(?:\s*(?:CONTEXT|DETAIL|HINT|QUERY|STATEMENT):|\s+\(SQL:|$)/s';

        if (preg_match($pattern, $message, $matches) !== 1) {
            return trim($message);
        }

        return trim(preg_replace('/\s+/', ' ', $matches[1]) ?? $matches[1]);
    }
}
