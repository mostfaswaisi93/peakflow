<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Support\Iso\LanguageRegistry;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\CounterpartyRegistrations;
use App\Tenant\Tax\Exceptions\ReverseChargeNotIssuable;
use App\Tenant\Tax\PlaceOfSupplyDecision;
use App\Tenant\Tenancy\Models\Entity;
use Carbon\CarbonInterface;

/**
 * The reverse charge: who accounts for the tax, and what the document says - PF-079.
 *
 * ## It is a category, not a zero rate with a comment
 *
 * `AE` and `Z` both put nothing in the tax column, which is exactly why they
 * get conflated - and they are not the same thing in any return anywhere.
 *
 * A zero-rated supply is taxable at 0%: it counts toward taxable turnover,
 * toward registration thresholds, and the supplier keeps full input recovery.
 * A reverse-charge supply moves the liability to the customer, who posts both
 * an output and an input entry on the bill it receives.
 *
 * Model it as "zero rate plus a note on the invoice" and three things break at
 * once: the supplier's return reports a taxable supply it did not make, the
 * customer's return reports nothing at all, and nobody notices until an
 * authority matches the two.
 *
 * ## The note is part of the treatment
 *
 * Not presentation. A reverse-charge invoice shows no tax, so the note is the
 * only thing on the document that explains why - and Directive 2006/112/EC
 * Art. 226(11a) requires the mention. Its wording is per jurisdiction, stored
 * in `config/jurisdictions.php`, and a profile with no note refuses rather
 * than falling back: the fallback prints a document that is missing a
 * mandatory legal mention and looks complete.
 *
 * ## The recipient's half is Phase 07
 *
 * `Purchasing` posts both the input and the output tax on the bill, which is
 * the half most systems forget - it is what makes the reverse charge net to
 * nothing for a fully recoverable customer, and what makes it cost money for
 * one that is partly exempt. The posting rule belongs with bills; this class
 * is the sell side's gate.
 *
 * @see docs/domain/place-of-supply.md
 */
final class ReverseCharge
{
    /** Does this decision move the liability to the customer? */
    public function appliesTo(PlaceOfSupplyDecision $decision): bool
    {
        return $decision->category->isReverseCharge();
    }

    /**
     * The legal note, in the language the document is written in.
     *
     * @throws ReverseChargeNotIssuable
     */
    public function noteFor(string $profile, ?string $locale = null): string
    {
        $notes = config('jurisdictions.reverse_charge_note');
        $note = \is_array($notes) ? ($notes[$profile] ?? null) : null;

        if (! \is_array($note)) {
            throw ReverseChargeNotIssuable::noNote($profile);
        }

        $language = $locale ?? app()->getLocale();
        $tag = LanguageRegistry::exists($language) ? $language : LanguageRegistry::DEFAULT_TAG;
        $text = $note[$tag] ?? null;

        if (! \is_string($text) || trim($text) === '') {
            throw ReverseChargeNotIssuable::noNoteInThatLanguage($profile, $tag);
        }

        return $text;
    }

    /** The article the note comes from, for the tax panel and the audit. */
    public function sourceFor(string $profile): string
    {
        $notes = config('jurisdictions.reverse_charge_note');
        $note = \is_array($notes) ? ($notes[$profile] ?? null) : null;

        if (! \is_array($note) || ! \is_string($note['source'] ?? null)) {
            throw ReverseChargeNotIssuable::noNote($profile);
        }

        return $note['source'];
    }

    /**
     * May this reverse charge be issued at all?
     *
     * Both sides have to be registered, and registered ON THE TAX POINT - not
     * today. A document raised in May for a supply in March is judged on
     * March, and a registration that lapsed in April was valid then.
     *
     * The supplier is checked in its own country; the customer is checked in
     * the country where the supply is taxed, which is the place-of-supply
     * decision rather than wherever the customer happens to be registered.
     * Those differ: an EU business established in France and registered in
     * Germany can receive a reverse charge on a supply taxed in Germany and
     * not on one taxed in Italy.
     *
     * @throws ReverseChargeNotIssuable
     */
    public function assertIssuable(
        Entity $entity,
        CounterpartyRegistrations $customer,
        PlaceOfSupplyDecision $decision,
        CarbonInterface $taxPoint,
    ): void {
        if (! $this->appliesTo($decision)) {
            throw ReverseChargeNotIssuable::notAReverseCharge($decision->category->value);
        }

        $date = $taxPoint->toDateString();

        $supplier = $entity->taxRegistrationOn($decision->supply->supplierCountry, $taxPoint);

        if ($supplier === null) {
            throw ReverseChargeNotIssuable::theSupplierIsNotRegistered(
                $decision->supply->supplierCountry, $date,
            );
        }

        $recipient = $customer->validOn($decision->country, $taxPoint);

        if ($recipient === null) {
            /*
             | Before refusing, say whether there was one that had lapsed.
             |
             | "No registration" and "a registration that expired in April"
             | send somebody to two different places: the first to the
             | customer record, the second to the customer. A refusal that
             | cannot tell them apart gets resolved by typing a number in.
             */
            $lapsed = $customer->lapsedBefore($decision->country, $taxPoint);

            if ($lapsed !== null && $lapsed->lastDay() !== null) {
                throw ReverseChargeNotIssuable::theRegistrationHadLapsed(
                    'customer', $decision->country, $lapsed->identifier,
                    $date, $lapsed->lastDay()->toDateString(),
                );
            }

            throw ReverseChargeNotIssuable::theCustomerIsNotRegistered($decision->country, $date);
        }
    }

    /**
     * What the tax column holds on a reverse-charge line: nothing.
     *
     * Stated as a method rather than left to the computation, because "no tax
     * amount" is a rule of the treatment and not an outcome of multiplying by
     * a zero rate. `AE` carries no rate at all - the database refuses one -
     * so there is nothing to multiply.
     */
    public function taxableAmountIsCharged(): bool
    {
        return false;
    }

    /** The category, so a caller never has to spell `AE` itself. */
    public function category(): TaxCategory
    {
        return TaxCategory::ReverseCharge;
    }
}
