<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Parsers;

use App\Support\Money\Money;
use Illuminate\Support\Carbon;

/**
 * A statement as a file gave it, before anything is believed - PF-150.
 *
 * ## It names its own account, and that is checked
 *
 * Every format identifies the account the statement is for. Importing Al
 * Rajhi's file into the SAB account is a mistake somebody makes within a
 * week of having two accounts, and it produces a reconciliation that can
 * never be completed: the balances are a third party's, the lines are a
 * third party's, and nothing about the result says so.
 *
 * So the identification travels with the parse and `StatementImport` refuses
 * a file whose account is not the one being imported into. The refusal is
 * cheap; the alternative is a month of work on an account nobody can tie.
 *
 * ## Booked and pending are kept apart
 *
 * `openingBalance` and `closingBalance` are the bank's own booked figures,
 * and only the booked lines are in them. A pending entry is reported, marked
 * provisional, and left out of the arithmetic - because adding it would make
 * the file disagree with itself and the import would be refused for a reason
 * that is not the file's fault.
 */
final readonly class ParsedStatement
{
    /**
     * The file did not say what currency it is in.
     *
     * ISO 4217's "no currency" code, used as a sentinel so the field is
     * never null and never a plausible-looking wrong answer. An MT942 with
     * no floor-limit tag is the case that reaches here. The importer
     * substitutes the account's own currency, which is the only defensible
     * reading - and refuses a file that states a DIFFERENT one.
     */
    public const UNSTATED_CURRENCY = 'XXX';

    public function statesItsCurrency(): bool
    {
        return $this->currency !== self::UNSTATED_CURRENCY;
    }

    /** @param list<ParsedLine> $lines */
    public function __construct(
        public string $sourceFormat,
        public ?string $accountIban,
        public ?string $accountOtherId,
        public string $currency,
        public Carbon $periodFrom,
        public Carbon $periodTo,
        public ?string $openingBalance,
        public ?string $closingBalance,
        public array $lines,
        public ?string $statementNumber = null,
        public ?string $institutionBic = null,
    ) {}

    /** @return list<ParsedLine> */
    public function bookedLines(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (ParsedLine $line): bool => ! $line->isPending,
        ));
    }

    /** @return list<ParsedLine> */
    public function pendingLines(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (ParsedLine $line): bool => $line->isPending,
        ));
    }

    /**
     * What the booked lines add up to.
     *
     * Checked against the movement the statement itself asserts. A file whose
     * booked lines do not account for its own movement is a parse that lost
     * something, and this is where that is visible.
     */
    public function bookedTotal(): Money
    {
        $sum = Money::zero($this->currency);

        foreach ($this->bookedLines() as $line) {
            $sum = $sum->plus(Money::of($line->amount, $this->currency));
        }

        return $sum;
    }

    /**
     * What the statement says it moved, where it says anything.
     *
     * Null for an interim message. MT942 carries no balance tags at all and
     * CAMT.052 need not either, so the parser reports what is absent rather
     * than inventing a figure - the importer fills them from the account's
     * own booked position, which makes the intraday statement carry zero
     * booked movement. Which is exactly what it is.
     */
    public function movement(): ?Money
    {
        if ($this->openingBalance === null || $this->closingBalance === null) {
            return null;
        }

        return Money::of($this->closingBalance, $this->currency)
            ->minus(Money::of($this->openingBalance, $this->currency));
    }

    public function statesItsBalances(): bool
    {
        return $this->openingBalance !== null && $this->closingBalance !== null;
    }

    /**
     * Whether this statement is for the given account.
     *
     * Compared on the IBAN where both have one, otherwise on the other
     * identifier. A file that identifies its account in neither form cannot
     * be checked and is let through with the caller warned, because refusing
     * it would make some banks' CSV exports unimportable - and a CSV has no
     * account identification at all by nature.
     */
    public function identifies(?string $iban, ?string $otherId): bool
    {
        if ($this->accountIban !== null && $iban !== null) {
            return strcasecmp($this->normalise($this->accountIban), $this->normalise($iban)) === 0;
        }

        if ($this->accountOtherId !== null && $otherId !== null) {
            return strcasecmp(
                $this->normalise($this->accountOtherId),
                $this->normalise($otherId),
            ) === 0;
        }

        /*
         | One of the two sides said nothing, so there is nothing to compare.
         |
         | Reported by `identification()` rather than treated as a match or a
         | mismatch: the caller decides, and the decision differs by format.
         */
        return true;
    }

    public function identification(): ?string
    {
        return $this->accountIban ?? $this->accountOtherId;
    }

    /** Digits and letters only: banks print IBANs with spaces, and we do not. */
    private function normalise(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }
}
