<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use App\Support\Money\Money;
use JsonSerializable;

/**
 * The movement on the tax control accounts, against the return - PF-088.
 *
 * ## Why these two figures differ even when everything is right
 *
 * The control account moves by **accounting date**. The return is assembled by
 * **tax point**. A supply delivered on 30 April and booked on 5 May is in
 * April's return and May's trial balance, and that is not an error - it is the
 * two dates doing their jobs.
 *
 * So the reconciliation's purpose is not to make the figures equal. It is to
 * explain the gap item by item, and to be left with nothing.
 *
 * ## Differences are itemised and classified, never netted
 *
 * A single reconciling figure is the thing this exists to prevent. Two
 * differences of opposite sign net to nothing and hide each other: a timing
 * difference of +3,000 and a manual journal of -3,000 look like a clean
 * reconciliation, and the manual journal is the one that matters.
 *
 * Each class carries its own amount and the ids behind it.
 *
 * ## Being classified is not the same as being explained
 *
 * Only a **timing** difference explains anything: the two dates doing their
 * jobs. A manual journal into a VAT account, a line whose code belongs to
 * another authority and a line that maps to no box are all identified - which
 * is what lets somebody act on them - and every one of them is a defect. They
 * are named AND they still count as unexplained, because the alternative is a
 * reconciliation that classifies a 500 riyal manual journal and then reports
 * itself clean.
 *
 * That was the first version of this class, and it reported a period with a
 * manual journal straight into the output account as reconciled.
 */
final readonly class TaxReconciliation implements JsonSerializable
{
    /**
     * @param  array<string, array{amount: Money, lines: list<string>, detail: string, blocks: bool}>  $classes
     */
    public function __construct(
        public string $jurisdiction,
        public string $periodLabel,
        public string $returnLabel,
        public string $currency,
        public Money $controlMovement,
        public Money $returnFigure,
        public array $classes,
    ) {}

    /** The whole gap between the ledger and the return. */
    public function difference(): Money
    {
        return $this->controlMovement->minus($this->returnFigure);
    }

    /**
     * What is legitimately explained: the classes that are not defects.
     *
     * Timing only. A manual journal is classified and still unexplained -
     * naming a problem is not the same as accounting for it.
     */
    public function explained(): Money
    {
        $sum = Money::zero($this->currency);

        foreach ($this->classes as $class) {
            if ($class['blocks']) {
                continue;
            }

            $sum = $sum->plus($class['amount']);
        }

        return $sum;
    }

    /**
     * The classes that are defects rather than explanations.
     *
     * Named separately so a refusal can say which, rather than quoting a
     * number somebody then has to go and decompose.
     *
     * @return array<string, array{amount: Money, lines: list<string>, detail: string, blocks: bool}>
     */
    public function blockingClasses(): array
    {
        return array_filter(
            $this->classes,
            static fn (array $class): bool => $class['blocks'] && ! $class['amount']->isZero(),
        );
    }

    /**
     * What nothing accounts for.
     *
     * This is the figure that blocks a close. It is the only one in the report
     * that is a problem by itself: every class above it is a difference
     * somebody can name.
     */
    public function unexplained(): Money
    {
        return $this->difference()->minus($this->explained());
    }

    public function isClean(): bool
    {
        return $this->unexplained()->isZero();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $classes = [];

        foreach ($this->classes as $name => $class) {
            $classes[$name] = [
                'amount' => (string) $class['amount'],
                'lines' => \count($class['lines']),
                'detail' => $class['detail'],
                'blocks' => $class['blocks'],
            ];
        }

        return [
            'jurisdiction' => $this->jurisdiction,
            'period' => $this->periodLabel,
            'return' => $this->returnLabel,
            'currency' => $this->currency,
            'control_movement' => (string) $this->controlMovement,
            'return_figure' => (string) $this->returnFigure,
            'difference' => (string) $this->difference(),
            'classes' => $classes,
            'explained' => (string) $this->explained(),
            'unexplained' => (string) $this->unexplained(),
        ];
    }
}
