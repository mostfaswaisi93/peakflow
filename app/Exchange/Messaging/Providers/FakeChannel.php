<?php

declare(strict_types=1);

namespace App\Exchange\Messaging\Providers;

use App\Exchange\Messaging\Contracts\MessageChannel;
use App\Exchange\Messaging\ValueObjects\OutboundMessage;
use App\Exchange\Messaging\ValueObjects\SendResult;
use App\Exchange\Messaging\ValueObjects\TemplateDefinition;

/**
 * The fake, which every check in this phase runs against - PF-257.
 *
 * ## Why this is production code and not a test double
 *
 * It is the default binding. A deployment that has not configured a
 * provider sends nothing and records why, rather than failing at the
 * first invoice - and an operator setting the product up for the first
 * time can see the whole delivery path work before they have an
 * account.
 *
 * It also makes the checks assert OUR behaviour. Every rule in this
 * task - an unapproved template refused before sending, an attempt
 * recorded per try, a failure that does not change the document's state
 * - is about what this product does, and a suite that needed a live
 * WhatsApp account to prove any of it would be a suite nobody runs.
 *
 * ## It refuses the same things the real one does
 *
 * Deliberately, and that is the whole value. A fake that accepted
 * everything would make the checks pass while the real path refused -
 * so this one refuses an unapproved template, an unverified number and
 * a placeholder count that does not match, with the codes a provider
 * uses.
 */
final class FakeChannel implements MessageChannel
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var list<string> */
    private array $reachable = [];

    /** @var list<TemplateDefinition> */
    private array $templates;

    public function __construct()
    {
        /*
         | Two templates, in two languages, shaped like real ones.
         |
         | `invoice_issued` is a utility template - transactional, which
         | is the category a provider approves for a document - and the
         | variable list says what each placeholder means so that a
         | caller passing them in the wrong order can be caught.
         */
        $this->templates = [
            new TemplateDefinition(
                name: 'invoice_issued',
                language: 'ar',
                variables: ['customer name', 'invoice number', 'amount', 'due date'],
                category: 'utility',
            ),
            new TemplateDefinition(
                name: 'invoice_issued',
                language: 'en',
                variables: ['customer name', 'invoice number', 'amount', 'due date'],
                category: 'utility',
            ),
            new TemplateDefinition(
                name: 'payment_received',
                language: 'ar',
                variables: ['customer name', 'amount'],
                category: 'utility',
            ),
        ];
    }

    public function key(): string
    {
        return 'whatsapp';
    }

    /** @return list<TemplateDefinition> */
    public function approvedTemplates(): array
    {
        return $this->templates;
    }

    public function send(OutboundMessage $message): SendResult
    {
        $template = null;

        foreach ($this->templates as $candidate) {
            if ($candidate->name === $message->template && $candidate->language === $message->language) {
                $template = $candidate;

                break;
            }
        }

        if ($template === null) {
            return SendResult::refused(
                'template_not_found',
                sprintf(
                    'No approved template "%s" in %s. The provider refuses an unapproved template with '.
                    'a generic error hours later, and the invoice is simply not delivered.',
                    $message->template,
                    $message->language,
                ),
            );
        }

        if (\count($message->variables) !== $template->placeholders()) {
            return SendResult::refused(
                'parameter_count_mismatch',
                sprintf(
                    'Template "%s" takes %d placeholder(s) - %s - and %d were given. Too few renders a '.
                    'literal {{n}} that the customer reads.',
                    $template->name,
                    $template->placeholders(),
                    implode(', ', $template->variables),
                    \count($message->variables),
                ),
            );
        }

        if (! $this->isReachable($message->to)) {
            return SendResult::refused(
                'recipient_not_reachable',
                sprintf('%s is not a number this provider can reach.', $message->to),
            );
        }

        /*
         | The same reference twice returns the SAME id.
         |
         | Because a provider that charges per message makes a retry
         | cost money, and a customer who gets two copies of one invoice
         | telephones about it. The real providers offer this; the fake
         | has to, or a check would prove nothing about it.
         */
        foreach ($this->sent as $previous) {
            if ($previous['reference'] === $message->reference) {
                return SendResult::accepted((string) $previous['id']);
            }
        }

        $id = 'fake-'.substr(hash('sha256', $message->reference), 0, 16);

        $this->sent[] = [
            'id' => $id,
            'reference' => $message->reference,
            'to' => $message->to,
            'template' => $message->template,
            'language' => $message->language,
            'variables' => $message->variables,
        ];

        return SendResult::accepted($id);
    }

    public function isReachable(string $number): bool
    {
        return \in_array($number, $this->reachable, true);
    }

    // ---- What a check drives it with --------------------------------------

    public function makeReachable(string $number): void
    {
        $this->reachable[] = $number;
    }

    /** @return list<array<string, mixed>> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function forget(): void
    {
        $this->sent = [];
        $this->reachable = [];
    }
}
