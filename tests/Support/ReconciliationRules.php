<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Closing\ClosingChecks;
use App\Tenant\Banking\Closing\ReconciliationCheck;
use App\Tenant\Banking\Models\BankStatement;
use App\Tenant\Banking\Services\Reconciliations;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A reconciliation is a document - PF-165.
 *
 * ## What an auditor re-performs
 *
 * Not "the account was reconciled on the 3rd". They need the statement it
 * was done against, the balance it agreed to, the ledger balance at that
 * moment, the items that bridged the two, who authorised it and when -
 * and all of those **as they stood**, because that is what re-performance
 * means.
 *
 * So the figures are frozen. A screen that recomputes when you open it
 * answers "is this correct now", which is useful and is not the question
 * an audit asks. More than useful, it is dangerous: a reconciliation that
 * restates itself every time somebody posts a prior-period entry cannot
 * be evidence of anything, and the restatement is silent.
 *
 * Most of these checks are about that one property, from different
 * angles: the figures do not move, the arithmetic is asserted rather than
 * shown, and undoing keeps the document.
 *
 * ## And the engine does not teach itself
 *
 * A correction is stored as a suggestion with a count, never applied. An
 * engine that changed its behaviour because one person disagreed with it
 * once would propose something different this month, and the only
 * explanation would be a correction somebody made in March.
 */
