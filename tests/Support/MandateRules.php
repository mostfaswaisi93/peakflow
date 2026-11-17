<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Settlement\Payments\CollectionSequences;
use App\Tenant\Settlement\Services\Mandates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Direct debit mandates and collections - PF-177.
 *
 * ## The sequence type is the whole of this
 *
 * SEPA distinguishes the first collection under a mandate from every one
 * after it, and getting it wrong is a scheme-level rejection rather than
 * a warning. So the thing being checked is that nobody can produce a
 * second `FRST` - not the service, not an import, not a retried job.
 *
 * ## And the controls are refusals here, not returns from the bank
 *
 * A collection against a cancelled mandate comes back days later, after
 * the customer has seen it on their statement and complained. Every
 * check below asserts the refusal happens at the moment somebody tries.
 */
final class MandateRules
{
    use \Tests\Support\Concerns\OpensBankAccounts;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['mandates (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        $before = auth()->guard('web')->user();

        try {
            $this->seedTheBankSide();

            /*
             | Signed in as somebody who may send money.
             |
             | A collection file names the debtor's account, so building
             | one is a read of it - and that goes through the disclosure
             | path that asks for `payment.release` rather than
             | `bank.manage`, for the reason PF-176 records.
             */
            auth()->guard('web')->setUser($this->aUser('controller'));

            return $this->runEachInASavepoint([
                // ---- The mandate ----------------------------------------
                'a mandate records its reference, date and channel' => fn (): array => $this->recorded(),
                'a reference is unique, because the bank matches on it' => fn (): array => $this->referenceIsUnique(),
                'a reference and a signature date cannot be changed' => fn (): array => $this->referenceIsFixed(),
                'a mandate signed in the future is refused' => fn (): array => $this->signedInTheFuture(),

                // ---- The sequence type ----------------------------------
                'the first collection is FRST and the next is RCUR' => fn (): array => $this->sequenceIsDerived(),
                'a returned first collection still counts as seen' => fn (): array => $this->returnedFirstStillCounts(),
                'the database refuses a second FRST' => fn (): array => $this->oneFirstPerMandate(),

                // ---- Pre-notification ----------------------------------
                'notice shorter than the scheme requires is refused' => fn (): array => $this->leadTime(),
                'a collection nobody was told about is refused' => fn (): array => $this->noNotification(),
                'collecting more than was notified is refused' => fn (): array => $this->moreThanNotified(),
                'collecting less than was notified is fine' => fn (): array => $this->lessThanNotified(),

                // ---- Cancellation and expiry ---------------------------
                'a cancelled mandate stops collections here' => fn (): array => $this->cancelledStopsCollections(),
                'and the database stops them too' => fn (): array => $this->databaseStopsCollections(),
                'an expired mandate is no authority at all' => fn (): array => $this->expiredStopsCollections(),
                'cancelling needs a reason somebody can read' => fn (): array => $this->cancellationNeedsAReason(),

                // ---- Returns -------------------------------------------
                'a return keeps the bank\'s reason code' => fn (): array => $this->returnKeepsTheCode(),
                'a return with no code is refused' => fn (): array => $this->returnNeedsACode(),
                'a disputed-mandate code cancels the mandate' => fn (): array => $this->disputeCancelsTheMandate(),
                'an insufficient balance does not cancel it' => fn (): array => $this->insufficientFundsDoesNot(),
                'the same collection is not returned twice' => fn (): array => $this->returnedOnce(),

                // ---- The file ------------------------------------------
                'the file groups by sequence type, not only by date' => fn (): array => $this->fileGroupsBySequence(),
                'the mandate travels with every transaction' => fn (): array => $this->mandateTravels(),
                'Core and B2B cannot share one file' => fn (): array => $this->schemesDoNotMix(),
                'every element the file emits has a declared position' => fn (): array => $this->sequencesAreComplete(),
            ]);
        } finally {
            if ($before === null) {
                auth()->guard('web')->logout();
            } else {
                auth()->guard('web')->setUser($before);
            }

            $this->connection->rollBack();
        }
    }

