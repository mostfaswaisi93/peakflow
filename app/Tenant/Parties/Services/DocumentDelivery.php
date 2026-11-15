<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Services;

use App\Tenant\Parties\Models\Party;
use App\Tenant\Tenancy\Services\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Where a document goes, and in whose language - PF-063.
 *
 * ## The recipient's language, not the sender's
 *
 * This is the whole reason a locale lives on the contact. An invoice from a
 * Saudi company to a German customer is read by somebody in Germany, and
 * sending it in Arabic because the issuer works in Arabic produces a document
 * the recipient files without reading - which then ages into a dunning letter
 * about an invoice they could not understand.
 *
 * So the resolution order for the language is: the preference's own locale,
 * then the named contact's, then the entity's default. The issuing user's
 * locale appears nowhere in that list, deliberately - it is the one thing
 * that must not decide.
 *
 * ## Resolution is explained, not just returned
 *
 * "Why did this go to the wrong person in the wrong language" is a question
 * asked after a customer complains, and a resolver that returns only an
 * address cannot answer it.
 */
final class DocumentDelivery
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PartyMerger $merges,
    ) {}

    /**
     * How to deliver one document type to one party.
     *
     * @return array{
     *     channel: string, address: ?string, locale: string, format: string,
     *     copy_to: ?string, contact: ?string, source: string, derivation: string,
     * }
     */
    public function for(string $entityId, Party $party, string $documentType): array
    {
        /*
         | The survivor's preferences, not the merged party's.
         |
         | Unlike a balance - which stays with the party it was posted
         | against - a preference is about who to contact NOW, and that is
         | the surviving record.
         */
        $party = $this->merges->resolve($party);

        $specific = $this->preference($entityId, (string) $party->getKey(), $documentType);
        $fallback = $specific ?? $this->preference($entityId, (string) $party->getKey(), null);

        $entityDefaultLocale = (string) $this->settings->get('documents.default_locale', $entityId);
        $entityDefaultChannel = (string) $this->settings->get('documents.delivery_channel', $entityId);

        if ($fallback === null) {
            return [
                'channel' => $entityDefaultChannel,
                'address' => $this->contactAddress($party, $entityDefaultChannel),
                'locale' => $this->partyLocale($party) ?? $entityDefaultLocale,
                'format' => 'pdf',
                'copy_to' => null,
                'contact' => null,
                'source' => 'the company default',
                'derivation' => sprintf(
                    'no delivery preference is set for %s, so the company default (%s, %s) applies%s',
                    $documentType,
                    $entityDefaultChannel,
                    $entityDefaultLocale,
                    $this->partyLocale($party) === null
                        ? ''
                        : sprintf(', with the party\'s own language (%s)', $this->partyLocale($party)),
                ),
            ];
        }

        $contact = $fallback->contact_id === null
            ? null
            : DB::connection('tenant')->table('party_contacts')->where('id', $fallback->contact_id)->first();

        /*
         | The language, in order: the preference, the contact, the entity.
         |
         | The issuing user's locale is absent on purpose. It is the one
         | thing that must not decide, and it is also the easiest thing to
         | reach for - `app()->getLocale()` is right there.
         */
        $locale = $fallback->locale
            ?? $contact?->locale
            ?? $this->partyLocale($party)
            ?? $entityDefaultLocale;

        $localeSource = match (true) {
            $fallback->locale !== null => 'the delivery preference',
            $contact?->locale !== null => 'the contact',
            $this->partyLocale($party) !== null => 'the party',
            default => 'the company default',
        };

        return [
            'channel' => (string) $fallback->channel,
            'address' => $fallback->address === null
                ? $this->contactAddress($party, (string) $fallback->channel, $contact)
                : (string) $fallback->address,
            'locale' => (string) $locale,
            'format' => (string) $fallback->format,
            'copy_to' => $fallback->copy_to === null ? null : (string) $fallback->copy_to,
            'contact' => $contact === null ? null : (string) $contact->name,
            'source' => $specific === null
                ? "the party's default preference"
                : sprintf('the preference for %s', $documentType),
            'derivation' => sprintf(
                '%s by %s%s, in %s (from %s)%s',
                $specific === null
                    ? sprintf('%s has no preference for %s, so its default applies: sent', (string) $party->legal_name, $documentType)
                    : sprintf('%s is sent', $documentType),
                (string) $fallback->channel,
                $contact === null ? '' : sprintf(' to %s', (string) $contact->name),
                (string) $locale,
                $localeSource,
                $fallback->copy_to === null ? '' : sprintf(', copied to %s', (string) $fallback->copy_to),
            ),
        ];
    }

    /**
     * Whether a document may be sent at all.
     *
     * A channel of `none` is a deliberate choice - a customer who collects
     * their invoices from a portal, or one in a dispute where everything is
     * going by post from the legal team. Returning false rather than
     * throwing, because "not sent, by arrangement" is a normal outcome and
     * an exception would make it an incident.
     */
    public function isDeliverable(string $entityId, Party $party, string $documentType): bool
    {
        $resolved = $this->for($entityId, $party, $documentType);

        return $resolved['channel'] !== 'none'
            && ($resolved['address'] !== null || $resolved['channel'] === 'portal' || $resolved['channel'] === 'print');
    }

    private function preference(string $entityId, string $partyId, ?string $documentType): ?object
    {
        return DB::connection('tenant')->table('party_delivery_preferences')
            ->where('entity_id', $entityId)
            ->where('party_id', $partyId)
            ->where('active', true)
            ->when(
                $documentType === null,
                fn ($query) => $query->whereNull('document_type'),
                fn ($query) => $query->where('document_type', $documentType),
            )
            ->first();
    }

    private function partyLocale(Party $party): ?string
    {
        $locale = $party->default_locale;

        return $locale === null ? null : (string) $locale;
    }

    /**
     * The address a channel needs, from the contact that receives documents.
     *
     * Only a contact flagged `receives_documents`. A technical contact is
     * somebody to ring, not somebody to invoice, and falling back to "the
     * first contact we have" is how an invoice reaches an engineer.
     */
    private function contactAddress(Party $party, string $channel, ?object $contact = null): ?string
    {
        $column = match ($channel) {
            'email', 'peppol' => 'email',
            'sms' => 'phone',
            default => null,
        };

        if ($column === null) {
            return null;
        }

        if ($contact !== null) {
            return $contact->{$column} === null ? null : (string) $contact->{$column};
        }

        $value = DB::connection('tenant')->table('party_contacts')
            ->where('party_id', $party->getKey())
            ->where('active', true)
            ->where('receives_documents', true)
            ->orderByRaw("case when role = 'billing' then 0 when role = 'ar' then 1 else 2 end")
            ->value($column);

        return $value === null ? null : (string) $value;
    }
}
