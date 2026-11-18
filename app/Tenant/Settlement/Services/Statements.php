<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Services;

use App\Support\Money\Money;
use App\Tenant\Settlement\Exceptions\PaymentRefused;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a party owes us, or we owe them, on a date - PF-181.
 *
 * ## Two forms, because they answer different questions
 *
 * **Open-item** lists the documents that are not settled. It answers
 * "which invoices do I still have to pay", which is what a customer's
 * accounts department is looking for, and it is the only form that can
 * be reconciled line by line against their own ledger.
 *
 * **Balance-forward** opens with the balance at the start of the period
 * and lists what happened since. It answers "how did we get from last
 * month's figure to this one", which is what somebody asks when the two
 * sides disagree about the total rather than about a document.
 *
 * Both, because a product with only the first cannot explain a
 * movement, and a product with only the second cannot be paid from.
 *
 * ## Unapplied cash, credits and disputes are LABELLED, not netted
 *
 * A statement that silently nets a customer's unapplied payment against
 * their oldest invoice invites exactly the dispute it was meant to
 * prevent: they telephone about an invoice they believe they paid, and
 * nobody can see which payment the system decided to use.
 *
 * So each appears as its own line with its own label. The total is the
 * same; what changes is that the customer can see what it is made of.
 *
 * ## It says whether it reconciles, rather than leaving it implied
 *
 * The sum of every party's open items has to equal the receivables
 * control account. A statement that was right about one customer while
 * the control account disagreed by a few hundred is a statement that
 * sends somebody looking in the wrong place for a week.
 *
 * So {@see reconciliation()} compares the two and SAYS so - including
 * when it does not, with the difference. A report that only printed the
 * figure when they agreed would be silent exactly when it matters.
 */
