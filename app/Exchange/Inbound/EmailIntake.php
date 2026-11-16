<?php

declare(strict_types=1);

namespace App\Exchange\Inbound;

use App\Support\Attachments\AttachmentRefused;
use App\Support\Attachments\AttachmentStore;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invoices that arrive by email - PF-140.
 *
 * ## Attachments and bodies become inbox items, never bills
 *
 * One item per attachment, plus one for the body where it carries anything.
 * A supplier who types an invoice into the message is doing something
 * irregular and is still owed money, and an intake that only looked at
 * attachments would lose them silently.
 *
 * Nothing here creates a bill. That is {@see BillFromDocument}, which
 * requires a validated document with a matched supplier - and an email from a
 * stranger has neither.
 *
 * ## Every attachment is sniffed, capped and scanned before anything reads it
 *
 * Through {@see AttachmentStore}, which exists for exactly this: the declared
 * name and type come from the sender and are not evidence of anything. A
 * `.pdf` that is a zip, a 900MB "invoice", and a file whose only purpose is
 * to be opened by whatever reads it next are all ordinary email, and the
 * order matters - scanning after parsing is scanning after the damage.
 *
 * An attachment the store refuses is recorded as a quarantined item with the
 * reason, not dropped. A supplier whose file we would not open has to be told
 * something, and "we never received it" is what they will be told otherwise.
 *
 * ## The intake address identifies the entity, and nothing else does
 *
 * `inbox+<entity code>@<domain>`, from configuration. Not the sender, not the
 * subject, not a header: a group with eight entities receives eight
 * suppliers' invoices at eight addresses, and resolving the entity from
 * anything the sender controls means the sender chooses which company's
 * ledger their invoice lands in.
 */
final class EmailIntake
{
    public function __construct(
        private readonly InboundDocuments $documents,
        private readonly AttachmentStore $attachments,
    ) {}

    /**
     * The address a supplier sends to, for this entity.
     *
     * Derived rather than stored, because a stored address is a second
     * answer to "which entity is this": the two disagree the first time
     * somebody renames an entity, and the invoice lands in the ledger the
     * stale row names.
     */
    public function addressFor(Entity $entity): string
    {
        $domain = (string) config('exchange.intake.domain', 'invoices.peakflow.test');
        $mailbox = (string) config('exchange.intake.mailbox', 'inbox');

        return sprintf('%s+%s@%s', $mailbox, strtolower((string) $entity->code), $domain);
    }

    /**
     * The entity an intake address names, or null.
     *
     * Null rather than a default. An email to an address nobody recognises
     * is not an email for the first entity in the table: delivering it there
     * would put one company's invoice in another company's ledger, and the
     * two companies may not even be in the same group.
     */
    public function entityForAddress(string $address): ?Entity
    {
        $domain = (string) config('exchange.intake.domain', 'invoices.peakflow.test');
        $mailbox = (string) config('exchange.intake.mailbox', 'inbox');

        $pattern = sprintf(
            '/^%s\+([A-Za-z0-9_-]+)@%s$/i',
            preg_quote($mailbox, '/'),
            preg_quote($domain, '/'),
        );

        if (preg_match($pattern, trim($address), $matches) !== 1) {
            return null;
        }

        return Entity::query()->whereRaw('lower(code) = ?', [strtolower($matches[1])])->first();
    }

