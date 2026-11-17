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
 * The collection file - PAIN.008 - PF-177.
 *
 * ## One block per sequence type, which is the structural rule here
 *
 * PAIN.001 groups by debtor account and date. PAIN.008 groups by those
 * **and by sequence type**, because `FRST` and `RCUR` collections have
 * different presentation deadlines and the debtor's bank processes them
 * differently.
 *
 * A file that put both in one block is rejected at the scheme level, and
 * that is the rejection this whole task exists to avoid - so the grouping
 * is derived from the collections themselves rather than assumed.
 *
 * ## The mandate travels with every transaction
 *
 * Its reference and signature date, in `MndtRltdInf`. The debtor's bank
 * matches on them: a collection whose reference they do not recognise is
 * returned, and one whose signature date disagrees with their
 * registration is returned under a different code. Both are facts we
 * hold, so neither should ever be the reason.
 */
final class CollectionSerializer
{
    public function __construct(private readonly BankDetailDisclosure $disclosure) {}

    /**
     * Render a collection file for a set of presented collections.
     *
     * @param  list<string>  $collectionIds
     *
     * @throws PaymentRefused
     */
    public function render(
        string $entityId,
        array $collectionIds,
        string $creditorName,
        string $creditorIban,
        ?string $creditorBic,
        string $creditorIdentifier,
        string $messageId,
        Carbon $createdAt,
    ): string {
        $rows = $this->collections($entityId, $collectionIds);

        if ($rows === []) {
            throw PaymentRefused::because('There are no presented collections to put in a file.');
        }

        $scheme = $this->schemeOf($rows);
        $total = Money::zero((string) $rows[0]->currency);

        foreach ($rows as $row) {
            $total = $total->plus(Money::of((string) $row->amount, (string) $row->currency));
        }

        $root = E::of('Document');
        $initiation = E::of('CstmrDrctDbtInitn')->with(
            E::of('GrpHdr')->with(
                E::text('MsgId', $messageId),
                E::text('CreDtTm', $createdAt->utc()->format('Y-m-d\TH:i:s\Z')),
                E::text('NbOfTxs', (string) \count($rows)),
                E::text('CtrlSum', $this->plain($total)),
                E::of('InitgPty')->with(
                    E::text('Nm', $this->clean($creditorName, $scheme, 'name')),
                ),
            ),
        );

        /*
         | Grouped by sequence type AND date.
         |
         | The rule that makes this file different from a credit transfer:
         | FRST and RCUR have different presentation deadlines, and a
         | block holding both is refused at the scheme level.
         */
        $blocks = [];

        foreach ($rows as $row) {
            $blocks[(string) $row->sequence_type.'|'.(string) $row->collection_date][] = $row;
        }

        ksort($blocks);
        $index = 0;

        foreach ($blocks as $key => $group) {
            $index++;
            [$sequence, $date] = explode('|', $key);

            $blockTotal = Money::zero((string) $group[0]->currency);

            foreach ($group as $row) {
                $blockTotal = $blockTotal->plus(Money::of((string) $row->amount, (string) $row->currency));
            }

            $block = E::of('PmtInf')->with(
                E::text('PmtInfId', $messageId.'-'.$index),
                E::text('PmtMtd', 'DD'),
                E::text('NbOfTxs', (string) \count($group)),
                E::text('CtrlSum', $this->plain($blockTotal)),
                E::of('PmtTpInf')->with(
                    E::of('SvcLvl')->with(E::text('Cd', 'SEPA')),
                    E::of('LclInstrm')->with(E::text('Cd', (string) $scheme['local_instrument'])),
                    E::text('SeqTp', $sequence),
                ),
                E::text('ReqdColltnDt', $date),
                E::of('Cdtr')->with(E::text('Nm', $this->clean($creditorName, $scheme, 'name'))),
                E::of('CdtrAcct')->with(
                    E::of('Id')->with(E::text('IBAN', $creditorIban)),
                ),
                E::of('CdtrAgt')->with(
                    E::of('FinInstnId')->with(
                        $creditorBic === null ? null : E::text('BICFI', $creditorBic),
                    ),
                ),
                E::text('ChrgBr', 'SLEV'),
                /*
                 | The creditor identifier, which is not the IBAN.
                 |
                 | A scheme-issued identifier that the debtor's bank uses
                 | to recognise who is collecting - and which the debtor
                 | sees on their statement. A file without it is refused,
                 | and a file with the wrong one collects under somebody
                 | else's name.
                 */
                E::of('CdtrSchmeId')->with(
                    E::of('Id')->with(
                        E::of('PrvtId')->with(
                            E::of('Othr')->with(
                                E::text('Id', $creditorIdentifier),
                                E::of('SchmeNm')->with(E::text('Prtry', 'SEPA')),
                            ),
                        ),
                    ),
                ),
            );

            foreach ($group as $row) {
                $block->with($this->transaction($row, $scheme));
            }

            $initiation->with($block);
        }

        $root->with($initiation);

        return (new XmlWriter(CollectionSequences::all()))->render($root, ['' => (string) $scheme['namespace']]);
    }

