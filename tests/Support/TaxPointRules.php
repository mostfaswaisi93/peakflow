<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Iso\LanguageRegistry;
use App\Tenant\Tax\Enums\TaxPointBasis;
use App\Tenant\Tax\Exceptions\TaxPointUndeterminable;
use App\Tenant\Tax\Services\TaxPoint;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The tax point, and which return period it puts a supply in - PF-077.
 *
 * ## What is actually being claimed
 *
 * That the basis a jurisdiction declares changes the answer. Not "the service
 * returns a date": the invoice date is always available and always plausible,
 * so a service that quietly returned it would pass every test that only checks
 * for a date and would file a month of supplies in the wrong return.
 *
 * So each rule is exercised on a document where the dates STRADDLE a month
 * boundary, and the assertion is on the month the supply lands in.
 */
final class TaxPointRules
{
    /**
     * What each basis needs, and what EN 16931 code it reports.
     *
     * Written out from UNCL 2005 rather than read from the enum - a check that
     * derived it would assert the enum equals itself.
     *
     * basis => [required fields, BT-8 code or null]
     *
     * @var array<string, array{list<string>, string|null}>
     */
    private const BASES = [
        'invoice_date' => [['invoice'], '3'],
        'delivery_date' => [['delivery'], '35'],
        'payment_date' => [['payment'], '432'],
        'earlier_of_invoice_and_delivery' => [['invoice', 'delivery'], null],
        'earlier_of_invoice_and_payment' => [['invoice', 'payment'], null],
    ];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return [
            'the five bases need what they say and report the right code' => $this->theBasesBehave(),
            'the basis changes which return period a supply lands in' => $this->theBasisChangesThePeriod(),
            'a prepayment fixes the tax point at receipt' => $this->aPrepaymentFixesItAtReceipt(),
            'a missing date refuses rather than falling back' => $this->aMissingDateRefuses(),
            'a basis the engine does not implement refuses' => $this->anUnknownBasisRefuses(),
            'the decision carries the reasoning, in both languages' => $this->theDecisionExplainsItself(),
            'every enabled profile declares a basis the engine implements' => $this->everyProfileDeclaresABasis(),
        ];
    }

    /**
     * Run a closure with a profile's basis overridden, then put it back.
     *
     * `config()` rather than a mock: the service reads configuration, which is
     * where PF-077 says the mapping belongs, and a mock would prove the
     * service calls a method rather than that the configuration decides.
     */
    private function withBasis(string $basis, callable $body): mixed
    {
        $key = 'jurisdictions.tax_point.generic';
        $previous = config($key);

        try {
            config([$key => $basis]);

            return $body();
        } finally {
            config([$key => $previous]);
        }
    }

    /** @return list<string> */
    private function theBasesBehave(): array
    {
        $problems = [];

        foreach (self::BASES as $value => [$requires, $code]) {
            $basis = TaxPointBasis::tryFrom($value);

            if ($basis === null) {
                $problems[] = sprintf('The engine has no basis "%s".', $value);

                continue;
            }

            if ($basis->requires() !== $requires) {
                $problems[] = sprintf(
                    'Basis %s requires [%s] and should require [%s]. A basis that under-declares its '.
                    'inputs is one that falls back silently when a date is missing.',
                    $value,
                    implode(', ', $basis->requires()),
                    implode(', ', $requires),
                );
            }

            if ($basis->documentCode() !== $code) {
                $problems[] = sprintf(
                    'Basis %s reports the EN 16931 BT-8 code %s and should report %s.',
                    $value,
                    var_export($basis->documentCode(), true),
                    var_export($code, true),
                );
            }

            if ($basis->explanation('ar') === $basis->explanation('en')) {
                $problems[] = sprintf('Basis %s has no Arabic explanation distinct from its English one.', $value);
            }
        }

        if (\count(TaxPointBasis::cases()) !== \count(self::BASES)) {
            $problems[] = sprintf(
                'The engine has %d bases and this check knows %d. A basis added without a row here is '.
                'one whose behaviour nothing asserts.',
                \count(TaxPointBasis::cases()),
                \count(self::BASES),
            );
        }

        return $problems;
    }

    /**
     * The claim that matters: the basis decides the return period.
     *
     * An invoice raised on 1 March for a supply delivered on 28 February.
     * Under the invoice-date rule the supply belongs to March's return; under
     * the earlier-of rule it belongs to February's. A system that files by
     * invoice date files it a month late, and the difference is found by the
     * authority rather than by the business.
     *
     * @return list<string>
     */
    private function theBasisChangesThePeriod(): array
    {
        $problems = [];
        $service = app(TaxPoint::class);

        $invoice = CarbonImmutable::parse('2026-03-01');
        $delivery = CarbonImmutable::parse('2026-02-28');

        $cases = [
            'invoice_date' => ['2026-03-01', '2026-03', 'invoice', '3'],
            'delivery_date' => ['2026-02-28', '2026-02', 'delivery', '35'],
            'earlier_of_invoice_and_delivery' => ['2026-02-28', '2026-02', 'delivery', '35'],
        ];

        foreach ($cases as $basis => [$date, $month, $winner, $code]) {
            $decision = $this->withBasis(
                $basis,
                static fn () => $service->derive('generic', $invoice, $delivery),
            );

            if ($decision->date->toDateString() !== $date) {
                $problems[] = sprintf(
                    'Under %s, an invoice of 2026-03-01 for a supply of 2026-02-28 has the tax point %s '.
                    'and should have %s.',
                    $basis,
                    $decision->date->toDateString(),
                    $date,
                );
            }

            if ($service->fallsInMonth($decision) !== $month) {
                $problems[] = sprintf(
                    'Under %s the supply lands in %s and should land in %s - which is the whole '.
                    'consequence of the rule.',
                    $basis,
                    $service->fallsInMonth($decision),
                    $month,
                );
            }

            if ($decision->winningDate !== $winner) {
                $problems[] = sprintf(
                    'Under %s the winning date is "%s" and should be "%s". An accountant checking the '.
                    'determination needs to know which date it was, because that is the fact they can '.
                    'verify against the delivery note.',
                    $basis,
                    $decision->winningDate,
                    $winner,
                );
            }

            if ($decision->documentCode() !== $code) {
                $problems[] = sprintf(
                    'Under %s the BT-8 code is %s and should be %s.',
                    $basis,
                    $decision->documentCode(),
                    $code,
                );
            }
        }

        /*
         | And the two answers must actually differ.
         |
         | If the invoice-date and earlier-of rules produced the same month on
         | this document, every assertion above would pass while the basis did
         | nothing - which is the shape of the bug the whole task exists to
         | prevent.
         */
        $byInvoice = $this->withBasis('invoice_date', static fn () => $service->derive('generic', $invoice, $delivery));
        $byEarlier = $this->withBasis('earlier_of_invoice_and_delivery', static fn () => $service->derive('generic', $invoice, $delivery));

        if ($service->fallsInMonth($byInvoice) === $service->fallsInMonth($byEarlier)) {
            $problems[] = sprintf(
                'The invoice-date and earlier-of rules both file this document in %s, so the basis is '.
                'not changing anything and the rest of this check is asserting nothing.',
                $service->fallsInMonth($byInvoice),
            );
        }

        return $problems;
    }

    /**
     * The rule that surprises people, and the reason it is modelled.
     *
     * Money received in January for an invoice raised in May. Several regimes
     * fix the tax point at receipt, so the supply belongs to January's return
     * - four months before the document exists.
     *
     * @return list<string>
     */
    private function aPrepaymentFixesItAtReceipt(): array
    {
        $problems = [];
        $service = app(TaxPoint::class);

        $decision = $this->withBasis(
            'earlier_of_invoice_and_payment',
            static fn () => $service->derive(
                'generic',
                CarbonImmutable::parse('2026-05-01'),
                null,
                CarbonImmutable::parse('2026-01-15'),
            ),
        );

        if ($decision->date->toDateString() !== '2026-01-15') {
            $problems[] = sprintf(
                'A prepayment received on 2026-01-15 against an invoice raised on 2026-05-01 produced '.
                'the tax point %s. Under the prepayment rule the money fixes it at receipt, so the '.
                'supply belongs to January - four months before the document exists.',
                $decision->date->toDateString(),
            );
        }

        if ($decision->winningDate !== 'payment' || $decision->documentCode() !== '432') {
            $problems[] = sprintf(
                'The prepayment decision reports the winner "%s" and the code %s, and should report '.
                '"payment" and 432. EN 16931 has no code for the RULE, so the document reports '.
                'whichever date actually won.',
                $decision->winningDate,
                $decision->documentCode(),
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function aMissingDateRefuses(): array
    {
        $problems = [];
        $service = app(TaxPoint::class);
        $invoice = CarbonImmutable::parse('2026-03-01');

        $cases = [
            'the delivery-date rule with no delivery date' => ['delivery_date', null, null],
            'the payment-date rule with no payment date' => ['payment_date', null, null],
            'the earlier-of-delivery rule with no delivery date' => ['earlier_of_invoice_and_delivery', null, null],
            'the earlier-of-payment rule with no payment date' => ['earlier_of_invoice_and_payment', null, null],
        ];

        foreach ($cases as $description => [$basis, $delivery, $payment]) {
            try {
                $decision = $this->withBasis(
                    $basis,
                    static fn () => $service->derive('generic', $invoice, $delivery, $payment),
                );

                $problems[] = sprintf(
                    '%s produced the tax point %s. The invoice date is always present and always '.
                    'plausible, which is why falling back to it is the failure this refuses: the '.
                    'document lands in the wrong return period and nothing says so.',
                    $description,
                    $decision->date->toDateString(),
                );
            } catch (TaxPointUndeterminable) {
                // Refused, as it must be.
            } catch (Throwable $e) {
                $problems[] = sprintf('%s raised %s: %s', $description, $e::class, $e->getMessage());
            }
        }

        /*
         | And the invoice-date rule must still work with nothing else given.
         |
         | A service that refused everything would pass all four cases above
         | and be unusable, which is the failure mode of a rule tested in one
         | direction.
         */
        try {
            $this->withBasis('invoice_date', static fn () => $service->derive('generic', $invoice));
        } catch (Throwable $e) {
            $problems[] = sprintf(
                'The invoice-date rule refused a document with only an invoice date: %s',
                $e->getMessage(),
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function anUnknownBasisRefuses(): array
    {
        $problems = [];
        $service = app(TaxPoint::class);

        foreach (['the_vibes', 'INVOICE_DATE', 'earlier_of_everything'] as $basis) {
            try {
                $this->withBasis(
                    $basis,
                    static fn () => $service->derive('generic', CarbonImmutable::parse('2026-03-01')),
                );

                $problems[] = sprintf(
                    'The basis "%s" was accepted. A configuration file naming a rule the engine does '.
                    'not implement must throw - guessing would apply a rule nobody wrote.',
                    $basis,
                );
            } catch (TaxPointUndeterminable) {
                // Refused.
            }
        }

        /*
         | An unknown PROFILE is different and must NOT throw.
         |
         | A misspelled profile key would otherwise stop a document being
         | issued at all, and the invoice date is both the commonest rule and
         | the one a reader can check at a glance. The distinction is
         | deliberate: a missing profile is an omission, a named-but-unknown
         | basis is an instruction nobody can follow.
         */
        try {
            $decision = $service->derive('a-profile-nobody-configured', CarbonImmutable::parse('2026-03-01'));

            if ($decision->date->toDateString() !== '2026-03-01') {
                $problems[] = 'An unknown profile produced an unexpected tax point.';
            }
        } catch (Throwable $e) {
            $problems[] = sprintf(
                'An unknown profile threw: %s. A misspelled profile key would then stop a document '.
                'being issued at all.',
                $e->getMessage(),
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function theDecisionExplainsItself(): array
    {
        $problems = [];
        $service = app(TaxPoint::class);

        $decision = $this->withBasis(
            'earlier_of_invoice_and_delivery',
            static fn () => $service->derive(
                'generic',
                CarbonImmutable::parse('2026-03-01'),
                CarbonImmutable::parse('2026-02-28'),
            ),
        );

        foreach (LanguageRegistry::served() as $locale) {
            $explanation = $decision->explain($locale);

            if (! str_contains($explanation, '2026-02-28')) {
                $problems[] = sprintf(
                    'The %s explanation does not state the date it decided: "%s"',
                    $locale,
                    $explanation,
                );
            }

            if (mb_strlen($explanation) < 30) {
                $problems[] = sprintf(
                    'The %s explanation is %d characters. When an accountant disagrees with a tax point '.
                    'the question is never "what date did you pick", it is "why" - and a bare date '.
                    'forces them to reconstruct the rule from the other dates, which they will '.
                    'reconstruct differently.',
                    $locale,
                    mb_strlen($explanation),
                );
            }
        }

        if ($decision->explain('ar') === $decision->explain('en')) {
            $problems[] = 'The explanation is the same in both languages.';
        }

        /** @var array<string, mixed> $serialised */
        $serialised = $decision->jsonSerialize();

        foreach (['date', 'basis', 'winning_date', 'profile', 'document_code'] as $member) {
            if (! isset($serialised[$member])) {
                $problems[] = sprintf(
                    'The serialised decision has no `%s`. All of it goes onto the document at issue and '.
                    'is frozen there, because the rule may change and the document may not.',
                    $member,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function everyProfileDeclaresABasis(): array
    {
        $problems = [];
        $service = app(TaxPoint::class);

        /** @var array<string, bool> $enabled */
        $enabled = (array) config('jurisdictions.enabled');

        foreach (array_keys($enabled) as $profile) {
            $declared = config('jurisdictions.tax_point.'.$profile);

            if (! \is_string($declared) || $declared === '') {
                $problems[] = sprintf(
                    'The %s profile declares no tax point basis. It would fall back to the default '.
                    'profile\'s, which is right for a misspelling and wrong for a jurisdiction somebody '.
                    'meant to configure.',
                    $profile,
                );

                continue;
            }

            if (TaxPointBasis::tryFrom($declared) === null) {
                $problems[] = sprintf(
                    'The %s profile declares the basis "%s", which the engine does not implement.',
                    $profile,
                    $declared,
                );

                continue;
            }

            try {
                $service->basisFor((string) $profile);
            } catch (Throwable $e) {
                $problems[] = sprintf('The %s profile\'s basis could not be resolved: %s', $profile, $e->getMessage());
            }
        }

        return $problems;
    }
}
