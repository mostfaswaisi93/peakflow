<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Support\Xml\SafeXmlReader;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Settlement\Exceptions\PaymentRefused;
use App\Tenant\Settlement\Posting\Rules\InstructionRejectedRule;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reading what the bank said about each instruction - PF-178.
 *
 * ## Per instruction, because a batch is usually only partly refused
 *
 * The interesting case is thirty-eight accepted and two refused for an
 * account that no longer exists. A product that read only the group
 * status would mark the whole batch pending, or worse accepted, and two
 * suppliers would go unpaid with nothing in the system saying so.
 *
 * So the group status is recorded beside the per-transaction statuses
 * rather than instead of them, and every accounting consequence is per
 * instruction.
 *
 * ## A rejection returns the payable, because it is owed again
 *
 * The supplier was never paid, so they are still a creditor and the bill
 * has to be selectable into another run. Reversing the whole release
 * entry would unwind the instructions the bank accepted as well, and
 * those are going to clear - so the rejection posts for its own
 * instruction's amount and leaves the rest alone.
 *
 * ## An unmatched status is kept and reported
 *
 * A status naming an instruction we cannot find is the most interesting
 * row in the file: a file submitted twice, a reference the bank mangled,
 * a report for another company routed to us. Discarding it loses the only
 * evidence any of those happened.
 */
