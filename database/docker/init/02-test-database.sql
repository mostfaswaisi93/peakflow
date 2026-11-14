-- The feature suite creates and wipes its own databases, so the server only
-- has to let it. `peakflow_testing` is the platform test database;
-- `peakflow_testing_tenant` is the disposable tenant one.
--
-- These are created here rather than by the test harness so that a developer
-- whose PostgreSQL role lacks CREATEDB can still run the suite.

CREATE DATABASE peakflow_testing OWNER peakflow_migrator;
CREATE DATABASE peakflow_testing_tenant OWNER peakflow_migrator;

GRANT ALL PRIVILEGES ON DATABASE peakflow_testing TO peakflow_migrator;
GRANT ALL PRIVILEGES ON DATABASE peakflow_testing_tenant TO peakflow_migrator;
GRANT CONNECT ON DATABASE peakflow_testing, peakflow_testing_tenant TO peakflow_app;
