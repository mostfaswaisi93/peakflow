<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Text\RestrictedLatin;
use App\Tenant\Settlement\Payments\PainSequences;
use App\Tenant\Settlement\Payments\PaymentScheme;
use App\Tenant\Settlement\Services\PaymentFiles;
use App\Tenant\Settlement\Services\PaymentRuns;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The payment instruction file - PF-176.
 *
 * ## What a check here is for
 *
 * A payment file is rejected as a whole, hours later, by an email naming
 * an XPath into a document the person who built it cannot read. Nobody in
 * the run is paid, and the only way to find out what was wrong is to read
 * the schema.
 *
 * So every rule the scheme imposes is checked here against a real run and
 * a real file, and the thing being asserted is that the **refusal happens
 * before the file exists** and names the beneficiary. A check that only
 * proved the XML parses would prove nothing about the day this matters.
 */
final class PaymentFileRules
{
    use \Tests\Support\Concerns\OpensBankAccounts;
    use \Tests\Support\Concerns\PreparesPaymentRuns;

    private ?\App\Tenant\Identity\Models\User $releaser = null;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['the payment file (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        $before = auth()->guard('web')->user();

        try {
            $this->seedTheBankSide();
            $this->postTheOpeningEntry();

            /*
             | A signed-in treasury user, for the whole suite.
             |
             | Producing a file needs `payment.release`, because the IBAN
             | goes into an instruction - and the disclosure service
             | deliberately does NOT accept `bank.manage` for that, since
             | that permission is what lets somebody change a supplier's
             | bank details. So the suite signs in as somebody who may
             | send money, which is the ordinary case, and the one check
             | about permission signs in as somebody who may not.
             */
            $this->signInAs($this->aReleaser());

            return $this->runEachInASavepoint([
                // ---- The document ---------------------------------------
                'the file has a message, a block and its transactions' => fn (): array => $this->threeLevels(),
                'the control sums are what the bank will count' => fn (): array => $this->controlSums(),
                'every element the serializer emits has a declared position' => fn (): array => $this->sequencesAreComplete(),
                'the same run renders byte-identically' => fn (): array => $this->deterministic(),

                // ---- The rulebook, before the file ----------------------
                'an account with no declared scheme cannot produce a file' => fn (): array => $this->noScheme(),
                'a currency the scheme does not carry is refused' => fn (): array => $this->wrongCurrency(),
                'a beneficiary the scheme cannot reach is refused' => fn (): array => $this->unreachable(),
                'an amount above the scheme ceiling is refused' => fn (): array => $this->aboveTheCeiling(),
                'a pay date the bank is closed on is refused' => fn (): array => $this->closedDay(),
                'and each scheme has its own closed days' => fn (): array => $this->closedDaysDiffer(),
                'a pay date too far ahead is refused' => fn (): array => $this->tooFarAhead(),
                'every problem is reported, not just the first' => fn (): array => $this->allAtOnce(),

                // ---- What the counterparty will read --------------------
                'an Arabic name is transliterated and shown' => fn (): array => $this->arabicIsShown(),
                'the conventional Latin expansions are used' => fn (): array => $this->latinExpansions(),
                'a name with nothing usable in it is refused' => fn (): array => $this->nothingUsable(),
                'a reference too long is shortened and reported' => fn (): array => $this->remittanceTooLong(),

                // ---- Binding the file to what was authorised ------------
                'the file carries the hash the release authorised' => fn (): array => $this->boundToTheRelease(),
                'the database refuses a file for an unreleased run' => fn (): array => $this->onlyForAReleasedRun(),
                'the database refuses a file naming another hash' => fn (): array => $this->refusesAForeignHash(),

                // ---- Once, and regeneration -----------------------------
                'a run has one live file at a time' => fn (): array => $this->oneLiveFile(),
                'a file is handed over once' => fn (): array => $this->handedOverOnce(),
                'regenerating supersedes, with a reason, and keeps both' => fn (): array => $this->regeneration(),
                'a regenerated file has a new id and a new hash' => fn (): array => $this->regenerationIsDistinct(),
                'the bytes of a sent file cannot be changed' => fn (): array => $this->bytesAreEvidence(),
                'a payment file cannot be deleted' => fn (): array => $this->notDeletable(),

                // ---- Who may produce one --------------------------------
                'producing a file needs the duty that sends money' => fn (): array => $this->needsTheReleaseDuty(),
            ]);
        } finally {
            $this->signInAs($before);
            $this->connection->rollBack();
        }
    }

    // ---- The document -----------------------------------------------------

