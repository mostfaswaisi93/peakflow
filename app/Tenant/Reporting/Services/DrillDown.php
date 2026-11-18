<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Services;

use App\Support\Money\Money;
use App\Tenant\Identity\Models\User;
use App\Tenant\Ledger\Enums\StatementLine;
use App\Tenant\Ledger\Services\TrialBalance;
use App\Tenant\Reporting\Drill\Figure;
use App\Tenant\Reporting\Exceptions\ReportRefused;
use App\Tenant\Reporting\Registers\ReportParameters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * From any figure to the document and the people behind it - PF-189.
 *
 * The structural promise: **if a number cannot be explained, it is a bug.**
 * Not "most numbers can be explained on most screens" - every figure, by
 * one path, or the product is claiming something it cannot deliver.
 *
 * ## One mechanism, which is why this takes a Figure and not a report
 *
 * Three levels, in order: the journal lines, then the source document,
 * then who entered and posted it. Each level is a method here and nowhere
 * else. A report contributes a {@see Figure} - the accounts, the dates and
 * whether it is a balance or a movement - and gets the same three levels
 * whatever report it was.
 *
 * The alternative arrives one screen at a time: a `drillTrialBalance`, then
 * a `drillCashFlow` that forgets the adjusting flag, then a fourth that
 * shows a payroll document to anybody. The paths then differ in exactly
 * the way nobody tests, because each was written to satisfy one page.
 *
 * ## The lines are read through the ledger's own drill-down
 *
 * {@see TrialBalance::drillDown()} already answers "which lines are behind
 * this balance", with the same date and adjusting bounds the balance itself
 * used. Asking it is the only way the lines can be guaranteed to add up to
 * the figure; a second query here would be a second definition of which
 * lines are in a balance, and the first time the two disagreed the
 * drill-down would quietly explain a different number.
 *
 * ## It PROVES it adds up, when the caller says what the figure was
 *
 * Pass the figure as the report printed it and the explanation says
 * whether the lines come to it. A drill-down that does not add up to its
 * own total teaches people the report is approximate - and that is exactly
 * the state nobody notices, because each screen looks right on its own.
 *
 * A sign difference is reported SEPARATELY from a value difference, because
 * they have different causes and only one of them is a defect. A statement
 * presents a liability as a positive number under a heading that says
 * Liabilities, while the ledger stores a credit as negative: the magnitudes
 * agreeing and the signs not is presentation. The magnitudes disagreeing is
 * a line this drill-down did not read.
 *
 * ## Permissions are decided per level, against the figure's own entity
 *
 * Asked of `User::holds()` rather than of the gate, deliberately. The gate
 * answers for whatever entity the session has in context, and a figure
 * names its entity: a drill-down into last year's books for the entity
 * somebody switched away from would otherwise be authorized against the
 * wrong company's roles. Both answers look like a boolean.
 *
 * A level the viewer may not see reports **that it exists** and which
 * permission is missing. Never a blank: a blank is read as "there is
 * nothing here", which is how somebody concludes a figure has no document
 * behind it and goes looking for a bug in the ledger.
 *
 * ## What this does NOT do
 *
 * It never reads a row it may not show. For a payroll document that is not
 * a nicety: the payroll tables carry a restrictive row policy and return
 * nothing at all until a session claims visibility, and claiming is a WRITE
 * - it records who looked and why. A report may not write, so a payroll
 * document is named, not opened, and the reader is sent to the screen where
 * the claim is made with a reason.
 *
 * It also does not manage focus. The levels, their labels and the
 * breadcrumb back are produced here so that every page drills the same way;
 * moving focus into a level and back out is the page's, and PF-189's
 * keyboard criterion is met on the page with these as its material.
 */
