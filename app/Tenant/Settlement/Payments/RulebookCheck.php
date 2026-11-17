<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Payments;

use App\Support\Money\Money;
use App\Support\Text\RestrictedLatin;
use App\Tenant\Parties\Models\PartyBankAccount;
use App\Tenant\Parties\Services\BankDetailDisclosure;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything the scheme would reject, found before the file exists - PF-176.
 *
 * ## A payment file is rejected as a whole
 *
 * One beneficiary name with a forbidden character, one remittance line two
 * characters too long, one execution date on a Sunday: the bank returns
 * the batch and nobody is paid. The rejection arrives hours later, by
 * email, naming an XPath into a document the person who built it cannot
 * read.
 *
 * So this runs first, against the run somebody is looking at, and every
 * finding names the beneficiary and what to do. That is the difference
 * between a control and a log.
 *
 * ## Findings, not exceptions
 *
 * A run of forty transfers with three problems should report three, not
 * the first. Throwing on the first means three rounds of fix-and-retry,
 * each of which is a person waiting on a file.
 *
 * Each finding says whether it **blocks**. A name that had to be
 * transliterated does not block - it is going out that way and the person
 * needs to see it. A beneficiary in a country the scheme does not reach
 * does block, because there is no file that can carry it.
 */
final class RulebookCheck
{
    public function __construct(private readonly BankDetailDisclosure $disclosure) {}

    /**
     * @return array{
     *     scheme: string,
     *     findings: list<array{beneficiary: string|null, field: string, blocks: bool, why: string}>,
     *     blocks: bool,
     *     names: list<array{party_id: string, original: string, sent: string, lossy: bool, why: string}>,
     *     why: string,
     * }
     */
    public function of(object $run, PaymentScheme $scheme): array
    {
        $findings = [];
        $names = [];

        foreach ($this->runLevel($run, $scheme) as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->instructions((string) $run->id) as $instruction) {
            [$instructionFindings, $name] = $this->checkOne($instruction, $scheme);

            foreach ($instructionFindings as $finding) {
                $findings[] = $finding;
            }

            if ($name !== null) {
                $names[] = $name;
            }
        }

        $blocking = array_values(array_filter($findings, static fn (array $f): bool => $f['blocks']));

        return [
            'scheme' => $scheme->key,
            'findings' => $findings,
            'blocks' => $blocking !== [],
            'names' => $names,
            'why' => $blocking !== []
                ? sprintf(
                    '%s would reject this file, and it rejects the whole of it - so nobody in the run '.
                    'would be paid. %d thing%s %s to be fixed first: %s',
                    $scheme->label(),
                    \count($blocking),
                    \count($blocking) === 1 ? '' : 's',
                    \count($blocking) === 1 ? 'has' : 'have',
                    implode(' ', array_map(
                        static fn (array $f): string => ucfirst($f['why']).'.',
                        $blocking,
                    )),
                )
                : sprintf(
                    'This run satisfies the %s rulebook.%s',
                    $scheme->label(),
                    $findings === []
                        ? ''
                        : sprintf(
                            ' %d thing%s worth reading before it goes: %s',
                            \count($findings),
                            \count($findings) === 1 ? ' is' : 's are',
                            implode(' ', array_map(
                                static fn (array $f): string => ucfirst($f['why']).'.',
                                $findings,
                            )),
                        ),
                ),
        ];
    }

    /**
     * What the run as a whole has to satisfy.
     *
     * @return list<array{beneficiary: string|null, field: string, blocks: bool, why: string}>
     */
    private function runLevel(object $run, PaymentScheme $scheme): array
    {
        $findings = [];
        $currency = (string) $run->currency;

        if ($scheme->currency() !== null && $scheme->currency() !== $currency) {
            $findings[] = [
                'beneficiary' => null,
                'field' => 'currency',
                'blocks' => true,
                'why' => sprintf(
                    'this run pays in %s and %s carries %s only. That is not a file to correct, it is a '.
                    'different scheme - pay these from an account whose bank reaches %s',
                    $currency,
                    $scheme->label(),
                    (string) $scheme->currency(),
                    $currency,
                ),
            ];
        }

        $payOn = Carbon::parse((string) $run->pay_on);

        if ($scheme->businessDaysOnly() && $this->isWeekend($payOn, $scheme)) {
            /*
             | A weekend execution date.
             |
             | The bank does not move money on a day it is closed, and
             | different schemes are closed on different days: Saturday and
             | Sunday in the euro area, Friday and Saturday where the
             | working week starts on Sunday. Getting it wrong is a
             | rejected file for a reason nobody reads as a calendar.
             */
            $findings[] = [
                'beneficiary' => null,
                'field' => 'pay_on',
                'blocks' => true,
                'why' => sprintf(
                    'the run asks to pay on %s, a %s, and %s executes on business days only. Move the '.
                    'pay date - the run has to be prepared again, because the date is part of what was '.
                    'approved',
                    $payOn->toDateString(),
                    $payOn->format('l'),
                    $scheme->label(),
                ),
            ];
        }

        $ahead = (int) Carbon::now()->startOfDay()->diffInDays($payOn->copy()->startOfDay(), false);

        if ($scheme->maxDaysAhead() > 0 && $ahead > $scheme->maxDaysAhead()) {
            $findings[] = [
                'beneficiary' => null,
                'field' => 'pay_on',
                'blocks' => true,
                'why' => sprintf(
                    'the pay date is %d days away and %s accepts at most %d. A bank holding an '.
                    'instruction that long is not a service it offers',
                    $ahead,
                    $scheme->label(),
                    $scheme->maxDaysAhead(),
                ),
            ];
        }

        if ($ahead < 0) {
            $findings[] = [
                'beneficiary' => null,
                'field' => 'pay_on',
                'blocks' => false,
                'why' => sprintf(
                    'the pay date was %d day%s ago, so the bank will execute this as soon as it reads '.
                    'the file rather than on the date in it',
                    abs($ahead),
                    abs($ahead) === 1 ? '' : 's',
                ),
            ];
        }

        return $findings;
    }

