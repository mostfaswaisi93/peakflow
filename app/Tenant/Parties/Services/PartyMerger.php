<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Parties\Exceptions\MergeRefused;
use App\Tenant\Parties\Models\Party;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Merging two records of the same customer - PF-059.
 *
 * ## The loser is kept, forever
 *
 * This looks wrong and is forced. Posted journal lines are immutable - the
 * ledger's central invariant - so a merge CANNOT repoint historical
 * postings at the survivor. Which leaves two options: rewrite history, or
 * keep the loser and resolve through it.
 *
 * The loser is kept, deactivated so nothing new can be addressed to it, with
 * `merged_into_id` pointing at the survivor. An invoice issued three years
 * ago still names who it was issued to, which is what an invoice is for.
 * Exposure, statements and aging sum across the chain.
 *
 * Deleting the loser and repointing everything is what every system that has
 * had to explain a merge to an auditor wishes it had not done.
 *
 * ## What DOES move
 *
 * The things a merge is for: addresses, contacts, tax registrations,
 * per-entity settings and delivery preferences, so the survivor is usable
 * and nobody has to retype them.
 *
 * ## What deliberately does not
 *
 * **Bank accounts.** A merge is a plausible way to introduce a beneficiary
 * account without the second pair of eyes: merge a party you control into a
 * real supplier, and their payments go to your account with no
 * re-verification. So accounts move UNVERIFIED, and the next payment is
 * blocked until somebody confirms them - the same control as any other
 * change.
 *
 * **Credit holds.** A hold is a decision about one party made on one day's
 * figures. Carrying it over asserts a decision nobody made; dropping it
 * silently releases a customer. So neither: the merge records that the loser
 * was on hold, and a human decides.
 */
