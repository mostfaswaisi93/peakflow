<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tokens a machine authenticates with - PF-218.
 *
 * ## One table, two planes, and the plane is a column
 *
 * An operator of the platform and a user of a tenant both authenticate with a
 * bearer token, and both tokens are rows in a table called `api_tokens`. They
 * are not the same population and must never be interchangeable: an operator
 * acting on a tenant route would read a customer's figures with nothing in
 * that tenant's own audit log saying they had, which is the one thing the
 * operator console exists to prevent.
 *
 * The defence cannot be "they are in different databases", because that is
 * true until the morning somebody restores a dump into the wrong one, or a
 * provisioning bug points the tenant connection at the platform. So the plane
 * is written ON the row, the guard refuses a row from the other plane, and
 * `ApiContractRules` plants a platform-plane row in a tenant's own table and
 * proves the API refuses it.
 *
 * ## A token lists its entities, and that is why this is not entity-scoped
 *
 * Deliberately not marked as a financial table, and the reason is in the
 * shape: a token is scoped to a LIST of entities, so there is no single
 * `entity_id` to compare against `peakflow_current_entity()`. Row-level
 * security here would hide a token from every entity but one - including from
 * the request that is trying to authenticate with it, because the entity is
 * not established until after authentication.
 *
 * Tokens are therefore tenant-wide, like `users` and `user_entity_roles`,
 * and which entities a token may act in is `entity_ids` - checked by the
 * middleware and again by the gate.
 *
 * ## The scopes are immutable
 *
 * A trigger refuses an UPDATE that changes `abilities`, `entity_ids` or the
 * hash. Widening a live token in place leaves no record that it was ever
 * narrower, so an investigation asking "what could this token do on the 14th"
 * has no answer. Widening is issuing a new token, which is what `rotate` does
 * - and the old row stays, revoked, naming its successor.
 *
 * ## No wildcard
 *
 * `abilities` may not contain `*`. Sanctum's own token model treats `*` as
 * every ability, which would be a second authorization model for machines:
 * one that grants permissions nobody granted and that no permission catalogue
 * describes. PF-218 asks for the scopes to mirror the permissions exactly,
 * and a wildcard is the one value that cannot.
 *
 * @online-safe Creates two tables; takes no lock on anything that exists.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            /*
             | Which plane issued this, and therefore which routes it may
             | reach. Not derivable from `tokenable_type`: that says which
             | model the token belongs to, and a model class is exactly the
             | thing a restore into the wrong database gets right while being
             | wholly wrong.
             */
            $table->string('plane', 16);

            // Sanctum's morph, kept at its own column names so the package's
            // `findToken()` and `tokens()` relation work unmodified.
            $table->string('tokenable_type');
            $table->char('tokenable_id', 26);

            $table->string('name', 64);

            /*
             | The SHA-256 of the token, never the token.
             |
             | A stolen database is a stolen set of credentials otherwise, and
             | the one thing a hash buys is that reading this table does not
             | let anybody act as anybody.
             */
            $table->char('token', 64)->unique();

            /*
             | The scopes: permission keys, verbatim.
             |
             | A jsonb array rather than a join table because the set is read
             | on every single request and written once. The constraint below
             | is what keeps it from becoming a free-text field.
             */
            $table->jsonb('abilities');

            /*
             | The entities this token may act in - PF-218.
             |
             | Never "the tenant". A bookkeeping integration set up for one
             | subsidiary must not be able to post into the parent because
             | somebody added a company six months later, and a
             | tenant-scoped token widens itself every time the group grows.
             */
            $table->jsonb('entity_ids');

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            /*
             | Revoked, not deleted.
             |
             | "Which token posted this entry" has to stay answerable after
             | the token is gone, and a deleted row answers it with silence -
             | which reads as "no token", which is worse than "we do not
             | know".
             */
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 200)->nullable();

            // The successor, when this row was rotated rather than simply
            // revoked. It is what makes a rotation legible afterwards: two
            // rows and an arrow, rather than one row that changed.
            $table->char('rotated_to_token_id', 26)->nullable();

            $table->char('created_by_user_id', 26)->nullable();

            $table->timestamps();

            $table->index(['tokenable_type', 'tokenable_id']);
            $table->index(['plane', 'revoked_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE api_tokens
                ADD CONSTRAINT api_tokens_plane_is_known
                    CHECK (plane IN ('tenant', 'platform')),

                /*
                 | The scopes are a non-empty list of strings, and `*` is not
                 | one of them.
                 |
                 | A token with no scopes is a token that can authenticate and
                 | do nothing, which is a configuration mistake that presents
                 | as a 403 nobody can explain. A token with `*` is the
                 | second, weaker authorization model PF-218 exists to
                 | prevent.
                 */
                ADD CONSTRAINT api_tokens_scopes_are_an_explicit_list
                    CHECK (
                        jsonb_typeof(abilities) = 'array'
                        AND jsonb_array_length(abilities) > 0
                        AND NOT (abilities ? '*')
                    ),

                /*
                 | And it names at least one entity.
                 |
                 | An empty list would read as "no restriction" to anybody
                 | writing the check in a hurry, which is the inversion that
                 | turns a scoped token into a tenant-wide one.
                 */
                ADD CONSTRAINT api_tokens_name_at_least_one_entity
                    CHECK (
                        jsonb_typeof(entity_ids) = 'array'
                        AND jsonb_array_length(entity_ids) > 0
                    ),

                /*
                 | A revocation says why, and a rotation is a revocation.
                 |
                 | "Revoked" with no reason is the audit entry that cannot
                 | distinguish a routine rotation from a response to a leak,
                 | and those are the two cases somebody will later need to
                 | tell apart.
                 */
                ADD CONSTRAINT api_tokens_a_revocation_has_a_reason
                    CHECK (
                        revoked_at IS NULL
                        OR length(btrim(coalesce(revoked_reason, ''))) >= 5
                    );

            COMMENT ON TABLE api_tokens IS
                'Bearer tokens, with the plane on the row - an operator token reaching a tenant route would read a customer''s figures with nothing in that tenant''s audit log saying so - PF-218.';
        SQL);

        /*
         | What a token may do is a fact about a point in time.
         |
         | Enforced here rather than only in the service, for the usual
         | reason: a console command, a backfill or a REPL session during an
         | incident goes nowhere near the service, and "the token was widened
         | by hand on the 14th" is precisely the edit nobody writes down.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_token_is_never_widened()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.abilities <> OLD.abilities
                   OR NEW.entity_ids <> OLD.entity_ids
                   OR NEW.token <> OLD.token
                   OR NEW.plane <> OLD.plane
                   OR NEW.tokenable_id <> OLD.tokenable_id THEN
                    RAISE EXCEPTION
                        'Token % cannot be rewritten in place. Changing what a live token may do leaves no record that it was ever narrower, so "what could this token do on the 14th" stops being answerable. Issue a new token and revoke this one - which is what rotation does.',
                        OLD.name
                        USING ERRCODE = 'check_violation';
                END IF;

                /*
                 | And a revocation is final.
                 |
                 | A token revoked after a leak and then un-revoked is a
                 | credential that was compromised and is live again, with
                 | one row saying both things.
                 */
                IF OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS NULL THEN
                    RAISE EXCEPTION
                        'Token % is revoked. Bringing it back would make a leaked credential live again, with one row saying both things - issue a new token instead.',
                        OLD.name
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER api_tokens_are_never_widened
                BEFORE UPDATE ON api_tokens
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_token_is_never_widened();
        SQL);

        /*
         |----------------------------------------------------------------------
         | What a request already did, so a retry does not do it twice - PF-220
         |----------------------------------------------------------------------
         |
         | A network that drops a RESPONSE is indistinguishable from one that
         | drops a REQUEST. The client has to retry and the server has to make
         | retrying safe, which means the client names the write and the
         | server remembers what it answered.
         |
         | ## Keyed on (token, key), not on (entity, key)
         |
         | PF-220 says `(tenant, token, key)`. The tenant is this database, so
         | the unique index is the other two. The ENTITY is not part of the
         | key: it is part of the FINGERPRINT, so that the same key sent with a
         | different `X-Entity` is reported as the client bug it is rather than
         | posting the same document into a second company.
         |
         | ## And it is entity-scoped by row-level security as well
         |
         | The row holds a stored RESPONSE BODY - a document from one company,
         | with its figures. The fingerprint comparison already stops another
         | entity reading it, and that comparison is application code: one
         | `hash_equals` away from a mistake, and bypassed entirely by a raw
         | query, a console command or a `tinker` session during an incident.
         | Cross-company leakage inside one tenant is harder to detect than
         | cross-tenant leakage, because a figure from the wrong company is
         | still a figure.
         |
         | So both mechanisms apply, which is the same decision every other
         | financial table here takes. The two interact in one place worth
         | writing down: the unique index spans entities and the POLICY does
         | not, so a claim can conflict with a row this entity cannot see.
         | That is not ambiguous - a conflicting row that is invisible belongs
         | to another entity by construction, which means the same key was
         | used for a different request - and {@see IdempotencyStore} reports
         | it as exactly that.
         |
         | The one consequence to know: an EXPIRED record belonging to another
         | entity keeps a key refused rather than freeing it after 24 hours,
         | because the hot-path pruning can only see its own entity's rows.
         | `api:prune-idempotency` clears them per entity; until it runs, the
         | failure is a refusal of a key that must never be reused anyway,
         | which is the conservative direction.
         */
        Schema::create('api_idempotency_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->char('token_id', 26);

            /*
             | `varchar`, not `char`.
             |
             | `char` is blank-padded in PostgreSQL, so a 24-character key
             | comes back as 24 characters and 104 spaces, every comparison in
             | application code fails, and the replay never matches. The same
             | trap `sync_submissions.client_id` documents, and the same
             | answer.
             */
            $table->string('idempotency_key', 128);

            // Context, not scope. See the note above.
            $table->char('entity_id', 26);

            /*
             | SHA-256 over the method, the path, the entity and the raw body.
             |
             | Stored rather than the body itself: a request body holds
             | amounts, party names and sometimes a bank identifier, and this
             | table exists for 24 hours in a database somebody may dump. A
             | hash answers the only question asked of it - "is this the same
             | request" - and leaks nothing.
             */
            $table->char('request_fingerprint', 64);

            $table->string('state', 16)->default('in_flight');

            /*
             | The response, replayed byte-identically.
             |
             | Stored and not re-derived. Re-running the handler to produce
             | the same answer is the thing idempotency exists to avoid, and
             | re-rendering from the record would drift the moment a
             | representation changes.
             */
            $table->smallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();

            // What it produced, so the delivery can be traced to the document
            // without parsing the stored body.
            $table->string('subject_type', 64)->nullable();
            $table->char('subject_id', 26)->nullable();

            $table->timestamp('completed_at')->nullable();

            /*
             | 24 hours, from PF-220, written on the row.
             |
             | On the row rather than computed at read time, because the
             | window is part of the published contract: a client that
             | retries at hour 23 must be replayed and one retrying at hour
             | 25 must execute, and a retention that moves with a config
             | change would move the contract with it.
             */
            $table->timestamp('expires_at');

            $table->timestamps();

            // THE contract: one execution per key, per token.
            $table->unique(['token_id', 'idempotency_key']);
            $table->index('expires_at');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE api_idempotency_records
                ADD CONSTRAINT api_idempotency_state_is_known
                    CHECK (state IN ('in_flight', 'completed')),

                /*
                 | A completed record can answer a replay.
                 |
                 | A row marked completed with no status and no body replays
                 | as an empty 200, which is worse than executing twice:
                 | the client believes it succeeded and has nothing to show.
                 */
                ADD CONSTRAINT api_idempotency_a_completed_one_can_be_replayed
                    CHECK (
                        state <> 'completed'
                        OR (
                            response_status IS NOT NULL
                            AND response_body IS NOT NULL
                            AND completed_at IS NOT NULL
                        )
                    );

            COMMENT ON TABLE api_idempotency_records IS
                'What a request already did, so a retry does not do it twice - a network that drops a response is indistinguishable from one that drops a request - PF-220.';
        SQL);

        /*
         | The standard entity policy, enabled and FORCED.
         |
         | Through the helper rather than by hand, so this table is scoped the
         | same way as every other financial one and cannot be scoped slightly
         | differently by accident. It also grants the migrator an explicit
         | bypass, which is what lets a backfill - or this migration's own
         | `down()` - reach every entity's rows deliberately rather than by
         | default.
         */
        DB::unprepared("SELECT peakflow_apply_entity_rls('api_idempotency_records')");

        /*
         | The fingerprint is what the first request asked for.
         |
         | Rewriting it would make the 409 impossible to reach: the second,
         | different request would overwrite the record of the first and then
         | match itself. That is the failure mode PF-220 calls a client bug
         | being hidden, implemented by accident.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_an_idempotent_request_is_not_rewritten()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.request_fingerprint <> OLD.request_fingerprint
                   OR NEW.idempotency_key <> OLD.idempotency_key
                   OR NEW.token_id <> OLD.token_id THEN
                    RAISE EXCEPTION
                        'The fingerprint of an idempotent request is what the first attempt asked for. Rewriting it lets a second, different request overwrite the first and then match itself, which turns the 409 that reports a client bug into silence.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.state = 'completed' AND NEW.state = 'in_flight' THEN
                    RAISE EXCEPTION
                        'A completed idempotent request does not go back in flight. The stored response is the answer to every later retry.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER api_idempotency_records_are_not_rewritten
                BEFORE UPDATE ON api_idempotency_records
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_an_idempotent_request_is_not_rewritten();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS api_idempotency_records_are_not_rewritten ON api_idempotency_records;
            DROP TRIGGER IF EXISTS api_tokens_are_never_widened ON api_tokens;
            DROP FUNCTION IF EXISTS peakflow_an_idempotent_request_is_not_rewritten();
            DROP FUNCTION IF EXISTS peakflow_a_token_is_never_widened();
        SQL);

        Schema::dropIfExists('api_idempotency_records');
        Schema::dropIfExists('api_tokens');
    }
};
