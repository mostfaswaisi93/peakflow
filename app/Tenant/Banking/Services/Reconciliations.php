<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Banking\Exceptions\MatchRefused;
use App\Tenant\Banking\Models\BankAccount;
use App\Tenant\Banking\Models\BankStatement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A reconciliation is a document, not a screen - PF-165.
 *
 * ## The difference, and why it is the whole task
 *
 * A screen answers "does this account reconcile now". A document answers
 * "was it reconciled in May, against what, by whom, and does the
 * arithmetic still hold". Those are different questions and only the
 * second is the one an audit asks.
 *
 * The distinction shows up in one decision: the figures are **frozen**.
 * `ledger_balance`, `statement_balance` and the four reconciling items are
 * stored as they stood. Recomputing them when somebody opens the document
 * would silently restate every past reconciliation the moment a
 * prior-period entry was posted - and a reconciliation that restates
 * itself is not evidence of anything.
 *
 * ## The arithmetic is asserted, not displayed
 *
 * PF-165 asks for exactly that. A reconciliation statement showing
 * `ledger + this - that = statement` and not checking it is a page
 * somebody reads past; the identity holds at the moment of reconciling or
 * the row does not exist, and the table says so too.
 *
 * ## Unreconciling keeps the old one
 *
 * With a reason and an author. "It was reconciled and then it was not -
 * what changed" is the question, and a table that overwrites cannot
 * answer it. The new reconciliation points back at what it superseded.
 *
 * That last sentence was written when the table was created and nothing
 * kept it - `superseded_by_id` was written by nothing and read by nothing
 * until PF-247, while this comment, the migration and the sentence `undo()`
 * returns to the user all described the chain as though it existed.
 *
 * ## A restatement withdraws the reconciliation it invalidates
 *
 * `reconcile()` refuses a statement that already carries a standing
 * reconciliation, and `reconciliations_one_live_per_statement` says the
 * same in the database. Both are keyed on the STATEMENT, so when a bank
 * reissues one neither sees anything: the old statement's reconciliation is
 * still standing and the new statement is a different row. Two documents
 * then stand for one account and one period - the exact condition the
 * refusal exists to prevent, arriving through the next door along.
 *
 * So reconciling a statement withdraws any standing reconciliation of the
 * statements it replaces, with a reason naming the restatement, and points
 * each of them at the new document. Withdrawn rather than refused because
 * the reason is not a judgement somebody brings: it is a fact the system
 * already holds.
 *
 * ## A correction is a suggestion, never a lesson learned silently
 *
 * When somebody rejects what the engine proposed and matches something
 * else, that correction is stored as a **rule candidate**. The engine does
 * not change its own behaviour because a person disagreed with it once:
 * an engine that did would propose something different this month, and
 * the only explanation would be a correction somebody made in March.
 *
 * When the same correction has been made enough times it is offered back
 * as a rule for somebody to write deliberately - which is a rule with an
 * author, a version and a test, like every other rule in this module.
 */
