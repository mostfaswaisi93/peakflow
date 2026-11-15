<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Tenant\Tax\Enums\TaxPointBasis;
use App\Tenant\Tax\Exceptions\TaxPointUndeterminable;
use App\Tenant\Tax\TaxPointDecision;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Deriving a document's tax point - PF-077.
 *
 * ## The tax point is its own date, and it is not the invoice date
 *
 * Three dates occur on a document and they answer three questions:
 *
 *   BT-2   invoice date     when the document was issued
 *   BT-7   TAX POINT        which return period the tax falls in
 *   BT-72  delivery date    when the supply occurred
 *
 * A document issued on the 1st for a supply on the 30th of the previous month
 * belongs to the earlier return in many jurisdictions. A system that files by
 * invoice date files it a month late, and the difference is found by the
 * authority rather than by the business.
 *
 * ## It is derived once and then frozen
 *
 * This service derives; it does not re-derive. The decision goes onto the
 * document at issue and is read from there afterwards, because the rule may
 * change and the document may not - and a document whose tax point moved
 * because somebody edited a jurisdiction profile is a document that has
 * changed which return it belongs to, retrospectively, after that return was
 * filed.
 *
 * ## A missing input REFUSES
 *
 * The temptation is to fall back to the invoice date, which is always present.
 * That is the failure this service exists to prevent: a delivery-date rule
 * applied to a document with no delivery date and quietly answered with the
 * invoice date is a document in the wrong return period, and nothing says so.
 *
 * So a basis whose input is missing throws, and the message names the field
 * the document needs rather than the rule that wanted it.
 */
final class TaxPoint
{
    /**
     * The tax point for a document, and the reasoning behind it.
     *
     * @param  string  $profile  the jurisdiction profile - `generic`, `zatca`, ...
     *
     * @throws TaxPointUndeterminable
     */
    public function derive(
        string $profile,
        CarbonInterface $invoiceDate,
        ?CarbonInterface $deliveryDate = null,
        ?CarbonInterface $paymentDate = null,
    ): TaxPointDecision {
        $basis = $this->basisFor($profile);

        $candidates = [
            'invoice' => CarbonImmutable::parse($invoiceDate->toDateString()),
            'delivery' => $deliveryDate === null ? null : CarbonImmutable::parse($deliveryDate->toDateString()),
            'payment' => $paymentDate === null ? null : CarbonImmutable::parse($paymentDate->toDateString()),
        ];

        $missing = array_values(array_filter(
            $basis->requires(),
            static fn (string $field): bool => $candidates[$field] === null,
        ));

        if ($missing !== []) {
            throw TaxPointUndeterminable::missingDates($profile, $basis, $missing);
        }

        /*
         | The winner is tracked, not just the date.
         |
         | For an "earlier of" basis EN 16931 has no code for the rule, so the
         | document reports whichever candidate actually won - and an
         | accountant checking the determination needs to know which of the
         | two it was, because that is the fact they can verify against the
         | delivery note or the bank statement.
         */
        $winner = $basis->requires()[0];

        foreach ($basis->requires() as $field) {
            if ($candidates[$field]->isBefore($candidates[$winner])) {
                $winner = $field;
            }
        }

        return new TaxPointDecision(
            date: $candidates[$winner],
            basis: $basis,
            winningDate: $winner,
            profile: $profile,
        );
    }

    /**
     * The basis a profile applies.
     *
     * Read from configuration, so a new jurisdiction is a row. An unknown
     * profile falls back to the platform default's basis rather than throwing:
     * the alternative is a document that cannot be issued because a profile
     * key was misspelled, and the invoice date is both the commonest rule and
     * the one a reader can check at a glance.
     *
     * An unknown BASIS does throw, because that is a configuration file
     * naming a rule this engine does not implement - and guessing would apply
     * a rule nobody wrote.
     *
     * @throws TaxPointUndeterminable
     */
    public function basisFor(string $profile): TaxPointBasis
    {
        $configured = $this->configuredBasis($profile)
            ?? $this->configuredBasis((string) config('jurisdictions.default', 'generic'));

        if ($configured === null) {
            return TaxPointBasis::InvoiceDate;
        }

        $basis = TaxPointBasis::tryFrom($configured);

        if ($basis === null) {
            throw TaxPointUndeterminable::unknownBasis($profile, $configured);
        }

        return $basis;
    }

    private function configuredBasis(string $profile): ?string
    {
        $value = config('jurisdictions.tax_point.'.$profile);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Which accounting period a document's tax point falls in, by date.
     *
     * A document's accounting period and its RETURN period can differ - a
     * monthly VAT return on a quarterly-closing entity is normal - so both are
     * stored on the document and neither is derived from the other. This
     * exists so the derivation happens in one place; the return period itself
     * is PF-085.
     */
    public function fallsInMonth(TaxPointDecision $decision): string
    {
        return $decision->date->format('Y-m');
    }
}
