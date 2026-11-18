<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Reporting\Services\StatementOfCashFlows;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The statement of cash flows - PF-186.
 *
 * ## What a check here is for
 *
 * **Two methods that agree because they share arithmetic.** That is the
 * failure this whole design is arranged against: if the direct method
 * derived anything from the indirect one, their agreement would prove
 * nothing at all. So the checks assert each against the CASH ACCOUNTS -
 * what the bank actually did - and not only against each other.
 *
 * **A non-cash movement reported as a cash flow.** A revaluation or a
 * translation difference changes a balance-sheet account and moves no
 * money. The indirect method would report it unless it is taken out, and
 * taking it out silently is how a statement reconciles by hiding its own
 * evidence.
 *
 * **A transfer inside cash reported as two flows.** Moving money from the
 * current account to the deposit account is not a flow at all - IAS 7.7 -
 * and a direct method counting both sides reports an inflow and an outflow
 * that never happened.
 */
final class CashFlowRules
{
    use Concerns\OpensBankAccounts;
    use Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['cash flows (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheBankSide();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'the two methods agree on the net movement' => fn (): array => $this->theMethodsAgree(),
                'and both agree with what the cash accounts did' => fn (): array => $this->theyAgreeWithTheBank(),
                'a receipt is an operating flow' => fn (): array => $this->aReceiptIsOperating(),
                'a non-cash movement is excluded and listed' => fn (): array => $this->nonCashIsListed(),
                'a transfer inside cash is not a flow' => fn (): array => $this->anInternalTransferIsNotAFlow(),
                'an unclassified counterpart is reported, not absorbed' => fn (): array => $this->unclassifiedIsReported(),
                'a reversed period is refused' => fn (): array => $this->aReversedPeriodIsRefused(),
            ]));
        } catch (Throwable $e) {
            return ['cash flows could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return array<string, mixed> */
    private function statement(): array
    {
        return app(StatementOfCashFlows::class)->forPeriod(
            $this->entityId,
            'SAR',
            Carbon::parse($this->date->toDateString())->startOfMonth(),
            Carbon::parse($this->date->toDateString())->endOfMonth(),
        );
    }

    /** @return list<string> */
    private function theMethodsAgree(): array
    {
        $this->tradeAndBank();

        $statement = $this->statement();

        $indirect = Money::of((string) $statement['indirect']['net_movement'], 'SAR');
        $direct = Money::of((string) $statement['direct']['net_movement'], 'SAR');

        if (! $indirect->equals($direct)) {
            return [sprintf(
                'indirect reports %s and direct reports %s. They share no arithmetic, so one of them is '.
                'wrong and the difference is almost always a non-cash movement nothing has tagged.',
                (string) $indirect->amount,
                (string) $direct->amount,
            )];
        }

        if ((string) $statement['declaration']['severity'] !== 'ok') {
            return ['the two methods agree and the statement does not say so: '
                .(string) $statement['declaration']['en']];
        }

        return [];
    }

    /** @return list<string> */
    private function theyAgreeWithTheBank(): array
    {
        $this->tradeAndBank();

        $statement = $this->statement();

        $opening = Money::of((string) $statement['opening_cash'], 'SAR');
        $closing = Money::of((string) $statement['closing_cash'], 'SAR');
        $actual = $closing->minus($opening);

        $problems = [];

        /*
         | Against the ACCOUNTS, not only against each other.
         |
         | Two methods that agreed with one another and not with the bank
         | would be two wrong answers, and a check comparing only the two
         | would report them as right.
         */
        foreach (['indirect', 'direct'] as $method) {
            $reported = Money::of((string) $statement[$method]['net_movement'], 'SAR');

            if (! $reported->equals($actual)) {
                $problems[] = sprintf(
                    'the %s method reports %s and the cash accounts moved by %s',
                    $method,
                    (string) $reported->amount,
                    (string) $actual->amount,
                );
            }
        }

        if ($actual->isZero()) {
            $problems[] = 'the fixture moved no cash at all, so this check compares three zeros and '.
                'would pass on a statement that reported nothing';
        }

        return $problems;
    }

    /** @return list<string> */
    private function aReceiptIsOperating(): array
    {
        /*
         | A receipt and NOTHING else.
         |
         | `tradeAndBank()` also releases a supplier payment larger than
         | the receipt, so its operating section is legitimately negative -
         | and the first version of this check read that as a defect. What
         | is under test is where a receipt lands, not what the net of two
         | unrelated flows comes to.
         */
        $this->issue();
        $this->bankAReceipt();

        $statement = $this->statement();
        $operating = null;

        foreach ($statement['direct']['sections'] as $section) {
            if ($section['activity'] === 'operating') {
                $operating = $section;
            }
        }

        if ($operating === null) {
            return ['the direct method has no operating section at all'];
        }

        $amount = Money::of((string) $operating['amount'], 'SAR');
        $problems = [];

        if (! $amount->isPositive()) {
            $problems[] = sprintf(
                'a customer receipt against an invoice produced an operating figure of %s. A receipt '.
                'settles a receivable, which is working capital, so it is an operating inflow.',
                (string) $amount->amount,
            );
        }

        /*
         | And nothing landed in the other two.
         |
         | "Operating is positive" alone is a weak claim, which the bite
         | sweep proved: moving receivables to financing left a sliver of
         | withholding tax in operating and the figure stayed positive. A
         | customer paying an invoice is not an investment and is not
         | capital, so those sections have to be EMPTY - and that is the
         | assertion the misclassification cannot survive.
         */
        foreach ($statement['direct']['sections'] as $section) {
            if ($section['activity'] === 'operating') {
                continue;
            }

            $other = Money::of((string) $section['amount'], 'SAR');

            if (! $other->isZero()) {
                $problems[] = sprintf(
                    'a customer receipt put %s into the %s section. Settling a receivable is neither an '.
                    'investment nor capital, and a statement that says it is reports cash the business '.
                    'did not raise.',
                    (string) $other->amount,
                    (string) $section['activity'],
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function nonCashIsListed(): array
    {
        $this->tradeAndBank();
        $this->creditTheTranslationReserve('250.0000');

        $statement = $this->statement();
        $nonCash = $statement['indirect']['non_cash'];

        if ($nonCash === []) {
            return ['a translation difference was posted and the statement lists no non-cash movement. '.
                'It changed a balance-sheet account and moved no money, so it is either excluded AND '.
                'shown, or the reader has no way to explain the difference it causes.'];
        }

        /*
         | And it must not have reached a section.
         |
         | A non-cash movement counted as financing reports capital the
         | shareholders never put in, and the net movement then disagrees
         | with the bank - which the next check would catch, but not by
         | name.
         */
        $indirect = Money::of((string) $statement['indirect']['net_movement'], 'SAR');
        $direct = Money::of((string) $statement['direct']['net_movement'], 'SAR');

        if (! $indirect->equals($direct)) {
            return [sprintf(
                'after a non-cash posting the two methods disagree (%s against %s), so it was counted '.
                'as a flow somewhere.',
                (string) $indirect->amount,
                (string) $direct->amount,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function anInternalTransferIsNotAFlow(): array
    {
        $this->tradeAndBank();

        $before = Money::of((string) $this->statement()['direct']['net_movement'], 'SAR');

        $this->transferBetweenCashAccounts('400.0000');

        $after = $this->statement();
        $moved = Money::of((string) $after['direct']['net_movement'], 'SAR');

        if (! $moved->equals($before)) {
            return [sprintf(
                'moving money between two cash accounts changed the net movement from %s to %s. IAS 7.7 '.
                'reports the movement IN cash, so a transfer inside it is not a flow - and counting '.
                'both sides reports an inflow and an outflow that never happened.',
                (string) $before->amount,
                (string) $moved->amount,
            )];
        }

        if ((string) $after['declaration']['severity'] !== 'ok') {
            return ['an internal transfer left the statement declaring itself broken: '
                .(string) $after['declaration']['en']];
        }

        return [];
    }

    /** @return list<string> */
    private function unclassifiedIsReported(): array
    {
        $this->tradeAndBank();

        /*
         | Strip the classification off the receivable the receipt settled.
         |
         | The cash line then faces an account with no statement line, and
         | the question is whether the product says so or quietly files it
         | under operating - which would make the two methods agree by
         | putting the mystery somewhere plausible.
         */
        $this->connection->statement(
            'UPDATE accounts SET statement_line = NULL WHERE entity_id = ? AND id IN '.
            '(SELECT account_id FROM account_roles WHERE entity_id = ? AND role = ?)',
            [$this->entityId, $this->entityId, 'accounts_receivable'],
        );

        $statement = $this->statement();

        if (Money::of((string) $statement['direct']['unclassified'], 'SAR')->isZero()) {
            return ['cash moved against an account with no classification and the statement reports '.
                'nothing unclassified, so it has been absorbed into a section that looks plausible'];
        }

        if ((string) $statement['declaration']['severity'] === 'ok') {
            return ['the direct method is short by an unclassified movement and the statement declares '.
                'itself fine'];
        }

        return [];
    }

    /** @return list<string> */
    private function aReversedPeriodIsRefused(): array
    {
        try {
            app(StatementOfCashFlows::class)->forPeriod(
                $this->entityId,
                'SAR',
                Carbon::parse($this->date->toDateString())->endOfMonth(),
                Carbon::parse($this->date->toDateString())->startOfMonth(),
            );
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'ends before it starts')
                ? []
                : ['a reversed period was refused for the wrong reason: '.$e->getMessage()];
        }

        return ['a period ending before it starts was accepted; every inflow in it would be reported as '.
            'an outflow'];
    }

    /**
     * Real money in and out, through the rules that move it.
     *
     * ## Why not a manual journal
     *
     * The bank and the receivables control are control accounts, and the
     * product refuses a manual entry to one: their balance is the total of
     * a subledger, and a manual posting makes the two disagree with nothing
     * afterwards able to say which is right. The first version of this
     * fixture wrote journals to both and was refused on every row - the
     * guard working, and the fixture asking for the wrong thing.
     *
     * So the cash moves the way it moves in the product: an opening
     * balance, an invoice, a receipt banked against it, and a supplier
     * payment released. Without cash on the books every figure on this
     * statement is zero and every check comparing them passes on a product
     * that reports nothing.
     */
    private function tradeAndBank(): void
    {
        $this->postTheOpeningEntry('5000.0000');
        $this->issue();
        $this->bankAReceipt();
        $this->releaseAPayment();
    }

    /**
     * Move money between two things the statement calls cash.
     *
     * The second account is created here, because the shipped charts give
     * every cash account a control role except petty cash - and a transfer
     * needs two. An entity adding a cash account is ordinary; the fixture
     * does what a customer would.
     */
    private function transferBetweenCashAccounts(string $amount): void
    {
        $petty = (string) $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('statement_line', 'cash_and_cash_equivalents')
            ->where('postable', true)
            ->whereNotIn('id', function ($query): void {
                $query->select('account_id')
                    ->from('account_roles')
                    ->where('entity_id', $this->entityId);
            })
            ->orderBy('code')
            ->value('code');

        if ($petty === '') {
            return;
        }

        $second = $this->aSecondCashAccount();

        if ($second === '') {
            return;
        }

        $this->journal([[$second, $amount], [$petty, '-'.$amount]]);
    }

    private function aSecondCashAccount(): string
    {
        $parent = $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('statement_line', 'cash_and_cash_equivalents')
            ->orderBy('code')
            ->first();

        if ($parent === null) {
            return '';
        }

        $code = $parent->code.'9';

        $existing = $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('code', $code)
            ->value('code');

        if ($existing !== null) {
            return (string) $existing;
        }

        \App\Tenant\Ledger\Models\Account::query()->create([
            'entity_id' => $this->entityId,
            'code' => $code,
            'name_ar' => 'حساب نقدي ثانٍ',
            'name_en' => 'A second cash account',
            'parent_id' => $parent->parent_id,
            'path' => $parent->path.'9',
            'depth' => (int) $parent->depth,
            'type' => 'asset',
            'statement_line' => 'cash_and_cash_equivalents',
            'postable' => true,
            'monetary' => true,
        ]);

        return $code;
    }

    private function creditTheTranslationReserve(string $amount): void
    {
        $reserve = (string) $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('statement_line', 'foreign_operation_translation')
            ->value('code');

        $counter = (string) $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('type', 'asset')
            ->where('postable', true)
            ->where('statement_line', '<>', 'cash_and_cash_equivalents')
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

        $this->journal([[$counter, $amount], [$reserve, '-'.$amount]]);
    }

    private function codeForRole(string $role): string
    {
        return (string) $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->whereIn('id', function ($query) use ($role): void {
                $query->select('account_id')
                    ->from('account_roles')
                    ->where('entity_id', $this->entityId)
                    ->where('role', $role);
            })
            ->value('code');
    }

    /**
     * Post a balanced entry through the real import path.
     *
     * @param  list<array{0: string, 1: string}>  $lines
     */
    private function journal(array $lines): void
    {
        $reference = 'CF-'.Str::random(5);

        $rows = array_map(
            fn (array $line): array => [
                'reference' => $reference,
                'date' => $this->date->toDateString(),
                'account_code' => $line[0],
                'amount' => $line[1],
            ],
            $lines,
        );

        $was = \Illuminate\Support\Facades\Auth::guard('web')->user();
        \Illuminate\Support\Facades\Auth::guard('web')->setUser($this->anAccountant());

        try {
            app(\App\Tenant\Ledger\Manual\JournalImport::class)->import(
                $this->entity(),
                $rows,
                reason: 'cash movement, for the statement of cash flows',
                headerRows: 0,
            );
        } finally {
            \Illuminate\Support\Facades\Auth::guard('web')->forgetUser();

            if ($was !== null) {
                \Illuminate\Support\Facades\Auth::guard('web')->setUser($was);
            }
        }
    }

    private function anAccountant(): \App\Tenant\Identity\Models\User
    {
        $role = \App\Tenant\Identity\Models\Role::query()->where('key', 'accountant')->firstOrFail();

        $user = \App\Tenant\Identity\Models\User::query()->create([
            'name' => 'Cash Flow Probe',
            'email' => 'cashflow-probe-'.bin2hex(random_bytes(6)).'@invalid.test',
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
}
