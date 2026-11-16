<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Services;

use App\Tenant\Banking\Models\BankTransaction;

/**
 * Which matching strategy a transaction gets - PF-155.
 *
 * ## The bank's own code is the cheapest signal there is
 *
 * A line carrying `PMNT/MDOP/CHRG` is a bank charge. There is no document
 * behind it and there never will be, so running it through the document
 * passes wastes every one of them and occasionally produces a confident
 * wrong match on amount alone - a 50 charge against a 50 invoice, which is
 * exactly the kind of mistake that survives review because the figures agree.
 *
 * Reading the code first costs nothing and removes that whole class of
 * error.
 *
 * ## Most specific wins, and an unmapped code falls through
 *
 * `PMNT/RCDT/ESCT` is tried, then `PMNT/RCDT`, then `PMNT`. A code nobody
 * has mapped gets the generic passes rather than an error: an unfamiliar
 * code is a transaction like any other, and refusing it would make a bank
 * unusable because of a code set nobody here has seen.
 *
 * ## It is data
 *
 * A bank using `PMNT/MCOP` where its neighbour uses `PMNT/MDOP` is
 * configuration, not a code change. The alternative is a release for every
 * bank that spells a code its own way, which means the fix arrives a month
 * after the problem.
 */
final class TransactionRouting
{
    /**
     * The strategy for a code, by the longest prefix that is mapped.
     */
    public function strategyFor(
        ?string $domain,
        ?string $family = null,
        ?string $subfamily = null,
    ): string {
        $strategies = (array) config('banking.routing.strategies', []);
        $fallback = (string) config('banking.routing.fallback', 'generic');

        if ($domain === null || trim($domain) === '') {
            return $fallback;
        }

        $parts = array_values(array_filter(
            [strtoupper(trim($domain)), $family, $subfamily],
            static fn (?string $part): bool => $part !== null && trim($part) !== '',
        ));

        $parts = array_map(static fn (string $part): string => strtoupper(trim($part)), $parts);

        /*
         | Longest first.
         |
         | `PMNT/RCDT/RRTN` is a RETURNED incoming payment and must not be
         | routed as an ordinary receipt - which is what it would get from
         | the two-level code. Trying the specific one first is the whole
         | reason the sub-family is stored.
         */
        for ($length = \count($parts); $length >= 1; $length--) {
            $key = implode('/', \array_slice($parts, 0, $length));

            if (isset($strategies[$key])) {
                return (string) $strategies[$key];
            }
        }

        return $fallback;
    }

    public function strategyForTransaction(BankTransaction $transaction): string
    {
        return $this->strategyFor(
            $transaction->code_domain,
            $transaction->code_family,
            $transaction->code_subfamily,
        );
    }

    /**
     * The passes a strategy runs, in order.
     *
     * @return list<string>
     */
    public function passesFor(string $strategy): array
    {
        $passes = (array) config('banking.routing.passes', []);
        $fallback = (string) config('banking.routing.fallback', 'generic');

        $chosen = $passes[$strategy] ?? $passes[$fallback] ?? [];

        return array_values(array_map(strval(...), (array) $chosen));
    }

    /**
     * Why this transaction is going down this route, in a sentence.
     *
     * Shown beside a proposal. "Routed as payables" is a fact nobody can
     * check; naming the code it came from is what lets somebody see that
     * their bank's charges are arriving with the wrong code and fix it in
     * configuration rather than reporting a matching bug.
     */
    public function explain(BankTransaction $transaction): string
    {
        $code = implode('/', array_filter([
            $transaction->code_domain,
            $transaction->code_family,
            $transaction->code_subfamily,
        ]));

        $strategy = $this->strategyForTransaction($transaction);

        if ($code === '') {
            return sprintf(
                'The bank sent no ISO 20022 transaction code%s, so this takes the generic passes: '
                .'every document pass in order, which is slower and no less correct.',
                $transaction->bank_code_proprietary === null
                    ? ''
                    : sprintf(' (only its own code, "%s")', (string) $transaction->bank_code_proprietary),
            );
        }

        if ($strategy === (string) config('banking.routing.fallback', 'generic')) {
            return sprintf(
                'The code %s is not one PeakFlow routes, so this takes the generic passes. If your '
                .'bank uses it for something specific, mapping it is a configuration change.',
                $code,
            );
        }

        return sprintf('The code %s routes this to the %s passes.', $code, str_replace('_', ' ', $strategy));
    }
}