    /**
     * One instruction, against everything the scheme says about it.
     *
     * @return array{0: list<array{beneficiary: string|null, field: string, blocks: bool, why: string}>, 1: array<string, mixed>|null}
     */
    private function checkOne(object $row, PaymentScheme $scheme): array
    {
        $who = $row->party_name === null ? 'a beneficiary' : (string) $row->party_name;
        $findings = [];
        $name = null;

        /*
         | The name, which is the one the counterparty reads.
         |
         | Converted and SHOWN rather than silently mangled: a supplier
         | whose name arrives as question marks on their statement will
         | ask, and "the system did that" is not an answer.
         */
        if ($row->party_name !== null) {
            $converted = RestrictedLatin::convert((string) $row->party_name, $scheme->limit('name'));

            $name = [
                'party_id' => (string) $row->party_id,
                'original' => (string) $row->party_name,
                'sent' => $converted['sent'],
                'lossy' => $converted['lossy'],
                'why' => $converted['why'],
            ];

            if ($converted['sent'] === '') {
                $findings[] = [
                    'beneficiary' => $who,
                    'field' => 'name',
                    'blocks' => true,
                    'why' => sprintf(
                        'nothing of "%s" survives the character set %s permits, so the file would name '.
                        'the beneficiary as an empty string. Record a Latin trading name for them',
                        (string) $row->party_name,
                        $scheme->label(),
                    ),
                ];
            } elseif ($converted['lossy']) {
                $findings[] = [
                    'beneficiary' => $who,
                    'field' => 'name',
                    'blocks' => false,
                    'why' => $converted['why'],
                ];
            }
        } else {
            $findings[] = [
                'beneficiary' => null,
                'field' => 'name',
                'blocks' => true,
                'why' => 'an instruction names no beneficiary, and a credit transfer with no creditor '.
                    'name is rejected by every scheme',
            ];
        }

        $account = $row->account_id === null
            ? null
            : PartyBankAccount::query()->find((string) $row->account_id);

        if ($account === null) {
            $findings[] = [
                'beneficiary' => $who,
                'field' => 'account',
                'blocks' => true,
                'why' => sprintf('%s has no account on this instruction, so there is nowhere to send the money', $who),
            ];

            return [$findings, $name];
        }

        foreach ($this->account($account, $who, $scheme) as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->amount($row, $who, $scheme) as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->remittance($row, $who, $scheme) as $finding) {
            $findings[] = $finding;
        }

        return [$findings, $name];
    }

