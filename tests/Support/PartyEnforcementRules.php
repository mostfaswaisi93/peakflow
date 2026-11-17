<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Support\Authorization\SegregatesDuties;
use App\Support\Money\Money;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;
use App\Tenant\Parties\Exceptions\BankAccountNotVerified;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Exceptions\CreditRefused;
use App\Tenant\Parties\Services\BankDetailDisclosure;
use App\Tenant\Parties\Services\BankDetailRegistry;
use App\Tenant\Parties\Services\CreditControl;
use App\Tenant\Parties\Services\DocumentDelivery;
use App\Tenant\Parties\Services\PartyMerger;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Ledger\Posting\RuleDescription;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Ledger\Services\Poster;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The controls around party bank details - PF-061.
 *
 * The highest-value fraud surface in the product: change where a supplier's
 * money goes, then release a payment to it. Every check here is one of the
 * layers that makes a single person acting alone insufficient.
 *
 * The encryption is the least of them. It protects the number from being
 * READ; it does nothing against an authorised user legitimately changing the
 * account to one they control. What stops that is the second person, and
 * these checks are mostly about whether that second person can be skipped.
 */
final class PartyEnforcementRules
{
    use \Tests\Support\Concerns\ProvesTheLedger;

    private ?Connection $connection = null;

    private string $entityId = '';

    private ?User $clerk = null;

    private ?User $approver = null;

    private ?Entity $entity = null;

    private string $revenueAccountId = '';