final class ReconciliationRules
{
    use \Tests\Support\Concerns\OpensBankAccounts;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['reconciliation (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheBankSide();
            $this->postTheOpeningEntry();

            return $this->runEachInASavepoint([
                // ---- The document --------------------------------------
                'a reconciliation names its statement, balances and author' => fn (): array => $this->theDocument(),
                'the arithmetic is asserted, not displayed' => fn (): array => $this->arithmeticIsAsserted(),
                'the database refuses a statement that does not add up' => fn (): array => $this->databaseRefusesTheIdentity(),
                'the figures do not move when the ledger does' => fn (): array => $this->figuresAreFrozen(),
                'an account that does not reconcile is not reconciled' => fn (): array => $this->refusedWhenItDoesNotAgree(),
                'one statement carries one standing reconciliation' => fn (): array => $this->oneLivePerStatement(),

                // ---- Undoing -------------------------------------------
                'undoing states a reason' => fn (): array => $this->undoNeedsAReason(),
                'undoing keeps the document exactly as it was' => fn (): array => $this->undoKeepsIt(),
                'undoing twice is refused' => fn (): array => $this->undoTwice(),
                'an undone statement can be reconciled again' => fn (): array => $this->reconcileAgainAfterUndo(),

                // ---- Corrections become suggestions --------------------
                'a correction is stored as a suggestion, not applied' => fn (): array => $this->correctionIsASuggestion(),
                'the same correction twice is one suggestion, counted' => fn (): array => $this->correctionsAreCounted(),
                'a suggestion is offered only once it has happened enough' => fn (): array => $this->suggestionsNeedRepetition(),

                // ---- The close -----------------------------------------
                'a period does not close over an unreconciled account' => fn (): array => $this->closeIsRefused(),
                'a reconciliation lets the period close' => fn (): array => $this->reconciledLetsItClose(),
                'an account with no statements does not block the close' => fn (): array => $this->noStatementsDoesNotBlock(),
                'a reconciliation of half the period does not close it' => fn (): array => $this->partialPeriodDoesNotClose(),
                'the check is registered with the period close' => fn (): array => $this->checkIsWired(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    // ---- The document -----------------------------------------------------

    /** @return list<string> */
    private function theDocument(): array
    {
        $statement = $this->aReconcilableStatement();
        $result = app(Reconciliations::class)->reconcile($statement, $this->actorId());

        $document = $result['statement'];
        $failures = [];

        foreach (['period', 'statement_balance', 'ledger_balance', 'items', 'authorised_by', 'authorised_at'] as $part) {
            if (! isset($document[$part])) {
                $failures[] = sprintf('the reconciliation carries no %s, so it cannot be re-performed', $part);
            }
        }

        if (($document['period']['to'] ?? null) !== $statement->period_to->toDateString()) {
            $failures[] = 'the document does not name the statement period it was reconciled against';
        }

        if (\count($document['items'] ?? []) !== 4) {
            $failures[] = sprintf(
                'the statement itemises %d reconciling items where the identity has four',
                \count($document['items'] ?? []),
            );
        }

        if (($document['matched_lines'] ?? null) === null || ($document['unexplained_lines'] ?? null) === null) {
            $failures[] = 'the document does not say how many lines it covered, so "the full set of matches" is a claim';
        }

        return $failures;
    }

    /** @return list<string> */
    private function arithmeticIsAsserted(): array
    {
        $statement = $this->aReconcilableStatement();
        $result = app(Reconciliations::class)->reconcile($statement, $this->actorId());

        $document = $result['statement'];

        /*
         | Asserted, not displayed.
         |
         | A reconciliation statement that shows the arithmetic and does
         | not check it is a page somebody reads past - and the figures
         | all look plausible, which is why nobody would notice. The
         | service refuses to present a document whose items do not
         | bridge, so reaching this line at all is half the assertion;
         | re-performing the arithmetic here is the other half.
         */
        /*
         | Added exactly as shown, in the order shown.
         |
         | Each item is signed as its contribution, so a reader adds them
         | to the statement balance and arrives at the ledger. That is
         | the arithmetic on the page, and it is what this re-performs -
         | re-deriving it from the stored columns instead would be
         | checking the constraint, which the database already does.
         */
        $bridge = $document['statement_balance'];

        foreach ($document['items'] as $item) {
            $bridge = $bridge->plus($item);
        }

        return $bridge->equals($document['ledger_balance'])
            ? []
            : [sprintf(
                'the items as presented bridge the statement balance to %s and the document claims '.
                '%s, so the lines somebody reads do not add to the figure beside them',
                (string) $bridge->amount,
                (string) $document['ledger_balance']->amount,
            )];
    }

    /** @return list<string> */
    private function databaseRefusesTheIdentity(): array
    {
        /*
         | Written straight to the table, past the service.
         |
         | The identity is the whole meaning of the document. A row that
         | does not satisfy it is a reconciliation asserting something
         | untrue, and an import or a correction script reaches this table
         | without passing a service.
         */
        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('reconciliations')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'bank_account_id' => (string) $this->bank()->getKey(),
            'bank_statement_id' => (string) Str::ulid(),
            'period_from' => $this->openingDate()->toDateString(),
            'period_to' => $this->date->toDateString(),
            'ledger_balance' => '1000.0000',
            'statement_balance' => '900.0000',
            'unpresented' => '0.0000',
            'unsettled' => '0.0000',
            'unrecorded' => '0.0000',
            'unposted_opening' => '0.0000',
            'currency' => 'SAR',
            'matched_lines' => 0,
            'unexplained_lines' => 0,
            'authorised_by' => $this->actorId(),
            'authorised_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $refusal !== null
            ? []
            : ['a reconciliation was stored claiming a ledger of 1000 against a statement of 900 with nothing bridging them'];
    }

    /** @return list<string> */
    private function figuresAreFrozen(): array
    {
        $statement = $this->aReconcilableStatement();
        $result = app(Reconciliations::class)->reconcile($statement, $this->actorId());

        $before = (string) $result['statement']['ledger_balance']->amount;

        /*
         | An entry lands INSIDE the reconciled period, afterwards.
         |
         | Which is ordinary - an accrual, a correction, a late invoice
         | dated earlier - and it changes what the ledger balance for that
         | period would be if anybody recomputed it. Posting after the
         | period instead would prove nothing: a recomputation as at the
         | statement date would not see it either, and the check would
         | pass whatever the service did.
         */
        $this->postTheOpeningEntry('250.0000', $this->openingDate()->copy()->addDay());

        $after = (string) app(Reconciliations::class)
            ->statementFor((string) $result['id'])['ledger_balance']->amount;

        return $before === $after
            ? []
            : [sprintf(
                'the reconciliation reported a ledger balance of %s and now reports %s, so a '.
                'posting restated a document somebody authorised and nothing said so',
                $before,
                $after,
            )];
    }

    /** @return list<string> */
    private function refusedWhenItDoesNotAgree(): array
    {
        $statement = $this->aReconcilableStatement();

        /*
         | A ledger movement inside the statement period that the bank
         | does not have.
         |
         | Not an unrecorded bank line - that is one of the four items and
         | is accounted for by construction. This is the case the items do
         | NOT explain: somebody posted to the bank account and the bank
         | never saw it. Signing a document over it would be asserting an
         | identity that does not hold.
         */
        $this->postTheOpeningEntry('777.0000', $this->openingDate()->copy()->addDay());

        $refusal = $this->refusal(fn () => app(Reconciliations::class)->reconcile($statement, $this->actorId()));

        if ($refusal === null) {
            return ['an account with an unexplained difference was reconciled, which asserts an identity that does not hold'];
        }

        return str_contains($refusal, 'not accounted for')
            ? []
            : [sprintf('the refusal does not say what is unaccounted for: %s', $refusal)];
    }

    /** @return list<string> */
    private function oneLivePerStatement(): array
    {
        $statement = $this->aReconcilableStatement();
        app(Reconciliations::class)->reconcile($statement, $this->actorId());

        $refusal = $this->refusal(fn () => app(Reconciliations::class)->reconcile($statement, $this->actorId()));

        if ($refusal === null) {
            return ['one statement carries two standing reconciliations, so nothing says which the close should look at'];
        }

        /*
         | The service's refusal, not the index's.
         |
         | A partial unique index refuses it too, which is why it is
         | there. A person reconciling a statement twice should be told
         | when it was first done and to undo it with a reason, not
         | handed a constraint name.
         */
        return str_contains($refusal, 'already reconciled on')
            ? []
            : [sprintf('the refusal came from the index rather than from reconciling: %s', $refusal)];
    }

    // ---- Undoing ----------------------------------------------------------

    /** @return list<string> */
    private function undoNeedsAReason(): array
    {
        $statement = $this->aReconcilableStatement();
        $result = app(Reconciliations::class)->reconcile($statement, $this->actorId());

        $refusal = $this->refusal(fn () => app(Reconciliations::class)->undo(
            (string) $result['id'],
            $this->actorId(),
            'wrong',
        ));

        if ($refusal === null) {
            return ['a reconciliation was withdrawn with no reason, which is the reversal an auditor stops on'];
        }

        return str_contains($refusal, 'withdraws a statement somebody authorised')
            ? []
            : [sprintf('the refusal came from the table rather than from undoing: %s', $refusal)];
    }

    /** @return list<string> */
    private function undoKeepsIt(): array
    {
        $statement = $this->aReconcilableStatement();
        $result = app(Reconciliations::class)->reconcile($statement, $this->actorId());

        $before = app(Reconciliations::class)->statementFor((string) $result['id']);

        app(Reconciliations::class)->undo(
            (string) $result['id'],
            $this->actorId(),
            'the February statement was superseded and this was reconciled against the old one',
        );

        $after = app(Reconciliations::class)->statementFor((string) $result['id']);

        $failures = [];

        if (! $after['statement_balance']->equals($before['statement_balance'])
            || ! $after['ledger_balance']->equals($before['ledger_balance'])) {
            $failures[] = 'withdrawing a reconciliation changed its figures, so the document is not what was authorised';
        }

        if ($after['undone_at'] === null) {
            $failures[] = 'the withdrawal is not recorded on the document';
        }

        $row = DB::connection('tenant')->table('reconciliations')->where('id', (string) $result['id'])->first();

        if ($row === null) {
            $failures[] = 'the reconciliation was deleted, so "it was reconciled and then it was not" has no answer';
        } elseif (trim((string) ($row->undone_reason ?? '')) === '') {
            $failures[] = 'the withdrawal carries no reason';
        }

        return $failures;
    }

    /** @return list<string> */
    private function undoTwice(): array
    {
        $statement = $this->aReconcilableStatement();
        $result = app(Reconciliations::class)->reconcile($statement, $this->actorId());

        app(Reconciliations::class)->undo(
            (string) $result['id'],
            $this->actorId(),
            'withdrawn because the statement was superseded',
        );

        $refusal = $this->refusal(fn () => app(Reconciliations::class)->undo(
            (string) $result['id'],
            $this->actorId(),
            'withdrawing it a second time',
        ));

        return $refusal !== null
            ? []
            : ['a document that is no longer standing was withdrawn again, which records a second withdrawal of nothing'];
    }

    /** @return list<string> */
    private function reconcileAgainAfterUndo(): array
    {
        $statement = $this->aReconcilableStatement();
        $first = app(Reconciliations::class)->reconcile($statement, $this->actorId());

        app(Reconciliations::class)->undo(
            (string) $first['id'],
            $this->actorId(),
            'withdrawn so the statement can be reconciled again',
        );

        /*
         | And the statement can be reconciled again.
         |
         | Which is the point of withdrawing rather than blocking: the
         | moment somebody needs to redo a reconciliation is the moment
         | after they found the first one wrong.
         */
        $refusal = $this->refusal(fn () => app(Reconciliations::class)->reconcile($statement, $this->actorId()));

        return $refusal === null
            ? []
            : [sprintf('a withdrawn statement could not be reconciled again: %s', $refusal)];
    }

    // ---- Corrections ------------------------------------------------------

    /** @return list<string> */
    private function correctionIsASuggestion(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, ['bank_reference' => 'REC-CORR'])]);
        $transaction = $this->transactionBy('REC-CORR');

        $before = DB::connection('tenant')->table('matching_rules')
            ->where('entity_id', $this->entityId)
            ->count();

        $result = app(Reconciliations::class)->noteACorrection(
            entityId: $this->entityId,
            bankTransactionId: (string) $transaction->getKey(),
            bookingDate: $transaction->booking_date->toDateString(),
            rejectedPass: 'amount_party_window',
            rejectedId: null,
            correctedType: 'invoice',
            correctedId: (string) $invoice->getKey(),
            conditions: ['counterparty_name_contains' => 'Gulf Retail'],
        );

        $after = DB::connection('tenant')->table('matching_rules')
            ->where('entity_id', $this->entityId)
            ->count();

        $failures = [];

        /*
         | The engine is not told.
         |
         | An engine that changed its behaviour because one person
         | disagreed with it once would propose something different this
         | month, and the only explanation would be a correction somebody
         | made in March.
         */
        if ($after !== $before) {
            $failures[] = 'a correction wrote a matching rule, so the engine taught itself from one disagreement';
        }

        $candidate = DB::connection('tenant')->table('rule_candidates')
            ->where('id', (string) $result['id'])
            ->first();

        if ($candidate === null) {
            $failures[] = 'the correction was not recorded at all, so it can never be offered back';
        } elseif ((string) $candidate->status !== 'open') {
            $failures[] = sprintf('the suggestion was stored as %s rather than open', (string) $candidate->status);
        }

        return $failures;
    }

    /** @return list<string> */
    private function correctionsAreCounted(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, ['bank_reference' => 'REC-C2'])]);
        $transaction = $this->transactionBy('REC-C2');

        $conditions = ['counterparty_name_contains' => 'Gulf Retail'];

        $first = app(Reconciliations::class)->noteACorrection(
            $this->entityId,
            (string) $transaction->getKey(),
            $transaction->booking_date->toDateString(),
            'amount_party_window',
            null,
            'invoice',
            (string) $invoice->getKey(),
            $conditions,
        );

        $second = app(Reconciliations::class)->noteACorrection(
            $this->entityId,
            (string) $transaction->getKey(),
            $transaction->booking_date->toDateString(),
            'amount_party_window',
            null,
            'invoice',
            (string) $invoice->getKey(),
            $conditions,
        );

        $failures = [];

        /*
         | One suggestion, counted - not two suggestions.
         |
         | One person correcting one match is an exception. The same
         | correction four times is a rule nobody has written, and the
         | count is what tells them apart. Two rows would make a list of
         | suggestions that nobody can rank.
         */
        if ((string) $first['id'] !== (string) $second['id']) {
            $failures[] = 'the same correction made twice produced two suggestions, so a list of them cannot be ranked';
        }

        if (($second['seen_times'] ?? 0) !== 2) {
            $failures[] = sprintf(
                'the same correction twice is counted %d times, so repetition does not make a '.
                'suggestion stronger',
                (int) ($second['seen_times'] ?? 0),
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function suggestionsNeedRepetition(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, ['bank_reference' => 'REC-C3'])]);
        $transaction = $this->transactionBy('REC-C3');

        $conditions = ['counterparty_name_contains' => 'Gulf Retail'];

        $note = fn (): array => app(Reconciliations::class)->noteACorrection(
            $this->entityId,
            (string) $transaction->getKey(),
            $transaction->booking_date->toDateString(),
            'amount_party_window',
            null,
            'invoice',
            (string) $invoice->getKey(),
            $conditions,
        );

        $note();

        if (app(Reconciliations::class)->suggestions($this->entityId) !== []) {
            return ['one correction was offered back as a rule, so an exception becomes a policy the first time it happens'];
        }

        $note();
        $note();

        return app(Reconciliations::class)->suggestions($this->entityId) !== []
            ? []
            : ['a correction made three times is still not offered as a rule, so the same work is done by hand forever'];
    }

    // ---- The close --------------------------------------------------------

    /** @return list<string> */
    private function closeIsRefused(): array
    {
        $this->importLines([$this->line('120.0000', ['bank_reference' => 'REC-CLOSE'])]);

        $reasons = $this->closingReasons();

        if ($reasons === []) {
            return ['a period with a month of unreconciled bank movements closed with nothing said'];
        }

        /*
         | The reason says why cash in particular.
         |
         | "The account is not reconciled" is a fact somebody dismisses.
         | "Cash is the one balance an auditor can confirm independently"
         | is the same fact with the reason it matters attached.
         */
        return str_contains($reasons[0], 'independently') || str_contains($reasons[0], 'unverified')
            ? []
            : [sprintf('the refusal does not say why this one matters: %s', $reasons[0])];
    }

    /** @return list<string> */
    private function reconciledLetsItClose(): array
    {
        $statement = $this->aReconcilableStatement();
        app(Reconciliations::class)->reconcile($statement, $this->actorId());

        $reasons = $this->closingReasons($statement->period_to->copy()->addDay()->toDateString());

        return $reasons === []
            ? []
            : [sprintf('a reconciled account still blocks the close: %s', $reasons[0])];
    }

    /** @return list<string> */
    private function noStatementsDoesNotBlock(): array
    {
        /*
         | A period with no statements at all.
         |
         | An account opened mid-period with nothing on it yet has nothing
         | to reconcile, and refusing a close over an absence of facts
         | would make the control the thing people route around.
         */
        $reasons = app(ReconciliationCheck::class)->reasonsToRefuse(
            $this->entityId,
            '2026-01',
            '2026-01-01',
            '2026-02-01',
        );

        return $reasons === []
            ? []
            : [sprintf('a month with no bank statements blocked the close: %s', $reasons[0])];
    }

    /** @return list<string> */
    private function partialPeriodDoesNotClose(): array
    {
        $statement = $this->aReconcilableStatement();
        app(Reconciliations::class)->reconcile($statement, $this->actorId());

        /*
         | The statement ends mid-month; the period does not.
         |
         | A reconciliation of the first half of March says nothing about
         | the second half, and a check that accepted any reconciliation
         | for the account would let March close on it.
         */
        $until = $statement->period_to->copy()->addDays(20)->toDateString();

        $this->importLines([$this->line('95.0000', ['bank_reference' => 'REC-LATE'])]);

        $reasons = $this->closingReasons($until);

        return $reasons !== []
            ? []
            : ['a reconciliation covering half the period closed the whole of it'];
    }

    /** @return list<string> */
    private function checkIsWired(): array
    {
        return app(ClosingChecks::class)->has('bank reconciliation')
            ? []
            : ['the reconciliation check is not registered, so a period closes over an unreconciled account however well the check works'];
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * A statement this account reconciles against cleanly.
     *
     * The fixture's own first statement: every line on it is accounted
     * for, which is what makes it the baseline the reconciling items are
     * measured from.
     */
    private function aReconcilableStatement(): BankStatement
    {
        $existing = BankStatement::query()
            ->where('bank_account_id', (string) $this->bank()->getKey())
            ->whereNull('superseded_at')
            ->orderByDesc('period_to')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        /*
         | The fixture's own first statement.
         |
         | Every line on it is accounted for, which is what makes it the
         | baseline the reconciling items are measured from - and what
         | lets the identity hold, so the document can be authorised at
         | all.
         */
        $this->importFirstStatement();

        return BankStatement::query()
            ->where('bank_account_id', (string) $this->bank()->getKey())
            ->whereNull('superseded_at')
            ->orderByDesc('period_to')
            ->firstOrFail();
    }

    /** @return list<string> */
    private function closingReasons(?string $until = null): array
    {
        $until ??= $this->date->copy()->addDay()->toDateString();

        return app(ReconciliationCheck::class)->reasonsToRefuse(
            $this->entityId,
            $this->date->format('Y-m'),
            $this->openingDate()->toDateString(),
            $until,
        );
    }
}
