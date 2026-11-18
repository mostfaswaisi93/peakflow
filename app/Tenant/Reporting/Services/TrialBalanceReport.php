<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Services;

use App\Support\Money\Money;
use App\Tenant\Catalog\Services\ExchangeRates;
use App\Tenant\Ledger\Services\TrialBalance;
use App\Tenant\Reporting\Exceptions\ReportRefused;
use Illuminate\Support\Carbon;

/**
 * The trial balance as a REPORT - PF-183.
 *
 * ## Three columns, not one
 *
 * {@see TrialBalance} answers "what is the balance on this date".
 * A trial balance a person reads answers three questions at once:
 * what it was at the start, what moved, and what it is now. Those are
 * two reads of the ledger and a subtraction, and a product that made
 * the reader do the subtraction would have them doing it in a
 * spreadsheet, once per account, every month.
 *
 * The movement column is derived rather than read, deliberately:
 * closing minus opening is the only definition under which the three
 * columns cannot disagree. Summing the period's lines separately would
 * give a fourth figure that is right in a different way.
 *
 * ## It SAYS when it does not balance
 *
 * Not a boolean in an array a screen may ignore, a PDF may not print
 * and an export may drop. A trial balance that does not balance is the
 * loudest signal this product has - it means the ledger's own invariant
 * has been broken - and it has to arrive as a sentence at the top of
 * the page, naming the difference.
 *
 * ## Presentation currency carries its basis, or it is refused
 *
 * A figure in a currency the books are not kept in is a translation,
 * and a translation without its rate and its date is a number nobody
 * can reproduce. So the rate used is on the output, with where it came
 * from - and a report that cannot find a rate says so rather than
 * translating at one.
 */
final class TrialBalanceReport
{
    public function __construct(
        private readonly TrialBalance $balances,
        private readonly ExchangeRates $rates,
    ) {}

    /**
     * Opening, movement and closing, for a period.
     *
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function forPeriod(
        string $entityId,
        string $functionalCurrency,
        Carbon $from,
        Carbon $until,
        bool $includeAdjusting = true,
        ?string $presentIn = null,
    ): array {
        if ($until->lt($from)) {
            throw ReportRefused::because(
                'A trial balance period ends after it starts. Reversed, the opening balance would be '.
                'later than the closing one and the movement between them would read as its own '.
                'negative.',
            );
        }

        /*
         | Opening is the balance the day BEFORE the period starts.
         |
         | Not on the first day: a posting dated the first belongs to the
         | period, and an opening balance that included it would report
         | the month's first invoice as something that was always there.
         */
        $opening = $this->balances->for(
            $entityId,
            $functionalCurrency,
            $from->copy()->subDay(),
            includeAdjusting: $includeAdjusting,
        );

        $closing = $this->balances->for(
            $entityId,
            $functionalCurrency,
            $until,
            includeAdjusting: $includeAdjusting,
        );

        $openingByCode = $this->byCode($opening['rows']);
        $closingByCode = $this->byCode($closing['rows']);

        $rows = [];

