<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use App\Tenant\Banking\Models\BankAccount;
use App\Tenant\Banking\Models\BankStatement;
use App\Tenant\Banking\Services\BankAccounts;
use App\Tenant\Banking\Services\StatementImport;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Posting\Rules\OpeningBalanceRule;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Tax\Posting\Rules\WithholdingOnPaymentRule;
use App\Tenant\Tax\Posting\Rules\WithholdingOnReceiptRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The fixture a banking check needs - PF-145 to PF-149.
 *
 * Builds on {@see EntersBills} rather than repeating it: the same entity,
 * calendar and open period, so a banking check and a purchasing check are
 * talking about the same books.
 *
 * What it adds is a bank account mirroring the chart's `bank` role account,
 * and the three ways to move each of the three balances independently -
 * which is the whole point of PF-146 and cannot be checked without them:
 *
 *   - `postTheOpeningEntry()` moves the LEDGER only.
 *   - `releaseAPayment()`    moves the LEDGER only, after the last statement.
 *   - `importFirstStatement()` moves the STATEMENT only.
 *
 * ## The timeline is deliberate
 *
 * The account opens on the first of the fixture month and the first
 * statement closes mid-month, so a posting on the fixture date falls AFTER
 * the last statement's period - which is the window `unpresented()` reads
 * and the normal state of any business that pays anybody. A fixture whose
 * postings all landed inside the statement period would make every
 * divergence check pass by reporting zero.
 */
trait OpensBankAccounts
{
    /*
     | Aliased so this trait's own `refreshFixtures()` can call it.
     |
     | A trait's own definition shadows the one it imports, and there is
     | no `parent::` for a trait - so without the alias the selling side's
     | fixtures would stop being re-read after a savepoint rollback, and
     | every check after the first would assert against a stale period.
     */
    use EntersBills {
        refreshFixtures as refreshTheSellingSide;
    }

    /** A valid Saudi IBAN: the check digits were computed, not invented. */
    protected const SA_IBAN = 'SA0380000000608010167519';

    /** A second valid one, for the checks that need two accounts. */
    protected const SA_IBAN_OTHER = 'SA7380000000608010167520';

    /** Valid elsewhere, which is what makes it wrong against a Saudi institution. */
    protected const DE_IBAN = 'DE89370400440532013000';

    /** JPMorgan Chase, New York. The ABA check digit is correct. */
    protected const US_ROUTING = '021000021';

    protected ?BankAccount $bankAccount = null;

    protected string $bankGlAccountId = '';

    protected function seedTheBankSide(): void
    {
        $this->seedThePayableSide();

        $this->bankGlAccountId = (string) DB::connection('tenant')->table('accounts as a')
            ->join('account_roles as r', 'r.account_id', '=', 'a.id')
            ->where('a.entity_id', $this->entityId)
            ->where('r.role', ControlRole::Bank->value)
            ->orderBy('a.code')
            ->value('a.id');

        $this->bankAccount = $this->openAccount();
    }

    /** The first of the fixture month, so a fixture-date posting lands after it. */
    protected function openingDate(): Carbon
    {
        return $this->date->copy()->startOfMonth();
    }

    protected function openAccount(
        string $openingBalance = '50000.0000',
        ?string $iban = self::SA_IBAN,
        ?string $glAccountId = null,
        string $institutionCountry = 'SA',
        string $currency = 'SAR',
        ?string $domesticScheme = null,
        ?string $domesticIdentifier = null,
        ?string $bic = 'RJHISARI',
        ?Carbon $openingDate = null,
        string $label = 'Main operating account',
    ): BankAccount {
        return app(BankAccounts::class)->open(
            entity: $this->entity(),
            label: $label,
            institutionName: 'Al Rajhi Bank',
            institutionCountry: $institutionCountry,
            holderLegalName: 'Fixture Trading Co.',
            currency: $currency,
            glAccountId: $glAccountId ?? $this->bankGlAccountId,
            openingDate: $openingDate ?? $this->openingDate(),
            openingBalance: $openingBalance,
            iban: $iban,
            bic: $bic,
            domesticScheme: $domesticScheme,
            domesticIdentifier: $domesticIdentifier,
            userId: $this->actorId(),
        );
    }

