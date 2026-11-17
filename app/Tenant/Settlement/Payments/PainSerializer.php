<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Payments;

use App\Support\Money\Money;
use App\Support\Text\RestrictedLatin;
use App\Support\Xml\Element as E;
use App\Support\Xml\XmlWriter;
use App\Tenant\Parties\Models\PartyBankAccount;
use App\Tenant\Parties\Services\BankDetailDisclosure;
use App\Tenant\Settlement\Exceptions\PaymentRefused;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The payment instruction file - PF-176.
 *
 * ## Three levels, and the grouping decision between them
 *
 * - **Message** (`GrpHdr`): one per file. Who initiated it, how many
 *   transactions and what they total - which the bank checks against the
 *   transactions it counts, so a mismatch is a rejected file.
 * - **Payment information** (`PmtInf`): one per (debtor account,
 *   execution date, service level). Everything in a block shares those,
 *   which is why the debtor and the date sit on the block and not on each
 *   transaction.
 * - **Transaction** (`CdtTrfTxInf`): one per instruction in the run.
 *
 * A run pays from one account on one date, so this produces one block.
 * The structure is nonetheless built as a block rather than flattened,
 * because a run that paid from two accounts would be two blocks and
 * nothing else about the file would change - whereas a flattened
 * generator would have to be rewritten.
 *
 * ## `BtchBookg` is false, deliberately
 *
 * It asks the bank for one debit per transaction rather than one for the
 * batch. Both are legal and PF-156's pass 2 matches either, but a bank
 * statement with a line per transfer is one a person can reconcile
 * against the run's instructions by eye, and a single lump sum is not.
 *
 * ## Determinism
 *
 * The same run renders byte-identically. No timestamps generated at
 * render time, no ids from a sequence, no formatting that depends on
 * nesting depth: the message id and creation time are passed in, because
 * the file is hashed and archived and a hash that moves on every render
 * identifies nothing.
 */
final class PainSerializer
{
    public function __construct(private readonly BankDetailDisclosure $disclosure) {}

    /**
     * Render the file for a released run.
     *
     * @throws PaymentRefused
     */
    public function render(
        object $run,
        PaymentScheme $scheme,
        string $messageId,
        Carbon $createdAt,
    ): string {
        $instructions = $this->instructions((string) $run->id);

        if ($instructions === []) {
            throw PaymentRefused::because(
                'This run holds no instructions, so there is nothing for a file to instruct.',
            );
        }

        $currency = (string) $run->currency;
        $total = Money::zero($currency);

        foreach ($instructions as $instruction) {
            $total = $total->plus(Money::of((string) $instruction->amount, $currency));
        }

        $debtor = $this->debtorAccount($run, $scheme);

        $root = E::of('Document');

        $initiation = E::of('CstmrCdtTrfInitn')->with(
            E::of('GrpHdr')->with(
                E::text('MsgId', $messageId),
                /*
                 | Seconds, no sub-second part, no offset drift.
                 |
                 | Some banks' parsers reject fractional seconds, and the
                 | ones that accept them echo the value back differently -
                 | which breaks a comparison against the archived file for
                 | no reason anybody can act on.
                 */
                E::text('CreDtTm', $createdAt->utc()->format('Y-m-d\TH:i:s\Z')),
                E::text('NbOfTxs', (string) \count($instructions)),
                E::text('CtrlSum', $this->plain($total)),
                E::of('InitgPty')->with(
                    E::text('Nm', $this->clean((string) $debtor['holder'], $scheme, 'name')),
                ),
            ),
        );

        $block = E::of('PmtInf')->with(
            E::text('PmtInfId', $messageId.'-1'),
            E::text('PmtMtd', 'TRF'),
            E::text('BtchBookg', 'false'),
            E::text('NbOfTxs', (string) \count($instructions)),
            E::text('CtrlSum', $this->plain($total)),
            E::of('PmtTpInf')->with(
                E::of('SvcLvl')->with(E::text('Cd', $this->serviceLevel($scheme))),
            ),
            E::of('ReqdExctnDt')->with(E::text('Dt', (string) $run->pay_on)),
            E::of('Dbtr')->with(
                E::text('Nm', $this->clean((string) $debtor['holder'], $scheme, 'name')),
            ),
            E::of('DbtrAcct')->with(
                E::of('Id')->with(E::text('IBAN', (string) $debtor['iban'])),
                E::text('Ccy', $currency),
            ),
            E::of('DbtrAgt')->with(
                E::of('FinInstnId')->with(
                    $debtor['bic'] === null
                        ? E::text('Nm', $this->clean((string) $debtor['institution'], $scheme, 'name'))
                        : E::text('BICFI', (string) $debtor['bic']),
                ),
            ),
            /*
             | `SLEV`: charges as the service level says.
             |
             | SEPA's rulebook is shared charges, and saying so explicitly
             | rather than leaving it to the bank's default is what stops a
             | supplier being short-paid by a fee nobody agreed - which
             | arrives as a dispute about the invoice.
             */
            E::text('ChrgBr', 'SLEV'),
        );

        foreach ($instructions as $instruction) {
            $block->with($this->transaction($instruction, $scheme, $currency, $messageId));
        }

        $initiation->with($block);
        $root->with($initiation);

        return (new XmlWriter(PainSequences::all()))->render($root, ['' => $scheme->namespace()]);
    }