    // ---- The mandate ------------------------------------------------------

    /** @return list<string> */
    private function recorded(): array
    {
        $mandate = $this->aMandate();
        $shown = app(Mandates::class)->show($mandate['id']);
        $failures = [];

        foreach ([
            'reference' => $mandate['reference'],
            'scheme' => 'sepa_core',
            'state' => 'active',
            'next_sequence' => 'FRST',
        ] as $field => $expected) {
            if (($shown[$field] ?? null) !== $expected) {
                $failures[] = sprintf(
                    'the mandate reports %s as "%s" rather than "%s"',
                    $field,
                    (string) ($shown[$field] ?? 'nothing'),
                    (string) $expected,
                );
            }
        }

        if (($shown['signed_on'] ?? '') === '') {
            $failures[] = 'the mandate records no signature date, which is what the debtor\'s bank matches '.
                'against its own registration';
        }

        $audited = DB::connection('tenant')->table('audit_log')
            ->where('action', 'mandate.recorded')
            ->where('subject_id', $mandate['id'])
            ->exists();

        if (! $audited) {
            $failures[] = 'recording a mandate wrote no audit entry, so "when did this customer authorise '.
                'us" has no answer';
        }

        return $failures;
    }

    /** @return list<string> */
    private function referenceIsUnique(): array
    {
        $first = $this->aMandate();

        $refusal = $this->refusal(fn () => $this->aMandate(reference: $first['reference']));

        if ($refusal === null) {
            return ['two mandates share one reference, so the debtor\'s bank cannot tell them apart - and '.
                'the second mandate\'s first collection goes out as RCUR against a registration the bank '.
                'does not have'];
        }

        return str_contains($refusal, 'already in use')
            ? []
            : ['the duplicate reference was refused for some other reason: '.$refusal];
    }

