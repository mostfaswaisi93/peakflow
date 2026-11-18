<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Http\Controllers;

use App\Support\Money\Money;
use App\Tenant\Banking\Models\BankAccount;
use App\Tenant\Banking\Services\BankBalances;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The entity's bank accounts, with all three balances - PF-145, PF-146.
 *
 * ## The page renders three balances per account, never one
 *
 * {@see BankBalances} returns the ledger balance, the statement balance
 * and the available balance together because they are only meaningful
 * together, and this page carries that the whole way to the screen. A
 * column headed "balance" would be read as the money the business has,
 * and the figure somebody would read is the one that includes a payment
 * run released on Friday that the bank has not taken yet - which is how
 * a payment gets authorised against money that is already committed.
 *
 * So each account states all three, each under its own name, and the
 * itemised difference comes with them rather than behind a link.
 *
 * ## The last statement's date is a figure in its own right
 *
 * A reconciliation measured against a statement from last month is a
 * reconciliation against nothing: everything that has happened since is
 * in the ledger, absent from the statement, and reported as a difference
 * that nobody can clear. The date is the only thing on the page that
 * says whether the comparison beside it means anything, so it is beside
 * the balances and not in a detail panel.
 *
 * ## No account number is rendered in full, and none is decrypted
 *
 * `iban_tail` and `domestic_identifier_tail` are stored in the clear for
 * exactly this screen; `iban()`, `domesticIdentifier()` and
 * `accountNumber()` decrypt and are not called here. A full IBAN on a
 * list screen is a full IBAN in a screenshot, a support ticket and a
 * browser cache.
 *
 * These are the entity's OWN accounts, so there is no disclosure to
 * record: {@see \App\Tenant\Parties\Services\BankDetailDisclosure} guards
 * a COUNTERPARTY's details, where the fraud is somebody reading a
 * supplier's account to redirect a payment. Our own IBAN is on every
 * invoice we issue, and auditing a read of it would bury the reads that
 * matter. Showing the tail rather than the number is still right, for
 * the screenshot.
 */
final class BankAccountListController
{
    public function __construct(private readonly BankBalances $balances) {}

    /**
     * @permission bank.manage - the three balances say what the business
     *     can spend and the itemised difference says what nobody has
     *     explained yet, which is treasury information rather than
     *     something a landing page shows everybody.
     *
     *     It is also the permission `Navigation` hides this item behind,
     *     and a page open where its own menu entry is hidden is the
     *     arrangement where the menu looks like the control. The route
     *     carries it too; this is the half that holds when somebody adds
     *     a second route to the group and forgets it.
     */
    public function __invoke(Request $request, EntityContext $context): View
    {
        Gate::authorize('bank.manage');

        $accounts = BankAccount::query()
            /*
             | By the label, which is what the person reading this named
             | the account.
             |
             | Ordering by `created_at` would put the list in the order
             | the accounts were opened, which is invisible to somebody
             | scanning for "the euro account in Frankfurt".
             */
            ->orderBy('label')
            ->get()
            ->map(fn (BankAccount $account): array => $this->describe($account))
            ->all();

        return view('app.banking.accounts', [
            'entity' => $context->entity(),
            'accounts' => $accounts,
        ]);
    }

    /**
     * One account, as the page needs it.
     *
     * @return array<string, mixed>
     */
    private function describe(BankAccount $account): array
    {
        $currency = (string) $account->currency;

        /*
         | One call to `all()` per account, not one query per figure.
         |
         | It is still several queries per account, and bank accounts are
         | counted in single figures per entity - the alternative is a
         | page that reports one balance because the other two were too
         | expensive, which is the failure this screen exists to prevent.
         */
        $balances = $this->balances->all($account);

        /*
         | The newest statement that stands, provisional ones included.
         |
         | `BankBalances` deliberately measures against the last BOOKED
         | statement, because an intraday report carries the same
         | movements the booked one will. Both dates matter to a reader
         | and they are different questions: "when did we last hear from
         | the bank" and "what is the reconciliation measured against".
         | Showing only the first would make a month-old baseline look
         | current the moment an intraday file arrived.
         */
        $latest = $account->lastStatement();

        return [
            'label' => (string) $account->label,
            'identifier' => $this->maskedIdentifier($account),
            'identifier_kind' => $account->iban_tail !== null
                ? 'iban'
                : ($account->domestic_identifier_tail !== null ? 'domestic' : 'none'),
            'domestic_scheme' => $account->domestic_scheme === null ? null : (string) $account->domestic_scheme,
            'institution' => (string) $account->institution_name,
            'institution_country' => (string) $account->institution_country,
            'branch' => $account->branch === null ? null : (string) $account->branch,
            'currency' => $currency,
            'active' => $account->isActive(),
            'status' => (string) $account->status,

            'as_of' => (string) $balances['as_of'],
            'ledger' => Money::of((string) $balances['ledger'], $currency),
            'statement' => Money::of((string) $balances['statement'], $currency),
            'available' => Money::of((string) $balances['available'], $currency),

            /*
             | The difference and its parts, signed as they contribute.
             |
             | `BankBalances` signs each component so they add to the
             | difference, which is what lets this page total them
             | without knowing the identity's signs. A screen that showed
             | the difference without its parts makes somebody rebuild
             | the parts by hand, every month, from data the system
             | already has.
             */
            'difference' => Money::of((string) $balances['reconciliation']['difference'], $currency),
            'agrees' => (bool) $balances['reconciliation']['agrees'],
            'components' => $this->components($balances['reconciliation']['components'], $currency),
            'why' => $balances['reconciliation']['why'],

            'statement_label' => $latest?->label(),
            'statement_period_to' => $latest?->period_to->toDateString(),
            'statement_imported_at' => $latest?->imported_at?->toDateString(),
            'statement_is_provisional' => $latest !== null && $latest->isProvisional(),
            /*
             | How stale the statement is, as a number of days.
             |
             | Left as a figure rather than turned into "recent" or
             | "stale" here: what counts as too old depends on how often
             | the bank sends a file, which this page does not know, so it
             | states the age and lets the reader judge.
             */
            'statement_age_in_days' => $latest === null
                ? null
                : (int) $latest->period_to->diffInDays(now(), false),
        ];
    }

    /**
     * The account's identifier, with only its last four digits.
     *
     * Nothing is decrypted: both tails are stored in the clear precisely
     * so a list screen can identify an account without reaching for the
     * number itself.
     */
    private function maskedIdentifier(BankAccount $account): ?string
    {
        $tail = $account->iban_tail ?? $account->domestic_identifier_tail;

        return $tail === null ? null : sprintf('···· %s', (string) $tail);
    }

    /**
     * The difference's components, as Money, with the zero ones dropped.
     *
     * A list that printed four zeroes beside an account that reconciles
     * reads as four things to look into.
     *
     * @param  array<string, mixed>  $components
     * @return array<string, Money>
     */
    private function components(array $components, string $currency): array
    {
        $kept = [];

        foreach ($components as $name => $amount) {
            $money = Money::of((string) $amount, $currency);

            if (! $money->isZero()) {
                $kept[(string) $name] = $money;
            }
        }

        return $kept;
    }
}