    /**
     * The statement that closes mid-month: four lines, netting +1,500.
     *
     * One of them carries the bank's reference and two are deliberately
     * identical on the same day - a customer paying twice in a morning,
     * which deduplication must not swallow.
     *
     * @param  list<array<string, mixed>>|null  $lines
     * @return array<string, mixed>
     */
    protected function importFirstStatement(
        ?array $lines = null,
        string $openingBalance = '50000.0000',
        string $closingBalance = '51500.0000',
        string $sourceFormat = 'camt053',
        ?Carbon $periodFrom = null,
        ?Carbon $periodTo = null,
        ?string $contentHash = null,
        ?BankAccount $account = null,
        bool $replaces = false,
        ?BankStatement $replacing = null,
        ?string $supersessionReason = null,
    ): array {
        $from = $periodFrom ?? $this->openingDate();
        $to = $periodTo ?? $this->openingDate()->addDays(9);
        $on = $account ?? $this->bank();

        /*
         | A re-issued statement supersedes the one it re-states.
         |
         | Without it continuity compares the replacement against the
         | statement it replaces and refuses it - so a fixture that only
         | re-imported would be checking the refusal rather than the
         | re-import.
         */
        $supersedes = $replacing ?? ($replaces ? $on->lastStatement() : null);

        return app(StatementImport::class)->import(
            account: $on,
            sourceFormat: $sourceFormat,
            periodFrom: $from,
            periodTo: $to,
            openingBalance: $openingBalance,
            closingBalance: $closingBalance,
            lines: $lines ?? $this->statementLines($from),
            statementNumber: '2026-05-A',
            fileName: 'camt.053.xml',
            contentHash: $contentHash ?? hash('sha256', 'statement-a'),
            userId: $this->actorId(),
            replaces: $supersedes,
            supersessionReason: $supersedes === null
                ? null
                : ($supersessionReason
                    ?? 'The bank re-issued this statement after a reversal was booked late.'),
        );
    }

    /**
     * Four lines netting +1,500, two of them identical on one day.
     *
     * @return list<array<string, mixed>>
     */
    protected function statementLines(?Carbon $from = null): array
    {
        $from ??= $this->openingDate();
        $day = $from->copy()->addDays(2)->toDateString();
        $next = $from->copy()->addDays(3)->toDateString();

        return [
            [
                'booking_date' => $day,
                'value_date' => $day,
                'amount' => '1000.0000',
                'bank_reference' => 'BR-ALPHA-001',
                'counterparty_name' => 'Gulf Retail LLC',
                'remittance_unstructured' => 'Payment for INV-1001',
            ],
            /*
             | Two identical lines on one day, with no bank reference.
             |
             | The pair a naive deduplication collapses - and collapsing it
             | hides a real duplicate payment, which is the error this whole
             | area exists to catch.
             */
            [
                'booking_date' => $next,
                'value_date' => $next,
                'amount' => '500.0000',
                'counterparty_name' => 'Gulf Retail LLC',
                'remittance_unstructured' => 'INV-1002',
            ],
            [
                'booking_date' => $next,
                'value_date' => $next,
                'amount' => '500.0000',
                'counterparty_name' => 'Gulf Retail LLC',
                'remittance_unstructured' => 'INV-1002',
            ],
            [
                'booking_date' => $next,
                'value_date' => $next,
                'amount' => '-500.0000',
                'bank_reference' => 'BR-ALPHA-002',
                'counterparty_name' => 'Riyadh Utilities',
                'remittance_unstructured' => 'Electricity, April',
            ],
        ];
    }

    /**
     * Post the opening balance into the ledger.
     *
     * Through the real rule, which plugs the other side into retained
     * earnings - the honest counter-account for a figure that came from a
     * previous system.
     */
    protected function postTheOpeningEntry(?string $amount = null, ?Carbon $on = null): JournalEntry
    {
        return app(Poster::class)->post(
            entity: $this->entity(),
            ruleKey: OpeningBalanceRule::KEY,
            event: ['balances' => [[
                'account_id' => $this->bankGlAccountId,
                'amount' => $amount ?? (string) $this->bank()->opening_balance,
            ]]],
            accountingDate: $on ?? $this->openingDate(),
            narration: 'Opening balance for the bank account',
        );
    }

