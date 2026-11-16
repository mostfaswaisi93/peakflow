<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Database\DatabaseRefusal;
use App\Support\Money\Money;
use App\Tenant\Banking\Exceptions\ImportRefused;
use App\Tenant\Banking\Exceptions\StatementUnreadable;
use App\Tenant\Banking\Models\BankAccount;
use App\Tenant\Banking\Models\BankStatement;
use App\Tenant\Banking\Parsers\ParsedStatement;
use App\Tenant\Banking\Parsers\StatementParsers;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importing a statement - PF-147, PF-149.
 *
 * ## Re-importing is a no-op that REPORTS what it skipped
 *
 * People re-download overlapping statement files constantly - a week's file
 * on Monday, a month's file on the 1st, the same file twice because the
 * first download looked wrong. Silently doing nothing is almost as bad as
 * duplicating: the person is left unsure whether the import worked, and the
 * usual next step is to try again with a wider date range.
 *
 * So an import returns a report: imported, skipped, and why each skipped
 * line was already there.
 *
 * ## Two identical transactions on one day stay two
 *
 * A customer paying the same amount twice in a morning is ordinary. The
 * identity key includes the line's sequence within its statement, so
 * deduplication cannot swallow the second - which would hide a real
 * duplicate payment, the error this whole area exists to catch.
 *
 * The key is a generated column rather than something this service computes,
 * because an import that forgot to compute it is an import that duplicates
 * and the unique index could not see the difference. That also decides HOW
 * the skip is detected: `insert ... on conflict do nothing` lets the
 * database compare keys it alone can compute, and - unlike catching the
 * unique violation - leaves the surrounding transaction usable. In
 * PostgreSQL a failed statement aborts its transaction, so a caught
 * violation would turn every remaining line of the file into
 * "current transaction is aborted".
 *
 * ## Continuity stops the import rather than importing a hole
 *
 * The database asserts that a statement's opening balance equals the
 * previous one's closing balance. A reconciliation over a hole can never be
 * completed and nobody knows why: the difference sits there for months, and
 * every month's work starts by rediscovering it.
 *
 * The two legitimate ways past it are both here, because a person holding a
 * file that will not import needs a route that is not the configuration
 * flag: `replaces:` supersedes a statement the bank has re-issued, and
 * `recordAGap()` books a period nobody has the file for as a statement of
 * its own.
 *
 * ## An intraday report does not post and does not reconcile
 *
 * CAMT.052 and CAMT.054 are provisional. They feed the cash position and
 * nothing else, and treating them as final double-counts when the CAMT.053
 * arrives - so their transactions are marked provisional and are superseded
 * by the booked statement rather than sitting beside it.
 */
