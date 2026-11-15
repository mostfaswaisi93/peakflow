<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use App\Support\Hashing\Canonicalizer;
use App\Support\Money\Money;
use JsonSerializable;

/**
 * A period's return, box by box - PF-086.
 *
 * ## The canonical form is what gets hashed and stored
 *
 * `Canonicalizer` is RFC 8785, so two processes that build the same return
 * produce the same bytes - no key ordering, no float formatting, no locale.
 * That is what makes "re-printable byte-identically" a property rather than a
 * hope: the filing stores those bytes, and the reprint is the same bytes with
 * the hash checked.
 *
 * ## The net due is computed, never summed from a box
 *
 * Output tax less input tax. A form has a net box, and that box's figure comes
 * from this arithmetic rather than from whatever somebody mapped to it - a
 * `net` box a tax code could post into would be a return whose bottom line
 * disagreed with its own boxes.
 */
final readonly class TaxReturn implements JsonSerializable
{
    /**
     * @param  list<TaxReturnBox>  $boxes
     * @param  list<array{corrects: string, box: string, net: string, tax: string, reason: string}>  $adjustments
     *     corrections to EARLIER filed periods, reported in this one - PF-087
     */
    public function __construct(
        public string $jurisdiction,
        public string $form,
        public string $label,
        public string $periodFrom,
        public string $periodUntil,
        public string $currency,
        public array $boxes,
        public array $adjustments = [],
    ) {}

    public function outputTax(): Money
    {
        return $this->sumOf('output');
    }

    public function inputTax(): Money
    {
        return $this->sumOf('input');
    }

    /**
     * Tax carried by corrections to earlier periods - PF-087.
     *
     * Signed: a correction can go either way, and a schedule holding only
     * absolute values would need a direction column somebody would eventually
     * disagree with.
     */
    public function adjustmentTax(): Money
    {
        $sum = Money::zero($this->currency);

        foreach ($this->adjustments as $adjustment) {
            $sum = $sum->plus(Money::of($adjustment['tax'], $this->currency));
        }

        return $sum;
    }

    /**
     * What is owed, or reclaimable when negative.
     *
     * Output less input PLUS the corrections reported in this period. A net
     * due computed without them is the wrong figure filed on purpose: the
     * correction is part of what this period owes, which is the whole reason
     * it was reported here rather than left in the period it corrects.
     */
    public function netDue(): Money
    {
        return $this->outputTax()->minus($this->inputTax())->plus($this->adjustmentTax());
    }

    public function boxFor(string $box): ?TaxReturnBox
    {
        foreach ($this->boxes as $candidate) {
            if ($candidate->box === $box) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<TaxReturnBox> */
    public function boxesWithTaxButNoBase(): array
    {
        return array_values(array_filter(
            $this->boxes,
            static fn (TaxReturnBox $box): bool => $box->hasTaxWithoutABase(),
        ));
    }

    public function lineCount(): int
    {
        $count = 0;

        foreach ($this->boxes as $box) {
            $count += $box->lineCount();
        }

        return $count;
    }

    /** The bytes that are hashed, stored and reprinted. */
    public function canonical(): string
    {
        return Canonicalizer::encode($this);
    }

    public function hash(): string
    {
        return Canonicalizer::hash($this);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'jurisdiction' => $this->jurisdiction,
            'form' => $this->form,
            'label' => $this->label,
            'period' => ['from' => $this->periodFrom, 'until' => $this->periodUntil],
            'currency' => $this->currency,
            'boxes' => array_map(
                static fn (TaxReturnBox $box): array => $box->jsonSerialize(),
                $this->boxes,
            ),
            /*
             | The schedule is part of the FILED document, so it is inside the
             | canonical form and under the hash. A correction reported in a
             | return and absent from its snapshot would be a figure in the
             | net due that the reprint could not explain.
             */
            'adjustments' => $this->adjustments,
            'totals' => [
                'output_tax' => (string) $this->outputTax(),
                'input_tax' => (string) $this->inputTax(),
                'adjustments' => (string) $this->adjustmentTax(),
                'net_due' => (string) $this->netDue(),
            ],
        ];
    }

    private function sumOf(string $side): Money
    {
        $sum = Money::zero($this->currency);

        foreach ($this->boxes as $box) {
            if ($box->side === $side) {
                $sum = $sum->plus($box->tax);
            }
        }

        return $sum;
    }
}
