<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Database\DatabaseRefusal;
use App\Support\Money\Money;
use App\Tenant\Banking\Exceptions\ImportRefused;
use App\Tenant\Banking\Models\BankAccount;
use App\Tenant\Banking\Models\BankStatement;
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
    public function __construct(private readonly AuditTrail $audit) {}

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

        $this->assertTheLinesAddUp($lines, $openingBalance, $closingBalance, $currency);

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
        $provisional = (config('banking.formats.'.$statement->source_format.'.posts') ?? true) === false;
        $stamp = now()->toDateTimeString();

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
            'id' => (string) Str::ulid(),
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
    ): void {
        $movement = Money::of($closing, $currency)->minus(Money::of($opening, $currency));
        $sum = Money::zero($currency);

        foreach ($lines as $line) {
            $sum = $sum->plus(Money::of((string) $line['amount'], $currency));
        }

        if ($sum->amount->isEqualTo($movement->amount)) {
            return;
        }

        throw ImportRefused::because(sprintf(
            'The statement moves from %s to %s - a movement of %s - and its %d lines add up to %s. '.
            'The difference is %s. A file whose lines do not account for its own movement is a '.
            'parse that lost something: a batch entry read as one line, a page the parser stopped '.
            'at, or a reversal dropped. Importing it anyway produces an account that can never be '.
            'reconciled, and the missing line is far harder to find a month from now.',
            $opening,
            $closing,
            (string) $movement->amount,
            \count($lines),
            (string) $sum->amount,
            (string) $movement->minus($sum)->amount,
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
