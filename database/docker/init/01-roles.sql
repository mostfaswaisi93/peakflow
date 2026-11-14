-- =============================================================================
--  Database roles and least privilege (PF-010)
-- =============================================================================
--  Three roles, because the application must not be able to do things it has
--  no business doing regardless of what the code says. An ORM cannot be
--  trusted to protect an invariant that a migration, a console command or a
--  future developer's REPL session can reach around.
--
--    peakflow_migrator  - owns the schema. Migrations run as this, from a
--                         separate process with its own credentials.
--    peakflow_app       - what the application connects as. No DDL, and no
--                         DELETE on tables holding posted financial rows.
--    peakflow_readonly  - reporting replicas and analytics.
--
--  The specific grants on financial tables are applied by the migrations that
--  create those tables, because a REVOKE on a table that does not exist yet
--  cannot be written here. PF-233 verifies the LIVE grants on every deploy -
--  not that this script once ran, but that the privileges are as expected now,
--  because a manual grant made during an incident is exactly how it regresses.
-- =============================================================================

-- The migrator is the POSTGRES_USER and already exists.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'peakflow_app') THEN
        CREATE ROLE peakflow_app LOGIN PASSWORD 'peakflow';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'peakflow_readonly') THEN
        CREATE ROLE peakflow_readonly LOGIN PASSWORD 'peakflow';
    END IF;
END
$$;

-- The application connects, but owns nothing and cannot create anything.
GRANT CONNECT ON DATABASE peakflow TO peakflow_app, peakflow_readonly;
GRANT USAGE ON SCHEMA public TO peakflow_app, peakflow_readonly;

-- No DDL for the application. This is the grant that makes "the application
-- cannot ALTER" a fact rather than an intention.
REVOKE CREATE ON SCHEMA public FROM peakflow_app, peakflow_readonly;
REVOKE ALL ON SCHEMA public FROM PUBLIC;

-- Default privileges for tables the migrator creates from here on.
ALTER DEFAULT PRIVILEGES FOR ROLE peakflow_migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO peakflow_app;

ALTER DEFAULT PRIVILEGES FOR ROLE peakflow_migrator IN SCHEMA public
    GRANT SELECT ON TABLES TO peakflow_readonly;

ALTER DEFAULT PRIVILEGES FOR ROLE peakflow_migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO peakflow_app;

-- Row-level security applies to the application role and NOT to the migrator,
-- which is correct: the migrator has to be able to backfill across entities.
-- The policies themselves arrive with the tables they protect (PF-026).
