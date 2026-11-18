<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Catalog\Services\CurrencyPolicy;
use App\Tenant\Catalog\Services\ExchangeRates;
use App\Tenant\Purchasing\Exceptions\BillRefused;
use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Purchasing\Services\Bills;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a bill in a foreign currency books at - PF-133.
 *
 * ## The criterion these close
 *
 * > A bill in a foreign currency books at the rate on its tax point date -
 * > the columns are there and the rate is not yet fetched.
 *
 * The columns were there, the rate was not fetched, and the consequence was
 * worse than a blank column. `BillPostedRule` built every posting line as
 * `Money::of($event['amount'], $context->functionalCurrency)` while the
 * event's figures were the supplier's own - so a 1,000 EUR purchase posted
 * 1,000 of the entity's own currency to expense and credited the supplier
 * 1,150 of it.
 *
 * Nothing refused it. The entry balanced, because every line was wrong by
 * the same factor. `journal_lines`' translation trigger - the one that
 * refuses a foreign line with no rate - saw lines claiming to be in the
 * functional currency and had nothing to object to. `ledger:verify` saw a
 * balanced entry. The figure was of the right type, the right shape, and
 * out by the exchange rate.
 *
 * ## Why these are checks and not assertions about structure
 *
 * Every one of them is written to fail if the translation is removed or
 * moved to the wrong date. "Books at the rate on its tax point date" has
 * three separable ways of being wrong - no rate, the wrong date's rate, and
 * a rate recorded but not applied - and a check that only looked at
 * `bills.fx_rate` would pass all three of the last two.
 *
 * The fixture rates are recorded in the market set inside the suite's own
 * transaction, which is rolled back. They are deliberately nothing like a
 * real EUR rate: a figure that cannot be mistaken for the market's makes it
 * obvious in a failure message that the number came from here.
 */
final class PurchasingCoverageRules
{
    use \Tests\Support\Concerns\EntersBills;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /**
     * The tax point, which is what a purchase is booked at.
     *
     * Eight days before the posting date, so that a rate fetched for "the
     * document's date" and a rate fetched for "ours" cannot be the same row.
     */
    private const TAX_POINT = '2026-05-10';

    /** The rate on the tax point. 1,000 EUR of cost is 4,100 of the books'. */
    private const TAX_POINT_RATE = '4.100000000000';

