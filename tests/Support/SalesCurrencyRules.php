<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Catalog\Models\FxRate;
use App\Tenant\Catalog\Services\CurrencyPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * An invoice in a currency the books are not kept in - PF-082.
 *
 * ## The defect this exists to catch
 *
 * `Issuer` emits the DOCUMENT's figures and `InvoiceIssuedRule` labelled
 * them with the entity's functional currency. So a 1,000 EUR invoice
 * credited 1,000 of the entity's own currency to revenue and debited
 * 1,150 to the receivable.
 *
 * Nothing could see it. The entry BALANCED - every line was wrong by the
 * same factor, which is what makes this class of bug invisible. The
 * database trigger that refuses a foreign line with no rate saw lines
 * claiming to be functional and had nothing to object to. `ledger:verify`
 * passed. The trial balance balanced. The only thing that was wrong was
 * the amount, and a product whose job is amounts reported it confidently.
 *
 * It was found on the purchasing side first, fixed there, and the same
 * shape was still live here.
 *
 * ## Why the check reads the LINES and not the totals
 *
 * A total is what balanced. The question is whether each line is worth
 * what the document says it is worth, in the books' currency - and that
 * is only answerable per line, against the rate the line carries.
 */
final class SalesCurrencyRules
{
    use Concerns\IssuesInvoices;
    use Concerns\ProvesTheLedger;