        foreach (array_unique([...array_keys($openingByCode), ...array_keys($closingByCode)]) as $code) {
            $before = $openingByCode[$code] ?? null;
            $after = $closingByCode[$code] ?? null;
            $shape = $after ?? $before;

            $openingBalance = Money::of((string) ($before['balance'] ?? '0'), $functionalCurrency);
            $closingBalance = Money::of((string) ($after['balance'] ?? '0'), $functionalCurrency);

            /*
             | The movement is closing MINUS opening.
             |
             | The only definition under which the three columns cannot
             | disagree. Summing the period's own lines would produce a
             | fourth figure that is right in a different way - and the
             | first time the two differed, nobody could say which was
             | the error.
             */
            $movement = $closingBalance->minus($openingBalance);

            /*
             | There is deliberately no "skip the zero rows" here.
             |
             | `TrialBalance` already excludes them - both of its
             | queries end in `HAVING sum(...) <> 0` - so a filter here
             | would be a guard that cannot fire, and a bite proved it:
             | removing one changed nothing. A guard that reads as
             | protection and provides none is worse than none, because
             | the next person trusts it.
             |
             | An account that opens at zero and closes at zero
             | therefore never reaches this loop, and a trial balance
             | padded with every unused account in the chart is one
             | nobody reads to the bottom.
             */

            $rows[] = [
                'code' => (string) $code,
                'name_ar' => (string) ($shape['name_ar'] ?? ''),
                'name_en' => (string) ($shape['name_en'] ?? ''),
                'type' => (string) ($shape['type'] ?? ''),
                'statement_line' => $shape['statement_line'] ?? null,
                'opening' => (string) $openingBalance->amount,
                'movement' => (string) $movement->amount,
                'closing' => (string) $closingBalance->amount,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        $report = [
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
            'currency' => $functionalCurrency,
            'includes_adjusting' => $includeAdjusting,
            'basis' => $closing['basis'],
            'rows' => $rows,
            'total_debit' => (string) $closing['total_debit'],
            'total_credit' => (string) $closing['total_credit'],
            'balances' => (bool) $closing['balances'],
            /*
             | The sentence, which is the criterion.
             |
             | "Prominently rather than printing" - so the report leads
             | with what is wrong, and a reader who looks at nothing else
             | still sees it.
             */
            'declaration' => $this->declare($closing, $functionalCurrency),
        ];

        return $presentIn === null || $presentIn === $functionalCurrency
            ? $report
            : $this->translate($report, $entityId, $functionalCurrency, $presentIn, $until);
    }

    /**
     * What the reader has to be told before anything else.
     *
     * @param  array<string, mixed>  $closing
     * @return array<string, string>
     */
    private function declare(array $closing, string $currency): array
    {
        if ((bool) $closing['balances']) {
            return [
                'severity' => 'ok',
                'en' => sprintf(
                    'Debits and credits both come to %s. The trial balance balances.',
                    (string) Money::of((string) $closing['total_debit'], $currency)->toPresentation(),
                ),
                'ar' => 'المدين والدائن متساويان. ميزان المراجعة متوازن.',
            ];
        }

        $difference = Money::of((string) $closing['total_debit'], $currency)
            ->minus(Money::of((string) $closing['total_credit'], $currency));

        /*
         | Named as what it is: the ledger's own invariant, broken.
         |
         | A deferred constraint refuses an unbalanced ENTRY, so an
         | unbalanced trial balance cannot be a posting that slipped
         | through - it is a row somebody wrote around the posting path,
         | or a read model that has drifted. Both are worth stopping for,
         | and neither is fixed by reading the report.
         */
        return [
            'severity' => 'broken',
            'en' => sprintf(
                'THIS TRIAL BALANCE DOES NOT BALANCE. Debits are %s and credits are %s, out by %s. '.
                'Every figure below is unreliable. An unbalanced entry is refused by the database, so '.
                'this is either a row written around the posting path or a read model that has '.
                'drifted - run `ledger:rebuild-balances --check` before reading anything else.',
                (string) Money::of((string) $closing['total_debit'], $currency)->toPresentation(),
                (string) Money::of((string) $closing['total_credit'], $currency)->toPresentation(),
                (string) $difference->toPresentation(),
            ),
            'ar' => sprintf(
                'ميزان المراجعة هذا غير متوازن. الفرق %s. كل رقم أدناه غير موثوق، والقيد غير المتوازن '.
                'مرفوض في قاعدة البيانات - فهذا إمّا صفٌّ كُتب خارج مسار الترحيل أو نموذج قراءة انحرف.',
                (string) $difference->toPresentation(),
            ),
        ];
    }

    /**
     * The same report in another currency, with the rate on it.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    private function translate(
        array $report,
        string $entityId,
        string $functional,
        string $presentIn,
        Carbon $on,
    ): array {
        /*
         | The rate for the direction we need, never its inverse.
         |
         | `ExchangeRates` refuses to invert, and that refusal is the
         | reason this asks for `functional -> presentIn` rather than
         | dividing by the other one: reaching for the inverse is how a
         | consolidation acquires a persistent one-cent difference that
         | grows with every account.
         */
        try {
            $rate = $this->rates->rateFor($functional, $presentIn, $on);
        } catch (\Throwable $e) {
            throw ReportRefused::aTranslationWithNoRate(
                $functional,
                $presentIn,
                $on->toDateString(),
                $e->getMessage(),
            );
        }

        $translated = array_map(
            static fn (array $row): array => array_merge($row, [
                'opening' => (string) \Brick\Math\BigDecimal::of($row['opening'])
                    ->multipliedBy($rate->value)->toScale(4, \Brick\Math\RoundingMode::HalfUp),
                'movement' => (string) \Brick\Math\BigDecimal::of($row['movement'])
                    ->multipliedBy($rate->value)->toScale(4, \Brick\Math\RoundingMode::HalfUp),
                'closing' => (string) \Brick\Math\BigDecimal::of($row['closing'])
                    ->multipliedBy($rate->value)->toScale(4, \Brick\Math\RoundingMode::HalfUp),
            ]),
            $report['rows'],
        );

        return array_merge($report, [
            'currency' => $presentIn,
            'rows' => $translated,
            /*
             | The basis, on the output.
             |
             | A figure in a currency the books are not kept in is a
             | translation, and a translation without its rate, its date
             | and where the rate came from is a number nobody can
             | reproduce - which is the only thing a reader can do with
             | it when it disagrees with theirs.
             */
            'translation' => [
                'from' => $functional,
                'to' => $presentIn,
                'rate' => (string) $rate->value,
                'as_of' => $rate->date->format('Y-m-d'),
                'source' => $rate->provider,
                'rate_set' => $rate->rateSet,
                'why' => sprintf(
                    'Translated from %s at %s, the %s rate of %s from %s. The books are kept in %s; '.
                    'this is a presentation of them and not a second set.',
                    $functional,
                    (string) $rate->value,
                    $rate->rateSet,
                    $rate->date->format('Y-m-d'),
                    $rate->provider,
                    $functional,
                ),
            ],
        ]);
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function byCode(iterable $rows): array
    {
        $byCode = [];

        foreach ($rows as $row) {
            $byCode[(string) $row['code']] = $row;
        }

        return $byCode;
    }
}