    /**
     * Take a delivered message apart into inbox items.
     *
     * @param  array{from?: string|null, to?: string|null, subject?: string|null, body?: string|null, attachments?: list<array{filename?: string, bytes?: string, content_type?: string|null}>}  $message
     * @return list<InboundDocument>
     *
     * @throws InboundRefused
     */
    public function accept(array $message, ?Carbon $at = null): array
    {
        $to = (string) ($message['to'] ?? '');
        $entity = $this->entityForAddress($to);

        if ($entity === null) {
            throw InboundRefused::because(sprintf(
                'No entity receives mail at %s. An email to an address nobody recognises is not an '.
                'email for the first entity in the table: delivering it there would put one company\'s '.
                'invoice in another company\'s ledger.',
                $to === '' ? '(no address)' : $to,
            ));
        }

        $from = strtolower(trim((string) ($message['from'] ?? '')));
        $subject = $message['subject'] ?? null;
        $at ??= Carbon::now();
        $items = [];

        foreach ($message['attachments'] ?? [] as $index => $attachment) {
            $items[] = $this->attachment($entity, $attachment, $from, $subject, $index, $at);
        }

        $body = trim((string) ($message['body'] ?? ''));

        if ($body !== '') {
            /*
             | The body as an item of its own.
             |
             | A supplier who types an invoice into the message is doing
             | something irregular and is still owed money. It is also
             | frequently the only record of what was agreed - "as discussed,
             | we'll invoice the extra day separately" - which is why PF-140
             | asks for the body to be kept rather than summarised.
             */
            $items[] = $this->documents->receive(
                entity: $entity,
                bytes: $body,
                channel: 'email',
                senderAddress: $from === '' ? null : $from,
                subject: $subject,
                contentType: 'text/plain',
                at: $at,
            );
        }

        if ($items === []) {
            throw InboundRefused::because(
                'The message has no attachments and an empty body, so there is nothing to record. An '.
                'inbox item nobody can action is a row somebody has to close by hand every day.',
            );
        }

        return array_values(array_filter($items));
    }

    /**
     * One attachment, sniffed and scanned before it is read.
     *
     * @param  array{filename?: string, bytes?: string, content_type?: string|null}  $attachment
     */
    private function attachment(
        Entity $entity,
        array $attachment,
        string $from,
        ?string $subject,
        int $index,
        Carbon $at,
    ): ?InboundDocument {
        $bytes = (string) ($attachment['bytes'] ?? '');
        $name = (string) ($attachment['filename'] ?? sprintf('attachment-%d', $index + 1));

        if ($bytes === '') {
            return null;
        }

        /*
         | Recorded first, so a refused attachment still has an item.
         |
         | The store's refusal is the reason, and a refusal with nothing to
         | attach it to is a refusal nobody reads. A supplier whose file we
         | would not open has to be told something, and "we never received
         | it" is what they will be told otherwise.
         */
        $document = $this->documents->receive(
            entity: $entity,
            bytes: $bytes,
            channel: 'email',
            senderAddress: $from === '' ? null : $from,
            subject: $subject,
            contentType: $attachment['content_type'] ?? null,
            at: $at,
        );

        $path = tempnam(sys_get_temp_dir(), 'peakflow-intake-');

        if ($path === false) {
            return $document;
        }

        try {
            file_put_contents($path, $bytes);

            $stored = $this->attachments->attach(
                entityId: (string) $entity->getKey(),
                attachableType: 'inbound_document',
                attachableId: (string) $document->getKey(),
                sourcePath: $path,
                declaredName: $name,
                declaredType: $attachment['content_type'] ?? null,
                note: sprintf('Received by email from %s.', $from === '' ? 'an unidentified sender' : $from),
            );

            $document->update(['content_type' => (string) ($stored['content_type'] ?? $document->content_type)]);
        } catch (AttachmentRefused $e) {
            /*
             | Quarantined with the store's own sentence.
             |
             | Which is the one that says what was wrong: the declared name
             | and the sniffed type disagree, the file is over the cap, the
             | scanner refused it, or no scanner was available. "Rejected"
             | would send the supplier an email nobody can act on.
             */
            $document->update([
                'status' => 'quarantined',
                'note' => $e->getMessage(),
            ]);
        } finally {
            @unlink($path);
        }

        return $document->refresh();
    }

    /**
     * Allowlist a sender for a supplier - PF-140.
     *
     * An address rather than a domain, because a shared mailbox at a large
     * supplier sends from one address and their marketing sends from another
     * at the same domain. A domain allowlist is the broader grant and nobody
     * revisits it.
     */
    public function allowlist(
        Entity $entity,
        string $address,
        ?string $partyId = null,
        string $channel = 'email',
        ?string $userId = null,
        ?string $note = null,
    ): void {
        DB::connection('tenant')->table('inbound_senders')->upsert(
            [[
                'id' => (string) Str::ulid(),
                'entity_id' => (string) $entity->getKey(),
                'channel' => $channel,
                'address' => strtolower(trim($address)),
                'party_id' => $partyId,
                'active' => true,
                'added_by' => $userId,
                'note' => $note,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['entity_id', 'channel', 'address'],
            ['party_id', 'active', 'note', 'updated_at'],
        );
    }
}
