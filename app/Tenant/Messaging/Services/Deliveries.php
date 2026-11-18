<?php

declare(strict_types=1);

namespace App\Tenant\Messaging\Services;

use App\Exchange\Messaging\Contracts\MessageChannel;
use App\Exchange\Messaging\ValueObjects\OutboundMessage;
use App\Support\Audit\AuditTrail;
use App\Tenant\Messaging\Exceptions\MessageRefused;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Delivering a document, and knowing whether it arrived - PF-257.
 *
 * ## Everything is checked BEFORE anything is sent
 *
 * Consent, verification, the template's existence and its placeholder
 * count. Each of those is also refused by the provider - hours later,
 * with a generic error, by which time the invoice is simply not
 * delivered and nobody has connected the two.
 *
 * So the order is: consent, reachability, template, variables, send.
 * The only failure that can arrive after that is a real one.
 *
 * ## A failure never changes the document
 *
 * {@see send()} returns an outcome and records an attempt. It does not
 * touch the document, and the document has no delivery column: an
 * invoice is ISSUED and UNDELIVERED, which are two facts. A product
 * that marked the document would have a state machine where a network
 * error moves an issued invoice - and the first time that happens the
 * hash chain disagrees with the row.
 *
 * ## Accepted, delivered and read are three things
 *
 * The provider accepting a message means it is queued. The delivery and
 * the read arrive later, as their own records. A product that collapsed
 * them would answer "we sent it" to a question about whether somebody
 * saw it, which is the answer that ends an argument badly.
 */
final class Deliveries
{
    public function __construct(
        private readonly MessageChannel $channel,
        private readonly Consents $consents,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Send a document to a party on this channel.
     *
     * @param  list<string>  $variables
     * @param  int  $attempt  numbered by the caller: a retry passes the number
     *     it used and gets the original answer, a chase passes the next one.
     *     There is nothing here to derive it from - see the note inside.
     * @return array<string, mixed>
     *
     * @throws MessageRefused
     */
    public function send(
        string $entityId,
        string $documentType,
        string $documentId,
        string $partyId,
        string $template,
        string $language,
        array $variables,
        int $attempt,
        ?string $documentUrl = null,
    ): array {
        $address = $this->consents->addressFor($entityId, $partyId, $this->channel->key());

        $this->assertTheTemplateIsApproved($template, $language, \count($variables));

        if ($attempt < 1) {
            throw MessageRefused::because(
                'An attempt is numbered from one by whoever is sending it. The number is what tells a '.
                'retry from a chase, and there is nothing to derive it from.',
            );
        }

        /*
         | The attempt number comes from the CALLER - PF-257.
         |
         | The first draft derived it by counting the rows already there,
         | and the bite sweep showed what that costs: a caller whose
         | request to the provider timed out retries, the count now
         | includes the row it wrote, the number becomes 2, the reference
         | changes, and the customer receives a second copy of one
         | invoice. Which is the exact duplicate this reference exists to
         | prevent.
         |
         | A derived number cannot tell a retry from a chase, because
         | they look identical from here: one is the same send happening
         | again and the other is a person deliberately sending it again.
         | Only the caller knows which, and it is the same lesson PF-255
         | records about a client id - a network that drops a response is
         | indistinguishable from one that drops a request.
         |
         | So: a retry passes the number it used, reaches the row below,
         | and gets the original answer back. A chase passes the next
         | number and is a second attempt, visible as one in the history.
         */
        $reference = sprintf('%s:%s:%d', $documentType, $documentId, $attempt);

        $existing = DB::connection('tenant')->table('messaging_attempts')
            ->where('reference', $reference)
            ->first();

        if ($existing !== null) {
            return $this->replay($existing);
        }

        $result = $this->channel->send(new OutboundMessage(
            to: $address,
            template: $template,
            language: $language,
            variables: $variables,
            reference: $reference,
            documentUrl: $documentUrl,
        ));

        $id = (string) Str::ulid();

        DB::connection('tenant')->table('messaging_attempts')->insert([
            'id' => $id,
            'entity_id' => $entityId,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'party_id' => $partyId,
            'channel' => $this->channel->key(),
            'address' => $address,
            'template' => $template,
            'language' => $language,
            'reference' => $reference,
            'attempt' => $attempt,
            'state' => $result->accepted ? 'accepted' : 'failed',
            'provider_message_id' => $result->providerMessageId,
            'failure_code' => $result->failureCode,
            'outcome' => $result->why,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'messaging',
            action: $result->accepted ? 'document.sent' : 'document.send_failed',
            subjectType: $documentType,
            subjectId: $documentId,
            subjectLabel: sprintf('%s to %s, attempt %d', $this->channel->key(), $address, $attempt),
            after: [
                'state' => $result->accepted ? 'accepted' : 'failed',
                'failure_code' => $result->failureCode,
                'provider_message_id' => $result->providerMessageId,
            ],
            reason: $result->why,
            entityId: $entityId,
            partyId: $partyId,
        );

        return [
            'id' => $id,
            'attempt' => $attempt,
            'state' => $result->accepted ? 'accepted' : 'failed',
            'failure_code' => $result->failureCode,
            /*
             | Said explicitly, because this is the sentence somebody
             | reads on a screen after a failed send and the wrong
             | reading of it is "the invoice did not go out".
             */
            'why' => sprintf(
                '%s The document is unchanged: it is issued, and %s.',
                $result->why,
                $result->accepted ? 'now queued with the provider' : 'undelivered',
            ),
        ];
    }

    /**
     * The provider says it arrived.
     *
     * @throws MessageRefused
     */
    public function markDelivered(string $providerMessageId, Carbon $at): void
    {
        $this->advance($providerMessageId, 'delivered', ['delivered_at' => $at->toDateTimeString()]);
    }

    /**
     * The provider says somebody opened it.
     *
     * Which is not the same as the provider accepting it, and that
     * distinction is the whole point of having three states.
     *
     * @throws MessageRefused
     */
    public function markRead(string $providerMessageId, Carbon $at): void
    {
        $attempt = $this->attemptOf($providerMessageId);

        /*
         | A read implies a delivery, and the delivery notice can arrive
         | AFTER the read one - providers do not promise an order.
         |
         | So a read with no delivery recorded sets both, dating the
         | delivery at the read. A product that refused it would drop
         | the strongest evidence it has that the customer saw the
         | invoice, for the sake of a sequence the provider never
         | guaranteed.
         */
        $delivered = $attempt->delivered_at === null
            ? $at->toDateTimeString()
            : (string) $attempt->delivered_at;

        $this->advance($providerMessageId, 'read', [
            'delivered_at' => $delivered,
            'read_at' => $at->toDateTimeString(),
        ]);
    }

    /** @throws MessageRefused */
    public function markFailed(string $providerMessageId, string $code, string $why): void
    {
        $this->advance($providerMessageId, 'failed', [
            'failure_code' => $code,
            'provider_message_id' => null,
            'outcome' => $why,
        ]);
    }

    /**
     * Every attempt against one document, oldest first.
     *
     * Which is what answers "did they get the invoice": the list, with
     * what the provider said at each step. A single "delivered" flag
     * answers it only when the answer is yes.
     *
     * @return list<array<string, mixed>>
     */
    public function historyFor(string $entityId, string $documentType, string $documentId): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT a.channel,
                   a.address,
                   a.template,
                   a.language,
                   a.attempt,
                   a.state,
                   a.failure_code,
                   a.outcome,
                   a.provider_message_id,
                   a.sent_at,
                   a.delivered_at,
                   a.read_at
            FROM messaging_attempts a
            WHERE a.entity_id = ?
              AND a.document_type = ?
              AND a.document_id = ?
            ORDER BY a.attempt
        SQL, [$entityId, $documentType, $documentId]);

        return array_map(static fn (object $row): array => [
            'channel' => (string) $row->channel,
            'address' => (string) $row->address,
            'template' => $row->template === null ? null : (string) $row->template,
            'language' => $row->language === null ? null : (string) $row->language,
            'attempt' => (int) $row->attempt,
            'state' => (string) $row->state,
            'failure_code' => $row->failure_code === null ? null : (string) $row->failure_code,
            'outcome' => (string) $row->outcome,
            'provider_message_id' => $row->provider_message_id === null ? null : (string) $row->provider_message_id,
            'sent_at' => (string) $row->sent_at,
            'delivered_at' => $row->delivered_at === null ? null : (string) $row->delivered_at,
            'read_at' => $row->read_at === null ? null : (string) $row->read_at,
        ], $rows);
    }

