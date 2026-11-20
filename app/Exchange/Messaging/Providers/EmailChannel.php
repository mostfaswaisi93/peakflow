<?php

declare(strict_types=1);

namespace App\Exchange\Messaging\Providers;

use App\Exchange\Messaging\Contracts\MessageChannel;
use App\Exchange\Messaging\ValueObjects\OutboundMessage;
use App\Exchange\Messaging\ValueObjects\SendResult;
use App\Exchange\Messaging\ValueObjects\TemplateDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Delivery by email - PF-104.
 *
 * ## Why this implements the same interface as WhatsApp
 *
 * Because the document does not care. An invoice is issued, and then it
 * is delivered on whatever channel the customer agreed to; the sending
 * path, the attempt numbering, the duplicate reference and the delivery
 * log are the same questions on both. Writing a second one for email
 * would be the second implementation `MessageChannel` exists to prevent -
 * and PF-181's scheduled statement delivery is waiting on exactly this,
 * so a half-channel here would have been a half-channel there too.
 *
 * ## Every template is approved, and that is not a shortcut
 *
 * `approvedTemplates()` means "approved by the provider". WhatsApp
 * Business approves templates because unsolicited free-form messages are
 * refused; an SMTP server approves nothing. So the approved set is the
 * set this product has defined, which is a true answer to the question
 * the interface asks rather than an empty one - a caller that asks for a
 * template nobody wrote still gets a refusal, from the same code path
 * that refuses an unapproved WhatsApp template.
 *
 * ## Bounces are a reason to stop, not a reason to retry
 *
 * A hard bounce means the address does not exist. Retrying it is how a
 * sending domain's reputation is destroyed, and the business finds out
 * when its invoices stop arriving at the customers whose addresses ARE
 * right. So a bounced address is recorded and `isReachable()` refuses it
 * from then on, which surfaces as a refusal on the next attempt rather
 * than as a silent failure.
 */
final class EmailChannel implements MessageChannel
{
    public function key(): string
    {
        return 'email';
    }

    /**
     * The templates this product has written, as approved ones.
     *
     * Read from configuration rather than listed here, so adding a
     * document type to the delivery path is a configuration change and
     * not a change to a provider adapter.
     *
     * @return list<TemplateDefinition>
     */
    public function approvedTemplates(): array
    {
        $templates = [];

        foreach ((array) config('messaging.email.templates', []) as $name => $definition) {
            foreach ((array) ($definition['languages'] ?? ['ar']) as $language) {
                $templates[] = new TemplateDefinition(
                    name: (string) $name,
                    language: (string) $language,
                    variables: array_map('strval', (array) ($definition['variables'] ?? [])),
                    category: (string) ($definition['category'] ?? 'utility'),
                );
            }
        }

        return $templates;
    }

    public function send(OutboundMessage $message): SendResult
    {
        if (! $this->isReachable($message->to)) {
            /*
             | Refused rather than attempted.
             |
             | The address either is not an address or has already bounced.
             | Sending anyway produces a second bounce, and a sending
             | domain is judged on the ratio.
             */
            return SendResult::refused(
                'unreachable',
                sprintf(
                    'The address "%s" is not deliverable: it is either malformed or it has bounced '.
                    'before. Sending to it again would be a second bounce against this domain\'s '.
                    'reputation, which is what stops the invoices that DO have the right address.',
                    $message->to,
                ),
            );
        }

        /*
         | A message id of our own, before the send.
         |
         | The provider's id arrives in a header we may or may not be able
         | to read back, and the delivery log needs something to key a
         | later bounce notification to. Generating it here means the row
         | and the message carry the same id whatever the transport does.
         */
        $messageId = (string) Str::ulid();

        try {
            Mail::mailer(config('messaging.email.mailer'))->send(
                [],
                [],
                function ($mail) use ($message, $messageId): void {
                    $mail->to($message->to)
                        ->subject($this->subjectFor($message))
                        ->text($this->bodyFor($message))
                        ->getHeaders()
                        ->addTextHeader('X-PeakFlow-Message-Id', $messageId);

                    foreach ($message->attachments ?? [] as $name => $path) {
                        if (is_string($path) && is_file($path)) {
                            $mail->attach($path, ['as' => (string) $name]);
                        }
                    }
                },
            );
        } catch (\Throwable $e) {
            /*
             | A transport failure is a FAILED attempt, not an exception
             | that reaches the caller.
             |
             | The document is issued. Whether it reached the customer is a
             | separate fact and the delivery log is where it lives - a
             | throw here would make the issuing path depend on an SMTP
             | server being up, which is the coupling PF-104's last
             | criterion exists to refuse.
             */
            return SendResult::refused('transport', Str::limit($e->getMessage(), 300));
        }

        return SendResult::accepted($messageId);
    }

    /**
     * Deliverable: a syntactically valid address that has not bounced.
     *
     * The suppression list is per tenant, because an address that bounced
     * for one company may be perfectly good for another - a shared list
     * would let one tenant's typo stop another tenant's invoice.
     */
    public function isReachable(string $number): bool
    {
        if (filter_var($number, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        try {
            return ! DB::connection('tenant')
                ->table('suppressed_addresses')
                ->where('address', Str::lower($number))
                ->exists();
        } catch (\Throwable) {
            /*
             | No tenant connection, no suppression list, no reason to
             | refuse. This is asked in contexts with no tenant pointed at
             | - a configuration check, a console command listing
             | templates - and refusing there would report every address
             | as undeliverable.
             */
            return true;
        }
    }

    private function subjectFor(OutboundMessage $message): string
    {
        $subject = (string) config(
            'messaging.email.templates.'.$message->template.'.subject.'.$message->language,
            '',
        );

        if ($subject === '') {
            $subject = (string) config('messaging.email.templates.'.$message->template.'.subject.ar', $message->reference);
        }

        return $this->fill($subject, $message->variables);
    }

    private function bodyFor(OutboundMessage $message): string
    {
        $body = (string) config(
            'messaging.email.templates.'.$message->template.'.body.'.$message->language,
            '',
        );

        if ($body === '') {
            $body = (string) config('messaging.email.templates.'.$message->template.'.body.ar', '');
        }

        $body = $this->fill($body, $message->variables);

        if ($message->documentUrl !== null) {
            $body .= "\n\n".$message->documentUrl;
        }

        return $body;
    }

    /**
     * `{1}`, `{2}` ... in the order the template declares them.
     *
     * The same positional scheme the WhatsApp templates use, so a
     * document type's variables are one list rather than one per channel.
     *
     * @param  array<int|string, mixed>  $variables
     */
    private function fill(string $text, array $variables): string
    {
        $position = 1;

        foreach ($variables as $value) {
            $text = str_replace('{'.$position.'}', (string) $value, $text);
            $position++;
        }

        return $text;
    }
}
