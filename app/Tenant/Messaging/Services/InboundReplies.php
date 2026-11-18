<?php

declare(strict_types=1);

namespace App\Tenant\Messaging\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Messaging\Exceptions\MessageRefused;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What came back, and somewhere a person sees it - PF-257.
 *
 * ## A channel that only sends is one customers learn to ignore
 *
 * They reply to the invoice. If the reply goes nowhere, the next
 * invoice is read as a message from a machine that cannot be answered -
 * and the question they wanted to ask becomes a telephone call, or
 * nothing.
 *
 * So a reply is a row, unread until somebody reads it, and {@see
 * unread()} is the list a person works through.
 *
 * ## The party is resolved only where the number is KNOWN
 *
 * Guessing which customer a number belongs to is how a reply is read by
 * the wrong account manager - who then answers a question about
 * somebody else's invoice, to somebody who is not that customer.
 *
 * An unresolved reply is not a problem to be solved by inference. It is
 * a reply from a number nobody has on record, which is itself worth
 * seeing: it is usually a wrong number, which means an invoice went
 * somewhere it should not have.
 */
final class InboundReplies
{
    public function __construct(
        private readonly Consents $consents,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Record something that arrived.
     *
     * @return array<string, mixed>
     *
     * @throws MessageRefused
     */
    public function receive(
        string $entityId,
        string $channel,
        string $address,
        string $body,
        string $providerMessageId,
        Carbon $at,
    ): array {
        if (trim($body) === '') {
            throw MessageRefused::because(
                'An empty reply is not a reply. Providers send status notices and delivery receipts '.
                'through the same hook, and recording them as messages fills the list a person works '.
                'through with nothing.',
            );
        }

        /*
         | The provider's id is unique, so a redelivered hook is one
         | reply.
         |
         | Providers retry a webhook until it is acknowledged, and a
         | product without this shows the same question four times -
         | which is how a person stops reading the list.
         */
        $existing = DB::connection('tenant')->table('messaging_inbound')
            ->where('provider_message_id', $providerMessageId)
            ->first();

        if ($existing !== null) {
            return [
                'id' => (string) $existing->id,
                'party_id' => $existing->party_id === null ? null : (string) $existing->party_id,
                'why' => 'This reply has already been recorded. A provider retries a webhook until it '.
                    'is acknowledged, and showing the same question four times is how a person stops '.
                    'reading the list.',
            ];
        }

        $party = $this->consents->partyAt($entityId, $channel, $address);
        $id = (string) Str::ulid();

        DB::connection('tenant')->table('messaging_inbound')->insert([
            'id' => $id,
            'entity_id' => $entityId,
            'channel' => $channel,
            'address' => $address,
            'party_id' => $party,
            'body' => $body,
            'provider_message_id' => $providerMessageId,
            'received_at' => $at->toDateTimeString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $id,
            'party_id' => $party,
            'why' => $party === null
                ? sprintf(
                    'Recorded from %s, which is not a number on record for anybody. That is worth '.
                    'seeing rather than guessing at: a reply from an unknown number usually means an '.
                    'invoice went somewhere it should not have.',
                    $address,
                )
                : 'Recorded and resolved to the party whose number it is.',
        ];
    }

    /**
     * Replies nobody has read.
     *
     * @return list<array<string, mixed>>
     */
    public function unread(string $entityId): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT i.id,
                   i.channel,
                   i.address,
                   i.body,
                   i.received_at,
                   coalesce(p.trading_name, p.legal_name) AS party_name,
                   /*
                    | The document the last attempt to this number was
                    | about, so a reply reads as an answer to something.
                    |
                    | "Is this the March one?" means nothing on its own
                    | and everything beside the invoice it followed.
                    */
                   (
                     SELECT a.document_type || ' ' || a.document_id
                     FROM messaging_attempts a
                     WHERE a.entity_id = i.entity_id
                       AND a.address = i.address
                       AND a.sent_at <= i.received_at
                     ORDER BY a.sent_at DESC
                     LIMIT 1
                   ) AS about
            FROM messaging_inbound i
            LEFT JOIN parties p ON p.id = i.party_id
            WHERE i.entity_id = ?
              AND i.read_at IS NULL
            ORDER BY i.received_at
        SQL, [$entityId]);

        return array_map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'channel' => (string) $row->channel,
            'address' => (string) $row->address,
            'from' => $row->party_name === null ? null : (string) $row->party_name,
            'body' => (string) $row->body,
            'received_at' => (string) $row->received_at,
            'about' => $row->about === null ? null : (string) $row->about,
        ], $rows);
    }

    /** @throws MessageRefused */
    public function markRead(string $replyId, string $userId): void
    {
        $reply = DB::connection('tenant')->table('messaging_inbound')->where('id', $replyId)->first();

        if ($reply === null) {
            throw MessageRefused::because('There is no reply with that id.');
        }

        if ($reply->read_at !== null) {
            return;
        }

        DB::connection('tenant')->table('messaging_inbound')->where('id', $replyId)->update([
            'read_at' => now(),
            'read_by' => $userId,
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'messaging',
            action: 'reply.read',
            subjectType: 'messaging_inbound',
            subjectId: $replyId,
            subjectLabel: (string) $reply->address,
            after: ['read_by' => $userId],
            entityId: (string) $reply->entity_id,
            partyId: $reply->party_id === null ? null : (string) $reply->party_id,
        );
    }
}
