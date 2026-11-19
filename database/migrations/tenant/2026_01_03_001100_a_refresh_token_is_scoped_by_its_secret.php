<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Undoes 001000. `api_refresh_tokens` is not entity-scoped, and scoping it
 * broke signing in.
 *
 * ## What I got wrong, and how the suite said so
 *
 * The previous migration read the rule as "a table with `entity_id` is
 * entity-scoped" and applied the policy. Two checks failed within the
 * minute: signing in answered 500, because the insert happens before any
 * entity scope is established - there is no entity until the sign-in has
 * decided one - and the refresh checks failed with it.
 *
 * ## Why the column is not a scope key here
 *
 * `entity_id` on a refresh token records **which company this session is
 * currently in**, and a session moves. `DeviceSessions::refresh()` will
 * carry a device to another entity - on request, or when the person has
 * lost every role in the one they were in - and it keeps the same
 * `family_id` while doing it.
 *
 * So the family spans entities by design. With an entity policy,
 * `where('family_id', ...)` stops seeing the rows written on the other side
 * of a switch, and the guarantee that spending one token closes the WHOLE
 * session quietly stops holding: the rows it cannot see, it cannot revoke.
 *
 * That is worse than the gap it was meant to close, and it is the kind of
 * thing that would have looked fine - the checks pass, the session seems to
 * work - until somebody switched company and their old tokens stayed live.
 *
 * ## What actually guards the row
 *
 * The secret. A refresh token is reachable only by presenting its own value,
 * which is stored hashed and compared on lookup; the row carries no reader
 * other than the holder of the token. `api_refresh_tokens_are_never_rewritten`
 * keeps it from being edited, and the rotation closes a family on reuse.
 *
 * The opt-out is a comment on the table rather than a list in the check, so
 * the reason travels with it and shows up in `\dt+` for whoever is holding
 * the incident.
 *
 * @online-safe Disables a policy and sets a comment. Both are catalog
 *              changes; no row is read or rewritten. It widens what the
 *              application can see on one table back to what it saw before
 *              001000 - which is the state every other release has run in.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE api_refresh_tokens NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE api_refresh_tokens DISABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS api_refresh_tokens_entity_scope ON api_refresh_tokens;

            COMMENT ON TABLE api_refresh_tokens IS
                'peakflow:tenant-scoped - a refresh token belongs to a user and a device, not to a company. entity_id records which company the session is currently in, and DeviceSessions::refresh() moves a device between companies while keeping the family - so an entity policy would hide the rows written on the other side of a switch, and spending one token would stop closing the whole session. What guards the row is its own secret, stored hashed and presented on every use.';
        SQL);
    }

    public function down(): void
    {
        /*
         | Deliberately not a revert.
         |
         | Putting the policy back restores a state in which signing in
         | answers 500 and a session that changed company keeps live tokens
         | nobody can revoke. `down()` undoes a change that turned out to be
         | wrong; this one is the correction.
         */
    }
};