    private const RATE = '4.100000000000';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['a foreign-currency invoice (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();
            $this->enableEurosAtAKnownRate();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'the receivable is the document translated, not its face value' => fn (): array => $this->theReceivableIsTranslated(),
                'and every foreign line says which rate produced it' => fn (): array => $this->linesCarryTheRate(),
                'a document in the books\' own currency carries no rate' => fn (): array => $this->functionalCarriesNoRate(),
                'and a ratio over these books counts one currency, and says what it left out' => fn (): array => $this->dsoIsOneCurrency(),
            ]));
        } catch (Throwable $e) {
            return ['a foreign-currency invoice could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    private function enableEurosAtAKnownRate(): void
    {
        app(CurrencyPolicy::class)->enable($this->entity(), 'EUR');

        /*
         | A rate on the tax point, and a round one.
         |
         | 4.1 rather than a market figure, so every expectation below is
         | arithmetic a reader can do in their head: 1,000 EUR is 4,100.
         | A realistic rate would make a wrong answer look plausible.
         */
        FxRate::query()->updateOrCreate(
            [
                'rate_set' => 'market',
                'from_currency' => 'EUR',
                'to_currency' => 'SAR',
                'as_of_date' => $this->date->toDateString(),
            ],
            ['rate' => self::RATE, 'provider' => 'the sales currency check'],
        );
    }

    /** @return list<string> */
    private function theReceivableIsTranslated(): array
    {
        $invoice = $this->issueInEuros();

        if ($invoice === null) {
            return ['a euro invoice could not be issued at all, so this check sees nothing: '
                .(string) $this->lastRefusal];
        }

        $receivable = $this->functionalOn($invoice, 'accounts_receivable');

        if ($receivable === null) {
            return ['the euro invoice posted no line to the receivables control'];
        }

        /*
         | 1,150 EUR at 4.1 is 4,715.
         |
         | The failure this names is the receivable coming back as 1,150 -
         | the document's own figure, labelled with the entity's currency.
         | The entry balances either way, which is why no other check in
         | this repository can see it.
         */
        $expected = Money::of('1150.0000', 'SAR')->multipliedBy(
            self::RATE,
            \App\Support\Money\RoundingPolicy::halfUp(\App\Support\Money\Scale::Amount),
        );

        if (! $this->agrees($receivable, $expected)) {
            return [sprintf(
                'a 1,150 EUR invoice put %s in the receivable. At %s that is worth %s. The face value '.
                'of a foreign document posted as functional currency balances, because every line is '.
                'wrong by the same factor - so the trial balance, the ledger proof and the database '.
                'trigger all pass and only the amount is wrong.',
                $receivable,
                self::RATE,
                (string) $expected->amount,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function linesCarryTheRate(): array
    {
        $invoice = $this->issueInEuros();

        if ($invoice === null) {
            return ['a euro invoice could not be issued at all: '.(string) $this->lastRefusal];
        }

        $missing = $this->connection->selectOne(
            'SELECT count(*) AS n FROM journal_lines WHERE entity_id = ? AND currency = ? '.
            'AND fx_rate IS NULL',
            [$this->entityId, 'EUR'],
        );

        if ($missing !== null && (int) $missing->n > 0) {
            return [sprintf(
                '%d euro line(s) carry no rate. A translated figure whose rate is not on the line is a '.
                'figure nobody can reproduce, and the one place it would be noticed is a consolidation '.
                'that no longer ties out.',
                (int) $missing->n,
            )];
        }

        $any = $this->connection->selectOne(
            'SELECT count(*) AS n FROM journal_lines WHERE entity_id = ? AND currency = ?',
            [$this->entityId, 'EUR'],
        );

        if ($any === null || (int) $any->n === 0) {
            return ['the euro invoice produced no euro lines at all, so its currency was lost between '.
                'the document and the ledger'];
        }

        return [];
    }

    /** @return list<string> */
    private function functionalCarriesNoRate(): array
    {
        $this->issue();

        $withRate = $this->connection->selectOne(
            'SELECT count(*) AS n FROM journal_lines WHERE entity_id = ? AND currency = ? '.
            'AND fx_rate IS NOT NULL',
            [$this->entityId, 'SAR'],
        );

        if ($withRate !== null && (int) $withRate->n > 0) {
            return [sprintf(
                '%d line(s) in the books\' own currency carry a rate. A rate of 1 recorded against a '.
                'functional line is a translation that did not happen, and it makes every report that '.
                'counts translated lines wrong.',
                (int) $withRate->n,
            )];
        }

        return [];
    }

    /**
     * DSO is a ratio, so both its halves must be in the same currency - PF-190.
     *
     * The receivables side already restricted its denominator to the
     * functional currency, so this is not the defect the rest of this suite
     * is about. What was wrong was the counter beside it.
     *
     * `daysSalesOutstanding()` selected `count(*) FILTER (WHERE currency <>
     * ?)` to report how many documents it had left out - and put `currency =
     * ?` in the WHERE clause as well, so the rows that counter would have
     * counted were gone before it ran. It could never be anything but zero.
     * The result was then dropped on the floor: selected, never read, never
     * returned. Two failures in one line, and both are the same failure as a
     * check that cannot fail, which is that they look like coverage.
     *
     * It matters because the alternative to saying so is saying nothing. A
     * reader who sees no exclusion line takes the figure as covering the
     * whole window. That is the number that ends up in a board pack.
     *
     * @return list<string>
     */
    private function dsoIsOneCurrency(): array
    {
        $this->issue();

        $alone = $this->dso();

        if (($alone['dso'] ?? null) === null) {
            return ['an invoice in the books\' own currency was issued inside the window and DSO came '.
                'back null, so the denominator did not see it and this check proves nothing'];
        }

        $problems = [];

        if (\array_key_exists('excluded_other_currency', $alone)) {
            $problems[] = 'DSO reports documents excluded for their currency when there are none, so '.
                'the line is noise and a reader learns to skip it';
        }

        if ($this->issueInEuros() === null) {
            return [...$problems, 'the euro invoice could not be issued, so the exclusion half of this '.
                'check saw nothing: '.(string) $this->lastRefusal];
        }

        $withForeign = $this->dso();

        if ((string) $withForeign['sales'] !== (string) $alone['sales']) {
            $problems[] = sprintf(
                'a euro invoice changed the sales denominator from %s to %s. Its face value was added '.
                'to a figure in the books\' own currency, so the ratio divides one currency by two.',
                (string) $alone['sales'],
                (string) $withForeign['sales'],
            );
        }

        if ((int) ($withForeign['excluded_other_currency'] ?? 0) < 1) {
            $problems[] = 'a euro invoice was left out of DSO and the report does not say so. A figure '.
                'that silently covers part of a window is the one that gets quoted without its '.
                'caveat, because there was no caveat to carry.';
        }

        return $problems;
    }

    /** @return array<string, mixed> */
    private function dso(): array
    {
        return app(\App\Tenant\Reporting\Services\AgingReport::class)->daysSalesOutstanding(
            $this->entityId,
            'SAR',
            $this->date->copy()->subDays(89),
            $this->date->copy(),
        );
    }

    private function issueInEuros(): ?object
    {
        $draft = $this->draft();

        /*
         | The rate is frozen on the DOCUMENT, which the validator demands.
         |
         | A foreign invoice with no frozen rate is already refused at
         | issue - a guard the purchasing side did not have. So the
         | question this check actually asks is the narrower one: with the
         | rate on the document, does the POSTING use it, or does it still
         | label the document's figures with the entity's currency?
         */
        $this->connection->table('invoices')
            ->where('id', $draft->getKey())
            ->update([
                'currency' => 'EUR',
                'fx_rate' => self::RATE,
                'fx_source' => 'the sales currency check',
                'fx_rate_date' => $this->date->toDateString(),
            ]);

        try {
            return $this->issueDraft($draft->refresh());
        } catch (Throwable $e) {
            $this->lastRefusal = $e->getMessage();

            return null;
        }
    }

    private ?string $lastRefusal = null;

    private function issueDraft(object $draft): object
    {
        return app(\App\Tenant\Sales\Services\Issuer::class)->issue(
            $draft,
            $this->entity(),
            $this->actorId(),
            $this->decision,
            $this->terms,
        );
    }

    private function functionalOn(object $invoice, string $role): ?string
    {
        $row = $this->connection->selectOne(
            'SELECT l.functional_amount::text AS amount FROM journal_lines l '.
            'JOIN journal_entries e ON e.id = l.journal_entry_id '.
            'WHERE l.entity_id = ? AND e.source_id = ? AND l.account_id IN '.
            '(SELECT account_id FROM account_roles WHERE entity_id = ? AND role = ?)',
            [$this->entityId, (string) $invoice->getKey(), $this->entityId, $role],
        );

        return $row === null ? null : (string) $row->amount;
    }

    private function agrees(string $actual, Money $expected): bool
    {
        return Money::of($actual, 'SAR')->abs()->equals($expected->abs());
    }
}
