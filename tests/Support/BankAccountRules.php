<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Banking\Models\BankAccount;
use App\Tenant\Banking\Services\BankAccounts;
use App\Tenant\Banking\Services\BankBalances;
use App\Tenant\Banking\Services\StatementImport;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bank accounts, and the three balances - PF-145, PF-146.
 *
 * ## What is actually being checked
 *
 * Not "can an account be saved". The two things that make this area either
 * trustworthy or quietly wrong:
 *
 * 1. **The account is reachable and the identifier is right.** An IBAN that
 *    fails its check digits, or belongs to a different country than the
 *    institution, is a payment file the first bank rejects - hours later,
 *    by which time the window is missed.
 * 2. **The three balances diverge, and the difference is itemised.** A
 *    system that reports one cash figure has deleted the question
 *    reconciliation exists to answer, and one that reports the difference
 *    without its parts makes somebody rebuild the parts by hand every
 *    month.
 *
 * The divergence check is the load-bearing one. PF-146 asks specifically
 * that all three differ correctly after a payment is released and before
 * the statement arrives, because that is the normal state of any business
 * that pays anybody - not an error to be flagged.
 */
final class BankAccountRules
{
    use \Tests\Support\Concerns\OpensBankAccounts;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['bank accounts (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheBankSide();

            return $this->runEachInASavepoint([
                // ---- The account, and whether it can be paid into --------
                'an account opens and its IBAN reads back' => fn (): array => $this->opens(),
                'the identifier is not stored in the clear' => fn (): array => $this->encrypted(),
                'and only its last four characters are shown' => fn (): array => $this->tailOnly(),
                'an IBAN that fails its check digits is refused' => fn (): array => $this->badIban(),
                'an IBAN from another country is refused here' => fn (): array => $this->foreignIban(),
                'an account with no identifier at all is refused' => fn (): array => $this->unreachable(),
                'a domestic identifier alone is enough' => fn (): array => $this->domesticOnly(),
                'and one that fails its check digit is refused' => fn (): array => $this->badRouting(),
                'a country with no domestic scheme says so' => fn (): array => $this->noScheme(),
                'an invalid BIC is refused' => fn (): array => $this->badBic(),

                // ---- The GL link ----------------------------------------
                'a GL account outside the bank subtree is refused' => fn (): array => $this->wrongRole(),
                'and the database refuses it too' => fn (): array => $this->wrongRoleInTheDatabase(),
                'and a sub-account under it is accepted' => fn (): array => $this->subAccountIsAccepted(),
                'a header account cannot be mirrored' => fn (): array => $this->headerAccount(),
                'and the database refuses that too' => fn (): array => $this->headerInTheDatabase(),
                'a GL account that does not exist is refused' => fn (): array => $this->missingGl(),
                'two bank accounts cannot share one GL account' => fn (): array => $this->sharedGl(),
                'the GL link cannot be re-pointed' => fn (): array => $this->glLinkIsFixed(),
                'opening an account posts nothing' => fn (): array => $this->opensPostNothing(),

                // ---- The three balances - PF-146 ------------------------
                'the three balances are reported together' => fn (): array => $this->three(),
                'the components add up to the difference' => fn (): array => $this->componentsAddUp(),
                'all three diverge after a payment is released' => fn (): array => $this->divergence(),
                'available ignores a receipt the bank has not credited' => fn (): array => $this->availableIsConservative(),
                'an unposted opening balance is named' => fn (): array => $this->unpostedOpening(),
                'and stops being named once it is posted' => fn (): array => $this->postedOpening(),
                'an unrecorded bank item is a component' => fn (): array => $this->unrecordedIsAComponent(),
                'a deposit in transit is a component too' => fn (): array => $this->depositInTransit(),
                'the statement balance ignores a superseded one' => fn (): array => $this->supersededIsIgnored(),
                'the ledger balance stops at the date asked for' => fn (): array => $this->ledgerAsOf(),

                // ---- The opening balance, and closing ------------------
                'a refusal from the database carries no plumbing' => fn (): array => $this->refusalsAreClean(),
                'the opening balance can be corrected before a statement' => fn (): array => $this->correctable(),
                'and not after one has been imported' => fn (): array => $this->notCorrectableAfter(),
                'which the database insists on as well' => fn (): array => $this->openingBalanceInTheDatabase(),
                'correcting it needs a reason' => fn (): array => $this->correctionNeedsAReason(),
                'an account with a balance cannot be closed' => fn (): array => $this->closingWithABalance(),
                'and an empty one can' => fn (): array => $this->closingAnEmptyOne(),

                // ---- Isolation -----------------------------------------
                'another entity cannot see this account' => fn (): array => $this->entityScope(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    // ---- The account ------------------------------------------------------

    /** @return list<string> */
    private function opens(): array
    {
        $account = $this->bank();
        $failures = [];

        if ((string) ($account->iban() ?? '') !== self::SA_IBAN) {
            $failures[] = sprintf('the IBAN read back as "%s"', (string) ($account->iban() ?? 'null'));
        }

        if ((string) $account->status !== 'active') {
            $failures[] = 'a newly opened account is not active';
        }

        if ((string) $account->gl_account_id !== $this->bankGlAccountId) {
            $failures[] = 'the GL link does not point at the account it was given';
        }

        if ((string) $account->currency !== 'SAR') {
            $failures[] = 'the currency was not stored';
        }

        return $failures;
    }

    /** @return list<string> */
    private function encrypted(): array
    {
        $raw = DB::connection('tenant')->table('bank_accounts')
            ->where('id', (string) $this->bank()->getKey())
            ->first(['iban_encrypted', 'iban_tail']);

        $failures = [];

        if ($raw === null) {
            return ['the account row could not be read back'];
        }

        /*
         | The stored value must not contain the IBAN.
         |
         | Checked against the column rather than through the model, because
         | the model is the thing that would be doing the encrypting - asking
         | it would be asking the suspect.
         */
        if (str_contains((string) $raw->iban_encrypted, self::SA_IBAN)) {
            $failures[] = 'the IBAN is recoverable from the stored column without decrypting it';
        }

        if (str_contains((string) $raw->iban_encrypted, substr(self::SA_IBAN, 2, 14))) {
            $failures[] = 'a substantial run of the IBAN appears in the stored column';
        }

        if ((string) $raw->iban_tail !== substr(self::SA_IBAN, -4)) {
            $failures[] = 'the tail was not stored alongside, so the account cannot be identified at all';
        }

        $hidden = $this->bank()->toArray();

        foreach (['iban_encrypted', 'domestic_identifier_encrypted', 'account_number_encrypted'] as $column) {
            if (\array_key_exists($column, $hidden)) {
                $failures[] = sprintf('%s is serialised by the model, so it reaches any JSON response', $column);
            }
        }

        /*
         | And the audit record carries the tail, not the identifier.
         |
         | An audit log is read by more people than the account screen is,
         | exported more often, and retained longer - so it is the place an
         | identifier survives longest and travels furthest. The tail is
         | there because somebody reading the log still has to know which
         | account this was.
         */
        $record = DB::connection('tenant')->table('audit_log')
            ->where('subject_type', 'bank_account')
            ->where('action', 'bank_account.opened')
            ->orderByDesc('created_at')
            ->first(['after_state', 'subject_label']);

        if ($record === null) {
            return [...$failures, 'opening an account wrote no audit record'];
        }

        $written = (string) $record->after_state.' '.(string) $record->subject_label;

        if (str_contains($written, self::SA_IBAN)) {
            $failures[] = 'the audit record carries the whole IBAN';
        }

        if (str_contains($written, substr(self::SA_IBAN, 2, 14))) {
            $failures[] = 'the audit record carries a substantial run of the IBAN';
        }

        if (! str_contains($written, substr(self::SA_IBAN, -4))) {
            $failures[] = 'the audit record does not identify which account it was about';
        }

        return $failures;
    }

    /** @return list<string> */
    private function tailOnly(): array
    {
        $short = $this->bank()->shortIdentifier();

        $failures = [];

        if (str_contains($short, substr(self::SA_IBAN, 0, 10))) {
            $failures[] = sprintf('the short identifier "%s" carries the full IBAN', $short);
        }

        if (! str_contains($short, substr(self::SA_IBAN, -4))) {
            $failures[] = sprintf('the short identifier "%s" does not identify the account', $short);
        }

        return $failures;
    }

    /** @return list<string> */
    private function badIban(): array
    {
        // One pair transposed: the length and the country are right.
        $transposed = 'SA0380000000608010167591';

        $refusal = $this->refusal(fn () => $this->openAccount(
            iban: $transposed,
            glAccountId: $this->secondBankGlAccountId(),
            label: $this->uniqueLabel('Transposed'),
        ));

        if ($refusal === null) {
            return ['an IBAN that fails its check digits was accepted'];
        }

        return str_contains($refusal, 'check digits')
            ? []
            : [sprintf('the refusal does not mention the check digits: %s', $refusal)];
    }

    /** @return list<string> */
    private function foreignIban(): array
    {
        $refusal = $this->refusal(fn () => $this->openAccount(
            iban: self::DE_IBAN,
            glAccountId: $this->secondBankGlAccountId(),
            institutionCountry: 'SA',
            label: $this->uniqueLabel('German IBAN'),
        ));

        if ($refusal === null) {
            return ['a German IBAN was accepted against a Saudi institution'];
        }

        $failures = [];

        if (! str_contains($refusal, 'DE')) {
            $failures[] = sprintf('the refusal does not name the IBAN\'s country: %s', $refusal);
        }

        /*
         | The refusal has to say WHICH of the two is wrong, or that it
         | cannot tell.
         |
         | A message that just says "mismatch" leaves somebody changing the
         | institution to match the IBAN, which is the wrong half half the
         | time.
         */
        if (! str_contains($refusal, 'wrong IBAN or the wrong institution')) {
            $failures[] = 'the refusal does not say that either of the two could be the mistake';
        }

        return $failures;
    }

    /** @return list<string> */
    private function unreachable(): array
    {
        $refusal = $this->refusal(fn () => $this->openAccount(
            iban: null,
            glAccountId: $this->secondBankGlAccountId(),
            label: $this->uniqueLabel('Unreachable'),
        ));

        if ($refusal === null) {
            return ['an account with no IBAN and no domestic identifier was accepted'];
        }

        return str_contains($refusal, 'nobody can pay into')
            ? []
            : [sprintf('the refusal does not say what is wrong with the record: %s', $refusal)];
    }

    /** @return list<string> */
    private function domesticOnly(): array
    {
        $gl = $this->secondBankGlAccountId();

        if ($gl === '') {
            return ['the chart has only one bank-role account, so this could not be checked'];
        }

        try {
            $account = $this->openAccount(
                iban: null,
                glAccountId: $gl,
                institutionCountry: 'US',
                currency: 'USD',
                domesticScheme: null,
                domesticIdentifier: self::US_ROUTING,
                bic: null,
                label: $this->uniqueLabel('US operating'),
            );
        } catch (\Throwable $e) {
            return [sprintf('an account identified by a routing number alone was refused: %s', $e->getMessage())];
        }

        $failures = [];

        if ((string) $account->domestic_scheme !== 'us_routing') {
            $failures[] = sprintf(
                'the scheme was stored as "%s" rather than being derived from the country',
                (string) $account->domestic_scheme,
            );
        }

        if ($account->domesticIdentifier() !== self::US_ROUTING) {
            $failures[] = 'the routing number did not read back';
        }

        if (str_contains($account->shortIdentifier(), substr(self::US_ROUTING, 0, 5))) {
            $failures[] = 'the short identifier carries the whole routing number';
        }

        return $failures;
    }

    /** @return list<string> */
    private function badRouting(): array
    {
        $gl = $this->secondBankGlAccountId();

        if ($gl === '') {
            return [];
        }

        // 021000021 is valid; the last digit changed breaks the ABA check.
        $refusal = $this->refusal(fn () => $this->openAccount(
            iban: null,
            glAccountId: $gl,
            institutionCountry: 'US',
            currency: 'USD',
            domesticIdentifier: '021000022',
            bic: null,
            label: $this->uniqueLabel('Bad routing'),
        ));

        if ($refusal === null) {
            return ['a routing number that fails its ABA check digit was accepted'];
        }

        return str_contains($refusal, 'check digit')
            ? []
            : [sprintf('the refusal does not mention the check digit: %s', $refusal)];
    }

    /** @return list<string> */
    private function noScheme(): array
    {
        $gl = $this->secondBankGlAccountId();

        if ($gl === '') {
            return [];
        }

        /*
         | Saudi Arabia has no domestic scheme in PeakFlow, by design: it
         | uses IBAN. Asking for a domestic identifier there should say so
         | rather than invent a scheme.
         */
        $refusal = $this->refusal(fn () => $this->openAccount(
            iban: null,
            glAccountId: $gl,
            institutionCountry: 'SA',
            domesticIdentifier: '1234567890',
            bic: null,
            label: $this->uniqueLabel('No scheme'),
        ));

        if ($refusal === null) {
            return ['a domestic identifier was accepted for a country with no domestic scheme'];
        }

        return str_contains($refusal, 'IBAN')
            ? []
            : [sprintf('the refusal does not say what identifies an account there: %s', $refusal)];
    }

    /** @return list<string> */
    private function badBic(): array
    {
        $gl = $this->secondBankGlAccountId();

        if ($gl === '') {
            return [];
        }

        // Nine characters: between the institution form and the branch form.
        $refusal = $this->refusal(fn () => $this->openAccount(
            iban: self::SA_IBAN_OTHER,
            glAccountId: $gl,
            bic: 'RJHISARIX',
            label: $this->uniqueLabel('Bad BIC'),
        ));

        if ($refusal === null) {
            return ['a nine-character BIC was accepted'];
        }

        return str_contains($refusal, 'eight') && str_contains($refusal, 'eleven')
            ? []
            : [sprintf('the refusal does not state the two valid lengths: %s', $refusal)];
    }

    // ---- The GL link ------------------------------------------------------

    /** @return list<string> */
    private function wrongRole(): array
    {
        $outside = $this->glAccountOutsideTheBankSubtree();

        if ($outside === '') {
            return ['no postable account outside the bank subtree was found, so this could not be checked'];
        }

        $refusal = $this->refusal(fn () => $this->openAccount(
            iban: self::SA_IBAN_OTHER,
            glAccountId: $outside,
            label: $this->uniqueLabel('Outside the subtree'),
        ));

        if ($refusal === null) {
            return ['a GL account outside the bank subtree was accepted as a bank account'];
        }

        $failures = [];

        /*
         | The SERVICE's refusal, in the service's own words.
         |
         | The trigger guards this too, and `open()` translates its
         | QueryException into a BankAccountRefused - so neither the
         | behaviour nor the exception class distinguishes the two layers,
         | and a check asserting either passed with the service's guard
         | removed.
         |
         | What distinguishes them is what each is FOR. The database refuses
         | and names the invariant; the service refuses and teaches the
         | route. So the assertion is on the teaching sentence, which only
         | the service has - and without it the only visible way to add a
         | second bank account is to move the `bank` role, which breaks
         | every posting rule that credits the bank.
         */
        if (! str_contains($refusal, 'sub-account under')) {
            $failures[] = sprintf(
                'the refusal does not say how to add a second bank account, so somebody facing it '.
                'will move the bank role instead and break every rule that credits the bank: %s',
                $refusal,
            );
        }

        if (! str_contains($refusal, 'reports as cash')) {
            $failures[] = sprintf('the refusal does not say what the subtree means: %s', $refusal);
        }

        if (! str_contains($refusal, 'BankAccountRefused')) {
            $failures[] = sprintf('the refusal is not the service\'s own exception type: %s', $refusal);
        }

        return $failures;
    }

    /** @return list<string> */
    private function subAccountIsAccepted(): array
    {
        $child = $this->secondBankGlAccountId();

        if ($child === '') {
            return ['a sub-account under the bank account could not be created'];
        }

        /*
         | Two bank accounts on one entity: the ordinary case.
         |
         | `account_roles` permits one `bank` role per entity, so requiring
         | the role on every bank account's GL link would cap an entity at
         | one bank account for ever - and a SAR operating account alongside
         | a USD one is not an edge case.
         */
        try {
            $second = $this->openAccount(
                openingBalance: '0',
                iban: self::SA_IBAN_OTHER,
                glAccountId: $child,
                label: $this->uniqueLabel('Second account'),
            );
        } catch (\Throwable $e) {
            return [sprintf(
                'a second bank account mirroring a sub-account of the chart\'s bank account was '.
                'refused, which caps an entity at one bank account: %s',
                $e->getMessage(),
            )];
        }

        $failures = [];

        if ((string) $second->gl_account_id !== $child) {
            $failures[] = 'the second account did not keep its own GL link';
        }

        if ((string) $second->getKey() === (string) $this->bank()->getKey()) {
            $failures[] = 'the second account is the first one';
        }

        $count = BankAccount::query()->where('entity_id', $this->entityId)->count();

        if ($count !== 2) {
            $failures[] = sprintf('the entity has %d bank accounts rather than two', $count);
        }

        return $failures;
    }

    /** @return list<string> */
    private function headerAccount(): array
    {
        $header = (string) DB::connection('tenant')->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('postable', false)
            ->orderBy('code')
            ->value('id');

        if ($header === '') {
            return ['no header account was found, so this could not be checked'];
        }

        $refusal = $this->refusal(fn () => $this->openAccount(
            iban: self::SA_IBAN_OTHER,
            glAccountId: $header,
            label: $this->uniqueLabel('Header'),
        ));

        if ($refusal === null) {
            return [
                'a bank account was allowed to mirror a header account, whose ledger balance is '.
                'permanently zero - the failure would surface on the first payment, as a refused '.
                'posting with nothing to do with banking',
            ];
        }

        $failures = [];

        if (! str_contains($refusal, 'BankAccountRefused')) {
            $failures[] = sprintf(
                'the service let the database refuse this instead of refusing it itself: %s',
                $refusal,
            );
        }

        if (! str_contains($refusal, 'header')) {
            $failures[] = sprintf('the refusal does not say the account is a header: %s', $refusal);
        }

        return $failures;
    }

    /** @return list<string> */
    private function missingGl(): array
    {
        $refusal = $this->refusal(fn () => $this->openAccount(
            iban: self::SA_IBAN_OTHER,
            glAccountId: (string) Str::ulid(),
            label: $this->uniqueLabel('Missing GL'),
        ));

        if ($refusal === null) {
            return ['an account was opened against a GL account that does not exist'];
        }

        /*
         | And it must NOT offer to create one.
         |
         | Creating the GL account as a side effect is how an entity ends up
         | with four accounts carrying the bank role and nobody knowing which
         | one the trial balance means.
         */
        return str_contains($refusal, 'already exists') || str_contains($refusal, 'mirrors one')
            ? []
            : [sprintf('the refusal does not say the GL account must exist first: %s', $refusal)];
    }

    /** @return list<string> */
    private function sharedGl(): array
    {
        $refusal = $this->refusal(fn () => $this->openAccount(
            iban: self::SA_IBAN_OTHER,
            glAccountId: $this->bankGlAccountId,
            label: $this->uniqueLabel('Second on one GL'),
        ));

        if ($refusal === null) {
            return ['two bank accounts were allowed to share one GL account'];
        }

        return str_contains($refusal, 'reconciled separately')
            ? []
            : [sprintf('the refusal does not say why sharing one is unworkable: %s', $refusal)];
    }

    /** @return list<string> */
    private function glLinkIsFixed(): array
    {
        $other = $this->secondBankGlAccountId();

        if ($other === '') {
            return ['the chart has only one bank-role account, so re-pointing could not be checked'];
        }

        /*
         | Attempted in the DATABASE, not through the service.
         |
         | A service-level guard is the one somebody bypasses with an
         | `update()` in a console command at 7pm. The trigger is what makes
         | re-pointing impossible rather than discouraged.
         */
        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('bank_accounts')
            ->where('id', (string) $this->bank()->getKey())
            ->update(['gl_account_id' => $other]));

        if ($refusal === null) {
            return ['the GL link was re-pointed with a direct update'];
        }

        return str_contains($refusal, 'without a single journal entry')
            || str_contains($refusal, 'moves history')
            || str_contains($refusal, 'cannot be changed')
            ? []
            : [sprintf('the refusal does not say what re-pointing would do: %s', $refusal)];
    }

    /** @return list<string> */
    private function opensPostNothing(): array
    {
        $gl = $this->secondBankGlAccountId();

        if ($gl === '') {
            return [];
        }

        $before = DB::connection('tenant')->table('journal_entries')->count();

        $this->openAccount(
            openingBalance: '12345.0000',
            iban: self::SA_IBAN_OTHER,
            glAccountId: $gl,
            label: $this->uniqueLabel('Posts nothing'),
        );

        $after = DB::connection('tenant')->table('journal_entries')->count();

        return $after === $before
            ? []
            : [sprintf(
                'opening an account wrote %d journal entries - an opening balance needs a date, a '.
                'counter-account and an approval, none of which a bank-account form has',
                $after - $before,
            )];
    }

    // ---- The three balances ----------------------------------------------

    /** @return list<string> */
    private function three(): array
    {
        $balances = app(BankBalances::class)->all($this->bank(), $this->date->copy());

        $failures = [];

        foreach (['ledger', 'statement', 'available', 'reconciliation', 'as_of', 'currency'] as $key) {
            if (! \array_key_exists($key, $balances)) {
                $failures[] = sprintf('the report has no "%s"', $key);
            }
        }

        foreach (['unpresented_payments', 'unsettled_receipts', 'unrecorded_bank_items',
            'unposted_opening_balance', 'unexplained'] as $component) {
            if (! \array_key_exists($component, (array) $balances['reconciliation']['components'])) {
                $failures[] = sprintf('the difference is not itemised into "%s"', $component);
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function componentsAddUp(): array
    {
        /*
         | Every component, with every mechanism in play at once.
         |
         | The identity is the thing most likely to be quietly wrong - a
         | single flipped sign reports exactly twice the item as unexplained,
         | which reads as a second error somewhere else. Checking it with one
         | component at a time would let two compensating sign errors pass.
         */
        $this->postTheOpeningEntry();
        $this->importFirstStatement();
        $this->releaseAPayment();
        $this->bankAReceipt();

        $balances = app(BankBalances::class)->all($this->bank(), $this->date->copy());
        $reconciliation = $balances['reconciliation'];

        $total = BigDecimal::zero();

        foreach ((array) $reconciliation['components'] as $amount) {
            $total = $total->plus((string) $amount);
        }

        $failures = [];

        if (! $total->isEqualTo((string) $reconciliation['difference'])) {
            $failures[] = sprintf(
                'the components total %s and the difference is %s, so a screen that adds them up '.
                'disagrees with the headline figure',
                (string) $total,
                (string) $reconciliation['difference'],
            );
        }

        if ((string) $reconciliation['components']['unexplained'] !== '0.0000') {
            $failures[] = sprintf(
                'everything was accounted for and %s is still reported as unexplained',
                (string) $reconciliation['components']['unexplained'],
            );
        }

        if ($reconciliation['agrees'] !== true) {
            $failures[] = 'the reconciliation does not agree although every difference is explained';
        }

        return $failures;
    }

    /**
     * PF-146's own criterion.
     *
     * @return list<string>
     */
    private function divergence(): array
    {
        $this->postTheOpeningEntry();
        $this->importFirstStatement();

        $before = app(BankBalances::class)->all($this->bank(), $this->date->copy());

        $this->releaseAPayment(gross: '3500.0000', withheld: '500.0000');

        $after = app(BankBalances::class)->all($this->bank(), $this->date->copy());

        $failures = [];

        // 3,500 gross less 500 withheld: 3,000 actually left the account.
        $expectedLedger = BigDecimal::of((string) $before['ledger'])->minus('3000.0000');

        if (! BigDecimal::of((string) $after['ledger'])->isEqualTo($expectedLedger)) {
            $failures[] = sprintf(
                'the ledger moved to %s and should have moved to %s',
                (string) $after['ledger'],
                (string) $expectedLedger,
            );
        }

        if ((string) $after['statement'] !== (string) $before['statement']) {
            $failures[] = 'the statement balance moved when a payment was released, which the bank has not seen';
        }

        $expectedAvailable = BigDecimal::of((string) $before['available'])->minus('3000.0000');

        if (! BigDecimal::of((string) $after['available'])->isEqualTo($expectedAvailable)) {
            $failures[] = sprintf(
                'the available balance is %s and should be %s - money committed and not yet taken is '.
                'not available, and reporting it as available is an overdraft',
                (string) $after['available'],
                (string) $expectedAvailable,
            );
        }

        /*
         | All three distinct, and the state still "agrees".
         |
         | This is the heart of it: a released payment is Tuesday, not an
         | exception. A system that flagged it would be flagging its own
         | normal operation, and the flag would be turned off within a week.
         */
        $distinct = \count(array_unique([
            (string) $after['ledger'],
            (string) $after['statement'],
            (string) $after['available'],
        ]));

        if ($distinct !== 3) {
            $failures[] = sprintf(
                'only %d of the three balances differ after a payment was released (ledger %s, '.
                'statement %s, available %s) - which is the normal state and must be visible',
                $distinct,
                (string) $after['ledger'],
                (string) $after['statement'],
                (string) $after['available'],
            );
        }

        if ($after['reconciliation']['agrees'] !== true) {
            $failures[] = 'a released payment is reported as an unexplained difference rather than as a component';
        }

        if ((string) $after['reconciliation']['components']['unpresented_payments'] !== '-3000.0000') {
            $failures[] = sprintf(
                'the released payment is itemised as %s rather than -3000.0000',
                (string) $after['reconciliation']['components']['unpresented_payments'],
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function availableIsConservative(): array
    {
        $this->postTheOpeningEntry();
        $this->importFirstStatement();

        $before = app(BankBalances::class)->all($this->bank(), $this->date->copy());

        // 2,000 gross less 100 withheld: 1,900 arrived in the ledger.
        $this->bankAReceipt(gross: '2000.0000', withheld: '100.0000');

        $after = app(BankBalances::class)->all($this->bank(), $this->date->copy());

        $failures = [];

        if ((string) $after['available'] !== (string) $before['available']) {
            $failures[] = sprintf(
                'the available balance rose from %s to %s on a receipt the bank has not credited - a '.
                'deposit paid in this morning is not money to spend this afternoon, and the whole '.
                'point of a separate available figure is that it is the safe one',
                (string) $before['available'],
                (string) $after['available'],
            );
        }

        if (BigDecimal::of((string) $after['ledger'])->isEqualTo((string) $before['ledger'])) {
            $failures[] = 'the ledger did not move on a banked receipt, so nothing was actually posted';
        }

        if ((string) $after['reconciliation']['components']['unsettled_receipts'] !== '1900.0000') {
            $failures[] = sprintf(
                'the deposit in transit is itemised as %s rather than 1900.0000',
                (string) $after['reconciliation']['components']['unsettled_receipts'],
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function unpostedOpening(): array
    {
        $balances = app(BankBalances::class)->all($this->bank(), $this->date->copy());
        $reconciliation = $balances['reconciliation'];

        $failures = [];

        if ((string) $reconciliation['components']['unposted_opening_balance'] !== '-50000.0000') {
            $failures[] = sprintf(
                'the unposted opening balance is itemised as %s rather than -50000.0000',
                (string) $reconciliation['components']['unposted_opening_balance'],
            );
        }

        if ((string) $reconciliation['components']['unexplained'] !== '0.0000') {
            $failures[] = sprintf(
                'the opening balance shows up as %s of unexplained difference, which is the first '.
                'thing anybody sees after opening an account and sends them hunting for a mistake '.
                'that is not there',
                (string) $reconciliation['components']['unexplained'],
            );
        }

        if (! str_contains((string) $reconciliation['why'], 'post the opening journal entry')) {
            $failures[] = sprintf(
                'the explanation does not say what to do about it: %s',
                (string) $reconciliation['why'],
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function postedOpening(): array
    {
        $this->postTheOpeningEntry();

        $balances = app(BankBalances::class)->all($this->bank(), $this->date->copy());
        $reconciliation = $balances['reconciliation'];

        $failures = [];

        if ((string) $reconciliation['components']['unposted_opening_balance'] !== '0.0000') {
            $failures[] = sprintf(
                'the opening balance is still being reported as unposted (%s) after the entry was '.
                'posted, so the explanation would outlive its cause',
                (string) $reconciliation['components']['unposted_opening_balance'],
            );
        }

        if ($reconciliation['why'] !== null) {
            $failures[] = sprintf(
                'an account that reconciles exactly still carries an explanation: %s',
                (string) $reconciliation['why'],
            );
        }

        if ((string) $balances['ledger'] !== '50000.0000') {
            $failures[] = sprintf('the ledger is %s after posting the opening balance', (string) $balances['ledger']);
        }

        return $failures;
    }

    /** @return list<string> */
    private function unrecordedIsAComponent(): array
    {
        $this->postTheOpeningEntry();
        $this->importFirstStatement();

        $balances = app(BankBalances::class)->all($this->bank(), $this->date->copy());
        $reconciliation = $balances['reconciliation'];

        $failures = [];

        /*
         | The statement's four lines net +1,500 and nothing matches them.
         |
         | So the bank is 1,500 ahead of the ledger, and that difference is
         | the direction a naive reconciliation forgets. Reported as a
         | component, the next step is to find the documents; reported as
         | unexplained, the next step is usually to adjust the ledger to
         | match the bank - which posts a number with no document behind it.
         */
        if ((string) $reconciliation['components']['unrecorded_bank_items'] !== '-1500.0000') {
            $failures[] = sprintf(
                'the bank items nothing records are itemised as %s rather than -1500.0000',
                (string) $reconciliation['components']['unrecorded_bank_items'],
            );
        }

        if ((string) $reconciliation['components']['unexplained'] !== '0.0000') {
            $failures[] = sprintf(
                'an imported transaction with no match is reported as %s of unexplained difference',
                (string) $reconciliation['components']['unexplained'],
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function depositInTransit(): array
    {
        $this->postTheOpeningEntry();
        $this->importFirstStatement();
        $this->bankAReceipt(gross: '2000.0000', withheld: '100.0000');

        $reconciliation = app(BankBalances::class)
            ->all($this->bank(), $this->date->copy())['reconciliation'];

        return $reconciliation['agrees'] === true
            ? []
            : [sprintf(
                'a receipt banked and not yet credited leaves %s unexplained - the other half of the '.
                'classic reconciliation, and the half a system that tracks only outstanding payments '.
                'reports as a mystery every month',
                (string) $reconciliation['components']['unexplained'],
            )];
    }

    /** @return list<string> */
    private function supersededIsIgnored(): array
    {
        $this->postTheOpeningEntry();
        $first = $this->importFirstStatement();

        $balance = app(BankBalances::class)->statement($this->bank(), $this->date->copy());

        if ((string) $balance->amount !== '51500.0000') {
            return [sprintf('the statement balance is %s before anything was superseded', (string) $balance->amount)];
        }

        $statement = $this->statementOf((string) $first['statement_id']);

        if ($statement === null) {
            return ['the imported statement could not be read back'];
        }

        DB::connection('tenant')->table('bank_statements')
            ->where('id', (string) $statement->getKey())
            ->update(['status' => 'superseded']);

        $after = app(BankBalances::class)->statement($this->bank(), $this->date->copy());

        /*
         | With the only statement superseded, the bank's figure falls back
         | to the account's own opening balance - not to zero.
         |
         | An account opened with 50,000 in it does not have a statement
         | balance of nothing, and reporting zero would make every screen
         | show a business with no cash until its first import.
         */
        return (string) $after->amount === '50000.0000'
            ? []
            : [sprintf(
                'with its only statement superseded the account reports a statement balance of %s '.
                'rather than its opening balance of 50000.0000',
                (string) $after->amount,
            )];
    }

    /** @return list<string> */
    private function ledgerAsOf(): array
    {
        $this->postTheOpeningEntry();
        $this->releaseAPayment(on: $this->date->copy());

        $onTheDay = app(BankBalances::class)->ledger($this->bank(), $this->date->copy());
        $theDayBefore = app(BankBalances::class)->ledger($this->bank(), $this->date->copy()->subDay());

        $failures = [];

        if ((string) $onTheDay->amount !== '47000.0000') {
            $failures[] = sprintf('the ledger on the day of the payment is %s', (string) $onTheDay->amount);
        }

        if ((string) $theDayBefore->amount !== '50000.0000') {
            $failures[] = sprintf(
                'the ledger the day before the payment is %s, so the balance ignores the date it was '.
                'asked for - which makes every comparative report wrong',
                (string) $theDayBefore->amount,
            );
        }

        return $failures;
    }

    // ---- The opening balance, and closing --------------------------------

    /** @return list<string> */
    private function correctable(): array
    {
        try {
            $account = app(BankAccounts::class)->correctOpeningBalance(
                account: $this->bank(),
                balance: '60000.0000',
                openingDate: $this->openingDate(),
                userId: $this->actorId(),
                reason: 'The bank\'s own opening figure was transcribed one digit short during setup.',
            );
        } catch (\Throwable $e) {
            return [sprintf('the opening balance could not be corrected before any statement: %s', $e->getMessage())];
        }

        return (string) $account->opening_balance === '60000.0000'
            ? []
            : [sprintf('the correction stored %s', (string) $account->opening_balance)];
    }

    /** @return list<string> */
    private function notCorrectableAfter(): array
    {
        $this->importFirstStatement();

        $refusal = $this->refusal(fn () => app(BankAccounts::class)->correctOpeningBalance(
            account: $this->bank(),
            balance: '60000.0000',
            openingDate: $this->openingDate(),
            userId: $this->actorId(),
            reason: 'Trying to change the figure every reconciliation since is measured from.',
        ));

        if ($refusal === null) {
            return ['the opening balance was changed after a statement had been imported'];
        }

        return str_contains($refusal, 'cannot be changed')
            ? []
            : [sprintf('the refusal does not say the figure is fixed: %s', $refusal)];
    }

    /**
     * The same rule, attempted beneath the service.
     *
     * ## Why both layers get their own check
     *
     * Because a check that accepts a refusal from either layer passes while
     * one of them is broken. Proved by trying: with the service's guard
     * removed the combined check still passed, because the trigger caught
     * it - and a guard whose absence nothing notices is a guard that will
     * be absent.
     *
     * The trigger is the one that matters more here. A service guard is what
     * somebody bypasses with an `update()` in a console command at 7pm on a
     * Friday, which is exactly when an opening balance gets "fixed".
     *
     * @return list<string>
     */
    private function openingBalanceInTheDatabase(): array
    {
        $this->importFirstStatement();

        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('bank_accounts')
            ->where('id', (string) $this->bank()->getKey())
            ->update(['opening_balance' => '60000.0000']));

        if ($refusal === null) {
            return [
                'a direct update changed the opening balance after a statement existed. It is the one '.
                'figure nothing else can reconstruct, so every reconciliation since the first import '.
                'is now out by the difference with no document recording why',
            ];
        }

        return str_contains($refusal, 'cannot be changed')
            ? []
            : [sprintf('the database\'s refusal does not say the figure is fixed: %s', $refusal)];
    }

    /** @return list<string> */
    private function correctionNeedsAReason(): array
    {
        $refusal = $this->refusal(fn () => app(BankAccounts::class)->correctOpeningBalance(
            account: $this->bank(),
            balance: '60000.0000',
            openingDate: $this->openingDate(),
            userId: $this->actorId(),
            reason: 'typo',
        ));

        if ($refusal === null) {
            return ['an opening balance was corrected with "typo" as the reason'];
        }

        return str_contains($refusal, 'does not tie') || str_contains($refusal, 'needs a reason')
            ? []
            : [sprintf('the refusal does not say why a reason matters: %s', $refusal)];
    }

    /** @return list<string> */
    private function closingWithABalance(): array
    {
        $refusal = $this->refusal(fn () => app(BankAccounts::class)->close(
            account: $this->bank(),
            userId: $this->actorId(),
            reason: 'Consolidating onto one operating account.',
        ));

        if ($refusal === null) {
            return ['an account holding 50,000 was closed'];
        }

        return str_contains($refusal, 'nothing reports on')
            ? []
            : [sprintf('the refusal does not say where the cash would go: %s', $refusal)];
    }

    /** @return list<string> */
    private function closingAnEmptyOne(): array
    {
        $gl = $this->secondBankGlAccountId();

        if ($gl === '') {
            return [];
        }

        $empty = $this->openAccount(
            openingBalance: '0',
            iban: self::SA_IBAN_OTHER,
            glAccountId: $gl,
            label: $this->uniqueLabel('Empty'),
        );

        try {
            $closed = app(BankAccounts::class)->close(
                account: $empty,
                userId: $this->actorId(),
                reason: 'Never funded, opened in error during setup.',
            );
        } catch (\Throwable $e) {
            return [sprintf('an empty account could not be closed: %s', $e->getMessage())];
        }

        return (string) $closed->status === 'closed'
            ? []
            : [sprintf('the account is "%s" after being closed', (string) $closed->status)];
    }

    // ---- Isolation --------------------------------------------------------

    /** @return list<string> */
    private function entityScope(): array
    {
        $id = (string) $this->bank()->getKey();

        $other = Entity::create([
            'code' => 'BK'.Str::upper(Str::random(6)),
            'legal_name' => 'A second entity in the same tenant',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $context = app(EntityContext::class);

        try {
            $context->use($other);

            $failures = [];

            if (BankAccount::query()->whereKey($id)->count() !== 0) {
                $failures[] = 'a bank account belonging to another entity is visible from this one';
            }

            $transactions = DB::connection('tenant')->table('bank_transactions')
                ->where('bank_account_id', $id)
                ->count();

            if ($transactions !== 0) {
                $failures[] = 'another entity\'s bank transactions are visible from this one';
            }

            return $failures;
        } finally {
            $context->use($this->entity());
        }
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * A GL account outside the bank subtree, for the role checks.
     *
     * Postable, so the refusal under test is the subtree one rather than the
     * header one.
     */
    private function glAccountOutsideTheBankSubtree(): string
    {
        $parent = DB::connection('tenant')->table('accounts')
            ->where('id', $this->bankGlAccountId)
            ->value('path');

        return (string) DB::connection('tenant')->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('postable', true)
            ->where('id', '!=', $this->bankGlAccountId)
            ->where('path', 'not like', (string) $parent.'%')
            ->orderBy('code')
            ->value('id');
    }

    /**
     * The subtree rule, attempted beneath the service.
     *
     * @return list<string>
     */
    private function wrongRoleInTheDatabase(): array
    {
        $outside = $this->glAccountOutsideTheBankSubtree();

        if ($outside === '') {
            return [];
        }

        $refusal = $this->refusal(fn () => $this->insertDirectly($outside));

        if ($refusal === null) {
            return [
                'a direct insert linked a bank account to a GL account outside the bank subtree. The '.
                'trial balance now reports a receivable as cash, and no screen in the product says so',
            ];
        }

        $failures = [];

        if (! str_contains($refusal, 'outside the subtree of bank account')) {
            $failures[] = sprintf('the database\'s refusal does not name the invariant: %s', $refusal);
        }

        return $failures;
    }

    /**
     * The header rule, attempted beneath the service.
     *
     * @return list<string>
     */
    private function headerInTheDatabase(): array
    {
        $header = (string) DB::connection('tenant')->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('postable', false)
            ->orderBy('code')
            ->value('id');

        if ($header === '') {
            return [];
        }

        $refusal = $this->refusal(fn () => $this->insertDirectly($header));

        if ($refusal === null) {
            return ['a direct insert mirrored a header account, whose ledger balance is permanently zero'];
        }

        $failures = [];

        if (! str_contains($refusal, 'header')) {
            $failures[] = sprintf('the database\'s refusal does not say the account is a header: %s', $refusal);
        }

        return $failures;
    }

    /**
     * A bank account row written straight to the table.
     *
     * No service, no model events: what a console command or a migration
     * would do. The encrypted columns are omitted, which the reachability
     * constraint would normally refuse - so a domestic identifier is
     * supplied, and the refusal under test is the one about the GL link.
     */
    private function insertDirectly(string $glAccountId): void
    {
        DB::connection('tenant')->table('bank_accounts')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'label' => $this->uniqueLabel('Direct'),
            'status' => 'active',
            'institution_name' => 'Al Rajhi Bank',
            'institution_country' => 'SA',
            'holder_legal_name' => 'Fixture Trading Co.',
            'currency' => 'SAR',
            'gl_account_id' => $glAccountId,
            'opening_balance' => '0',
            'opening_date' => $this->openingDate()->toDateString(),
            'domestic_scheme' => 'us_routing',
            'domestic_identifier_encrypted' => 'not-a-real-ciphertext',
            'domestic_identifier_tail' => '0021',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A refusal a user sees carries nothing from inside the database.
     *
     * ## What this is about
     *
     * Structural rules live in the database, so the sentence somebody reads
     * for "this GL account is not a bank account" is written in PL/pgSQL and
     * arrives wrapped in a `QueryException`. The service unwraps it - and the
     * first three implementations of that unwrapping stopped at `(SQL:` and
     * kept PostgreSQL's `CONTEXT:` block, so the message ended:
     *
     *     CONTEXT: PL/pgSQL function peakflow_bank_account_gl_link_is_fixed()
     *     line 22 at RAISE
     *
     * Noise to the person reading it, a map of the schema to anybody else,
     * and it moves whenever the function is edited - which also makes it
     * impossible to assert what a refusal says.
     *
     * @return list<string>
     */
    private function carriesNoInternals(string $refusal): array
    {
        $failures = [];

        foreach (['CONTEXT:', 'PL/pgSQL', 'at RAISE', '(SQL:', 'DETAIL:', 'HINT:'] as $leak) {
            if (str_contains($refusal, $leak)) {
                $failures[] = sprintf('the refusal carries "%s" from inside the database: %s', $leak, $refusal);
            }
        }

        return $failures;
    }

    /**
     * Every refusal the service translates, checked for plumbing at once.
     *
     * @return list<string>
     */
    private function refusalsAreClean(): array
    {
        $failures = [];

        /*
         | Only the paths a SERVICE translates.
         |
         | A direct insert from a console command raises a QueryException and
         | nothing is there to unwrap it, which is correct - the plumbing is
         | the caller's to deal with. What must never carry plumbing is a
         | refusal a service hands to its caller, so these are the two rules
         | that live ONLY in the database and therefore reach a user through
         | a translation.
         */
        $attempts = [
            'the continuity refusal' => function (): void {
                $this->importFirstStatement();

                app(StatementImport::class)->import(
                    account: $this->bank(),
                    sourceFormat: 'camt053',
                    periodFrom: $this->openingDate()->addDays(10),
                    periodTo: $this->openingDate()->addDays(14),
                    openingBalance: '52000.0000',
                    closingBalance: '52500.0000',
                    lines: [[
                        'booking_date' => $this->openingDate()->addDays(11)->toDateString(),
                        'value_date' => $this->openingDate()->addDays(11)->toDateString(),
                        'amount' => '500.0000',
                        'bank_reference' => 'BR-GAMMA-001',
                    ]],
                    userId: $this->actorId(),
                );
            },
            'the opening-balance refusal' => function (): void {
                $this->importFirstStatement();

                app(BankAccounts::class)->correctOpeningBalance(
                    account: $this->bank(),
                    balance: '60000.0000',
                    openingDate: $this->openingDate(),
                    userId: $this->actorId(),
                    reason: 'Trying to change the figure every reconciliation is measured from.',
                );
            },
        ];

        foreach ($attempts as $what => $attempt) {
            $refusal = $this->refusal($attempt);

            if ($refusal === null) {
                $failures[] = sprintf('%s was not refused at all', $what);

                continue;
            }

            foreach ($this->carriesNoInternals($refusal) as $leak) {
                $failures[] = sprintf('%s: %s', $what, $leak);
            }
        }

        return $failures;
    }
}
