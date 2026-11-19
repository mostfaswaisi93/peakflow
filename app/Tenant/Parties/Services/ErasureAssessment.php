<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Services;

use App\Tenant\Parties\Models\Party;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What an erasure request can actually remove - PF-239.
 *
 * ## Statutory retention wins, and the product says so
 *
 * A customer asks to be deleted. Some of what the product holds about them
 * can go and some cannot, and the difference is not a preference: a tax
 * authority requiring seven years of invoice retention overrides the
 * request, and the invoice names the buyer.
 *
 * The failure this exists to prevent is the one where the product says
 * "deleted" and keeps the rows. That is worse than refusing, in both
 * directions: the person believes something that is false, and the operator
 * who later finds the data has no record of what was promised.
 *
 * So this answers per category, with a reason and a date, and the product
 * shows the answer rather than a button that pretends.
 *
 * ## It assesses, it does not erase
 *
 * Deliberately. Erasing is a decision with a person behind it, and an
 * assessment somebody reads before deciding is a different act from a
 * command that does it. Keeping them apart also means this can be run as
 * often as anybody likes - on the party page, in an evidence pack, while
 * answering the request - without any of those runs changing anything.
 *
 * ## The dates come from the archive, not from a constant here
 *
 * `archived_artefacts.retain_until` is written when the artefact is archived,
 * from the profile's declared period **at that moment**. A constant here
 * would be a second opinion about the law, and the first thing to drift
 * would be the one that is not beside the document it governs.
 *
 * Where a party has documents and none of them is archived - which is every
 * document in a jurisdiction with no clearance profile enabled - there is no
 * date to give, and this says that rather than inventing one.
 */
final class ErasureAssessment
{
    /**
     * @return array<string, mixed>
     */
    public function forParty(string $entityId, Party $party): array
    {
        $partyId = (string) $party->getKey();

        $documents = $this->documentCounts($entityId, $partyId);
        $total = array_sum($documents);
        $until = $this->retainedUntil($entityId, $partyId);

        $categories = [];

        /*
         | The party row itself.
         |
         | An invoice has to name its buyer - that is what makes it an
         | invoice - so the legal name cannot go while a document naming it
         | is retained. Registration numbers go with it for the same reason.
         */
        $categories['identity'] = $this->category(
            'The party record: legal name and registration number',
            $total === 0,
            $total === 0
                ? 'No document names this party, so nothing holds the record.'
                : sprintf('%d document(s) name this party, and a document has to name its counterparty.', $total),
            $until,
        );

        /*
         | Contact details are the half that usually CAN go.
         |
         | An email address is how the document was sent, not part of what
         | the document says. Once there is nothing left to send, there is
         | nothing holding the address - and this is the answer a request
         | most often actually wants.
         */
        $openDocuments = $this->openDocumentCount($entityId, $partyId);

        $categories['contact'] = $this->category(
            'Contact details: names, email addresses, telephone numbers',
            $openDocuments === 0,
            $openDocuments === 0
                ? 'Nothing is open with this party, so there is nothing left to send them.'
                : sprintf('%d document(s) are still open, and these are how they are sent.', $openDocuments),
            null,
        );

        $categories['address'] = $this->category(
            'Postal addresses',
            $total === 0,
            $total === 0
                ? 'No document used an address of theirs.'
                : 'The place of supply decided the tax on their documents, and the decision has to remain explicable.',
            $until,
        );

        $categories['banking'] = $this->category(
            'Bank details: BIC and the last four digits',
            $this->paymentCount($entityId, $partyId) === 0,
            $this->paymentCount($entityId, $partyId) === 0
                ? 'No payment was made to or received from this party.'
                : 'A payment is evidenced by where the money went.',
            $until,
        );

        /*
         | And the one that is never erasable.
         |
         | The audit log is hash-chained: removing a record breaks every
         | link after it, so the chain stops being evidence that the books
         | were not altered. This is the clearest case in the product where
         | retention wins, and the notice says it plainly rather than
         | leaving somebody to discover it.
         */
        $categories['audit'] = $this->category(
            'The audit trail',
            false,
            'The trail is hash-chained, so removing a record breaks every link after it - and the '.
                'chain is what proves the accounts were not altered. It is never erased.',
            null,
        );

        return [
            'party' => (string) $party->legal_name,
            'party_id' => $partyId,
            'documents' => $documents,
            'retained_until' => $until?->toDateString(),
            'categories' => $categories,
            'erasable_now' => array_keys(array_filter(
                $categories,
                static fn (array $c): bool => $c['erasable'] === true,
            )),
        ];
    }

    /**
     * @return array{erasable: bool, what: string, why: string, until: ?string}
     */
    private function category(string $what, bool $erasable, string $why, ?Carbon $until): array
    {
        return [
            'erasable' => $erasable,
            'what' => $what,
            'why' => $why,
            'until' => $erasable ? null : $until?->toDateString(),
        ];
    }

    /** @return array<string, int> */
    private function documentCounts(string $entityId, string $partyId): array
    {
        return [
            'invoices' => (int) DB::connection('tenant')->table('invoices')
                ->where('entity_id', $entityId)->where('party_id', $partyId)->count(),
            'bills' => (int) DB::connection('tenant')->table('bills')
                ->where('entity_id', $entityId)->where('party_id', $partyId)->count(),
        ];
    }

    private function openDocumentCount(string $entityId, string $partyId): int
    {
        return (int) DB::connection('tenant')->table('invoices')
            ->where('entity_id', $entityId)
            ->where('party_id', $partyId)
            ->whereNotIn('status', ['paid', 'void', 'cancelled'])
            ->count();
    }

    private function paymentCount(string $entityId, string $partyId): int
    {
        return (int) DB::connection('tenant')->table('payment_run_instructions')
            ->where('party_id', $partyId)
            ->count();
    }

    /**
     * The latest date anything archived for this party may be removed.
     *
     * Over the party's documents rather than over the party, because an
     * artefact is archived against the document it is a copy of.
     */
    private function retainedUntil(string $entityId, string $partyId): ?Carbon
    {
        $ids = DB::connection('tenant')->table('invoices')
            ->where('entity_id', $entityId)->where('party_id', $partyId)->pluck('id')
            ->merge(
                DB::connection('tenant')->table('bills')
                    ->where('entity_id', $entityId)->where('party_id', $partyId)->pluck('id'),
            )
            ->map(static fn ($id): string => (string) $id)
            ->all();

        if ($ids === []) {
            return null;
        }

        $latest = DB::connection('tenant')->table('archived_artefacts')
            ->where('entity_id', $entityId)
            ->whereIn('subject_id', $ids)
            ->max('retain_until');

        return $latest === null ? null : Carbon::parse((string) $latest);
    }
}