final class PaymentStatuses
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Poster $poster,
    ) {}

    /**
     * Ingest a PAIN.002 and apply it.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function ingest(string $entityId, string $xml, string $userId): array
    {
        $parsed = $this->read($xml);
        $sha = hash('sha256', $xml);

        $already = DB::connection('tenant')->table('payment_status_reports')
            ->where('entity_id', $entityId)
            ->where('message_id', $parsed['message_id'])
            ->first();

        if ($already !== null) {
            /*
             | A bank that resends a report must not apply it twice.
             |
             | The second application would reverse an in-transit posting
             | that was already reversed, and the payable would come back
             | owed twice - which the supplier discovers when they are
             | paid twice.
             */
            throw PaymentRefused::because(sprintf(
                'Status report %s was already ingested on %s. Applying it again would reverse postings '.
                'that were already reversed, and a payable returned twice is a supplier paid twice.%s',
                $parsed['message_id'],
                (string) $already->created_at,
                (string) $already->sha256 === $sha
                    ? ' It is byte-for-byte the same file.'
                    : ' Note that the file differs from the one ingested under that id, which is worth '.
                      'asking the bank about.',
            ));
        }

        $entity = Entity::query()->findOrFail($entityId);
        $reportId = (string) Str::ulid();

        return DB::connection('tenant')->transaction(function () use (
            $entityId,
            $entity,
            $parsed,
            $xml,
            $sha,
            $reportId,
            $userId,
        ): array {
            $applied = [];
            $unmatched = [];

            foreach ($parsed['statuses'] as $status) {
                $instruction = $this->instructionFor($entityId, $status);

                if ($instruction === null) {
                    $unmatched[] = $status;

                    DB::connection('tenant')->table('instruction_statuses')->insert([
                        'id' => (string) Str::ulid(),
                        'entity_id' => $entityId,
                        'report_id' => $reportId,
                        'instruction_id' => null,
                        'bank_reference' => $status['reference'],
                        'status' => $status['status'],
                        'reason_code' => $status['reason_code'],
                        'reason' => $status['reason'],
                        'amount' => $status['amount'],
                        'currency' => $status['currency'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    continue;
                }

                $applied[] = $this->apply($entity, $reportId, $instruction, $status, $userId);
            }

            DB::connection('tenant')->table('payment_status_reports')->insert([
                'id' => $reportId,
                'entity_id' => $entityId,
                'message_id' => $parsed['message_id'],
                'original_message_id' => $parsed['original_message_id'],
                'run_id' => $this->runFor($entityId, $parsed['original_message_id']),
                'group_status' => $parsed['group_status'],
                'reported_at' => $parsed['reported_at'],
                'content' => $xml,
                'sha256' => $sha,
                'statuses' => \count($parsed['statuses']),
                'matched' => \count($applied),
                'unmatched' => \count($unmatched),
                'ingested_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $rejected = array_values(array_filter($applied, static fn (array $a): bool => $a['status'] === 'RJCT'));
            $accepted = array_values(array_filter($applied, static fn (array $a): bool => $a['accepted']));

            $this->audit->record(
                category: 'settlement',
                action: 'payment_status.ingested',
                subjectType: 'payment_status_report',
                subjectId: $reportId,
                subjectLabel: $parsed['message_id'],
                after: [
                    'original_message_id' => $parsed['original_message_id'],
                    'group_status' => $parsed['group_status'],
                    'statuses' => \count($parsed['statuses']),
                    'accepted' => \count($accepted),
                    'rejected' => \count($rejected),
                    'unmatched' => \count($unmatched),
                    'sha256' => $sha,
                ],
                reason: sprintf(
                    'the bank reported on %d instruction(s): %d accepted, %d refused, %d not recognised',
                    \count($parsed['statuses']),
                    \count($accepted),
                    \count($rejected),
                    \count($unmatched),
                ),
                entityId: $entityId,
            );

            return [
                'id' => $reportId,
                'message_id' => $parsed['message_id'],
                'group_status' => $parsed['group_status'],
                'accepted' => \count($accepted),
                'rejected' => \count($rejected),
                'unmatched' => $unmatched,
                'rejections' => $rejected,
                'why' => $this->summarise($parsed, $accepted, $rejected, $unmatched),
            ];
        });
    }

    /**
     * Statuses the bank sent that name nothing we hold.
     *
     * @return list<array<string, mixed>>
     */
    public function unmatched(string $entityId): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT s.id,
                   s.bank_reference,
                   s.status,
                   s.reason_code,
                   s.reason,
                   s.amount::text AS amount,
                   s.currency,
                   r.message_id,
                   r.reported_at
            FROM instruction_statuses s
            JOIN payment_status_reports r ON r.id = s.report_id
            WHERE s.entity_id = ?
              AND s.instruction_id IS NULL
            ORDER BY r.reported_at DESC
        SQL, [$entityId]);

        return array_map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'bank_reference' => $row->bank_reference === null ? null : (string) $row->bank_reference,
            'status' => (string) $row->status,
            'reason_code' => $row->reason_code === null ? null : (string) $row->reason_code,
            'reason' => $row->reason === null ? null : (string) $row->reason,
            'amount' => $row->amount === null ? null : (string) $row->amount,
            'report' => (string) $row->message_id,
            'reported_at' => (string) $row->reported_at,
            'why' => sprintf(
                'The bank reported %s for "%s" and nothing here instructed that. The usual causes are a '.
                'file submitted twice, a reference the bank mangled, or a report for another company '.
                'routed to us - and all three are worth knowing about.',
                (string) $row->status,
                (string) ($row->bank_reference ?? 'an instruction with no reference'),
            ),
        ], $rows);
    }

    /**
     * Apply one status to one instruction.
     *
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function apply(
        Entity $entity,
        string $reportId,
        object $instruction,
        array $status,
        string $userId,
    ): array {
        $entryId = null;
        $accepted = \in_array($status['status'], ['ACCP', 'ACSP', 'ACSC', 'ACTC', 'ACWC'], true);

        if ($status['status'] === 'RJCT') {
            /*
             | The payable comes back, for this instruction's amount only.
             |
             | Not by reversing the release entry: a batch is usually only
             | partly refused, and reversing it would unwind the
             | instructions the bank accepted as well.
             */
            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: InstructionRejectedRule::KEY,
                event: [
                    'amount' => (string) $instruction->amount,
                    'party_id' => (string) $instruction->party_id,
                    'narration' => sprintf(
                        'The bank refused this instruction (%s)',
                        (string) ($status['reason_code'] ?? 'no code given'),
                    ),
                ],
                accountingDate: Carbon::parse((string) $status['reported_at']),
                journalCode: 'CSH',
                sourceType: 'payment_run_instruction',
                sourceId: (string) $instruction->id,
            );

            $entryId = (string) $entry->getKey();
        }

        DB::connection('tenant')->table('instruction_statuses')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => (string) $instruction->entity_id,
            'report_id' => $reportId,
            'instruction_id' => (string) $instruction->id,
            'bank_reference' => $status['reference'],
            'status' => $status['status'],
            'reason_code' => $status['reason_code'],
            'reason' => $status['reason'],
            'amount' => $status['amount'],
            'currency' => $status['currency'],
            'journal_entry_id' => $entryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('tenant')->table('payment_run_instructions')
            ->where('id', (string) $instruction->id)
            ->update([
                'bank_status' => $status['status'],
                'bank_reason_code' => $status['reason_code'],
                'updated_at' => now(),
            ]);

        return [
            'instruction_id' => (string) $instruction->id,
            'party_id' => (string) $instruction->party_id,
            'status' => (string) $status['status'],
            'accepted' => $accepted,
            'reason_code' => $status['reason_code'],
            'amount' => (string) $instruction->amount,
            'journal_entry_id' => $entryId,
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function instructionFor(string $entityId, array $status): ?object
    {
        if ($status['reference'] === null) {
            return null;
        }

        return DB::connection('tenant')->table('payment_run_instructions')
            ->where('entity_id', $entityId)
            ->where('id', $status['reference'])
            ->first();
    }

    private function runFor(string $entityId, ?string $originalMessageId): ?string
    {
        if ($originalMessageId === null) {
            return null;
        }

        $runId = DB::connection('tenant')->table('payment_files')
            ->where('entity_id', $entityId)
            ->where('message_id', $originalMessageId)
            ->value('run_id');

        return $runId === null ? null : (string) $runId;
    }

    /**
     * Read the statuses out of a PAIN.002.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    private function read(string $xml): array
    {
        /*
         | Through the safe reader, because this file came from outside.
         |
         | A status report arrives by SFTP, email or a portal download.
         | Parsing it with entity expansion enabled is how an attacker
         | reads the filesystem through a file the bank is supposed to
         | have sent.
         */
        $document = SafeXmlReader::parse($xml);
        $path = new \DOMXPath($document);
        $path->registerNamespace('p', (string) $document->documentElement?->namespaceURI);

        $messageId = $path->query('//p:GrpHdr/p:MsgId')->item(0)?->textContent;

        if ($messageId === null || trim($messageId) === '') {
            throw PaymentRefused::because(
                'This file carries no message id, so it cannot be a status report - and ingesting it '.
                'would mean no way to refuse it when the bank sends it again.',
            );
        }

        $reportedAt = $path->query('//p:GrpHdr/p:CreDtTm')->item(0)?->textContent;
        $original = $path->query('//p:OrgnlGrpInfAndSts/p:OrgnlMsgId')->item(0)?->textContent;
        $groupStatus = $path->query('//p:OrgnlGrpInfAndSts/p:GrpSts')->item(0)?->textContent;

        $statuses = [];

        foreach ($path->query('//p:TxInfAndSts') as $node) {
            $status = $path->query('p:TxSts', $node)->item(0)?->textContent;

            if ($status === null) {
                continue;
            }

            $amount = $path->query('p:OrgnlTxRef/p:Amt/p:InstdAmt', $node)->item(0);

            $statuses[] = [
                'reference' => trim((string) ($path->query('p:OrgnlEndToEndId', $node)->item(0)?->textContent ?? '')) ?: null,
                'status' => trim($status),
                'reason_code' => trim((string) ($path->query('p:StsRsnInf/p:Rsn/p:Cd', $node)->item(0)?->textContent ?? '')) ?: null,
                'reason' => trim((string) ($path->query('p:StsRsnInf/p:AddtlInf', $node)->item(0)?->textContent ?? '')) ?: null,
                'amount' => $amount === null ? null : $amount->textContent,
                'currency' => $amount instanceof \DOMElement ? ($amount->getAttribute('Ccy') ?: null) : null,
                'reported_at' => $reportedAt ?? now()->toDateTimeString(),
            ];
        }

        if ($statuses === [] && $groupStatus === null) {
            throw PaymentRefused::because(
                'This file reports neither a group status nor any transaction status, so there is '.
                'nothing in it to apply.',
            );
        }

        return [
            'message_id' => trim($messageId),
            'original_message_id' => $original === null ? null : trim($original),
            'group_status' => $groupStatus === null ? null : trim($groupStatus),
            'reported_at' => $reportedAt ?? now()->toDateTimeString(),
            'statuses' => $statuses,
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<array<string, mixed>>  $accepted
     * @param  list<array<string, mixed>>  $rejected
     * @param  list<array<string, mixed>>  $unmatched
     */
    private function summarise(array $parsed, array $accepted, array $rejected, array $unmatched): string
    {
        if ($rejected === [] && $unmatched === []) {
            return sprintf(
                'The bank accepted all %d instruction(s) in %s. They stay in transit until a statement '.
                'line says the money left - which is a different fact from the bank agreeing to send it.',
                \count($accepted),
                (string) $parsed['original_message_id'] ?: 'that file',
            );
        }

        $parts = [];

        if ($accepted !== []) {
            $parts[] = sprintf('%d accepted', \count($accepted));
        }

        if ($rejected !== []) {
            $parts[] = sprintf(
                '%d refused (%s), each payable returned to open so it can go in another run - the '.
                'supplier was never paid, so they are still a creditor',
                \count($rejected),
                implode(', ', array_unique(array_map(
                    static fn (array $r): string => (string) ($r['reason_code'] ?? 'no code'),
                    $rejected,
                ))),
            );
        }

        if ($unmatched !== []) {
            $parts[] = sprintf(
                '%d naming instructions nothing here issued, kept for somebody to look at rather than '.
                'discarded - a file submitted twice, a mangled reference and a report meant for another '.
                'company all arrive looking like this',
                \count($unmatched),
            );
        }

        return sprintf(
            'The bank reported on %d instruction(s) in %s: %s.',
            \count($parsed['statuses']),
            (string) $parsed['original_message_id'] ?: 'that file',
            implode('; ', $parts),
        );
    }
}