    /**
     * The rate on the POSTING date, more than twice the other.
     *
     * A bill booked at this one is wrong by 119%, which no rounding
     * tolerance absorbs and no reviewer mistakes for a near miss.
     */
    private const POSTING_DATE_RATE = '9.000000000000';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['a bill in a foreign currency (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedThePayableSide();
            app(CurrencyPolicy::class)->enable($this->entity(), 'EUR');
            app(CurrencyPolicy::class)->enable($this->entity(), 'JPY');

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'a bill takes the rate on its tax point, not the posting date\'s' => fn (): array => $this->theTaxPointsRate(),
                'and records the rate\'s own date, not the date it asked for' => fn (): array => $this->theRatesOwnDate(),
                'the expense is the cost in the books\' currency' => fn (): array => $this->theExpenseIsTranslated(),
                'and the supplier is owed what the document says, in their currency' => fn (): array => $this->thePayableStaysForeign(),
                'every translated line says which rate produced it' => fn (): array => $this->everyLineCarriesTheRate(),
                'the reverse charge translates both sides from one original' => fn (): array => $this->reverseChargeSidesAgree(),
                'the rounding residual is a line of its own' => fn (): array => $this->theResidualIsItsOwnLine(),
                'a bill in the books\' own currency carries no rate at all' => fn (): array => $this->functionalBillsAreNotTranslated(),
                'a missing rate does not stop the bill being recorded' => fn (): array => $this->entryIsNotBlocked(),
                'but it refuses the posting, rather than booking the face value' => fn (): array => $this->postingIsRefused(),
                'and the ledger still proves its own invariants' => fn (): array => [],
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * The date the rate comes from.
     *
     * A bill dated the 10th that we post on the 18th is booked at the 10th's
     * rate: IAS 21 books a transaction at the rate on the date of the
     * transaction, and for a purchase that date is the tax point. Taking the
     * posting date's rate instead would move the expense by whatever the
     * currency did in between and leave an exchange difference that cannot
     * be derived from the document.
     *
     * @return list<string>
     */
    private function theTaxPointsRate(): array
    {
        $violations = [];
        $this->recordBothRates();

        $bill = $this->enterForeignBill();

        if ($bill->fx_rate === null) {
            return ['the bill records no rate at all, so it is not booked at any date\'s rate'];
        }

        if (! BigDecimal::of((string) $bill->fx_rate)->isEqualTo(self::TAX_POINT_RATE)) {
            $violations[] = sprintf(
                'the bill booked at %s. The rate on its tax point (%s) is %s and the rate on the '.
                'posting date is %s: a bill takes the first, because the transaction happened on the '.
                'tax point and the posting date is only when we wrote it down',
                (string) $bill->fx_rate,
                self::TAX_POINT,
                self::TAX_POINT_RATE,
                self::POSTING_DATE_RATE,
            );
        }

        if ((string) $bill->fx_rate_date?->toDateString() !== self::TAX_POINT) {
            $violations[] = sprintf(
                'the bill says it booked at a rate dated %s, and the tax point is %s',
                (string) $bill->fx_rate_date?->toDateString(),
                self::TAX_POINT,
            );
        }

        return $violations;
    }

    /**
     * Which rate, which is not the same as which date we asked about.
     *
     * The lookup is effective-dated: a market that was closed on the tax
     * point has no rate for it, and the answer is the last one before it.
     * The document has to record the date of the rate it actually used, or
     * "why is this figure what it is" is answered with a date on which no
     * rate existed - and whoever checks it finds nothing there and concludes
     * the figure was invented.
     *
     * @return list<string>
     */
    private function theRatesOwnDate(): array
    {
        $rates = app(ExchangeRates::class);
        $friday = Carbon::parse(self::TAX_POINT)->subDays(2);

        // Only this one, dated BEFORE the tax point. Nothing on the day.
        $rates->record('EUR', 'SAR', $friday, self::TAX_POINT_RATE);

        $bill = $this->enterForeignBill();

        if ((string) $bill->fx_rate_date?->toDateString() === self::TAX_POINT) {
            return [sprintf(
                'the bill records its rate as dated %s, which is the date it asked about - there is '.
                'no rate on that date. The rate it used is dated %s, and recording the question '.
                'rather than the answer points an auditor at a row that does not exist',
                self::TAX_POINT,
                $friday->toDateString(),
            )];
        }

        if ((string) $bill->fx_rate_date?->toDateString() !== $friday->toDateString()) {
            return [sprintf(
                'the bill records its rate as dated %s, and the rate it had to use is dated %s',
                (string) $bill->fx_rate_date?->toDateString(),
                $friday->toDateString(),
            )];
        }

        return [];
    }

    /**
     * The expense, in the currency the books are kept in.
     *
     * 1,000 EUR at 4.1 is 4,100. The failure this replaces posted 1,000 -
     * the document's own figure under the functional currency's name - and
     * balanced while doing it.
     *
     * @return list<string>
     */
    private function theExpenseIsTranslated(): array
    {
        $violations = [];
        $this->recordBothRates();

        $bill = $this->postForeignBill();
        $lines = $this->journalLines($bill);

        $functional = $this->functionalOnAccount($lines, $this->expenseAccountId);
        $document = $this->documentAmountOnAccount($lines, $this->expenseAccountId);

        if (! BigDecimal::of($functional)->isEqualTo('4100.0000')) {
            $violations[] = sprintf(
                'the expense is %s in the books and 1,000 EUR at %s is 4,100. %s',
                $functional,
                self::TAX_POINT_RATE,
                BigDecimal::of($functional)->isEqualTo('1000.0000')
                    ? 'It is the document\'s own figure with the functional currency\'s name on it, '.
                      'which is what a bill posted without its rate looks like: nothing refuses it, '.
                      'because every line is wrong by the same factor and the entry still balances'
                    : 'Neither figure follows from the rate on the tax point.',
            );
        }

        if (! BigDecimal::of($document)->isEqualTo('1000.0000')) {
            $violations[] = sprintf(
                'the expense line is denominated as %s and the supplier billed 1,000 EUR. The '.
                'document amount is what ties the posting back to the invoice',
                $document,
            );
        }

        $currencies = $this->currenciesOnAccount($lines, $this->expenseAccountId);

        if ($currencies !== ['EUR']) {
            $violations[] = sprintf(
                'the expense line is denominated in %s and the bill is in EUR',
                implode(', ', $currencies) === '' ? '(nothing)' : implode(', ', $currencies),
            );
        }

        return $violations;
    }

    /**
     * What the supplier is owed, and in what.
     *
     * The payable is the figure a payment run reads and a supplier
     * statement is reconciled against, so it stays in their currency: 1,150
     * EUR, worth 4,715 in the books. A payable recorded as 1,150 of the
     * functional currency would be paid as 1,150 of it - the supplier
     * underpaid by three quarters, and the aging report would agree with the
     * payment.
     *
     * @return list<string>
     */
    private function thePayableStaysForeign(): array
    {
        $violations = [];
        $this->recordBothRates();

        $bill = $this->postForeignBill();
        $lines = $this->journalLines($bill);

        $payable = array_values(array_filter(
            $lines,
            static fn (array $line): bool => (string) ($line['role'] ?? '') === 'accounts_payable',
        ));

        if ($payable === []) {
            return ['the posting credits no payable, so the supplier is owed nothing by it'];
        }

        $document = BigDecimal::zero();
        $functional = BigDecimal::zero();

        foreach ($payable as $line) {
            $document = $document->plus((string) $line['amount']);
            $functional = $functional->plus((string) $line['functional_amount']);

            if ((string) $line['currency'] !== 'EUR') {
                $violations[] = sprintf(
                    'the payable is denominated in %s and the supplier billed in EUR. A payment run '.
                    'reads this balance to decide what to send them',
                    (string) $line['currency'],
                );
            }
        }

        if (! $document->isEqualTo('-1150.0000')) {
            $violations[] = sprintf('the supplier is owed %s EUR and the document says 1,150', $document);
        }

        if (! $functional->isEqualTo('-4715.0000')) {
            $violations[] = sprintf(
                'the payable is worth %s in the books and 1,150 EUR at %s is 4,715',
                $functional,
                self::TAX_POINT_RATE,
            );
        }

        return $violations;
    }

    /**
     * Every line, not merely the document.
     *
     * A rate on the bill with untranslated journal lines is the worst of the
     * three failures, because the document looks right. The return is filed
     * from the journal and the accounts are read from the journal, so a rate
     * that reached the header and not the lines is a rate nobody used.
     *
     * @return list<string>
     */
    private function everyLineCarriesTheRate(): array
    {
        $violations = [];
        $this->recordBothRates();

        $bill = $this->postForeignBill();
        $lines = $this->journalLines($bill);

        if ($lines === []) {
            return ['the bill posted no journal lines'];
        }

        foreach ($lines as $line) {
            /*
             | The residual is the exception, and the only one.
             |
             | It exists because the books are kept to four places, not
             | because anything was converted, so it carries no rate - and a
             | rate on it would be a translation of an amount that was never
             | denominated in anything else.
             */
            if ((string) ($line['role'] ?? '') === 'rounding_difference') {
                continue;
            }

            if ($line['fx_rate'] === null) {
                $violations[] = sprintf(
                    'a line of %s %s carries no rate. A missing rate is not a rate of 1: it makes a '.
                    'translated line and an untranslated one indistinguishable afterwards, which is '.
                    'the question asked whenever a foreign balance looks wrong',
                    (string) $line['amount'],
                    (string) $line['currency'],
                );

                continue;
            }

            if (! BigDecimal::of((string) $line['fx_rate'])->isEqualTo(self::TAX_POINT_RATE)) {
                $violations[] = sprintf(
                    'a line booked at %s and the bill booked at %s. One bill is one transaction at '.
                    'one rate, and two rates inside an entry is a difference that lands in whichever '.
                    'account was translated second',
                    (string) $line['fx_rate'],
                    self::TAX_POINT_RATE,
                );
            }

            if ((string) $line['fx_rate_as_of'] !== self::TAX_POINT) {
                $violations[] = sprintf(
                    'a line says its rate is dated %s and the tax point is %s',
                    (string) $line['fx_rate_as_of'],
                    self::TAX_POINT,
                );
            }
        }

        return $violations;
    }

    /**
     * The reverse charge, where rounding must not separate the two sides.
     *
     * The recipient owes the tax and may claim it back, which is an output
     * and an input of the same amount in two different boxes of the return.
     * Translating the output from the already-rounded input - or rounding
     * each independently - can leave the two boxes a hundredth apart, and
     * that difference is precisely what the authority's comparison looks at.
     *
     * @return list<string>
     */
    private function reverseChargeSidesAgree(): array
    {
        $this->recordBothRates();

        /*
         | A tax figure chosen so that half a unit is at stake.
         |
         | 33.335 EUR at 4.1 is 136.6735, which is 136.6735 at four places -
         | and 136.67 if somebody rounds to two first. Any treatment that
         | rounds the sides separately or chains one off the other shows up
         | here as an input and an output that do not cancel.
         */
        $bill = $this->postForeignBill(lines: [$this->reverseChargeLine('222.2300', '33.3345')]);
        $lines = $this->journalLines($bill);

        $input = BigDecimal::zero();
        $output = BigDecimal::zero();

        foreach ($lines as $line) {
            if ((string) ($line['role'] ?? '') === 'vat_input') {
                $input = $input->plus((string) $line['functional_amount']);
            }

            if ((string) ($line['role'] ?? '') === 'vat_output') {
                $output = $output->plus((string) $line['functional_amount']);
            }
        }

        if ($input->isZero()) {
            return ['the reverse charge posted no input tax, so there is nothing to compare'];
        }

        if (! $input->plus($output)->isZero()) {
            return [sprintf(
                'the reverse charge debited %s of input tax and credited %s of output tax. The two '.
                'are the same supply and must be the same figure: the authority compares the input '.
                'box against the output box, and %s is what they would find',
                $input,
                $output,
                $input->plus($output),
            )];
        }

        return [];
    }

    /**
     * Where the parts do not add up to the whole.
     *
     * Three lines of 1 EUR at 3.333333333333 are 3.3333 each, and the 3 EUR
     * they come to is 10.0000 - so the entry is a hundredth of a unit short
     * of balancing. The difference cannot be absorbed into a translated
     * line, because `journal_lines` re-derives every functional amount from
     * that line's own rate and refuses one that does not follow from it. It
     * cannot be dropped either: the Poster refuses an entry that does not
     * balance, which would make this bill unpostable for the sake of 0.0001.
     *
     * So it posts to `rounding_difference`, where somebody can see it. A
     * residual that stops being pennies is how a wrong rate announces
     * itself, and an account nobody can reconcile cannot announce anything.
     *
     * @return list<string>
     */
    private function theResidualIsItsOwnLine(): array
    {
        $violations = [];
        app(ExchangeRates::class)->record('EUR', 'SAR', Carbon::parse(self::TAX_POINT), '3.333333333333');

        $bill = $this->postForeignBill(lines: [
            $this->untaxedLine('Freight, leg one'),
            $this->untaxedLine('Freight, leg two'),
            $this->untaxedLine('Freight, leg three'),
        ]);

        $lines = $this->journalLines($bill);

        $residual = BigDecimal::zero();
        $total = BigDecimal::zero();

        foreach ($lines as $line) {
            $total = $total->plus((string) $line['functional_amount']);

            if ((string) ($line['role'] ?? '') === 'rounding_difference') {
                $residual = $residual->plus((string) $line['functional_amount']);

                if ($line['fx_rate'] !== null) {
                    $violations[] = sprintf(
                        'the residual carries a rate of %s. It is not a translation of anything: it '.
                        'exists because the books are kept to four places, and a rate on it asserts '.
                        'an original amount that never existed',
                        (string) $line['fx_rate'],
                    );
                }
            }
        }

        if (! $residual->isEqualTo('0.0001')) {
            $violations[] = sprintf(
                'the rounding residual posted is %s. Three lines of 1 EUR at 3.333333333333 are '.
                '3.3333 each and the 3 EUR they come to is 10.0000, so 0.0001 has to go somewhere '.
                'that can be reconciled',
                $residual,
            );
        }

        if (! $total->isZero()) {
            $violations[] = sprintf('the entry is out by %s in the functional currency', $total);
        }

        return $violations;
    }

    /**
     * And nothing is translated when nothing needed to be.
     *
     * The common case, and the one a translation added everywhere would
     * break. A rate of 1 on a bill already in the entity's own currency
     * asserts that a translation happened and came out even - a different
     * claim from no translation at all, and one that cannot be told from it
     * afterwards. The database refuses both halves; this is the service's.
     *
     * @return list<string>
     */
    private function functionalBillsAreNotTranslated(): array
    {
        $violations = [];
        $this->recordBothRates();

        $bill = $this->post($this->enterBill());

        if ($bill->fx_rate !== null || $bill->fx_rate_date !== null) {
            $violations[] = sprintf(
                'a bill in SAR, which is the currency the books are kept in, records a rate of %s '.
                'dated %s',
                (string) $bill->fx_rate,
                (string) $bill->fx_rate_date?->toDateString(),
            );
        }

        foreach ($this->journalLines($bill) as $line) {
            if ($line['fx_rate'] !== null) {
                $violations[] = sprintf(
                    'a line of a SAR bill carries a rate of %s',
                    (string) $line['fx_rate'],
                );
            }

            if ((string) ($line['role'] ?? '') === 'rounding_difference') {
                $violations[] = 'a bill needing no translation posted a rounding residual, which '.
                    'means the figures were put through a conversion that was not required';
            }
        }

        return $violations;
    }

    /**
     * Recording the supplier's invoice is not blocked by our feed.
     *
     * A bill is a record of something that has already happened. A rate
     * service that has not run is our problem and not the supplier's, and a
     * system that refuses the entry sends somebody to a spreadsheet - where
     * the invoice then sits, outside every control this module exists for.
     * The refusal belongs at the posting, which is the act that needs a
     * figure in the books' own currency.
     *
     * @return list<string>
     */
    private function entryIsNotBlocked(): array
    {
        // Deliberately no rate for JPY.
        try {
            $bill = $this->enterForeignBill(currency: 'JPY');
        } catch (BillRefused $e) {
            return [sprintf(
                'a bill in a currency with no rate on file could not be recorded: %s. Entering a '.
                'supplier invoice is writing down what happened, and a feed that has not run is not '.
                'a reason the business cannot write it down',
                $e->getMessage(),
            )];
        }

        if ($bill->fx_rate !== null) {
            return [sprintf(
                'the bill records a rate of %s and no JPY rate was on file, so it came from '.
                'somewhere other than the rate service',
                (string) $bill->fx_rate,
            )];
        }

        return [];
    }

    /**
     * And the posting is where the absence becomes a refusal.
     *
     * There is no safe fallback at this point. A rate of 1 asserts that
     * 150,000 JPY and 150,000 SAR are the same money and balances perfectly
     * while doing it; today's rate values a purchase at a figure the
     * document cannot explain. So it refuses, and the refusal names the
     * date a rate has to be loaded for - because the person reading it has
     * to go and load one.
     *
     * @return list<string>
     */
    private function postingIsRefused(): array
    {
        $bill = $this->enterForeignBill(currency: 'JPY');

        try {
            $this->post($bill);
        } catch (BillRefused $e) {
            $message = $e->getMessage();

            if (! str_contains($message, self::TAX_POINT)) {
                return [sprintf(
                    'the refusal does not name the tax point (%s), which is the date a rate has to '.
                    'be loaded for: %s',
                    self::TAX_POINT,
                    $message,
                )];
            }

            return [];
        }

        return [
            'a bill in JPY posted with no JPY rate on file. Whatever figures reached the ledger were '.
            'not derived from any rate, and because every line would be wrong by the same factor the '.
            'entry balances and nothing downstream notices',
        ];
    }

    // ---- Fixture -----------------------------------------------------------

    /**
     * Both rates, so a check can tell which date was asked about.
     *
     * A single rate would be returned whichever date the service looked up,
     * and a check against it would pass with the lookup pointed at the
     * posting date, at the document date or at today.
     */
    private function recordBothRates(): void
    {
        $rates = app(ExchangeRates::class);
        $rates->record('EUR', 'SAR', Carbon::parse(self::TAX_POINT), self::TAX_POINT_RATE);
        $rates->record('EUR', 'SAR', $this->date->copy(), self::POSTING_DATE_RATE);
    }

    /** @param list<array<string, mixed>>|null $lines */
    private function enterForeignBill(?array $lines = null, string $currency = 'EUR'): Bill
    {
        return $this->enterBill(
            lines: $lines,
            number: 'FX-'.Str::upper(Str::random(6)),
            documentDate: Carbon::parse(self::TAX_POINT),
            taxPoint: Carbon::parse(self::TAX_POINT),
            postingDate: $this->date->copy(),
            currency: $currency,
        );
    }

    /** @param list<array<string, mixed>>|null $lines */
    private function postForeignBill(?array $lines = null, string $currency = 'EUR'): Bill
    {
        return $this->post($this->enterForeignBill($lines, $currency));
    }

    private function post(Bill $bill): Bill
    {
        return app(Bills::class)->post($bill, $this->entity(), $this->actorId());
    }

    /**
     * A line with no tax, so the residual check is arithmetic and nothing else.
     *
     * The zero-rated code rather than no code at all: `journal_lines` pairs
     * the tax point with the tax code and refuses one without the other, so
     * a line with no code is a different posting shape and not a simpler
     * version of this one.
     *
     * @return array<string, mixed>
     */
    private function untaxedLine(string $description): array
    {
        return [
            'description' => $description,
            'quantity' => '1',
            'unit_price' => '1.0000',
            'net_amount' => '1.0000',
            'expense_account_id' => $this->expenseAccountId,
            'tax_code_id' => $this->zeroRatedTaxCodeId,
            'tax_category' => 'Z',
            'tax_rate' => '0.000000',
            'tax_amount' => '0.0000',
        ];
    }

    /**
     * The journal lines, with the three columns a translation is judged by.
     *
     * `amount` with `currency` is what the document said, `functional_amount`
     * is what the books carry, and `fx_rate` is the only thing that can
     * explain the second from the first.
     *
     * @return list<array<string, mixed>>
     */
    private function journalLines(Bill $bill): array
    {
        $rows = DB::connection('tenant')->table('journal_lines as l')
            ->leftJoin('accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('account_roles as r', 'r.account_id', '=', 'a.id')
            ->where('l.journal_entry_id', (string) $bill->journal_entry_id)
            ->select([
                'l.account_id', 'l.amount', 'l.currency', 'l.functional_amount',
                'l.fx_rate', 'l.fx_rate_as_of', 'r.role',
            ])
            ->get();

        return $rows->map(static fn (object $row): array => (array) $row)->all();
    }

    /** @param list<array<string, mixed>> $lines */
    private function functionalOnAccount(array $lines, string $accountId): string
    {
        $total = BigDecimal::zero();

        foreach ($lines as $line) {
            if ((string) ($line['account_id'] ?? '') === $accountId) {
                $total = $total->plus((string) $line['functional_amount']);
            }
        }

        return (string) $total->toScale(4);
    }

    /** @param list<array<string, mixed>> $lines */
    private function documentAmountOnAccount(array $lines, string $accountId): string
    {
        $total = BigDecimal::zero();

        foreach ($lines as $line) {
            if ((string) ($line['account_id'] ?? '') === $accountId) {
                $total = $total->plus((string) $line['amount']);
            }
        }

        return (string) $total->toScale(4);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<string>
     */
    private function currenciesOnAccount(array $lines, string $accountId): array
    {
        $codes = [];

        foreach ($lines as $line) {
            if ((string) ($line['account_id'] ?? '') === $accountId) {
                $codes[(string) $line['currency']] = true;
            }
        }

        $codes = array_keys($codes);
        sort($codes);

        return $codes;
    }
}
