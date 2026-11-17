<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Settlement\Exceptions\PaymentRefused;
use App\Tenant\Settlement\Payments\PainSerializer;
use App\Tenant\Settlement\Payments\PaymentScheme;
use App\Tenant\Settlement\Payments\RulebookCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Producing, archiving and handing over the payment file - PF-176.
 *
 * ## The rulebook is checked before anything is built
 *
 * A payment file is rejected as a whole: one forbidden character and
 * nobody in the run is paid, hours later, by an email naming an XPath. So
 * {@see RulebookCheck} runs first and reports everything at once, with
 * the beneficiary named - because three rounds of fix-and-retry is three
 * times a person waiting on a file.
 *
 * ## The file is bound to what was authorised
 *
 * PF-175 hashes the released instruction set. The file carries that hash,
 * and the database refuses a file whose hash is not the run's own - so
 * "is this the file for what was approved" is a string comparison rather
 * than a reconstruction from two tables and an argument.
 *
 * ## Handed over once, and regeneration is visible
 *
 * A payment file is an instrument. Two copies in circulation is two
 * chances for somebody to upload the second after the first was
 * processed, and the bank's duplicate detection is not something to rely
 * on.
 *
 * So `handOver()` records the first and the database refuses a second.
 * `regenerate()` supersedes, with a reason, and leaves both on the record
 * - because "which file did we actually send" is the question an
 * investigation starts with.
 */
