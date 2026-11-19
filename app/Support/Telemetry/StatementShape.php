<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

/**
 * A statement's SHAPE, with every value gone - PF-240.
 *
 * ## Why a database span cannot carry the statement as written
 *
 * Two reasons, and the second is the one people forget.
 *
 * A bound value in this product is an amount, an IBAN, a tax identifier or a
 * counterparty's legal name. A trace is a log with a nicer viewer, it is
 * retained for weeks on a third party's infrastructure, and it is read by
 * engineers rather than by auditors - so a bound value in a span is customer
 * data in a place with none of the controls customer data needs.
 *
 * The second reason is that a span name or attribute carrying a literal is
 * HIGH CARDINALITY. `where id = '01M7FW…'` is a different string for every
 * row ever read, so a viewer grouping by statement has one group per query
 * and the one thing a trace is for - "which statement is slow" - cannot be
 * answered at all.
 *
 * ## It is a text transform, and that is deliberate
 *
 * Not a parse. A SQL parser that must handle PostgreSQL's dialect, including
 * dollar-quoted function bodies and the `::` casts this codebase uses
 * everywhere, is a dependency and a source of its own bugs - and the failure
 * mode of a parser that does not understand a statement is to pass it
 * through, which is the one outcome that must not happen.
 *
 * So the transform is subtractive: anything that could be a value is replaced,
 * and what cannot be recognised is replaced too. A shape that is more
 * redacted than necessary costs a diagnostic; a shape that is less redacted
 * than necessary is a leak.
 *
 * ## `IN (?, ?, ?)` collapses
 *
 * Because `whereIn()` with 3 ids and the same query with 300 are the same
 * statement and would otherwise be 298 separate shapes.
 */
final class StatementShape
{
    /**
     * How long a shape may be.
     *
     * A truncated shape is still groupable - the discriminating part of a
     * statement is its head - and an untruncated one puts a 40KB generated
     * query into every span of a report run.
     */
    private const MAX = 300;

    public static function of(string $sql): string
    {
        $shape = $sql;

        // Comments first: a value can hide in one, and this codebase writes
        // long block comments inside its SQL.
        $shape = (string) preg_replace('~/\*.*?\*/~s', ' ', $shape);
        $shape = (string) preg_replace('/--[^\n]*/', ' ', $shape);

        /*
         | Dollar-quoted bodies, before anything else touches them.
         |
         | `$$ ... $$` and `$tag$ ... $tag$` are how PostgreSQL carries a
         | function body, and the body contains quotes and semicolons that
         | would otherwise desynchronise every rule below it.
         */
        $shape = (string) preg_replace('/\$([A-Za-z_]*)\$.*?\$\1\$/s', '$$?$$', $shape);

        // Quoted strings - single quotes, with '' as the escape.
        $shape = (string) preg_replace("/'(?:[^']|'')*'/", '?', $shape);

        /*
         | Numbers, but not an identifier that merely contains digits.
         |
         | `journal_lines_2026_05` is a partition name and part of the shape;
         | `20.0000` is an amount and is not. The boundary condition is what
         | separates them: a number preceded by a letter, a digit or an
         | underscore is part of a name.
         */
        $shape = (string) preg_replace('/(?<![A-Za-z0-9_$])-?\d+(?:\.\d+)?(?![A-Za-z0-9_])/', '?', $shape);

        // Whitespace, so two spellings of one statement are one shape.
        $shape = trim((string) preg_replace('/\s+/', ' ', $shape));

        // IN (?, ?, ?) and VALUES (?, ?), (?, ?) collapse.
        $shape = (string) preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/i', '(?)', $shape);
        $shape = (string) preg_replace('/(\(\?\))(?:\s*,\s*\(\?\))+/', '$1', $shape);

        return mb_strlen($shape) > self::MAX ? mb_substr($shape, 0, self::MAX).'…' : $shape;
    }

    /**
     * The operation, for the span's name.
     *
     * Low cardinality by construction: one of six words, or `other`. The name
     * is what a viewer groups by and `db.select` is the group somebody wants;
     * `db.select_from_journal_lines_where_entity_id` is a group of one.
     */
    public static function operation(string $sql): string
    {
        $head = ltrim((string) preg_replace('~/\*.*?\*/~s', ' ', $sql));

        foreach (['select', 'insert', 'update', 'delete', 'with', 'savepoint', 'release', 'rollback', 'set', 'create', 'alter', 'drop'] as $verb) {
            if (stripos($head, $verb) === 0) {
                return $verb;
            }
        }

        return 'other';
    }
}
