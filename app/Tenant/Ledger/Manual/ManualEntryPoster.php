<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Manual;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Rate;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Ledger\Posting\RuleDescription;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;

/**
 * Posting a manual journal entry that somebody - or something - typed.
 *
 * ## It validates through the same service the screen uses
 *
 * {@see ManualJournal} is the validator the manual entry screen runs on every
 * keystroke. Reusing it here is not convenience: it is the only way the API
 * and the screen can refuse the same entry for the same reason. A second
 * validator would drift, and it would drift in the direction that matters -
 * the API accepting a control-account posting that the screen refuses, which
 * makes the subledger disagree with its control account through the route
 * nobody is watching.
 *
 * ## The rule is a pass-through, exactly like the spreadsheet import's
 *
 * `Poster` posts through a registered rule, because that is the seam that
 * keeps `Ledger` from knowing what any document is. A manual entry has no
 * document and no rule to describe - its lines are whatever somebody typed -
 * so the rule returns the lines the validator already built.
 *
 * It derives nothing, and that is deliberate: the validator has already
 * resolved the accounts, read the amounts and applied the rates with their
 * provenance. A rule that rebuilt a line from an account code and a
 * functional amount would lose the rate, and the database refuses a foreign
 * line without one - so the failure would be an imported euro line becoming
 * a riyal line that nothing afterwards can trace. {@see JournalImport} took
 * the same decision for the same reason.
 *
 * ## What it does NOT do
 *
 * Create a draft. The API posts or it refuses; it has no half-state. A draft
 * reachable only by machine is a row nothing will ever come back to finish -
 * the screen has a draft because a person is interrupted, and an integration
 * is not.
 */
final class ManualEntryPoster
{
    /** The rule key that appears on every entry posted this way. */
    public const RULE = 'ledger.manual.api';

    public function __construct(
        private readonly ManualJournal $validator,
        private readonly Poster $poster,
        private readonly PostingRuleRegistry $rules,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Validate and post, or refuse with every problem at once.
     *
     * @param  list<DraftLine>  $lines
     * @param  array<string, Rate>  $rates  "FROM/TO" => rate, for foreign lines
     *
     * @throws ManualEntryRefused
     */
    public function post(
        Entity $entity,
        array $lines,
        Carbon $accountingDate,
        ?string $narration = null,
        array $rates = [],
        ?string $sourceId = null,
    ): JournalEntry {
        if (\count($lines) < 2) {
            throw ManualEntryRefused::needsTwoLines((string) $entity->functional_currency);
        }

        $verdict = $this->validator->validate($entity, $lines, $rates);

        if (! $verdict->postable) {
            throw ManualEntryRefused::from($verdict);
        }

        $this->registerTheRule();

        $entry = $this->poster->post(
            entity: $entity,
            ruleKey: self::RULE,
            event: ['posting_lines' => $verdict->postingLines],
            accountingDate: $accountingDate,
            journalCode: 'GEN',
            sourceType: 'api',
            /*
             | The document that caused the posting, where a caller has one.
             |
             | Not the client's idempotency key: `journal_entries.source_id`
             | is a `char(26)` ULID column, and a key is whatever the client
             | generated - so writing one in truncates or fails depending on
             | its length. The key-to-document mapping lives on the
             | idempotency record for the 24 hours the guarantee does.
             */
            sourceId: $sourceId,
            narration: $narration,
        );

        $this->audit->record(
            category: 'ledger',
            action: 'ledger.manual.posted',
            subjectType: 'journal_entries',
            subjectId: (string) $entry->getKey(),
            subjectLabel: (string) $entry->number,
            after: [
                'lines' => \count($verdict->lines),
                'total_debit' => (string) $verdict->debits->amount,
                'currency' => $verdict->debits->currency,
                'accounting_date' => $accountingDate->toDateString(),
            ],
            reason: $narration ?? 'A journal entry was posted through the API.',
            entityId: (string) $entity->getKey(),
        );

        return $entry;
    }

    /**
     * The rule this posts under, registered on first use.
     *
     * Registered rather than declared as a class in `Posting/Rules`, because
     * it has no posting behaviour to declare: its lines come from the
     * validator. A class there would advertise itself on the rule-inspection
     * screen as something an operator could review, and there would be
     * nothing to review.
     */
    private function registerTheRule(): void
    {
        if ($this->rules->has(self::RULE)) {
            return;
        }

        $this->rules->register(new class implements PostingRule
        {
            public function key(): string
            {
                return ManualEntryPoster::RULE;
            }

            public function describe(): RuleDescription
            {
                return RuleDescription::of(
                    ManualEntryPoster::RULE,
                    'قيد يدوي من الواجهة البرمجية',
                    'A manual entry posted through the API',
                    [['Dr/Cr', 'as the request says', 'validated before anything is written; a control account is refused']],
                );
            }

            /**
             * The lines the validator already built.
             *
             * @return list<PostingLine>
             */
            public function lines(array $event, PostingContext $context): array
            {
                return array_values(array_filter(
                    (array) ($event['posting_lines'] ?? []),
                    static fn (mixed $line): bool => $line instanceof PostingLine,
                ));
            }
        });
    }
}
