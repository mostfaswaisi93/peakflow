<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Services;

use App\Support\Money\Money;
use App\Tenant\Banking\Models\BankAccount;
use App\Tenant\Banking\Models\BankStatement;
use App\Tenant\Banking\Models\BankTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Three balances, always together - PF-146.
 *
 * ## Why three, and why never one
 *
 * - **Ledger**: what PeakFlow believes, from posted journal lines.
 * - **Statement**: what the bank says, from the last imported closing
 *   balance.
 * - **Available**: the statement less what has been released and not yet
 *   presented - the figure a treasurer actually spends against.
 *
 * A design that stores one balance for both of the first two has deleted the
 * question reconciliation exists to answer. The three diverge in normal
 * operation: a payment released this afternoon is in the ledger, not on the
 * statement, and already out of the available figure. That is not an error
 * state, it is Tuesday - and a system that showed one number would be
 * reporting cash the business cannot spend.
 *
 * ## The difference is always itemised
 *
 * `all()` returns the components, never a single unexplained figure. "The
 * bank says 90,000 and we say 93,500" is where a month of work starts;
 * "3,500 of unpresented payments and 0 of unrecorded bank items" is where it
 * finishes. A screen that shows the difference without its parts makes
 * somebody rebuild the parts by hand, every month, from the same data the
 * system already has.
 *
 * ## The identity, derived rather than guessed
 *
 * This is the four-way bank reconciliation, and the signs are the part that
 * is easy to get wrong - a sign error here reports exactly twice the item as
 * unexplained, which looks like a second mistake somewhere else.
 *
 *     adjusted bank  = statement + deposits in transit - outstanding payments
 *     adjusted book  = ledger    + bank items we have not recorded (signed)
 *     adjusted bank  = adjusted book
 *
 * Rearranged into the form this class reports:
 *
 *     ledger = statement
 *            - unpresented payments      (we have paid, the bank has not shown it)
 *            + unsettled receipts        (we have banked it, the bank has not shown it)
 *            - unrecorded bank items     (signed: a charge is negative)
 *            - unposted opening balance  (the account was opened with money and
 *                                         nothing has posted it)
 *
 * Whatever is left over after those four is the part nobody has explained,
 * and it is reported on its own line rather than folded into one of them -
 * because folding it in is how a difference comes to look explained when it
 * is not.
 *
 * ## Both directions are components, not just ours
 *
 * The naive reconciliation accounts only for our side - payments we have made
 * that the bank has not shown yet. The other direction is just as common and
 * worse: a direct debit, a bank charge or an interest credit the bank has
 * booked and nobody has recorded. It appears as an unexplained difference,
 * and the usual response is to adjust the ledger to match the bank - which
 * posts a number with no document behind it.
 */
final class BankBalances
{
    /**
     * All three balances, and the itemised difference.
     *
     * @return array<string, mixed>
     */
    public function all(BankAccount $account, ?Carbon $asOf = null): array
    {
        $day = ($asOf ?? Carbon::now())->copy()->endOfDay();
        $currency = (string) $account->currency;

        $ledger = $this->ledger($account, $day);
        $statement = $this->statement($account, $day);
        $unpresented = $this->unpresented($account, $day);
        $unsettled = $this->unsettledReceipts($account, $day);
        $unrecorded = $this->unrecordedBankItems($account, $day);
        $unposted = $this->unpostedOpeningBalance($account);

        return [
            'as_of' => $day->toDateString(),
            'currency' => $currency,

            /*
             | Named for what each one IS, not "balance" with a qualifier.
             |
             | A screen labelled "balance: 93,500" with the other two behind
             | a tab is a screen somebody reads one number off. The three are
             | returned together because they are only meaningful together.
             */
            'ledger' => (string) $ledger->amount,
            'statement' => (string) $statement->amount,

            /*
             | Available subtracts outstanding payments and does NOT add
             | unsettled receipts.
             |
             | A deposit paid in this morning is not money the business can
             | spend this afternoon, however certain it is - and the whole
             | point of a separate available figure is that it is the one
             | safe to spend against. Adding receipts the bank has not
             | cleared would make it the optimistic figure, which is the
             | figure that causes an overdraft.
             */
            'available' => (string) $statement->minus($unpresented)->amount,

            'reconciliation' => $this->itemise(
                ledger: $ledger,
                statement: $statement,
                unpresented: $unpresented,
                unsettled: $unsettled,
                unrecorded: $unrecorded,
                unpostedOpening: $unposted,
                currency: $currency,
            ),
        ];
    }