    /** @throws PaymentRefused */
    private function transaction(object $row, PaymentScheme $scheme, string $currency, string $messageId): E
    {
        $account = PartyBankAccount::query()->find((string) $row->account_id);

        if ($account === null) {
            throw PaymentRefused::because(sprintf(
                'The instruction for %s names no account that exists, so the file cannot say where the '.
                'money goes.',
                $row->party_name === null ? 'a beneficiary' : (string) $row->party_name,
            ));
        }

        $iban = $this->disclosure->forAnInstruction(
            $account,
            sprintf('building a %s payment file (%s)', $scheme->label(), $messageId),
        );

        $transaction = E::of('CdtTrfTxInf')->with(
            E::of('PmtId')->with(
                /*
                 | The end-to-end id the beneficiary's bank passes through.
                 |
                 | The instruction's own id, so a returned or queried
                 | payment names the row it came from. A generated id would
                 | make the bank's reply unmatched against anything.
                 */
                E::text('EndToEndId', mb_substr((string) $row->id, 0, (int) ($scheme->limit('end_to_end_id') ?? 35))),
            ),
            E::of('Amt')->with(
                E::text(
                    'InstdAmt',
                    $this->plain(Money::of((string) $row->amount, $currency)),
                    ['Ccy' => $currency],
                ),
            ),
            E::of('Cdtr')->with(
                E::text('Nm', $this->clean((string) ($row->party_name ?? ''), $scheme, 'name')),
            ),
            E::of('CdtrAcct')->with(
                E::of('Id')->with(E::text('IBAN', $iban)),
            ),
        );

        if ($account->bic !== null && trim((string) $account->bic) !== '') {
            $transaction->with(
                E::of('CdtrAgt')->with(
                    E::of('FinInstnId')->with(E::text('BICFI', (string) $account->bic)),
                ),
            );
        }

        $reference = $this->remittance((string) $row->id);

        if ($reference !== '') {
            $transaction->with(
                E::of('RmtInf')->with(
                    E::text('Ustrd', $this->clean($reference, $scheme, 'remittance')),
                ),
            );
        }

        return $transaction;
    }

    /**
     * The debtor side, read through the service that records the read.
     *
     * @return array{iban: string, bic: string|null, holder: string, institution: string}
     *
     * @throws PaymentRefused
     */
    private function debtorAccount(object $run, PaymentScheme $scheme): array
    {
        $account = DB::connection('tenant')->table('bank_accounts')
            ->where('id', (string) $run->bank_account_id)
            ->first();

        if ($account === null) {
            throw PaymentRefused::because('The account this run pays from no longer exists.');
        }

        /*
         | The entity's OWN account, so there is no disclosure service.
         |
         | `BankDetailDisclosure` guards a counterparty's details - the
         | fraud it exists for is somebody reading a supplier's account to
         | redirect a payment. Our own IBAN goes in every file we send and
         | on every invoice we issue; recording a read of it would be
         | noise that buries the reads that matter.
         */
        $iban = \App\Tenant\Banking\Models\BankAccount::query()
            ->findOrFail((string) $run->bank_account_id)
            ->iban();

        if ($iban === null) {
            throw PaymentRefused::because(sprintf(
                'The account this run pays from has no IBAN, and %s addresses the debtor by one. A '.
                'domestic-identifier debtor is a scheme variant this release does not build.',
                $scheme->label(),
            ));
        }

        return [
            'iban' => (string) $iban,
            'bic' => $account->bic === null || trim((string) $account->bic) === '' ? null : (string) $account->bic,
            'holder' => (string) $account->holder_legal_name,
            'institution' => (string) $account->institution_name,
        ];
    }

    /** The invoice numbers this instruction settles. */
    private function remittance(string $instructionId): string
    {
        $numbers = DB::connection('tenant')->table('payment_run_items as pri')
            ->join('bills as b', 'b.id', '=', 'pri.bill_id')
            ->where('pri.instruction_id', $instructionId)
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
                   coalesce(p.trading_name, p.legal_name) AS party_name
            FROM payment_run_instructions i
            LEFT JOIN parties p ON p.id = i.party_id
            WHERE i.run_id = ?
            /*
             | Ordered by id, which is total and stable.
             |
             | The same order PF-175's hash uses, for the same reason: a
             | file whose element order depends on how a query returned
             | rows is a file whose bytes change without its content
             | changing, and the hash it was archived under stops matching.
             */
            ORDER BY i.id
        SQL, [$runId]);
    }

    /** What the scheme's character set permits, at the scheme's length. */
    private function clean(string $value, PaymentScheme $scheme, string $field): string
    {
        return RestrictedLatin::convert($value, $scheme->limit($field))['sent'];
    }

    /**
     * An amount as the schema wants it: a plain decimal, no separators.
     *
     * Two places, because every scheme this release ships carries a
     * two-exponent currency - and `toPresentation()` is for people, so it
     * groups thousands and localises the separator. A grouped figure in an
     * `InstdAmt` is a rejected file.
     */
    private function plain(Money $amount): string
    {
        return (string) $amount->amount->toScale(2, \Brick\Math\RoundingMode::Unnecessary);
    }

    private function serviceLevel(PaymentScheme $scheme): string
    {
        return $scheme->key === 'sepa_sct' ? 'SEPA' : 'NURG';
    }
}
