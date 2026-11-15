-- The feature suite creates and wipes its own databases, so the server only
-- has to let it. `peakflow_testing` is the platform test database;
-- `peakflow_testing_tenant` is the disposable tenant one.
--
-- These are created here rather than by the test harness so that a developer
-- whose PostgreSQL role lacks CREATEDB can still run the suite.

CREATE DATABASE peakflow_testing OWNER peakflow_migrator;
CREATE DATABASE peakflow_testing_tenant OWNER peakflow_migrator;

-- Same reasoning as 01-roles.sql: a new database's empty ACL grants CONNECT to
-- PUBLIC, so the revoke has to come before the grant. It matters here too -
-- the test databases hold whatever a developer last seeded, which in practice
-- is a copy of production's shape.
REVOKE ALL ON DATABASE peakflow_testing FROM PUBLIC;
REVOKE ALL ON DATABASE peakflow_testing_tenant FROM PUBLIC;

GRANT ALL PRIVILEGES ON DATABASE peakflow_testing TO peakflow_migrator;
GRANT ALL PRIVILEGES ON DATABASE peakflow_testing_tenant TO peakflow_migrator;
GRANT CONNECT, TEMPORARY ON DATABASE peakflow_testing, peakflow_testing_tenant TO peakflow_app;
