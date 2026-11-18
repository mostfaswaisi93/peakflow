<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Reporting\Services\StatementOfProfitOrLoss;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The statement of profit or loss and OCI - PF-185.
 *
 * ## What a check here is for
 *
 * **Two analyses that disagree.** By function and by nature are two
 * groupings of one set of rows, so a difference is never a rounding
 * artefact: it is an account classified on one axis and not the other. The
 * check that compares them found a real defect on its first run - the
 * by-nature total negated its expense lines twice, so it reported revenue
 * plus costs.
 *
 * **A subtotal that is wrong while the total is right.** Gross margin and
 * the operating result are running totals at band boundaries. A line put in
 * the wrong band moves a subtotal and leaves the bottom of the statement
 * agreeing with the ledger, which is the hardest arithmetic error to see.
 *
 * **OCI folded into profit.** It overstates the result and the error never
 * unwinds, because the reclassification that would have corrected it is
 * exactly what did not happen.
 */
final class ProfitOrLossRules
{
    use Concerns\EntersBills;
    use Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['statement of profit or loss (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedThePayableSide();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'revenue is presented as a positive contribution' => fn (): array => $this->revenueIsPositive(),
                'and an expense as a negative one' => fn (): array => $this->expensesAreNegative(),
                'the two analyses reach the same result' => fn (): array => $this->theAnalysesAgree(),
                'a subtotal is the running total at its band boundary' => fn (): array => $this->subtotalsAreRunningTotals(),
                'profit for the period is read before OCI is added' => fn (): array => $this->ociIsAfterProfit(),
                'an expense with no nature is shown, never dropped' => fn (): array => $this->unclassifiedNatureIsShown(),
                'an overlapping comparative period is refused' => fn (): array => $this->overlappingComparativeIsRefused(),
                'the statement is a movement, not a balance' => fn (): array => $this->itIsAMovement(),
            ]));
        } catch (Throwable $e) {
            return ['the statement of profit or loss could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return array<string, mixed> */
    private function statement(?Carbon $comparativeUntil = null): array
    {
        return app(StatementOfProfitOrLoss::class)->forPeriod(
            $this->entityId,
            'SAR',
            Carbon::parse($this->date->toDateString())->startOfMonth(),
            Carbon::parse($this->date->toDateString())->endOfMonth(),
            comparativeUntil: $comparativeUntil,
        );
    }

    /** @return list<string> */
    private function revenueIsPositive(): array
    {
        $this->issue();

        $line = $this->functionLine($this->statement(), 'revenue');

        if ($line === null) {
            return ['an issued invoice produced no revenue line on the statement of profit or loss'];
        }

        $amount = Money::of((string) $line['amount'], 'SAR');

        if (! $amount->isPositive()) {
            return [sprintf(
                'revenue was presented as %s. It is stored as a credit, so a statement that did not '.
                'negate it reports income as a loss - and every subtotal below it follows.',
                (string) $amount->amount,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function expensesAreNegative(): array
    {
        $this->issueWithACost();

        $statement = $this->statement();
        $line = null;

        foreach ($statement['by_function']['bands'] as $band) {
            foreach ($band['lines'] as $candidate) {
                if (str_contains((string) $candidate['line'], 'cost_of_sales')
                    || str_contains((string) $candidate['line'], 'administrative')) {
                    $line = $candidate;
                }
            }
        }

        if ($line === null) {
            return ['a posted supplier bill produced no expense line on the statement at all'];
        }

        $amount = Money::of((string) $line['amount'], 'SAR');

        if (! $amount->isNegative()) {
            return [sprintf(
                'cost of sales was presented as %s. Every line on this statement is a CONTRIBUTION to '.
                'the result, so an expense presented positive is added to profit rather than taken off '.
                'it, and the bottom line is wrong by twice the cost.',
                (string) $amount->amount,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function theAnalysesAgree(): array
    {
        $this->issueWithACost();

        $statement = $this->statement();

        $byFunction = Money::of((string) $statement['by_function']['profit_for_the_period'], 'SAR');
        $byNature = Money::of((string) $statement['by_nature']['profit_for_the_period'], 'SAR');

        if (! $byFunction->equals($byNature)) {
            return [sprintf(
                'by function the result is %s and by nature it is %s. They are two groupings of the '.
                'same rows, so an account is classified on one axis and not the other.',
                (string) $byFunction->amount,
                (string) $byNature->amount,
            )];
        }

        if ((string) $statement['declaration']['severity'] !== 'ok') {
            return ['the analyses agree and the statement does not say so: '
                .(string) $statement['declaration']['en']];
        }

        return [];
    }

    /** @return list<string> */
    private function subtotalsAreRunningTotals(): array
    {
        $this->issueWithACost();

        $statement = $this->statement();
        $running = Money::zero('SAR');
        $problems = [];

        foreach ($statement['by_function']['bands'] as $band) {
            foreach ($band['lines'] as $line) {
                $running = $running->plus(Money::of((string) $line['amount'], 'SAR'));
            }

            $reported = Money::of((string) $band['amount'], 'SAR');

            if (! $reported->equals($running)) {
                $problems[] = sprintf(
                    'the subtotal "%s" reports %s and the lines above it come to %s. A subtotal that is '.
                    'not the running total is wrong while the bottom of the statement is right, which is '.
                    'the hardest arithmetic error to see.',
                    (string) $band['subtotal'],
                    (string) $reported->amount,
                    (string) $running->amount,
                );
            }
        }

        return $problems;
    }

    /**
     * An OCI posting is in total comprehensive income and NOT in profit.
     *
     * ## Why this needed a real account before it could mean anything
     *
     * The first version asserted the order of the bands and that both
     * totals were reported. With no OCI posted anywhere the two totals
     * were equal, so a product that folded OCI into profit passed - and
     * the bite that moved OCI above the tax boundary changed nothing.
     * A check that cannot fail is worse than no check.
     *
     * So the fixture posts to the translation reserve through the real
     * manual-entry path, and the assertion is the one that matters: the
     * two totals DIFFER, by exactly the OCI amount.
     *
     * @return list<string>
     */
    private function ociIsAfterProfit(): array
    {
        $this->issueWithACost();
        $this->postToTheTranslationReserve('250.0000');

        $statement = $this->statement();
        $bands = array_column($statement['by_function']['bands'], 'band');

        $taxAt = array_search('tax', $bands, true);
        $ociAt = array_search('other_comprehensive_income', $bands, true);

        $problems = [];

        if ($taxAt === false) {
            $problems[] = 'the statement has no tax band, so profit for the period is never captured';
        }

        if ($ociAt !== false && $taxAt !== false && $ociAt < $taxAt) {
            $problems[] = 'other comprehensive income is presented before profit for the period. '.
                'IAS 1.82A puts it after and separately, because OCI folded into profit overstates the '.
                'result and the error never unwinds.';
        }

        foreach (['profit_for_the_period', 'total_comprehensive_income'] as $key) {
            if (! \array_key_exists($key, $statement['by_function'])) {
                $problems[] = sprintf('the statement does not report "%s" at all', $key);

                return $problems;
            }
        }

        $profit = Money::of((string) $statement['by_function']['profit_for_the_period'], 'SAR');
        $comprehensive = Money::of((string) $statement['by_function']['total_comprehensive_income'], 'SAR');

        if ($profit->equals($comprehensive)) {
            $problems[] = sprintf(
                'a 250.00 credit to the translation reserve left profit (%s) equal to total '.
                'comprehensive income (%s). The OCI item has been folded into profit, which overstates '.
                'the result - and the error never unwinds, because the reclassification that would have '.
                'corrected it is exactly what did not happen.',
                (string) $profit->amount,
                (string) $comprehensive->amount,
            );
        }

        if (! $comprehensive->minus($profit)->equals(Money::of('250.0000', 'SAR'))) {
            $problems[] = sprintf(
                'total comprehensive income exceeds profit by %s, and the only OCI posted was 250.00.',
                (string) $comprehensive->minus($profit)->amount,
            );
        }

        return $problems;
    }

    /**
     * A credit to the translation reserve, through the real posting path.
     *
     * The other side is any ordinary asset account, because this fixture
     * is about where the credit is PRESENTED and not about what caused it -
     * and a real cause is a foreign operation, which arrives with
     * consolidation.
     */
    private function postToTheTranslationReserve(string $amount): void
    {
        $reserve = (string) $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('statement_line', 'foreign_operation_translation')
            ->value('code');

        /*
         | The counter account must carry NO control role.
         |
         | The product refuses a manual entry to one, correctly: a control
         | account's balance is the total of a subledger, and a manual
         | posting makes the two disagree with nothing afterwards able to
         | say which is right. The first version of this fixture reached
         | for suspense, which is a control account, and was refused - the
         | guard working, and the fixture asking for the wrong thing.
         */
        $counter = (string) $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('type', 'asset')
            ->where('postable', true)
            ->whereNotIn('id', function ($query): void {
                $query->select('account_id')
                    ->from('account_roles')
                    ->where('entity_id', $this->entityId);
            })
            ->orderBy('code')
            ->value('code');

        if ($reserve === '' || $counter === '') {
            return;
        }

        /*
         | Signed in as somebody who may post.
         |
         | `JournalImport` asks the Gate, correctly: a file of four hundred
         | lines is four hundred postings and needs the same permission one
         | entry does. Reaching past it to write the rows directly would be
         | a fixture that proves the statement works for a user the product
         | would refuse.
         */
        $was = \Illuminate\Support\Facades\Auth::guard('web')->user();
        \Illuminate\Support\Facades\Auth::guard('web')->setUser($this->anAccountant());

        try {
            $this->importTheReserveEntry($counter, $reserve, $amount);
        } finally {
            \Illuminate\Support\Facades\Auth::guard('web')->forgetUser();

            if ($was !== null) {
                \Illuminate\Support\Facades\Auth::guard('web')->setUser($was);
            }
        }
    }

    private function importTheReserveEntry(string $counter, string $reserve, string $amount): void
    {
        app(\App\Tenant\Ledger\Manual\JournalImport::class)->import(
            $this->entity(),
            [
                ['reference' => 'OCI-1', 'date' => $this->date->toDateString(),
                    'account_code' => $counter, 'amount' => $amount],
                ['reference' => 'OCI-1', 'date' => $this->date->toDateString(),
                    'account_code' => $reserve, 'amount' => '-'.$amount],
            ],
            reason: 'a translation difference, for the OCI section of the statement',
            headerRows: 0,
        );
    }

    /** @return list<string> */
    private function unclassifiedNatureIsShown(): array
    {
        $this->issueWithACost();

        /*
         | The account the bill actually posted to, not a named role.
         |
         | The fixture picks the first postable expense account by code, and
         | which role that carries differs between charts. Stripping the
         | nature from the account that has the movement is what makes the
         | unclassified line non-empty - the whole point of the check.
         */
        $this->connection->statement(
            'UPDATE accounts SET expense_nature = NULL WHERE entity_id = ? AND id = ?',
            [$this->entityId, $this->expenseAccountId],
        );

        $statement = $this->statement();
        $byNature = $statement['by_nature'];

        if (! (bool) $byNature['has_unclassified']) {
            return ['an expense account with a movement and no nature was not reported as unclassified, '.
                'so it is missing from the by-nature analysis and the two analyses would silently differ'];
        }

        $line = null;

        foreach ($byNature['lines'] as $candidate) {
            if ($candidate['nature'] === 'unclassified') {
                $line = $candidate;
            }
        }

        if ($line === null) {
            return ['the statement says there are unclassified expenses and shows no line for them'];
        }

        if (Money::of((string) $line['amount'], 'SAR')->isZero()) {
            return ['the unclassified line is present and zero, so the expense was dropped from the '.
                'by-nature analysis while being counted in the functional one'];
        }

        if ((string) $statement['declaration']['severity'] === 'ok') {
            return ['the by-nature analysis is incomplete and the statement declares itself fine'];
        }

        return [];
    }

    /** @return list<string> */
    private function overlappingComparativeIsRefused(): array
    {
        $this->issue();

        $inside = Carbon::parse($this->date->toDateString())->endOfMonth();

        try {
            $this->statement($inside);
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'overlaps')
                ? []
                : ['an overlapping comparative was refused for the wrong reason: '.$e->getMessage()];
        }

        return ['a comparative period overlapping the reported one was accepted. Profit or loss is a '.
            'movement, so the overlap counts the same revenue twice and reads as growth that did not '.
            'happen.'];
    }

    /**
     * A window that OPENS after the posting reports nothing.
     *
     * The first version issued two invoices and asserted the result moved,
     * which a product reading closing BALANCES passes just as happily -
     * the balance moves too. The distinction only shows on a window the
     * posting is outside: the movement is nil and the closing balance is
     * not, and a revenue account's closing balance is the year to date.
     * A statement built from it reports January's revenue again in
     * February and the comparative column makes it look like nothing grew.
     *
     * @return list<string>
     */
    private function itIsAMovement(): array
    {
        $this->issue();

        $after = app(StatementOfProfitOrLoss::class)->forPeriod(
            $this->entityId,
            'SAR',
            Carbon::parse($this->date->toDateString())->addDay(),
            Carbon::parse($this->date->toDateString())->endOfMonth(),
        );

        $result = Money::of((string) $after['by_function']['profit_for_the_period'], 'SAR');

        if (! $result->isZero()) {
            return [sprintf(
                'a period beginning the day AFTER the only invoice reported a result of %s. The '.
                'statement is reading closing balances rather than the period\'s movement, so every '.
                'month would repeat the year to date.',
                (string) $result->amount,
            )];
        }

        return [];
    }

    /**
     * An invoice for the revenue and a supplier bill for the expense.
     *
     * ## Why a bill and not a hand-written journal pair
     *
     * The fixture's invoice posts revenue, a receivable and VAT - and no
     * expense at all, so a statement checked against it has an empty
     * expense side and a product that dropped every expense would pass.
     *
     * The first version of this helper wrote the journal entry and its two
     * lines with raw inserts, which failed on a column it had invented and
     * would have gone on to miss the entry number, the fingerprint and the
     * balance trigger. A bill goes through the real posting path, so the
     * expense on the statement is the one the product actually produces.
     */
    private function issueWithACost(): void
    {
        $this->issue();

        /*
         | Entered AND posted.
         |
         | `enterBill()` leaves a draft, and a draft has no journal lines -
         | which is correct and is why the first version of this helper
         | produced a statement with an empty expense side. The expense
         | reaches the ledger when the bill is posted, so the fixture does
         | both.
         */
        /*
         | 400, against the fixture invoice's 1,000.
         |
         | The default bill is also 1,000, which makes the result exactly
         | zero - and zero is unchanged by every sign error there is, so
         | the check that compares the two analyses passed on a product
         | that negated one of them. The two figures have to differ for
         | the comparison to mean anything.
         */
        app(\App\Tenant\Purchasing\Services\Bills::class)->post(
            $this->enterBill(lines: [[
                'description' => 'Machined brackets, for the by-nature analysis',
                'quantity' => '40',
                'unit_price' => '10',
                'net_amount' => '400.0000',
                'expense_account_id' => $this->expenseAccountId,
                'tax_code_id' => $this->taxCodeId,
                'tax_category' => 'S',
                'tax_rate' => '15.000000',
                'tax_amount' => '60.0000',
            ]]),
            $this->entity(),
            $this->actorId(),
        );
    }

    /** A real user holding the seeded accountant role in the fixture entity. */
    private function anAccountant(): \App\Tenant\Identity\Models\User
    {
        $role = \App\Tenant\Identity\Models\Role::query()->where('key', 'accountant')->firstOrFail();

        $user = \App\Tenant\Identity\Models\User::query()->create([
            'name' => 'Reporting Probe',
            'email' => 'reporting-probe-'.bin2hex(random_bytes(6)).'@invalid.test',
            'password' => Str::password(32),
            'active' => true,
        ]);

        \App\Tenant\Identity\Models\UserEntityRole::query()->create([
            'user_id' => $user->getKey(),
            'entity_id' => $this->entityId,
            'role_id' => $role->getKey(),
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>|null
     */
    private function functionLine(array $statement, string $name): ?array
    {
        foreach ($statement['by_function']['bands'] as $band) {
            foreach ($band['lines'] as $line) {
                if ($line['line'] === $name) {
                    return $line;
                }
            }
        }

        return null;
    }
}