    private string $postingDate = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return ['party controls (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return $this->withTheLedgerProof([
                'a bank account number never becomes an attribute' => $this->numberIsNotAnAttribute(),
                'a new or changed account is unusable until verified' => $this->changesRequireVerification(),
                'the person who changed an account cannot verify it' => $this->verifierIsNotTheAuthor(),
                'a bank number never reaches the audit log' => $this->numberNeverReachesTheLog(),
                'the beneficiary/release pair can never be overridden' => $this->theSodPairCannotBeOverridden(),
                'exposure counts uninvoiced orders, not just receivables' => $this->exposureIsComplete(),
                'a credit hold cannot exist without an author' => $this->holdsHaveAnAuthor(),
                'an override is bounded, justified and raises TO not BY' => $this->overridesAreBounded(),
                'a dispute suspends dunning and keeps the debt' => $this->disputesSuspendOnly(),
                'a merge keeps the loser, and exposure sums across the chain' => $this->mergeKeepsHistory(),
                "a document goes out in the RECIPIENT's language" => $this->deliveryUsesTheirLanguage(),
                'who LOOKED at a bank account is recorded too' => $this->readsAreAudited(),
            ], $this->entityId);
        } catch (Throwable $e) {
            return ['party controls could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function numberIsNotAnAttribute(): array
    {
        $violations = [];
        $account = $this->newAccount('SA0380000000608010167519');
        $serialised = $account->fresh()->toArray();

        foreach (['iban', 'iban_encrypted', 'account_number', 'account_number_encrypted'] as $key) {
            if (array_key_exists($key, $serialised)) {
                $violations[] = sprintf(
                    '"%s" is in the model\'s array form, so it reaches anything that serialises the model - a '.
                    'log line, a webhook payload, an exception report, a `dd()`',
                    $key,
                );
            }
        }

        if ($account->last_four !== '7519') {
            $violations[] = 'the last four digits are not recorded, so nobody can confirm the account on a '.
                'phone call without decrypting it - which is the control that actually catches substitution';
        }

        $stored = (string) $this->connection->table('party_bank_accounts')
            ->where('id', $account->getKey())
            ->value('iban_encrypted');

        if ($stored === '' || str_contains($stored, '6080')) {
            $violations[] = 'the account is stored in clear at the column, so disk encryption is the only '.
                'thing protecting it - and that protects a stolen disk, not a SELECT';
        }

        if ($account->fresh()->revealIban() !== 'SA0380000000608010167519') {
            $violations[] = 'the stored account does not decrypt back to what was entered';
        }

        return $violations;
    }

    /** @return list<string> */
    public function changesRequireVerification(): array
    {
        $registry = app(BankDetailRegistry::class);
        $account = $this->newAccount('SA0380000000608010167519');
        $violations = [];

        if (! $account->requires_reverification) {
            $violations[] = 'a NEW account is usable immediately, so an account added by an import or an API '.
                'client skips the second person entirely';
        }

        /*
         | As the APPROVER, not the clerk.
         |
         | The clerk changed this account, and the SoD pair refuses them
         | `payment.release` on this party outright - which is the control
         | working and not what this particular check is about. Asserting
         | usability as the clerk tested the SoD pair by accident and said
         | nothing about verification.
         */
        auth()->guard('web')->setUser($this->approver);

        try {
            $registry->assertUsable($account->fresh());

            $violations[] = 'a payment to an unverified account was allowed';
        } catch (BankAccountNotVerified) {
            // Correct.
        }

        $registry->verify($account->fresh(), (string) $this->approver?->getKey(), 'phoned the published number');

        if ($account->fresh()->requires_reverification) {
            $violations[] = 'verifying an account did not make it usable';
        }

        /*
         | A raw UPDATE must re-arm the block.
         |
         | The attack is somebody reaching the column another way - a support
         | script, a bulk import, a console session - so the flag is set by a
         | database trigger rather than by the service.
         */
        $this->connection->table('party_bank_accounts')
            ->where('id', $account->getKey())
            ->update(['bic' => 'SABBSARI']);

        if (! $account->fresh()->requires_reverification) {
            $violations[] = 'changing the account with a direct UPDATE left it verified, so anything reaching '.
                'the column outside the service bypasses the second person';
        }

        auth()->guard('web')->setUser($this->clerk);

        return $violations;
    }

    /**
     * The one pair that can never be overridden - PF-061.
     *
     * `supplier.bank_details.update` against `payment.release`, scoped to
     * the party. Whoever changed where a supplier's money goes must not be
     * the one who releases a payment to it, because the two acts together
     * ARE the fraud - either one alone is ordinary work.
     *
     * Asserted at BOTH ends, because the order is not fixed: somebody who
     * has already released a payment to this supplier and now changes their
     * bank account is the same conflict arrived at backwards.
     *
     * @return list<string>
     */
    public function theSodPairCannotBeOverridden(): array
    {
        $registry = app(BankDetailRegistry::class);
        $duties = app(SegregatesDuties::class);
        $violations = [];

        // The clerk changes an account.
        auth()->guard('web')->setUser($this->clerk);
        $account = $this->newAccount('SA0380000000608010167519');
        $partyId = (string) $account->party_id;

        /*
         | Asked with a subject AND a party.
         |
         | `payment.release` carries more than one rule, and
         | prepare-then-release is scoped to the payment RUN - the engine
         | refuses to evaluate an object-scoped rule with no subject id,
         | which is right and which made the first version of this check
         | throw rather than answer.
         */
        $blocking = $duties->blockingRule(
            duty: 'payment.release',
            subjectType: 'payment_run',
            subjectId: (string) Str::ulid(),
            partyId: $partyId,
        );

        if ($blocking !== 'beneficiary_then_release') {
            $violations[] = sprintf(
                'after changing a supplier\'s bank account, the same user is not blocked from releasing a '.
                'payment to that supplier (blocking rule: %s). The two acts together ARE the fraud; either '.
                'one alone is ordinary work',
                var_export($blocking, true),
            );
        }

        $refusal = $this->refusal(fn () => $registry->assertUsable($account->fresh()));

        if ($refusal === null) {
            $violations[] = 'the payment path allowed the person who changed the account to release to it';
        } elseif (! str_contains($refusal, 'you cannot release a payment to it')) {
            $violations[] = 'the payment was refused for a reason other than the conflicting duty: '.$refusal;
        }

        /*
         | And the rule may not be marked overridable.
         |
         | Every other pair has a legitimate exception - a tenant with three
         | staff genuinely cannot separate every duty. This one does not, at
         | any size of business, because an override on it is exactly what
         | an insider would request.
         */
        $overridable = $this->connection->table('sod_rules')
            ->where('key', 'beneficiary_then_release')
            ->value('overridable');

        if ($overridable !== false) {
            $violations[] = 'the beneficiary-change-against-payment-release rule is marked overridable. '.
                'An override on this pair is exactly what an insider would request, and granting one is '.
                'indistinguishable from not having the rule';
        }

        // A DIFFERENT person is not blocked by the pair.
        auth()->guard('web')->setUser($this->approver);

        $forSomebodyElse = $duties->blockingRule(
            duty: 'payment.release',
            subjectType: 'payment_run',
            subjectId: (string) Str::ulid(),
            partyId: $partyId,
        );

        if ($forSomebodyElse !== null) {
            $violations[] = 'a second person is blocked from releasing a payment to a supplier whose account '.
                'somebody else changed. The control is a second pair of eyes, not a freeze - blocking '.
                'everybody means the payment never goes out and the rule gets switched off';
        }

        auth()->guard('web')->setUser($this->clerk);

        return $violations;
    }

    /** @return list<string> */
    public function verifierIsNotTheAuthor(): array
    {
        $registry = app(BankDetailRegistry::class);
        $account = $this->newAccount('SA4420000001234567891234');

        try {
            $registry->verify($account->fresh(), (string) $this->clerk?->getKey(), 'confirmed it myself');

            return ['the person who changed an account was allowed to verify it; one person acting alone is '.
                'exactly the case the control exists to catch'];
        } catch (BankAccountNotVerified) {
            return [];
        }
    }

    /** @return list<string> */
    public function numberNeverReachesTheLog(): array
    {
        $this->newAccount('SA0380000000608010167519');

        $everything = $this->connection->table('audit_log')
            ->get()
            ->map(static fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))
            ->implode(' ');

        $violations = [];

        foreach (['608010167519', '0380000000', '1234567891234'] as $fragment) {
            if (str_contains($everything, $fragment)) {
                $violations[] = sprintf(
                    'the fragment "%s" of a bank account appears in the audit log, which is read by far more '.
                    'people than the account is',
                    $fragment,
                );
            }
        }

        return $violations;
    }

    /**
     * PF-062: the term that gets left out.
     *
     * Exposure is open receivables PLUS uninvoiced orders. Leaving the
     * second out is what makes a credit limit decorative: a customer at
     * their limit places four more orders, none of which is an invoice yet,
     * and the limit is breached by the time anybody looks.
     *
     * Sales orders arrive in a later phase, so what is checked now is that
     * the term EXISTS and is reported - a credit check written without it
     * gets shipped, and adding it later means revisiting every call site and
     * every number people have learnt to expect.
     *
     * @return list<string>
     */
    public function exposureIsComplete(): array
    {
        $credit = app(CreditControl::class);
        $violations = [];

        $customer = $this->customerOwing('46000.0000');
        $state = $credit->exposure($this->entityId, (string) $customer->getKey(), 'SAR');

        if ($state['receivables'] !== '46000.0000') {
            $violations[] = sprintf(
                'open receivables came back as %s rather than 46,000. They are read from the receivable '.
                'CONTROL account, because a credit limit measured against a number that disagrees with the '.
                'trial balance is one nobody trusts after the first argument',
                $state['receivables'],
            );
        }

        if (! array_key_exists('uninvoiced_orders', $state)) {
            $violations[] = 'exposure does not report uninvoiced orders at all, so a customer at their limit '.
                'can place four more orders and the limit is breached before anybody looks';
        }

        if (! str_contains((string) $state['derivation'], 'uninvoiced orders')) {
            $violations[] = 'the exposure does not say how it was built, so a salesperson told "credit check '.
                'failed" has nothing to tell the customer';
        }

        $this->setLimit($customer, '50000.0000');
        $state = $credit->exposure($this->entityId, (string) $customer->getKey(), 'SAR');

        if ($state['headroom'] !== '4000.0000') {
            $violations[] = sprintf('headroom came back as %s rather than 4,000', (string) $state['headroom']);
        }

        // Inside the headroom: allowed. Past it: refused, with the figures.
        try {
            $credit->assertCanCommit($this->entityId, $customer, 'SAR', Money::of('3999.00', 'SAR'));
        } catch (Throwable $e) {
            $violations[] = 'a commitment inside the headroom was refused: '.Str::limit($e->getMessage(), 120);
        }

        try {
            $credit->assertCanCommit($this->entityId, $customer, 'SAR', Money::of('4001.00', 'SAR'));

            $violations[] = 'a commitment past the credit limit was allowed';
        } catch (CreditRefused $e) {
            if (! str_contains($e->getMessage(), 'over their credit limit')) {
                $violations[] = 'the refusal does not say by how much or against what limit, which is what '.
                    'the salesperson needs to say to the customer';
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function holdsHaveAnAuthor(): array
    {
        $credit = app(CreditControl::class);
        $violations = [];

        $customer = $this->customerOwing('1000.0000');

        $credit->hold(
            $this->entityId,
            $customer,
            'two unpaid invoices past 90 days and no response to three letters',
            'SAR',
        );

        $settings = $this->connection->table('party_entity_settings')
            ->where('party_id', $customer->getKey())
            ->first();

        if ($settings?->credit_hold !== true) {
            $violations[] = 'placing a hold did not set the flag the invoice path reads, so the hold does '.
                'nothing';
        }

        $event = $this->connection->table('credit_hold_events')
            ->where('party_id', $customer->getKey())
            ->first();

        if ($event === null || $event->by_user_id === null) {
            $violations[] = 'a hold was placed with no recorded author. "Who put us on hold" is the first '.
                'thing the customer asks, and a boolean cannot answer it';
        }

        /*
         | The figures AS THEY WERE.
         |
         | Recomputing them later gives today's numbers, and "why was this
         | customer put on hold in March" is answered by the March figures -
         | which is also what makes the decision defensible when the
         | customer disputes it.
         */
        if ($event !== null && $event->exposure_at_decision === null) {
            $violations[] = 'the hold does not record the exposure it was decided on, so the decision can '.
                'only be re-justified against today\'s figures';
        }

        /*
         | And the flag cannot be written directly.
         |
         | Without that, the mirror is a convenience rather than a
         | guarantee: anything reaching party_entity_settings could put a
         | customer on hold with no record of who or why.
         */
        $direct = $this->refusal(fn () => $this->connection->table('party_entity_settings')
            ->where('party_id', $customer->getKey())
            ->update(['credit_hold' => false]));

        if ($direct === null) {
            $violations[] = 'the credit hold flag was cleared by a direct UPDATE, so a support script can '.
                'release a customer with no record - and the next person to ask why finds nothing';
        }

        return $violations;
    }

    /** @return list<string> */
    public function overridesAreBounded(): array
    {
        $credit = app(CreditControl::class);
        $violations = [];

        $customer = $this->customerOwing('46000.0000');
        $this->setLimit($customer, '50000.0000');

        foreach ([
            ['approved', Carbon::now()->addDay(), 'a one-word justification'],
            ['the regional director approved this by email', Carbon::now()->subDay(), 'an expiry in the past'],
        ] as [$justification, $until, $what]) {
            try {
                $credit->override($this->entityId, $customer, $justification, $until);

                $violations[] = sprintf('%s was accepted for a credit override', $what);
            } catch (CreditRefused) {
                // Correct.
            }
        }

        $credit->override(
            $this->entityId,
            $customer,
            'the regional director approved this on 11 March pending the receipt they have sent',
            Carbon::now()->addDays(7),
            upTo: Money::of('60000.00', 'SAR'),
        );

        /*
         | TO the amount, not BY it.
         |
         | "Approve up to 60,000" raises the ceiling to 60,000. Reading it
         | as "60,000 on top of what they already owe" was the first
         | interpretation and it is wrong in the dangerous direction - it
         | would have allowed 106,000 against a 50,000 limit.
         */
        try {
            $credit->assertCanCommit($this->entityId, $customer, 'SAR', Money::of('13999.00', 'SAR'));
        } catch (Throwable $e) {
            $violations[] = 'an override up to 60,000 did not allow a commitment taking exposure to 59,999: '.
                Str::limit($e->getMessage(), 120);
        }

        try {
            $credit->assertCanCommit($this->entityId, $customer, 'SAR', Money::of('14001.00', 'SAR'));

            $violations[] = 'an override up to 60,000 allowed exposure past 60,000, so it was read as "by" '.
                'rather than "to" - which would permit 106,000 against a 50,000 limit';
        } catch (CreditRefused) {
            // Correct.
        }

        return $violations;
    }

    /** @return list<string> */
    public function disputesSuspendOnly(): array
    {
        $credit = app(CreditControl::class);
        $violations = [];

        $customer = $this->customerOwing('46000.0000');
        $documentId = (string) Str::ulid();

        if ($credit->dunningSuspended($this->entityId, 'invoice', $documentId)) {
            $violations[] = 'dunning is suspended for a document with no dispute';
        }

        $credit->dispute(
            $this->entityId,
            'invoice',
            $documentId,
            $customer,
            'quantity',
            'the customer says eight pallets arrived, not ten',
            Money::of('12000.00', 'SAR'),
        );

        if (! $credit->dunningSuspended($this->entityId, 'invoice', $documentId)) {
            $violations[] = 'a dispute did not suspend dunning, so a customer who has formally queried an '.
                'invoice keeps receiving reminders about it - which is how a relationship ends over an '.
                'administrative error';
        }

        $state = $credit->exposure($this->entityId, (string) $customer->getKey(), 'SAR');

        /*
         | The debt is NOT cleared.
         |
         | Netting a disputed invoice off the exposure would be the easy
         | implementation and would let a customer keep buying by disputing
         | everything - which is the standard response to a credit block,
         | not a theoretical concern.
         */
        if ($state['exposure'] !== '46000.0000') {
            $violations[] = sprintf(
                'a dispute changed the exposure to %s. A disputed invoice is still owed; excluding it lets a '.
                'customer buy by disputing everything',
                $state['exposure'],
            );
        }

        if ($state['disputed'] !== '12000.0000') {
            $violations[] = 'the disputed amount is not reported separately, so nobody can see how much of '.
                'the exposure is contested';
        }

        // One open dispute per document: the second is the same complaint
        // entered twice, and each would suspend dunning and need resolving.
        $second = $this->refusal(fn () => $credit->dispute(
            $this->entityId,
            'invoice',
            $documentId,
            $customer,
            'price',
            'the same complaint again',
        ));

        if ($second === null) {
            $violations[] = 'a second open dispute on one document was accepted';
        }

        // A resolution needs text somebody can read.
        $closedBlind = $this->refusal(fn () => $this->connection->table('document_disputes')
            ->where('entity_id', $this->entityId)
            ->where('document_id', $documentId)
            ->update(['status' => 'upheld', 'resolved_at' => now()]));

        if ($closedBlind === null) {
            $violations[] = 'a dispute was resolved with no resolution text, so the customer\'s next '.
                'question has no answer';
        }

        return $violations;
    }

    /**
     * PF-059: a merge cannot repoint history, so it keeps the loser.
     *
     * Posted journal lines are immutable - the ledger's central invariant -
     * so a merge CANNOT move a duplicate customer's invoices to the
     * survivor. The loser is kept, deactivated, pointing at the survivor,
     * and every lookup resolves through it.
     *
     * Which means exposure has to sum across the chain. Summing only the
     * survivor's own lines reports a customer who owes 46,000 as owing
     * 30,000 - the credit check failing in the one direction that matters.
     *
     * @return list<string>
     */
    public function mergeKeepsHistory(): array
    {
        $merger = app(PartyMerger::class);
        $credit = app(CreditControl::class);
        $violations = [];

        $survivor = $this->customerOwing('30000.0000');
        $duplicate = $this->customerOwing('16000.0000');

        /*
         | The duplicate's bank account is VERIFIED before the merge.
         |
         | Which is the point of the check below: a merge is otherwise a way
         | to introduce a beneficiary account without the second pair of
         | eyes - merge a party you control into a real supplier and their
         | payments follow.
         */
        $account = app(BankDetailRegistry::class)->record($duplicate, iban: 'SA4420000001234567891234');

        app(BankDetailRegistry::class)->verify(
            $account->fresh(),
            (string) $this->approver?->getKey(),
            'phoned the number on their published website',
        );

        $thin = $this->refusal(fn () => $merger->merge($survivor, $duplicate, 'dupe'));

        if ($thin === null) {
            $violations[] = 'a merge with a one-word reason was accepted. It cannot be undone, and the '.
                'question asked afterwards is always why these were thought to be the same customer';
        }

        $result = $merger->merge(
            $survivor,
            $duplicate,
            'same commercial registration 1010123456 and the same VAT number; confirmed with their team',
        );

        if ($duplicate->fresh()?->merged_into_id === null) {
            return ['the merge did not point the duplicate at the survivor'];
        }

        if ($duplicate->fresh()?->active !== false) {
            $violations[] = 'the merged party is still active, so new documents can be addressed to a '.
                'record that is no longer a customer';
        }

        /*
         | History still names the old party, and must.
         |
         | An invoice issued three years ago says who it was issued to,
         | which is what an invoice is for.
         */
        $historical = $this->connection->table('journal_lines')
            ->where('entity_id', $this->entityId)
            ->where('party_id', $duplicate->getKey())
            ->exists();

        if (! $historical) {
            $violations[] = 'the merge repointed historical journal lines. Posted lines are immutable - if '.
                'this succeeded, the ledger accepted a write it must refuse';
        }

        // And the exposure follows the chain.
        $state = $credit->exposure($this->entityId, (string) $survivor->getKey(), 'SAR');

        if ($state['receivables'] !== '46000.0000') {
            $violations[] = sprintf(
                'after merging, the survivor owes %s rather than 46,000. The duplicate\'s invoices still '.
                'name the duplicate and always will, so exposure has to sum across the merge chain - and '.
                'without that a merged customer reads as owing less than they do',
                $state['receivables'],
            );
        }

        /*
         | The bank account moves UNVERIFIED.
         |
         | The highest-value attack in the product is beneficiary
         | substitution, and a merge that carried verification over would be
         | a path straight past the second person.
         */
        if ($account->fresh()?->requires_reverification !== true) {
            $violations[] = 'a verified bank account stayed verified through a merge. Merging a party you '.
                'control into a real supplier would then redirect their payments with nobody confirming it';
        }

        if ($result['bank_accounts_requiring_verification'] !== 1) {
            $violations[] = 'the merge does not report how many bank accounts now need re-verifying, so '.
                'nobody knows to do it';
        }

        /*
         | The chain stays one deep, and a merge cannot be undone.
         |
         | Un-merging would have to decide which of the documents written
         | since belong to which party, and there is no information to
         | decide it with.
         */
        $deeper = $this->refusal(fn () => $this->connection->table('parties')
            ->where('id', $this->customerOwing('1.0000')->getKey())
            ->update(['merged_into_id' => $duplicate->getKey(), 'active' => false]));

        if ($deeper === null) {
            $violations[] = 'a party was merged into an already-merged one, so the chain is two deep and '.
                'every lookup has to walk it - and a walk can loop';
        }

        $unmerged = $this->refusal(fn () => $this->connection->table('parties')
            ->where('id', $duplicate->getKey())
            ->update(['merged_into_id' => null]));

        if ($unmerged === null) {
            $violations[] = 'a merged party was un-merged, which leaves the documents written since '.
                'belonging to nobody in particular';
        }

        return $violations;
    }

    /** @return list<string> */
    public function deliveryUsesTheirLanguage(): array
    {
        $delivery = app(DocumentDelivery::class);
        $violations = [];

        $party = Party::create([
            'legal_name' => 'Delivery probe '.bin2hex(random_bytes(3)),
            'country' => 'DE',
            'is_customer' => true,
            // The party reads Arabic; the contact reads English. Both differ
            // from the entity default, which is what makes the order
            // observable.
            'default_locale' => 'ar',
        ]);

        $contactId = (string) Str::ulid();

        $this->connection->table('party_contacts')->insert([
            'id' => $contactId,
            'party_id' => $party->getKey(),
            'name' => 'Accounts payable',
            'role' => 'billing',
            'email' => 'ap@example.invalid',
            'locale' => 'en',
            'receives_documents' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         | With no preference, the PARTY's language beats the company
         | default - and the issuing user's locale appears nowhere.
         |
         | An invoice from a Saudi company to a German customer is read in
         | Germany, and sending it in Arabic because the issuer works in
         | Arabic produces a document the recipient files unread - which
         | then ages into a dunning letter about an invoice they could not
         | read.
         */
        $withoutPreference = $delivery->for($this->entityId, $party, 'invoice');

        if ($withoutPreference['locale'] !== 'ar') {
            $violations[] = sprintf(
                'with no preference set, the document language came out as %s rather than the party\'s own',
                $withoutPreference['locale'],
            );
        }

        $this->connection->table('party_delivery_preferences')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'party_id' => $party->getKey(),
            'document_type' => null,
            'contact_id' => $contactId,
            'channel' => 'email',
            'address' => 'ap@example.invalid',
            'locale' => null,
            'format' => 'pdf',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $withContact = $delivery->for($this->entityId, $party, 'invoice');

        if ($withContact['locale'] !== 'en') {
            $violations[] = sprintf(
                "the named contact's language (en) did not win over the party's (ar): got %s. The contact is ".
                'the person who reads it',
                $withContact['locale'],
            );
        }

        if (! str_contains((string) $withContact['derivation'], 'from the contact')) {
            $violations[] = 'the resolution does not say where the language came from, so "why did this go '.
                'out in English" has no answer';
        }

        // A per-document-type preference beats the default one.
        $this->connection->table('party_delivery_preferences')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'party_id' => $party->getKey(),
            'document_type' => 'statement',
            'channel' => 'email',
            'address' => 'cfo@example.invalid',
            'locale' => 'ar',
            'format' => 'pdf',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $statement = $delivery->for($this->entityId, $party, 'statement');

        if ($statement['address'] !== 'cfo@example.invalid' || $statement['locale'] !== 'ar') {
            $violations[] = 'a per-document-type preference did not beat the party default, so an invoice '.
                'and a statement cannot go to different people - which is the normal arrangement';
        }

        if ($delivery->for($this->entityId, $party, 'invoice')['address'] !== 'ap@example.invalid') {
            $violations[] = 'adding a statement preference changed where invoices go';
        }

        // Two active defaults would each claim to be the answer.
        $clash = $this->refusal(fn () => $this->connection->table('party_delivery_preferences')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'party_id' => $party->getKey(),
            'document_type' => null,
            'channel' => 'print',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($clash === null) {
            $violations[] = 'two active default delivery preferences were accepted, so which one applies '.
                'depends on row order';
        }

        /*
         | A channel that needs an address must have one.
         |
         | "Email, no address" is a document that silently goes nowhere, and
         | the symptom is a customer saying they never received it - which
         | is indistinguishable from a customer who did.
         */
        $addressless = $this->refusal(fn () => $this->connection->table('party_delivery_preferences')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'party_id' => $party->getKey(),
            'document_type' => 'credit_note',
            'channel' => 'email',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($addressless === null) {
            $violations[] = 'an email delivery preference with no address was accepted, so the document goes '.
                'nowhere and nobody can tell that from a customer ignoring it';
        }

        return $violations;
    }

    /**
     * A customer with a receivable balance, posted through a rule.
     *
     * Through the poster because the receivable is a CONTROL account and the
     * ledger refuses a manual posting to one - the invariant that keeps the
     * subledger equal to its control account.
     */
    /**
     * A read of a bank account is audited - PF-035.
     *
     * ## The fraud this guards against needs no write
     *
     * Somebody who can read a supplier's IBAN can use it anywhere, and the
     * change log would show nothing because nothing changed. "Who had this
     * number" is answerable only if every disclosure was recorded - and a log
     * of writes alone answers it with silence, which reads as "nobody".
     *
     * ## Four things are asserted, and the third is the one that gets dropped
     *
     * That the read is recorded; that it is recorded under the `read`
     * category so an investigation can filter for it; that **the number is
     * not in the entry**; and that a read with no reason is refused.
     *
     * The third is the one a reasonable implementation gets wrong, because
     * putting the value in the log makes the log more useful - and doubles
     * the number of places the secret can leak from, in the one place nobody
     * can delete from.
     *
     * @return list<string>
     */
    public function readsAreAudited(): array
    {
        $violations = [];
        $disclosure = app(BankDetailDisclosure::class);

        $iban = 'SA0380000000608010167519';
        $account = $this->newAccount($iban);

        /*
         | The permission first, as somebody who does not hold it.
         |
         | Both users this suite seeds hold `controller`, which includes
         | `bank.manage` - so asserting the refusal needs a third person. A
         | receivables clerk is the realistic one: they manage parties and
         | record payments, and they have no business reading a supplier's
         | full account number.
         */
        $receivablesClerk = $this->userHolding('ar_clerk');
        $authorised = auth()->guard('web')->user();

        auth()->guard('web')->setUser($receivablesClerk);

        $refused = $this->refusal(fn () => $disclosure->ibanOf(
            $account,
            'reading it as a receivables clerk, who should not be able to',
        ));

        if ($refused === null) {
            $violations[] = 'a receivables clerk read a supplier\'s full account number. There is no '.
                'weaker "may view" permission on purpose: somebody who can see the number can use it '.
                'anywhere, and the change log would show nothing because nothing changed';
        }

        $authorised === null
            ? auth()->guard('web')->setUser($this->clerk)
            : auth()->guard('web')->setUser($authorised);

        $before = $this->connection->table('audit_log')
            ->where('category', 'read')
            ->where('subject_id', $account->getKey())
            ->count();

        $revealed = $disclosure->ibanOf($account, 'preparing the October payment run for this supplier');

        if ($revealed !== $iban) {
            $violations[] = sprintf('the disclosure returned "%s" rather than the stored account', $revealed);
        }

        $entries = $this->connection->table('audit_log')
            ->where('category', 'read')
            ->where('subject_id', $account->getKey())
            ->get(['action', 'subject_label', 'after_state', 'reason', 'party_id']);

        if ($entries->count() !== $before + 1) {
            $violations[] = sprintf(
                'reading a bank account wrote %d audit entries. Who looked at it is an auditable event, '.
                'not only who changed it',
                $entries->count() - $before,
            );

            return $violations;
        }

        $entry = $entries->last();

        if ((string) $entry->action !== 'party.bank_account.disclosed') {
            $violations[] = sprintf('the read was recorded as "%s"', (string) $entry->action);
        }

        /*
         | The number must not be in the entry, anywhere in it.
         |
         | Checked across every column rather than the ones it was expected
         | in: a log that copies the secret it is protecting has doubled the
         | places it can leak from, and this is the one place nobody can
         | delete from.
         */
        $serialised = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        if (str_contains($serialised, $iban)) {
            $violations[] = 'the audit entry for the read contains the account number. The log would then '.
                'be a second copy of the secret, in the one place nobody can delete from';
        }

        if (! str_contains($serialised, '7519')) {
            $violations[] = 'the audit entry does not carry even the last four digits, so nobody reading '.
                'it can tell which account was disclosed';
        }

        if ((string) $entry->party_id !== (string) $account->party_id) {
            $violations[] = 'the read was not recorded against the party, so "who saw this supplier\'s '.
                'account" cannot be answered from the party screen';
        }

        if (! str_contains((string) $entry->reason, 'payment run')) {
            $violations[] = 'the reason given was not recorded. The question an investigation asks is not '.
                'whether it was read but why';
        }

        $thin = $this->refusal(fn () => $disclosure->ibanOf($account, 'look'));

        if ($thin === null || ! str_contains($thin, 'at least ten characters')) {
            $violations[] = 'a read with no real reason was allowed. A reason nobody typed is a question '.
                'nobody can answer';
        }

        /*
         | And the reads are readable, by the people who own the
         | relationship.
         |
         | A read log nobody can see deters nobody, and deterrence is most of
         | what it is for.
         */
        $reads = $disclosure->readsOf($account);

        if ($reads === [] || ($reads[0]['field'] ?? '') !== 'iban') {
            $violations[] = 'the disclosure log cannot be read back, so the people who own the '.
                'relationship cannot see who looked at their supplier\'s account';
        }

        return $violations;
    }

    private function customerOwing(string $amount): Party
    {
        $party = Party::create([
            'legal_name' => 'Credit probe '.bin2hex(random_bytes(3)),
            'country' => 'SA',
            'is_customer' => true,
            'default_currency' => 'SAR',
        ]);

        app(Poster::class)->post(
            entity: $this->entity,
            ruleKey: CreditCheckSaleRule::KEY,
            event: [
                'amount' => $amount,
                'party' => (string) $party->getKey(),
                'revenue' => $this->revenueAccountId,
            ],
            accountingDate: Carbon::parse($this->postingDate),
        );

        return $party;
    }

    private function setLimit(Party $party, string $limit): void
    {
        $this->connection->table('party_entity_settings')->updateOrInsert(
            ['entity_id' => $this->entityId, 'party_id' => $party->getKey()],
            [
                'id' => (string) Str::ulid(),
                'credit_limit' => $limit,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * A write expected to be refused, in its own savepoint.
     *
     * A refused statement aborts the whole transaction, not just itself.
     */
    private function refusal(callable $write): ?string
    {
        try {
            $this->connection->transaction(function () use ($write): void {
                $write();

                throw new PartyWriteAccepted();
            });
        } catch (PartyWriteAccepted) {
            return null;
        } catch (Throwable $e) {
            return (string) preg_replace('/\s+/', ' ', $e->getMessage());
        }

        return null;
    }

    /**
     * A user holding one seeded role, for a permission assertion.
     *
     * Both users the suite seeds hold `controller`, which includes
     * `bank.manage` - so asserting a refusal needs somebody who does not.
     */
    private function userHolding(string $role): User
    {
        $user = User::create([
            'name' => 'Party '.$role,
            'email' => $role.'-'.bin2hex(random_bytes(5)).'@invalid.test',
            'password' => Str::password(32),
            'active' => true,
        ]);

        UserEntityRole::create([
            'user_id' => $user->getKey(),
            'entity_id' => $this->entityId,
            'role_id' => Role::query()->where('key', $role)->firstOrFail()->getKey(),
        ]);

        return $user;
    }

    private function newAccount(string $iban): \App\Tenant\Parties\Models\PartyBankAccount
    {
        auth()->guard('web')->setUser($this->clerk);

        $party = Party::create([
            'legal_name' => 'Probe Supplier '.bin2hex(random_bytes(3)),
            'country' => 'SA',
            'is_supplier' => true,
        ]);

        return app(BankDetailRegistry::class)->record($party, iban: $iban, bic: 'RIBLSARI');
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'PTY'.random_int(100, 999),
            'legal_name' => 'Party check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entity = $entity;
        $this->entityId = (string) $entity->getKey();
        $context->use($entity);

        /*
         | A ledger the credit checks can post into.
         |
         | The receivable is a CONTROL account and the ledger refuses a
         | manual posting to one, so exposure cannot be faked by inserting a
         | line - it has to be posted through a rule, like the product does.
         */
        $postingDate = Carbon::now()->startOfYear()->addMonths(2)->addDays(9);

        $period = app(FiscalCalendarGenerator::class)->periodFor($this->entityId, $postingDate);

        if ($period !== null) {
            // Through the state machine: a period's state cannot move
            // without a recorded transition, and a bare update is refused.
            app(PeriodStateMachine::class)->transition(
                $period,
                PeriodState::Open,
                'opened for the party enforcement checks',
            );
        }

        $this->postingDate = $postingDate->toDateString();

        $this->revenueAccountId = (string) Account::query()
            ->where('entity_id', $this->entityId)
            ->where('code', '4.1')
            ->value('id');

        app(PostingRuleRegistry::class)->register(new CreditCheckSaleRule());

        $role = Role::query()->where('key', 'controller')->firstOrFail();

        foreach (['clerk', 'approver'] as $which) {
            $user = User::create([
                'name' => 'Party '.$which,
                'email' => $which.'-'.bin2hex(random_bytes(5)).'@invalid.test',
                'password' => Str::password(32),
                'active' => true,
            ]);

            UserEntityRole::create([
                'user_id' => $user->getKey(),
                'entity_id' => $this->entityId,
                'role_id' => $role->getKey(),
            ]);

            $this->{$which} = $user;
        }

        // The credit checks post and audit, both of which read the guard.
        auth()->guard('web')->setUser($this->clerk);
    }

    private function tenant(): ?Tenant
    {
        try {
            return Tenant::query()->with('connection')->whereHas('connection')->orderBy('slug')->first();
        } catch (Throwable) {
            return null;
        }
    }
}

/**
 * A sale, so the credit checks can create a real receivable balance.
 *
 * Posted through a rule because the receivable is a control account and the
 * ledger refuses a manual posting to one. Faking exposure with a direct
 * insert would make the check pass while proving the ledger accepts
 * something it must not.
 */
final class CreditCheckSaleRule implements PostingRule
{
    public const KEY = 'check.credit.sale';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(self::KEY, 'بيع للفحص', 'A sale, for the checks', [
            ['Dr', ControlRole::AccountsReceivable, 'the total'],
            ['Cr', 'revenue', 'the same'],
        ]);
    }

    /**
     * @param  array{amount: string, party: string, revenue: string}  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        return [
            PostingLine::toRole(
                ControlRole::AccountsReceivable,
                $context->money($event['amount']),
                partyId: $event['party'],
            ),
            PostingLine::toAccount($event['revenue'], $context->money($event['amount'])->negated()),
        ];
    }
}

/** Thrown to roll back a write the database ACCEPTED. */
final class PartyWriteAccepted extends \RuntimeException {}