    /**
     * Release a payment: the ledger moves and the statement does not.
     *
     * Through the withholding rule because it is the one shipped rule that
     * credits the bank, and withholding is ordinary on a supplier payment
     * here. Gross less withheld is what actually leaves the account.
     */
    protected function releaseAPayment(
        string $gross = '3500.0000',
        string $withheld = '500.0000',
        ?Carbon $on = null,
    ): JournalEntry {
        return app(Poster::class)->post(
            entity: $this->entity(),
            ruleKey: WithholdingOnPaymentRule::KEY,
            event: [
                'gross_amount' => $gross,
                'withheld_amount' => $withheld,
                'party_id' => (string) $this->supplier?->getKey(),
                'narration' => 'Supplier payment released',
            ],
            accountingDate: $on ?? $this->date->copy(),
        );
    }

    /**
     * Bank a receipt: the ledger moves up and the statement does not.
     *
     * A deposit in transit - the other half of the classic reconciliation,
     * and the half a system that tracks only outstanding payments reports as
     * an unexplained difference every month.
     */
    protected function bankAReceipt(
        string $gross = '2000.0000',
        string $withheld = '100.0000',
        ?Carbon $on = null,
    ): JournalEntry {
        return app(Poster::class)->post(
            entity: $this->entity(),
            /*
             | The receipt rule, not the payment rule run backwards.
             |
             | Both would move the bank by the right amount, but the payment
             | rule with a withholding of zero writes a zero line and the
             | ledger refuses those - correctly, since a zero line tells
             | nobody anything. Reaching for the rule that actually describes
             | a receipt is also what keeps the fixture honest about what it
             | posted.
             */
            ruleKey: WithholdingOnReceiptRule::KEY,
            event: [
                'gross_amount' => $gross,
                'withheld_amount' => $withheld,
                'party_id' => (string) $this->party?->getKey(),
                'narration' => 'Receipt banked, not yet credited',
            ],
            accountingDate: $on ?? $this->date->copy(),
        );
    }

    protected function bank(): BankAccount
    {
        return $this->bankAccount ?? throw new \RuntimeException('The bank fixture was not seeded.');
    }

    protected function statementOf(string $id): ?BankStatement
    {
        return BankStatement::query()->whereKey($id)->first();
    }

    protected function transactionCount(?BankAccount $account = null): int
    {
        return DB::connection('tenant')->table('bank_transactions')
            ->where('bank_account_id', (string) ($account ?? $this->bank())->getKey())
            ->count();
    }

    /** A GL account that is not a bank account, for the role checks. */
    protected function nonBankGlAccountId(): string
    {
        return (string) DB::connection('tenant')->table('accounts as a')
            ->leftJoin('account_roles as r', 'r.account_id', '=', 'a.id')
            ->where('a.entity_id', $this->entityId)
            ->where('a.postable', true)
            ->whereNull('r.role')
            ->orderBy('a.code')
            ->value('a.id');
    }

    protected function uniqueLabel(string $prefix): string
    {
        return $prefix.' '.Str::upper(Str::random(5));
    }

    /**
     * A savepoint rolls back the database and not PHP.
     *
     * The bank account model is re-read because the checks mutate it - a
     * closed status, a corrected opening balance - and a stale copy would
     * make the next check assert against a row that no longer exists.
     */
    protected function refreshFixtures(): void
    {
        $this->refreshTheSellingSide();

        if ($this->bankAccount !== null) {
            $this->bankAccount = BankAccount::query()
                ->whereKey((string) $this->bankAccount->getKey())
                ->first() ?? $this->bankAccount;
        }
    }

