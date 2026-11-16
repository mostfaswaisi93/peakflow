<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Banking\Enums\DifferenceType;
use App\Tenant\Banking\Services\DifferenceClassifier;
use App\Tenant\Banking\Services\Differences;
use App\Tenant\Identity\Models\SodRule;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Tenancy\Settings\SettingsRegistry;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Differences, classified before they are written off - PF-164.
 *
 * ## The one that costs real money
 *
 * A customer in a withholding jurisdiction pays 95 against an invoice for
 * 100 and keeps 5 for the tax authority, against which they issue a
 * certificate. That 5 is **ours**: a credit against our own liability.
 *
 * Classified as a short payment it goes to bad debt, the credit is never
 * claimed, and the loss runs at a few percent of revenue in every
 * withholding jurisdiction the business sells into - quietly, every
 * individual write-off looking like a small customer dispute. So
 * withholding is tested first, on the arithmetic rather than on a habit,
 * and it is the one type with a platform ceiling of zero: it is never
 * absorbed, only recognised and sent to PF-083.
 *
 * ## And the one that reads as housekeeping
 *
 * A short payment is a decision - somebody decided a customer need not pay
 * - and the amount is not what makes it one. A tolerance that let small
 * ones through without a name against them would be a tolerance for making
 * that decision quietly, one invoice at a time.
 *
 * So: a per-type tolerance, each bounded by a platform ceiling, an
 * approver above it, and a duty that conflicts with having done the
 * matching.
 */