final class Statements
{
    /**
     * Documents that are not settled, as at a date.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function openItems(
        string $entityId,
        string $partyId,
        string $currency,
        Carbon $asAt,
        string $side = 'customer',
    ): array {
        $this->assertTheSideIsKnown($side);

        $documentType = $side === 'customer' ? 'invoice' : 'bill';

        $rows = DB::connection('tenant')->select(
            $side === 'customer' ? $this->customerOpenItemsSql() : $this->supplierOpenItemsSql(),
            [$entityId, $partyId, $currency, $asAt->toDateString()],
        );

        $items = [];
        $total = Money::zero($currency);

        foreach ($rows as $row) {
            $amount = Money::of((string) $row->open_balance, $currency);
            $total = $total->plus($amount);

            $items[] = [
                'kind' => 'document',
                'label' => (string) $row->label,
                'reference' => (string) $row->reference,
                'issued_on' => (string) $row->issued_on,
                'due_on' => $row->due_on === null ? null : (string) $row->due_on,
                'gross' => (string) $row->gross_amount,
                'open' => (string) $amount->amount,
                'days_overdue' => $row->due_on === null
                    ? null
                    : max(0, (int) Carbon::parse((string) $row->due_on)->diffInDays($asAt, false)),
                'disputed' => (bool) $row->disputed,
                /*
                 | A disputed document stays ON the statement, labelled.
                 |
                 | Removing it would make the statement disagree with the
                 | control account, and hiding the dispute makes the
                 | customer think we have forgotten their complaint - so
                 | they raise it again, to somebody else.
                 */
                'note' => $row->disputed
                    ? sprintf('In dispute: %s', (string) $row->dispute_detail)
                    : null,
            ];
        }

        $extras = $this->creditsAndCash($entityId, $partyId, $currency, $asAt, $side);

        foreach ($extras['lines'] as $line) {
            $items[] = $line;
        }

        $total = $total->plus($extras['total']);

        return [
            'form' => 'open_item',
            'side' => $side,
            'as_at' => $asAt->toDateString(),
            'currency' => $currency,
            'items' => $items,
            'total' => (string) $total->amount,
            'why' => sprintf(
                '%d line(s) that are not settled, totalling %s. Unapplied cash and credit balances are '.
                'listed with their own labels rather than netted against the oldest invoice: a '.
                'statement that nets silently invites the dispute it was meant to prevent, because the '.
                'customer cannot see which payment the system decided to use.',
                \count($items),
                (string) $total->toPresentation(),
            ),
        ];
    }

    /**
     * The opening balance, what happened, and the closing balance.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function balanceForward(
        string $entityId,
        string $partyId,
        string $currency,
        Carbon $from,
        Carbon $until,
        string $side = 'customer',
    ): array {
        $this->assertTheSideIsKnown($side);

        if ($until->lt($from)) {
            throw PaymentRefused::because(
                'A statement period ends after it starts. A reversed pair would produce an opening '.
                'balance later than its closing one, and the movement between them would read as its '.
                'own negative.',
            );
        }

        $opening = $this->balanceAt($entityId, $partyId, $currency, $from->copy()->subDay(), $side);
        $closing = $this->balanceAt($entityId, $partyId, $currency, $until, $side);

        /*
         | Ten parameters, not five: each half of the UNION takes its
         | own.
         |
         | PostgreSQL binds positionally across the whole statement, so
         | a UNION of two five-parameter halves needs the five given
         | twice. The first draft passed them once and the driver said
         | so - "bind message supplies 5 parameters, but the prepared
         | statement requires 10" - which is the clearest error in this
         | whole task.
         */
        $arguments = [$entityId, $partyId, $currency, $from->toDateString(), $until->toDateString()];

        $movements = DB::connection('tenant')->select(
            $this->movementsSql($side),
            array_merge($arguments, $arguments),
        );

        $lines = [];
        $running = $opening;

        foreach ($movements as $movement) {
            $amount = Money::of((string) $movement->amount, $currency);
            $running = $running->plus($amount);

            $lines[] = [
                'on' => (string) $movement->happened_on,
                'label' => (string) $movement->label,
                'reference' => (string) $movement->reference,
                'amount' => (string) $amount->amount,
                'running' => (string) $running->amount,
            ];
        }

        /*
         | The arithmetic is asserted, not assumed.
         |
         | Opening plus every movement has to equal the closing balance
         | computed independently. When it does not, the statement says
         | so rather than printing a column that does not add up - which
         | is what the customer notices first and trusts least.
         |
         | ## It cannot fire today, and that is the point
         |
         | `balanceAt()` and `movementsSql()` are two separately written
         | queries over the same facts, so while they agree this flag is
         | always true - a bite that hard-coded it changed nothing, which
         | is how that was established rather than assumed.
         |
         | It is kept because the two are SEPARATE: an edit to one that
         | does not reach the other is exactly the change this catches,
         | and that edit is a Tuesday away. The check that covers this
         | today does the addition itself rather than trusting the flag,
         | so the flag is not the only guard - it is the one that fires
         | in production, where nobody is running checks.
         */
        $reconciles = $running->equals($closing);

        return [
            'form' => 'balance_forward',
            'side' => $side,
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
            'currency' => $currency,
            'opening' => (string) $opening->amount,
            'movements' => $lines,
            'closing' => (string) $closing->amount,
            'adds_up' => $reconciles,
            'why' => $reconciles
                ? sprintf(
                    'Opened at %s, %d movement(s), closed at %s - and the column adds up.',
                    (string) $opening->toPresentation(),
                    \count($lines),
                    (string) $closing->toPresentation(),
                )
                : sprintf(
                    'Opened at %s and the movements run to %s, while the closing balance computed '.
                    'independently is %s - a difference of %s. The statement says so rather than '.
                    'printing a column that does not add up, which is what a customer notices first.',
                    (string) $opening->toPresentation(),
                    (string) $running->toPresentation(),
                    (string) $closing->toPresentation(),
                    (string) $closing->minus($running)->toPresentation(),
                ),
        ];
    }

    /**
     * Does the sum of every party's open items equal the control account?
     *
     * @return array<string, mixed>
     *
     * @throws PaymentRefused
     */
    public function reconciliation(string $entityId, string $currency, string $side = 'customer'): array
    {
        $this->assertTheSideIsKnown($side);

        $role = $side === 'customer' ? 'accounts_receivable' : 'accounts_payable';
        $documentType = $side === 'customer' ? 'invoice' : 'bill';
        $table = $side === 'customer' ? 'invoices' : 'bills';

        /*
         | Read as text, never through a float.
         |
         | `->sum()` hands back a PHP number, and a receivables balance
         | summed through a float is wrong by a fraction that grows with
         | the row count - which is exactly the kind of difference
         | somebody then spends a week looking for.
         */
        $subsidiary = DB::connection('tenant')->selectOne(sprintf(<<<'SQL'
            SELECT coalesce(sum(peakflow_document_open_balance(?, d.id)), 0)::text AS total
            FROM %s d
            WHERE d.entity_id = ?
              AND d.currency = ?
              AND d.status IN ('issued', 'approved')
        SQL, $table), [$documentType, $entityId, $currency]);

        $control = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(l.functional_amount), 0)::text AS total
            FROM journal_lines l
            JOIN account_roles r ON r.account_id = l.account_id AND r.entity_id = l.entity_id
            WHERE l.entity_id = ?
              AND r.role = ?
        SQL, [$entityId, $role]);

        $ledger = Money::of((string) ($subsidiary->total ?? '0'), $currency);
        $account = Money::of((string) ($control->total ?? '0'), $currency);

        /*
         | A payables control carries a CREDIT balance.
         |
         | So the comparison is against its absolute value: the
         | subsidiary ledger of what we owe is a positive figure and the
         | account is negative, and a product that compared them raw
         | would report every entity as out by twice the balance.
         */
        $comparable = $side === 'customer' ? $account : $account->negated();
        $difference = $ledger->minus($comparable);

        return [
            'side' => $side,
            'currency' => $currency,
            'subsidiary_ledger' => (string) $ledger->amount,
            'control_account' => (string) $comparable->amount,
            'difference' => (string) $difference->amount,
            'reconciles' => $difference->isZero(),
            /*
             | Said either way, which is the criterion.
             |
             | A report that printed the figure only when the two agreed
             | would be silent exactly when it matters - and a statement
             | that was right about one customer while the control
             | account disagreed by a few hundred sends somebody looking
             | in the wrong place for a week.
             */
            'why' => $difference->isZero()
                ? sprintf(
                    'The open items of every party total %s, and the %s control account holds the '.
                    'same. They reconcile.',
                    (string) $ledger->toPresentation(),
                    str_replace('_', ' ', $role),
                )
                : sprintf(
                    'The open items of every party total %s and the %s control account holds %s, a '.
                    'difference of %s. This statement is not reliable until that is explained: the '.
                    'difference is either a document posted without an allocation or an allocation '.
                    'posted without a document.',
                    (string) $ledger->toPresentation(),
                    str_replace('_', ' ', $role),
                    (string) $comparable->toPresentation(),
                    (string) $difference->toPresentation(),
                ),
        ];
    }

    /**
     * Unapplied cash and credit balances, as their own labelled lines.
     *
     * @return array{lines: list<array<string, mixed>>, total: Money}
     */
    private function creditsAndCash(
        string $entityId,
        string $partyId,
        string $currency,
        Carbon $asAt,
        string $side,
    ): array {
        $lines = [];
        $total = Money::zero($currency);

        /*
         | Cash received and not yet applied to anything.
         |
         | It reduces what they owe and it is NOT a payment against any
         | particular invoice - which is the whole reason it is its own
         | line. Netting it against the oldest invoice is a decision the
         | system would be making on the customer's behalf, invisibly.
         */
        $unapplied = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(p.amount - coalesce((
                       SELECT sum(a.amount)
                       FROM allocations a
                       WHERE a.payment_id = p.id
                         AND a.reversed_at IS NULL
                   ), 0)), 0)::text AS total
            FROM payments p
            WHERE p.entity_id = ?
              AND p.party_id = ?
              AND p.currency = ?
              AND p.value_date <= ?
              AND p.reversed_at IS NULL
              AND p.direction = ?
        SQL, [$entityId, $partyId, $currency, $asAt->toDateString(), $side === 'customer' ? 'receipt' : 'disbursement']);

        $cash = Money::of((string) ($unapplied->total ?? '0'), $currency);

        if (! $cash->isZero()) {
            $lines[] = [
                'kind' => 'unapplied_cash',
                'label' => $side === 'customer'
                    ? 'Payments received and not yet applied to an invoice'
                    : 'Payments made and not yet applied to a bill',
                'reference' => null,
                'open' => (string) $cash->negated()->amount,
                'note' => 'Listed separately rather than netted, so you can see which documents are '.
                    'still outstanding and tell us which this should settle.',
            ];

            $total = $total->minus($cash);
        }

        return ['lines' => $lines, 'total' => $total];
    }

    /** The balance on a date, from the documents and what settled them. */
    private function balanceAt(
        string $entityId,
        string $partyId,
        string $currency,
        Carbon $on,
        string $side,
    ): Money {
        $table = $side === 'customer' ? 'invoices' : 'bills';
        $documentType = $side === 'customer' ? 'invoice' : 'bill';
        $dateColumn = $side === 'customer' ? 'issue_date' : 'supplier_document_date';

        $row = DB::connection('tenant')->selectOne(sprintf(<<<'SQL'
            SELECT coalesce(sum(d.gross_amount), 0)::text AS billed
            FROM %1$s d
            WHERE d.entity_id = ?
              AND d.party_id = ?
              AND d.currency = ?
              AND d.status IN ('issued', 'approved')
              AND d.%2$s <= ?
        SQL, $table, $dateColumn), [$entityId, $partyId, $currency, $on->toDateString()]);

        $settled = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(a.amount), 0)::text AS total
            FROM allocations a
            JOIN payments p ON p.id = a.payment_id
            WHERE a.entity_id = ?
              AND a.document_type = ?
              AND a.reversed_at IS NULL
              AND p.party_id = ?
              AND p.currency = ?
              AND p.value_date <= ?
        SQL, [$entityId, $documentType, $partyId, $currency, $on->toDateString()]);

        return Money::of((string) ($row->billed ?? '0'), $currency)
            ->minus(Money::of((string) ($settled->total ?? '0'), $currency));
    }

    /** @throws PaymentRefused */
    private function assertTheSideIsKnown(string $side): void
    {
        if (! \in_array($side, ['customer', 'supplier'], true)) {
            throw PaymentRefused::because(sprintf(
                '"%s" is neither a customer nor a supplier statement. The two read from different '.
                'documents and against different control accounts, so there is no sensible default.',
                $side,
            ));
        }
    }

    private function customerOpenItemsSql(): string
    {
        return <<<'SQL'
            SELECT i.number AS reference,
                   i.issue_date AS issued_on,
                   (
                     SELECT max((d->>'date')::date)
                     FROM jsonb_array_elements(i.terms_snapshot->'due_dates') d
                   ) AS due_on,
                   i.gross_amount::text AS gross_amount,
                   peakflow_document_open_balance('invoice', i.id)::text AS open_balance,
                   CASE i.document_type
                       WHEN '381' THEN 'Credit note'
                       WHEN '383' THEN 'Debit note'
                       ELSE 'Invoice'
                   END AS label,
                   EXISTS (
                     SELECT 1 FROM document_disputes dd
                     WHERE dd.document_type = 'invoice'
                       AND dd.document_id = i.id
                       AND dd.status = 'open'
                   ) AS disputed,
                   coalesce((
                     SELECT dd.detail FROM document_disputes dd
                     WHERE dd.document_type = 'invoice'
                       AND dd.document_id = i.id
                       AND dd.status = 'open'
                     ORDER BY dd.raised_at DESC
                     LIMIT 1
                   ), '') AS dispute_detail
            FROM invoices i
            WHERE i.entity_id = ?
              AND i.party_id = ?
              AND i.currency = ?
              AND i.status = 'issued'
              AND i.issue_date <= ?
              AND peakflow_document_open_balance('invoice', i.id) <> 0
            ORDER BY i.issue_date, i.number
        SQL;
    }

    private function supplierOpenItemsSql(): string
    {
        return <<<'SQL'
            SELECT b.supplier_document_number AS reference,
                   b.supplier_document_date AS issued_on,
                   (
                     SELECT max((d->>'date')::date)
                     FROM jsonb_array_elements(b.terms_snapshot->'due_dates') d
                   ) AS due_on,
                   b.gross_amount::text AS gross_amount,
                   peakflow_document_open_balance('bill', b.id)::text AS open_balance,
                   'Bill' AS label,
                   EXISTS (
                     SELECT 1 FROM document_disputes dd
                     WHERE dd.document_type = 'bill'
                       AND dd.document_id = b.id
                       AND dd.status = 'open'
                   ) AS disputed,
                   coalesce((
                     SELECT dd.detail FROM document_disputes dd
                     WHERE dd.document_type = 'bill'
                       AND dd.document_id = b.id
                       AND dd.status = 'open'
                     ORDER BY dd.raised_at DESC
                     LIMIT 1
                   ), '') AS dispute_detail
            FROM bills b
            WHERE b.entity_id = ?
              AND b.party_id = ?
              AND b.currency = ?
              AND b.approved_at IS NOT NULL
              AND b.supplier_document_date <= ?
              AND peakflow_document_open_balance('bill', b.id) <> 0
            ORDER BY b.supplier_document_date, b.supplier_document_number
        SQL;
    }

    private function movementsSql(string $side): string
    {
        $table = $side === 'customer' ? 'invoices' : 'bills';
        $reference = $side === 'customer' ? 'i.number' : 'i.supplier_document_number';
        $date = $side === 'customer' ? 'i.issue_date' : 'i.supplier_document_date';
        $status = $side === 'customer' ? "i.status = 'issued'" : 'i.approved_at IS NOT NULL';
        $documentType = $side === 'customer' ? 'invoice' : 'bill';
        $direction = $side === 'customer' ? 'receipt' : 'disbursement';
        $label = $side === 'customer' ? 'Invoice' : 'Bill';

        return sprintf(<<<'SQL'
            SELECT %2$s::text AS reference,
                   %3$s AS happened_on,
                   '%7$s' AS label,
                   i.gross_amount::text AS amount
            FROM %1$s i
            WHERE i.entity_id = ?
              AND i.party_id = ?
              AND i.currency = ?
              AND %4$s
              AND %3$s BETWEEN ?::date AND ?::date

            UNION ALL

            SELECT p.number::text,
                   p.value_date,
                   'Payment',
                   (-a.amount)::text
            FROM allocations a
            JOIN payments p ON p.id = a.payment_id
            WHERE a.entity_id = ?
              AND a.document_type = '%5$s'
              AND a.reversed_at IS NULL
              AND p.party_id = ?
              AND p.currency = ?
              AND p.direction = '%6$s'
              AND p.value_date BETWEEN ?::date AND ?::date

            ORDER BY 2, 1
        SQL, $table, $reference, $date, $status, $documentType, $direction, $label);
    }
}
