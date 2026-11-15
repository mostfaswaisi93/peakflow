<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Tax\Posting\Rules\PurchaseTaxRule;
use App\Tenant\Tax\Posting\Rules\ReverseChargeSelfAssessmentRule;
use App\Tenant\Tax\Posting\Rules\SalesTaxRule;
use App\Tenant\Tax\Posting\Rules\WithholdingOnPaymentRule;
use App\Tenant\Tax\Posting\Rules\WithholdingOnReceiptRule;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tax accounts and the postings that reach them - PF-084.
 *
 * ## What is actually being claimed
 *
 * That each posting lands on the right account, in the right jurisdiction,
 * with the right sign - and that the two failures nobody notices are refused.
 *
 * The first is **one VAT account for two jurisdictions**: the figure in it is
 * the sum of two authorities' liabilities, every document posts somewhere
 * plausible, and neither return reconciles.
 *
 * The second is **irrecoverable tax in the input account**: the account
 * overstates the claim by exactly that amount every month, in a figure nobody
 * can derive without re-reading the documents.
 *
 * ## The entries are really posted
 *
 * Through the Poster, against a real chart, into an open period - not by
 * inspecting what a rule returns. A rule can return a perfectly balanced set
 * of lines that the poster then refuses, and a check that stopped at the lines
 * would be green while nothing could be posted.
 */
final class TaxPostingRules
{
    use \Tests\Support\Concerns\ProvesTheLedger;

    private ?Connection $connection = null;

    private string $entityId = '';

    private ?Entity $entity = null;

    private Carbon $date;

    private string $expenseAccountId = '';

