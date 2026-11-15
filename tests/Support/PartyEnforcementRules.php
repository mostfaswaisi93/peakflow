<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Support\Money\Money;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;
use App\Tenant\Parties\Exceptions\BankAccountNotVerified;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Exceptions\CreditRefused;
use App\Tenant\Parties\Services\BankDetailRegistry;
use App\Tenant\Parties\Services\CreditControl;
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
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
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

            return [
                'a bank account number never becomes an attribute' => $this->numberIsNotAnAttribute(),
                'a new or changed account is unusable until verified' => $this->changesRequireVerification(),
                'the person who changed an account cannot verify it' => $this->verifierIsNotTheAuthor(),
                'a bank number never reaches the audit log' => $this->numberNeverReachesTheLog(),
                'exposure counts uninvoiced orders, not just receivables' => $this->exposureIsComplete(),
                'a credit hold cannot exist without an author' => $this->holdsHaveAnAuthor(),
                'an override is bounded, justified and raises TO not BY' => $this->overridesAreBounded(),
                'a dispute suspends dunning and keeps the debt' => $this->disputesSuspendOnly(),
            ];
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
     * A customer with a receivable balance, posted through a rule.
     *
     * Through the poster because the receivable is a CONTROL account and the
     * ledger refuses a manual posting to one - the invariant that keeps the
     * subledger equal to its control account.
     */
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

        app(FiscalCalendarGenerator::class)
            ->periodFor($this->entityId, $postingDate)
            ?->update(['state' => 'open']);

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