    /** @return list<string> */
    private function threeLevels(): array
    {
        $file = $this->aFileFor(['9000.0000', '4500.0000']);
        $xml = $this->parse($file['content']);
        $failures = [];

        if ($xml === null) {
            return ['the file is not parseable XML, so nothing below is checking a document'];
        }

        foreach ([
            '/p:Document/p:CstmrCdtTrfInitn/p:GrpHdr/p:MsgId' => 'the message has no id, so the bank cannot quote it back',
            '/p:Document/p:CstmrCdtTrfInitn/p:GrpHdr/p:NbOfTxs' => 'the message does not say how many transactions it holds',
            '/p:Document/p:CstmrCdtTrfInitn/p:PmtInf/p:PmtMtd' => 'the block does not say the payment method',
            '/p:Document/p:CstmrCdtTrfInitn/p:PmtInf/p:ReqdExctnDt/p:Dt' => 'the block carries no execution date, so the bank picks one',
            '/p:Document/p:CstmrCdtTrfInitn/p:PmtInf/p:DbtrAcct/p:Id/p:IBAN' => 'the block does not say which account pays',
        ] as $path => $why) {
            if ($xml->query($path)->length === 0) {
                $failures[] = $why.' ('.$path.')';
            }
        }

        $transactions = $xml->query('/p:Document/p:CstmrCdtTrfInitn/p:PmtInf/p:CdtTrfTxInf');

        if ($transactions->length !== 2) {
            $failures[] = sprintf(
                'a run of two instructions produced %d transaction(s) in the file, so the file does not '.
                'pay what the run says it pays',
                $transactions->length,
            );
        }

        /*
         | The grouping, which is the structural decision.
         |
         | One payment-information block, because a run pays from one
         | account on one date - and everything in a block shares those,
         | which is why the debtor and the date are on the block rather
         | than on each transaction.
         */
        $blocks = $xml->query('/p:Document/p:CstmrCdtTrfInitn/p:PmtInf');

        if ($blocks->length !== 1) {
            $failures[] = sprintf(
                'the file holds %d payment-information blocks for one account on one date, so the '.
                'grouping is per transaction rather than per debtor-and-date',
                $blocks->length,
            );
        }

        foreach ($xml->query('/p:Document/p:CstmrCdtTrfInitn/p:PmtInf/p:CdtTrfTxInf') as $transaction) {
            foreach (['p:PmtId/p:EndToEndId', 'p:Amt/p:InstdAmt', 'p:Cdtr/p:Nm', 'p:CdtrAcct/p:Id/p:IBAN'] as $needed) {
                if ($xml->query($needed, $transaction)->length === 0) {
                    $failures[] = 'a transaction is missing '.$needed.', which every scheme requires';
                }
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function controlSums(): array
    {
        $file = $this->aFileFor(['9000.0000', '4500.0000']);
        $xml = $this->parse($file['content']);

        if ($xml === null) {
            return ['the file is not parseable XML'];
        }

        $failures = [];
        $amounts = [];

        foreach ($xml->query('//p:CdtTrfTxInf/p:Amt/p:InstdAmt') as $node) {
            $amounts[] = $node->textContent;
        }

        $sum = \Brick\Math\BigDecimal::zero();

        foreach ($amounts as $amount) {
            $sum = $sum->plus($amount);
        }

        /*
         | The bank adds the transactions and compares.
         |
         | A control sum that disagrees by a penny is a rejected file, and
         | the usual cause is a total taken from somewhere other than the
         | lines - a stored column, a figure computed before a split. So
         | this adds the lines in the file itself.
         */
        foreach (['//p:GrpHdr/p:CtrlSum', '//p:PmtInf/p:CtrlSum'] as $path) {
            $stated = $xml->query($path)->item(0)?->textContent;

            if ($stated === null) {
                $failures[] = 'the file states no control sum at '.$path.', which some schemes require and all banks check';

                continue;
            }

            if (! $sum->isEqualTo($stated)) {
                $failures[] = sprintf(
                    'the control sum at %s says %s and the transactions in the file add to %s - a bank '.
                    'adds them and rejects the file',
                    $path,
                    $stated,
                    (string) $sum,
                );
            }
        }

        foreach (['//p:GrpHdr/p:NbOfTxs', '//p:PmtInf/p:NbOfTxs'] as $path) {
            $stated = $xml->query($path)->item(0)?->textContent;

            if ($stated !== (string) \count($amounts)) {
                $failures[] = sprintf(
                    'the count at %s says %s and the file holds %d transactions',
                    $path,
                    (string) $stated,
                    \count($amounts),
                );
            }
        }

        /*
         | And a plain decimal, not a presented figure.
         |
         | `toPresentation()` groups thousands and localises the
         | separator, which is right for a person and a rejected file in
         | an `InstdAmt`.
         */
        foreach ([...$amounts, (string) $xml->query('//p:GrpHdr/p:CtrlSum')->item(0)?->textContent] as $figure) {
            if (preg_match('/^\d+\.\d{2}$/', (string) $figure) !== 1) {
                $failures[] = sprintf(
                    '"%s" is not a plain two-place decimal, so it has been through a presentation step - '.
                    'a grouped or localised figure is a rejected file',
                    (string) $figure,
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function sequencesAreComplete(): array
    {
        $file = $this->aFileFor(['9000.0000', '4500.0000']);
        $xml = $this->parse($file['content']);

        if ($xml === null) {
            return ['the file is not parseable XML'];
        }

        $sequences = PainSequences::all();
        $failures = [];

        /*
         | Every element in the file is where its sequence says.
         |
         | The writer sorts children into the declared order, so this
         | asserts the TABLE is right rather than that the writer works:
         | a sequence with two entries transposed produces a file that
         | validates here and fails at the bank, which is the one failure
         | mode nothing else would catch.
         */
        foreach ($xml->query('//*') as $node) {
            $children = [];

            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $children[] = $child->localName;
                }
            }

            if ($children === []) {
                continue;
            }

            $declared = $sequences[$node->localName] ?? null;

            if ($declared === null) {
                $failures[] = sprintf(
                    'the file holds a <%s> with children and no declared sequence, so its order is '.
                    'whatever the serializer happened to emit',
                    (string) $node->localName,
                );

                continue;
            }

            $positions = [];

            foreach ($children as $child) {
                $at = array_search($child, $declared, true);

                if ($at === false) {
                    $failures[] = sprintf('<%s> is not a declared child of <%s>', $child, (string) $node->localName);

                    continue 2;
                }

                $positions[] = $at;
            }

            $sorted = $positions;
            sort($sorted);

            if ($positions !== $sorted) {
                $failures[] = sprintf(
                    'the children of <%s> are in the order %s, and the schema declares %s - a bank '.
                    'rejects this and names the element it did not expect rather than the one out of place',
                    (string) $node->localName,
                    implode(', ', $children),
                    implode(', ', $declared),
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function deterministic(): array
    {
        $run = $this->aReleasedRunFor(['9000.0000']);
        $serializer = app(\App\Tenant\Settlement\Payments\PainSerializer::class);
        $scheme = PaymentScheme::named('sa_local');
        $at = Carbon::parse('2026-05-19 09:00:00');

        $once = $serializer->render($this->runRow($run['id']), $scheme, 'FIXED-MESSAGE-ID', $at);
        $twice = $serializer->render($this->runRow($run['id']), $scheme, 'FIXED-MESSAGE-ID', $at);

        $failures = [];

        if ($once !== $twice) {
            $failures[] = 'the same run rendered twice gave two different files, so the hash it is '.
                'archived under identifies nothing and the file cannot be held against what was authorised';
        }

        if (str_contains($once, "\n  ") || str_contains($once, "\t")) {
            $failures[] = 'the file is pretty-printed, so adding one element reflows the whole document '.
                'and every byte of the hash changes';
        }

        return $failures;
    }

    // ---- The rulebook -----------------------------------------------------

    /** @return list<string> */
    private function noScheme(): array
    {
        $run = $this->aReleasedRunFor(['9000.0000'], scheme: null);

        $refusal = $this->refusal(fn () => app(PaymentFiles::class)->generate($run['id'], $this->actorId()));

        if ($refusal === null) {
            return [
                'a file was produced for an account that declares no payment scheme, so it was built to '.
                'whichever rulebook the code defaults to - and a file built to the wrong rulebook passes '.
                'every check here and is rejected by the bank',
            ];
        }

        $failures = [];

        if (! str_contains($refusal, 'declares no payment scheme')) {
            $failures[] = 'the refusal does not say the account declares no scheme: '.$refusal;
        }

        /*
         | And it names the schemes, so somebody can act.
         |
         | "No scheme declared" with no list sends the reader to the
         | source, which is where a configuration error becomes a support
         | ticket.
         */
        if (! str_contains($refusal, 'sepa_sct') || ! str_contains($refusal, 'sa_local')) {
            $failures[] = 'the refusal does not name the schemes that exist, so the reader has to go to '.
                'the source to find out what to set: '.$refusal;
        }

        return $failures;
    }

    /** @return list<string> */
    private function wrongCurrency(): array
    {
        /*
         | A SAR run against a scheme that carries EUR only.
         |
         | Not a file to correct - a different scheme. The refusal has to
         | say that, because somebody will otherwise look for a setting to
         | change.
         */
        $run = $this->aReleasedRunFor(['9000.0000'], scheme: 'sepa_sct');

        $check = app(PaymentFiles::class)->check($run['id']);
        $failures = [];

        if (! $check['blocks']) {
            return ['a SAR run was accepted against SEPA Credit Transfer, which carries EUR only - so the '.
                'bank rejects the file and nobody in the run is paid'];
        }

        $why = implode(' ', array_column($check['findings'], 'why'));

        if (! str_contains($why, 'different scheme')) {
            $failures[] = 'the refusal does not say this is a different scheme rather than a file to '.
                'correct, so somebody will look for a setting to change: '.$why;
        }

        $refusal = $this->refusal(fn () => app(PaymentFiles::class)->generate($run['id'], $this->actorId()));

        if ($refusal === null) {
            $failures[] = 'the check reported it and the file was produced anyway, so the check is a '.
                'screen rather than a control';
        }

        return $failures;
    }

    /** @return list<string> */
    private function unreachable(): array
    {
        $run = $this->aReleasedRunFor(['9000.0000']);

        /*
         | The beneficiary's account moved to a country the bank does not
         | reach.
         |
         | A real case: a supplier banking in a country outside the
         | scheme. The payment has to go another way, and saying so is
         | more useful than the bank's rejection code.
         */
        DB::connection('tenant')->table('party_bank_accounts')
            ->whereIn('id', $this->accountsOf($run['id']))
            ->update(['country' => 'US']);

        $check = app(PaymentFiles::class)->check($run['id']);
        $failures = [];

        if (! $check['blocks']) {
            return ['a beneficiary banking outside the scheme area was accepted, so the file goes to the '.
                'bank and the whole batch is returned'];
        }

        $why = implode(' ', array_column($check['findings'], 'why'));

        if (! str_contains($why, 'does not reach')) {
            $failures[] = 'the refusal does not say the bank cannot reach that country: '.$why;
        }

        if (! str_contains($why, 'another route')) {
            $failures[] = 'the refusal does not say what to do instead, so it reads as a dead end: '.$why;
        }

        return $failures;
    }

    /** @return list<string> */
    private function aboveTheCeiling(): array
    {
        /*
         | A transfer above what the scheme carries per transaction.
         |
         | Checked against SEPA, which has a ceiling. The local scheme
         | declares none, and a check that only ran against that one would
         | pass with the whole comparison removed.
         */
        $run = $this->aPreparedRunFor(['9000.0000'], scheme: 'sepa_sct');

        /*
         | Mutated while prepared, then released.
         |
         | A released run is frozen - the first draft edited one after
         | release and was refused, which is the guard working. The
         | fixture needs a euro run of a billion, and the honest way to
         | get one is to build it before the freeze.
         */
        DB::connection('tenant')->table('payment_runs')->where('id', $run['id'])->update(['currency' => 'EUR']);
        DB::connection('tenant')->table('payment_run_instructions')
            ->where('run_id', $run['id'])
            ->update(['currency' => 'EUR', 'amount' => '1000000000.00']);

        app(PaymentRuns::class)->release($run['id'], $this->secondActorId());

        $check = app(PaymentFiles::class)->check($run['id']);
        $failures = [];

        $why = implode(' ', array_column($check['findings'], 'why'));

        if (! str_contains($why, 'per transaction')) {
            $failures[] = 'a transfer of a billion was accepted against a scheme that carries at most '.
                '999,999,999.99, so the bank returns the whole file: '.$why;
        }

        if (! $check['blocks']) {
            $failures[] = 'the ceiling finding does not block, so the file is produced anyway';
        }

        return $failures;
    }

    /** @return list<string> */
    private function closedDay(): array
    {
        /*
         | 2026-05-23 is a Saturday, which both schemes are closed on.
         */
        $run = $this->aReleasedRunFor(['9000.0000'], payOn: '2026-05-23');

        $check = app(PaymentFiles::class)->check($run['id']);
        $failures = [];

        if (! $check['blocks']) {
            return ['a run asking to pay on a Saturday was accepted, and the bank does not move money on '.
                'a day it is closed'];
        }

        $why = implode(' ', array_column($check['findings'], 'why'));

        if (! str_contains($why, 'Saturday')) {
            $failures[] = 'the refusal does not name the day, so it reads as an arbitrary rule rather than '.
                'as a calendar: '.$why;
        }

        if (! str_contains($why, 'prepared again')) {
            $failures[] = 'the refusal does not say the run has to be prepared again - the date is part of '.
                'what was approved, and a releaser who edits it is releasing something else: '.$why;
        }

        return $failures;
    }

    /** @return list<string> */
    private function closedDaysDiffer(): array
    {
        $failures = [];

        /*
         | Friday is a working day in the euro area and not in Riyadh.
         |
         | The first draft inferred this from the scheme's country list,
         | which worked for the two schemes shipped and would have been
         | wrong for the third. It is declared now, and this is what
         | proves the declaration is read.
         */
        $sepa = PaymentScheme::named('sepa_sct');
        $local = PaymentScheme::named('sa_local');

        if (\in_array('friday', $sepa->closedDays(), true)) {
            $failures[] = 'SEPA is recorded as closed on Friday, which would refuse a perfectly ordinary '.
                'euro-area execution date';
        }

        if (! \in_array('friday', $local->closedDays(), true)) {
            $failures[] = 'the Saudi scheme is not recorded as closed on Friday, so a file dated on one '.
                'goes out and is held until Sunday with nobody told';
        }

        if (! \in_array('sunday', $sepa->closedDays(), true)) {
            $failures[] = 'SEPA is not recorded as closed on Sunday';
        }

        if (\in_array('sunday', $local->closedDays(), true)) {
            $failures[] = 'the Saudi scheme is recorded as closed on Sunday, which is the first working day '.
                'there - every Sunday run would be refused';
        }

        /*
         | And the difference is visible end to end, not only in config.
         |
         | 2026-05-22 is a Friday: a working day for SEPA, closed for the
         | local scheme.
         */
        $run = $this->aReleasedRunFor(['9000.0000'], payOn: '2026-05-22');
        $why = implode(' ', array_column(app(PaymentFiles::class)->check($run['id'])['findings'], 'why'));

        if (! str_contains($why, 'Friday')) {
            $failures[] = 'a Friday pay date was accepted by the Saudi scheme, so the declared closed days '.
                'are not reaching the check: '.$why;
        }

        return $failures;
    }

    /** @return list<string> */
    private function tooFarAhead(): array
    {
        $run = $this->aReleasedRunFor(
            ['9000.0000'],
            payOn: Carbon::now()->addDays(100)->next(Carbon::MONDAY)->toDateString(),
        );

        $check = app(PaymentFiles::class)->check($run['id']);

        if (! $check['blocks']) {
            return ['a pay date a hundred days out was accepted, and a bank holding an instruction that '.
                'long is not a service it offers'];
        }

        $why = implode(' ', array_column($check['findings'], 'why'));

        return str_contains($why, 'accepts at most')
            ? []
            : ['the refusal does not say what the scheme does accept, so there is nothing to correct it to: '.$why];
    }

    /** @return list<string> */
    private function allAtOnce(): array
    {
        /*
         | Three problems, reported together.
         |
         | Throwing on the first means three rounds of fix-and-retry, each
         | of which is a person waiting on a file - and the third round is
         | where somebody starts editing the XML by hand.
         */
        $run = $this->aReleasedRunFor(['9000.0000', '4500.0000'], payOn: '2026-05-23');

        DB::connection('tenant')->table('party_bank_accounts')
            ->whereIn('id', $this->accountsOf($run['id']))
            ->update(['country' => 'US']);

        $check = app(PaymentFiles::class)->check($run['id']);
        $blocking = array_values(array_filter($check['findings'], static fn (array $f): bool => $f['blocks']));

        if (\count($blocking) < 3) {
            return [sprintf(
                'a run with a closed pay date and two unreachable beneficiaries reported %d blocking '.
                'problem(s) rather than three, so fixing them is three rounds of trial and error',
                \count($blocking),
            )];
        }

        $failures = [];

        foreach ($blocking as $finding) {
            if ($finding['field'] === 'account' && $finding['beneficiary'] === null) {
                $failures[] = 'a per-beneficiary problem is reported without naming the beneficiary, so '.
                    'the reader has to work out which of forty transfers it is about';
            }
        }

        return $failures;
    }

    // ---- What the counterparty will read ----------------------------------

    /** @return list<string> */
    private function arabicIsShown(): array
    {
        $run = $this->aReleasedRunFor(['9000.0000'], name: 'شركة الخليج للتجارة');

        $check = app(PaymentFiles::class)->check($run['id']);
        $failures = [];

        $name = $check['names'][0] ?? null;

        if ($name === null) {
            return ['the check reports nothing about what the beneficiary name will become, so an Arabic '.
                'name is sent transliterated with nobody told'];
        }

        if ($name['sent'] !== 'SHRKH ALKHLYJ LLTJARH') {
            $failures[] = sprintf(
                'the Arabic name transliterates to "%s" rather than its consonants - and a guessed vowel '.
                'is a different name, which the beneficiary\'s bank will not match',
                (string) $name['sent'],
            );
        }

        if ($name['lossy'] !== true) {
            $failures[] = 'the transliteration is not declared lossy, so nobody is told that short vowels '.
                'are missing and that the result may not match what the bank holds';
        }

        if (! str_contains((string) $name['why'], 'override')) {
            $failures[] = 'the explanation does not say the name can be overridden, which is the only '.
                'thing a person can do about it: '.(string) $name['why'];
        }

        /*
         | And the file carries what was shown, not something else.
         |
         | The one failure that would make all of this theatre: the screen
         | says one thing and the bytes say another.
         */
        $file = app(PaymentFiles::class)->generate($run['id'], $this->actorId());

        if (! str_contains($file['content'], (string) $name['sent'])) {
            $failures[] = 'the file does not hold the name the check said would be sent, so what was '.
                'shown and what goes out are two different things';
        }

        if (str_contains($file['content'], 'شركة')) {
            $failures[] = 'the file carries Arabic script, which the scheme\'s character set forbids - the '.
                'bank rejects the whole file';
        }

        return $failures;
    }

    /** @return list<string> */
    private function latinExpansions(): array
    {
        $failures = [];

        /*
         | The expansions a counterparty reads as their own name.
         |
         | A German supplier reads `UE` as their name and `U` as a typo, so
         | `Müller` is `Mueller` and not `Muller`. Checked at the unit
         | rather than through a file, because the table is the thing being
         | asserted.
         */
        foreach ([
            ['Müller & Søn GmbH', 'Mueller AND Soen GmbH'],
            ['Åkesson Ø/S', 'AAkesson OE/S'],
            ['Société Générale', 'Societe Generale'],
            ['Straße 1', 'Strasse 1'],
            ['Acme Trading Co.', 'Acme Trading Co.'],
        ] as [$given, $expected]) {
            $got = RestrictedLatin::convert($given, 70)['sent'];

            if ($got !== $expected) {
                $failures[] = sprintf('"%s" becomes "%s" rather than "%s"', $given, $got, $expected);
            }
        }

        /*
         | And the ampersand, which is the one that matters.
         |
         | Forbidden, extremely common in trade names, and dropping it
         | turns "Smith & Sons" into "Smith  Sons".
         */
        $amp = RestrictedLatin::convert('Smith & Sons', 70);

        if ($amp['sent'] !== 'Smith AND Sons') {
            $failures[] = sprintf('"Smith & Sons" becomes "%s" rather than "Smith AND Sons"', $amp['sent']);
        }

        if ($amp['lossy']) {
            $failures[] = 'expanding an ampersand is reported as lossy, so every trade name with one '.
                'raises an advisory nobody can act on - and advisories nobody can act on get ignored';
        }

        return $failures;
    }

    /** @return list<string> */
    private function nothingUsable(): array
    {
        $run = $this->aReleasedRunFor(['9000.0000'], name: '٭٭٭');

        $check = app(PaymentFiles::class)->check($run['id']);
        $failures = [];

        if (! $check['blocks']) {
            return ['a beneficiary whose name has nothing the scheme permits was accepted, so the file '.
                'names them as an empty string and the bank rejects it'];
        }

        $why = implode(' ', array_column($check['findings'], 'why'));

        if (! str_contains($why, 'Latin trading name')) {
            $failures[] = 'the refusal does not say what to record instead, so there is nothing to act on: '.$why;
        }

        return $failures;
    }

    /** @return list<string> */
    private function remittanceTooLong(): array
    {
        $run = $this->aReleasedRunFor(['9000.0000'], billsEach: 2);

        /*
         | A reference past the scheme's 70 characters.
         |
         | Built from a real document number rather than a 120-character
         | string: `supplier_document_number` is varchar(64), and the
         | first draft overflowed the column instead of the scheme's
         | limit - which was the fixture testing the wrong thing. One
         | 60-character number is already past 70 once the scheme's own
         | shortening is applied to it with anything else.
         |
         | Reported and NOT blocking: truncating it does not stop the
         | payment, it stops the supplier matching it - and paying late is
         | worse than paying with a shortened reference.
         */
        DB::connection('tenant')->table('bills')
            ->whereIn('id', DB::connection('tenant')->table('payment_run_items')->where('run_id', $run['id'])->pluck('bill_id'))
            ->update(['supplier_document_number' => str_repeat('INV-1234567890-', 4).'END']);

        $check = app(PaymentFiles::class)->check($run['id']);
        $failures = [];

        $remittance = array_values(array_filter(
            $check['findings'],
            static fn (array $f): bool => $f['field'] === 'remittance',
        ));

        if ($remittance === []) {
            return ['a reference longer than the scheme carries is sent shortened with nobody told, and '.
                'the supplier cannot match the payment'];
        }

        if ($remittance[0]['blocks']) {
            $failures[] = 'a long reference blocks the file, so a payment is held for something that only '.
                'costs the supplier a phone call - paying late is worse';
        }

        if (! str_contains((string) $remittance[0]['why'], 'match the payment by')) {
            $failures[] = 'the advisory does not say what the supplier will see, which is the only reason '.
                'to report it: '.(string) $remittance[0]['why'];
        }

        $file = app(PaymentFiles::class)->generate($run['id'], $this->actorId());
        $xml = $this->parse($file['content']);
        $sent = $xml?->query('//p:RmtInf/p:Ustrd')->item(0)?->textContent ?? '';

        if (mb_strlen($sent) > 70) {
            $failures[] = sprintf(
                'the file carries a %d-character reference and the scheme allows 70, so the bank rejects it',
                mb_strlen($sent),
            );
        }

        return $failures;
    }

    // ---- Binding the file to what was authorised --------------------------

    /** @return list<string> */
    private function boundToTheRelease(): array
    {
        $run = $this->aReleasedRunFor(['9000.0000']);
        $file = app(PaymentFiles::class)->generate($run['id'], $this->actorId());

        $authorised = (string) DB::connection('tenant')->table('payment_runs')
            ->where('id', $run['id'])->value('released_hash');

        $failures = [];

        if ($file['authorised_hash'] !== $authorised) {
            $failures[] = 'the file does not carry the hash the release authorised, so "is this the file '.
                'for what was approved" is a reconstruction rather than a comparison';
        }

        if ($file['sha256'] !== hash('sha256', (string) $file['content'])) {
            $failures[] = 'the stored hash is not the hash of the bytes, so it identifies something other '.
                'than the file';
        }

        if ($file['sha256'] === $file['authorised_hash']) {
            $failures[] = 'the file hash and the authorised hash are the same value, so one of them is '.
                'being copied from the other and the comparison proves nothing';
        }

        $stored = (string) DB::connection('tenant')->table('payment_files')
            ->where('id', $file['id'])->value('sha256');

        if ($stored !== $file['sha256']) {
            $failures[] = 'the hash returned to the caller is not the hash on the row';
        }

        return $failures;
    }

    /** @return list<string> */
    private function onlyForAReleasedRun(): array
    {
        $run = $this->aPreparedRunFor(['9000.0000']);
        $failures = [];

        $refusal = $this->refusal(fn () => app(PaymentFiles::class)->generate($run['id'], $this->actorId()));

        if ($refusal === null) {
            $failures[] = 'a file was produced for a run nobody released, which is an instruction for '.
                'money nobody authorised';
        } elseif (! str_contains($refusal, 'unreleased run')) {
            $failures[] = 'the refusal does not say the run is unreleased: '.$refusal;
        }

        /*
         | And the database, which the service is one door to.
         |
         | An import, a queued job or a repaired row is another, and a
         | file for an unreleased run is the one row that must not exist.
         */
        $direct = $this->refusal(fn () => DB::connection('tenant')->table('payment_files')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'entity_id' => $this->entityId,
            'run_id' => $run['id'],
            'scheme' => 'sa_local',
            'format' => 'pain.001.001.09',
            'message_id' => 'DIRECT-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(6)),
            'created_at_message' => now(),
            'content' => '<Document/>',
            'sha256' => str_repeat('a', 64),
            'byte_count' => 12,
            'authorised_hash' => str_repeat('b', 64),
            'instruction_count' => 1,
            'total' => '9000.0000',
            'currency' => 'SAR',
            'generated_by' => $this->actorId(),
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($direct === null) {
            $failures[] = 'the database accepted a file for an unreleased run, so a single INSERT produces '.
                'an instruction for money nobody authorised';
        } elseif (! str_contains($direct, 'not released')) {
            $failures[] = 'the database refused it without saying why: '.$direct;
        }

        return $failures;
    }

    /** @return list<string> */
    private function refusesAForeignHash(): array
    {
        $run = $this->aReleasedRunFor(['9000.0000']);

        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('payment_files')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'entity_id' => $this->entityId,
            'run_id' => $run['id'],
            'scheme' => 'sa_local',
            'format' => 'pain.001.001.09',
            'message_id' => 'FOREIGN-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(6)),
            'created_at_message' => now(),
            'content' => '<Document/>',
            'sha256' => str_repeat('a', 64),
            'byte_count' => 12,
            /*
             | A hash that is not the run's.
             |
             | A file built from a stale read, a wrong id, or a race with a
             | regeneration. The comparison costs nothing at the moment of
             | the insert and is impossible to reconstruct afterwards.
             */
            'authorised_hash' => str_repeat('c', 64),
            'instruction_count' => 1,
            'total' => '9000.0000',
            'currency' => 'SAR',
            'generated_by' => $this->actorId(),
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($refusal === null) {
            return ['the database accepted a file claiming to carry a hash the run never authorised, so a '.
                'file built from something else reads as the approved one'];
        }

        return str_contains($refusal, 'nobody approved')
            ? []
            : ['the foreign hash was refused without explaining what it means: '.$refusal];
    }

    // ---- Once, and regeneration -------------------------------------------

    /** @return list<string> */
    private function oneLiveFile(): array
    {
        $run = $this->aReleasedRunFor(['9000.0000']);
        app(PaymentFiles::class)->generate($run['id'], $this->actorId());

        $failures = [];
        $refusal = $this->refusal(fn () => app(PaymentFiles::class)->generate($run['id'], $this->actorId()));

        if ($refusal === null) {
            $failures[] = 'a second file was produced for one run, so two instruments exist for one '.
                'payment and somebody can upload the second after the first was processed';
        } elseif (! str_contains($refusal, 'regenerate')) {
            $failures[] = 'the refusal does not point at regeneration, which is the way to do this '.
                'properly: '.$refusal;
        }

        /*
         | And the database, since the service is one door.
         |
         | A partial unique index rather than a service check, because two
         | live files is the one row combination that must not exist.
         */
        $direct = $this->refusal(fn () => DB::connection('tenant')->table('payment_files')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'entity_id' => $this->entityId,
            'run_id' => $run['id'],
            'scheme' => 'sa_local',
            'format' => 'pain.001.001.09',
            'message_id' => 'SECOND-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(6)),
            'created_at_message' => now(),
            'content' => '<Document/>',
            'sha256' => str_repeat('d', 64),
            'byte_count' => 12,
            'authorised_hash' => (string) DB::connection('tenant')->table('payment_runs')->where('id', $run['id'])->value('released_hash'),
            'instruction_count' => 1,
            'total' => '9000.0000',
            'currency' => 'SAR',
            'generated_by' => $this->actorId(),
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($direct === null) {
            $failures[] = 'the database accepted a second live file for one run, so an import or a retried '.
                'job puts two instruments into circulation';
        }

        return $failures;
    }

    /** @return list<string> */
    private function handedOverOnce(): array
    {
        $run = $this->aReleasedRunFor(['9000.0000']);
        $file = app(PaymentFiles::class)->generate($run['id'], $this->actorId());

        $first = app(PaymentFiles::class)->handOver($file['id'], $this->actorId());
        $failures = [];

        if (($first['content'] ?? '') === '') {
            $failures[] = 'handing the file over returned no content, so there is nothing to give the bank';
        }

        $refusal = $this->refusal(fn () => app(PaymentFiles::class)->handOver($file['id'], $this->secondActorId()));

        if ($refusal === null) {
            $failures[] = 'the file was handed over twice, so two copies are in circulation and the bank\'s '.
                'duplicate detection is the only thing between them and a double payment';
        } elseif (! str_contains($refusal, 'already been handed over')
            && ! str_contains($refusal, 'already handed over')
            && ! str_contains($refusal, 'was handed over on')) {
            $failures[] = 'the refusal does not say it has already gone: '.$refusal;
        }

        /*
         | And clearing the column is refused too.
         |
         | Nulling `downloaded_at` is the obvious shortcut for anybody who
         | wants a second copy, and the database is where it has to be
         | stopped.
         */
        $cleared = $this->refusal(fn () => DB::connection('tenant')->table('payment_files')
            ->where('id', $file['id'])
            ->update(['downloaded_at' => null, 'downloaded_by' => null]));

        if ($cleared === null) {
            $failures[] = 'the download record can be cleared, so the once-only rule is a suggestion';
        }

        /*
         | And the audit trail records the hand-over.
         |
         | "Who gave the bank this file, and when" is the first question
         | after a duplicate payment, and the row alone does not say who.
         */
        $audited = DB::connection('tenant')->table('audit_log')
            ->where('action', 'payment_file.handed_over')
            ->where('subject_id', $file['id'])
            ->exists();

        if (! $audited) {
            $failures[] = 'handing the file over wrote no audit entry, so "who gave the bank this file" '.
                'has no answer';
        }

        return $failures;
    }

    /** @return list<string> */
    private function regeneration(): array
    {
        $run = $this->aReleasedRunFor(['9000.0000']);
        $first = app(PaymentFiles::class)->generate($run['id'], $this->actorId());

        $failures = [];

        $reasonless = $this->refusal(fn () => app(PaymentFiles::class)->regenerate($run['id'], $this->actorId(), 'oops'));

        if ($reasonless === null) {
            $failures[] = 'a file was regenerated with no reason, so an investigation finds two files and '.
                'nothing saying why there were two';
        }

        $second = app(PaymentFiles::class)->regenerate(
            $run['id'],
            $this->actorId(),
            'the bank rejected the first file for a reference the beneficiary could not match',
        );

        $history = app(PaymentFiles::class)->history($run['id']);

        if (\count($history) !== 2) {
            $failures[] = sprintf(
                'after regenerating, the run has %d file(s) on record rather than two - "which file did '.
                'we actually send" is the question an investigation starts with, and a deleted row '.
                'answers it with silence',
                \count($history),
            );
        }

        $superseded = DB::connection('tenant')->table('payment_files')->where('id', $first['id'])->first();

        if ($superseded === null) {
            return [...$failures, 'the superseded file is gone from the table'];
        }

        if ((string) ($superseded->superseded_by_id ?? '') !== $second['id']) {
            $failures[] = 'the first file does not point at the one that replaced it';
        }

        if (! str_contains((string) ($superseded->superseded_reason ?? ''), 'bank rejected')) {
            $failures[] = 'the reason for regenerating is not stored on the file it replaced';
        }

        /*
         | And the superseded one cannot be handed over.
         |
         | It is kept as a record, not as an instrument - and a screen
         | listing both would otherwise offer a download of the wrong one.
         */
        $stale = $this->refusal(fn () => app(PaymentFiles::class)->handOver($first['id'], $this->actorId()));

        if ($stale === null) {
            $failures[] = 'a superseded file can still be handed over, so the file that was replaced can '.
                'be the one that goes to the bank';
        }

        if (app(PaymentFiles::class)->current($run['id'])['id'] !== $second['id']) {
            $failures[] = 'the run\'s current file is not the one that replaced the other';
        }

        return $failures;
    }

    /** @return list<string> */
    private function regenerationIsDistinct(): array
    {
        $run = $this->aReleasedRunFor(['9000.0000']);
        $first = app(PaymentFiles::class)->generate($run['id'], $this->actorId());

        $second = app(PaymentFiles::class)->regenerate(
            $run['id'],
            $this->actorId(),
            'the first file was built before the beneficiary name was corrected',
        );

        $failures = [];

        /*
         | A new message id, because a bank rejects a duplicate.
         |
         | And the usual cause is exactly this: a regeneration reusing the
         | id of the file that was already accepted.
         */
        if ($second['message_id'] === $first['message_id']) {
            $failures[] = 'the regenerated file reuses the message id, and a bank that has already seen '.
                'that id rejects the whole file as a duplicate';
        }

        if ($second['sha256'] === $first['sha256']) {
            $failures[] = 'the regenerated file hashes the same as the one it replaced, so the two cannot '.
                'be told apart by the one thing that identifies them';
        }

        if ($second['authorised_hash'] !== $first['authorised_hash']) {
            $failures[] = 'the regenerated file carries a different authorised hash, although the run is '.
                'frozen - so one of the two was not built from what was approved';
        }

        $audited = DB::connection('tenant')->table('audit_log')
            ->where('action', 'payment_file.regenerated')
            ->where('subject_id', $second['id'])
            ->exists();

        if (! $audited) {
            $failures[] = 'regenerating wrote no audit entry under its own action, so a second file looks '.
                'like a first one in the log';
        }

        return $failures;
    }

    /** @return list<string> */
    private function bytesAreEvidence(): array
    {
        $run = $this->aReleasedRunFor(['9000.0000']);
        $file = app(PaymentFiles::class)->generate($run['id'], $this->actorId());
        $failures = [];

        foreach ([
            ['content' => '<Document>changed</Document>'],
            ['sha256' => str_repeat('e', 64)],
            ['total' => '1.0000'],
            ['instruction_count' => 99],
            ['run_id' => str_repeat('0', 26)],
        ] as $change) {
            $refusal = $this->refusal(fn () => DB::connection('tenant')->table('payment_files')
                ->where('id', $file['id'])
                ->update($change));

            if ($refusal === null) {
                $failures[] = sprintf(
                    '%s can be changed after the file was produced, so the record of what went to the '.
                    'bank can be made to say something else',
                    implode(' and ', array_keys($change)),
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function notDeletable(): array
    {
        $run = $this->aReleasedRunFor(['9000.0000']);
        $file = app(PaymentFiles::class)->generate($run['id'], $this->actorId());

        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('payment_files')
            ->where('id', $file['id'])
            ->delete());

        if ($refusal === null) {
            return ['a payment file can be deleted, and "which file did we actually send" is the question '.
                'an investigation starts with'];
        }

        return str_contains($refusal, 'answers it with silence')
            ? []
            : ['the delete was refused without explaining why a file is kept: '.$refusal];
    }

    /** @return list<string> */
    private function needsTheReleaseDuty(): array
    {
        $run = $this->aReleasedRunFor(['9000.0000']);
        $before = auth()->guard('web')->user();

        try {
            /*
             | An accounts-payable clerk, who may enter and match bills
             | and may not send money.
             |
             | The ordinary case of somebody who should not be able to
             | produce an instrument - and a real shipped role rather than
             | an invented one, so the check fails if the role's
             | permissions ever grow to include release.
             */
            $clerk = $this->aUser('ap_clerk');
            $this->signInAs($clerk);

            $refusal = $this->refusal(fn () => app(PaymentFiles::class)->generate($run['id'], (string) $clerk->getKey()));

            if ($refusal === null) {
                return ['somebody with no release permission produced a payment file, so the instrument '.
                    'can be made by anybody who can see the run'];
            }

            return str_contains($refusal, 'payment.release')
                ? []
                : ['the refusal does not name the permission that is needed - and naming `bank.manage` '.
                    'instead would send somebody to acquire the permission that lets them change a '.
                    'supplier\'s bank details: '.$refusal];
        } finally {
            $this->signInAs($before);
        }
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * A released run on an account declaring a scheme.
     *
     * @param  list<string>  $amounts
     * @return array{id: string, number: string}
     */
    private function aReleasedRunFor(
        array $amounts,
        ?string $scheme = 'sa_local',
        string $payOn = '2026-05-19',
        ?string $name = null,
        int $billsEach = 1,
    ): array {
        $run = $this->aPreparedRunFor($amounts, $scheme, $payOn, $name, $billsEach);

        app(PaymentRuns::class)->release($run['id'], $this->secondActorId());

        return $run;
    }

    /**
     * @param  list<string>  $amounts
     * @return array{id: string, number: string}
     */
    private function aPreparedRunFor(
        array $amounts,
        ?string $scheme = 'sa_local',
        string $payOn = '2026-05-19',
        ?string $name = null,
        int $billsEach = 1,
    ): array {
        DB::connection('tenant')->table('bank_accounts')
            ->where('id', (string) $this->bank()->getKey())
            ->update(['payment_scheme' => $scheme]);

        /*
         | The suppliers are entered by somebody else.
         |
         | `beneficiary_then_release` refuses a release to a supplier
         | whose bank details the releaser entered - and it is
         | unoverridable, so a fixture that created both as one person
         | tripped it on every check. Which is the control working: the
         | person who maintains supplier bank details is not the person
         | who sends the money, and the fixture has to be two people
         | because the product requires two.
         */
        $releaser = auth()->guard('web')->user();
        $this->signInAs($this->aUser('controller'));

        foreach ($amounts as $amount) {
            $supplier = $this->aPayableSupplier();

            if ($name !== null) {
                /*
                 | The name written at the table, not through a service.
                 |
                 | `Party::create()` would be refused for a name the
                 | validation does not like, and what is being checked
                 | here is what the FILE does with a name the system
                 | already holds - which includes names imported before a
                 | rule existed.
                 */
                DB::connection('tenant')->table('parties')
                    ->where('id', (string) $supplier->getKey())
                    ->update(['legal_name' => $name, 'trading_name' => null]);
            }

            /*
             | Several bills to one supplier group into one instruction,
             | whose remittance is all their numbers joined - which is how
             | a reference comes to exceed what a scheme carries.
             */
            for ($i = 0; $i < $billsEach; $i++) {
                $this->anApprovedBill($supplier, $amount, $payOn);
            }
        }

        /*
         | And a third person prepares it.
         |
         | `prepare_then_release` is the other unoverridable pair, so the
         | releaser cannot be the preparer either. Three people, because
         | the product requires three: somebody maintains the supplier's
         | bank details, somebody selects what to pay, and somebody else
         | sends it.
         |
         | A fixture that collapsed any two of them would be a fixture
         | standing on a state the product refuses - and the first two
         | drafts of this one did exactly that, which is the controls
         | doing their job on the checks that were written to exercise
         | them.
         */
        $this->signInAs($this->aUser('controller'));

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse($payOn),
            userId: $this->actorId(),
        );

        $this->signInAs($releaser instanceof \App\Tenant\Identity\Models\User ? $releaser : null);
        $this->provePresenceFor((string) $run['id']);

        return ['id' => (string) $run['id'], 'number' => (string) $run['number']];
    }

    /**
     * A file for a released run, in one call.
     *
     * @param  list<string>  $amounts
     * @return array<string, mixed>
     */
    private function aFileFor(array $amounts): array
    {
        $run = $this->aReleasedRunFor($amounts);

        return app(PaymentFiles::class)->generate($run['id'], $this->actorId());
    }

    private function runRow(string $runId): object
    {
        $run = DB::connection('tenant')->table('payment_runs')->where('id', $runId)->first();
        \assert($run !== null);

        return $run;
    }

    /** @return list<string> */
    private function accountsOf(string $runId): array
    {
        return array_map(
            static fn (mixed $id): string => (string) $id,
            DB::connection('tenant')->table('payment_run_instructions')
                ->where('run_id', $runId)
                ->pluck('party_bank_account_id')
                ->all(),
        );
    }

    /**
     * An XPath reader over the file, with the default namespace bound.
     *
     * PAIN.001 uses a default namespace, and XPath has no concept of one -
     * an unprefixed name in a query matches only elements in no
     * namespace, so `//CdtTrfTxInf` finds nothing and a check written
     * that way passes by finding zero of everything. The prefix is bound
     * here once.
     */
    private function parse(string $xml): ?\DOMXPath
    {
        $document = new \DOMDocument();

        if (! $document->loadXML($xml)) {
            return null;
        }

        $path = new \DOMXPath($document);
        $path->registerNamespace('p', (string) $document->documentElement?->namespaceURI);

        return $path;
    }

    /**
     * The one releaser this suite acts as, with a confirmed factor.
     *
     * Created once, because enrolling a TOTP factor consumes a code per
     * time step and a new releaser per check would need more valid codes
     * than a drift window holds.
     */
    private function aReleaser(): \App\Tenant\Identity\Models\User
    {
        if ($this->releaser !== null) {
            return $this->releaser;
        }

        $user = $this->aUser('controller');

        /*
         | A real enrolment, verified with a real code.
         |
         | `payment.release` demands both a confirmed factor on the
         | account and a factor asserted in this session - so the suite
         | satisfies both rather than reaching past the guard.
         */
        $enrolment = app(\App\Tenant\Identity\Services\MfaService::class)
            ->beginTotpEnrolment($user, 'Payment file check authenticator');

        app(\App\Tenant\Identity\Services\MfaService::class)->confirmTotpEnrolment(
            $enrolment['authenticator'],
            \App\Support\Mfa\Totp::at((string) $enrolment['secret'], \App\Support\Mfa\Totp::stepAt(time()) * 30),
        );

        session(['factor_asserted_at' => now()->toIso8601String()]);

        return $this->releaser = $user->refresh();
    }

    /**
     * A step-up assertion for one run, written at the table.
     *
     * ## Why this is written and not asserted with a code
     *
     * A TOTP code is valid for one time step and is refused once used, so
     * a drift window holds about three. This suite releases two dozen
     * runs, and no amount of arithmetic produces two dozen valid codes
     * inside one window.
     *
     * The factor path itself is proved end to end elsewhere: PF-175's
     * `a stolen session is not enough to send money` enrols a real
     * authenticator, computes a real code, records a real assertion and
     * checks that one recorded for one run does not authorise another.
     * That is where step-up is the subject.
     *
     * Here the subject is the FILE, and the assertion is a precondition
     * of getting to it - so the row is written directly, scoped to this
     * run exactly as the guard would scope it. A fixture that disabled
     * the guard instead would be a fixture proving the file can be built
     * when the control is off.
     */
    private function provePresenceFor(string $runId): void
    {
        \App\Tenant\Identity\Models\StepUpAssertion::create([
            'user_id' => $this->aReleaser()->getKey(),
            'session_id' => session()->getId(),
            'authorized_for' => 'payment.release',
            'subject_id' => $runId,
            'factor_type' => 'totp',
            'asserted_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    /** Sign a user in, or sign out when there was nobody - `setUser(null)` is a TypeError. */
    private function signInAs(?\App\Tenant\Identity\Models\User $user): void
    {
        if ($user === null) {
            auth()->guard('web')->logout();

            return;
        }

        auth()->guard('web')->setUser($user);
    }

    private function aUser(string $role): \App\Tenant\Identity\Models\User
    {
        $user = \App\Tenant\Identity\Models\User::create([
            'name' => 'Check '.$role,
            'email' => $role.random_int(100000, 999999).'@check.test',
            'password' => 'x-not-a-real-password',
            'active' => true,
        ]);

        \App\Tenant\Identity\Models\UserEntityRole::create([
            'user_id' => $user->getKey(),
            'entity_id' => $this->entityId,
            'role_id' => \App\Tenant\Identity\Models\Role::query()->where('key', $role)->firstOrFail()->getKey(),
        ]);

        return $user;
    }
}
