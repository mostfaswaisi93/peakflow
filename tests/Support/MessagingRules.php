<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exchange\Messaging\Contracts\MessageChannel;
use App\Exchange\Messaging\Providers\FakeChannel;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Messaging\Services\Consents;
use App\Tenant\Messaging\Services\Deliveries;
use App\Tenant\Messaging\Services\InboundReplies;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivering a document to somebody who agreed to receive it - PF-257.
 *
 * ## What a check here is for
 *
 * **An invoice sent to a wrong number.** It carries a name and an
 * amount, and the person who receives it has no relationship with the
 * business - which makes it a breach with a timestamp, and the
 * timestamp is ours. Consent, and separately verification, are what
 * stand in front of that.
 *
 * **"Did they get it?" answered with a guess.** A single delivered flag
 * answers the question only when the answer is yes. So the attempts are
 * rows, the states are three and not one, and they move forward only.
 *
 * **A delivery failure that moves an issued document.** An invoice is
 * ISSUED and UNDELIVERED, which are two facts. A product that marked
 * the document would have a network error changing a hash-chained row.
 */
final class MessagingRules
{
    use Concerns\IssuesInvoices;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['messaging (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->runEachInASavepoint([
                // ---- Consent, before anything is sent ------------------
                'nothing is sent without consent on record' => fn (): array => $this->consentComesFirst(),
                'consent says how it was given, not merely that it was' => fn (): array => $this->consentSaysHow(),
                'consent is not reachability: a number is verified too' => fn (): array => $this->verificationIsSeparate(),
                'a withdrawn consent stops sending and stays on record' => fn (): array => $this->withdrawalIsADate(),
                'one number has one spelling' => fn (): array => $this->numbersAreNormalised(),

                // ---- The template --------------------------------------
                'an unapproved template is refused before sending' => fn (): array => $this->unapprovedTemplatesAreRefused(),
                'a placeholder count that does not match is refused' => fn (): array => $this->placeholderCountsMatch(),

                // ---- The attempts --------------------------------------
                'every attempt is a row with its outcome' => fn (): array => $this->attemptsAreRecorded(),
                'accepted, delivered and read are three things' => fn (): array => $this->threeStatesNotOne(),
                'a delivery state only moves forward' => fn (): array => $this->statesMoveForward(),
                'a retry of one attempt is one message' => fn (): array => $this->retryingIsOneMessage(),
                'a failure records its code, and the document is unchanged' => fn (): array => $this->failuresLeaveTheDocumentAlone(),
                'an attempt cannot be edited or removed' => fn (): array => $this->attemptsAreFacts(),

                // ---- Inbound -------------------------------------------
                'a reply lands somewhere a person sees it' => fn (): array => $this->repliesAreSeen(),
                'a reply from an unknown number is not guessed at' => fn (): array => $this->unknownNumbersStayUnresolved(),
                'a redelivered webhook is one reply' => fn (): array => $this->webhooksAreIdempotent(),

                // ---- The vendor boundary -------------------------------
                'the provider is behind an interface with a fake' => fn (): array => $this->theVendorIsBehindAnInterface(),

                // ---- Email delivery - PF-104 ---------------------------
                'email is a channel, not a second sending path' => fn (): array => $this->emailIsAChannel(),
                'a bounced address is refused next time, with the reason' => fn (): array => $this->aBounceStopsTheSending(),
                'a template carries both languages, and the recipient picks' => fn (): array => $this->templatesCarryBothLanguages(),
            ]);
        } catch (Throwable $e) {
            return ['messaging could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    // ---- Consent ----------------------------------------------------------

    /** @return list<string> */
    private function consentComesFirst(): array
    {
        $invoice = $this->issue();
        $failures = [];

        $refusal = $this->refusal(fn () => $this->deliveries()->send(
            entityId: $this->entityId,
            documentType: 'invoice',
            documentId: (string) $invoice->getKey(),
            partyId: (string) $this->party?->getKey(),
            template: 'invoice_issued',
            language: 'ar',
            variables: ['A customer', 'INV-1', '1,150.00', '2026-06-18'],
            attempt: 1,
        ));

        if ($refusal === null) {
            $failures[] = 'an invoice was sent to a party with no consent on record - it carries a name '.
                'and an amount, and the person at the wrong number has no relationship with this '.
                'business';
        } elseif (! str_contains($refusal, 'breach with a date')) {
            $failures[] = 'the refusal does not say what the risk is: '.$refusal;
        }

        /*
         | And nothing was recorded as an attempt.
         |
         | A product that logged the attempt and then refused would make
         | the delivery history say something was tried, which is the
         | opposite of what happened.
         */
        $attempts = (int) DB::connection('tenant')->table('messaging_attempts')
            ->where('document_id', (string) $invoice->getKey())
            ->count();

        if ($attempts !== 0) {
            $failures[] = 'a refused send left an attempt on the record, so the history says something '.
                'was tried when nothing was';
        }

        return $failures;
    }

    /** @return list<string> */
    private function consentSaysHow(): array
    {
        $consents = app(Consents::class);
        $failures = [];

        $thin = $this->refusal(fn () => $consents->record(
            entityId: $this->entityId,
            partyId: (string) $this->party?->getKey(),
            channel: 'whatsapp',
            address: '+966501234567',
            basis: 'agreed',
            userId: $this->actorId(),
        ));

        if ($thin === null) {
            $failures[] = 'consent was recorded with "agreed" as its basis - a regulator asking about '.
                'consent is asking how it was given, and a boolean answers none of it';
        } elseif (! str_contains($thin, 'a boolean answers none of it')) {
            /*
             | And the sentence is the service's, not the column's.
             |
             | The constraint refuses a short basis too, which is the
             | control - but "violates check constraint
             | messaging_consents_say_how_it_was_given" is read by
             | whoever is setting a customer up, and it tells them
             | nothing about what to type.
             */
            $failures[] = 'a thin consent basis is refused by the column rather than explained by the '.
                'service: '.$thin;
        }

        /*
         | And the column refuses it too, for the import that does not
         | call this service.
         */
        $direct = $this->refusal(fn () => DB::connection('tenant')->table('messaging_consents')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'party_id' => (string) $this->party?->getKey(),
            'channel' => 'whatsapp',
            'address' => '+966501234568',
            'consented_at' => now(),
            'consent_basis' => 'yes',
            'recorded_by' => $this->actorId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($direct === null) {
            $failures[] = 'the database accepted a consent with no account of how it was given';
        }

        $recorded = $consents->record(
            entityId: $this->entityId,
            partyId: (string) $this->party?->getKey(),
            channel: 'whatsapp',
            address: '+966501234567',
            basis: 'ticked the box on the customer portal when the account was opened',
            userId: $this->actorId(),
        );

        $row = DB::connection('tenant')->table('messaging_consents')
            ->where('id', (string) $recorded['id'])
            ->first();

        if (($row->consented_at ?? null) === null) {
            $failures[] = 'the consent carries no date, which is the half of it a complaint turns on';
        }

        if (! str_contains((string) ($row->consent_basis ?? ''), 'customer portal')) {
            $failures[] = 'the consent does not carry how it was given';
        }

        return $failures;
    }

    /** @return list<string> */
    private function verificationIsSeparate(): array
    {
        $invoice = $this->issue();
        $consent = $this->aConsent();
        $failures = [];

        /*
         | Consent given, number never verified.
         |
         | A customer can agree and give a number with a digit missing,
         | and that digit belongs to somebody else.
         */
        $refusal = $this->refusal(fn () => $this->sendTo($invoice));

        if ($refusal === null) {
            $failures[] = 'an invoice went to a number that has consent and was never verified - a '.
                'customer can agree and give a number with a digit missing';
        } elseif (! str_contains($refusal, 'digit missing')) {
            $failures[] = 'the refusal does not say why verification is separate from consent: '.$refusal;
        }

        $short = $this->refusal(fn () => app(Consents::class)->verify($consent, '12'));

        if ($short === null) {
            $failures[] = 'a number was verified with a two-character code, so the verification is '.
                'evidence of nothing';
        }

        app(Consents::class)->verify($consent, '483915');
        $this->channel()->makeReachable('+966501234567');

        if ($this->refusal(fn () => $this->sendTo($invoice)) !== null) {
            $failures[] = 'a verified number with consent still could not be sent to';
        }

        return $failures;
    }

    /** @return list<string> */
    private function withdrawalIsADate(): array
    {
        $invoice = $this->issue();
        $consent = $this->aVerifiedConsent();
        $consents = app(Consents::class);
        $failures = [];

        $silent = $this->refusal(fn () => $consents->withdraw($consent, 'stop', $this->actorId()));

        if ($silent === null) {
            $failures[] = 'consent was withdrawn with "stop" as the reason - the customer asking, the '.
                'number failing and a complaint lead to three different conversations later';
        } elseif (! str_contains($silent, 'lead to different conversations')) {
            $failures[] = 'a reasonless withdrawal is refused by the column rather than explained by '.
                'the service: '.$silent;
        }

        $consents->withdraw($consent, 'the customer asked us to stop on the telephone', $this->actorId());

        $refusal = $this->refusal(fn () => $this->sendTo($invoice));

        if ($refusal === null) {
            $failures[] = 'an invoice was sent after consent was withdrawn';
        }

        /*
         | And the row is still there.
         |
         | "Did we have consent in March" is asked after a complaint, and
         | a deleted row answers it with silence.
         */
        $row = DB::connection('tenant')->table('messaging_consents')->where('id', $consent)->first();

        if ($row === null) {
            $failures[] = 'the consent row was deleted rather than dated, so "did we have consent in '.
                'March" cannot be answered';
        } elseif (($row->consented_at ?? null) === null || ($row->withdrawn_at ?? null) === null) {
            $failures[] = 'the row does not carry both dates, so the window consent covered is unknown';
        }

        return $failures;
    }

    /** @return list<string> */
    private function numbersAreNormalised(): array
    {
        $consents = app(Consents::class);
        $failures = [];

        /*
         | Three spellings of one number.
         |
         | A product that stored what somebody typed would have consent
         | for one and refuse the next - and would fail to match the
         | reply when it arrived.
         */
        foreach (['+966 50 123 4567', '00966501234567'] as $typed) {
            $recorded = $consents->record(
                entityId: $this->entityId,
                partyId: (string) $this->party?->getKey(),
                channel: 'whatsapp',
                address: $typed,
                basis: 'ticked the box on the customer portal when the account was opened',
                userId: $this->actorId(),
            );

            if ((string) $recorded['address'] !== '+966501234567') {
                $failures[] = sprintf(
                    '"%s" was stored as %s rather than +966501234567, so one number has two spellings '.
                    'and a reply from it matches neither',
                    $typed,
                    (string) $recorded['address'],
                );
            }
        }

        /*
         | And a number with no country code is refused, not guessed.
         |
         | Guessing the country is how an invoice reaches the same digits
         | somewhere else.
         */
        $local = $this->refusal(fn () => $consents->record(
            entityId: $this->entityId,
            partyId: (string) $this->party?->getKey(),
            channel: 'whatsapp',
            address: '0501234567',
            basis: 'ticked the box on the customer portal when the account was opened',
            userId: $this->actorId(),
        ));

        if ($local === null) {
            $failures[] = 'a number with no country code was accepted, and the country was guessed - '.
                'which is how an invoice reaches the same digits in another one';
        }

        return $failures;
    }

    // ---- Templates --------------------------------------------------------

    /** @return list<string> */
    /**
     * Email implements the same contract, and is reached the same way - PF-104.
     *
     * ## What this is really asserting
     *
     * Not that an email can be sent - that `Mail` can send mail is
     * Laravel's problem. That the email channel is reached through
     * `MessageChannel`, so the attempt numbering, the duplicate
     * reference, the consent check and the delivery log are the SAME
     * code for both channels.
     *
     * A second sending path for email would have its own answers to all
     * four, and the first time they differed would be a customer who got
     * two copies of one invoice because only one path deduplicated.
     *
     * @return list<string>
     */
    private function emailIsAChannel(): array
    {
        $channel = new \App\Exchange\Messaging\Providers\EmailChannel();
        $failures = [];

        if (! $channel instanceof \App\Exchange\Messaging\Contracts\MessageChannel) {
            $failures[] = 'the email channel does not implement MessageChannel, so it is a second '.
                'sending path with its own answers to consent, attempts and deduplication';
        }

        if ($channel->key() !== 'email') {
            $failures[] = sprintf('the email channel calls itself "%s", so a delivery log cannot say '.
                'which channel an attempt was on', $channel->key());
        }

        $templates = $channel->approvedTemplates();

        if ($templates === []) {
            $failures[] = 'the email channel approves no templates, so every send through it is refused '.
                'by the same guard that refuses an unapproved WhatsApp template - which is correct '.
                'behaviour for an empty configuration and useless as a channel';
        }

        /*
         | A malformed address is not reachable.
         |
         | The same question WhatsApp answers about a number. Asking it
         | here is what stops a typo becoming a bounce, which is cheaper
         | than suppressing the address afterwards.
         */
        if ($channel->isReachable('not-an-address')) {
            $failures[] = 'the email channel reports a malformed address as reachable, so a typo becomes '.
                'a bounce rather than a refusal - and a bounce costs the sending domain something a '.
                'refusal does not';
        }

        return $failures;
    }

    /**
     * A bounce stops the sending and says why - PF-104.
     *
     * ## "Rather than silently retrying forever"
     *
     * A hard bounce means the mailbox does not exist. Retrying does not
     * eventually work; it burns the sending domain's reputation, and the
     * business discovers that when the invoices with the RIGHT addresses
     * stop arriving. Silent and ever-widening is the worst shape a
     * failure has.
     *
     * So the next attempt is refused, and the refusal names the address.
     *
     * @return list<string>
     */
    private function aBounceStopsTheSending(): array
    {
        $suppressions = app(\App\Tenant\Messaging\Services\Suppressions::class);
        $channel = new \App\Exchange\Messaging\Providers\EmailChannel();
        $address = 'ap-'.bin2hex(random_bytes(3)).'@showcase.test';
        $failures = [];

        if (! $channel->isReachable($address)) {
            return ['a fresh address was already unreachable, so this check cannot tell a suppression '.
                'from the starting state'];
        }

        $suppressions->suppress($this->entityId, $address, 'bounce', 'mailbox unavailable');

        if ($channel->isReachable($address)) {
            $failures[] = 'an address that bounced is still reported as reachable, so the next attempt '.
                'sends to it again. A hard bounce retried is how a sending domain loses the reputation '.
                'that delivers the invoices whose addresses are right';
        }

        $result = $channel->send(new \App\Exchange\Messaging\ValueObjects\OutboundMessage(
            to: $address,
            template: 'invoice_issued',
            language: 'ar',
            variables: ['INV-1', 'Showcase', '100', '2026-12-01'],
            reference: 'probe-'.bin2hex(random_bytes(3)),
        ));

        if ($result->accepted) {
            $failures[] = 'a send to a suppressed address was accepted';
        }

        if (! str_contains($result->why, $address)) {
            $failures[] = sprintf(
                'the refusal does not name the address (%s). "Surfaces it" means somebody reading the '.
                'delivery log can see WHICH address stopped, not that something did',
                $result->why,
            );
        }

        /*
         | And the reason is kept, because the remedies differ.
         |
         | A bounce is fixed by correcting the address; a complaint is
         | fixed by asking the customer whether they want these at all,
         | and certainly not by sending from a different address.
         */
        if ($suppressions->reasonFor($address) !== 'bounce') {
            $failures[] = 'the suppression does not record that it was a bounce, so nobody can tell it '.
                'from a complaint - and the two have opposite remedies';
        }

        $suppressions->release($address);

        if (! $channel->isReachable($address)) {
            $failures[] = 'releasing a suppression did not make the address reachable again, so a '.
                'corrected address stays blocked for ever';
        }

        return $failures;
    }

    /**
     * Rendered in the RECIPIENT's language - PF-104.
     *
     * A customer who reads Arabic should not receive an English invoice
     * because the person who issued it had English on. So every template
     * carries both, and the check is that it does - a channel with one
     * language is a channel that silently sends the wrong one.
     *
     * @return list<string>
     */
    private function templatesCarryBothLanguages(): array
    {
        $channel = new \App\Exchange\Messaging\Providers\EmailChannel();
        $byName = [];

        foreach ($channel->approvedTemplates() as $template) {
            $byName[$template->name][] = $template->language;
        }

        if ($byName === []) {
            return ['no email template is approved, so this check asserted nothing'];
        }

        $failures = [];

        foreach ($byName as $name => $languages) {
            foreach (['ar', 'en'] as $wanted) {
                if (! \in_array($wanted, $languages, true)) {
                    $failures[] = sprintf(
                        'the email template "%s" has no %s version, so a customer who reads it receives '.
                        'the other language - which is the issuer\'s, not theirs',
                        $name,
                        $wanted,
                    );
                }
            }
        }

        return $failures;
    }

    private function unapprovedTemplatesAreRefused(): array
    {
        $invoice = $this->issue();
        $this->aVerifiedConsent();
        $failures = [];

        $refusal = $this->refusal(fn () => $this->deliveries()->send(
            entityId: $this->entityId,
            documentType: 'invoice',
            documentId: (string) $invoice->getKey(),
            partyId: (string) $this->party?->getKey(),
            template: 'invoice_reminder_v3',
            language: 'ar',
            variables: ['A customer'],
            attempt: 1,
        ));

        if ($refusal === null) {
            return ['a template the provider has not approved was sent anyway - which it refuses with '.
                'a generic error hours later, by which time the invoice is simply not delivered'];
        }

        if (! str_contains($refusal, 'before anything is sent')) {
            $failures[] = 'the refusal does not say that it was caught before sending: '.$refusal;
        }

        /*
         | And it names what IS approved.
         |
         | A refusal that only said no sends somebody to the provider's
         | console to compare lists by eye.
         */
        if (! str_contains($refusal, 'invoice_issued')) {
            $failures[] = 'the refusal does not name the approved templates, so somebody has to go and '.
                'compare lists by eye: '.$refusal;
        }

        /*
         | And nothing reached the provider.
         */
        if ($this->channel()->sent() !== []) {
            $failures[] = 'the message reached the provider before the template was checked, which is '.
                'the whole thing this is meant to prevent';
        }

        return $failures;
    }

    /** @return list<string> */
    private function placeholderCountsMatch(): array
    {
        $invoice = $this->issue();
        $this->aVerifiedConsent();
        $failures = [];

        /*
         | Three values into a four-placeholder template.
         |
         | Which renders a literal {{4}} in the message the customer
         | reads - and the provider accepts it, because the template is
         | approved.
         */
        $refusal = $this->refusal(fn () => $this->deliveries()->send(
            entityId: $this->entityId,
            documentType: 'invoice',
            documentId: (string) $invoice->getKey(),
            partyId: (string) $this->party?->getKey(),
            template: 'invoice_issued',
            language: 'ar',
            variables: ['A customer', 'INV-1', '1,150.00'],
            attempt: 1,
        ));

        if ($refusal === null) {
            $failures[] = 'three values were sent to a four-placeholder template, which renders a '.
                'literal {{4}} that the customer reads';
        } elseif (! str_contains($refusal, '{{n}}')) {
            $failures[] = 'the refusal does not say what the customer would see: '.$refusal;
        }

        if ($this->channel()->sent() !== []) {
            $failures[] = 'the message reached the provider before the placeholders were counted';
        }

        return $failures;
    }

    // ---- Attempts ---------------------------------------------------------

    /** @return list<string> */
    private function attemptsAreRecorded(): array
    {
        $invoice = $this->issue();
        $this->aVerifiedConsent();

        $outcome = $this->sendTo($invoice);
        $failures = [];

        $history = $this->deliveries()->historyFor($this->entityId, 'invoice', (string) $invoice->getKey());

        if (\count($history) !== 1) {
            return [sprintf('one send produced %d attempt(s) in the history', \count($history))];
        }

        $row = $history[0];

        foreach (['channel', 'address', 'template', 'language', 'state', 'outcome', 'provider_message_id', 'sent_at'] as $field) {
            if (trim((string) ($row[$field] ?? '')) === '') {
                $failures[] = sprintf('the attempt carries no %s', $field);
            }
        }

        if (($row['state'] ?? '') !== 'accepted') {
            $failures[] = sprintf('a successful send is recorded as %s', (string) ($row['state'] ?? '-'));
        }

        /*
         | Accepted is not delivered, and the record says so.
         |
         | A product that recorded "sent" and stopped would answer "did
         | they get it" with "we sent it", which is the answer that ends
         | an argument badly.
         */
        if (($row['delivered_at'] ?? null) !== null) {
            $failures[] = 'the attempt claims a delivery the provider has not reported yet';
        }

        if (! str_contains((string) ($outcome['why'] ?? ''), 'Accepted is not delivered')) {
            $failures[] = 'the answer does not say that acceptance is not delivery';
        }

        return $failures;
    }

    /** @return list<string> */
    private function threeStatesNotOne(): array
    {
        $invoice = $this->issue();
        $this->aVerifiedConsent();
        $this->sendTo($invoice);

        $deliveries = $this->deliveries();
        $id = (string) $this->channel()->sent()[0]['id'];
        $failures = [];

        $deliveries->markDelivered($id, Carbon::parse('2026-05-18 10:00:00'));
        $after = $this->deliveries()->historyFor($this->entityId, 'invoice', (string) $invoice->getKey())[0];

        if (($after['state'] ?? '') !== 'delivered' || ($after['read_at'] ?? null) !== null) {
            $failures[] = 'a delivery notice was treated as a read, so "did they read it" is answered '.
                'from a column the provider set because it queued the message';
        }

        $deliveries->markRead($id, Carbon::parse('2026-05-18 10:05:00'));
        $read = $this->deliveries()->historyFor($this->entityId, 'invoice', (string) $invoice->getKey())[0];

        if (($read['state'] ?? '') !== 'read' || ($read['read_at'] ?? null) === null) {
            $failures[] = 'a read notice did not record the read';
        }

        /*
         | And a read with no delivery on record sets both.
         |
         | Providers do not promise an order, and refusing the read
         | would drop the strongest evidence that the customer saw the
         | invoice for the sake of a sequence nobody guaranteed.
         */
        $second = $this->issue();
        $this->sendTo($second);
        $secondId = (string) $this->channel()->sent()[1]['id'];

        $deliveries->markRead($secondId, Carbon::parse('2026-05-18 11:00:00'));
        $outOfOrder = $this->deliveries()->historyFor($this->entityId, 'invoice', (string) $second->getKey())[0];

        if (($outOfOrder['state'] ?? '') !== 'read' || ($outOfOrder['delivered_at'] ?? null) === null) {
            $failures[] = 'a read that arrived before its delivery notice was lost, and providers do '.
                'not promise an order';
        }

        return $failures;
    }

    /** @return list<string> */
    private function statesMoveForward(): array
    {
        $invoice = $this->issue();
        $this->aVerifiedConsent();
        $this->sendTo($invoice);

        $id = (string) $this->channel()->sent()[0]['id'];
        $deliveries = $this->deliveries();

        $deliveries->markDelivered($id, Carbon::parse('2026-05-18 10:00:00'));
        $deliveries->markRead($id, Carbon::parse('2026-05-18 10:05:00'));

        $row = (string) DB::connection('tenant')->table('messaging_attempts')
            ->where('provider_message_id', $id)
            ->value('id');

        $failures = [];

        foreach ([
            'back to delivered' => ['state' => 'delivered'],
            'back to accepted' => ['state' => 'accepted'],
            'to failed after being read' => ['state' => 'failed', 'failure_code' => 'bounced'],
        ] as $label => $change) {
            if ($this->refusal(fn () => DB::connection('tenant')->table('messaging_attempts')
                ->where('id', $row)
                ->update($change)) === null) {
                $failures[] = sprintf(
                    'an attempt went %s, so "did they read it" is answered differently depending on '.
                    'when the question is asked',
                    $label,
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function retryingIsOneMessage(): array
    {
        $invoice = $this->issue();
        $this->aVerifiedConsent();

        $first = $this->sendTo($invoice, attempt: 1);
        $failures = [];

        /*
         | The SAME attempt, sent again after the response was lost.
         |
         | A network that drops a response is indistinguishable from one
         | that drops a request, so the caller retries with the number it
         | used - and gets the original answer rather than a second
         | message. A provider charges per message, and a customer who
         | receives two copies of one invoice telephones about it.
         |
         | The first draft derived the attempt number by counting the
         | rows already there, which made this retry attempt 2 with a new
         | reference and a second message. The bite sweep found it.
         */
        $retry = $this->sendTo($invoice, attempt: 1);

        if (($retry['id'] ?? '') !== ($first['id'] ?? '-')) {
            $failures[] = 'retrying one attempt produced a second record, so a dropped response sends '.
                'the customer two copies of one invoice';
        }

        if (! str_contains((string) ($retry['why'] ?? ''), 'was already made')) {
            $failures[] = 'the retry does not say it was recognised as one';
        }

        if (\count($this->channel()->sent()) !== 1) {
            $failures[] = sprintf(
                'the provider was asked to send %d message(s) for one attempt - which is what a '.
                'provider charges for',
                \count($this->channel()->sent()),
            );
        }

        /*
         | And the provider itself returns the same id for one
         | reference.
         |
         | Asserted against the fake directly, because this is the
         | behaviour the real providers offer and a fake that invented a
         | new id each time would make the check above pass for the
         | wrong reason.
         */
        $fake = new FakeChannel();
        $fake->makeReachable('+966501234567');

        $message = new \App\Exchange\Messaging\ValueObjects\OutboundMessage(
            to: '+966501234567',
            template: 'payment_received',
            language: 'ar',
            variables: ['A customer', '1,150.00'],
            reference: 'probe:one',
        );

        $firstId = $fake->send($message)->providerMessageId;
        $secondId = $fake->send($message)->providerMessageId;

        if ($firstId !== $secondId) {
            $failures[] = 'the provider returns a different id for one reference, so nothing above '.
                'proves a retry is one message';
        }

        /*
         | And it SENT once, not twice with one id.
         |
         | Asserted by counting, because the fake derives its id from
         | the reference - so two sends return the same id even when
         | both went out. A bite that removed the de-duplication left
         | the ids matching and changed nothing the comparison above
         | could see.
         */
        if (\count($fake->sent()) !== 1) {
            $failures[] = sprintf(
                'one reference produced %d message(s) at the provider, which is what a provider '.
                'charges for - the ids matching is not the same as one send',
                \count($fake->sent()),
            );
        }

        /*
         | A chase is attempt 2, and is visible as a second try.
         |
         | Which is different from a retry: somebody chasing an unpaid
         | invoice sends it again on purpose, and the history has to show
         | two.
         */
        $chase = $this->sendTo($invoice, attempt: 2);

        if (($chase['attempt'] ?? 0) !== 2 || ($chase['id'] ?? '') === ($first['id'] ?? '-')) {
            $failures[] = 'a deliberate chase was folded into the first attempt, so the history cannot '.
                'show that the invoice was sent twice';
        }

        if (\count($this->deliveries()->historyFor($this->entityId, 'invoice', (string) $invoice->getKey())) !== 2) {
            $failures[] = 'the history does not show two attempts after a chase';
        }

        /*
         | And attempt zero is refused.
         |
         | A caller that passed nothing would otherwise write a reference
         | ending `:0`, and the next real send would be `:1` - which is
         | not a retry of it, so the duplicate is back.
         */
        $zero = $this->refusal(fn () => $this->sendTo($invoice, attempt: 0));

        if ($zero === null) {
            $failures[] = 'an attempt numbered zero was accepted, so a caller that passed nothing gets '.
                'a reference nothing else will ever match';
        } elseif (! str_contains($zero, 'tells a retry from a chase')) {
            $failures[] = 'a zero attempt is refused by the column rather than explained by the '.
                'service, so nobody learns that the number is theirs to choose: '.$zero;
        }

        /*
         | And the database holds the reference unique.
         |
         | For the two callers in flight at once, which no service check
         | can see.
         */
        $reference = (string) DB::connection('tenant')->table('messaging_attempts')
            ->where('document_id', (string) $invoice->getKey())
            ->where('attempt', 1)
            ->value('reference');

        $direct = $this->refusal(fn () => DB::connection('tenant')->table('messaging_attempts')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'document_type' => 'invoice',
            'document_id' => (string) $invoice->getKey(),
            'party_id' => (string) $this->party?->getKey(),
            'channel' => 'whatsapp',
            'address' => '+966501234567',
            'reference' => $reference,
            'attempt' => 1,
            'state' => 'accepted',
            'provider_message_id' => 'another-id',
            'outcome' => 'a second record of one attempt',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($direct === null) {
            $failures[] = 'the database accepted two records of one attempt, so two callers in flight '.
                'at once both send';
        }

        return $failures;
    }

    /** @return list<string> */
    private function failuresLeaveTheDocumentAlone(): array
    {
        $invoice = $this->issue();
        $consent = $this->aConsent();
        app(Consents::class)->verify($consent, '483915');

        /*
         | Consent and verification in place, and the provider cannot
         | reach the number.
         |
         | Which is the ordinary case: a number that was right when it
         | was given and has since been disconnected.
         */
        $before = DB::connection('tenant')->table('invoices')->where('id', (string) $invoice->getKey())->first();

        $outcome = $this->sendTo($invoice);
        $failures = [];

        if (($outcome['state'] ?? '') !== 'failed') {
            $failures[] = sprintf('an unreachable number reports %s', (string) ($outcome['state'] ?? '-'));
        }

        if (($outcome['failure_code'] ?? '') !== 'recipient_not_reachable') {
            $failures[] = sprintf(
                'the failure is recorded as "%s" rather than a code somebody can act on - a template '.
                'not approved, an unreachable number and a rate limit need three different responses',
                (string) ($outcome['failure_code'] ?? '-'),
            );
        }

        if (! str_contains((string) ($outcome['why'] ?? ''), 'issued, and undelivered')) {
            $failures[] = 'the answer does not say that the document is unchanged, which is the wrong '.
                'reading somebody takes from a failed send';
        }

        /*
         | And the invoice is untouched, byte for byte.
         |
         | An invoice is ISSUED and UNDELIVERED, which are two facts. A
         | product that marked the document would have a network error
         | changing a hash-chained row.
         */
        $after = DB::connection('tenant')->table('invoices')->where('id', (string) $invoice->getKey())->first();

        foreach (['status', 'document_hash', 'updated_at'] as $column) {
            if (($before->$column ?? null) !== ($after->$column ?? null)) {
                $failures[] = sprintf(
                    'a failed delivery changed the invoice\'s %s, so a network error moves an issued '.
                    'document',
                    $column,
                );
            }
        }

        /*
         | And the database refuses a failure with no code.
         */
        $codeless = $this->refusal(fn () => DB::connection('tenant')->table('messaging_attempts')
            ->where('document_id', (string) $invoice->getKey())
            ->update(['failure_code' => null]));

        if ($codeless === null) {
            $failures[] = 'a failure can be recorded with no code, so every kind of failure goes to '.
                'the same place, which is nowhere';
        }

        return $failures;
    }

    /** @return list<string> */
    private function attemptsAreFacts(): array
    {
        $invoice = $this->issue();
        $this->aVerifiedConsent();
        $this->sendTo($invoice);

        $row = (string) DB::connection('tenant')->table('messaging_attempts')
            ->where('document_id', (string) $invoice->getKey())
            ->value('id');

        $failures = [];

        foreach ([
            'who it went to' => ['address' => '+966509999999'],
            'which document it was about' => ['document_id' => (string) Str::ulid()],
            'when it was sent' => ['sent_at' => now()->subYear()],
        ] as $label => $change) {
            if ($this->refusal(fn () => DB::connection('tenant')->table('messaging_attempts')
                ->where('id', $row)
                ->update($change)) === null) {
                $failures[] = sprintf(
                    '%s can be changed after the fact, which makes "did they get the invoice" '.
                    'unanswerable',
                    $label,
                );
            }
        }

        if ($this->refusal(fn () => DB::connection('tenant')->table('messaging_attempts')
            ->where('id', $row)
            ->delete()) === null) {
            $failures[] = 'a delivery attempt can be deleted, which is how "we never sent that" '.
                'becomes true after the fact';
        }

        return $failures;
    }

    // ---- Inbound ----------------------------------------------------------

    /** @return list<string> */
    private function repliesAreSeen(): array
    {
        $invoice = $this->issue();
        $this->aVerifiedConsent();
        $this->sendTo($invoice);

        $inbound = app(InboundReplies::class);
        $failures = [];

        /*
         | The reply arrives AFTER the send, which is what dates it.
         |
         | The fixture first used the document's own date and the check
         | failed: `sent_at` is the real clock - it is when WE sent it -
         | and a reply dated six months earlier matches no attempt. That
         | is the query behaving correctly, and it is worth keeping: a
         | reply cannot be about a message that had not gone out yet.
         */
        $received = $inbound->receive(
            entityId: $this->entityId,
            channel: 'whatsapp',
            address: '+966501234567',
            body: 'Is this the March invoice or the April one?',
            providerMessageId: 'wamid.reply.1',
            at: Carbon::now()->addMinutes(3),
        );

        if (($received['party_id'] ?? null) !== (string) $this->party?->getKey()) {
            $failures[] = 'a reply from a number we have on record was not resolved to its party';
        }

        $unread = $inbound->unread($this->entityId);

        if ($unread === []) {
            return ['a reply does not appear in the list a person works through, so the channel only '.
                'sends - which is a channel customers learn to ignore'];
        }

        $row = $unread[0];

        if (($row['from'] ?? null) === null) {
            $failures[] = 'the reply does not name who it is from';
        }

        /*
         | And it says what it is probably about.
         |
         | "Is this the March one?" means nothing on its own and
         | everything beside the invoice it followed.
         */
        if (($row['about'] ?? null) === null || ! str_contains((string) $row['about'], 'invoice')) {
            $failures[] = 'the reply does not say which document it followed, so "is this the March '.
                'one" is a question nobody can answer';
        }

        $inbound->markRead((string) $row['id'], $this->actorId());

        if ($inbound->unread($this->entityId) !== []) {
            $failures[] = 'a reply somebody read is still in the unread list, so the list never empties '.
                'and stops being read';
        }

        return $failures;
    }

    /** @return list<string> */
    private function unknownNumbersStayUnresolved(): array
    {
        $inbound = app(InboundReplies::class);

        $received = $inbound->receive(
            entityId: $this->entityId,
            channel: 'whatsapp',
            address: '+966509998887',
            body: 'Who is this? I did not order anything.',
            providerMessageId: 'wamid.reply.unknown',
            at: Carbon::now()->addMinutes(3),
        );

        $failures = [];

        if (($received['party_id'] ?? null) !== null) {
            $failures[] = 'a reply from a number nobody has on record was attached to a party anyway - '.
                'which is how a reply is read by the wrong account manager, who answers a question '.
                'about somebody else\'s invoice';
        }

        if (! str_contains((string) ($received['why'] ?? ''), 'should not have')) {
            $failures[] = 'the answer does not say what an unknown number usually means, which is that '.
                'an invoice went somewhere it should not have';
        }

        /*
         | And it is still in the list.
         |
         | An unresolved reply is the MOST important one to read: it is
         | usually evidence of a wrong number.
         */
        $unread = $inbound->unread($this->entityId);

        if (\count($unread) !== 1) {
            $failures[] = 'a reply from an unknown number is not in the list, so the evidence that an '.
                'invoice went to the wrong person is invisible';
        }

        return $failures;
    }

    /** @return list<string> */
    private function webhooksAreIdempotent(): array
    {
        $inbound = app(InboundReplies::class);

        for ($i = 0; $i < 3; $i++) {
            $inbound->receive(
                entityId: $this->entityId,
                channel: 'whatsapp',
                address: '+966501234567',
                body: 'When is this due?',
                providerMessageId: 'wamid.retried',
                at: Carbon::now()->addMinutes(3),
            );
        }

        $rows = (int) DB::connection('tenant')->table('messaging_inbound')
            ->where('provider_message_id', 'wamid.retried')
            ->count();

        $failures = $rows === 1 ? [] : [sprintf(
            'a webhook redelivered three times produced %d replies - providers retry until the hook is '.
            'acknowledged, and showing the same question four times is how a person stops reading the '.
            'list',
            $rows,
        )];

        /*
         | And an empty body is not a reply.
         |
         | Providers send status notices through the same hook, and
         | recording them as messages fills the list with nothing.
         */
        $empty = $this->refusal(fn () => $inbound->receive(
            entityId: $this->entityId,
            channel: 'whatsapp',
            address: '+966501234567',
            body: '   ',
            providerMessageId: 'wamid.empty',
            at: Carbon::now()->addMinutes(3),
        ));

        if ($empty === null) {
            $failures[] = 'an empty message was recorded as a reply, which fills the list a person '.
                'works through with status notices';
        } elseif (! str_contains($empty, 'status notices')) {
            $failures[] = 'an empty reply is refused by the column rather than explained by the '.
                'service, so nobody learns that the provider sends receipts through the same hook: '.$empty;
        }

        return $failures;
    }

    // ---- The vendor boundary ----------------------------------------------

    /** @return list<string> */
    private function theVendorIsBehindAnInterface(): array
    {
        $failures = [];

        if (! app(MessageChannel::class) instanceof MessageChannel) {
            $failures[] = 'the channel is not resolved through its interface, so changing vendor means '.
                'changing every call site';
        }

        /*
         | And no vendor name appears in the tenant module.
         |
         | A product wired to one vendor's SDK is a product that cannot
         | change vendor - and WhatsApp Business is reached through
         | resellers whose pricing and approval queues differ, so a
         | business that outgrows one has to move.
         */
        foreach (glob(base_path('app/Tenant/Messaging/**/*.php')) ?: [] as $file) {
            $source = (string) preg_replace(
                ['~/\*.*?\*/~s', '~//[^\n]*~'],
                '',
                (string) file_get_contents($file),
            );

            foreach (['Twilio', 'Meta\\', 'GuzzleHttp', 'curl_'] as $vendor) {
                if (str_contains($source, $vendor)) {
                    $failures[] = sprintf(
                        '%s names %s directly, so the vendor has reached into the tenant module and a '.
                        'move means rewriting the sending path',
                        basename($file),
                        $vendor,
                    );
                }
            }
        }

        /*
         | And the fake refuses what the real one refuses.
         |
         | A fake that accepted everything would make every check here
         | pass while the real path refused.
         */
        $fake = new FakeChannel();
        $result = $fake->send(new \App\Exchange\Messaging\ValueObjects\OutboundMessage(
            to: '+966501234567',
            template: 'not_approved',
            language: 'ar',
            variables: [],
            reference: 'probe-1',
        ));

        if ($result->accepted) {
            $failures[] = 'the fake accepts an unapproved template, so every check here passes while '.
                'the real path refuses';
        }

        return $failures;
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * A refusal, inside a savepoint.
     *
     * PostgreSQL aborts the whole transaction on a constraint
     * violation, so a probe that expects one has to be wrapped -
     * otherwise every statement after it fails with "current
     * transaction is aborted" and the check reports a confusing error
     * instead of its finding.
     */
    private function refusal(callable $attempt): ?string
    {
        $savepoint = 'ms_'.bin2hex(random_bytes(4));

        $this->connection?->statement('SAVEPOINT '.$savepoint);

        try {
            $attempt();
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return null;
        } catch (Throwable $e) {
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return $e::class.': '.Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300);
        }
    }

    private function channel(): FakeChannel
    {
        $channel = app(MessageChannel::class);
        \assert($channel instanceof FakeChannel);

        return $channel;
    }

    private function deliveries(): Deliveries
    {
        return app(Deliveries::class);
    }

    private function aConsent(): string
    {
        $this->channel()->forget();

        return (string) app(Consents::class)->record(
            entityId: $this->entityId,
            partyId: (string) $this->party?->getKey(),
            channel: 'whatsapp',
            address: '+966501234567',
            basis: 'ticked the box on the customer portal when the account was opened',
            userId: $this->actorId(),
        )['id'];
    }

    private function aVerifiedConsent(): string
    {
        $consent = $this->aConsent();
        app(Consents::class)->verify($consent, '483915');
        $this->channel()->makeReachable('+966501234567');

        return $consent;
    }

    /**
     * @return array<string, mixed>
     */
    private function sendTo(object $invoice, int $attempt = 1): array
    {
        return $this->deliveries()->send(
            entityId: $this->entityId,
            documentType: 'invoice',
            documentId: (string) $invoice->getKey(),
            partyId: (string) $this->party?->getKey(),
            template: 'invoice_issued',
            language: 'ar',
            variables: ['A customer', 'INV-1', '1,150.00', '2026-06-18'],
            attempt: $attempt,
        );
    }
}
