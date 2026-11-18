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
use App\Tenant\Purchasing\Services\PayablesAging;
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
                'the payables figure is the ledger\'s posted balance, in the books\' currency' => fn (): array => $this->theControlBalanceIsFunctionalAndPosted(),
                'and DPO is one currency, named, with what it left out counted' => fn (): array => $this->dpoIsOneCurrency(),
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
    /**
     * What the accounts-payable control account is worth - PF-144.
     *
     * ## Two defects, and the second one accused the ledger
     *
     * `PayablesAging::controlAccountBalance()` summed `journal_lines.amount`
     * - the line's amount in the DOCUMENT's currency - and labelled the
     * total with the entity's functional currency. The ageing path was saved
     * from it by `reconcile()`, which refuses a foreign currency outright,
     * so nothing in a single-currency fixture could see it; `daysPayable\
     * Outstanding()` divides by the same figure and had no such guard.
     *
     * And it filtered no entry status, so a DRAFT counted. A draft is
     * somebody's unfinished work - no period of its own, nothing posted -
     * and counting it made the control account disagree with the ageing
     * total. The report then announced that one of the two was wrong and
     * offered three causes, none of which was "somebody has a journal open".
     * That is worse than a wrong number, because it names a culprit.
     *
     * The check reads the ledger directly and asks the report to agree with
     * it, which is the only form this assertion can take: a hard-coded
     * figure would have to be rederived every time the fixture's bill
     * changed, and the point is not the number but which column it came
     * from.
     *
     * @return list<string>
     */
    private function theControlBalanceIsFunctionalAndPosted(): array
    {
        $this->recordBothRates();

        /*
         | One bill in each currency, and the home one is not decoration.
         |
         | DPO returns early with no `payables` key when the window holds no
         | purchases in the functional currency - correctly, because there is
         | nothing to divide by - and the figure under test is only reached
         | on the other branch. A fixture with the euro bill alone would have
         | asserted against an absent key and passed whatever the production
         | code did.
         */
        $this->post($this->enterBill(
            number: 'HOME-'.Str::upper(Str::random(6)),
            documentDate: Carbon::parse(self::TAX_POINT),
            taxPoint: Carbon::parse(self::TAX_POINT),
            postingDate: $this->date->copy(),
            currency: $this->entity()->functional_currency,
        ));

        $this->postForeignBill();

        $control = $this->controlAccountId();

        $posted = $this->sumOnAccount($control, 'functional_amount', ['posted', 'reversed']);
        $inDocumentCurrency = $this->sumOnAccount($control, 'amount', ['posted', 'reversed']);

        $problems = [];

        if (BigDecimal::of($posted)->isEqualTo($inDocumentCurrency)) {
            return ['the fixture posted nothing foreign to the payables control account, so this '.
                'check cannot tell the document column from the functional one and proves nothing'];
        }

        $reported = (string) ($this->dpo()['payables'] ?? '');
        $expected = BigDecimal::of($posted)->negated();

        if (! BigDecimal::of($reported === '' ? '0' : $reported)->isEqualTo($expected)) {
            $problems[] = sprintf(
                'the payables figure is %s and the ledger\'s posted payables are %s. The document-'.
                'currency column would have given %s - a thousand euros read as a thousand riyals, '.
                'which is the figure a DPO then divides by.',
                $reported === '' ? '(absent)' : $reported,
                (string) $expected,
                (string) BigDecimal::of($inDocumentCurrency)->negated(),
            );
        }

        /*
         | And a draft does not move it.
         |
         | Written straight onto the table, because there is no service that
         | will put a draft on a control account - which is the point: the
         | row exists in the wild (an import halfway through, a screen
         | somebody left open) and the report has to ignore it.
         */
        $this->aDraftOnTheControlAccount($control);

        $afterTheDraft = (string) ($this->dpo()['payables'] ?? '');

        if ($afterTheDraft !== $reported) {
            $problems[] = sprintf(
                'a DRAFT entry on the payables control account moved the reported balance from %s to '.
                '%s. The ageing total does not count it, so the report would declare a difference and '.
                'blame a bill approved and never posted, a journal posted straight to the control '.
                'account, or a payment recorded against the ledger - and it is none of those.',
                $reported,
                $afterTheDraft,
            );
        }

        return $problems;
    }

    /**
     * DPO is a ratio, so both its halves must be in the same currency.
     *
     * It summed `bills.net_amount` across every currency a supplier had ever
     * billed in. A thousand dollars and a thousand riyals made two thousand
     * of nothing, divided into a balance that was itself adding currencies,
     * and the quotient printed to one decimal place - which is what makes a
     * ratio like this dangerous. It looks precise, and nobody reads a number
     * with a decimal point and wonders whether its halves were denominated
     * in the same thing.
     *
     * DSO on the receivables side already refused to, and the two sit beside
     * each other on a page and get subtracted from one another.
     *
     * @return list<string>
     */
    private function dpoIsOneCurrency(): array
    {
        $this->recordBothRates();

        $functional = $this->post($this->enterBill(
            number: 'HOME-'.Str::upper(Str::random(6)),
            documentDate: Carbon::parse(self::TAX_POINT),
            taxPoint: Carbon::parse(self::TAX_POINT),
            postingDate: $this->date->copy(),
            currency: $this->entity()->functional_currency,
        ));

        $alone = $this->dpo();

        if (($alone['purchases'] ?? '0') === '0' || ($alone['dpo'] ?? null) === null) {
            return ['a bill in the books\' own currency was posted inside the window and DPO came '.
                'back empty, so the denominator did not see it and this check proves nothing'];
        }

        $problems = [];

        if (($alone['currency'] ?? null) !== $functional->currency) {
            $problems[] = sprintf(
                'DPO does not name the currency it was taken in (it says %s). A reader comparing it '.
                'with DSO has no way to know the two were taken on the same basis.',
                (string) ($alone['currency'] ?? '(nothing)'),
            );
        }

        if (! str_contains((string) ($alone['formula'] ?? ''), 'purchases')) {
            $problems[] = 'DPO is reported without its formula, so two people comparing their figures '.
                'cannot tell whether they computed the same ratio';
        }

        if (\array_key_exists('excluded_other_currency', $alone)) {
            $problems[] = 'DPO reports documents excluded for their currency when there are none, so '.
                'the line is noise and a reader learns to skip it';
        }

        // Now a euro bill in the same window. The denominator must not move.
        $this->postForeignBill();

        $withForeign = $this->dpo();

        if ((string) $withForeign['purchases'] !== (string) $alone['purchases']) {
            $problems[] = sprintf(
                'a euro bill changed the purchases denominator from %s to %s. Its face value was added '.
                'to a figure in %s, so the ratio divides one currency by two.',
                (string) $alone['purchases'],
                (string) $withForeign['purchases'],
                $functional->currency,
            );
        }

        if ((int) ($withForeign['excluded_other_currency'] ?? 0) < 1) {
            $problems[] = 'a euro bill was left out of DPO and the report does not say so. A figure '.
                'that silently covers part of the window is the one that gets quoted in a board pack.';
        }

        return $problems;
    }

    /** @return array<string, mixed> */
    private function dpo(): array
    {
        return app(PayablesAging::class)->daysPayableOutstanding(
            $this->entityId,
            $this->date->copy()->subDays(89),
            $this->date->copy(),
        );
    }

    /** The accounts-payable control account's id. */
    private function controlAccountId(): string
    {
        return (string) $this->connection->table('account_roles')
            ->where('role', \App\Tenant\Ledger\Enums\ControlRole::AccountsPayable->value)
            ->value('account_id');
    }

    /**
     * One column of one account, over the entry statuses given.
     *
     * @param  list<string>  $statuses
     */
    private function sumOnAccount(string $accountId, string $column, array $statuses): string
    {
        return (string) ($this->connection->table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $accountId)
            ->where('e.entity_id', $this->entityId)
            ->whereIn('e.status', $statuses)
            ->sum('l.'.$column) ?? '0');
    }

    /**
     * A balanced draft entry touching the control account.
     *
     * Balanced so that nothing else in the suite has an opinion about it -
     * the global ledger proof is about what posted, and a draft that did not
     * balance would be a second thing for a reader of a failure to wonder
     * about.
     */
    private function aDraftOnTheControlAccount(string $controlAccountId): void
    {
        $entryId = (string) Str::ulid();

        $periodId = (string) $this->connection->table('journal_entries')
            ->where('entity_id', $this->entityId)
            ->orderByDesc('accounting_date')
            ->value('period_id');

        $this->connection->table('journal_entries')->insert([
            'id' => $entryId,
            'entity_id' => $this->entityId,
            'journal_id' => (string) $this->connection->table('journals')
                ->where('entity_id', $this->entityId)
                ->where('code', 'GEN')
                ->value('id'),
            'period_id' => $periodId,
            'number' => 'DRAFT-'.Str::upper(Str::random(5)),
            'accounting_date' => $this->date->toDateString(),
            'narration' => 'Half-finished, and no report may count it',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $currency = (string) $this->entity()->functional_currency;

        foreach ([[$controlAccountId, '-500.0000'], [$this->expenseAccountId, '500.0000']] as [$accountId, $amount]) {
            $this->connection->table('journal_lines')->insert([
                'id' => (string) Str::ulid(),
                'journal_entry_id' => $entryId,
                'entity_id' => $this->entityId,
                'account_id' => $accountId,
                /*
                 | Supplied, although the trigger overwrites them.
                 |
                 | `journal_lines` is RANGE-partitioned on
                 | `accounting_date`, and PostgreSQL routes the row to a
                 | partition BEFORE the trigger runs: left out, the row is
                 | routed to the default partition and the trigger's
                 | assignment then has to move it, which PostgreSQL refuses
                 | outright. The real poster supplies them for the same
                 | reason.
                 */
                'accounting_date' => $this->date->toDateString(),
                'period_id' => $periodId,
                'amount' => $amount,
                'currency' => $currency,
                'functional_amount' => $amount,
                'narration' => 'A draft line',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

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