final class StatementImport
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly StatementParsers $parsers = new StatementParsers(),
    ) {}

    /**
     * Import a statement and its lines.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  BankStatement|null  $replaces  a statement the bank has re-issued
     * @return array<string, mixed>
     *
     * @throws ImportRefused
     */
    public function import(
        BankAccount $account,
        string $sourceFormat,
        Carbon $periodFrom,
        Carbon $periodTo,
        string $openingBalance,
        string $closingBalance,
        array $lines,
        ?string $statementNumber = null,
        ?string $fileName = null,
        ?string $contentHash = null,
        ?string $userId = null,
        ?BankStatement $replaces = null,
        ?string $supersessionReason = null,
        bool $provisionalLinesMove = false,
    ): array {
        $format = $this->assertTheFormatIsKnown($sourceFormat);
        $currency = (string) $account->currency;

        if ($periodTo->lt($periodFrom)) {
            throw ImportRefused::because(sprintf(
                'The statement period ends on %s and starts on %s.',
                $periodTo->toDateString(),
                $periodFrom->toDateString(),
            ));
        }

        $this->assertTheReplacementIsCoherent($account, $replaces, $supersessionReason);

        /*
         | The same file twice is the same statement.
         |
         | Checked before anything is written, because the report a person
         | reads should say "this file is already imported" rather than
         | listing every line as skipped - which is the same information and
         | reads as a failure.
         */
        if ($contentHash !== null && $replaces === null) {
            $already = BankStatement::query()
                ->where('bank_account_id', (string) $account->getKey())
                ->where('content_hash', $contentHash)
                ->first();

            if ($already !== null) {
                return [
                    'statement_id' => (string) $already->getKey(),
                    'imported' => 0,
                    'skipped' => \count($lines),
                    'already_imported' => true,
                    'why' => sprintf(
                        'This exact file was already imported as statement %s on %s. Nothing was '.
                        'changed.',
                        $already->label(),
                        $already->imported_at->toDateTimeString(),
                    ),
                    'skipped_detail' => [],
                ];
            }
        }

        $this->assertTheLinesAddUp($lines, $openingBalance, $closingBalance, $currency, $provisionalLinesMove);

        return DB::connection('tenant')->transaction(function () use (
            $account, $format, $periodFrom, $periodTo, $openingBalance, $closingBalance,
            $lines, $statementNumber, $fileName, $contentHash, $userId, $currency,
            $replaces, $supersessionReason,
        ): array {
            /*
             | The superseded statement steps aside BEFORE the new one is
             | written.
             |
             | Otherwise continuity compares the replacement against the
             | statement it replaces and refuses it - leaving somebody
             | holding a corrected file from their bank with no route but
             | the configuration flag. The pointer back is set afterwards,
             | because it needs an id that does not exist yet.
             */
            if ($replaces !== null) {
                $replaces->update([
                    'status' => 'superseded',
                    'superseded_at' => now(),
                    'superseded_by_user' => $userId,
                    'supersession_reason' => trim((string) $supersessionReason),
                ]);
            }

            $sequence = (int) (BankStatement::query()
                ->where('bank_account_id', (string) $account->getKey())
                ->max('sequence') ?? 0) + 1;

            try {
                $statement = BankStatement::create([
                    'entity_id' => (string) $account->entity_id,
                    'bank_account_id' => (string) $account->getKey(),
                    'statement_number' => $statementNumber,
                    'sequence' => $sequence,
                    'period_from' => $periodFrom->toDateString(),
                    'period_to' => $periodTo->toDateString(),
                    'opening_balance' => $openingBalance,
                    'closing_balance' => $closingBalance,
                    'currency' => $currency,
                    'source_format' => $format,
                    'status' => 'imported',
                    'content_hash' => $contentHash,
                    'file_name' => $fileName,
                    'imported_at' => now(),
                    'imported_by' => $userId,
                ]);
            } catch (QueryException $e) {
                /*
                 | The continuity trigger's own sentence reaches the caller.
                 |
                 | It already names both balances, the previous statement and
                 | the gap - which is what somebody needs to find the missing
                 | file. Replacing it with "import failed" would throw that
                 | away.
                 */
                throw ImportRefused::because(DatabaseRefusal::sentence($e->getMessage()));
            }

            if ($replaces !== null) {
                $replaces->update(['superseded_by_id' => (string) $statement->getKey()]);
            }

            $imported = 0;
            $skipped = [];

            foreach (array_values($lines) as $index => $line) {
                $result = $this->writeLine($account, $statement, $line, $index + 1, $currency);

                if ($result === null) {
                    $imported++;

                    continue;
                }

                $skipped[] = $result;
            }

            $this->audit->record(
                category: 'banking',
                action: 'statement.imported',
                subjectType: 'bank_statement',
                subjectId: (string) $statement->getKey(),
                subjectLabel: $statement->label(),
                after: [
                    'account' => $account->shortIdentifier(),
                    'format' => $format,
                    'period' => $periodFrom->toDateString().' to '.$periodTo->toDateString(),
                    'opening' => $openingBalance,
                    'closing' => $closingBalance,
                    'lines_imported' => $imported,
                    'lines_skipped' => \count($skipped),
                    'replaces' => $replaces?->label(),
                ],
                reason: $supersessionReason === null ? null : trim($supersessionReason),
                entityId: (string) $account->entity_id,
            );

            return [
                'statement_id' => (string) $statement->getKey(),
                'imported' => $imported,
                'skipped' => \count($skipped),
                'already_imported' => false,
                'replaced' => $replaces?->label(),
                /*
                 | Reported, not silent - PF-149.
                 |
                 | A re-import that does nothing and says nothing leaves the
                 | person unsure whether it worked, and the usual next step
                 | is to try again with a wider date range.
                 */
                'why' => $skipped === []
                    ? sprintf('%d transactions imported.', $imported)
                    : sprintf(
                        '%d transactions imported and %d skipped as already present. A bank file '.
                        'overlapping one already imported is normal: the skipped lines are listed '.
                        'so you can see they are the ones you expect.',
                        $imported,
                        \count($skipped),
                    ),
                'skipped_detail' => $skipped,
            ];
        });
    }

    /**
     * Import a statement file, in whatever format it is in - PF-150 to PF-154.
     *
     * ## The file names its own account, and that is checked
     *
     * Importing Al Rajhi's file into the SAB account is a mistake somebody
     * makes within a week of having two accounts, and it produces a
     * reconciliation that can never be completed: the balances are a third
     * party's, the lines are a third party's, and nothing about the result
     * says so. The check costs a string comparison.
     *
     * Where the file identifies no account - a CSV never does - there is
     * nothing to compare and the import proceeds. That is stated in the
     * report rather than left silent, because "no mismatch" and "nothing was
     * checked" are not the same assurance.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws ImportRefused|StatementUnreadable
     */
    public function importFile(
        BankAccount $account,
        string $content,
        ?string $format = null,
        ?string $fileName = null,
        ?string $userId = null,
        ?BankStatement $replaces = null,
        ?string $supersessionReason = null,
    ): array {
        $statements = $this->parsers->parse($content, $format);
        $parsed = $this->theOneFor($account, $statements);

        return $this->importParsed(
            account: $account,
            parsed: $parsed,
            content: $content,
            fileName: $fileName,
            userId: $userId,
            replaces: $replaces,
            supersessionReason: $supersessionReason,
            checked: $parsed->identification() !== null,
        );
    }

    /**
     * Import an already-parsed statement.
     *
     * Separate from {@see importFile()} because a CSV is read through a
     * column mapping rather than by a reader that recognises it, and both
     * paths end here.
     *
     * @return array<string, mixed>
     *
     * @throws ImportRefused
     */
    public function importParsed(
        BankAccount $account,
        ParsedStatement $parsed,
        ?string $content = null,
        ?string $fileName = null,
        ?string $userId = null,
        ?BankStatement $replaces = null,
        ?string $supersessionReason = null,
        bool $checked = true,
    ): array {
        $currency = (string) $account->currency;

        if ($parsed->statesItsCurrency() && strtoupper($parsed->currency) !== strtoupper($currency)) {
            throw ImportRefused::because(sprintf(
                'This statement is in %s and the account is in %s. A statement in another currency '.
                'is either the wrong file or the wrong account, and importing it would put every '.
                'line into the account at face value - which is not a conversion, it is the same '.
                'number with a different meaning.',
                strtoupper($parsed->currency),
                strtoupper($currency),
            ));
        }

        [$opening, $closing] = $this->balancesFor($account, $parsed);

        /*
         | A zero line moves nothing, so it is not a transaction.
         |
         | Banks book these as advice. Refusing the whole file over one
         | would be absurd and dropping it silently would be the kind of
         | quiet loss this module exists to avoid, so it is skipped and
         | reported exactly like a duplicate.
         */
        $lines = [];
        $skippedZero = [];

        foreach ($parsed->lines as $index => $line) {
            $array = $line->toArray();

            if (BigDecimal::of((string) $array['amount'])->isZero()) {
                $skippedZero[] = [
                    'sequence' => $index + 1,
                    'booking_date' => $line->bookingDate->toDateString(),
                    'amount' => (string) $array['amount'],
                    'bank_reference' => $line->bankReference,
                    'why' => 'The bank booked this as an advice line of zero. It moves no money, so '
                        .'it is not stored as a transaction.',
                ];

                continue;
            }

            $lines[] = $array;
        }

        $report = $this->import(
            account: $account,
            sourceFormat: $parsed->sourceFormat,
            periodFrom: $parsed->periodFrom->copy(),
            periodTo: $parsed->periodTo->copy(),
            openingBalance: $opening,
            closingBalance: $closing,
            lines: $lines,
            statementNumber: $parsed->statementNumber,
            fileName: $fileName,
            contentHash: $content === null ? null : hash('sha256', $content),
            userId: $userId,
            replaces: $replaces,
            supersessionReason: $supersessionReason,
            provisionalLinesMove: ! $this->reconciles($parsed->sourceFormat),
        );

        $report['skipped'] = (int) $report['skipped'] + \count($skippedZero);
        $report['skipped_detail'] = [...(array) $report['skipped_detail'], ...$skippedZero];
        $report['account_identification'] = $parsed->identification();
        $report['account_was_checked'] = $checked;
        $report['pending'] = \count($parsed->pendingLines());
        $report['balances_were_stated'] = $parsed->statesItsBalances();

        return $report;
    }

    /**
     * The statement in this file that is for this account.
     *
     * @param  list<ParsedStatement>  $statements
     *
     * @throws ImportRefused
     */
    private function theOneFor(BankAccount $account, array $statements): ParsedStatement
    {
        $iban = (string) ($account->iban() ?? '') ?: null;
        $other = $account->domesticIdentifier() ?? $account->accountNumber();

        foreach ($statements as $statement) {
            if ($statement->identifies($iban, $other)) {
                return $statement;
            }
        }

        $found = array_filter(array_map(
            static fn (ParsedStatement $statement): ?string => $statement->identification(),
            $statements,
        ));

        throw ImportRefused::because(sprintf(
            'This file is for %s and you are importing it into %s. Importing a statement into the '.
            'wrong account produces a reconciliation that can never be completed: the balances are '.
            'another account\'s, the lines are another account\'s, and nothing about the result '.
            'says so.',
            $found === []
                ? 'an account it does not name'
                : implode(' and ', array_unique($found)),
            $account->shortIdentifier(),
        ));
    }

    /**
     * The balances to record, filling in what an interim file does not state.
     *
     * An MT942 carries no balance tags at all and a CSV has none by nature.
     * Rather than invent a figure, the statement opens and closes where the
     * account's booked position already stands: its booked movement is zero,
     * which is exactly what an interim report moves.
     *
     * For a CSV - which is booked - the opening is the account's position
     * and the closing is that plus the file's own lines. Not stated by the
     * file and not pretended to be: `balances_were_stated` says so in the
     * report, and what still has to hold is continuity against the previous
     * statement, which is what catches a CSV missing its last page.
     *
     * @return array{0: string, 1: string}
     */
    private function balancesFor(BankAccount $account, ParsedStatement $parsed): array
    {
        if ($parsed->statesItsBalances()) {
            return [(string) $parsed->openingBalance, (string) $parsed->closingBalance];
        }

        $currency = (string) $account->currency;
        $previous = $account->lastStatement();
        $opening = $previous === null
            ? Money::of((string) $account->opening_balance, $currency)
            : Money::of((string) $previous->closing_balance, $currency);

        /*
         | An interim report's position includes its provisional lines.
         |
         | An MT942 says "since the last statement, these movements
         | happened", so the position it implies is the last booked closing
         | plus all of them. Summing only its booked lines - of which it has
         | none - would make every interim report balance-neutral, and the
         | cash position it exists to feed would just be the booked balance.
         */
        $counted = $this->reconciles($parsed->sourceFormat)
            ? $parsed->bookedLines()
            : $parsed->lines;

        $moved = Money::zero($currency);

        foreach ($counted as $line) {
            $moved = $moved->plus(Money::of($line->amount, $currency));
        }

        return [(string) $opening->amount, (string) $opening->plus($moved)->amount];
    }

    /**
     * Whether a format's statements are final.
     *
     * Read from `banking.formats` rather than listed here, because a second
     * list is the one that stops matching the first - at which point an
     * interim format quietly becomes a booked one.
     */
    private function reconciles(string $format): bool
    {
        return (bool) (config('banking.formats.'.$format.'.reconciles') ?? true);
    }

    /**
     * What the bank said was inside a batch entry.
     *
     * @param  array<string, mixed>  $line
     */
    private function writeDetails(
        BankAccount $account,
        string $transactionId,
        Carbon $bookingDate,
        array $line,
        string $currency,
    ): void {
        $details = (array) ($line['details'] ?? []);

        if ($details === []) {
            return;
        }

        $stamp = now()->toDateTimeString();
        $rows = [];
        $sequence = 0;

        foreach ($details as $detail) {
            $detail = (array) $detail;
            $amount = (string) ($detail['amount'] ?? '0');

            /*
             | A zero detail is kept out, for the same reason as a zero line.
             |
             | The parser records it because the bank sent it; the table
             | holds movements, and nothing sums these into a balance - so a
             | zero row would be a row that means nothing and reads as one
             | that does.
             */
            if (BigDecimal::of($amount)->isZero()) {
                continue;
            }

            $sequence++;

            $rows[] = [
                'id' => (string) Str::ulid(),
                'entity_id' => (string) $account->entity_id,
                'bank_transaction_id' => $transactionId,
                'booking_date' => $bookingDate->toDateString(),
                'sequence' => $sequence,
                'is_batch' => (bool) ($line['is_batch'] ?? false),
                'amount' => $amount,
                'currency' => strtoupper((string) ($detail['currency'] ?? $currency)),
                'counterparty_name' => $detail['counterparty_name'] ?? null,
                'counterparty_account' => $detail['counterparty_account'] ?? null,
                'counterparty_bic' => $detail['counterparty_bic'] ?? null,
                'remittance_unstructured' => $detail['remittance_unstructured'] ?? null,
                'remittance_structured' => isset($detail['remittance_structured'])
                    ? json_encode($detail['remittance_structured'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                    : null,
                'end_to_end_id' => $detail['end_to_end_id'] ?? null,
                'mandate_id' => $detail['mandate_id'] ?? null,
                'instruction_id' => $detail['instruction_id'] ?? null,
                'instructed_amount' => $detail['instructed_amount'] ?? null,
                'instructed_currency' => $detail['instructed_currency'] ?? null,
                'exchange_rate' => $detail['exchange_rate'] ?? null,
                'charges_amount' => $detail['charges_amount'] ?? null,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        if ($rows !== []) {
            DB::connection('tenant')->table('bank_transaction_details')->insert($rows);
        }
    }

    /**
     * Supersede a statement with a replacement that already exists.
     *
     * The old statement stays: it is the document somebody reconciled
     * against last month, and deleting it makes that reconciliation
     * unexplainable.
     *
     * Where the replacement is a file about to be imported, pass it to
     * `import()` as `replaces:` instead - the order matters, because
     * continuity would refuse a replacement imported while the statement it
     * replaces is still current.
     *
     * @throws ImportRefused
     */
    public function supersede(
        BankStatement $old,
        BankStatement $new,
        string $userId,
        string $reason,
    ): BankStatement {
        if (\strlen(trim($reason)) <= 10) {
            throw ImportRefused::because(
                'Superseding a statement needs a reason. The old one is what somebody reconciled '.
                'against, and "superseded" with nothing behind it is what they find while asking '.
                'why last month no longer ties.',
            );
        }

        if ((string) $old->bank_account_id !== (string) $new->bank_account_id) {
            throw ImportRefused::because(
                'A statement can only be superseded by one for the same account.',
            );
        }

        if ((string) $old->getKey() === (string) $new->getKey()) {
            throw ImportRefused::because('A statement cannot supersede itself.');
        }

        $old->update([
            'status' => 'superseded',
            'superseded_by_id' => (string) $new->getKey(),
            'superseded_at' => now(),
            'superseded_by_user' => $userId,
            'supersession_reason' => trim($reason),
        ]);

        $this->audit->record(
            category: 'banking',
            action: 'statement.superseded',
            subjectType: 'bank_statement',
            subjectId: (string) $old->getKey(),
            subjectLabel: $old->label(),
            after: ['superseded_by' => $new->label()],
            reason: trim($reason),
            entityId: (string) $old->entity_id,
        );

        return $old->refresh();
    }

    /**
     * Record a bank-side gap deliberately - PF-147's documented procedure.
     *
     * A genuine gap happens: a bank that will not re-issue a statement from
     * four years ago, a migration from a system whose history is gone. The
     * answer is not to turn continuity off, because the next real gap would
     * then pass silently. It is to record the gap as a statement of its
     * own - a period with an opening balance, a closing balance, no lines,
     * and a reason - so the account's history stays continuous and the hole
     * is visible as a figure somebody signed for rather than as an import
     * that keeps failing.
     *
     * @throws ImportRefused
     */
    public function recordAGap(
        BankAccount $account,
        Carbon $periodFrom,
        Carbon $periodTo,
        string $closingBalance,
        string $userId,
        string $reason,
    ): BankStatement {
        if (\strlen(trim($reason)) <= 20) {
            throw ImportRefused::because(
                'Recording a bank-side gap needs an explanation, and a longer one than most: this '.
                'is a statement with no lines standing in for a period nobody has the file for. '.
                'Whoever reads the account\'s history in three years needs to know which bank '.
                'refused what, and when.',
            );
        }

        $previous = $account->lastStatement();
        $opening = $previous === null
            ? (string) $account->opening_balance
            : (string) $previous->closing_balance;

        $sequence = (int) (BankStatement::query()
            ->where('bank_account_id', (string) $account->getKey())
            ->max('sequence') ?? 0) + 1;

        $currency = (string) $account->currency;
        $unexplained = Money::of($closingBalance, $currency)->minus(Money::of($opening, $currency));

        $statement = BankStatement::create([
            'entity_id' => (string) $account->entity_id,
            'bank_account_id' => (string) $account->getKey(),
            'statement_number' => 'GAP',
            'sequence' => $sequence,
            'period_from' => $periodFrom->toDateString(),
            'period_to' => $periodTo->toDateString(),
            /*
             | It opens where the last one closed, by construction.
             |
             | Which is the point: the gap is recorded as a continuous
             | statement whose movement is unexplained, rather than as a
             | break in continuity. The difference between its opening and
             | closing balance IS the gap, and it is visible as a figure
             | rather than as an import that keeps failing.
             */
            'opening_balance' => $opening,
            'closing_balance' => $closingBalance,
            'currency' => $currency,
            'source_format' => 'manual',
            'status' => 'imported',
            'imported_at' => now(),
            'imported_by' => $userId,
            'note' => sprintf(
                'Bank-side gap recorded deliberately. Unexplained movement of %s %s. %s',
                $currency,
                (string) $unexplained->amount,
                trim($reason),
            ),
        ]);

        $this->audit->record(
            category: 'banking',
            action: 'statement.gap_recorded',
            subjectType: 'bank_statement',
            subjectId: (string) $statement->getKey(),
            subjectLabel: $statement->label(),
            after: [
                'account' => $account->shortIdentifier(),
                'period' => $periodFrom->toDateString().' to '.$periodTo->toDateString(),
                'opening' => $opening,
                'closing' => $closingBalance,
                'unexplained_movement' => (string) $unexplained->amount,
            ],
            reason: trim($reason),
            entityId: (string) $account->entity_id,
        );

        return $statement->refresh();
    }

    /**
     * Write one line, or report why it was already there.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>|null  null when it was imported
     */
    private function writeLine(
        BankAccount $account,
        BankStatement $statement,
        array $line,
        int $sequence,
        string $currency,
    ): ?array {
        $bookingDate = Carbon::parse((string) ($line['booking_date'] ?? $line['value_date']));
        $valueDate = Carbon::parse((string) ($line['value_date'] ?? $line['booking_date']));

        /*
         | Provisional by the line OR by the format.
         |
         | A CAMT.053 is booked and may still carry a `PDNG` entry, which is
         | not in its closing balance. Taking only the format's answer would
         | import that entry as booked and the file would then disagree with
         | its own stated movement - at which point the import is refused for
         | a reason that is not the file's fault.
         */
        $provisional = ((config('banking.formats.'.$statement->source_format.'.posts') ?? true) === false)
            || (bool) ($line['is_pending'] ?? false);

        $stamp = now()->toDateTimeString();
        $id = (string) Str::ulid();

        /*
         | `on conflict do nothing`, not a caught exception.
         |
         | The identity key is a generated column, so only the database can
         | compare it - and in PostgreSQL a failed statement aborts its
         | transaction, so catching the unique violation would turn every
         | remaining line of the file into "current transaction is aborted".
         | The returned count is how many rows the insert actually wrote,
         | which is 1 or 0 and is the whole answer.
         */
        $inserted = DB::connection('tenant')->table('bank_transactions')->insertOrIgnore([[
            'id' => $id,
            'entity_id' => (string) $account->entity_id,
            'bank_account_id' => (string) $account->getKey(),
            'bank_statement_id' => (string) $statement->getKey(),
            'booking_date' => $bookingDate->toDateString(),
            'value_date' => $valueDate->toDateString(),
            'amount' => (string) $line['amount'],
            'currency' => (string) ($line['currency'] ?? $currency),
            'counterparty_name' => $line['counterparty_name'] ?? null,
            'counterparty_account' => $line['counterparty_account'] ?? null,
            'counterparty_bic' => $line['counterparty_bic'] ?? null,
            'remittance_unstructured' => $line['remittance_unstructured'] ?? null,
            'remittance_structured' => isset($line['remittance_structured'])
                ? json_encode($line['remittance_structured'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                : null,
            'bank_reference' => $line['bank_reference'] ?? null,
            'end_to_end_id' => $line['end_to_end_id'] ?? null,
            'mandate_id' => $line['mandate_id'] ?? null,
            'code_domain' => $line['code_domain'] ?? null,
            'code_family' => $line['code_family'] ?? null,
            'code_subfamily' => $line['code_subfamily'] ?? null,
            'bank_code_proprietary' => $line['bank_code_proprietary'] ?? null,
            'is_reversal' => (bool) ($line['is_reversal'] ?? false),
            'reverses_reference' => $line['reverses_reference'] ?? null,
            'charges_amount' => $line['charges_amount'] ?? null,
            'instructed_amount' => $line['instructed_amount'] ?? null,
            'instructed_currency' => $line['instructed_currency'] ?? null,
            'exchange_rate' => $line['exchange_rate'] ?? null,
            'statement_sequence' => (int) ($line['statement_sequence'] ?? $sequence),
            'is_provisional' => $provisional,
            'source_format' => (string) $statement->source_format,
            'imported_at' => $stamp,
            'source_payload' => isset($line['source_payload'])
                ? json_encode($line['source_payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                : null,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]]);

        if ($inserted === 1) {
            $this->writeDetails($account, $id, $bookingDate, $line, $currency);

            return null;
        }

        /*
         | Already imported, and the report says which one.
         |
         | The index is on `(account, identity_key, booking_date)`, so this
         | is the same line from an overlapping file - the normal case
         | rather than an error. Reporting it by its reference and amount is
         | what lets somebody confirm the skipped lines are the ones they
         | expected.
         */
        $reference = $line['bank_reference'] ?? null;

        return [
            'sequence' => $sequence,
            'booking_date' => $bookingDate->toDateString(),
            'amount' => (string) $line['amount'],
            'bank_reference' => $reference,
            'why' => $reference !== null
                ? sprintf(
                    'The bank\'s reference %s is already imported for this account.',
                    (string) $reference,
                )
                : 'An identical line - same value date, amount, counterparty, remittance and '.
                  'position in the statement - is already imported for this account.',
        ];
    }

    /**
     * Whether the lines account for the statement's own movement.
     *
     * The statement asserts an opening and a closing balance, and its lines
     * have to add up to the difference. A file whose lines do not is a parse
     * that lost something - a batch entry read as one line, a page the
     * parser stopped at, a reversal dropped - and the difference is the only
     * sign of it.
     *
     * Checked before anything is written, because importing is the easy half
     * and finding the missing line a month later is the hard one.
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws ImportRefused
     */
    private function assertTheLinesAddUp(
        array $lines,
        string $opening,
        string $closing,
        string $currency,
        bool $provisionalLinesMove = false,
    ): void {
        $movement = Money::of($closing, $currency)->minus(Money::of($opening, $currency));
        $sum = Money::zero($currency);
        $booked = 0;
        $pending = 0;

        foreach ($lines as $line) {
            /*
             | Pending lines are not in the closing balance.
             |
             | A CAMT.053 carrying a card authorisation that has not settled
             | is ordinary, and counting it here would refuse a file that is
             | perfectly correct - which reads as a parser bug and is not.
             |
             | An INTERIM report is the other way round: every one of its
             | lines is provisional, and the intraday balance it states
             | includes them. Leaving them out there would refuse a CAMT.052
             | that is perfectly correct, for the mirror-image reason.
             */
            if (! $provisionalLinesMove && (bool) ($line['is_pending'] ?? false)) {
                $pending++;

                continue;
            }

            $booked++;
            $sum = $sum->plus(Money::of((string) $line['amount'], $currency));
        }

        if ($sum->amount->isEqualTo($movement->amount)) {
            return;
        }

        throw ImportRefused::because(sprintf(
            'The statement moves from %s to %s - a movement of %s - and its %d booked lines add up '.
            'to %s. The difference is %s.%s A file whose lines do not account for its own movement '.
            'is a parse that lost something: a batch entry read as one line, a page the parser '.
            'stopped at, or a reversal dropped. Importing it anyway produces an account that can '.
            'never be reconciled, and the missing line is far harder to find a month from now.',
            $opening,
            $closing,
            (string) $movement->amount,
            $booked,
            (string) $sum->amount,
            (string) $movement->minus($sum)->amount,
            $pending === 0
                ? ''
                : sprintf(
                    ' %d pending line%s were left out of the sum, which is correct: a pending entry '.
                    'is not in the closing balance.',
                    $pending,
                    $pending === 1 ? '' : 's',
                ),
        ));
    }

    /**
     * @throws ImportRefused
     */
    private function assertTheReplacementIsCoherent(
        BankAccount $account,
        ?BankStatement $replaces,
        ?string $reason,
    ): void {
        if ($replaces === null) {
            if ($reason !== null) {
                throw ImportRefused::because(
                    'A supersession reason was given but no statement to supersede. An import that '.
                    'quietly ignored the reason would read as having replaced something.',
                );
            }

            return;
        }

        if ((string) $replaces->bank_account_id !== (string) $account->getKey()) {
            throw ImportRefused::because(sprintf(
                'Statement %s belongs to a different bank account.',
                $replaces->label(),
            ));
        }

        if ($replaces->isSuperseded()) {
            throw ImportRefused::because(sprintf(
                'Statement %s was already superseded on %s. Superseding it again would leave two '.
                'statements claiming to replace it and no way to tell which one the account\'s '.
                'history runs through.',
                $replaces->label(),
                $replaces->superseded_at?->toDateString() ?? 'an earlier date',
            ));
        }

        if (\strlen(trim((string) $reason)) <= 10) {
            throw ImportRefused::because(sprintf(
                'Replacing statement %s needs a reason. The old one is what somebody reconciled '.
                'against, and "superseded" with nothing behind it is what they find while asking '.
                'why last month no longer ties.',
                $replaces->label(),
            ));
        }
    }

    /**
     * @throws ImportRefused
     */
    private function assertTheFormatIsKnown(string $format): string
    {
        $known = array_keys((array) config('banking.formats', []));

        if (! \in_array($format, [...$known, 'manual'], true)) {
            throw ImportRefused::because(sprintf(
                '"%s" is not a statement format PeakFlow reads. The ones it does are: %s. The order '.
                'is by fidelity rather than popularity, and where a bank offers more than one the '.
                'richer format is the one to take.',
                $format,
                implode(', ', $known),
            ));
        }

        return $format;
    }
}
