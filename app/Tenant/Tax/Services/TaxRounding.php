<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Support\Iso\CurrencyRegistry;
use App\Support\Money\Money;
use App\Support\Money\RoundingBasis;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use Brick\Math\BigDecimal;

/**
 * Applies a jurisdiction's rounding policy to a rate group - PF-081.
 *
 * ## This is the step where the policy stops being a configuration value
 *
 * A policy that nothing applies is a row in a file. The claim PF-081 makes is
 * that the policy produces a different, specified answer - so this class is
 * where the mode, the scale and the basis all three get used, and the checks
 * compare two policies on the same lines.
 *
 * ## Rounded to the CURRENCY's exponent, not to the storage scale
 *
 * Storage is four decimal places; a document shows two for SAR, three for JOD
 * and none for JPY. The per-line and per-group answers differ because of
 * rounding to what the document shows, so that is the scale that matters here.
 * Rounding to four places per line and summing gives the same answer as
 * rounding once, and would make this class look like it worked while doing
 * nothing.
 *
 * ## Not the whole computation
 *
 * PF-082 computes a document: several rate groups, a taxable amount per
 * category, exemption reasons, and the BT-110/BT-117 totals. This is the
 * rounding step it is built on, kept separate because the rounding is the part
 * with a jurisdiction's name on it.
 */
final class TaxRounding
{
    /**
     * The tax on one rate group, under one policy.
     *
     * @param  list<Money>  $lines  net amounts, all in the same currency and all at the same rate
     * @param  string  $ratePercent  the rate as published: `15` means fifteen percent
     */
    public function taxOn(array $lines, string $ratePercent, RoundingPolicy $policy): Money
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('a rate group with no lines has no tax');
        }

        $currency = $lines[0]->currency;
        $fraction = BigDecimal::of($ratePercent)->dividedBy('100', Scale::Rate->value);
        $document = $this->documentPolicy($policy, $currency);

        if ($policy->basis === RoundingBasis::PerLine) {
            $total = Money::zero($currency);

            foreach ($lines as $line) {
                $total = $total->plus(Money::of(
                    $line->multipliedBy($fraction, $document)->toPresentation($document),
                    $currency,
                ));
            }

            return $total;
        }

        $net = Money::zero($currency);

        foreach ($lines as $line) {
            $net = $net->plus($line);
        }

        return Money::of($net->multipliedBy($fraction, $document)->toPresentation($document), $currency);
    }

    /** The net of a rate group, unrounded beyond the storage scale. */
    public function netOf(array $lines): Money
    {
        /** @var list<Money> $lines */
        $net = Money::zero($lines[0]->currency);

        foreach ($lines as $line) {
            $net = $net->plus($line);
        }

        return $net;
    }

    /**
     * The policy a profile declares, at the currency's own scale.
     *
     * The scale is not a jurisdiction's choice: JOD has three decimals because
     * ISO 4217 says so. What the jurisdiction chooses is the mode and the
     * basis, and this binds them to the currency the document is in.
     */
    public function policyFor(string $profile, string $currency): RoundingPolicy
    {
        return $this->documentPolicy(RoundingPolicy::forProfile($profile, Scale::Amount), $currency);
    }

    /**
     * The same policy, bound to what the document displays.
     *
     * `Scale` holds the three storage scales and nothing currency-specific, so
     * the exponent is applied through `Money::toPresentation()` rather than by
     * inventing a Scale case per currency.
     */
    private function documentPolicy(RoundingPolicy $policy, string $currency): RoundingPolicy
    {
        // The exponent is read so that an unknown currency fails here rather
        // than silently rounding to two places.
        CurrencyRegistry::exponent($currency);

        return $policy;
    }
}