    /**
     | @param array<string, mixed> $scheme
     |
     | @throws PaymentRefused
     */
    private function transaction(object $row, array $scheme): E
    {
        /*
         | Through the service that records the read, like every other
         | file this product builds.
         |
         | A collection file names the debtor's account, so building one
         | is a read of it - and `forAnInstruction()` is the path that
         | asks for `payment.release` rather than `bank.manage`, for the
         | reason PF-176 records.
         */
        $account = $row->debtor_account_id === null
            ? null
            : PartyBankAccount::query()->find((string) $row->debtor_account_id);

        if ($account === null) {
            throw PaymentRefused::because(sprintf(
                'Mandate %s names no debtor account, and a collection has to say which account it '.
                'takes the money from.',
                (string) $row->mandate_reference,
            ));
        }

        $iban = $this->disclosure->forAnInstruction(
            $account,
            sprintf('building a collection file for mandate %s', (string) $row->mandate_reference),
        );

        return E::of('DrctDbtTxInf')->with(
            E::of('PmtId')->with(
                E::text('EndToEndId', mb_substr((string) $row->id, 0, 35)),
            ),
            E::text('InstdAmt', $this->plain(Money::of((string) $row->amount, (string) $row->currency)), [
                'Ccy' => (string) $row->currency,
            ]),
            E::of('DrctDbtTx')->with(
                E::of('MndtRltdInf')->with(
                    E::text('MndtId', (string) $row->mandate_reference),
                    E::text('DtOfSgntr', (string) $row->signed_on),
                ),
            ),
            E::of('DbtrAgt')->with(
                E::of('FinInstnId')->with(
                    $row->debtor_bic === null ? null : E::text('BICFI', (string) $row->debtor_bic),
                ),
            ),
            E::of('Dbtr')->with(
                E::text('Nm', $this->clean((string) $row->debtor_name, $scheme, 'name')),
            ),
            E::of('DbtrAcct')->with(
                E::of('Id')->with(E::text('IBAN', $iban)),
            ),
            $row->invoice_number === null
                ? null
                : E::of('RmtInf')->with(
                    E::text('Ustrd', $this->clean((string) $row->invoice_number, $scheme, 'remittance')),
                ),
        );
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    private function schemeOf(array $rows): array
    {
        $schemes = array_unique(array_map(static fn (object $r): string => (string) $r->scheme, $rows));

        if (\count($schemes) > 1) {
            /*
             | Core and B2B cannot share a file.
             |
             | B2B has no refund right and the mandate is registered with
             | the debtor's bank in advance, so a Core collection under a
             | B2B mandate is refused by a bank holding a registration
             | that says otherwise.
             */
            throw PaymentRefused::because(sprintf(
                'These collections span %s. One file carries one scheme: B2B mandates are registered '.
                'with the debtor\'s bank in advance and have no refund right, so a bank holding a B2B '.
                'registration refuses a Core collection against it.',
                implode(' and ', $schemes),
            ));
        }

        $definition = (array) config('settlement.collection_schemes.'.reset($schemes));

        if ($definition === []) {
            throw PaymentRefused::because('These collections name a scheme this release does not know.');
        }

        return $definition;
    }

    /**
     * @param  list<string>  $ids
     * @return list<object>
     */
    private function collections(string $entityId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::connection('tenant')->select(<<<'SQL'
            SELECT c.id,
                   c.amount::text AS amount,
                   c.currency,
                   c.collection_date,
                   c.sequence_type,
                   m.scheme,
                   m.reference AS mandate_reference,
                   m.signed_on,
                   m.debtor_name,
                   m.debtor_account_id,
                   a.bic AS debtor_bic,
                   i.number AS invoice_number
            FROM collections c
            JOIN mandates m ON m.id = c.mandate_id
            LEFT JOIN party_bank_accounts a ON a.id = m.debtor_account_id
            LEFT JOIN invoices i ON i.id = c.invoice_id
            WHERE c.entity_id = ?
              AND c.state = 'presented'
              AND c.id = ANY (?::char(26)[])
            /*
             | Ordered by id, like every other file this product builds.
             |
             | A hash over a document whose element order depends on how a
             | query returned rows is a hash that changes without the
             | content changing.
             */
            ORDER BY c.id
        SQL, [$entityId, '{'.implode(',', $ids).'}']);
    }

    /** @param array<string, mixed> $scheme */
    private function clean(string $value, array $scheme, string $field): string
    {
        $limit = $scheme['limits'][$field] ?? null;

        return RestrictedLatin::convert($value, $limit === null ? null : (int) $limit)['sent'];
    }

    private function plain(Money $amount): string
    {
        return (string) $amount->amount->toScale(2, \Brick\Math\RoundingMode::Unnecessary);
    }
}