final class Reconciliations
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly BankBalances $balances,
        private readonly Splits $splits,
    ) {}

    /**
     * Reconcile a bank account against a statement.
     *
     * @return array<string, mixed>
     *
     * @throws MatchRefused
     */
    public function reconcile(
        BankStatement $statement,
        string $userId,
        ?Carbon $asOf = null,
    ): array {
        $account = BankAccount::query()->findOrFail((string) $statement->bank_account_id);
        $asOf ??= Carbon::parse($statement->period_to->toDateString());

        $live = $this->liveFor((string) $statement->getKey());

        if ($live !== null) {
            throw MatchRefused::because(sprintf(
                'This statement was already reconciled on %s. Reconciling it again would leave two '.
                'documents for one statement with nothing saying which the period close should '.
                'look at - undo the first, with a reason, if it was wrong.',
                Carbon::parse((string) $live->authorised_at)->toDateString(),
            ));
        }

        $balances = $this->balances->all($account, $asOf);
        $components = $balances['reconciliation']['components'];

        if ($balances['reconciliation']['agrees'] !== true) {
            /*
             | An account that does not reconcile is not reconciled.
             |
             | The unexplained component is the difference the four items
             | do not account for, and signing a document over it would be
             | asserting an identity that does not hold. The refusal says
             | the figure, because "it does not reconcile" is not
             | something anybody can act on.
             */
            throw MatchRefused::because(sprintf(
                'This account does not reconcile: %s of the difference between the ledger and the '.
                'statement is not accounted for by unpresented payments, unsettled receipts, '.
                'unrecorded bank items or an unposted opening balance. %s',
                (string) Money::of((string) $components['unexplained'], (string) $balances['currency'])
                    ->abs()->toPresentation(),
                (string) $balances['reconciliation']['why'],
            ));
        }

        $counts = $this->lineCounts($account, $statement);

        return DB::connection('tenant')->transaction(function () use (
            $statement,
            $account,
            $balances,
            $components,
            $counts,
            $userId,
            $asOf,
        ): array {
            $id = (string) Str::ulid();

            DB::connection('tenant')->table('reconciliations')->insert([
                'id' => $id,
                'entity_id' => (string) $account->entity_id,
                'bank_account_id' => (string) $account->getKey(),
                'bank_statement_id' => (string) $statement->getKey(),
                'period_from' => $statement->period_from->toDateString(),
                'period_to' => $statement->period_to->toDateString(),

                /*
                 | Frozen, with the components signed as the identity
                 | needs them rather than as the itemisation presents
                 | them.
                 |
                 | The itemisation signs each component as its
                 | contribution to the difference, which is what a screen
                 | wants. The constraint on the table checks the identity
                 | itself, so the figures go in as magnitudes and the
                 | signs live in the CHECK where they can be read.
                 */
                'ledger_balance' => (string) $balances['ledger'],
                'statement_balance' => (string) $balances['statement'],
                'unpresented' => (string) Money::of((string) $components['unpresented_payments'], (string) $balances['currency'])->negated()->amount,
                'unsettled' => (string) Money::of((string) $components['unsettled_receipts'], (string) $balances['currency'])->amount,
                'unrecorded' => (string) Money::of((string) $components['unrecorded_bank_items'], (string) $balances['currency'])->negated()->amount,
                'unposted_opening' => (string) Money::of((string) $components['unposted_opening_balance'], (string) $balances['currency'])->negated()->amount,
                'currency' => (string) $balances['currency'],

                'matched_lines' => $counts['matched'],
                'unexplained_lines' => $counts['unexplained'],

                'authorised_by' => $userId,
                'authorised_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $replaced = $this->linkWhatThisReplaces($statement, $id, $userId);

            $this->audit->record(
                category: 'banking',
                action: 'reconciliation.authorised',
                subjectType: 'bank_statement',
                subjectId: (string) $statement->getKey(),
                subjectLabel: sprintf(
                    '%s to %s on %s',
                    $statement->period_from->toDateString(),
                    $statement->period_to->toDateString(),
                    (string) $account->label,
                ),
                after: [
                    'ledger_balance' => (string) $balances['ledger'],
                    'statement_balance' => (string) $balances['statement'],
                    'matched_lines' => $counts['matched'],
                    'unexplained_lines' => $counts['unexplained'],
                    'replaces' => $replaced,
                ],
                reason: 'the ledger and the statement agree once the reconciling items are applied',
                entityId: (string) $account->entity_id,
            );

            return [
                'id' => $id,
                'statement' => $this->statementFor($id),
                'why' => sprintf(
                    'Reconciled to %s. %d line%s matched, %d unexplained. The figures are kept as '.
                    'they stand today - this document does not change when a prior-period entry is '.
                    'posted, which is what makes it something an auditor can re-perform.',
                    $statement->period_to->toDateString(),
                    $counts['matched'],
                    $counts['matched'] === 1 ? '' : 's',
                    $counts['unexplained'],
                ),
            ];
        });
    }

    /**
     * Point every reconciliation this one replaces at it - PF-247.
     *
     * ## The column three comments described and nothing wrote
     *
     * `reconciliations.superseded_by_id` existed from the table's first
     * migration, which says it "points forward to the reconciliation that
     * replaced this one, so the chain reads in the direction somebody asks
     * it in". The class docblock said "the new reconciliation points back at
     * what it superseded". `undo()` returns a sentence to the user promising
     * "the statement can be reconciled again and the new one will point back
     * at this."
     *
     * Measured: the column was written by nothing and read by nothing.
     *
     * ## Two ways one reconciliation replaces another
     *
     * The first is the one `undo()` promises: the same statement is
     * withdrawn and reconciled again.
     *
     * The second is the one that was actually broken. A bank reissues a
     * statement, `StatementImport::supersede()` marks the old row
     * `superseded`, and the new one is imported and reconciled. The old
     * statement's reconciliation is untouched - `undone_at` is still null,
     * so it is still STANDING - and `reconciliations_one_live_per_statement`
     * does not catch it, because the two are live against two different
     * statement rows.
     *
     * The result is two standing reconciliations for one account and one
     * period, with nothing saying which the period close should look at.
     * That is word for word the condition `reconcile()` refuses when it is
     * the same statement twice; a restatement produced it through the next
     * door along, and `BankReconciliationReport` listed both as standing.
     *
     * ## Withdrawn here rather than refused
     *
     * The alternative was to refuse until somebody undid the old one by
     * hand, which is what the same-statement case does. It is the wrong
     * answer here: the reason is not a judgement the person brings, it is a
     * fact the system already holds - the bank replaced the statement, and
     * `supersession_reason` on the statement row says why. Asking somebody
     * to retype it would produce a worse record than copying it.
     *
     * The withdrawal is attributed to whoever is reconciling, because they
     * are the one doing it, and the audit entry names the statement that
     * replaced the old one.
     *
     * ## Walked back, because a restatement can itself be restated
     *
     * old → mid → new, where `mid` was never reconciled, leaves `old`'s
     * reconciliation standing and one hop further away than a single lookup
     * would find. The walk is bounded: a cycle in that chain is impossible
     * through `supersede()` - it refuses a statement superseding itself -
     * but a bound costs nothing and an unbounded loop over data is not
     * something to leave in a service.
     *
     * @return list<string> the ids that now point at this one
     */
    private function linkWhatThisReplaces(BankStatement $statement, string $newId, string $userId): array
    {
        $statementIds = [(string) $statement->getKey()];
        $frontier = [(string) $statement->getKey()];

        for ($hop = 0; $hop < 20 && $frontier !== []; $hop++) {
            $frontier = DB::connection('tenant')->table('bank_statements')
                ->whereIn('superseded_by_id', $frontier)
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            $statementIds = [...$statementIds, ...$frontier];
        }

        $predecessors = DB::connection('tenant')->table('reconciliations')
            ->whereIn('bank_statement_id', $statementIds)
            ->where('id', '!=', $newId)
            ->whereNull('superseded_by_id')
            ->orderBy('authorised_at')
            ->get();

        $linked = [];

        foreach ($predecessors as $row) {
            $update = ['superseded_by_id' => $newId, 'updated_at' => now()];

            if ($row->undone_at === null) {
                /*
                 | Still standing, so it is withdrawn as part of this.
                 |
                 | Reached only through the restatement branch: the same
                 | statement cannot have a live reconciliation here, because
                 | `reconcile()` refused before any of this ran.
                 |
                 | The reason names the statement that replaced the one this
                 | document reconciled, which is the fact somebody needs when
                 | they come back to ask why a standing reconciliation stopped
                 | standing.
                 */
                $update['undone_at'] = now();
                $update['undone_by'] = $userId;
                $update['undone_reason'] = sprintf(
                    'The statement this reconciled was superseded by %s, which has now been '.
                    'reconciled in its place. Withdrawn so that one document stands for this '.
                    'account and period.',
                    $statement->label(),
                );

                $this->audit->record(
                    category: 'banking',
                    action: 'reconciliation.superseded',
                    subjectType: 'bank_statement',
                    subjectId: (string) $row->bank_statement_id,
                    subjectLabel: sprintf('%s to %s', (string) $row->period_from, (string) $row->period_to),
                    before: [
                        'ledger_balance' => (string) $row->ledger_balance,
                        'statement_balance' => (string) $row->statement_balance,
                        'authorised_by' => (string) $row->authorised_by,
                    ],
                    after: ['superseded_by' => $newId],
                    reason: 'the statement it reconciled was replaced by a restatement, which is now reconciled',
                    entityId: (string) $row->entity_id,
                );
            }

            DB::connection('tenant')->table('reconciliations')
                ->where('id', (string) $row->id)
                ->update($update);

            $linked[] = (string) $row->id;
        }

        return $linked;
    }

    /**
     * The reconciliation statement, as it was authorised.
     *
     * Read back from the stored figures, never recomputed. That is the
     * point of the table.
     *
     * @return array<string, mixed>
     */
    public function statementFor(string $id): array
    {
        $row = DB::connection('tenant')->table('reconciliations')->where('id', $id)->first();

        if ($row === null) {
            throw MatchRefused::because('There is no reconciliation with that id.');
        }

        $currency = (string) $row->currency;
        $money = static fn (string $value): Money => Money::of($value, $currency);

        $ledger = $money((string) $row->ledger_balance);
        $statement = $money((string) $row->statement_balance);

        /*
         | Each item signed as it is shown, and the bridge built from
         | THOSE values.
         |
         | Not from the stored columns. The constraint on the table
         | already holds the row to the identity, so a check against the
         | columns can never fail and is a field that asserts nothing -
         | which is what it was, until a bite that faked the flag changed
         | no outcome at all.
         |
         | What can fail, and is worth failing on, is the PRESENTATION: a
         | statement whose displayed lines do not add to the balance
         | beside them is the specific failure that makes a reconciliation
         | worthless, and it is the one nobody would notice because every
         | figure on the page looks plausible.
         */
        $items = [
            'unpresented payments' => $money((string) $row->unpresented)->negated(),
            'unsettled receipts' => $money((string) $row->unsettled),
            'unrecorded bank items' => $money((string) $row->unrecorded)->negated(),
            'unposted opening balance' => $money((string) $row->unposted_opening)->negated(),
        ];

        $bridge = $statement;

        foreach ($items as $item) {
            $bridge = $bridge->plus($item);
        }

        if (! $bridge->equals($ledger)) {
            /*
             | Refused, not reported as a flag beside the figures.
             |
             | This started as `arithmetic_holds`, and a bite showed the
             | field could not be observed to be wrong: the constraint
             | guarantees the stored row, so the flag was true for every
             | document that exists and hard-coding it changed nothing.
             |
             | A refusal has teeth. A document whose presented lines do
             | not add to the balance beside them is not a document to
             | hand somebody with a warning on it - it is one that must
             | not be handed over at all, because every figure on it
             | looks plausible and the reader has no way to tell.
             */
            throw MatchRefused::because(sprintf(
                'This reconciliation cannot be presented: its items bridge the %s statement balance '.
                'to %s and it records a ledger balance of %s. The stored figures satisfy the '.
                'identity, so the fault is in how they are being shown - which is the one error a '.
                'reader cannot catch, because the page looks right.',
                (string) $statement->toPresentation(),
                (string) $bridge->toPresentation(),
                (string) $ledger->toPresentation(),
            ));
        }

        return [
            'id' => (string) $row->id,
            /*
             | WHICH account, in WHICH entity, against WHICH statement -
             | added for PF-191.
             |
             | The table carries all three and this document carried none
             | of them, so the figures could be re-performed and the thing
             | they were about could not: an auditor holding this array had
             | to go back to the table to learn which bank account it
             | reconciled. The migration's own comment says the period is
             | denormalised here precisely so the document "reads without a
             | join"; these three are the rest of that sentence.
             |
             | Added rather than re-derived in the report, because a report
             | that looked the row up itself would be a second answer to
             | "what is this document about" - and the first thing to drift
             | would be which statement it names.
             */
            'entity_id' => (string) $row->entity_id,
            'bank_account_id' => (string) $row->bank_account_id,
            'bank_statement_id' => (string) $row->bank_statement_id,
            'period' => ['from' => (string) $row->period_from, 'to' => (string) $row->period_to],
            'currency' => $currency,
            'statement_balance' => $statement,
            'items' => $items,
            'ledger_balance' => $ledger,
            'matched_lines' => (int) $row->matched_lines,
            'unexplained_lines' => (int) $row->unexplained_lines,
            'authorised_by' => (string) $row->authorised_by,
            'authorised_at' => (string) $row->authorised_at,
            'undone_at' => $row->undone_at === null ? null : (string) $row->undone_at,

            /*
             | What replaced this, and why it was withdrawn - PF-247.
             |
             | Both, because they answer different halves of the same
             | question. `undone_reason` says what happened; `superseded_by`
             | says where to go next. A document that says it was withdrawn
             | and does not say what stands in its place leaves the reader
             | looking through a list of reconciliations for the same account
             | and guessing by date - which is exactly what they do when the
             | restatement is the reason, because then the replacement is
             | against a DIFFERENT statement and the dates do not line up.
             */
            'undone_reason' => $row->undone_reason === null ? null : (string) $row->undone_reason,
            'superseded_by' => $row->superseded_by_id === null ? null : (string) $row->superseded_by_id,
            'why' => sprintf(
                'The bank says %s. Take away %s not yet presented, add %s not yet settled, take '.
                'away %s the bank has that we have not recorded and %s of opening balance not yet '.
                'posted, and you have the %s in the ledger.',
                (string) $statement->toPresentation(),
                (string) $money((string) $row->unpresented)->abs()->toPresentation(),
                (string) $money((string) $row->unsettled)->abs()->toPresentation(),
                (string) $money((string) $row->unrecorded)->abs()->toPresentation(),
                (string) $money((string) $row->unposted_opening)->abs()->toPresentation(),
                (string) $ledger->toPresentation(),
            ),
        ];
    }

    /**
     * Undo a reconciliation, keeping it.
     *
     * @return array<string, mixed>
     *
     * @throws MatchRefused
     */
    public function undo(string $id, string $userId, string $reason): array
    {
        if (mb_strlen(trim($reason)) <= 10) {
            throw MatchRefused::because(
                'Undoing a reconciliation needs a reason. It withdraws a statement somebody '.
                'authorised, and "it was reconciled and then it was not" is a question an auditor '.
                'asks - a reversal nobody explained is the one they stop on.',
            );
        }

        $row = DB::connection('tenant')->table('reconciliations')->where('id', $id)->first();

        if ($row === null) {
            throw MatchRefused::because('There is no reconciliation with that id.');
        }

        if ($row->undone_at !== null) {
            throw MatchRefused::because(
                'This reconciliation has already been undone. Undoing it again would record a '.
                'second withdrawal of a document that is no longer standing.',
            );
        }

        return DB::connection('tenant')->transaction(function () use ($row, $id, $userId, $reason): array {
            DB::connection('tenant')->table('reconciliations')->where('id', $id)->update([
                'undone_at' => now(),
                'undone_by' => $userId,
                'undone_reason' => $reason,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'banking',
                action: 'reconciliation.undone',
                subjectType: 'bank_statement',
                subjectId: (string) $row->bank_statement_id,
                subjectLabel: sprintf('%s to %s', (string) $row->period_from, (string) $row->period_to),
                before: [
                    'ledger_balance' => (string) $row->ledger_balance,
                    'statement_balance' => (string) $row->statement_balance,
                    'authorised_by' => (string) $row->authorised_by,
                ],
                reason: $reason,
                entityId: (string) $row->entity_id,
            );

            return [
                'id' => $id,
                'why' => 'Withdrawn. The document is kept exactly as it was authorised, with who '.
                    'withdrew it and why beside it - the statement can be reconciled again and the '.
                    'new one will point back at this.',
            ];
        });
    }

    /**
     * Record a correction as a rule candidate - PF-165.
     *
     * The engine is not told. It is offered back, with a count, to
     * somebody who can write a rule deliberately.
     *
     * @param  array<string, mixed>  $conditions
     * @return array<string, mixed>
     */
    public function noteACorrection(
        string $entityId,
        string $bankTransactionId,
        string $bookingDate,
        string $rejectedPass,
        ?string $rejectedId,
        string $correctedType,
        string $correctedId,
        array $conditions,
    ): array {
        $signature = $this->signatureOf($rejectedPass, $conditions, $correctedType);

        return DB::connection('tenant')->transaction(function () use (
            $entityId,
            $bankTransactionId,
            $bookingDate,
            $rejectedPass,
            $rejectedId,
            $correctedType,
            $correctedId,
            $conditions,
            $signature,
        ): array {
            /*
             | The same correction, seen again, is a stronger suggestion.
             |
             | One person correcting one match is an exception. The same
             | correction four times is a rule nobody has written, and
             | the count is what tells them apart - which is why this
             | increments rather than inserting a second row.
             */
            $existing = DB::connection('tenant')->table('rule_candidates')
                ->where('entity_id', $entityId)
                ->where('status', 'open')
                ->whereRaw('md5(conditions::text || rejected_pass || corrected_type) = ?', [$signature])
                ->first();

            if ($existing !== null) {
                DB::connection('tenant')->table('rule_candidates')
                    ->where('id', (string) $existing->id)
                    ->update([
                        'seen_times' => (int) $existing->seen_times + 1,
                        'last_seen_at' => now(),
                        'updated_at' => now(),
                    ]);

                return [
                    'id' => (string) $existing->id,
                    'seen_times' => (int) $existing->seen_times + 1,
                    'why' => sprintf(
                        'The same correction has now been made %d times. It is a rule nobody has '.
                        'written - suggest it rather than letting somebody make it a fifth time.',
                        (int) $existing->seen_times + 1,
                    ),
                ];
            }

            $id = (string) Str::ulid();

            DB::connection('tenant')->table('rule_candidates')->insert([
                'id' => $id,
                'entity_id' => $entityId,
                'bank_transaction_id' => $bankTransactionId,
                'booking_date' => $bookingDate,
                'rejected_pass' => $rejectedPass,
                'rejected_id' => $rejectedId,
                'corrected_type' => $correctedType,
                'corrected_id' => $correctedId,
                'conditions' => json_encode($conditions, JSON_THROW_ON_ERROR),
                'seen_times' => 1,
                'status' => 'open',
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'id' => $id,
                'seen_times' => 1,
                'why' => 'Recorded as a suggestion. The engine has not changed: it will propose the '.
                    'same thing next time, because an engine that learned from one disagreement is '.
                    'one whose behaviour nobody can account for.',
            ];
        });
    }

    /**
     * Corrections worth offering as rules.
     *
     * @return list<array<string, mixed>>
     */
    public function suggestions(string $entityId, int $atLeast = 3): array
    {
        $rows = DB::connection('tenant')->table('rule_candidates')
            ->where('entity_id', $entityId)
            ->where('status', 'open')
            ->where('seen_times', '>=', $atLeast)
            ->orderByDesc('seen_times')
            ->get();

        return $rows->map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'seen_times' => (int) $row->seen_times,
            'rejected_pass' => (string) $row->rejected_pass,
            'corrected_type' => (string) $row->corrected_type,
            'conditions' => json_decode((string) $row->conditions, true, 512, JSON_THROW_ON_ERROR),
            'why' => sprintf(
                'Corrected the same way %d times: the %s pass proposed something a person replaced '.
                'with a %s. Writing it as a rule makes the engine do it deliberately, with an '.
                'author and a version behind it.',
                (int) $row->seen_times,
                str_replace('_', ' ', (string) $row->rejected_pass),
                (string) $row->corrected_type,
            ),
        ])->all();
    }

    /** The reconciliation standing for a statement, if there is one. */
    public function liveFor(string $statementId): ?object
    {
        return DB::connection('tenant')->table('reconciliations')
            ->where('bank_statement_id', $statementId)
            ->whereNull('undone_at')
            ->first();
    }

    /** @return array{matched: int, unexplained: int} */
    private function lineCounts(BankAccount $account, BankStatement $statement): array
    {
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT count(*) FILTER (WHERE m.id IS NOT NULL) AS matched,
                   count(*) FILTER (WHERE m.id IS NULL) AS unexplained
            FROM bank_transactions t
            LEFT JOIN matches m
              ON m.bank_transaction_id = t.id
             AND m.booking_date = t.booking_date
             AND m.reversed_at IS NULL
            WHERE t.bank_account_id = ?
              AND t.booking_date BETWEEN ?::date AND ?::date
        SQL, [
            (string) $account->getKey(),
            $statement->period_from->toDateString(),
            $statement->period_to->toDateString(),
        ]);

        return [
            'matched' => (int) ($row->matched ?? 0),
            'unexplained' => (int) ($row->unexplained ?? 0),
        ];
    }

    /** @param  array<string, mixed>  $conditions */
    private function signatureOf(string $pass, array $conditions, string $correctedType): string
    {
        /*
         | Computed the way the query computes it.
         |
         | `md5(conditions::text || ...)` in SQL, so the two have to agree
         | on the JSON text - which they do because PostgreSQL stores
         | `jsonb` canonically and `conditions::text` reads it back the
         | same way every time, whatever order it went in.
         */
        $canonical = DB::connection('tenant')->selectOne(
            'SELECT md5(?::jsonb::text || ? || ?) AS signature',
            [json_encode($conditions, JSON_THROW_ON_ERROR), $pass, $correctedType],
        );

        return (string) $canonical->signature;
    }
}
