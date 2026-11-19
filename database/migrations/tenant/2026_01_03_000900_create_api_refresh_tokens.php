<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A person's device, signed in - PF-260.
 *
 * ## Two credentials, and this table holds the long-lived one
 *
 * A mobile app signs a person in with their password (and their second
 * factor), and is handed two things: an ACCESS token, which is an ordinary
 * row in `api_tokens` that dies after an hour, and a REFRESH token, which is a
 * row here and is what the device exchanges for the next access token.
 *
 * The access token is on every request, so it is the copy that ends up in a
 * proxy log or a crash report; keeping it short-lived means such a copy is
 * already dead. The refresh token is shown to the server once an hour and
 * nowhere else.
 *
 * ## Single use, and a second use closes the session
 *
 * Each exchange spends the refresh token and issues a successor in the same
 * FAMILY. A token that is presented after it was spent is not a confused
 * client - the client was handed the successor and stored it - it is a copy
 * held by somebody else. Both parties now hold a credential and the server
 * cannot tell which is the person, so the whole family is revoked and the
 * person signs in again. That is the only response that is safe for both of
 * the people who might be the person.
 *
 * ## Tenant-wide, like `api_tokens`
 *
 * A session can switch between the entities its person holds a role in, so
 * there is no single `entity_id` to apply row-level security to - and the
 * request that presents a refresh token has not established an entity yet,
 * because establishing one is part of what the exchange decides.
 *
 * @online-safe Creates one table; takes no lock on anything that exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_refresh_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            /*
             | The device session this token belongs to.
             |
             | The first token's own id, inherited by every successor. It is
             | what "sign out of my phone" revokes, and what a reused token
             | takes down with it.
             */
            $table->char('family_id', 26);

            $table->char('user_id', 26);

            // The SHA-256 of the secret, never the secret - see api_tokens.
            $table->char('token', 64)->unique();

            // What the person called the device, so that "sign out of the
            // iPad" is a choice they can make from a list.
            $table->string('device_name', 64);

            /*
             | The entity the paired access token was issued for.
             |
             | A refresh with no entity named continues in this one, which is
             | what lets an app restore where the person was without having
             | to remember it separately.
             */
            $table->char('entity_id', 26);

            // The access token issued alongside, so closing the session can
            // revoke the credential that is live right now.
            $table->char('access_token_id', 26)->nullable();

            /*
             | Which second factor the person presented when the SESSION began.
             |
             | Carried through every successor, because it is a fact about the
             | sign-in and the sign-in is what the family descends from. Null
             | when the person has no factor enrolled.
             */
            $table->string('second_factor', 16)->nullable();

            // When the family began, and so when it must end regardless.
            $table->timestamp('session_started_at');

            $table->timestamp('expires_at');

            /*
             | Spent, and by what.
             |
             | Kept rather than deleted: a spent token is the evidence that
             | turns a later presentation of it from "unknown token" into
             | "somebody else holds a copy of this", which is a different
             | finding with a different response.
             */
            $table->timestamp('used_at')->nullable();
            $table->char('replaced_by_id', 26)->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 200)->nullable();

            $table->string('created_ip', 45)->nullable();
            $table->string('user_agent', 300)->nullable();

            $table->timestamps();

            $table->index('family_id');
            $table->index(['user_id', 'revoked_at']);
            $table->index('access_token_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE api_refresh_tokens
                /*
                 | Null, or the name of what was presented - an authenticator's
                 | type, or `recovery_code`. Not an enumeration: the
                 | authenticator types are the MFA module's to extend, and a
                 | constraint here would turn the day it adds one into a day
                 | nobody with that factor can sign in on a phone.
                 */
                ADD CONSTRAINT api_refresh_tokens_factor_is_named
                    CHECK (second_factor IS NULL OR length(btrim(second_factor)) > 0),

                /*
                 | Spent means replaced, and replaced means spent.
                 |
                 | A token marked used with no successor is a session that
                 | ended without anybody deciding it should; a successor
                 | recorded against an unspent token is two live credentials
                 | where the design promises one.
                 */
                ADD CONSTRAINT api_refresh_tokens_spent_names_its_successor
                    CHECK ((used_at IS NULL) = (replaced_by_id IS NULL)),

                ADD CONSTRAINT api_refresh_tokens_a_revocation_has_a_reason
                    CHECK (
                        revoked_at IS NULL
                        OR length(btrim(coalesce(revoked_reason, ''))) >= 5
                    ),

                ADD CONSTRAINT api_refresh_tokens_expire_after_they_begin
                    CHECK (expires_at > session_started_at);

            COMMENT ON TABLE api_refresh_tokens IS
                'peakflow:no-delete - a spent refresh token is the evidence that a later presentation of it is a stolen copy, so it is kept, and DELETE is revoked from the application role - PF-260.';
        SQL);

        /*
         | What a refresh token was is a fact about a point in time.
         |
         | The same rule `api_tokens` holds, for the same reason: an edit by
         | hand during an incident is exactly the change nobody writes down.
         | A spent token stays spent and a revoked one stays revoked - bringing
         | either back would make a credential that was retired, possibly
         | because it leaked, live again.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_refresh_token_is_never_rewritten()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.token <> OLD.token
                   OR NEW.family_id <> OLD.family_id
                   OR NEW.user_id <> OLD.user_id
                   OR NEW.session_started_at <> OLD.session_started_at THEN
                    RAISE EXCEPTION
                        'Refresh token % cannot be rewritten in place. Issue a successor in the same family instead - which is what a refresh does.',
                        OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.used_at IS NOT NULL AND NEW.used_at IS NULL THEN
                    RAISE EXCEPTION
                        'Refresh token % was spent. Un-spending it would make one credential usable twice, which is the thing single use exists to prevent.',
                        OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS NULL THEN
                    RAISE EXCEPTION
                        'Refresh token % is revoked. Bringing it back would make a retired credential live again - sign in again instead.',
                        OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER api_refresh_tokens_are_never_rewritten
                BEFORE UPDATE ON api_refresh_tokens
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_refresh_token_is_never_rewritten();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS api_refresh_tokens_are_never_rewritten ON api_refresh_tokens;
            DROP FUNCTION IF EXISTS peakflow_a_refresh_token_is_never_rewritten();
        SQL);

        Schema::dropIfExists('api_refresh_tokens');
    }
};