final class DifferenceRules
{
    use \Tests\Support\Concerns\OpensBankAccounts;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['differences (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheBankSide();
            $this->postTheOpeningEntry();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                // ---- Classification ------------------------------------
                'withholding is recognised before anything else claims it' => fn (): array => $this->withholdingFirst(),
                'a shortfall that is not the rate is a short payment' => fn (): array => $this->notTheRate(),
                'the smallest unit of the currency is rounding' => fn (): array => $this->roundingIsTheUnit(),
                'a three-decimal currency rounds at its own unit' => fn (): array => $this->roundingIsPerCurrency(),
                'the bank taking it is a bank charge' => fn (): array => $this->bankCharge(),
                'a rate that moved is an exchange difference' => fn (): array => $this->fxDifference(),
                'paying too much is an overpayment, not a gift' => fn (): array => $this->overpayment(),
                'an unexplained shortfall defaults to the one somebody chases' => fn (): array => $this->defaultIsTheVisibleOne(),
                'every classification says why and what else it could be' => fn (): array => $this->classificationExplainsItself(),

                // ---- Each type has its own rule and account ------------
                'each type has a posting rule that is registered' => fn (): array => $this->everyTypeHasARule(),
                'each type posts to its own account' => fn (): array => $this->everyTypeHasAnAccount(),
                'a bank charge posts to bank charges, not to receivables' => fn (): array => $this->bankChargePosts(),
                'a short payment posts to bad debt and relieves the party' => fn (): array => $this->shortPaymentPosts(),
                'an entity missing the account is refused' => fn (): array => $this->missingAccount(),

                // ---- Tolerances ----------------------------------------
                'each type has its own tolerance' => fn (): array => $this->tolerancePerType(),
                'every tolerance is bounded by a platform ceiling' => fn (): array => $this->tolerancesHaveCeilings(),
                'a tenant cannot raise a tolerance past the ceiling' => fn (): array => $this->ceilingIsEnforced(),
                'withholding may never be absorbed' => fn (): array => $this->withholdingIsNeverAbsorbed(),

                // ---- Approval ------------------------------------------
                'a difference above tolerance needs an approver' => fn (): array => $this->aboveToleranceNeedsApproval(),
                'no type is exempt from needing one' => fn (): array => $this->noTypeIsExempt(),
                'the database refuses an unapproved write-off on its own' => fn (): array => $this->databaseRefusesUnapproved(),
                'a difference states a reason' => fn (): array => $this->needsAReason(),
                'a zero difference is refused' => fn (): array => $this->zeroDifference(),
                'the tolerance that applied is stored, not recomputed' => fn (): array => $this->toleranceIsStored(),
                'whoever matched does not write off' => fn (): array => $this->sodPairExists(),

                // ---- A near miss is matched and named - PF-158 ---------
                'a payment just short proposes the document and names the gap' => fn (): array => $this->nearMissIsProposed(),
                'a near miss matches what arrived, not what is owed' => fn (): array => $this->nearMissDoesNotAbsorb(),
                'a near miss is never applied automatically' => fn (): array => $this->nearMissIsNotAutomatic(),
                'a gap beyond the tolerance is not a near miss' => fn (): array => $this->beyondToleranceIsNotNear(),
                'an exact match beats a near one' => fn (): array => $this->exactBeatsNear(),

                // ---- The report ----------------------------------------
                'the report groups by type and by period' => fn (): array => $this->reportGroups(),
                'a rising line is reported as rising' => fn (): array => $this->risingIsReported(),
                'two months are not a trend' => fn (): array => $this->twoMonthsIsNotATrend(),
            ]));
        } finally {
            $this->connection->rollBack();
        }
    }

    // ---- Classification ---------------------------------------------------

    /** @return list<string> */
    private function withholdingFirst(): array
    {
        /*
         | 5% of 10,000 is 500, and the customer paid 9,500.
         |
         | Every other rule would also accept this shortfall: it is larger
         | than rounding, the counterparty is not the bank, and nothing
         | about it is obviously tax. Tested first precisely because the
         | cost of the alternative is a credit never claimed.
         */
        $result = app(DifferenceClassifier::class)->classify([
            'expected' => Money::of('10000.0000', 'SAR'),
            'received' => Money::of('9500.0000', 'SAR'),
            'withholding_rate' => '5',
            'counterparty_is_a_bank' => false,
            'rate_moved' => false,
        ]);

        if ($result['type'] !== DifferenceType::Withholding) {
            return [sprintf(
                'a shortfall of exactly the withholding rate was classified as %s, so the tax credit '.
                'goes to bad debt and is never claimed - a few percent of revenue, quietly, in every '.
                'withholding jurisdiction',
                $result['type']->value,
            )];
        }

        return str_contains($result['why'], 'withholding rate')
            ? []
            : [sprintf('the classification does not say what made it withholding: %s', $result['why'])];
    }

    /** @return list<string> */
    private function notTheRate(): array
    {
        /*
         | The same customer, and a shortfall that is not the rate.
         |
         | 5% of 10,000 is 500 and they paid 600 short. A classifier that
         | took "this party withholds" as the signal rather than the
         | arithmetic would file a genuine dispute as a tax credit - and
         | the credit would never get a certificate, which is how it is
         | found, a year later, by an authority.
         */
        $result = app(DifferenceClassifier::class)->classify([
            'expected' => Money::of('10000.0000', 'SAR'),
            'received' => Money::of('9400.0000', 'SAR'),
            'withholding_rate' => '5',
            'counterparty_is_a_bank' => false,
            'rate_moved' => false,
        ]);

        return $result['type'] === DifferenceType::ShortPayment
            ? []
            : [sprintf(
                'a shortfall that is not the withholding rate was classified as %s, so a dispute is '.
                'recorded as a tax credit nobody can certify',
                $result['type']->value,
            )];
    }

    /** @return list<string> */
    private function roundingIsTheUnit(): array
    {
        $result = app(DifferenceClassifier::class)->classify([
            'expected' => Money::of('1000.0000', 'SAR'),
            'received' => Money::of('999.9900', 'SAR'),
            'withholding_rate' => null,
            'counterparty_is_a_bank' => false,
            'rate_moved' => false,
        ]);

        return $result['type'] === DifferenceType::Rounding
            ? []
            : [sprintf('a one-halala difference was classified as %s', $result['type']->value)];
    }

    /** @return list<string> */
    private function roundingIsPerCurrency(): array
    {
        /*
         | 0.009 in a three-decimal currency is not rounding.
         |
         | A rule with 0.01 written into it would absorb nine times the
         | smallest unit in JOD and call it arithmetic - and a rule that
         | absorbs nine units is a rule somebody can use.
         */
        $withinJod = app(DifferenceClassifier::class)->classify([
            'expected' => Money::of('1000.0000', 'JOD'),
            'received' => Money::of('999.9990', 'JOD'),
            'withholding_rate' => null,
            'counterparty_is_a_bank' => false,
            'rate_moved' => false,
        ]);

        $beyondJod = app(DifferenceClassifier::class)->classify([
            'expected' => Money::of('1000.0000', 'JOD'),
            'received' => Money::of('999.9910', 'JOD'),
            'withholding_rate' => null,
            'counterparty_is_a_bank' => false,
            'rate_moved' => false,
        ]);

        $failures = [];

        if ($withinJod['type'] !== DifferenceType::Rounding) {
            $failures[] = sprintf('one fils in a three-decimal currency was classified as %s', $withinJod['type']->value);
        }

        if ($beyondJod['type'] === DifferenceType::Rounding) {
            $failures[] = 'nine fils was called rounding in a three-decimal currency, so the rule uses two decimals whatever the currency is';
        }

        return $failures;
    }

    /** @return list<string> */
    private function bankCharge(): array
    {
        $result = app(DifferenceClassifier::class)->classify([
            'expected' => Money::of('1000.0000', 'SAR'),
            'received' => Money::of('975.0000', 'SAR'),
            'withholding_rate' => null,
            'counterparty_is_a_bank' => true,
            'rate_moved' => false,
        ]);

        return $result['type'] === DifferenceType::BankCharge
            ? []
            : [sprintf('a difference against the bank itself was classified as %s', $result['type']->value)];
    }

    /** @return list<string> */
    private function fxDifference(): array
    {
        $result = app(DifferenceClassifier::class)->classify([
            'expected' => Money::of('1000.0000', 'SAR'),
            'received' => Money::of('985.0000', 'SAR'),
            'withholding_rate' => null,
            'counterparty_is_a_bank' => false,
            'rate_moved' => true,
        ]);

        return $result['type'] === DifferenceType::FxDifference
            ? []
            : [sprintf('a difference where the rate moved was classified as %s', $result['type']->value)];
    }

    /** @return list<string> */
    private function overpayment(): array
    {
        $result = app(DifferenceClassifier::class)->classify([
            'expected' => Money::of('1000.0000', 'SAR'),
            'received' => Money::of('1200.0000', 'SAR'),
            'withholding_rate' => null,
            'counterparty_is_a_bank' => false,
            'rate_moved' => false,
        ]);

        $failures = [];

        if ($result['type'] !== DifferenceType::Overpayment) {
            $failures[] = sprintf('paying 200 too much was classified as %s', $result['type']->value);
        }

        if (! $result['amount']->isNegative()) {
            $failures[] = 'an overpayment was not signed as money owed back, so a rule reading the sign would post it as a cost';
        }

        return $failures;
    }

    /** @return list<string> */
    private function defaultIsTheVisibleOne(): array
    {
        $result = app(DifferenceClassifier::class)->classify([
            'expected' => Money::of('1000.0000', 'SAR'),
            'received' => Money::of('940.0000', 'SAR'),
            'withholding_rate' => null,
            'counterparty_is_a_bank' => false,
            'rate_moved' => false,
        ]);

        /*
         | The default has to be the one somebody chases.
         |
         | Rounding, a charge or a rate are all answers that get a
         | difference filed. "The customer did not pay all of it" is the
         | one that gets it looked at, and the difference somebody should
         | have chased must not be the one nobody sees.
         */
        return $result['type'] === DifferenceType::ShortPayment
            ? []
            : [sprintf(
                'an unexplained 60 defaulted to %s, which is an answer that files it rather than one '.
                'that gets somebody to look',
                $result['type']->value,
            )];
    }

    /** @return list<string> */
    private function classificationExplainsItself(): array
    {
        $failures = [];

        foreach ([
            ['expected' => '1000.0000', 'received' => '940.0000', 'rate' => null, 'bank' => false, 'moved' => false],
            ['expected' => '1000.0000', 'received' => '950.0000', 'rate' => '5', 'bank' => false, 'moved' => false],
            ['expected' => '1000.0000', 'received' => '999.9900', 'rate' => null, 'bank' => false, 'moved' => false],
        ] as $case) {
            $result = app(DifferenceClassifier::class)->classify([
                'expected' => Money::of($case['expected'], 'SAR'),
                'received' => Money::of($case['received'], 'SAR'),
                'withholding_rate' => $case['rate'],
                'counterparty_is_a_bank' => $case['bank'],
                'rate_moved' => $case['moved'],
            ]);

            if (mb_strlen(trim($result['why'])) < 40) {
                $failures[] = sprintf(
                    'the %s classification explains itself in under forty characters, which is a '.
                    'label rather than a reason',
                    $result['type']->value,
                );
            }

            if ($result['confidence'] <= 0.0 || $result['confidence'] > 1.0) {
                $failures[] = sprintf('%s scored %s, which is not a proportion', $result['type']->value, (string) $result['confidence']);
            }
        }

        /*
         | A classification that could be something else says so.
         |
         | The short payment default is a guess by construction, and a
         | guess presented without its alternatives is one nobody
         | questions.
         */
        $guess = app(DifferenceClassifier::class)->classify([
            'expected' => Money::of('1000.0000', 'SAR'),
            'received' => Money::of('940.0000', 'SAR'),
            'withholding_rate' => null,
            'counterparty_is_a_bank' => false,
            'rate_moved' => false,
        ]);

        if ($guess['alternatives'] === []) {
            $failures[] = 'the default classification offers no alternatives, so a guess is presented as a finding';
        }

        return $failures;
    }

    // ---- Rules and accounts -----------------------------------------------

    /** @return list<string> */
    private function everyTypeHasARule(): array
    {
        $registry = app(PostingRuleRegistry::class);
        $failures = [];

        foreach (DifferenceType::cases() as $type) {
            if (! $registry->has($type->postingRule())) {
                $failures[] = sprintf(
                    'a %s names the posting rule "%s", which is not registered - so recording one '.
                    'fails at the moment somebody needs it rather than at boot',
                    $type->labelEn(),
                    $type->postingRule(),
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function everyTypeHasAnAccount(): array
    {
        $seen = [];
        $failures = [];

        foreach (DifferenceType::cases() as $type) {
            $seen[$type->account()->value][] = $type->value;
        }

        /*
         | Six types, and not all six accounts are distinct - by design.
         |
         | An overpayment belongs on the customer, which is receivables,
         | and that is the right answer rather than a collision. What
         | would be wrong is two types that mean different things sharing
         | a cost account, because the report by type would then be the
         | only place the difference exists.
         */
        foreach ($seen as $account => $types) {
            if (\count($types) > 1 && $account !== ControlRole::AccountsReceivable->value) {
                $failures[] = sprintf(
                    '%s all post to %s, so the ledger cannot tell them apart and the report by type '.
                    'is the only place the distinction survives',
                    implode(' and ', $types),
                    $account,
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function bankChargePosts(): array
    {
        $this->importLines([$this->line('-25.0000', ['bank_reference' => 'DIF-CHG'])]);
        $transaction = $this->transactionBy('DIF-CHG');

        $result = app(Differences::class)->record(
            DifferenceType::BankCharge,
            Money::of('25.0000', 'SAR'),
            [
                'entity_id' => $this->entityId,
                'subject_type' => 'bank_transaction',
                'subject_id' => (string) $transaction->getKey(),
                'counter_account_id' => $this->bankGlAccountId,
                'arose_on' => $this->date->toDateString(),
            ],
            $this->actorId(),
            'the quarterly account maintenance fee the bank takes',
        );

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $result['journal_entry_id'])
            ->get();

        $failures = [];

        $charges = $this->accountFor(ControlRole::BankCharges);
        $onCharges = $lines->firstWhere('account_id', $charges);
        $onBank = $lines->firstWhere('account_id', $this->bankGlAccountId);

        if ($onCharges === null) {
            $failures[] = 'a bank charge did not reach the bank charges account, so a rising charge is invisible';
        } elseif (! BigDecimal::of((string) $onCharges->functional_amount)->isEqualTo('25.0000')) {
            $failures[] = sprintf('the charge posted %s rather than 25', (string) $onCharges->functional_amount);
        }

        if ($onBank === null) {
            $failures[] = 'the bank account was not relieved, so the entry does not describe what happened';
        }

        /*
         | And receivables is untouched.
         |
         | A fee the bank took is not a customer's. An entry that relieved
         | a receivable would close somebody's invoice for the cost of a
         | bank charge, and they would be chased for the difference later.
         */
        $receivables = $this->accountFor(ControlRole::AccountsReceivable);

        if ($lines->firstWhere('account_id', $receivables) !== null) {
            $failures[] = 'a bank charge moved receivables, so the bank taking a fee relieved a customer balance';
        }

        return $failures;
    }

    /** @return list<string> */
    private function shortPaymentPosts(): array
    {
        $invoice = $this->issue();

        $result = app(Differences::class)->record(
            DifferenceType::ShortPayment,
            Money::of('40.0000', 'SAR'),
            [
                'entity_id' => $this->entityId,
                'subject_type' => 'invoice',
                'subject_id' => (string) $invoice->getKey(),
                'counter_account_id' => $this->accountFor(ControlRole::AccountsReceivable),
                'party_id' => (string) $invoice->party_id,
                'arose_on' => $this->date->toDateString(),
            ],
            $this->actorId(),
            'the customer disputes the delivery charge and will not pay it',
            approvedBy: $this->actorId(),
        );

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $result['journal_entry_id'])
            ->get();

        $failures = [];

        $badDebt = $this->accountFor(ControlRole::BadDebt);
        $receivable = $lines->firstWhere('account_id', $this->accountFor(ControlRole::AccountsReceivable));

        if ($lines->firstWhere('account_id', $badDebt) === null) {
            $failures[] = 'a short payment did not reach bad debt, so a credit loss is somewhere else in the accounts';
        }

        if ($receivable === null) {
            return [...$failures, 'the receivable was not relieved, so the invoice stays open for money nobody is chasing'];
        }

        /*
         | The party is on the receivable line.
         |
         | Without it the control account moves and the customer's own
         | balance does not, so the subledger and the control stop
         | agreeing - and the first symptom is a statement chasing money
         | somebody already wrote off.
         */
        if ($receivable->party_id === null) {
            $failures[] = 'the receivable line carries no party, so the control account moved and the customer balance did not';
        }

        return $failures;
    }

    /** @return list<string> */
    private function missingAccount(): array
    {
        AccountRole::query()
            ->where('entity_id', $this->entityId)
            ->where('role', ControlRole::BankCharges->value)
            ->delete();

        $this->importLines([$this->line('-15.0000', ['bank_reference' => 'DIF-NOACC'])]);

        $refusal = $this->refusal(fn () => app(Differences::class)->record(
            DifferenceType::BankCharge,
            Money::of('15.0000', 'SAR'),
            [
                'entity_id' => $this->entityId,
                'subject_type' => 'bank_transaction',
                'subject_id' => (string) $this->transactionBy('DIF-NOACC')->getKey(),
                'counter_account_id' => $this->bankGlAccountId,
                'arose_on' => $this->date->toDateString(),
            ],
            $this->actorId(),
            'a charge with nowhere to go',
        ));

        if ($refusal === null) {
            return ['a difference posted with no account carrying its role, so it went wherever the posting rule fell back to'];
        }

        /*
         | The ledger's refusal, and that is the right one.
         |
         | There used to be a guard in the difference service saying the
         | same thing worse. The ledger owns the fact that a control role
         | has no account, names the role, and says to assign it in the
         | chart before posting - so the service says nothing and this
         | asserts what the person actually sees.
         */
        return str_contains($refusal, 'bank_charges')
            && str_contains($refusal, 'chart of accounts')
            ? []
            : [sprintf(
                'the refusal does not name the role that is missing or say where to assign it: %s',
                $refusal,
            )];
    }

    // ---- Tolerances -------------------------------------------------------

    /** @return list<string> */
    private function tolerancePerType(): array
    {
        $tolerances = [];

        foreach (DifferenceType::cases() as $type) {
            if ($type === DifferenceType::Withholding) {
                continue;
            }

            $tolerances[$type->value] = (string) app(Differences::class)
                ->tolerance($type, $this->entityId)->amount;
        }

        /*
         | Not all the same figure.
         |
         | One number for every type would have to be the largest of them,
         | and the largest is the one that absorbs theft. A rounding
         | tolerance of 0.05 is arithmetic; a short-payment tolerance of
         | 0.05 is a policy.
         */
        if (\count(array_unique($tolerances)) === 1) {
            return [sprintf(
                'every type has the same tolerance (%s), so one figure governs arithmetic and '.
                'deciding a customer need not pay',
                reset($tolerances),
            )];
        }

        $rounding = BigDecimal::of($tolerances[DifferenceType::Rounding->value]);
        $short = BigDecimal::of($tolerances[DifferenceType::ShortPayment->value]);

        return $rounding->isGreaterThan($short)
            ? ['rounding is absorbed more freely than a short payment, which inverts the two risks']
            : [];
    }

    /** @return list<string> */
    private function tolerancesHaveCeilings(): array
    {
        $failures = [];

        $settings = array_map(
            static fn (DifferenceType $type): string => $type->toleranceSetting(),
            array_values(array_filter(
                DifferenceType::cases(),
                static fn (DifferenceType $type): bool => $type !== DifferenceType::Withholding,
            )),
        );

        /*
         | And the one that decides what counts as near - PF-158.
         |
         | `banking.auto_match_tolerance` is the same kind of lever as the
         | six: generous enough and a persistent discrepancy stops being
         | visible. It was risk-bearing before this task and nothing
         | asserted it, which is how a ceiling gets removed in a refactor.
         */
        $settings[] = 'banking.auto_match_tolerance';

        foreach ($settings as $setting) {
            if (! SettingsRegistry::has($setting)) {
                $failures[] = sprintf(
                    'the setting "%s" is named by a difference type and is not in the registry, so '.
                    'no tenant can set it',
                    $setting,
                );

                continue;
            }

            if (! SettingsRegistry::get($setting)->bearsRisk()) {
                $failures[] = sprintf(
                    '"%s" has no platform ceiling, so "write off anything under 50,000" is a '.
                    'setting rather than a conversation',
                    $setting,
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function ceilingIsEnforced(): array
    {
        $refusal = $this->refusal(fn () => app(\App\Tenant\Tenancy\Services\Settings::class)->set(
            'banking.tolerance_short_payment',
            '999999.00',
            $this->entityId,
        ));

        return $refusal !== null
            ? []
            : ['a tenant set a short-payment tolerance of 999,999, which absorbs theft one invoice at a time'];
    }

    /** @return list<string> */
    private function withholdingIsNeverAbsorbed(): array
    {
        $failures = [];

        /*
         | Withholding has no tolerance, and that is the statement.
         |
         | A customer who deducted tax at source handed us a credit
         | against our own liability. There is no figure below which
         | throwing it away is acceptable, so there is no setting to put
         | one in.
         */
        if (SettingsRegistry::has(DifferenceType::Withholding->toleranceSetting())) {
            $failures[] = 'withholding has a tolerance setting, so a tenant can decide below what figure to throw a tax credit away';
        }

        if (DifferenceType::Withholding->resolvesElsewhere() === null) {
            $failures[] = 'withholding does not say it resolves elsewhere, so a credit is recorded here with no certificate behind it and nobody is told';
        }

        if (DifferenceType::Withholding->account() !== ControlRole::WithholdingReceivable) {
            $failures[] = sprintf(
                'withholding posts to %s rather than the receivable the return claims against',
                DifferenceType::Withholding->account()->value,
            );
        }

        return $failures;
    }

    // ---- Approval ---------------------------------------------------------

    /** @return list<string> */
    private function aboveToleranceNeedsApproval(): array
    {
        $invoice = $this->issue();

        $refusal = $this->refusal(fn () => app(Differences::class)->record(
            DifferenceType::ShortPayment,
            Money::of('500.0000', 'SAR'),
            [
                'entity_id' => $this->entityId,
                'subject_type' => 'invoice',
                'subject_id' => (string) $invoice->getKey(),
                'counter_account_id' => $this->accountFor(ControlRole::AccountsReceivable),
                'party_id' => (string) $invoice->party_id,
                'arose_on' => $this->date->toDateString(),
            ],
            $this->actorId(),
            'writing off five hundred with nobody having approved it',
        ));

        if ($refusal === null) {
            return ['five hundred was written off with nobody deciding it, which is the decision this control exists for'];
        }

        return str_contains($refusal, 'approval') || str_contains($refusal, 'decide')
            ? []
            : [sprintf('the refusal does not say an approver is needed: %s', $refusal)];
    }

    /** @return list<string> */
    private function noTypeIsExempt(): array
    {
        /*
         | A bank charge above tolerance needs an approver too.
         |
         | The type that most obviously should not need one - nobody
         | decided the bank's fee - and that is exactly why it is checked.
         | An earlier version exempted it, which put the enum at odds with
         | the constraint on the table, and two rules that disagree is
         | worse than either. The answer is the tolerance: a generous
         | ceiling is what keeps an ordinary fee from ever reaching an
         | approver, and a charge above it is a bank that repriced.
         */
        $this->importLines([$this->line('-400.0000', ['bank_reference' => 'DIF-EXEMPT'])]);

        $refusal = $this->refusal(fn () => app(Differences::class)->record(
            DifferenceType::BankCharge,
            Money::of('400.0000', 'SAR'),
            [
                'entity_id' => $this->entityId,
                'subject_type' => 'bank_transaction',
                'subject_id' => (string) $this->transactionBy('DIF-EXEMPT')->getKey(),
                'counter_account_id' => $this->bankGlAccountId,
                'arose_on' => $this->date->toDateString(),
            ],
            $this->actorId(),
            'a charge far beyond anything this account has ever been billed',
        ));

        return $refusal !== null
            ? []
            : ['a bank charge sixteen times the tolerance was recorded with nobody looking at it'];
    }

    /** @return list<string> */
    private function databaseRefusesUnapproved(): array
    {
        /*
         | Written straight to the table, past the service.
         |
         | An import, a correction script or a future bulk tool reaches
         | this table without passing a service, and "the service checks
         | the approver" is not an invariant.
         */
        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('reconciliation_differences')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'entity_id' => $this->entityId,
            'type' => 'short_payment',
            'subject_type' => 'invoice',
            'subject_id' => (string) \Illuminate\Support\Str::ulid(),
            'party_id' => null,
            'amount' => '900.0000',
            'currency' => 'SAR',
            'tolerance' => '1.0000',
            'within_tolerance' => false,
            'arose_on' => $this->date->toDateString(),
            'reason' => 'written off with nobody having approved it',
            'recorded_by' => $this->actorId(),
            'recorded_at' => now(),
            'approved_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $refusal !== null
            ? []
            : ['the table accepted a write-off above tolerance with no approver, so the control is only as good as the one code path that checks it'];
    }

    /** @return list<string> */
    private function needsAReason(): array
    {
        $this->importLines([$this->line('-10.0000', ['bank_reference' => 'DIF-NR'])]);

        $refusal = $this->refusal(fn () => app(Differences::class)->record(
            DifferenceType::BankCharge,
            Money::of('10.0000', 'SAR'),
            [
                'entity_id' => $this->entityId,
                'subject_type' => 'bank_transaction',
                'subject_id' => (string) $this->transactionBy('DIF-NR')->getKey(),
                'counter_account_id' => $this->bankGlAccountId,
                'arose_on' => $this->date->toDateString(),
            ],
            $this->actorId(),
            'fee',
        ));

        if ($refusal === null) {
            return ['a difference was recorded with no reason, so "why did this invoice close for less" has no answer'];
        }

        /*
         | Refused by the service, not by the column.
         |
         | The table refuses a short reason too, which is why it is there.
         | A person recording a difference should be told what the reason
         | is for, not handed the name of a check constraint.
         */
        return str_contains($refusal, 'why an invoice closed')
            ? []
            : [sprintf('the refusal came from the table rather than from recording: %s', $refusal)];
    }

    /** @return list<string> */
    private function zeroDifference(): array
    {
        $this->importLines([$this->line('-11.0000', ['bank_reference' => 'DIF-ZERO'])]);

        $refusal = $this->refusal(fn () => app(Differences::class)->record(
            DifferenceType::BankCharge,
            Money::of('0.0000', 'SAR'),
            [
                'entity_id' => $this->entityId,
                'subject_type' => 'bank_transaction',
                'subject_id' => (string) $this->transactionBy('DIF-ZERO')->getKey(),
                'counter_account_id' => $this->bankGlAccountId,
                'arose_on' => $this->date->toDateString(),
            ],
            $this->actorId(),
            'a difference of nothing at all',
        ));

        if ($refusal === null) {
            return ['a zero difference was recorded, which appears on the report as an event nobody can act on'];
        }

        return str_contains($refusal, 'no difference to record')
            ? []
            : [sprintf('the refusal came from the table rather than from recording: %s', $refusal)];
    }

    /** @return list<string> */
    private function toleranceIsStored(): array
    {
        $this->importLines([$this->line('-12.0000', ['bank_reference' => 'DIF-TOL'])]);

        $result = app(Differences::class)->record(
            DifferenceType::BankCharge,
            Money::of('12.0000', 'SAR'),
            [
                'entity_id' => $this->entityId,
                'subject_type' => 'bank_transaction',
                'subject_id' => (string) $this->transactionBy('DIF-TOL')->getKey(),
                'counter_account_id' => $this->bankGlAccountId,
                'arose_on' => $this->date->toDateString(),
            ],
            $this->actorId(),
            'an ordinary transfer fee, within tolerance',
        );

        $row = DB::connection('tenant')->table('reconciliation_differences')->where('id', $result['id'])->first();

        if ($row === null) {
            return ['the difference was posted and not recorded, so the report has nothing to show'];
        }

        /*
         | The figure, not just the verdict.
         |
         | "It was within tolerance" is the claim and the tolerance is the
         | evidence. A tenant who raises a tolerance next week would
         | otherwise make every past difference look as though it had
         | always been inside one.
         */
        $expected = app(Differences::class)->tolerance(DifferenceType::BankCharge, $this->entityId);

        return BigDecimal::of((string) $row->tolerance)->isEqualTo($expected->amount)
            ? []
            : [sprintf(
                'the difference stored a tolerance of %s against an actual %s, so the evidence does '.
                'not match the claim',
                (string) $row->tolerance,
                (string) $expected->amount,
            )];
    }

    /** @return list<string> */
    private function sodPairExists(): array
    {
        $rule = SodRule::query()->where('key', 'match_then_write_off')->first();

        if ($rule === null) {
            return ['nothing stops the person who decided a payment fell short from deciding the shortfall need not be chased'];
        }

        $duties = [(string) $rule->duty_a, (string) $rule->duty_b];
        sort($duties);

        $failures = [];

        if ($duties !== ['bank.reconcile', 'reconciliation.write_off']) {
            $failures[] = sprintf('the rule pairs %s rather than matching against writing off', implode(' and ', $duties));
        }

        $map = (array) config('sod.performed_as');

        if (($map['reconciliation.write_off'] ?? []) === []) {
            $failures[] = 'the write-off duty maps to no audit action, so the rule cannot see it happen';
        }

        /*
         | And it maps only to the write-off, not to every difference.
         |
         | A 0.01 rounding is not a decision anybody made. Counting it as
         | the write-off duty would stop the same person approving a
         | genuine write-off on a different invoice for the rest of the
         | period, and a control that fires on nothing is one people get
         | an override for.
         */
        if (\in_array('difference.recorded', (array) ($map['reconciliation.write_off'] ?? []), true)) {
            $failures[] = 'recording a within-tolerance difference counts as writing one off, so a rounding blocks a real approval';
        }

        return $failures;
    }

    // ---- A near miss - PF-158 ---------------------------------------------

    /** @return list<string> */
    private function nearMissIsProposed(): array
    {
        $invoice = $this->issue();
        $short = (string) BigDecimal::of((string) $invoice->gross_amount)->minus('0.4000');

        $this->importLines([$this->line($short, [
            'bank_reference' => 'NEAR-1',
            'counterparty_name' => 'Gulf Retail LLC',
        ])]);

        $considered = app(\App\Tenant\Banking\Matching\MatchingEngine::class)
            ->consider($this->transactionBy('NEAR-1'));

        foreach ($considered['candidates'] as $candidate) {
            if ($candidate->matchedId !== (string) $invoice->getKey()) {
                continue;
            }

            /*
             | The reason names the gap and what happens to it.
             |
             | A proposal that said "nearly settles this invoice" and
             | stopped would leave a person to work out that 0.40 is
             | unaccounted for - which is the moment the difference gets
             | absorbed by somebody rounding in their head.
             */
            return str_contains($candidate->reason, 'classify')
                ? []
                : [sprintf('the near match does not say the gap will be classified: %s', $candidate->reason)];
        }

        return [sprintf(
            'a payment 0.40 short of an invoice proposed nothing for it (%d candidates), so every '.
            'bank charge, withholding and rate movement goes to somebody to match by hand',
            \count($considered['candidates']),
        )];
    }

    /** @return list<string> */
    private function nearMissDoesNotAbsorb(): array
    {
        $invoice = $this->issue();
        $short = (string) BigDecimal::of((string) $invoice->gross_amount)->minus('0.4000');

        $this->importLines([$this->line($short, ['bank_reference' => 'NEAR-2'])]);

        $considered = app(\App\Tenant\Banking\Matching\MatchingEngine::class)
            ->consider($this->transactionBy('NEAR-2'));

        foreach ($considered['candidates'] as $candidate) {
            if ($candidate->matchedId !== (string) $invoice->getKey()) {
                continue;
            }

            /*
             | The proposal is for what arrived.
             |
             | A candidate for the full outstanding would settle the
             | invoice for a smaller payment - writing the gap off by
             | arithmetic, with nobody having named it - and it would
             | over-apply the bank line, which the database refuses.
             */
            return $candidate->amount->amount->isEqualTo($short)
                ? []
                : [sprintf(
                    'the near match proposes %s against a line of %s, so it settles the document '.
                    'for money that did not arrive',
                    (string) $candidate->amount->amount,
                    $short,
                )];
        }

        return ['nothing was proposed, so this check cannot tell what the proposal was for'];
    }

    /** @return list<string> */
    private function nearMissIsNotAutomatic(): array
    {
        $invoice = $this->issue();
        $short = (string) BigDecimal::of((string) $invoice->gross_amount)->minus('0.4000');

        $this->importLines([$this->line($short, ['bank_reference' => 'NEAR-3'])]);
        $transaction = $this->transactionBy('NEAR-3');

        $considered = app(\App\Tenant\Banking\Matching\MatchingEngine::class)->consider($transaction);

        if ($considered['auto_appliable'] !== null) {
            return [sprintf(
                'a payment short of the document was applied automatically at %s, closing it while '.
                'money nobody has explained is unaccounted for',
                (string) $considered['auto_appliable']->confidence,
            )];
        }

        $failures = [];

        /*
         | The flag, not only the score.
         |
         | A near miss scores far below the threshold today, so "it was
         | not applied" is true for a reason that has nothing to do with
         | this rule - and a scoring change would quietly remove the
         | guarantee while this check went on passing. The flag is the
         | guarantee: never applied unseen, whatever it scores.
         */
        foreach ($considered['candidates'] as $candidate) {
            if (! \in_array('amount_nearly_settles_it', array_column($candidate->factors, 'factor'), true)) {
                continue;
            }

            if ($candidate->proposalOnly !== true) {
                $failures[] = 'a near miss is not marked proposal-only, so it rests on scoring low rather than on a rule';
            }
        }

        $result = app(\App\Tenant\Banking\Services\Matches::class)->settle($transaction, $this->actorId());

        if ($result['applied'] !== false) {
            $failures[] = 'a near miss settled itself, so the difference is discovered by a reconciliation rather than named by a person';
        }

        return $failures;
    }

    /** @return list<string> */
    private function beyondToleranceIsNotNear(): array
    {
        $invoice = $this->issue();
        $short = (string) BigDecimal::of((string) $invoice->gross_amount)->minus('40.0000');

        $this->importLines([$this->line($short, ['bank_reference' => 'NEAR-4'])]);

        $considered = app(\App\Tenant\Banking\Matching\MatchingEngine::class)
            ->consider($this->transactionBy('NEAR-4'));

        foreach ($considered['candidates'] as $candidate) {
            if ($candidate->matchedId === (string) $invoice->getKey()
                && $candidate->pass === 'amount_party_window') {
                return [sprintf(
                    'a payment 40 short was proposed as a near match against a tolerance of %s, so '.
                    'the tolerance is not what decides',
                    (string) app(\App\Tenant\Tenancy\Services\Settings::class)
                        ->get('banking.auto_match_tolerance', $this->entityId),
                )];
            }
        }

        return [];
    }

    /** @return list<string> */
    private function exactBeatsNear(): array
    {
        $exact = $this->issue();

        /*
         | A second invoice within the tolerance, but not equal to it.
         |
         | Two invoices at the SAME amount cannot show this: neither is a
         | near miss of the payment, both are exact, and the check would
         | pass whatever the pass did. The second document has to be close
         | enough to be proposed if the exact path did not win.
         */
        $near = $this->issue();

        /*
         | Its outstanding is moved by settling part of it, not by editing
         | it. An issued invoice cannot be changed - the customer holds a
         | copy and the hash was taken over it - and the outstanding
         | figure is computed from the matches anyway, which is the thing
         | the pass actually compares against.
         */
        $this->applyAMatchDirectly(
            'NEAR-5-PART',
            '0.3000',
            (string) $near->getKey(),
            'a part payment that leaves this invoice near the other one',
        );

        $this->importLines([$this->line((string) $exact->gross_amount, ['bank_reference' => 'NEAR-5'])]);

        $considered = app(\App\Tenant\Banking\Matching\MatchingEngine::class)
            ->consider($this->transactionBy('NEAR-5'));

        /*
         | An exact amount identifies a document; a near one narrows it.
         |
         | With a document settled exactly by this payment, the near
         | misses are not worth proposing at all - and if they were,
         | scoring them the same would let a near miss on the wrong
         | invoice outrank the exact match on the right one.
         */
        foreach ($considered['candidates'] as $candidate) {
            if ($candidate->pass !== 'amount_party_window') {
                continue;
            }

            if (\in_array('amount_nearly_settles_it', array_column($candidate->factors, 'factor'), true)) {
                return ['a near miss was proposed although a document is settled exactly by this payment'];
            }
        }

        return [];
    }

    // ---- The report -------------------------------------------------------

    /** @return list<string> */
    private function reportGroups(): array
    {
        $this->recordA(DifferenceType::BankCharge, '18.0000', '2026-05-04', 'DIF-R1');
        $this->recordA(DifferenceType::BankCharge, '22.0000', '2026-05-18', 'DIF-R2');
        $this->recordA(DifferenceType::Rounding, '0.0100', '2026-05-18', 'DIF-R3');

        $report = app(Differences::class)->report($this->entityId);

        $failures = [];

        if (! isset($report['by_type']['bank_charge']) || $report['by_type']['bank_charge']['items'] !== 2) {
            $failures[] = 'two bank charges are not reported as two of one type';
        }

        if (! isset($report['by_type']['rounding'])) {
            $failures[] = 'a rounding difference does not appear in the report by type';
        }

        if (! isset($report['by_period']['2026-05'])) {
            $failures[] = 'the report does not group by period, so a rising line cannot be seen';
        }

        if (isset($report['by_type']['bank_charge'])
            && ! $report['by_type']['bank_charge']['total']->amount->isEqualTo('40.0000')) {
            $failures[] = sprintf(
                'two charges of 18 and 22 total %s',
                (string) $report['by_type']['bank_charge']['total']->amount,
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function risingIsReported(): array
    {
        $this->recordA(DifferenceType::BankCharge, '10.0000', '2026-03-10', 'DIF-M1');
        $this->recordA(DifferenceType::BankCharge, '17.0000', '2026-04-10', 'DIF-M2');
        $this->recordA(DifferenceType::BankCharge, '24.0000', '2026-05-10', 'DIF-M3');

        $report = app(Differences::class)->report($this->entityId);

        /*
         | A rising bank-charge line is information.
         |
         | A bank that repriced, a payment route that should change, a
         | customer base that started paying by a more expensive method -
         | none of those are visible in a single total, which is why the
         | report says which lines are rising rather than only what they
         | add to.
         */
        return $report['trends'] !== []
            ? []
            : ['three months of rising bank charges are reported as a total, so the trend that is the information is not in the report'];
    }

    /** @return list<string> */
    private function twoMonthsIsNotATrend(): array
    {
        $this->recordA(DifferenceType::BankCharge, '10.0000', '2026-04-10', 'DIF-T1');
        $this->recordA(DifferenceType::BankCharge, '20.0000', '2026-05-10', 'DIF-T2');

        $report = app(Differences::class)->report($this->entityId);

        return $report['trends'] === []
            ? []
            : ['two points were called a trend, and a report that cries wolf about bank charges is one people stop opening'];
    }

    // ---- Helpers ----------------------------------------------------------

    private function recordA(DifferenceType $type, string $amount, string $on, string $reference): void
    {
        $this->openThePeriodFor($on);
        $this->importLines([$this->line('-'.$amount, ['bank_reference' => $reference])]);

        app(Differences::class)->record(
            $type,
            Money::of($amount, 'SAR'),
            [
                'entity_id' => $this->entityId,
                'subject_type' => 'bank_transaction',
                'subject_id' => (string) $this->transactionBy($reference)->getKey(),
                'counter_account_id' => $this->bankGlAccountId,
                'arose_on' => $on,
            ],
            $this->actorId(),
            sprintf('a %s recorded for the purposes of this check', $type->labelEn()),
        );
    }

    /**
     * Open the period a date falls in, if it is not open already.
     *
     * A generated calendar's periods start `future`, and `future` does not
     * accept postings - which is right, and is what stops December being
     * posted into in March. The trend checks need three months of history,
     * so they open the three months rather than pretending a difference
     * can arise in a period nobody opened.
     */
    private function openThePeriodFor(string $date): void
    {
        $period = app(\App\Tenant\Ledger\Services\FiscalCalendarGenerator::class)
            ->periodFor($this->entityId, \Illuminate\Support\Carbon::parse($date));

        if ($period === null || $period->state === \App\Tenant\Ledger\Enums\PeriodState::Open) {
            return;
        }

        app(\App\Tenant\Ledger\Services\PeriodStateMachine::class)->transition(
            $period,
            \App\Tenant\Ledger\Enums\PeriodState::Open,
            'opened so a difference can arise in the month it arose in',
        );
    }

    private function accountFor(ControlRole $role): string
    {
        return (string) AccountRole::query()
            ->where('entity_id', $this->entityId)
            ->where('role', $role->value)
            ->value('account_id');
    }
}