    /**
     * What PeakFlow believes, from the ledger.
     *
     * The GL account's posted balance, which is the only figure in the three
     * that a journal entry can move. Read from `journal_lines` rather than
     * from a cached balance, because this is the figure a reconciliation is
     * judged against and a stale cache would make the difference a cache
     * problem.
     */
    public function ledger(BankAccount $account, ?Carbon $asOf = null): Money
    {
        $day = ($asOf ?? Carbon::now())->copy()->endOfDay();

        /*
         | Summed in SQL and read as a string.
         |
         | A query-builder aggregate comes back through a numeric cast on
         | some drivers, and this is the figure a reconciliation is judged
         | against - and, since PF-165, the figure frozen into a document
         | an auditor re-performs. A balance that has been through a float
         | is one somebody has to defend.
         */
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(l.amount), 0)::text AS balance
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            WHERE l.account_id = ?
              AND e.accounting_date <= ?::date
        SQL, [(string) $account->gl_account_id, $day->toDateString()]);

        return Money::of((string) ($row->balance ?? '0'), (string) $account->currency);
    }

    /**
     * What the bank says, from the last BOOKED statement that stands.
     *
     * The account's own opening balance where no statement has arrived yet -
     * which is the honest answer rather than zero: an account opened with
     * 50,000 in it does not have a statement balance of nothing.
     *
     * Intraday reports are excluded. CAMT.052 and CAMT.054 are provisional:
     * they are the same movements the booked statement will carry, so
     * letting one set the reconciliation baseline double-counts every line
     * on it when the CAMT.053 arrives. They feed {@see intradayPosition()}
     * instead, which is what they are for.
     */
    public function statement(BankAccount $account, ?Carbon $asOf = null): Money
    {
        $statement = $this->lastStatement($account, $asOf, bookedOnly: true);

        return $statement === null
            ? $account->openingBalance()
            : $statement->closing();
    }

    /**
     * Today's cash position, intraday reports included.
     *
     * A treasurer deciding whether a payment run can go out this afternoon
     * needs the intraday figure; a reconciliation must not use it. Returning
     * both from one method with a flag would guarantee the wrong one gets
     * read, so they are separate methods - and this one states its basis,
     * because "is this provisional" is the only question that matters about
     * the number it returns.
     *
     * @return array<string, mixed>
     */
    public function intradayPosition(BankAccount $account, ?Carbon $asOf = null): array
    {
        $latest = $this->lastStatement($account, $asOf, bookedOnly: false);

        if ($latest === null) {
            return [
                'balance' => (string) $account->openingBalance()->amount,
                'basis' => 'opening_balance',
                'provisional' => false,
                'as_of' => null,
            ];
        }

        return [
            'balance' => (string) $latest->closing()->amount,
            'basis' => (string) $latest->source_format,
            'provisional' => $latest->isProvisional(),
            'as_of' => $latest->period_to->toDateString(),
        ];
    }

    /**
     * The statement the reconciliation measures against - exposed for PF-191.
     *
     * The same private lookup `statement()` and `unrecordedBankItems()`
     * use, and the whole of its definition is the three things it
     * excludes: a superseded statement, one whose period runs past the
     * date asked about, and an intraday report. A report that needed "the
     * last statement the bank closed" and wrote its own query would get
     * the intraday one - the model's own `lastStatement()` returns it -
     * and would then draw the drift line in the wrong place every
     * afternoon a CAMT.052 arrived.
     */
    public function baseline(BankAccount $account, ?Carbon $asOf = null): ?BankStatement
    {
        return $this->lastStatement($account, $asOf, bookedOnly: true);
    }

    /** The newest statement that stands, optionally only a booked one. */
    private function lastStatement(
        BankAccount $account,
        ?Carbon $asOf,
        bool $bookedOnly,
    ): ?BankStatement {
        $day = ($asOf ?? Carbon::now())->copy()->endOfDay();

        /*
         | Queried directly, NOT through `$account->statements()`.
         |
         | That relation imposes `orderBy('sequence')` so a listing comes
         | back in order, which is right for a listing and a trap here:
         | appending `orderByDesc('sequence')` to it produces
         | `ORDER BY sequence ASC, sequence DESC`, the first clause wins, and
         | `first()` quietly returns the OLDEST statement. The reconciliation
         | then measures against the account's first statement for ever, and
         | nothing about the output says so.
         |
         | `reorder()` would also work and is one word away from being
         | forgotten by the next caller. Not going through the relation
         | cannot be forgotten.
         |
         | Found while proving PF-147's continuity criterion: the second
         | statement imported correctly and the balance stayed on the first.
         */
        $query = BankStatement::query()
            ->where('bank_account_id', (string) $account->getKey())
            ->where('status', '!=', 'superseded')
            ->where('period_to', '<=', $day->toDateString())
            ->orderByDesc('sequence');

        if ($bookedOnly) {
            /*
             | Read from config rather than listing the formats here.
             |
             | `banking.formats` already says which formats reconcile, and a
             | second list in code is the one that stops matching it - at
             | which point an intraday format quietly becomes the
             | reconciliation baseline.
             */
            $query->whereIn('source_format', self::bookedFormats());
        }

        return $query->first();
    }

    /** @return list<string> */
    public static function bookedFormats(): array
    {
        $booked = [];

        foreach ((array) config('banking.formats', []) as $format => $traits) {
            if ((bool) ($traits['reconciles'] ?? true) === true) {
                $booked[] = (string) $format;
            }
        }

        /*
         | A manually recorded gap reconciles.
         |
         | It is a statement with no lines standing in for a period nobody
         | has the file for, and the account's history runs through it - so
         | excluding it would make the next real statement's opening balance
         | compare against the one before the gap.
         */
        $booked[] = 'manual';

        return $booked;
    }

    /**
     * What has left the ledger and not yet appeared on a statement.
     *
     * Posted debits to the bank account after the last statement's period.
     * This is the normal state of a business that pays anybody: a payment
     * released on Friday afternoon is in the ledger, not on the statement,
     * and must already be out of the available figure - because the money is
     * committed and spending it twice is an overdraft.
     *
     * Positive means money committed and not yet taken.
     */
    public function unpresented(BankAccount $account, ?Carbon $asOf = null): Money
    {
        return $this->postedAfterTheLastStatement($account, $asOf, '<')->negated();
    }

    /**
     * What has been banked and not yet appeared on a statement.
     *
     * Deposits in transit: a cheque banked on Friday, a card settlement
     * posted on the day of the sale. The other half of the classic
     * reconciliation, and the half a system that only tracks outstanding
     * payments reports as an unexplained difference every single month.
     *
     * Kept separate from {@see unpresented()} rather than netted into it
     * because the two are not interchangeable: both belong in the
     * reconciliation, and only one belongs in the available figure.
     *
     * Positive means money banked and not yet credited.
     */
    public function unsettledReceipts(BankAccount $account, ?Carbon $asOf = null): Money
    {
        return $this->postedAfterTheLastStatement($account, $asOf, '>');
    }

    /**
     * What the bank has booked and nobody has recorded.
     *
     * The direction the naive reconciliation forgets, and the worse one: a
     * direct debit, a bank charge or an interest credit the bank has taken
     * and no document in PeakFlow explains. It shows up as an unexplained
     * difference, and the usual response is to adjust the ledger to match
     * the bank - which posts a number with no document behind it and makes
     * the next month's difference harder to read, not easier.
     *
     * Signed as the bank booked it: a charge is negative, interest positive.
     */
    public function unrecordedBankItems(BankAccount $account, ?Carbon $asOf = null): Money
    {
        /*
         | Bounded by the BASELINE statement, not by the date asked for.
         |
         | The statement balance is the closing balance of the last booked
         | statement, at its period end. An item the bank booked after that
         | period is in neither that balance nor our ledger, so it belongs
         | on neither side of the identity - counting it makes the
         | reconciliation disagree by exactly that item.
         |
         | It bites as soon as a second statement is imported whose period
         | runs past the date being reported on, which is every month-end
         | report run before the month is over. Found end to end: three
         | statements imported, ledger 50,000, statement 51,500, and 11,200
         | reported as unexplained when nothing was.
         */
        $baseline = $this->lastStatement($account, $asOf, bookedOnly: true);

        $upTo = $baseline === null
            ? Carbon::parse($account->opening_date->toDateString())
            : Carbon::parse($baseline->period_to->toDateString());

        $sum = BankTransaction::query()
            ->where('bank_account_id', (string) $account->getKey())
            ->where('booking_date', '<=', $upTo->toDateString())
            /*
             | Provisional lines are excluded.
             |
             | An intraday report is the same movement the booked statement
             | will carry, so counting both reports every intraday line
             | twice - once as an unrecorded item and once again when the
             | CAMT.053 arrives.
             */
            ->where('is_provisional', false)
            ->whereNull('superseded_by_id')
            ->whereNotExists(static function ($query): void {
                /*
                 | "Matched" is a link that exists, not a flag on the row.
                 |
                 | The transaction is immutable, so a `matched` boolean on it
                 | could not be set - which is the schema making the right
                 | thing the only possible thing.
                 */
                $query->selectRaw('1')
                    ->from('matches')
                    ->whereColumn('matches.bank_transaction_id', 'bank_transactions.id');
            })
            ->sum('amount');

        return Money::of((string) ($sum ?? '0'), (string) $account->currency);
    }

    /**
     * The opening balance, where nothing has posted it to the ledger.
     *
     * `BankAccounts::open()` deliberately posts nothing - a service that
     * wrote a journal entry on the side would be posting without a document,
     * a date or an approval. So an account opened with 50,000 in it starts
     * with a ledger balance of zero and a statement balance of 50,000, and
     * the whole opening balance shows up as a difference.
     *
     * That difference has exactly one cause and it is worth naming, because
     * it is the first thing anybody sees after opening an account and the
     * generic "a posting with no bank transaction behind it" sends them
     * hunting for a mistake that is not there. What they need to do is post
     * the opening entry.
     *
     * Detected by there being no journal line at all on the GL account up to
     * the opening date: once the opening entry exists this returns zero and
     * stops explaining anything.
     */
    public function unpostedOpeningBalance(BankAccount $account): Money
    {
        $opening = $account->openingBalance();

        if ($opening->isZero()) {
            return $opening;
        }

        $posted = DB::connection('tenant')->table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', (string) $account->gl_account_id)
            ->where('e.accounting_date', '<=', $account->opening_date->toDateString())
            ->exists();

        return $posted ? Money::zero((string) $account->currency) : $opening;
    }

    /**
     * Postings to the bank account the last statement cannot have included.
     *
     * The window opens the day after the last statement's period and runs to
     * `asOf`. `$direction` picks the side: `<` for debits, `>` for credits.
     */
    private function postedAfterTheLastStatement(
        BankAccount $account,
        ?Carbon $asOf,
        string $direction,
    ): Money {
        $day = ($asOf ?? Carbon::now())->copy()->endOfDay();
        $currency = (string) $account->currency;

        /*
         | The same statement the balance came from.
         |
         | Booked only, and as of the same date - otherwise a reconciliation
         | asked for a past date measures its window from a statement that
         | had not arrived yet, and every comparative report is wrong by
         | whatever happened in between.
         */
        $statement = $this->lastStatement($account, $asOf, bookedOnly: true);

        /*
         | The window opens the day AFTER the opening date, not on it.
         |
         | An account's opening balance is its balance at the END of the
         | opening date - the same convention as a statement's closing
         | balance for a period ending that day, which is what makes the two
         | comparable at all.
         |
         | So a posting dated on the opening date is already inside the
         | opening balance. Starting the window on the opening date counts
         | the opening journal entry itself as a deposit in transit: the
         | ledger and the statement agree at 50,000, and the reconciliation
         | reports 50,000 banked-not-credited against 50,000 unexplained.
         | Both figures are invented, they cancel, and the only sign of it is
         | `agrees` going false on an account that is perfectly reconciled.
         |
         | Found while proving PF-146's divergence criterion, on the fixture's
         | second stage.
         */
        $from = $statement === null
            ? Carbon::parse($account->opening_date->toDateString())->addDay()
            : Carbon::parse($statement->period_to->toDateString())->addDay();

        if ($from->gt($day)) {
            return Money::zero($currency);
        }

        $sum = DB::connection('tenant')->table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', (string) $account->gl_account_id)
            ->whereBetween('e.accounting_date', [$from->toDateString(), $day->toDateString()])
            ->where('l.amount', $direction, 0)
            ->sum('l.amount');

        return Money::of((string) ($sum ?? '0'), $currency);
    }

    /**
     * The difference, itemised into its components.
     *
     * @return array<string, mixed>
     */
    private function itemise(
        Money $ledger,
        Money $statement,
        Money $unpresented,
        Money $unsettled,
        Money $unrecorded,
        Money $unpostedOpening,
        string $currency,
    ): array {
        $explained = $statement
            ->minus($unpresented)
            ->plus($unsettled)
            ->minus($unrecorded)
            ->minus($unpostedOpening);

        $unexplained = $ledger->minus($explained);
        $difference = $ledger->minus($statement);

        return [
            'difference' => (string) $difference->amount,

            /*
             | Each component signed as its contribution to the difference.
             |
             | So that they add to it: the screen that shows these does not
             | have to know the identity's signs to total them, and a
             | component whose sign was flipped shows up as a total that does
             | not match rather than as a plausible wrong figure.
             */
            'components' => [
                'unpresented_payments' => (string) $unpresented->negated()->amount,
                'unsettled_receipts' => (string) $unsettled->amount,
                'unrecorded_bank_items' => (string) $unrecorded->negated()->amount,
                'unposted_opening_balance' => (string) $unpostedOpening->negated()->amount,
                'unexplained' => (string) $unexplained->amount,
            ],
            'agrees' => $unexplained->isZero(),
            'why' => $this->whyTheyDiffer(
                $difference,
                $unpresented,
                $unsettled,
                $unrecorded,
                $unpostedOpening,
                $unexplained,
                $currency,
            ),
        ];
    }

    /**
     * The sentence under the figures.
     *
     * A recognised cause gets its own sentence naming what to do about it,
     * because the generic list of three possibilities is what somebody reads
     * at the start of a day spent ruling out two of them.
     */
    private function whyTheyDiffer(
        Money $difference,
        Money $unpresented,
        Money $unsettled,
        Money $unrecorded,
        Money $unpostedOpening,
        Money $unexplained,
        string $currency,
    ): ?string {
        /*
         | An unposted opening balance is named even when everything ties.
         |
         | It is not an error and `agrees` stays true, but leaving it silent
         | means an account whose ledger is permanently short by its opening
         | balance reports that it reconciles - which it does, against a
         | ledger nobody has finished setting up.
         */
        if ($unexplained->isZero() && ! $unpostedOpening->isZero()) {
            return sprintf(
                'The ledger and the statement differ by %s %s, and the opening balance of %s is part '.
                'of it: nothing has posted it to the general ledger yet. Opening an account '.
                'deliberately posts nothing - the entry needs a date, a counter-account and somebody '.
                'to approve it - so post the opening journal entry and that part goes away. '.
                'Everything else about this account already reconciles.',
                $currency,
                (string) $difference->amount,
                (string) $unpostedOpening->amount,
            );
        }

        if ($unexplained->isZero()) {
            return null;
        }

        $explained = $unpresented->negated()
            ->plus($unsettled)
            ->plus($unrecorded->negated())
            ->plus($unpostedOpening->negated());

        return sprintf(
            'The ledger and the statement differ by %s %s. %s of that is accounted for: %s. The '.
            'remaining %s is not explained by anything, and the usual causes are a posting to the '.
            'bank account with no bank transaction behind it, a transaction matched to the wrong '.
            'document, or a statement imported out of order.',
            $currency,
            (string) $difference->amount,
            (string) $explained->amount,
            $this->listComponents($unpresented, $unsettled, $unrecorded, $unpostedOpening),
            (string) $unexplained->amount,
        );
    }

    /** The non-zero components, named, so the sentence does not list zeroes. */
    private function listComponents(
        Money $unpresented,
        Money $unsettled,
        Money $unrecorded,
        Money $unpostedOpening,
    ): string {
        $parts = [];

        if (! $unpresented->isZero()) {
            $parts[] = sprintf('%s of payments the bank has not shown yet', (string) $unpresented->amount);
        }

        if (! $unsettled->isZero()) {
            $parts[] = sprintf('%s banked and not yet credited', (string) $unsettled->amount);
        }

        if (! $unrecorded->isZero()) {
            $parts[] = sprintf(
                '%s the bank has booked that nothing here records',
                (string) $unrecorded->amount,
            );
        }

        if (! $unpostedOpening->isZero()) {
            $parts[] = sprintf(
                'an opening balance of %s that has not been posted to the general ledger',
                (string) $unpostedOpening->amount,
            );
        }

        return $parts === [] ? 'nothing' : implode(', ', $parts);
    }
}