    /**
     * Reachability, which is a question about the account's country.
     *
     * @return list<array{beneficiary: string|null, field: string, blocks: bool, why: string}>
     */
    private function account(PartyBankAccount $account, string $who, PaymentScheme $scheme): array
    {
        $findings = [];
        $country = $account->country === null ? null : mb_strtoupper((string) $account->country);

        if ($country === null) {
            return [[
                'beneficiary' => $who,
                'field' => 'account',
                'blocks' => true,
                'why' => sprintf('%s\'s account records no country, so whether the scheme reaches it cannot be answered', $who),
            ]];
        }

        if (! $scheme->reaches($country)) {
            /*
             | Reachability, declared rather than vendored.
             |
             | The scheme area is a list the operator declares their bank
             | reaches - see config/settlement.php for why that is not a
             | registry. A country outside it is a payment that has to go
             | another way, and saying so is more useful than a bank's
             | rejection code.
             */
            $findings[] = [
                'beneficiary' => $who,
                'field' => 'account',
                'blocks' => true,
                'why' => sprintf(
                    '%s banks in %s, which this bank does not reach under %s. The payment has to go by '.
                    'another route - an international transfer rather than this file',
                    $who,
                    $country,
                    $scheme->label(),
                ),
            ];

            return $findings;
        }

        if ($scheme->requiresIban()) {
            /*
             | Through the disclosure service, which records the read.
             |
             | Who LOOKED at a bank account is an auditable event, and
             | building a payment file is the most legitimate reason there
             | is to look - which is exactly why it goes on the record
             | rather than around it.
             */
            try {
                $this->disclosure->forAnInstruction(
                    $account,
                    sprintf('building a %s payment file', $scheme->label()),
                );
            } catch (\Throwable $e) {
                $findings[] = [
                    'beneficiary' => $who,
                    'field' => 'account',
                    'blocks' => true,
                    'why' => sprintf(
                        '%s\'s account cannot be read as an IBAN, which %s addresses by: %s',
                        $who,
                        $scheme->label(),
                        $e->getMessage(),
                    ),
                ];
            }
        }

        if ($scheme->requiresBic() && ($account->bic === null || trim((string) $account->bic) === '')) {
            $findings[] = [
                'beneficiary' => $who,
                'field' => 'account',
                'blocks' => true,
                'why' => sprintf('%s\'s account records no BIC, which %s requires', $who, $scheme->label()),
            ];
        }

        return $findings;
    }

    /**
     * @return list<array{beneficiary: string|null, field: string, blocks: bool, why: string}>
     */
    private function amount(object $row, string $who, PaymentScheme $scheme): array
    {
        $ceiling = $scheme->amountCeiling();

        if ($ceiling === null) {
            return [];
        }

        $amount = BigDecimal::of((string) $row->amount);

        if (! $amount->isGreaterThan($ceiling)) {
            return [];
        }

        return [[
            'beneficiary' => $who,
            'field' => 'amount',
            'blocks' => true,
            'why' => sprintf(
                'the transfer to %s is %s and %s carries at most %s per transaction. Split it across '.
                'instructions or send it another way - the beneficiary\'s payment limit is where a '.
                'split is recorded',
                $who,
                (string) Money::of((string) $amount, (string) $row->currency)->toPresentation(),
                $scheme->label(),
                $ceiling,
            ),
        ]];
    }

    /**
     * @return list<array{beneficiary: string|null, field: string, blocks: bool, why: string}>
     */
    private function remittance(object $row, string $who, PaymentScheme $scheme): array
    {
        $limit = $scheme->limit('remittance');
        $reference = $this->remittanceFor($row);

        if ($limit === null || $reference === '') {
            return [];
        }

        $converted = RestrictedLatin::convert($reference, $limit);

        if (! $converted['lossy']) {
            return [];
        }

        /*
         | The remittance is what tells the supplier what was paid.
         |
         | Truncating it does not stop the payment - it stops the supplier
         | matching it, and the call comes a fortnight later. So it is
         | reported and does not block: paying late is worse than paying
         | with a shortened reference.
         */
        return [[
            'beneficiary' => $who,
            'field' => 'remittance',
            'blocks' => false,
            'why' => sprintf(
                'the reference for %s is longer than the %d characters %s carries, so it goes as "%s" - '.
                'which is what they will have to match the payment by',
                $who,
                $limit,
                $scheme->label(),
                $converted['sent'],
            ),
        ]];
    }

    /** The invoice numbers this instruction settles. */
    public function remittanceFor(object $row): string
    {
        $numbers = DB::connection('tenant')->table('payment_run_items as pri')
            ->join('bills as b', 'b.id', '=', 'pri.bill_id')
            ->where('pri.instruction_id', (string) $row->id)
            ->orderBy('b.supplier_document_number')
            ->pluck('b.supplier_document_number')
            ->all();

        return implode(' ', array_map(static fn (mixed $n): string => (string) $n, $numbers));
    }

    /** @return list<object> */
    private function instructions(string $runId): array
    {
        return DB::connection('tenant')->select(<<<'SQL'
            SELECT i.id,
                   i.party_id,
                   i.party_bank_account_id AS account_id,
                   i.amount::text AS amount,
                   i.currency,
                   i.sequence,
                   i.of,
                   coalesce(p.trading_name, p.legal_name) AS party_name
            FROM payment_run_instructions i
            LEFT JOIN parties p ON p.id = i.party_id
            WHERE i.run_id = ?
            ORDER BY i.id
        SQL, [$runId]);
    }

    /**
     * Whether a date falls on a day the scheme's banks are closed.
     *
     * Declared by the scheme, because the answer differs: Saturday and
     * Sunday in the euro area, Friday and Saturday where the working week
     * starts on Sunday. The first draft inferred it from the scheme's
     * country list, which worked for the two schemes this release ships
     * and would have been wrong for the third.
     */
    private function isWeekend(Carbon $date, PaymentScheme $scheme): bool
    {
        return \in_array(mb_strtolower($date->format('l')), $scheme->closedDays(), true);
    }
}