    /**
     * Refuse a template the provider has not approved.
     *
     * Before sending, which is the criterion. The provider refuses it
     * too, with a generic error, hours later.
     *
     * @throws MessageRefused
     */
    private function assertTheTemplateIsApproved(string $template, string $language, int $given): void
    {
        $approved = $this->channel->approvedTemplates();

        foreach ($approved as $candidate) {
            if ($candidate->name !== $template || $candidate->language !== $language) {
                continue;
            }

            if ($candidate->placeholders() !== $given) {
                throw MessageRefused::theWrongNumberOfVariables(
                    $candidate->key(),
                    $candidate->placeholders(),
                    $given,
                );
            }

            return;
        }

        throw MessageRefused::anUnapprovedTemplate(
            $template,
            $language,
            array_map(static fn (object $t): string => (string) $t->key(), $approved),
        );
    }

    /**
     * @param  array<string, mixed>  $columns
     *
     * @throws MessageRefused
     */
    private function advance(string $providerMessageId, string $state, array $columns): void
    {
        $attempt = $this->attemptOf($providerMessageId);

        DB::connection('tenant')->table('messaging_attempts')
            ->where('id', (string) $attempt->id)
            ->update(array_merge($columns, [
                'state' => $state,
                'updated_at' => now(),
            ]));
    }

    /** @throws MessageRefused */
    private function attemptOf(string $providerMessageId): object
    {
        $attempt = DB::connection('tenant')->table('messaging_attempts')
            ->where('provider_message_id', $providerMessageId)
            ->first();

        if ($attempt === null) {
            throw MessageRefused::because(sprintf(
                'No attempt was sent with the provider id %s. A status notice for a message this '.
                'product did not send is either another system\'s or a replay, and recording it would '.
                'invent a delivery.',
                $providerMessageId,
            ));
        }

        return $attempt;
    }

    /** @return array<string, mixed> */
    private function replay(object $existing): array
    {
        return [
            'id' => (string) $existing->id,
            'attempt' => (int) $existing->attempt,
            'state' => (string) $existing->state,
            'failure_code' => $existing->failure_code === null ? null : (string) $existing->failure_code,
            'why' => sprintf(
                'This attempt was already made and is %s: %s. The same answer comes back rather than a '.
                'second message - a provider charges per message, and a customer who gets two copies '.
                'of one invoice telephones about it.',
                (string) $existing->state,
                (string) $existing->outcome,
            ),
        ];
    }
}