final class DrillDown
{
    /**
     * How to name a source document, per source type.
     *
     * A table, the column that holds the number a person would recognise,
     * the column that dates it, the permission needed to see it, and the
     * screen that opens it.
     *
     * Declared as data because every entry is a claim that can be checked:
     * a check asserts each table and column exists and each named screen
     * resolves to a route. A map that drifted from the schema would throw
     * at the moment somebody drilled into that document type - which is to
     * say, in front of an auditor.
     *
     * A source type that is NOT here is reported by name rather than
     * guessed at. An entry posted by a process that owns no document - an
     * opening balance, a year-end close, a revaluation - has nothing to
     * open, and saying so is the honest answer.
     *
     * @var array<string, array{
     *     table: string, number: ?string, date: string, permission: ?string,
     *     screen: ?string, claim: ?string, label_ar: string, label_en: string,
     *     lines: ?array{table: string, key: string},
     * }>
     */
    private const DOCUMENTS = [
        'invoice' => [
            'table' => 'invoices',
            'number' => 'number',
            'date' => 'issue_date',
            'permission' => null,
            'screen' => 'invoices.show',
            'claim' => null,
            'label_ar' => 'فاتورة',
            'label_en' => 'Invoice',
            'lines' => ['table' => 'invoice_lines', 'key' => 'invoice_id'],
        ],
        'bill' => [
            'table' => 'bills',
            'number' => 'supplier_document_number',
            'date' => 'supplier_document_date',
            'permission' => null,
            'screen' => 'bills.show',
            'claim' => null,
            'label_ar' => 'فاتورة مورد',
            'label_en' => 'Supplier invoice',
            'lines' => ['table' => 'bill_lines', 'key' => 'bill_id'],
        ],
        'payment' => [
            'table' => 'payments',
            'number' => 'number',
            'date' => 'value_date',
            'permission' => null,
            'screen' => null,
            'claim' => null,
            'label_ar' => 'سداد',
            'label_en' => 'Payment',
            'lines' => null,
        ],
        'allocation' => [
            'table' => 'allocations',
            // An allocation carries no number of its own: it is an act
            // against a payment and a document, and both of those have
            // one. The id is the handle, and the date is what a reader
            // recognises it by.
            'number' => null,
            'date' => 'allocated_on',
            'permission' => null,
            'screen' => null,
            'claim' => null,
            'label_ar' => 'تخصيص سداد',
            'label_en' => 'Allocation',
            'lines' => null,
        ],
        'write_off' => [
            'table' => 'write_offs',
            'number' => 'number',
            'date' => 'written_off_on',
            'permission' => null,
            'screen' => null,
            'claim' => null,
            'label_ar' => 'إعدام دين',
            'label_en' => 'Write-off',
            'lines' => null,
        ],
        'expense_claim' => [
            'table' => 'expense_claims',
            'number' => 'number',
            'date' => 'claimed_on',
            'permission' => null,
            'screen' => null,
            'claim' => null,
            'label_ar' => 'مطالبة مصروفات',
            'label_en' => 'Expense claim',
            'lines' => ['table' => 'expense_claim_lines', 'key' => 'expense_claim_id'],
        ],
        'goods_receipt' => [
            'table' => 'goods_receipts',
            'number' => 'number',
            'date' => 'received_on',
            'permission' => null,
            'screen' => null,
            'claim' => null,
            'label_ar' => 'إشعار استلام',
            'label_en' => 'Goods receipt',
            'lines' => null,
        ],
        'bank_transaction' => [
            'table' => 'bank_transactions',
            'number' => 'bank_reference',
            'date' => 'booking_date',
            'permission' => null,
            'screen' => null,
            'claim' => null,
            'label_ar' => 'حركة بنكية',
            'label_en' => 'Bank transaction',
            'lines' => null,
        ],
        'ecl_run' => [
            'table' => 'ecl_runs',
            'number' => 'period_label',
            'date' => 'as_of',
            'permission' => null,
            'screen' => null,
            'claim' => null,
            'label_ar' => 'احتساب الخسائر المتوقعة',
            'label_en' => 'Expected credit loss run',
            'lines' => null,
        ],
        'payroll_run' => [
            'table' => 'payroll_runs',
            'number' => 'number',
            'date' => 'pay_on',
            /*
             | A salary is the one number in a business that everybody
             | wants and almost nobody should have, so the permission is
             | its own rather than `report.view` - which every role here
             | holds.
             */
            'permission' => 'payroll.view',
            'screen' => null,
            'claim' => 'The payroll tables return nothing until a session claims visibility, and the '.
                'claim records who looked and why. A report may not write, so this names the run and '.
                'does not open it: it is read on the payroll screen, where the reason is recorded.',
            'label_ar' => 'مسيّر رواتب',
            'label_en' => 'Payroll run',
            'lines' => null,
        ],
    ];

    /** Seeing any figure at all. Every role in this product holds it. */
    private const SEE_FIGURES = 'report.view';

    /** Who did what, which is the audit trail and is gated as one. */
    private const SEE_PEOPLE = 'audit.view';

    public function __construct(private readonly TrialBalance $balances) {}