    /** @return list<string> */
    private function referenceIsFixed(): array
    {
        $mandate = $this->aMandate();
        $failures = [];

        foreach ([
            ['reference' => 'CHANGED-'.Str::upper(Str::random(6))],
            ['signed_on' => '2020-01-01'],
        ] as $change) {
            $refusal = $this->refusal(fn () => DB::connection('tenant')->table('mandates')
                ->where('id', $mandate['id'])
                ->update($change));

            if ($refusal === null) {
                $failures[] = sprintf(
                    'a mandate\'s %s can be changed, which starts a new mandate while claiming this '.
                    'one\'s collection history - so the next collection goes out as RCUR against a '.
                    'mandate the bank has never seen',
                    implode(' and ', array_keys($change)),
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function signedInTheFuture(): array
    {
        $refusal = $this->refusal(fn () => $this->aMandate(signedOn: Carbon::now()->addMonth()));

        return $refusal === null
            ? ['a mandate signed next month was accepted - the commonest data-entry error on a mandate '.
                'form, and one the bank rejects']
            : [];
    }

    // ---- The sequence type ------------------------------------------------

    /** @return list<string> */
    private function sequenceIsDerived(): array
    {
        $mandate = $this->aMandate();
        $failures = [];

        $first = $this->aCollection($mandate['id'], '500.0000');

        if ($first['sequence_type'] !== 'FRST') {
            $failures[] = sprintf(
                'the first collection under a mandate went out as %s rather than FRST, which the '.
                'debtor\'s bank refuses because it has no registration to match',
                (string) $first['sequence_type'],
            );
        }

        if (! str_contains((string) $first['why'], 'has not seen before')) {
            $failures[] = 'the result does not explain why the first one is different, so a reader cannot '.
                'tell this from an arbitrary code: '.(string) $first['why'];
        }

        $second = $this->aCollection($mandate['id'], '500.0000', on: '2026-07-15');

        if ($second['sequence_type'] !== 'RCUR') {
            $failures[] = sprintf(
                'the second collection went out as %s rather than RCUR - a second FRST is a '.
                'scheme-level rejection',
                (string) $second['sequence_type'],
            );
        }

        /*
         | And it is derived, not stored on the mandate.
         |
         | A field would be edited, and the first edit produces a file the
         | bank rejects for a reason that reads as our bug.
         */
        $columns = DB::connection('tenant')->getSchemaBuilder()->getColumnListing('mandates');

        if (\in_array('sequence_type', $columns, true)) {
            $failures[] = 'the mandate carries a sequence_type column, so somebody can set it - and the '.
                'first edit produces a file the bank rejects at the scheme level';
        }

        return $failures;
    }

    /** @return list<string> */
    private function returnedFirstStillCounts(): array
    {
        $mandate = $this->aMandate();
        $first = $this->aCollection($mandate['id'], '500.0000');

        /*
         | The first collection is returned for want of funds.
         |
         | The debtor's bank still holds the mandate: the collection
         | failed, the registration did not. Presenting a second FRST
         | after a return is the commonest way a business earns a
         | scheme-level rejection on top of the one it already had.
         */
        app(Mandates::class)->returned(
            $first['id'],
            'AM04',
            'the debtor had insufficient funds',
            Carbon::parse('2026-06-20'),
            $this->actorId(),
        );

        $next = app(Mandates::class)->sequenceFor($mandate['id']);

        return $next === 'RCUR'
            ? []
            : [sprintf(
                'after a returned first collection the next one is %s, so a second FRST goes out - and '.
                'the debtor\'s bank refuses it because it already holds the registration',
                $next,
            )];
    }

    /** @return list<string> */
    private function oneFirstPerMandate(): array
    {
        $mandate = $this->aMandate();
        $this->aCollection($mandate['id'], '500.0000');

        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('collections')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'mandate_id' => $mandate['id'],
            'amount' => '500.0000',
            'currency' => 'EUR',
            'collection_date' => '2026-07-15',
            'sequence_type' => 'FRST',
            'state' => 'presented',
            'created_by' => $this->actorId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $refusal === null
            ? ['the database accepted a second FRST under one mandate, so an import or a retried job '.
                'produces the file the scheme refuses']
            : [];
    }

    // ---- Pre-notification -------------------------------------------------

    /** @return list<string> */
    private function leadTime(): array
    {
        $mandate = $this->aMandate();

        $refusal = $this->refusal(fn () => app(Mandates::class)->notify(
            $mandate['id'],
            Money::of('500.0000', 'EUR'),
            Carbon::parse('2026-06-15'),
            'email',
            $this->actorId(),
            notifiedOn: Carbon::parse('2026-06-14'),
        ));

        if ($refusal === null) {
            return ['one day\'s notice was accepted where the scheme requires fourteen - and notice that '.
                'is too short is not notice: the debtor can reverse the collection without giving a '.
                'reason for thirteen months'];
        }

        $failures = [];

        if (! str_contains($refusal, 'earliest collection')) {
            $failures[] = 'the refusal does not say the earliest date the notice could cover, so there is '.
                'nothing to correct it to: '.$refusal;
        }

        if (! str_contains($refusal, '13 months')) {
            $failures[] = 'the refusal does not say what short notice costs, so it reads as paperwork: '.$refusal;
        }

        return $failures;
    }

    /** @return list<string> */
    private function noNotification(): array
    {
        $mandate = $this->aMandate();

        $refusal = $this->refusal(fn () => app(Mandates::class)->collect(
            $mandate['id'],
            Money::of('500.0000', 'EUR'),
            Carbon::parse('2026-06-15'),
            $this->actorId(),
        ));

        if ($refusal === null) {
            return ['a collection was presented with the debtor never told about it, which is a collection '.
                'they can reverse without giving a reason for thirteen months'];
        }

        return str_contains($refusal, 'has not been told')
            ? []
            : ['the collection was refused for some other reason: '.$refusal];
    }

    /** @return list<string> */
    private function moreThanNotified(): array
    {
        $mandate = $this->aMandate();

        app(Mandates::class)->notify(
            $mandate['id'],
            Money::of('500.0000', 'EUR'),
            Carbon::parse('2026-06-15'),
            'email',
            $this->actorId(),
            notifiedOn: Carbon::parse('2026-05-20'),
        );

        /*
         | The half a product skips.
         |
         | The notification promised a figure. Collecting more than was
         | promised is not covered by it, and the symptom is a refund
         | thirteen months later nobody can explain.
         */
        $refusal = $this->refusal(fn () => app(Mandates::class)->collect(
            $mandate['id'],
            Money::of('900.0000', 'EUR'),
            Carbon::parse('2026-06-15'),
            $this->actorId(),
        ));

        if ($refusal === null) {
            return ['the debtor was told 500 and 900 was collected, which the notice does not cover - and '.
                'they can reverse the whole collection for thirteen months'];
        }

        return str_contains($refusal, 'More than was notified')
            ? []
            : ['the over-collection was refused for some other reason: '.$refusal];
    }

    /** @return list<string> */
    private function lessThanNotified(): array
    {
        $mandate = $this->aMandate();

        app(Mandates::class)->notify(
            $mandate['id'],
            Money::of('500.0000', 'EUR'),
            Carbon::parse('2026-06-15'),
            'email',
            $this->actorId(),
            notifiedOn: Carbon::parse('2026-05-20'),
        );

        /*
         | Nobody disputes being charged less than they were told.
         |
         | A check that only proved the over-collection refusal would pass
         | with the comparison inverted, and every ordinary part-credit
         | would be refused.
         */
        $collection = app(Mandates::class)->collect(
            $mandate['id'],
            Money::of('300.0000', 'EUR'),
            Carbon::parse('2026-06-15'),
            $this->actorId(),
        );

        return ($collection['sequence_type'] ?? '') === 'FRST'
            ? []
            : ['collecting less than was notified was refused, so every customer who gets a credit note '.
                'before the collection has to be re-notified for no reason'];
    }

    // ---- Cancellation and expiry ------------------------------------------

    /** @return list<string> */
    private function cancelledStopsCollections(): array
    {
        $mandate = $this->aMandate();
        $this->notifyFor($mandate['id'], '500.0000', '2026-06-15');

        app(Mandates::class)->cancel(
            $mandate['id'],
            $this->actorId(),
            'the customer moved to paying by transfer and asked us to stop collecting',
        );

        $refusal = $this->refusal(fn () => app(Mandates::class)->collect(
            $mandate['id'],
            Money::of('500.0000', 'EUR'),
            Carbon::parse('2026-06-15'),
            $this->actorId(),
        ));

        if ($refusal === null) {
            return ['a collection was presented under a cancelled mandate, so it comes back days later '.
                'after the customer has seen it on their statement and complained'];
        }

        $failures = [];

        if (! str_contains($refusal, 'no authority')) {
            $failures[] = 'the refusal does not say there is no authority to collect: '.$refusal;
        }

        /*
         | And it says WHY the mandate went.
         |
         | "Cancelled" with no reason sends whoever is looking at it to
         | the audit log, which is a worse place to read a sentence from
         | than the refusal they are already holding.
         */
        if (! str_contains($refusal, 'moved to paying by transfer')) {
            $failures[] = 'the refusal does not carry the reason the mandate was withdrawn: '.$refusal;
        }

        return $failures;
    }

    /** @return list<string> */
    private function databaseStopsCollections(): array
    {
        $mandate = $this->aMandate();

        app(Mandates::class)->cancel(
            $mandate['id'],
            $this->actorId(),
            'the customer disputed the arrangement and we agreed to stop',
        );

        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('collections')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'mandate_id' => $mandate['id'],
            'amount' => '500.0000',
            'currency' => 'EUR',
            'collection_date' => '2026-06-15',
            'sequence_type' => 'FRST',
            'state' => 'presented',
            'created_by' => $this->actorId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($refusal === null) {
            return ['the database accepted a collection under a cancelled mandate, so an import or a '.
                'queued job presents one anyway'];
        }

        return str_contains($refusal, 'revoked') || str_contains($refusal, 'no authority')
            ? []
            : ['the database refused it without explaining what a cancelled mandate means: '.$refusal];
    }

    /** @return list<string> */
    private function expiredStopsCollections(): array
    {
        $mandate = $this->aMandate(expiresOn: Carbon::parse('2026-06-01'));
        $this->notifyFor($mandate['id'], '500.0000', '2026-06-15');

        $refusal = $this->refusal(fn () => app(Mandates::class)->collect(
            $mandate['id'],
            Money::of('500.0000', 'EUR'),
            Carbon::parse('2026-06-15'),
            $this->actorId(),
        ));

        if ($refusal === null) {
            return ['a collection dated after the mandate expires was accepted, and an expired mandate is '.
                'no authority at all'];
        }

        return str_contains($refusal, 'expires on')
            ? []
            : ['the expired mandate was refused without naming the date it expired: '.$refusal];
    }

    /** @return list<string> */
    private function cancellationNeedsAReason(): array
    {
        $mandate = $this->aMandate();

        $refusal = $this->refusal(fn () => app(Mandates::class)->cancel($mandate['id'], $this->actorId(), 'done'));

        return $refusal === null
            ? ['a mandate was withdrawn with no reason, so the next person to ask why a customer is no '.
                'longer on direct debit gets a timestamp']
            : [];
    }

    // ---- Returns ----------------------------------------------------------

    /** @return list<string> */
    private function returnKeepsTheCode(): array
    {
        $mandate = $this->aMandate();
        $collection = $this->aCollection($mandate['id'], '500.0000');

        app(Mandates::class)->returned(
            $collection['id'],
            'AM04',
            'the debtor had insufficient funds on the collection date',
            Carbon::parse('2026-06-20'),
            $this->actorId(),
        );

        $row = DB::connection('tenant')->table('collections')->where('id', $collection['id'])->first();
        $failures = [];

        if ((string) ($row->state ?? '') !== 'returned') {
            $failures[] = 'the collection is not marked returned';
        }

        if ((string) ($row->return_reason_code ?? '') !== 'AM04') {
            $failures[] = 'the bank\'s reason code is not kept, and it is what tells an insufficient '.
                'balance from a revoked mandate from an account that no longer exists';
        }

        if (($row->returned_on ?? null) === null) {
            $failures[] = 'the return records no date';
        }

        return $failures;
    }

    /** @return list<string> */
    private function returnNeedsACode(): array
    {
        $mandate = $this->aMandate();
        $collection = $this->aCollection($mandate['id'], '500.0000');

        $refusal = $this->refusal(fn () => app(Mandates::class)->returned(
            $collection['id'],
            '  ',
            'it came back',
            Carbon::parse('2026-06-20'),
            $this->actorId(),
        ));

        if ($refusal === null) {
            return ['a return was recorded with no reason code, so the figure is one somebody has to '.
                'telephone the bank about'];
        }

        /*
         | And the database refuses it too.
         |
         | The constraint exists because an import is the other way a
         | return arrives, and a returned collection with no code is the
         | one row that cannot be acted on.
         */
        $direct = $this->refusal(fn () => DB::connection('tenant')->table('collections')
            ->where('id', $collection['id'])
            ->update(['state' => 'returned', 'returned_on' => '2026-06-20']));

        return $direct === null
            ? ['the database accepted a returned collection with no reason code']
            : [];
    }

    /** @return list<string> */
    private function disputeCancelsTheMandate(): array
    {
        $mandate = $this->aMandate();
        $collection = $this->aCollection($mandate['id'], '500.0000');

        /*
         | MD01: the debtor says there is no mandate.
         |
         | Presenting again would be returned again and would look, to the
         | debtor's bank, like a business that does not read its returns -
         | which is how a creditor identifier gets suspended.
         */
        $result = app(Mandates::class)->returned(
            $collection['id'],
            'MD01',
            'the debtor says they never authorised this',
            Carbon::parse('2026-06-20'),
            $this->actorId(),
        );

        $failures = [];

        if (($result['mandate_cancelled'] ?? false) !== true) {
            $failures[] = 'a return under MD01 - the debtor denying the mandate - left the mandate active, '.
                'so the next collection goes out and is returned again';
        }

        $state = (string) DB::connection('tenant')->table('mandates')->where('id', $mandate['id'])->value('state');

        if ($state !== 'cancelled') {
            $failures[] = 'the mandate is still '.$state.' after the debtor denied it';
        }

        if (! str_contains((string) $result['why'], 'identifier gets suspended')) {
            $failures[] = 'the result does not say what repeated returns cost, so cancelling the mandate '.
                'reads as an overreaction: '.(string) $result['why'];
        }

        return $failures;
    }

    /** @return list<string> */
    private function insufficientFundsDoesNot(): array
    {
        $mandate = $this->aMandate();
        $collection = $this->aCollection($mandate['id'], '500.0000');

        $result = app(Mandates::class)->returned(
            $collection['id'],
            'AM04',
            'the debtor had insufficient funds',
            Carbon::parse('2026-06-20'),
            $this->actorId(),
        );

        $failures = [];

        /*
         | A failed collection is not a withdrawn authority.
         |
         | Cancelling on every return would end the mandate the first time
         | a customer was short for a week, and getting it signed again is
         | a conversation nobody wants to have.
         */
        if (($result['mandate_cancelled'] ?? false) !== false) {
            $failures[] = 'an insufficient balance cancelled the mandate, so one short week ends the '.
                'arrangement and somebody has to ask the customer to sign again';
        }

        $state = (string) DB::connection('tenant')->table('mandates')->where('id', $mandate['id'])->value('state');

        if ($state !== 'active') {
            $failures[] = 'the mandate is '.$state.' after a collection failed for want of funds';
        }

        if (! str_contains((string) $result['why'], 'mandate stands')) {
            $failures[] = 'the result does not say the mandate stands, which is the thing a reader needs '.
                'to know: '.(string) $result['why'];
        }

        return $failures;
    }

    /** @return list<string> */
    private function returnedOnce(): array
    {
        $mandate = $this->aMandate();
        $collection = $this->aCollection($mandate['id'], '500.0000');

        app(Mandates::class)->returned(
            $collection['id'],
            'AM04',
            'the debtor had insufficient funds',
            Carbon::parse('2026-06-20'),
            $this->actorId(),
        );

        $refusal = $this->refusal(fn () => app(Mandates::class)->returned(
            $collection['id'],
            'MS03',
            'and again',
            Carbon::parse('2026-06-21'),
            $this->actorId(),
        ));

        return $refusal === null
            ? ['the same collection was returned twice, so the accounting for it is applied twice and the '.
                'reason code on the row is whichever arrived last']
            : [];
    }

    // ---- The file ---------------------------------------------------------

    /** @return list<string> */
    private function fileGroupsBySequence(): array
    {
        $first = $this->aMandate();
        $second = $this->aMandate();

        $a = $this->aCollection($first['id'], '500.0000');
        $b = $this->aCollection($second['id'], '700.0000');

        /*
         | One FRST and one RCUR, on the same date.
         |
         | The structural rule that makes this file different from a
         | credit transfer: a block holding both is refused at the scheme
         | level, because `SeqTp` is on the block.
         */
        $this->notifyFor($first['id'], '400.0000', '2026-06-15');
        $recurring = app(Mandates::class)->collect(
            $first['id'],
            Money::of('400.0000', 'EUR'),
            Carbon::parse('2026-06-15'),
            $this->actorId(),
        );

        $xml = $this->parse($this->renderFile([$a['id'], $b['id'], $recurring['id']]));

        if ($xml === null) {
            return ['the collection file is not parseable XML'];
        }

        $failures = [];
        $sequences = [];

        foreach ($xml->query('//p:PmtInf') as $block) {
            $sequence = $xml->query('p:PmtTpInf/p:SeqTp', $block)->item(0)?->textContent;
            $sequences[] = (string) $sequence;

            if ($sequence === null) {
                $failures[] = 'a payment block carries no sequence type, which the scheme requires on the '.
                    'block rather than on the transaction';
            }
        }

        if (\count(array_unique($sequences)) !== \count($sequences)) {
            $failures[] = 'two blocks share a sequence type and a date, so the grouping is not by sequence '.
                'type at all';
        }

        if (! \in_array('FRST', $sequences, true) || ! \in_array('RCUR', $sequences, true)) {
            $failures[] = 'a file holding a first and a recurring collection does not produce one block of '.
                'each - and a block holding both is a scheme-level rejection: '.implode(', ', $sequences);
        }

        return $failures;
    }

    /** @return list<string> */
    private function mandateTravels(): array
    {
        $mandate = $this->aMandate();
        $collection = $this->aCollection($mandate['id'], '500.0000');

        $xml = $this->parse($this->renderFile([$collection['id']]));

        if ($xml === null) {
            return ['the collection file is not parseable XML'];
        }

        $failures = [];

        $reference = $xml->query('//p:DrctDbtTx/p:MndtRltdInf/p:MndtId')->item(0)?->textContent;
        $signed = $xml->query('//p:DrctDbtTx/p:MndtRltdInf/p:DtOfSgntr')->item(0)?->textContent;

        if ($reference !== $mandate['reference']) {
            $failures[] = sprintf(
                'the transaction carries "%s" as the mandate reference rather than "%s" - the debtor\'s '.
                'bank matches on it, and one they do not recognise is returned',
                (string) $reference,
                $mandate['reference'],
            );
        }

        if ($signed === null || $signed === '') {
            $failures[] = 'the transaction carries no signature date, and one that disagrees with the '.
                'debtor\'s bank\'s registration is returned under its own code';
        }

        if ($xml->query('//p:CdtrSchmeId//p:Othr/p:Id')->length === 0) {
            $failures[] = 'the file carries no creditor identifier, which the debtor sees on their '.
                'statement and the bank uses to recognise who is collecting';
        }

        return $failures;
    }

    /** @return list<string> */
    private function schemesDoNotMix(): array
    {
        $core = $this->aMandate();
        $b2b = $this->aMandate(scheme: 'sepa_b2b');

        $a = $this->aCollection($core['id'], '500.0000');
        $b = $this->aCollection($b2b['id'], '500.0000');

        $refusal = $this->refusal(fn () => $this->renderFile([$a['id'], $b['id']]));

        if ($refusal === null) {
            return ['Core and B2B collections were put in one file - and a bank holding a B2B '.
                'registration refuses a Core collection against it, which fails the whole file'];
        }

        return str_contains($refusal, 'One file carries one scheme')
            ? []
            : ['the mixed file was refused for some other reason: '.$refusal];
    }

    /** @return list<string> */
    private function sequencesAreComplete(): array
    {
        $mandate = $this->aMandate();
        $collection = $this->aCollection($mandate['id'], '500.0000');
        $xml = $this->parse($this->renderFile([$collection['id']]));

        if ($xml === null) {
            return ['the collection file is not parseable XML'];
        }

        $sequences = CollectionSequences::all();
        $failures = [];

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
                $failures[] = sprintf('<%s> has children and no declared sequence', (string) $node->localName);

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
                    'the children of <%s> are in the order %s, and the schema declares %s',
                    (string) $node->localName,
                    implode(', ', $children),
                    implode(', ', $declared),
                );
            }
        }

        return $failures;
    }

    // ---- Helpers ----------------------------------------------------------

    /** @return array{id: string, reference: string} */
    private function aMandate(
        ?string $reference = null,
        string $scheme = 'sepa_core',
        ?Carbon $signedOn = null,
        ?Carbon $expiresOn = null,
    ): array {
        $party = \App\Tenant\Parties\Models\Party::create([
            'code' => 'DD-'.Str::upper(Str::random(6)),
            'legal_name' => 'Direct Debit Customer '.bin2hex(random_bytes(3)),
            'is_customer' => true,
            'country' => 'DE',
            'default_currency' => 'EUR',
            'active' => true,
        ]);

        /*
         | A German IBAN, because the debtor is in the euro area.
         |
         | Computed rather than invented: the model validates the check
         | digits, and a fixture that disabled that validation would be
         | standing on a state the system refuses.
         */
        $account = app(\App\Tenant\Parties\Services\BankDetailRegistry::class)->record(
            $party,
            iban: $this->aGermanIban(),
            bic: 'DEUTDEFF',
        );

        app(\App\Tenant\Parties\Services\BankDetailRegistry::class)->verify(
            $account->fresh(),
            $this->secondActorId(),
            'callback',
        );

        $result = app(Mandates::class)->record(
            entityId: $this->entityId,
            partyId: (string) $party->getKey(),
            reference: $reference ?? 'MND-'.Str::upper(Str::random(10)),
            scheme: $scheme,
            signedOn: $signedOn ?? Carbon::parse('2026-05-01'),
            signatureChannel: 'paper',
            debtorName: (string) $party->legal_name,
            userId: $this->actorId(),
            debtorAccountId: (string) $account->getKey(),
            expiresOn: $expiresOn,
        );

        return ['id' => (string) $result['id'], 'reference' => (string) $result['reference']];
    }

    /** @return array<string, mixed> */
    private function aCollection(string $mandateId, string $amount, string $on = '2026-06-15'): array
    {
        $this->notifyFor($mandateId, $amount, $on);

        return app(Mandates::class)->collect(
            $mandateId,
            Money::of($amount, 'EUR'),
            Carbon::parse($on),
            $this->actorId(),
        );
    }

    private function notifyFor(string $mandateId, string $amount, string $on): void
    {
        app(Mandates::class)->notify(
            $mandateId,
            Money::of($amount, 'EUR'),
            Carbon::parse($on),
            'email',
            $this->actorId(),
            notifiedOn: Carbon::parse($on)->subDays(20),
        );
    }

    /** @param list<string> $collectionIds */
    private function renderFile(array $collectionIds): string
    {
        return app(\App\Tenant\Settlement\Payments\CollectionSerializer::class)->render(
            entityId: $this->entityId,
            collectionIds: $collectionIds,
            creditorName: 'Peakflow Trading Co.',
            creditorIban: 'DE89370400440532013000',
            creditorBic: 'COBADEFFXXX',
            creditorIdentifier: 'DE98ZZZ09999999999',
            messageId: 'COLL-'.Str::upper(Str::random(8)),
            createdAt: Carbon::parse('2026-06-01 09:00:00'),
        );
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

    private int $ibanCounter = 0;

    /** A German IBAN with computed check digits. */
    private function aGermanIban(): string
    {
        $this->ibanCounter++;
        $bban = sprintf('37040044%010d', $this->ibanCounter);

        // ISO 13616: rearrange, letters to digits (D=13, E=14), 98 minus mod 97.
        $remainder = 0;

        foreach (str_split($bban.'131400') as $digit) {
            $remainder = (int) (($remainder.$digit) % 97);
        }

        return sprintf('DE%02d%s', 98 - $remainder, $bban);
    }

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
}
