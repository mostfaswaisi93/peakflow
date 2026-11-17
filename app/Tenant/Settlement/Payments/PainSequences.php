<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Payments;

/**
 * The order PAIN.001.001.09 declares for each of its complex types - PF-176.
 *
 * ## Order is not a style question here either
 *
 * Every ISO 20022 complex type is an `xsd:sequence`. An element in the
 * wrong position fails schema validation at the bank, and the rejection
 * names the element it did not expect rather than the one that is out of
 * place - which is why `cvc-complex-type.2.4.a: Invalid content was found
 * starting with element 'ReqdExctnDt'` is a familiar and unhelpful sight.
 *
 * So the serializer builds children in whatever order reads best and
 * {@see \App\Support\Xml\XmlWriter} sorts them into these sequences. An
 * element with no declared position is an **error** rather than something
 * appended at the end: a name the sequence does not know is either a typo
 * or a term somebody added without checking where it goes, and both
 * produce a file the bank rejects.
 *
 * ## Only what this release emits
 *
 * The schema declares far more than these - a hundred optional elements
 * for cheques, cards, mandates and regulatory reporting. Declaring
 * positions for elements nothing emits would be a table nobody can check
 * against anything, so this holds exactly the terms the serializer uses
 * and the writer refuses the rest.
 *
 * @see ISO 20022 pain.001.001.09 CustomerCreditTransferInitiationV09
 */
final class PainSequences
{
    /** @return array<string, list<string>> */
    public static function all(): array
    {
        return [
            'Document' => ['CstmrCdtTrfInitn'],

            'CstmrCdtTrfInitn' => ['GrpHdr', 'PmtInf'],

            'GrpHdr' => ['MsgId', 'CreDtTm', 'NbOfTxs', 'CtrlSum', 'InitgPty'],

            /*
             | `PmtInf` is where the batch decision lives.
             |
             | One payment-information block per (debtor account, currency,
             | execution date, service level). Everything in a block shares
             | those, which is why the debtor and the date sit here and not
             | on each transaction - and why a run paying from two accounts
             | would be two blocks rather than one file with a per-line
             | debtor.
             */
            'PmtInf' => [
                'PmtInfId',
                'PmtMtd',
                'BtchBookg',
                'NbOfTxs',
                'CtrlSum',
                'PmtTpInf',
                'ReqdExctnDt',
                'Dbtr',
                'DbtrAcct',
                'DbtrAgt',
                'ChrgBr',
                'CdtTrfTxInf',
            ],

            'PmtTpInf' => ['InstrPrty', 'SvcLvl', 'LclInstrm', 'CtgyPurp'],
            'SvcLvl' => ['Cd', 'Prtry'],
            'LclInstrm' => ['Cd', 'Prtry'],
            'CtgyPurp' => ['Cd', 'Prtry'],

            'ReqdExctnDt' => ['Dt', 'DtTm'],

            'CdtTrfTxInf' => [
                'PmtId',
                'PmtTpInf',
                'Amt',
                'ChrgBr',
                'CdtrAgt',
                'Cdtr',
                'CdtrAcct',
                'Purp',
                'RmtInf',
            ],

            'PmtId' => ['InstrId', 'EndToEndId', 'UETR'],
            'Amt' => ['InstdAmt', 'EqvtAmt'],
            'Purp' => ['Cd', 'Prtry'],

            /*
             | Party, account and agent, which repeat for debtor and creditor.
             |
             | One declaration each, because the schema uses the same
             | complex types on both sides - and two copies would be two
             | places for an element to be in the wrong position.
             */
            'InitgPty' => ['Nm', 'PstlAdr', 'Id', 'CtryOfRes', 'CtctDtls'],
            'Dbtr' => ['Nm', 'PstlAdr', 'Id', 'CtryOfRes', 'CtctDtls'],
            'Cdtr' => ['Nm', 'PstlAdr', 'Id', 'CtryOfRes', 'CtctDtls'],

            'PstlAdr' => [
                'AdrTp', 'Dept', 'SubDept', 'StrtNm', 'BldgNb', 'BldgNm',
                'Flr', 'PstBx', 'Room', 'PstCd', 'TwnNm', 'TwnLctnNm',
                'DstrctNm', 'CtrySubDvsn', 'Ctry', 'AdrLine',
            ],

            'DbtrAcct' => ['Id', 'Tp', 'Ccy', 'Nm', 'Prxy'],
            'CdtrAcct' => ['Id', 'Tp', 'Ccy', 'Nm', 'Prxy'],
            'Id' => ['IBAN', 'Othr'],
            'Othr' => ['Id', 'SchmeNm', 'Issr'],
            'SchmeNm' => ['Cd', 'Prtry'],

            'DbtrAgt' => ['FinInstnId', 'BrnchId'],
            'CdtrAgt' => ['FinInstnId', 'BrnchId'],
            'FinInstnId' => ['BICFI', 'ClrSysMmbId', 'LEI', 'Nm', 'PstlAdr', 'Othr'],

            'RmtInf' => ['Ustrd', 'Strd'],
        ];
    }
}