final class PaymentFiles
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly PainSerializer $serializer,
        private readonly RulebookCheck $rulebook,
    ) {}

    /**
     * What the scheme would say, without building anything.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function check(string $runId): array
    {
        $run = $this->runOr404($runId);

        return $this->rulebook->of($run, $this->schemeFor($run));
    }

    /**
     * Build the file for a released run.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function generate(string $runId, string $userId): array
    {
        $run = $this->runOr404($runId);

        if ((string) $run->state !== 'released') {
            throw PaymentRefused::because(sprintf(
                'Run %s is %s. A file for an unreleased run would be an instruction for money nobody '.
                'authorised - release it first, which is where the second person, the factor and the '.
                'check on what changed all happen.',
                (string) $run->number,
                (string) $run->state,
            ));
        }

        $existing = $this->liveFileFor($runId);

        if ($existing !== null) {
            throw PaymentRefused::because(sprintf(
                'Run %s already has file %s, generated on %s%s. A second file for one run is a second '.
                'instrument for one payment: if the first is wrong, regenerate - which supersedes it '.
                'and says why, leaving both on the record.',
                (string) $run->number,
                (string) $existing->message_id,
                (string) $existing->generated_at,
                $existing->downloaded_at === null ? ' and not yet handed over' : ' and already handed over',
            ));
        }

        $scheme = $this->schemeFor($run);
        $rulebook = $this->rulebook->of($run, $scheme);

        if ($rulebook['blocks']) {
            throw PaymentRefused::because($rulebook['why']);
        }

        return $this->write($run, $scheme, $userId, $rulebook, null, null);
    }

    /**
     * A new file for the same run, superseding the one before it.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function regenerate(string $runId, string $userId, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 15) {
            throw PaymentRefused::because(
                'Regenerating a payment file needs a reason. A second file for one run is the thing an '.
                'investigation asks about first - a bare pointer answers which file replaced which, and '.
                'not why there were two.',
            );
        }

        $run = $this->runOr404($runId);
        $previous = $this->liveFileFor($runId);

        if ($previous === null) {
            throw PaymentRefused::because(sprintf(
                'Run %s has no current file to replace. Generate one.',
                (string) $run->number,
            ));
        }

        $scheme = $this->schemeFor($run);
        $rulebook = $this->rulebook->of($run, $scheme);

        if ($rulebook['blocks']) {
            throw PaymentRefused::because($rulebook['why']);
        }

        return $this->write($run, $scheme, $userId, $rulebook, (string) $previous->id, trim($reason));
    }

    /**
     * Hand the file over, once.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function handOver(string $fileId, string $userId): array
    {
        $file = DB::connection('tenant')->table('payment_files')->where('id', $fileId)->first();

        if ($file === null) {
            throw PaymentRefused::because('There is no payment file with that id.');
        }

        if ($file->superseded_by_id !== null) {
            throw PaymentRefused::because(sprintf(
                'File %s was superseded and is kept as a record rather than as an instrument. The '.
                'current file for this run is the one that replaced it.',
                (string) $file->message_id,
            ));
        }

        if ($file->downloaded_at !== null) {
            throw PaymentRefused::because(sprintf(
                'File %s was handed over on %s. A second copy is a second instrument for one payment: '.
                'regenerate if it has to go again, which supersedes this one and leaves both on the '.
                'record.',
                (string) $file->message_id,
                (string) $file->downloaded_at,
            ));
        }

        return DB::connection('tenant')->transaction(function () use ($file, $fileId, $userId): array {
            DB::connection('tenant')->table('payment_files')->where('id', $fileId)->update([
                'downloaded_at' => now(),
                'downloaded_by' => $userId,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'settlement',
                action: 'payment_file.handed_over',
                subjectType: 'payment_file',
                subjectId: $fileId,
                subjectLabel: (string) $file->message_id,
                after: [
                    'sha256' => (string) $file->sha256,
                    'authorised_hash' => (string) $file->authorised_hash,
                    'instructions' => (int) $file->instruction_count,
                    'total' => (string) $file->total,
                ],
                reason: 'the file was handed over for submission to the bank',
                entityId: (string) $file->entity_id,
            );

            return [
                'id' => $fileId,
                'message_id' => (string) $file->message_id,
                'content' => (string) $file->content,
                'sha256' => (string) $file->sha256,
                'why' => sprintf(
                    'File %s handed over, recorded under %s. It cannot be handed over again: a second '.
                    'copy is a second instrument for one payment, and the bank\'s duplicate detection '.
                    'is not something to rely on. If it has to go again, regenerate.',
                    (string) $file->message_id,
                    mb_substr((string) $file->sha256, 0, 12),
                ),
            ];
        });
    }

    /** The file a run currently has, with its content. @return array<string, mixed>|null */
    public function current(string $runId): ?array
    {
        $file = $this->liveFileFor($runId);

        if ($file === null) {
            return null;
        }

        return [
            'id' => (string) $file->id,
            'message_id' => (string) $file->message_id,
            'scheme' => (string) $file->scheme,
            'format' => (string) $file->format,
            'sha256' => (string) $file->sha256,
            'authorised_hash' => (string) $file->authorised_hash,
            'byte_count' => (int) $file->byte_count,
            'instruction_count' => (int) $file->instruction_count,
            'total' => Money::of((string) $file->total, (string) $file->currency),
            'generated_at' => (string) $file->generated_at,
            'handed_over_at' => $file->downloaded_at === null ? null : (string) $file->downloaded_at,
            'content' => (string) $file->content,
        ];
    }

    /**
     * Every file this run has had, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $runId): array
    {
        $rows = DB::connection('tenant')->table('payment_files')
            ->where('run_id', $runId)
            ->orderByDesc('generated_at')
            ->get();

        return $rows->map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'message_id' => (string) $row->message_id,
            'sha256' => (string) $row->sha256,
            'generated_at' => (string) $row->generated_at,
            'handed_over_at' => $row->downloaded_at === null ? null : (string) $row->downloaded_at,
            'superseded_by' => $row->superseded_by_id === null ? null : (string) $row->superseded_by_id,
            'superseded_reason' => $row->superseded_reason === null ? null : (string) $row->superseded_reason,
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $rulebook
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    private function write(
        object $run,
        PaymentScheme $scheme,
        string $userId,
        array $rulebook,
        ?string $supersedes,
        ?string $reason,
    ): array {
        $createdAt = Carbon::now();

        /*
         | The message id, unique per entity, and never reused.
         |
         | A bank rejecting a duplicate message id rejects the whole file,
         | and the usual cause is a regeneration reusing the id of the one
         | that was already accepted. So it carries the run's number and a
         | distinct suffix rather than being derived from the run alone.
         */
        $messageId = $this->messageId($run, $createdAt);

        $content = $this->serializer->render($run, $scheme, $messageId, $createdAt);
        $sha = hash('sha256', $content);

        $total = Money::of((string) $run->net_total, (string) $run->currency);
        $fileId = (string) Str::ulid();

        return DB::connection('tenant')->transaction(function () use (
            $run,
            $scheme,
            $userId,
            $rulebook,
            $supersedes,
            $reason,
            $createdAt,
            $messageId,
            $content,
            $sha,
            $total,
            $fileId,
        ): array {
            /*
             | The previous file is superseded FIRST.
             |
             | The partial unique index allows one live file per run, so
             | inserting before superseding would be refused - and the
             | refusal would name an index rather than explain that a
             | regeneration is in progress.
             */
            if ($supersedes !== null) {
                DB::connection('tenant')->table('payment_files')->where('id', $supersedes)->update([
                    'superseded_by_id' => $fileId,
                    'superseded_reason' => $reason,
                    'updated_at' => now(),
                ]);
            }

            DB::connection('tenant')->table('payment_files')->insert([
                'id' => $fileId,
                'entity_id' => (string) $run->entity_id,
                'run_id' => (string) $run->id,
                'scheme' => $scheme->key,
                'format' => $scheme->format(),
                'message_id' => $messageId,
                'created_at_message' => $createdAt,
                'content' => $content,
                'sha256' => $sha,
                'byte_count' => strlen($content),
                'authorised_hash' => (string) $run->released_hash,
                'instruction_count' => (int) $run->instruction_count,
                'total' => (string) $total->amount,
                'currency' => (string) $run->currency,
                'generated_by' => $userId,
                'generated_at' => $createdAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'settlement',
                action: $supersedes === null ? 'payment_file.generated' : 'payment_file.regenerated',
                subjectType: 'payment_file',
                subjectId: $fileId,
                subjectLabel: $messageId,
                before: $supersedes === null ? null : ['superseded_file_id' => $supersedes],
                after: [
                    'run' => (string) $run->number,
                    'scheme' => $scheme->key,
                    'format' => $scheme->format(),
                    'sha256' => $sha,
                    'authorised_hash' => (string) $run->released_hash,
                    'bytes' => strlen($content),
                    'instructions' => (int) $run->instruction_count,
                    'total' => (string) $total->amount,
                    'advisories' => \count($rulebook['findings']),
                ],
                reason: $reason ?? sprintf('a %s file was produced for run %s', $scheme->label(), (string) $run->number),
                entityId: (string) $run->entity_id,
            );

            return [
                'id' => $fileId,
                'message_id' => $messageId,
                'scheme' => $scheme->key,
                'format' => $scheme->format(),
                'sha256' => $sha,
                'authorised_hash' => (string) $run->released_hash,
                'byte_count' => strlen($content),
                'content' => $content,
                'names' => $rulebook['names'],
                'advisories' => array_values(array_filter(
                    $rulebook['findings'],
                    static fn (array $f): bool => ! $f['blocks'],
                )),
                'why' => sprintf(
                    '%s built for run %s: %d instruction%s, %s, as %s. Recorded under %s, against the '.
                    '%s the release authorised - so the file and what was approved can be held '.
                    'together. It can be handed over once.%s',
                    $messageId,
                    (string) $run->number,
                    (int) $run->instruction_count,
                    (int) $run->instruction_count === 1 ? '' : 's',
                    (string) $total->toPresentation(),
                    $scheme->format(),
                    mb_substr($sha, 0, 12),
                    mb_substr((string) $run->released_hash, 0, 12),
                    $rulebook['findings'] === []
                        ? ''
                        : sprintf(
                            ' %d thing%s worth reading first: %s',
                            \count($rulebook['findings']),
                            \count($rulebook['findings']) === 1 ? ' is' : 's are',
                            implode(' ', array_map(
                                static fn (array $f): string => ucfirst($f['why']).'.',
                                $rulebook['findings'],
                            )),
                        ),
                ),
            ];
        });
    }

    private function messageId(object $run, Carbon $at): string
    {
        /*
         | Short, and shaped so a bank's support desk can read it back.
         |
         | 35 characters is the schema's limit. The run's number is what a
         | person searches for; the suffix is what makes a regeneration a
         | different id, which is the whole reason a duplicate rejection
         | happens.
         */
        $number = preg_replace('/[^A-Za-z0-9]/', '', (string) $run->number) ?? '';

        return mb_substr(sprintf(
            '%s-%s-%s',
            mb_substr($number, 0, 16),
            $at->format('ymdHis'),
            mb_strtoupper(Str::random(4)),
        ), 0, 35);
    }

    /** @throws PaymentRefused */
    private function schemeFor(object $run): PaymentScheme
    {
        $declared = DB::connection('tenant')->table('bank_accounts')
            ->where('id', (string) $run->bank_account_id)
            ->value('payment_scheme');

        return PaymentScheme::named($declared === null ? null : (string) $declared);
    }

    private function liveFileFor(string $runId): ?object
    {
        return DB::connection('tenant')->table('payment_files')
            ->where('run_id', $runId)
            ->whereNull('superseded_by_id')
            ->first();
    }

    /** @throws PaymentRefused */
    private function runOr404(string $runId): object
    {
        $run = DB::connection('tenant')->table('payment_runs')->where('id', $runId)->first();

        if ($run === null) {
            throw PaymentRefused::because('There is no payment run with that id.');
        }

        return $run;
    }
}