final class PartyMerger
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Merge `$loser` into `$survivor`.
     *
     * @return array{
     *     moved: array<string, int>,
     *     bank_accounts_requiring_verification: int,
     *     loser_was_on_hold: bool,
     *     note: string,
     * }
     *
     * @throws MergeRefused
     */
    public function merge(Party $survivor, Party $loser, string $reason): array
    {
        if ((string) $survivor->getKey() === (string) $loser->getKey()) {
            throw MergeRefused::intoItself();
        }

        if ($loser->merged_into_id !== null) {
            throw MergeRefused::alreadyMerged((string) $loser->code, (string) $loser->merged_into_id);
        }

        if ($survivor->merged_into_id !== null) {
            throw MergeRefused::survivorIsMerged((string) $survivor->getKey(), (string) $survivor->merged_into_id);
        }

        if (mb_strlen(trim($reason)) < 15) {
            throw MergeRefused::reasonTooThin();
        }

        return DB::connection('tenant')->transaction(function () use ($survivor, $loser, $reason): array {
            $connection = DB::connection('tenant');
            $survivorId = (string) $survivor->getKey();
            $loserId = (string) $loser->getKey();

            $wasOnHold = $connection->table('party_entity_settings')
                ->where('party_id', $loserId)
                ->where('credit_hold', true)
                ->exists();

            $moved = [];

            /*
             | The things a merge is for.
             |
             | `party_entity_settings` is NOT in this list: it carries a
             | credit limit, a hold and control accounts per entity, and
             | merging two of them would have to decide whose limit wins.
             | A row is moved only where the survivor has none.
             */
            foreach (['party_addresses', 'party_contacts', 'party_tax_registrations'] as $table) {
                $moved[$table] = $connection->table($table)
                    ->where('party_id', $loserId)
                    ->update(['party_id' => $survivorId, 'updated_at' => now()]);
            }

            /*
             | Bank accounts move UNVERIFIED.
             |
             | A merge is otherwise a way to introduce a beneficiary account
             | without the second pair of eyes: merge a party you control
             | into a real supplier and their payments follow. The trigger on
             | the table re-arms verification on any change to the account
             | itself, and this re-arms it for a change of owner, which the
             | trigger does not see as a change to the account.
             */
            $accounts = $connection->table('party_bank_accounts')
                ->where('party_id', $loserId)
                ->count();

            $connection->table('party_bank_accounts')
                ->where('party_id', $loserId)
                ->update([
                    'party_id' => $survivorId,
                    'requires_reverification' => true,
                    'verified_by_user_id' => null,
                    'verified_at' => null,
                    'verification_channel' => null,
                    'updated_at' => now(),
                ]);

            $moved['party_bank_accounts'] = $accounts;

            // Delivery preferences and per-entity settings move only where
            // the survivor has none for that entity - otherwise two rows
            // would claim the same (entity, party) key.
            $moved['party_delivery_preferences'] = $this->moveWhereAbsent(
                'party_delivery_preferences',
                $loserId,
                $survivorId,
            );

            /*
             | The per-entity settings move; the HOLD does not.
             |
             | Moving the row carried the hold with it, which this service
             | claimed not to do - a credit hold is a decision about one
             | party on one day's figures, and carrying it asserts a
             | decision nobody made about a different party.
             |
             | Dropping it silently is no better, so the hold is LIFTED
             | explicitly first: an event with the merge as its reason and
             | the person merging as its author. That satisfies the guard
             | that forbids an unrecorded hold change, leaves a trail, and
             | still leaves the survivor's own hold decision to a human -
             | which the returned note says.
             */
            $this->releaseHoldsBeforeMoving($loserId, $reason);

            $moved['party_entity_settings'] = $this->moveWhereAbsent(
                'party_entity_settings',
                $loserId,
                $survivorId,
            );

            /*
             | The loser is deactivated and pointed at the survivor.
             |
             | `parties_merged_is_inactive` is a CHECK, so these two have to
             | happen in the same statement - setting one and then the other
             | is refused in between.
             */
            $connection->table('parties')->where('id', $loserId)->update([
                'merged_into_id' => $survivorId,
                'merged_at' => now(),
                'active' => false,
                'updated_at' => now(),
            ]);

            $note = sprintf(
                'Historical documents still name %s and always will: posted journal lines are immutable, so '.
                'a merge cannot repoint them. Every lookup follows the chain instead, and exposure sums '.
                'across it.%s',
                (string) $loser->legal_name,
                $wasOnHold
                    ? ' The merged party was on credit hold; that decision was NOT carried over and needs '.
                      'someone to make it again.'
                    : '',
            );

            $this->audit->record(
                category: 'party',
                action: 'party.merged',
                subjectType: Party::class,
                subjectId: $survivorId,
                subjectLabel: sprintf(
                    '%s merged into %s',
                    (string) $loser->legal_name,
                    (string) $survivor->legal_name,
                ),
                before: ['merged_party' => $loserId, 'was_on_hold' => $wasOnHold],
                after: ['survivor' => $survivorId, 'moved' => $moved],
                reason: $reason.' — '.$note,
                partyId: $survivorId,
            );

            return [
                'moved' => $moved,
                'bank_accounts_requiring_verification' => $accounts,
                'loser_was_on_hold' => $wasOnHold,
                'note' => $note,
            ];
        });
    }

    /**
     * Lift any hold on the loser, as a recorded decision.
     *
     * Before the settings row moves, so the move changes only `party_id`
     * and the guard that forbids an unrecorded hold change does not fire -
     * and so the history says the hold was lifted by the merge rather than
     * simply stopping.
     */
    private function releaseHoldsBeforeMoving(string $loserId, string $reason): void
    {
        $connection = DB::connection('tenant');

        $held = $connection->table('party_entity_settings')
            ->where('party_id', $loserId)
            ->where('credit_hold', true)
            ->pluck('entity_id');

        foreach ($held as $entityId) {
            $connection->table('credit_hold_events')->insert([
                'id' => (string) Str::ulid(),
                'entity_id' => $entityId,
                'party_id' => $loserId,
                'direction' => 'lifted',
                'reason' => sprintf(
                    'lifted by a merge into the surviving party: %s. The hold was NOT carried over - it was '.
                    'a decision about this record on its own figures, and the survivor needs its own.',
                    $reason,
                ),
                'by_user_id' => auth()->guard('web')->id(),
                'at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Who a party really is now, following the merge chain.
     *
     * One hop, because merging into an already-merged party is refused by a
     * database trigger - so the chain is never deeper and this cannot loop.
     */
    public function resolve(Party $party): Party
    {
        if ($party->merged_into_id === null) {
            return $party;
        }

        /** @var Party $survivor */
        $survivor = Party::query()->findOrFail($party->merged_into_id);

        return $survivor;
    }

    /**
     * Every party id that is now this one, including itself.
     *
     * What exposure, statements and aging sum across. Returning the ids
     * rather than a resolved party, because the figures are in tables that
     * still point at the old ones and always will.
     *
     * @return list<string>
     */
    public function identities(string $partyId): array
    {
        $merged = DB::connection('tenant')->table('parties')
            ->where('merged_into_id', $partyId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return [$partyId, ...$merged];
    }

    /**
     * Move rows whose (entity, party) key the survivor does not already own.
     *
     * A straight UPDATE would violate the unique key the first time both
     * parties had a row for the same entity - and that is the normal case
     * for the duplicate this merge exists to fix.
     */
    private function moveWhereAbsent(string $table, string $loserId, string $survivorId): int
    {
        $connection = DB::connection('tenant');

        $takenEntities = $connection->table($table)
            ->where('party_id', $survivorId)
            ->pluck('entity_id')
            ->all();

        return $connection->table($table)
            ->where('party_id', $loserId)
            ->when($takenEntities !== [], fn ($query) => $query->whereNotIn('entity_id', $takenEntities))
            ->update(['party_id' => $survivorId, 'updated_at' => now()]);
    }
}