    /**
     * Level one: the journal lines behind a figure.
     *
     * @param  ?string  $expected  the figure as the report printed it, in the
     *                             ledger's sign - a debit positive - so that
     *                             a disagreement can be told from a
     *                             presentation sign
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function explain(Figure $figure, ?User $viewer, ?string $expected = null): array
    {
        $parameters = ReportParameters::for(
            report: 'drill_down',
            entityId: $figure->entityId,
            from: $figure->from ?? $figure->until,
            until: $figure->until,
            currency: $figure->currency,
            includesAdjusting: $figure->includesAdjusting,
            filters: [
                'figure' => $figure->kind.':'.$figure->value,
                'on_report' => $figure->report,
            ],
        );

        $codes = $this->accountsBehind($figure);
        $access = $this->maySee($viewer, $figure->entityId, self::SEE_FIGURES);

        $lines = $access['permitted']
            ? $this->balances->drillDown(
                $figure->entityId,
                $codes,
                $figure->until,
                $figure->from,
                $figure->includesAdjusting,
            )
            : collect();

        $total = Money::zero($figure->currency);
        $explained = [];

        foreach ($lines as $line) {
            $amount = Money::of((string) $line->functional_amount, $figure->currency);
            $total = $total->plus($amount);

            $explained[] = [
                'line_id' => (string) $line->line_id,
                'entry_id' => (string) $line->entry_id,
                'entry_number' => (string) $line->number,
                'status' => (string) $line->status,
                'journal' => (string) $line->journal_code,
                'date' => (string) $line->accounting_date,
                'account_code' => (string) $line->account_code,
                'account_name_ar' => (string) $line->account_name_ar,
                'account_name_en' => (string) $line->account_name_en,
                'narration' => (string) ($line->line_narration ?? $line->entry_narration ?? ''),
                'posting_rule' => $line->posting_rule === null ? null : (string) $line->posting_rule,
                'currency' => (string) $line->currency,
                'amount' => (string) $line->amount,
                'functional_amount' => (string) $amount->amount,
                /*
                 | The next level's handle, and the fact that there IS one.
                 |
                 | Not the document itself: a list of fifty lines would be
                 | fifty document reads, half of them refused, to render a
                 | page nobody has drilled into yet. The stub says what
                 | kind of thing is below and lets the page ask.
                 */
                'source' => $this->stub($line, $viewer, $figure->entityId),
            ];
        }

        return [
            'figure' => $figure->toArray(),
            'parameters' => $parameters->toArray(),
            'parameter_lines' => $parameters->lines(),
            'accounts' => $codes,
            'levels' => ['figure', 'journal_lines', 'source_document', 'people'],
            'level' => 'journal_lines',
            'breadcrumb' => $this->breadcrumb($figure, 'journal_lines'),
            'access' => $access,
            'lines' => $explained,
            'line_count' => \count($explained),
            'total' => (string) $total->amount,
            'total_basis' => 'the ledger\'s sign, where a debit is positive. A statement that presents a '.
                'liability as a positive number under a heading saying Liabilities has flipped this, '.
                'which is presentation and not a difference.',
            'reconciliation' => $this->reconcile($expected, $total, $figure),
            'declaration' => $this->declare($figure, $codes, $explained, $access, $expected, $total),
        ];
    }

    /**
     * Level two: the document the entry came from.
     *
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function document(Figure $figure, string $entryId, ?User $viewer): array
    {
        $entry = DB::connection('tenant')->table('journal_entries')
            ->where('entity_id', $figure->entityId)
            ->where('id', $entryId)
            ->first(['id', 'number', 'source_type', 'source_id', 'posting_rule', 'narration']);

        if ($entry === null) {
            /*
             | Refused rather than returned empty, and the same refusal
             | whether the entry belongs to another entity or to nobody.
             |
             | Two different answers would let somebody walk entry ids
             | across companies and learn which ones exist - from a report
             | screen, which is the last place anybody looks for that.
             */
            throw ReportRefused::because(
                'No entry with that id in this entity. The drill-down refuses rather than showing an '.
                'empty document, and it refuses identically for an entry that belongs to another '.
                'entity - two different answers would let entry ids be walked across companies.',
            );
        }

        $sourceType = $entry->source_type === null ? null : (string) $entry->source_type;
        $sourceId = $entry->source_id === null ? null : (string) $entry->source_id;

        $node = [
            'figure' => $figure->toArray(),
            'level' => 'source_document',
            'levels' => ['figure', 'journal_lines', 'source_document', 'people'],
            'breadcrumb' => $this->breadcrumb($figure, 'source_document', $entryId, (string) $entry->number),
            'entry_id' => (string) $entry->id,
            'entry_number' => (string) $entry->number,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'posting_rule' => $entry->posting_rule === null ? null : (string) $entry->posting_rule,
        ];

        if ($sourceType === null || $sourceId === null) {
            /*
             | An entry with no source document is not a failure.
             |
             | An opening balance, a manual journal, a year-end close:
             | somebody typed each of those and no document stands behind
             | it. The explanation is the entry itself, and the narration
             | is the only account of why - which is why the manual path
             | demands a reason.
             */
            return array_merge($node, [
                'exists' => false,
                'document' => null,
                'access' => ['permitted' => true, 'permission' => null, 'reason_en' => null, 'reason_ar' => null],
                'why' => 'This entry names no source document. It was entered rather than produced by a '.
                    'document - an opening balance, a manual journal, a close - so the entry and its '.
                    'narration are the whole explanation: '.((string) ($entry->narration ?? '(none)')),
            ]);
        }

        $map = self::DOCUMENTS[$sourceType] ?? null;

        if ($map === null) {
            return array_merge($node, [
                'exists' => true,
                'document' => null,
                'access' => ['permitted' => true, 'permission' => null, 'reason_en' => null, 'reason_ar' => null],
                'why' => sprintf(
                    'This entry came from a %s, which this drill-down can name and cannot open. The '.
                    'type and the id are here so the document can be found; guessing at a table from '.
                    'the type would open the wrong row the first time one was renamed.',
                    $sourceType,
                ),
            ]);
        }

        $access = $this->maySee($viewer, $figure->entityId, $map['permission']);

        if (! $access['permitted']) {
            /*
             | It EXISTS and it is not shown - which is the criterion.
             |
             | A blank here reads as "this figure has no document behind
             | it", and somebody acting on that goes looking for a defect
             | in the ledger. The row itself is not read at all: for
             | payroll it could not be, and for everything else reading a
             | row in order to withhold it is how a figure ends up in a
             | log or an export.
             */
            return array_merge($node, [
                'exists' => true,
                'document' => null,
                'access' => $access,
                'label_ar' => $map['label_ar'],
                'label_en' => $map['label_en'],
                'why' => sprintf(
                    'A %s stands behind this line and you do not hold "%s", so it is named and not '.
                    'opened. The row was not read: reading it in order to withhold it is how a figure '.
                    'reaches a log or an export.',
                    $map['label_en'],
                    (string) $map['permission'],
                ),
            ]);
        }

        if ($map['claim'] !== null) {
            return array_merge($node, [
                'exists' => true,
                'document' => null,
                'access' => $access,
                'label_ar' => $map['label_ar'],
                'label_en' => $map['label_en'],
                'screen' => $map['screen'],
                'why' => $map['claim'],
            ]);
        }

        /*
         | The columns are named by the map and read back by name.
         |
         | Not aliased in SQL: the column list goes through the query
         | builder's identifier quoting, so "issue_date::text AS date"
         | arrives as a quoted column of that whole name and the query
         | fails on a column nobody declared. A date column comes back
         | from the builder as the string PostgreSQL rendered, which is
         | what a report wants anyway.
         */
        $columns = array_values(array_filter(['id', $map['number'], $map['date']]));

        $document = DB::connection('tenant')->table($map['table'])
            ->where('entity_id', $figure->entityId)
            ->where('id', $sourceId)
            ->first($columns);

        return array_merge($node, [
            'exists' => true,
            'label_ar' => $map['label_ar'],
            'label_en' => $map['label_en'],
            'screen' => $map['screen'],
            'access' => $access,
            'document' => $document === null ? null : [
                'id' => (string) $document->id,
                'number' => $map['number'] === null
                    ? null
                    : (string) $document->{$map['number']},
                'date' => (string) $document->{$map['date']},
            ],
            /*
             | The document's own lines, where it has them - PF-189.
             |
             | Because the figure is the sum of particular lines, and "it
             | came from INV-1001" is not an explanation of which. This is
             | the end of the path the task names: a cash-flow line, to
             | the journal lines behind it, to the invoice, to the invoice
             | line.
             |
             | Only for the types whose lines are an itemisation of the
             | document. A payroll run has lines and they are salaries:
             | they are behind a claim this report may not make, and they
             | are not here even for somebody who holds the permission.
             */
            'lines' => $document === null ? [] : $this->documentLines($map, $figure->entityId, $sourceId),
            'why' => $document === null
                /*
                 | The entry names a document that is not there.
                 |
                 | Not a blank and not an error: a restrictive row policy
                 | can hide it, and a document deleted under an entry it
                 | produced would be a defect worth a sentence. Either
                 | way the reader is told the entry claims one, because
                 | that is the fact the drill-down has.
                 */
                ? sprintf(
                    'This entry names %s %s and the row is not readable from here. Either a row policy '.
                    'is hiding it or the document was removed under an entry it produced - the second '.
                    'is a defect, and both are worth knowing before the figure is explained to '.
                    'anybody.',
                    $map['label_en'],
                    $sourceId,
                )
                : null,
        ]);
    }

    /**
     * Level three: who entered the entry, and who posted it.
     *
     * @return array<string, mixed>
     *
     * @throws ReportRefused
     */
    public function people(Figure $figure, string $entryId, ?User $viewer): array
    {
        $entry = DB::connection('tenant')->table('journal_entries')
            ->where('entity_id', $figure->entityId)
            ->where('id', $entryId)
            ->first(['id', 'number', 'created_by_user_id', 'posted_by_user_id', 'entered_at', 'posted_at']);

        if ($entry === null) {
            throw ReportRefused::because(
                'No entry with that id in this entity, and the refusal is deliberately the same one a '.
                'foreign entity\'s entry gets.',
            );
        }

        $access = $this->maySee($viewer, $figure->entityId, self::SEE_PEOPLE);

        $node = [
            'figure' => $figure->toArray(),
            'level' => 'people',
            'levels' => ['figure', 'journal_lines', 'source_document', 'people'],
            'breadcrumb' => $this->breadcrumb($figure, 'people', $entryId, (string) $entry->number),
            'entry_id' => (string) $entry->id,
            'entry_number' => (string) $entry->number,
            'access' => $access,
        ];

        if (! $access['permitted']) {
            /*
             | Who posted an entry IS the audit trail, so it is gated as
             | the audit trail - and the fact that two people are recorded
             | is not itself withheld. "Somebody entered this and somebody
             | posted it, and you may not see who" is an answer; a blank
             | reads as an entry nobody is accountable for, which is the
             | opposite of what the ledger knows.
             */
            return array_merge($node, [
                'entered_by' => null,
                'posted_by' => null,
                'recorded' => [
                    'entered' => $entry->created_by_user_id !== null,
                    'posted' => $entry->posted_by_user_id !== null,
                ],
                'why' => 'Who entered and who posted an entry is audit-trail information, and you do '.
                    'not hold "'.self::SEE_PEOPLE.'". The ledger records both; this page will not name '.
                    'them.',
            ]);
        }

        return array_merge($node, [
            'entered_by' => $this->person($entry->created_by_user_id === null ? null : (string) $entry->created_by_user_id),
            'entered_at' => $entry->entered_at === null ? null : (string) $entry->entered_at,
            'posted_by' => $this->person($entry->posted_by_user_id === null ? null : (string) $entry->posted_by_user_id),
            'posted_at' => $entry->posted_at === null ? null : (string) $entry->posted_at,
            /*
             | Entered and posted are two facts and sometimes two people.
             |
             | That separation is the whole of maker-checker, and a
             | drill-down that showed one name would make a self-approved
             | entry indistinguishable from a reviewed one.
             */
            'why' => 'Who drafted an entry and who posted it are separate facts. Where they are the '.
                'same person, nobody reviewed it before it reached the ledger.',
            'same_person' => $entry->created_by_user_id !== null
                && $entry->created_by_user_id === $entry->posted_by_user_id,
        ]);
    }

    /**
     * Which accounts a figure covers.
     *
     * @return list<string>
     *
     * @throws ReportRefused
     */
    public function accountsBehind(Figure $figure): array
    {
        $query = DB::connection('tenant')->table('accounts')
            ->where('entity_id', $figure->entityId);

        match ($figure->kind) {
            'account' => $query->where('code', $figure->value),
            'statement_line' => $query->where('statement_line', $figure->value),
            'account_type' => $query->where('type', $figure->value),
            'cash_flow_activity' => $query->whereIn('statement_line', $this->linesForActivity($figure->value)),
            // Figure::KINDS is closed and checked in its constructors, so
            // this arm is unreachable - and it is here rather than absent
            // because a match with no default is a TypeError somebody
            // reads as a bug in the drill-down.
            default => throw ReportRefused::because('Unknown figure kind "'.$figure->kind.'".'),
        };

        /** @var list<string> $codes */
        $codes = $query->orderBy('code')->pluck('code')->map(static fn (mixed $code): string => (string) $code)->all();

        return $codes;
    }

    /**
     * The IAS 1 lines that belong to one IAS 7 activity.
     *
     * Read from the enum rather than listed here, so a line added to the
     * statement joins the right activity without this class learning about
     * it - the alternative being a second classification that is right on
     * the day it is written.
     *
     * @return list<string>
     *
     * @throws ReportRefused
     */
    private function linesForActivity(string $activity): array
    {
        $lines = [];

        foreach (StatementLine::cases() as $line) {
            if ($line->cashFlowActivity()->value === $activity) {
                $lines[] = $line->value;
            }
        }

        if ($lines === []) {
            throw ReportRefused::because(sprintf(
                'No statement line belongs to the "%s" activity, so a figure on it cannot be '.
                'explained. An activity nobody classified anything under would drill into nothing and '.
                'present that as an explanation.',
                $activity,
            ));
        }

        return $lines;
    }

    /**
     * Whether a viewer may see a level, and why not.
     *
     * @return array{permitted: bool, permission: ?string, reason_en: ?string, reason_ar: ?string}
     */
    private function maySee(?User $viewer, string $entityId, ?string $permission): array
    {
        if ($viewer === null) {
            /*
             | No viewer is not "no restriction".
             |
             | A permission question with no subject has one honest answer.
             | Defaulting to permitted is the failure this signature exists
             | to prevent: a background job or a console command rendering
             | a drill-down would then see everything, and nothing about
             | the call would look unusual.
             */
            return [
                'permitted' => false,
                'permission' => $permission,
                'reason_en' => 'Nobody is signed in. A permission question with no subject is answered '.
                    'no, because the alternative is a console command or a queued job seeing '.
                    'everything.',
                'reason_ar' => 'لا يوجد مستخدم مسجّل الدخول، وسؤال الصلاحية بلا صاحب جوابه الرفض.',
            ];
        }

        if ($permission === null) {
            return ['permitted' => true, 'permission' => null, 'reason_en' => null, 'reason_ar' => null];
        }

        if ($viewer->holds($permission, $entityId)) {
            return ['permitted' => true, 'permission' => $permission, 'reason_en' => null, 'reason_ar' => null];
        }

        return [
            'permitted' => false,
            'permission' => $permission,
            'reason_en' => sprintf(
                'This level needs "%s" in this entity and you do not hold it. What is here is named '.
                'rather than hidden: a blank reads as nothing being here at all.',
                $permission,
            ),
            'reason_ar' => sprintf(
                'هذا المستوى يحتاج صلاحية «%s» في هذه الشركة. المحتوى مذكور وغير معروض، لأنّ الفراغ '.
                'يُقرأ كأن لا شيء هنا.',
                $permission,
            ),
        ];
    }

    /**
     * The stub that says what is one level down, without reading it.
     *
     * @return array<string, mixed>
     */
    private function stub(object $line, ?User $viewer, string $entityId): array
    {
        $type = $line->source_type === null ? null : (string) $line->source_type;
        $id = $line->source_id === null ? null : (string) $line->source_id;

        if ($type === null || $id === null) {
            return [
                'type' => null,
                'id' => null,
                'exists' => false,
                'label_en' => 'Entered, with no source document',
                'label_ar' => 'قيد مُدخَل بلا مستند',
                'permitted' => true,
            ];
        }

        $map = self::DOCUMENTS[$type] ?? null;
        $access = $this->maySee($viewer, $entityId, $map['permission'] ?? null);

        return [
            'type' => $type,
            'id' => $id,
            'exists' => true,
            'label_en' => $map['label_en'] ?? $type,
            'label_ar' => $map['label_ar'] ?? $type,
            'openable' => $map !== null,
            'permitted' => $access['permitted'],
            'permission' => $access['permission'],
        ];
    }

    /**
     * The document's own lines: the last step of the path.
     *
     * @param  array{table: string, number: ?string, date: string, permission: ?string, screen: ?string, claim: ?string, label_ar: string, label_en: string, lines: ?array{table: string, key: string}}  $map
     * @return list<array<string, mixed>>
     */
    private function documentLines(array $map, string $entityId, string $documentId): array
    {
        if ($map['lines'] === null) {
            return [];
        }

        $rows = DB::connection('tenant')->table($map['lines']['table'])
            ->where('entity_id', $entityId)
            ->where($map['lines']['key'], $documentId)
            ->orderBy('line_no')
            ->get(['id', 'line_no', 'description', 'net_amount']);

        return $rows->map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'line_no' => (int) $row->line_no,
            'description' => (string) ($row->description ?? ''),
            'net_amount' => (string) $row->net_amount,
        ])->all();
    }

    /** @return array<string, mixed> */
    private function person(?string $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $user = DB::connection('tenant')->table('users')->where('id', $userId)->first(['id', 'name', 'active']);

        if ($user === null) {
            /*
             | A user id with no user is reported, not blanked.
             |
             | A deleted user is the ordinary cause and the entry is still
             | theirs; a blank would present a posted entry as one nobody
             | made. The id is kept because it is what an investigation
             | follows.
             */
            return [
                'id' => $userId,
                'name' => null,
                'active' => null,
                'why' => 'The entry names this user id and no user row answers to it - usually somebody '.
                    'who has since been removed. The entry is still theirs.',
            ];
        }

        return [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'active' => (bool) $user->active,
            'why' => null,
        ];
    }

    /**
     * Whether the lines come to the figure, told apart from the sign.
     *
     * @return array<string, mixed>
     */
    private function reconcile(?string $expected, Money $total, Figure $figure): array
    {
        if ($expected === null) {
            return [
                'checked' => false,
                'why' => 'The caller did not say what the figure was, so this explains the lines without '.
                    'proving they come to it. Pass the printed figure and the drill-down asserts it.',
            ];
        }

        $printed = Money::of($expected, $figure->currency);
        $difference = $total->minus($printed);

        if ($difference->isZero()) {
            return [
                'checked' => true,
                'agrees' => true,
                'expected' => (string) $printed->amount,
                'total' => (string) $total->amount,
                'difference' => (string) $difference->amount,
                'why' => null,
            ];
        }

        /*
         | The magnitudes agreeing and the signs not is PRESENTATION.
         |
         | A liability is a credit, stored negative, and presented
         | positive under a heading that says Liabilities. Reporting that
         | as a difference would cry wolf on every liability line on every
         | statement, and the day there was a real difference nobody would
         | be reading.
         */
        if ($total->negated()->equals($printed)) {
            return [
                'checked' => true,
                'agrees' => true,
                'expected' => (string) $printed->amount,
                'total' => (string) $total->amount,
                'difference' => (string) $difference->amount,
                'why' => 'The lines come to the figure with the opposite sign, which is the statement\'s '.
                    'presentation: a credit balance is stored negative and shown positive under a '.
                    'heading that names it. The magnitudes agree exactly, so no line is missing.',
            ];
        }

        return [
            'checked' => true,
            'agrees' => false,
            'expected' => (string) $printed->amount,
            'total' => (string) $total->amount,
            'difference' => (string) $difference->amount,
            'why' => 'The lines behind this figure do not come to it, and not by a sign. Either the '.
                'figure covers accounts this drill-down did not resolve, or it was taken on different '.
                'bounds - a balance explained with a period\'s dates is the usual cause.',
        ];
    }

    /**
     * The path back, one crumb per level.
     *
     * Produced here rather than on each page so that every drill-down
     * reads the same way round. PF-189 asks for a breadcrumb back and for
     * focus management: the first is this, the second is the page's, and
     * giving the page the labels is what stops four pages inventing four
     * vocabularies for the same four levels.
     *
     * @return list<array<string, mixed>>
     */
    private function breadcrumb(
        Figure $figure,
        string $level,
        ?string $entryId = null,
        ?string $entryNumber = null,
    ): array {
        $crumbs = [
            [
                'level' => 'figure',
                'label_ar' => $figure->labelAr ?? $figure->value,
                'label_en' => $figure->labelEn ?? $figure->value,
                'detail_en' => $figure->report,
                'current' => $level === 'figure',
            ],
            [
                'level' => 'journal_lines',
                'label_ar' => 'سطور القيد',
                'label_en' => 'Journal lines',
                'detail_en' => $figure->basis(),
                'current' => $level === 'journal_lines',
            ],
        ];

        if ($entryId !== null) {
            $crumbs[] = [
                'level' => 'source_document',
                'label_ar' => 'المستند',
                'label_en' => 'Source document',
                'detail_en' => 'entry '.(string) $entryNumber,
                'entry_id' => $entryId,
                'current' => $level === 'source_document',
            ];
        }

        if ($level === 'people') {
            $crumbs[] = [
                'level' => 'people',
                'label_ar' => 'من أدخله ومن رحّله',
                'label_en' => 'Who entered and posted it',
                'detail_en' => 'entry '.(string) $entryNumber,
                'entry_id' => $entryId,
                'current' => true,
            ];
        }

        return $crumbs;
    }

    /**
     * What the reader is told before any of it.
     *
     * @param  list<string>  $codes
     * @param  list<array<string, mixed>>  $lines
     * @param  array{permitted: bool, permission: ?string, reason_en: ?string, reason_ar: ?string}  $access
     * @return array<string, string>
     */
    private function declare(
        Figure $figure,
        array $codes,
        array $lines,
        array $access,
        ?string $expected,
        Money $total,
    ): array {
        if (! $access['permitted']) {
            return [
                'severity' => 'withheld',
                'en' => (string) $access['reason_en'],
                'ar' => (string) $access['reason_ar'],
            ];
        }

        if ($codes === []) {
            return [
                'severity' => 'empty',
                'en' => sprintf(
                    'No account in this chart is classified as %s "%s", so the figure is zero and there '.
                    'is nothing behind it. That is different from a figure whose lines could not be '.
                    'found, and the difference is why this says which it is.',
                    $figure->kind,
                    $figure->value,
                ),
                'ar' => 'لا يوجد حساب في هذه الشجرة مُصنَّف بهذا التصنيف، فالرقم صفر ولا يوجد ما يفسّره.',
            ];
        }

        if ($lines === []) {
            return [
                'severity' => 'empty',
                'en' => sprintf(
                    '%d account%s carr%s this classification and no posted line reached %s in %s. The '.
                    'figure is zero because nothing happened, not because nothing was found.',
                    \count($codes),
                    \count($codes) === 1 ? '' : 's',
                    \count($codes) === 1 ? 'ies' : 'y',
                    \count($codes) === 1 ? 'it' : 'them',
                    $figure->isBalance() ? 'the whole history up to '.$figure->until->toDateString() : 'this period',
                ),
                'ar' => 'الحسابات موجودة ولم يصلها أي سطر مُرحَّل في هذا النطاق، فالرقم صفر لأن لا شيء حدث.',
            ];
        }

        if ($expected !== null) {
            $printed = Money::of($expected, $figure->currency);

            if (! $total->equals($printed) && ! $total->negated()->equals($printed)) {
                return [
                    'severity' => 'broken',
                    'en' => sprintf(
                        'THESE LINES DO NOT COME TO THE FIGURE. The report printed %s and the %d lines '.
                        'behind it come to %s, a difference of %s that is not a sign. A figure that '.
                        'cannot be explained is a bug, so this is one: the usual cause is a balance '.
                        'drilled with a period\'s dates, which explains the movement instead.',
                        (string) $printed->amount,
                        \count($lines),
                        (string) $total->amount,
                        (string) $total->minus($printed)->amount,
                    ),
                    'ar' => 'سطور هذا الرقم لا تساويه. الرقم المطبوع والمجموع مختلفان، والفرق ليس إشارة.',
                ];
            }
        }

        return [
            'severity' => 'ok',
            'en' => sprintf(
                '%d line%s on %d account%s, coming to %s in the ledger\'s sign, over %s. Each line '.
                'opens the document it came from, and each document names who entered and posted it.',
                \count($lines),
                \count($lines) === 1 ? '' : 's',
                \count($codes),
                \count($codes) === 1 ? '' : 's',
                (string) $total->amount,
                $figure->basis(),
            ),
            'ar' => sprintf(
                '%d سطرًا في %d حسابًا، مجموعها %s بإشارة الدفتر. كل سطر يفتح مستنده، وكل مستند يذكر من '.
                'أدخله ومن رحّله.',
                \count($lines),
                \count($codes),
                (string) $total->amount,
            ),
        ];
    }

    /**
     * The map, for the check that asserts it matches the schema.
     *
     * Exposed because a claim nothing can verify is a claim that drifts:
     * the alternative is a check that copies the map, which would then
     * agree with itself and not with the database.
     *
     * @return array<string, array{
     *     table: string, number: ?string, date: string, permission: ?string,
     *     screen: ?string, claim: ?string, label_ar: string, label_en: string,
     *     lines: ?array{table: string, key: string},
     * }>
     */
    public static function documentTypes(): array
    {
        return self::DOCUMENTS;
    }

    /**
     * Every line behind a figure, for a caller that wants the rows.
     *
     * @return Collection<int, object>
     */
    public function lines(Figure $figure): Collection
    {
        return $this->balances->drillDown(
            $figure->entityId,
            $this->accountsBehind($figure),
            $figure->until,
            $figure->from,
            $figure->includesAdjusting,
        );
    }
}