    /**
     * Import one statement of arbitrary lines, balanced by construction.
     *
     * The matching checks need transactions rather than statements, and
     * computing a closing balance by hand in every one of them would make
     * each check about arithmetic it is not testing.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    protected function importLines(array $lines, ?string $hash = null): array
    {
        $from = $this->openingDate();
        $opening = $this->bank()->lastStatement()?->closing_balance
            ?? (string) $this->bank()->opening_balance;

        $movement = \Brick\Math\BigDecimal::zero();

        foreach ($lines as $line) {
            $movement = $movement->plus((string) $line['amount']);
        }

        $previous = $this->bank()->lastStatement();
        $start = $previous === null
            ? $from->copy()
            : Carbon::parse($previous->period_to->toDateString())->addDay();

        return app(StatementImport::class)->import(
            account: $this->bank(),
            sourceFormat: 'camt053',
            periodFrom: $start,
            periodTo: $start->copy()->addDays(6),
            openingBalance: (string) $opening,
            closingBalance: (string) \Brick\Math\BigDecimal::of((string) $opening)->plus($movement)->toScale(4),
            lines: $lines,
            statementNumber: 'MATCH-'.Str::upper(Str::random(4)),
            contentHash: $hash ?? hash('sha256', (string) Str::ulid()),
            userId: $this->actorId(),
        );
    }

    /**
     * One credit line, with whatever the check needs on it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function line(string $amount, array $overrides = []): array
    {
        $day = $this->openingDate()->addDays(2)->toDateString();

        return [
            'booking_date' => $day,
            'value_date' => $day,
            'amount' => $amount,
            'counterparty_name' => 'Gulf Retail LLC',
            ...$overrides,
        ];
    }

    protected function transactionBy(string $reference): \App\Tenant\Banking\Models\BankTransaction
    {
        return \App\Tenant\Banking\Models\BankTransaction::query()
            ->where('bank_account_id', (string) $this->bank()->getKey())
            ->where('bank_reference', $reference)
            ->firstOrFail();
    }

    /**
     * A second GL account a bank account may mirror.
     *
     * A postable sub-account UNDER the chart's bank account, with no role of
     * its own - which is how a business with two bank accounts models the
     * second one, since `account_roles` permits exactly one `bank` role per
     * entity.
     */
    protected function secondBankGlAccountId(): string
    {
        $parent = DB::connection('tenant')->table('accounts')
            ->where('id', $this->bankGlAccountId)
            ->first();

        if ($parent === null) {
            return '';
        }

        $existing = DB::connection('tenant')->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('path', 'like', (string) $parent->path.'.%')
            ->where('postable', true)
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('bank_accounts')
                    ->whereColumn('bank_accounts.gl_account_id', 'accounts.id');
            })
            ->orderBy('code')
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::ulid();

        DB::connection('tenant')->table('accounts')->insert([
            'id' => $id,
            'entity_id' => $this->entityId,
            'code' => (string) $parent->code.'.9',
            'path' => (string) $parent->path.'.9',
            'parent_id' => $this->bankGlAccountId,
            'name_ar' => 'حساب بنكي ثانٍ',
            'name_en' => 'Second bank account',
            'type' => (string) $parent->type,
            'postable' => true,
            'monetary' => true,
            'currency' => $parent->currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Attempt something and return the refusal, or null if it succeeded.
     *
     * In its own savepoint, rolled back either way: several of these
     * refusals come from the database, and in PostgreSQL a failed statement
     * aborts its transaction - so without the rollback the next check fails
     * with "current transaction is aborted" and the report blames the wrong
     * rule.
     *
     * ## The exception's class is part of the answer
     *
     * Prefixed to the message, because a structural rule here is guarded in
     * two places and the two are not interchangeable. With a service guard
     * removed the database still refuses - so a check that only read the
     * message passed while the service's contract was broken, and a caller
     * catching `BankAccountRefused` would be hit by a `QueryException`
     * instead. Proved by trying: the subtree bite did not fire until the
     * class was asserted.
     *
     * The prefix is harmless to the message assertions, which all look for
     * substrings.
     *
     * @return string|null
     */
    protected function refusal(callable $attempt): ?string
    {
        $savepoint = 'bk_'.bin2hex(random_bytes(4));

        $this->connection?->statement('SAVEPOINT '.$savepoint);

        try {
            $attempt();
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return null;
        } catch (\Throwable $e) {
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return $e::class.': '.Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 400);
        }
    }
}