    public function __construct()
    {
        $this->date = Carbon::parse('2026-05-15');
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['tax posting (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return $this->withTheLedgerProof($this->runEach([
                'a VAT role exists per jurisdiction, and the chart stamps the entity\'s own' => fn (): array => $this->rolesArePerJurisdiction(),
                'sales tax credits the output account of the right authority' => fn (): array => $this->salesTax(),
                'purchase tax debits the input account, and only the recoverable part' => fn (): array => $this->purchaseTax(),
                'irrecoverable tax goes to expense, and refuses without an account' => fn (): array => $this->irrecoverableTax(),
                'a reverse charge posts both sides and nets to nothing' => fn (): array => $this->reverseCharge(),
                'a partly exempt reverse charge is not neutral' => fn (): array => $this->partlyExemptReverseCharge(),
                'withholding on a payment clears the payable in full' => fn (): array => $this->withholdingOnPayment(),
                'withholding on a receipt closes the invoice in full' => fn (): array => $this->withholdingOnReceipt(),
                'a posting without a jurisdiction is refused' => fn (): array => $this->jurisdictionIsRequired(),
                'every tax rule describes its own debits and credits' => fn (): array => $this->rulesDescribeThemselves(),
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function rolesArePerJurisdiction(): array
    {
        $violations = [];

        foreach ([ControlRole::VatInput, ControlRole::VatOutput] as $role) {
            if (! $role->isPerJurisdiction()) {
                $violations[] = sprintf('%s is not per jurisdiction', $role->value);
            }

            $assigned = AccountRole::query()
                ->where('entity_id', $this->entityId)
                ->where('role', $role->value)
                ->pluck('jurisdiction')
                ->all();

            if ($assigned !== ['SA']) {
                $violations[] = sprintf(
                    'the chart assigned %s for %s and should assign it for the entity\'s own country, SA',
                    $role->value, implode(', ', array_map(static fn ($j): string => (string) ($j ?? 'null'), $assigned)),
                );
            }
        }

        foreach ([ControlRole::Bank, ControlRole::AccountsReceivable, ControlRole::WithholdingPayable] as $role) {
            if ($role->isPerJurisdiction()) {
                $violations[] = sprintf(
                    '%s is per jurisdiction. A bank account is a bank account wherever the entity is '.
                    'registered, and a jurisdiction on it is a value the next reader has to interpret',
                    $role->value,
                );
            }
        }

        /*
         | And a second jurisdiction can be added without disturbing the first.
         |
         | Which is the whole arrangement: a Saudi entity that registers in
         | Jordan gets a second pair, and both returns reconcile to their own
         | account.
         */
        $jordanOutput = Account::query()->where('entity_id', $this->entityId)->where('code', '2.1.3')->first();

        if ($jordanOutput === null) {
            return [...$violations, 'the fixture chart has no spare account to assign'];
        }

        try {
            AccountRole::create([
                'entity_id' => $this->entityId,
                'role' => 'vat_output',
                'account_id' => $jordanOutput->getKey(),
                'jurisdiction' => 'JO',
            ]);
        } catch (Throwable $e) {
            $violations[] = 'a second jurisdiction\'s output account could not be assigned: '.$e->getMessage();
        }

        // And a duplicate within one jurisdiction is still refused.
        try {
            AccountRole::create([
                'entity_id' => $this->entityId,
                'role' => 'vat_output',
                'account_id' => $jordanOutput->getKey(),
                'jurisdiction' => 'SA',
            ]);

            $violations[] = 'a second SA output account was assigned. "Exactly one account per role per '.
                'jurisdiction" is what the posting layer rests on';
        } catch (Throwable) {
            // Correct.
        }

        // A jurisdiction on a role that has none is refused by the database.
        try {
            AccountRole::query()
                ->where('entity_id', $this->entityId)
                ->where('role', 'bank')
                ->update(['jurisdiction' => 'SA']);

            $violations[] = 'the bank role accepted a jurisdiction';
        } catch (Throwable) {
            // Correct.
        }

        return $violations;
    }

    /** @return list<string> */
    public function salesTax(): array
    {
        $entry = $this->post(SalesTaxRule::KEY, [
            'tax_amount' => '150.0000',
            'jurisdiction' => 'SA',
            'narration' => 'output tax on INV-1',
        ]);

        return $this->expectLines($entry, [
            [ControlRole::AccountsReceivable, null, '150.0000'],
            [ControlRole::VatOutput, 'SA', '-150.0000'],
        ]);
    }

    /** @return list<string> */
    public function purchaseTax(): array
    {
        $entry = $this->post(PurchaseTaxRule::KEY, [
            'tax_amount' => '150.0000',
            'recoverable_amount' => '150.0000',
            'jurisdiction' => 'SA',
        ]);

        return $this->expectLines($entry, [
            [ControlRole::VatInput, 'SA', '150.0000'],
            [ControlRole::AccountsPayable, null, '-150.0000'],
        ]);
    }

    /** @return list<string> */
    public function irrecoverableTax(): array
    {
        $violations = [];

        $entry = $this->post(PurchaseTaxRule::KEY, [
            'tax_amount' => '150.0000',
            'recoverable_amount' => '90.0000',
            'jurisdiction' => 'SA',
            'irrecoverable_account_id' => $this->expenseAccountId,
        ]);

        $violations = [...$violations, ...$this->expectLines($entry, [
            [ControlRole::VatInput, 'SA', '90.0000'],
            [null, null, '60.0000'],
            [ControlRole::AccountsPayable, null, '-150.0000'],
        ])];

        $input = $this->amountOn($entry, ControlRole::VatInput, 'SA');

        if ($input !== '90.0000') {
            $violations[] = sprintf(
                'the input account took %s of a 150.00 tax with 90.00 recoverable. Tax the business '.
                'cannot reclaim sitting in that account overstates the claim by exactly the difference, '.
                'every month, in a figure nobody can derive without re-reading the documents',
                $input ?? 'nothing',
            );
        }

        // And without an account to put it on, the posting refuses.
        $refusal = $this->refusal(fn (): JournalEntry => $this->post(PurchaseTaxRule::KEY, [
            'tax_amount' => '150.0000',
            'recoverable_amount' => '90.0000',
            'jurisdiction' => 'SA',
        ]));

        if ($refusal === null) {
            $violations[] = 'irrecoverable tax with no expense account was posted anyway, so it went '.
                'somewhere nobody named';
        } elseif (! str_contains($refusal, 'input account')) {
            $violations[] = 'the refusal does not say why the input account is the wrong home: '.$refusal;
        }

        // Claiming more than was charged is refused.
        if ($this->refusal(fn (): JournalEntry => $this->post(PurchaseTaxRule::KEY, [
            'tax_amount' => '150.0000',
            'recoverable_amount' => '200.0000',
            'jurisdiction' => 'SA',
        ])) === null) {
            $violations[] = 'a recoverable amount larger than the tax charged was posted. The entry '.
                'balances and the excess sits in the input account as a claim the authority will refuse';
        }

        return $violations;
    }

    /** @return list<string> */
    public function reverseCharge(): array
    {
        $violations = [];

        $entry = $this->post(ReverseChargeSelfAssessmentRule::KEY, [
            'tax_amount' => '150.0000',
            'jurisdiction' => 'SA',
        ]);

        $violations = [...$violations, ...$this->expectLines($entry, [
            [ControlRole::VatInput, 'SA', '150.0000'],
            [ControlRole::VatOutput, 'SA', '-150.0000'],
        ])];

        /*
         | No payable line, deliberately.
         |
         | The supplier is owed the net and nothing more - the tax never
         | passes through them. Crediting the payable with it shows the
         | supplier as owed money the business owes the authority, which is
         | the mistake that produces an overpayment.
         */
        if ($this->amountOn($entry, ControlRole::AccountsPayable, null) !== null) {
            $violations[] = 'the reverse charge credited the payable. The supplier is owed the net and '.
                'nothing more; crediting it shows them as owed money that goes to the authority';
        }

        return $violations;
    }

    /** @return list<string> */
    public function partlyExemptReverseCharge(): array
    {
        $violations = [];

        $entry = $this->post(ReverseChargeSelfAssessmentRule::KEY, [
            'tax_amount' => '150.0000',
            'recoverable_amount' => '90.0000',
            'jurisdiction' => 'SA',
            'irrecoverable_account_id' => $this->expenseAccountId,
        ]);

        $violations = [...$violations, ...$this->expectLines($entry, [
            [ControlRole::VatInput, 'SA', '90.0000'],
            [null, null, '60.0000'],
            [ControlRole::VatOutput, 'SA', '-150.0000'],
        ])];

        /*
         | The output side is the FULL tax, and that is the point.
         |
         | For a fully recoverable business the two legs net to nothing, which
         | is why forgetting the output leg is invisible on a trial balance.
         | For a partly exempt one the irrecoverable part is a real cost, and
         | the output liability is still the whole amount.
         */
        if ($this->amountOn($entry, ControlRole::VatOutput, 'SA') !== '-150.0000') {
            $violations[] = 'the self-assessed output liability is not the whole tax. A partly exempt '.
                'business still owes the authority all of it; what it cannot do is reclaim all of it';
        }

        return $violations;
    }

    /** @return list<string> */
    public function withholdingOnPayment(): array
    {
        $violations = [];

        $entry = $this->post(WithholdingOnPaymentRule::KEY, [
            'gross_amount' => '1000.0000',
            'withheld_amount' => '100.0000',
        ]);

        $violations = [...$violations, ...$this->expectLines($entry, [
            [ControlRole::AccountsPayable, null, '1000.0000'],
            [ControlRole::Bank, null, '-900.0000'],
            [ControlRole::WithholdingPayable, null, '-100.0000'],
        ])];

        if ($this->amountOn($entry, ControlRole::AccountsPayable, null) !== '1000.0000') {
            $violations[] = 'the payable was not cleared in full. The supplier is owed the whole '.
                'invoice, of which part goes to the authority on its behalf - clearing only what was '.
                'transferred leaves a balance the supplier chases until somebody writes it off';
        }

        if ($this->refusal(fn (): JournalEntry => $this->post(WithholdingOnPaymentRule::KEY, [
            'gross_amount' => '100.0000',
            'withheld_amount' => '150.0000',
        ])) === null) {
            $violations[] = 'a withholding larger than the payment was posted';
        }

        return $violations;
    }

    /** @return list<string> */
    public function withholdingOnReceipt(): array
    {
        $violations = [];

        $entry = $this->post(WithholdingOnReceiptRule::KEY, [
            'gross_amount' => '1000.0000',
            'withheld_amount' => '100.0000',
            'certificate_number' => 'WHT-2026/00042',
        ]);

        $violations = [...$violations, ...$this->expectLines($entry, [
            [ControlRole::Bank, null, '900.0000'],
            [ControlRole::WithholdingReceivable, null, '100.0000'],
            [ControlRole::AccountsReceivable, null, '-1000.0000'],
        ])];

        if ($this->amountOn($entry, ControlRole::AccountsReceivable, null) !== '-1000.0000') {
            $violations[] = 'the receivable was not closed in full. An invoice total that does not equal '.
                'the cash received is correct when withholding applies, and this is the posting that '.
                'makes it true in the books';
        }

        $narrations = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $entry->getKey())
            ->pluck('narration')
            ->filter()
            ->implode(' ');

        if (! str_contains($narrations, 'WHT-2026/00042')) {
            $violations[] = 'the certificate number is nowhere on the entry, so when the authority '.
                'queries the claim there is nothing in the ledger to look it up by';
        }

        return $violations;
    }

    /** @return list<string> */
    public function jurisdictionIsRequired(): array
    {
        $violations = [];

        $refusal = $this->refusal(fn (): JournalEntry => $this->post(SalesTaxRule::KEY, [
            'tax_amount' => '150.0000',
            'jurisdiction' => '',
        ]));

        if ($refusal === null) {
            $violations[] = 'output tax was posted with no jurisdiction. An entity registered in two '.
                'would put one authority\'s VAT in the other\'s return, with a figure that looks '.
                'plausible in both';
        }

        $missing = $this->refusal(fn (): JournalEntry => $this->post(SalesTaxRule::KEY, [
            'tax_amount' => '150.0000',
            'jurisdiction' => 'JO',
        ]));

        if ($missing === null) {
            $violations[] = 'output tax was posted in a jurisdiction this entity has no account for. It '.
                'has one for SA, and posting there would put Jordanian VAT in the Saudi return';
        } elseif (! str_contains($missing, 'JO')) {
            $violations[] = 'the refusal does not name the jurisdiction that is missing: '.$missing;
        }

        return $violations;
    }

    /** @return list<string> */
    public function rulesDescribeThemselves(): array
    {
        $violations = [];

        $keys = [
            SalesTaxRule::KEY, PurchaseTaxRule::KEY, ReverseChargeSelfAssessmentRule::KEY,
            WithholdingOnPaymentRule::KEY, WithholdingOnReceiptRule::KEY,
        ];

        $registry = app(\App\Tenant\Ledger\Posting\PostingRuleRegistry::class);

        foreach ($keys as $key) {
            if (! $registry->has($key)) {
                $violations[] = sprintf(
                    'the rule "%s" is not registered, so nothing can post through it - and the posting '.
                    'that needed it fails at the moment somebody issues a document', $key,
                );

                continue;
            }

            $description = $registry->resolve($key)->describe();
            $encoded = json_encode($description, JSON_UNESCAPED_UNICODE);
            $encoded = \is_string($encoded) ? $encoded : '';

            foreach (['Dr', 'Cr'] as $side) {
                if (! str_contains($encoded, $side)) {
                    $violations[] = sprintf(
                        'the rule "%s" does not write out its %s lines. The inspection screen is where '.
                        'an accountant checks what a rule does without reading PHP',
                        $key, $side,
                    );
                }
            }

            if (preg_match('/\p{Arabic}/u', $encoded) !== 1) {
                $violations[] = sprintf('the rule "%s" has no Arabic description', $key);
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function post(string $rule, array $event): JournalEntry
    {
        \assert($this->entity !== null);

        return app(Poster::class)->post($this->entity, $rule, $event, $this->date);
    }

    /**
     * @param  list<array{0: ControlRole|null, 1: string|null, 2: string}>  $expected
     * @return list<string>
     */
    private function expectLines(JournalEntry $entry, array $expected): array
    {
        $violations = [];

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $entry->getKey())
            ->get();

        if ($lines->count() !== \count($expected)) {
            $violations[] = sprintf(
                'the entry has %d lines and should have %d', $lines->count(), \count($expected),
            );
        }

        foreach ($expected as [$role, $jurisdiction, $amount]) {
            if ($role === null) {
                // A named account rather than a role: match on the amount.
                $found = $lines->first(static fn (object $line): bool => (string) $line->amount === $amount);

                if ($found === null) {
                    $violations[] = sprintf('no line of %s against a named account', $amount);
                }

                continue;
            }

            $actual = $this->amountOn($entry, $role, $jurisdiction);

            if ($actual !== $amount) {
                $violations[] = sprintf(
                    '%s%s took %s and should take %s',
                    $role->value,
                    $jurisdiction === null ? '' : ' in '.$jurisdiction,
                    $actual ?? 'nothing',
                    $amount,
                );
            }
        }

        return $violations;
    }

    private function amountOn(JournalEntry $entry, ControlRole $role, ?string $jurisdiction): ?string
    {
        $accountId = AccountRole::query()
            ->where('entity_id', $this->entityId)
            ->where('role', $role->value)
            ->when($jurisdiction !== null, fn ($q) => $q->where('jurisdiction', $jurisdiction))
            ->value('account_id');

        if ($accountId === null) {
            return null;
        }

        $amount = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $entry->getKey())
            ->where('account_id', $accountId)
            ->value('amount');

        return $amount === null ? null : (string) $amount;
    }

    /** @param  callable(): mixed  $attempt */
    private function refusal(callable $attempt): ?string
    {
        $savepoint = 'txp_'.bin2hex(random_bytes(4));
        $this->connection?->statement('SAVEPOINT '.$savepoint);

        try {
            $attempt();

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        } finally {
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
        }
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'TXP'.random_int(100, 999),
            'legal_name' => 'Tax posting check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $this->entity = $entity;
        $context->use($entity);

        $period = app(FiscalCalendarGenerator::class)->periodFor($this->entityId, $this->date);
        app(PeriodStateMachine::class)->transition($period, PeriodState::Open, 'opened for the tax posting check');

        $expense = Account::query()
            ->where('entity_id', $this->entityId)
            ->where('type', 'expense')
            ->where('postable', true)
            ->whereNotIn('code', ['5.3', '5.4', '5.5', '5.6'])
            ->orderBy('code')
            ->first();

        if ($expense === null) {
            throw new \RuntimeException('the chart offers no plain expense account');
        }

        $this->expenseAccountId = (string) $expense->getKey();
    }

    /**
     * @param  array<string, callable(): list<string>>  $rules
     * @return array<string, list<string>>
     */
    private function runEach(array $rules): array
    {
        $results = [];

        foreach ($rules as $name => $rule) {
            $savepoint = 'txpr_'.bin2hex(random_bytes(4));
            $this->connection?->statement('SAVEPOINT '.$savepoint);

            try {
                $results[$name] = $rule();
            } catch (Throwable $e) {
                $results[$name] = ['the check could not run: '.$e->getMessage()];
            } finally {
                $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }
        }

        return $results;
    }
}
