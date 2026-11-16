<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use Illuminate\Database\Connection;
use Throwable;

/**
 * Run each check inside a savepoint, so one failure does not end the suite.
 *
 * ## Why a savepoint per check and not a transaction per suite
 *
 * Several of these checks work by making the database refuse something -
 * a constraint violation, a trigger, a row-level security policy. In
 * PostgreSQL a failed statement aborts the whole transaction: every
 * statement after it fails with "current transaction is aborted", and a
 * suite that shares one transaction reports one real failure and a dozen
 * meaningless ones.
 *
 * So each check gets a savepoint and the savepoint is rolled back whether it
 * passed or failed. The outer transaction - which the suite opens and rolls
 * back - keeps the fixtures out of the committed database.
 *
 * Measured, the first time this was needed: the archive suite's first
 * intentional refusal took the next fifteen checks with it, and the report
 * said the suite could not be built.
 */
trait RunsInSavepoints
{
    protected ?Connection $connection = null;

    /**
     * @param  array<string, callable(): list<string>>  $rules
     * @return array<string, list<string>>
     */
    protected function runEachInASavepoint(array $rules): array
    {
        $results = [];

        foreach ($rules as $name => $rule) {
            $savepoint = $this->savepoint();

            try {
                $this->refreshFixtures();

                $results[$name] = $rule();
            } catch (Throwable $e) {
                $results[$name] = ['the check could not run: '.$e->getMessage()];
            } finally {
                $this->rollbackTo($savepoint);
            }
        }

        return $results;
    }

    /**
     * Re-read whatever the previous savepoint's rollback invalidated.
     *
     * A savepoint rolls back the database and not PHP, so a model loaded
     * before it still holds the row as it was. Suites with fixture models
     * override this; suites with none do not need to.
     */
    protected function refreshFixtures(): void {}

    protected function savepoint(): string
    {
        $name = 'sp_'.bin2hex(random_bytes(4));
        $this->connection?->statement('SAVEPOINT '.$name);

        return $name;
    }

    protected function rollbackTo(string $savepoint): void
    {
        $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
    }
}
