<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use Symfony\Component\Process\Process;

/**
 * ClamAV, through `clamdscan` - PF-073.
 *
 * ## Exit codes, and the one that matters
 *
 * `clamdscan` exits 0 for clean, 1 for infected, and 2 for an error. The
 * third is the one implementations get wrong: a daemon that is not running,
 * a socket with the wrong permissions or a file the daemon cannot read all
 * exit 2, and a scanner that treats a non-1 exit as clean passes every file
 * on a machine where ClamAV is broken.
 *
 * So 2 is {@see ScanVerdict::failed()}, which the read path refuses.
 *
 * ## `--fdpass`, and why the daemon may not be able to read the file
 *
 * `clamd` usually runs as its own user and the application's storage is
 * usually not world readable. Passing the descriptor rather than the path
 * means the daemon reads through the application's own open file, which
 * avoids making the storage directory readable by another account.
 */
final class ClamAvScanner implements VirusScanner
{
    public function __construct(
        private readonly string $binary = 'clamdscan',
        private readonly int $timeoutSeconds = 60,
    ) {}

    public function name(): string
    {
        return 'clamav';
    }

    public function scan(string $absolutePath): ScanVerdict
    {
        if (! is_file($absolutePath)) {
            return ScanVerdict::failed($this->name(), sprintf('%s is not a file.', $absolutePath));
        }

        $process = new Process([$this->binary, '--no-summary', '--fdpass', $absolutePath]);
        /*
         | No cast.
         |
         | `setTimeout()` takes a float and PHP widens an int to one even
         | under `strict_types`. The cast was there for clarity and tripped
         | the no-float guardrail, which scans `app/Support` without knowing
         | that this particular number is seconds rather than money - and a
         | guardrail that has to know would be one with exceptions in it.
         */
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return ScanVerdict::failed($this->name(), $e->getMessage());
        }

        $output = trim($process->getOutput().' '.$process->getErrorOutput());

        return match ($process->getExitCode()) {
            0 => ScanVerdict::clean($this->name()),
            1 => ScanVerdict::infected($this->name(), $output === '' ? 'a signature matched' : $output),
            default => ScanVerdict::failed($this->name(), sprintf(
                'clamdscan exited %s: %s',
                var_export($process->getExitCode(), true),
                $output === '' ? 'no output' : $output,
            )),
        };
    }
}
