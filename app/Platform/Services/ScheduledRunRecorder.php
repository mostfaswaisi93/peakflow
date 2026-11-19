<?php

declare(strict_types=1);

namespace App\Platform\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes one row per finished scheduled run - PF-241, PF-238.
 *
 * ## An alert is a notification; this is a record
 *
 * `routes/console.php` already raises `scheduled_job_failed` when a job
 * exits non-zero, and that is the right thing for somebody to be woken by.
 * It is not a history: an alert that fired three weeks ago is in whatever
 * the log retention kept, and "did the nightly chain walk run every night
 * last quarter" is not a question a notification can answer.
 *
 * ## It records successes too
 *
 * A table of failures answers "what went wrong" and not "what happened",
 * and the second is the one an auditor asks. A run that is MISSING from a
 * history of failures looks exactly like a run that succeeded - and it is
 * also what a run that never started looks like.
 */
final class ScheduledRunRecorder
{
    public function record(string $command, Carbon $startedAt, ?int $exitCode): void
    {
        $finishedAt = Carbon::now();

        try {
            DB::connection('platform')->table('scheduled_runs')->insert([
                'id' => (string) Str::ulid(),
                'command' => Str::limit($this->tidy($command), 190, ''),
                'tenant' => null,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'duration_ms' => max(0, (int) $startedAt->diffInMilliseconds($finishedAt)),
                'exit_code' => $exitCode,
                'created_at' => $finishedAt,
                'updated_at' => $finishedAt,
            ]);
        } catch (\Throwable) {
            /*
             | A recorder that can take the scheduler down is worse than no
             | recorder.
             |
             | This runs in an after-callback, so a throw here would abort
             | the remaining callbacks - including the one that raises the
             | alert. The failure that matters would then be unreported
             | BECAUSE the thing recording failures failed.
             */
        }
    }

    /**
     * The command as a person would name it.
     *
     * The event carries a full command line - a quoted php binary, the
     * artisan path, the command and its arguments - and a history keyed on
     * that is a history where the same job looks like two after a deployment
     * moves the binary.
     */
    private function tidy(string $command): string
    {
        if (preg_match('/artisan[\'"]?\s+(.+)$/', $command, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return trim($command);
    }
}
