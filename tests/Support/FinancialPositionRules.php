<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Reporting\Services\StatementOfFinancialPosition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The statement of financial position - PF-184.
 *
 * ## What a check here is for
 *
 * **An account that is silently dropped.** It is the failure the whole
 * design is arranged against: a balance exists, it is somewhere, and a
 * statement that omits it is wrong by exactly that amount and says it is
 * right. So an unclassified account has to appear, with its balance, under
 * its own heading.
 *
 * **A statement that balances for the wrong reason.** Assets equal
 * liabilities plus equity only once the unclosed result is carried in.
 * A check that asserted the equation without posting any revenue would pass
 * on a product that ignored the result entirely.
 *
 * **A side that is presented in the ledger's sign.** A liability's natural
 * balance is a credit, stored negative. A report that forgot to flip it
 * would print equity negative AND STILL BALANCE, because both sides move
 * together - which is why there is a check on the sign itself rather than
 * only on the equation.
 */
final class FinancialPositionRules
{
    use Concerns\IssuesInvoices;
    use Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['statement of financial position (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'it is built from the chart, not from account codes' => fn (): array => $this->builtFromTheChart(),
                'assets equal liabilities plus equity' => fn (): array => $this->theEquationHolds(),
                'and the period result is what makes them' => fn (): array => $this->theResultIsCarried(),
                'a liability is presented positive, not as the ledger stores it' => fn (): array => $this->signsArePresented(),
                'the current and non-current split is on the statement' => fn (): array => $this->theSplitIsPresent(),
                'an unclassified account is shown, never dropped' => fn (): array => $this->unclassifiedIsShown(),
                'and the statement says it is incomplete' => fn (): array => $this->unclassifiedIsDeclared(),
                'a comparative column is earlier, or it is refused' => fn (): array => $this->comparativeMustBeEarlier(),
                'every line carries its IAS 1 paragraph' => fn (): array => $this->linesCiteTheStandard(),
            ]));
        } catch (Throwable $e) {
            return ['the statement of financial position could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return array<string, mixed> */
    private function statement(?Carbon $comparativeUntil = null): array
    {
        return app(StatementOfFinancialPosition::class)->asAt(
            $this->entityId,
            'SAR',
            Carbon::parse($this->date->toDateString())->startOfMonth(),
            Carbon::parse($this->date->toDateString())->endOfMonth(),
            comparativeUntil: $comparativeUntil,
        );
    }

    /** @return list<string> */
    private function builtFromTheChart(): array
    {
        $this->issue();

        $statement = $this->statement();
        $lines = $this->lines($statement);

        if ($lines === []) {
            return ['an entity with an issued invoice produced a statement with no lines on it at all'];
        }

        /*
         | The receivable must be on the receivables LINE.
         |
         | Not merely "somewhere under assets". The grouping is the thing
         | under test, and a product that put every asset on one line
         | would satisfy a check that only looked at the section total.
         */
        $receivables = $this->line($statement, 'trade_and_other_receivables');

        if ($receivables === null) {
            return ['an issued invoice produced no trade receivables line, so the chart\'s own '.
                'classification is not what the statement is grouped by'];
        }

        if (Money::of((string) $receivables['amount'], 'SAR')->isZero()) {
            return ['the trade receivables line is on the statement and is zero, with an invoice issued'];
        }

        return [];
    }

    /** @return list<string> */
    private function theEquationHolds(): array
    {
        $this->issue();

        $statement = $this->statement();
        $totals = $statement['totals'];
        $difference = Money::of((string) $totals['difference'], 'SAR');

        if (! $difference->isZero()) {
            return [sprintf(
                'assets %s against liabilities and equity %s, a difference of %s. The statement is '.
                'incomplete or an account is on the wrong side.',
                (string) $totals['assets'],
                (string) $totals['liabilities_and_equity'],
                (string) $totals['difference'],
            )];
        }

        if ((string) $statement['declaration']['severity'] !== 'ok') {
            return ['the statement balances and does not say so: '.(string) $statement['declaration']['en']];
        }

        return [];
    }

    /** @return list<string> */
    private function theResultIsCarried(): array
    {
        $this->issue();

        $statement = $this->statement();
        $result = Money::of((string) $statement['result_for_the_period']['amount'], 'SAR');

        /*
         | An issued invoice is revenue, so the period is in profit.
         |
         | The sign matters as much as the figure: a product that summed
         | the profit-and-loss balances without negating would report a
         | profit as a loss, and the equation would still hold because
         | the same wrong number is added to equity.
         */
        if (! $result->isPositive()) {
            return [sprintf(
                'an entity whose only activity is an issued invoice reported a result of %s. Revenue is '.
                'a credit, so a profit summed without negating comes out negative - and the equation '.
                'still holds, because the same wrong number is carried into equity.',
                (string) $result->amount,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function signsArePresented(): array
    {
        $this->issue();

        $statement = $this->statement();
        $problems = [];

        $assets = Money::of((string) $statement['totals']['assets'], 'SAR');

        if (! $assets->isPositive()) {
            $problems[] = sprintf('total assets came out %s on an entity with an issued invoice', (string) $assets->amount);
        }

        $payables = $this->line($statement, 'current_tax_liabilities');

        if ($payables !== null && Money::of((string) $payables['amount'], 'SAR')->isNegative()) {
            $problems[] = sprintf(
                'the output VAT line is presented as %s. A liability is stored as a credit and presented '.
                'positive; a report that forgot the flip prints equity negative AND STILL BALANCES, '.
                'because both sides move together.',
                (string) $payables['amount'],
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function theSplitIsPresent(): array
    {
        $this->issue();

        $statement = $this->statement();
        $problems = [];

        foreach ($statement['sections'] as $section) {
            if ($section['section'] === 'unclassified') {
                continue;
            }

            foreach ($section['maturities'] as $group) {
                if ($section['section'] === 'equity' && $group['maturity'] !== 'not_applicable') {
                    $problems[] = sprintf(
                        'equity was split into "%s". IAS 1.60 asks for the split on assets and '.
                        'liabilities; equity has no maturity, and a "current equity" heading reads as a '.
                        'classification somebody made.',
                        (string) $group['maturity'],
                    );
                }

                if ($section['section'] === 'assets' && $group['maturity'] === 'not_applicable') {
                    $problems[] = 'an asset line was presented with no maturity, so IAS 1.60\'s split is '.
                        'missing on the side it matters most';
                }
            }
        }

        if ($this->maturities($statement, 'assets') === []) {
            $problems[] = 'the assets section carries no current / non-current grouping at all';
        }

        return $problems;
    }

    /** @return list<string> */
    private function unclassifiedIsShown(): array
    {
        $this->issue();

        /*
         | Strip the classification off the receivables control.
         |
         | Not an invented account: the one the invoice actually posted
         | to, so there is a real balance that has to turn up somewhere.
         | An account with no balance would let a product that drops
         | unclassified accounts pass.
         */
        $this->connection->statement(
            'UPDATE accounts SET statement_line = NULL WHERE entity_id = ? AND id IN '.
            '(SELECT account_id FROM account_roles WHERE entity_id = ? AND role = ?)',
            [$this->entityId, $this->entityId, 'accounts_receivable'],
        );

        $statement = $this->statement();
        $section = $this->section($statement, 'unclassified');

        if ($section === null) {
            return ['an account with a balance and no classification produced no unclassified section'];
        }

        if (Money::of((string) $section['total'], 'SAR')->isZero()) {
            return ['the unclassified section is present and its total is zero, so the balance on the '.
                'unclassified account was dropped - and the statement is wrong by exactly that amount '.
                'while reporting that it is right'];
        }

        $codes = [];

        foreach ($section['maturities'] as $group) {
            foreach ($group['lines'] as $line) {
                foreach ($line['accounts'] as $account) {
                    $codes[] = (string) $account['code'];
                }
            }
        }

        if ($codes === []) {
            return ['the unclassified section has a total and names no account, so nobody can tell which '.
                'account to classify'];
        }

        return [];
    }

    /** @return list<string> */
    private function unclassifiedIsDeclared(): array
    {
        $this->issue();

        $this->connection->statement(
            'UPDATE accounts SET statement_line = NULL WHERE entity_id = ? AND id IN '.
            '(SELECT account_id FROM account_roles WHERE entity_id = ? AND role = ?)',
            [$this->entityId, $this->entityId, 'accounts_receivable'],
        );

        $declaration = $this->statement()['declaration'];

        if ((string) $declaration['severity'] === 'ok') {
            return ['a statement with an unclassified balance on it declared itself complete'];
        }

        if (! str_contains((string) $declaration['en'], 'Unclassified')) {
            return ['the statement is incomplete and does not say that the cause is an unclassified '.
                'account: '.(string) $declaration['en']];
        }

        return [];
    }

    /** @return list<string> */
    private function comparativeMustBeEarlier(): array
    {
        $this->issue();

        $same = Carbon::parse($this->date->toDateString())->endOfMonth();

        try {
            $this->statement($same);
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'not earlier')
                ? []
                : ['a comparative period ending with the reported one was refused for the wrong reason: '
                    .$e->getMessage()];
        }

        return ['a comparative column ending on the same date as the reported one was accepted. It is '.
            'the same column twice, and a reader comparing them would conclude nothing moved.'];
    }

    /** @return list<string> */
    private function linesCiteTheStandard(): array
    {
        $this->issue();

        $problems = [];

        foreach ($this->lines($this->statement()) as $line) {
            if ($line['line'] === 'unclassified') {
                continue;
            }

            if (! str_starts_with((string) $line['reference'], 'IAS 1.')) {
                $problems[] = sprintf(
                    'the line "%s" cites "%s" rather than a paragraph of IAS 1. A statement line with no '.
                    'reference is a heading somebody chose, and nobody can check it.',
                    (string) $line['line'],
                    (string) $line['reference'],
                );
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return list<array<string, mixed>>
     */
    private function lines(array $statement): array
    {
        $lines = [];

        foreach ($statement['sections'] as $section) {
            foreach ($section['maturities'] as $group) {
                foreach ($group['lines'] as $line) {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>|null
     */
    private function line(array $statement, string $name): ?array
    {
        foreach ($this->lines($statement) as $line) {
            if ($line['line'] === $name) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>|null
     */
    private function section(array $statement, string $name): ?array
    {
        foreach ($statement['sections'] as $section) {
            if ($section['section'] === $name) {
                return $section;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return list<string>
     */
    private function maturities(array $statement, string $section): array
    {
        $found = $this->section($statement, $section);

        return $found === null
            ? []
            : array_map(static fn (array $g): string => (string) $g['maturity'], $found['maturities']);
    }
}
