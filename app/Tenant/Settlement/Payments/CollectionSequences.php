<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Payments;

/**
 * The order PAIN.008.001.08 declares for each of its complex types - PF-177.
 *
 * Same discipline as {@see PainSequences} and for the same reason: an
 * element in the wrong position fails schema validation at the bank, and
 * the rejection names the element it did not expect rather than the one
 * that is out of place.
 *
 * The shapes a collection file shares with a credit transfer - parties,
 * accounts, agents - are declared identically here rather than imported,
 * because the two schemas are free to diverge and a shared table would
 * make one of them wrong silently.
 *
 * @see ISO 20022 pain.008.001.08 CustomerDirectDebitInitiationV08
 */
final class CollectionSequences
{
    /** @return array<string, list<string>> */
    public static function all(): array
    {
        return [
            'Document' => ['CstmrDrctDbtInitn'],
            'CstmrDrctDbtInitn' => ['GrpHdr', 'PmtInf'],
            'GrpHdr' => ['MsgId', 'CreDtTm', 'NbOfTxs', 'CtrlSum', 'InitgPty'],

            'PmtInf' => [
                'PmtInfId',
                'PmtMtd',
                'BtchBookg',
                'NbOfTxs',
                'CtrlSum',
                'PmtTpInf',
                'ReqdColltnDt',
                'Cdtr',
                'CdtrAcct',
                'CdtrAgt',
                'ChrgBr',
                'CdtrSchmeId',
                'DrctDbtTxInf',
            ],

            /*
             | `SeqTp` sits inside `PmtTpInf`, after the local instrument.
             |
             | Which is why the grouping is per sequence type: the element
             | is on the BLOCK and not on the transaction, so a block
             | holding both FRST and RCUR cannot say so.
             */
            'PmtTpInf' => ['InstrPrty', 'SvcLvl', 'LclInstrm', 'SeqTp', 'CtgyPurp'],
            'SvcLvl' => ['Cd', 'Prtry'],
            'LclInstrm' => ['Cd', 'Prtry'],
            'CtgyPurp' => ['Cd', 'Prtry'],

            'DrctDbtTxInf' => [
                'PmtId',
                'PmtTpInf',
                'InstdAmt',
                'ChrgBr',
                'DrctDbtTx',
                'UltmtCdtr',
                'DbtrAgt',
                'Dbtr',
                'DbtrAcct',
                'UltmtDbtr',
                'Purp',
                'RmtInf',
            ],

            'PmtId' => ['InstrId', 'EndToEndId', 'UETR'],
            'DrctDbtTx' => ['MndtRltdInf', 'CdtrSchmeId', 'PreNtfctnId', 'PreNtfctnDt'],
            'MndtRltdInf' => ['MndtId', 'DtOfSgntr', 'AmdmntInd', 'AmdmntInfDtls'],

            'Cdtr' => ['Nm', 'PstlAdr', 'Id', 'CtryOfRes', 'CtctDtls'],
            'Dbtr' => ['Nm', 'PstlAdr', 'Id', 'CtryOfRes', 'CtctDtls'],
            'InitgPty' => ['Nm', 'PstlAdr', 'Id', 'CtryOfRes', 'CtctDtls'],
            'PstlAdr' => ['AdrTp', 'Dept', 'SubDept', 'StrtNm', 'BldgNb', 'PstCd', 'TwnNm', 'Ctry', 'AdrLine'],

            'CdtrAcct' => ['Id', 'Tp', 'Ccy', 'Nm', 'Prxy'],
            'DbtrAcct' => ['Id', 'Tp', 'Ccy', 'Nm', 'Prxy'],
            'Id' => ['IBAN', 'Othr', 'PrvtId', 'OrgId'],
            'Othr' => ['Id', 'SchmeNm', 'Issr'],
            'SchmeNm' => ['Cd', 'Prtry'],

            'CdtrSchmeId' => ['Nm', 'PstlAdr', 'Id', 'CtryOfRes', 'CtctDtls'],
            'PrvtId' => ['DtAndPlcOfBirth', 'Othr'],

            'CdtrAgt' => ['FinInstnId', 'BrnchId'],
            'DbtrAgt' => ['FinInstnId', 'BrnchId'],
            'FinInstnId' => ['BICFI', 'ClrSysMmbId', 'LEI', 'Nm', 'PstlAdr', 'Othr'],

            'RmtInf' => ['Ustrd', 'Strd'],
        ];
    }
}
