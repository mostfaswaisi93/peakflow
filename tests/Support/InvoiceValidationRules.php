<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Sales\InvoiceFinding;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Services\Drafts;
use App\Tenant\Sales\Services\InvoiceValidator;
use App\Tenant\Tax\Models\ReturnPeriod;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What a draft refuses, and how it says so - PF-092.
 *
 * ## Why the draft is validated and not the issued document
 *
 * Because the point is to be told before the number is consumed. A validator
 * that runs at issue time and refuses is correct and useless: the person has
 * already sent the document to a customer in their head.
 *
 * ## Why every finding is addressed to a field
 *
 * `lines.3.tax_code_id` can be clicked. "A line has no tax code" cannot, and
 * on a fifty-line invoice the difference is twenty minutes. Each check here
 * asserts the field as well as the code, because a validator that reports the
 * right problem at the wrong address is a validator people route around.
 *
 * ## Blocking and advisory are separated on purpose
 *
 * `blocking()` is what the issuer consults. An advisory finding - evidence
 * that should be on record, a customer with no address - is reported and does
 * not stop anything, because a regime that accepts the document is not this
 * system's business to override. A check here proves an advisory finding does
 * not block, which is the half somebody breaks by treating the list as flat.
 */
final class InvoiceValidationRules
{
    use \Tests\Support\Concerns\IssuesInvoices;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['invoice validation (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->runEachInASavepoint([
                'a complete draft has nothing to report' => fn (): array => $this->aGoodDraftIsSilent(),
                'a document of nothing is refused' => fn (): array => $this->theRefusals([
                    'no_lines' => function (Invoice $draft): void {
                        $draft->lines()->delete();
                    },
                ]),
                'a line missing what a return needs is refused, by line' => fn (): array => $this->theRefusals([
                    'line_without_a_tax_code' => function (Invoice $draft): void {
                        $draft->lines()->update(['tax_code_id' => null]);
                    },
                    'line_without_a_tax_point' => function (Invoice $draft): void {
                        $draft->lines()->update(['tax_point' => null]);
                    },
                    'place_of_supply_unresolved' => function (Invoice $draft): void {
                        $draft->lines()->update(['place_of_supply' => null]);
                    },
                ], 'lines.1.'),
                'an exempt line with no reason behind it is refused' => fn (): array => $this->theRefusals([
                    /*
                     | The line claims exempt; the breakdown still says
                     | standard.
                     |
                     | Not "an E row with a null reason", because
                     | `invoice_tax_breakdown_reason_where_required` refuses
                     | that INSERT outright - measured, it is a check
                     | violation, not a finding. What the database cannot see
                     | is a line and a breakdown that disagree about what the
                     | line is, which is the shape a recomputation that half
                     | ran leaves behind: an invoice that says 0% with no
                     | reason anywhere, and cannot be defended.
                     */
                    'exemption_reason_missing' => function (Invoice $draft): void {
                        $draft->lines()->update(['tax_category' => 'E', 'tax_rate' => '0.000000']);
                    },
                ], 'lines.1.'),
                'totals that disagree with the lines are refused' => fn (): array => $this->theRefusals([
                    'net_does_not_match_the_lines' => function (Invoice $draft): void {
                        $draft->lines()->update(['net_amount' => '900.0000', 'unit_price' => '900.0000']);
                    },
                    'breakdown_does_not_sum_to_the_tax' => function (Invoice $draft): void {
                        $draft->taxBreakdown()->update(['tax_amount' => '9.0000']);
                    },
                    'no_breakdown' => function (Invoice $draft): void {
                        $draft->taxBreakdown()->delete();
                    },
                ]),
                'a party who is not a customer is refused' => fn (): array => $this->theRefusals([
                    'party_is_not_a_customer' => function (Invoice $draft): void {
                        $this->party?->update(['is_customer' => false]);
                    },
                    'party_was_merged' => function (Invoice $draft): void {
                        $survivor = Party::create([
                            'code' => 'INV-S-'.random_int(100, 999),
                            'legal_name' => 'Acme Industrial LLC (surviving)',
                            'is_customer' => true,
                            'country' => 'SA',
                            'default_currency' => 'SAR',
                            'active' => true,
                        ]);

                        /*
                         | Inactive as well as merged, because
                         | `parties_merged_is_inactive` refuses the other
                         | shape: a party cannot be both merged away and still
                         | open for business. The finding being checked is the
                         | merge, not the inactivity.
                         */
                        DB::connection('tenant')->table('parties')
                            ->where('id', (string) $this->party?->getKey())
                            ->update([
                                'merged_into_id' => (string) $survivor->getKey(),
                                'active' => false,
                            ]);
                    },
                ], 'party_id'),
                'a closed accounting period is refused' => fn (): array => $this->theRefusals([
                    'period_is_not_open' => function (Invoice $draft): void {
                        \assert($this->period !== null);
                        app(PeriodStateMachine::class)->transition(
                            $this->period, PeriodState::SoftClosed, 'closed for a validation check',
                        );
                    },
                ], 'issue_date'),
                'a filed return period is refused' => fn (): array => $this->theRefusals([
                    'return_period_will_not_take_it' => function (Invoice $draft): void {
                        /*
                         | Filed, with a filer and a time.
                         |
                         | `return_periods_filed_is_attributable` refuses a
                         | filing attributed to nobody - measured, it is a
                         | check violation - which is PF-087 making the point
                         | that a return nobody filed is a return nobody can
                         | be asked about.
                         */
                        ReturnPeriod::query()
                            ->where('entity_id', $this->entityId)
                            ->where('jurisdiction', 'SA')
                            ->where('label', '2026-05')
                            ->update([
                                'state' => 'filed',
                                'filed_at' => now(),
                                'filed_by' => $this->actorId(),
                            ]);
                    },
                ], 'tax_point'),
                'a currency the entity does not deal in is refused' => fn (): array => $this->theRefusals([
                    'currency_is_not_enabled' => function (Invoice $draft): void {
                        $draft->update(['currency' => 'JPY']);
                    },
                ], 'currency'),
                'a draft is editable, weightless and duplicable' => fn (): array => $this->aDraftIsWeightless(),
                'an advisory finding reports and does not block' => fn (): array => $this->advisoriesDoNotBlock(),
                'every finding speaks both languages' => fn (): array => $this->bothLanguages(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function aGoodDraftIsSilent(): array
    {
        $findings = app(InvoiceValidator::class)->validate($this->draft(), $this->entity());
        $blocking = app(InvoiceValidator::class)->blocking($findings);

        if ($blocking === []) {
            return [];
        }

        /*
         | Reported in full, not counted.
         |
         | "1 blocking finding" sends the reader back to the fixture with no
         | idea which of twenty checks is wrong, and this is the check every
         | other check in the suite depends on - a fixture that is quietly
         | invalid makes every refusal below pass for the wrong reason.
         */
        return array_map(static fn (InvoiceFinding $f): string => sprintf(
            'a complete standard-rate draft was refused: %s (%s) - %s',
            $f->field, $f->code, $f->messageEn,
        ), $blocking);
    }

    /**
     * Each mutation, applied alone, produces exactly that finding.
     *
     * @param  array<string, callable(Invoice): void>  $cases  code => what to break
     * @param  string  $fieldPrefix  what the finding's address must start with
     * @return list<string>
     */
    public function theRefusals(array $cases, string $fieldPrefix = ''): array
    {
        $violations = [];
        $validator = app(InvoiceValidator::class);

        foreach ($cases as $code => $break) {
            $savepoint = $this->savepoint();

            try {
                $draft = $this->draft();
                $break($draft);
                $draft = $draft->refresh();
                $draft->load('lines', 'taxBreakdown', 'party');

                $findings = $validator->validate($draft, $this->entity());
                $match = null;

                foreach ($findings as $finding) {
                    if ($finding->code === $code) {
                        $match = $finding;

                        break;
                    }
                }

                if ($match === null) {
                    $violations[] = sprintf(
                        'the draft was not refused with "%s". What came back: %s',
                        $code,
                        $findings === []
                            ? 'nothing at all'
                            : implode(', ', array_map(
                                static fn (InvoiceFinding $f): string => $f->code,
                                $findings,
                            )),
                    );

                    continue;
                }

                if (! $match->blocking) {
                    $violations[] = sprintf(
                        '"%s" was reported as advisory. A document that cannot reach a return, or whose '.
                        'totals do not tie, is not a matter of taste',
                        $code,
                    );
                }

                if ($fieldPrefix !== '' && ! str_starts_with($match->field, $fieldPrefix)) {
                    $violations[] = sprintf(
                        '"%s" is addressed to "%s" and should be addressed under "%s". A finding at the '.
                        'wrong address is a finding somebody has to hunt for',
                        $code, $match->field, $fieldPrefix,
                    );
                }
            } catch (Throwable $e) {
                $violations[] = sprintf('"%s" could not be checked: %s', $code, $e->getMessage());
            } finally {
                $this->rollbackTo($savepoint);
            }
        }

        return $violations;
    }

    /**
     * What a draft is: editable, with no number, no posting and no weight.
     *
     * @return list<string>
     */
    public function aDraftIsWeightless(): array
    {
        $violations = [];
        $entries = $this->journalEntries();
        $before = $this->nextNumber();

        $draft = $this->draft();
        $draft->update(['note' => 'edited freely, twice']);
        $draft->lines()->update(['description' => 'edited freely, twice']);

        if ($draft->refresh()->note !== 'edited freely, twice') {
            $violations[] = 'a draft could not be edited';
        }

        foreach ([
            'a number' => $draft->number,
            'an issue timestamp' => $draft->issued_at,
            'a chain position' => $draft->chain_index,
            'a frozen customer' => $draft->customer_snapshot,
        ] as $what => $value) {
            if ($value !== null) {
                $violations[] = sprintf('a draft carries %s', $what);
            }
        }

        if ($this->nextNumber() !== $before) {
            $violations[] = 'creating a draft consumed a number from the series';
        }

        if ($this->journalEntries() !== $entries) {
            $violations[] = 'creating a draft moved a balance. A draft is a document nobody has '.
                'committed to yet, and a draft with an effect on a balance is a document in the books '.
                'that nobody agreed to';
        }

        /*
         | And a duplicate carries the substance and none of the identity.
         |
         | The failure that looks harmless: a duplicate that copies the
         | number. It consumes nothing - the series never advanced - and now
         | two rows claim one legal number, one of which was never issued.
         */
        /*
         | An ISSUED document, because that is what gets duplicated.
         |
         | Measured: duplicating a draft made this check blind. The bite -
         | `'number' => $source->number` in the duplicator - copied a null,
         | because a draft has no number, and the check passed while the bug
         | it exists to catch was in place. "The same invoice again next
         | month" starts from a document that was sent.
         */
        $issued = $this->issue();
        /*
         | Dated explicitly, because the fixture lives in May 2026 and
         | `duplicate()` defaults to today - whose accounting period is
         | `future`, which does not accept postings. Measured: the duplicate
         | came back carrying `period_is_not_open`, which is the service and
         | the validator both behaving correctly and the check asking the
         | wrong question.
         */
        $copy = app(Drafts::class)->duplicate($issued, $this->date);

        if (! $copy->isDraft()) {
            $violations[] = 'a duplicate is not a draft';
        }

        foreach (['number', 'issued_at', 'issued_by', 'canonical', 'canonical_hash', 'previous_hash', 'chain_index'] as $column) {
            if ($copy->{$column} !== null) {
                $violations[] = sprintf('a duplicate copied "%s" from the document it was made from', $column);
            }
        }

        if ($copy->lines()->count() !== $issued->lines()->count()) {
            $violations[] = 'a duplicate did not copy the lines, which is the only reason to duplicate';
        }

        if ((string) $copy->net_amount !== (string) $issued->net_amount) {
            $violations[] = sprintf(
                'a duplicate of a %s document is for %s',
                (string) $issued->net_amount, (string) $copy->net_amount,
            );
        }

        if ((string) $copy->getKey() === (string) $issued->getKey()) {
            $violations[] = 'the duplicate is the same row';
        }

        $findings = app(InvoiceValidator::class)->blocking(
            app(InvoiceValidator::class)->validate($copy, $this->entity()),
        );

        if ($findings !== []) {
            $violations[] = sprintf(
                'a duplicate of a valid draft is not itself valid: %s',
                implode(', ', array_map(static fn (InvoiceFinding $f): string => $f->code, $findings)),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function advisoriesDoNotBlock(): array
    {
        $violations = [];
        $validator = app(InvoiceValidator::class);

        /*
         | An intra-community supply: evidence is required before the
         | treatment can be claimed, and the document still issues. The
         | evidence gate is PF-080's business at return time; blocking the
         | invoice here would stop a legitimate zero-rated export because a
         | shipping document has not arrived yet, which is not how the trade
         | works.
         */
        $draft = $this->draft();
        $draft->lines()->update(['tax_category' => 'K', 'tax_rate' => '0.000000']);
        $draft->taxBreakdown()->update([
            'category' => 'K',
            'rate' => '0.000000',
            'tax_amount' => '0.0000',
            'exemption_reason_code' => 'VATEX-EU-IC',
            'exemption_reason_text' => 'Intra-community supply, EN 16931 BT-120',
        ]);
        $draft->update([
            'tax_amount' => '0.0000',
            'gross_amount' => '1000.0000',
            'payable_amount' => '1000.0000',
        ]);

        $findings = $validator->validate($draft->refresh(), $this->entity());
        $codes = array_map(static fn (InvoiceFinding $f): string => $f->code, $findings);

        if (! \in_array('evidence_required', $codes, true)) {
            $violations[] = sprintf(
                'a category K line produced no evidence advisory. What came back: %s',
                $codes === [] ? 'nothing at all' : implode(', ', $codes),
            );
        }

        $blocking = array_map(
            static fn (InvoiceFinding $f): string => $f->code,
            $validator->blocking($findings),
        );

        if (\in_array('evidence_required', $blocking, true)) {
            $violations[] = 'the evidence advisory blocks issuing. A zero-rated export whose shipping '.
                'document has not arrived yet is still an invoice the business has to send';
        }

        return $violations;
    }

    /** @return list<string> */
    public function bothLanguages(): array
    {
        $violations = [];
        $draft = $this->draft();
        $draft->lines()->update(['tax_code_id' => null, 'tax_point' => null, 'place_of_supply' => null]);
        $draft->taxBreakdown()->delete();

        $findings = app(InvoiceValidator::class)->validate($draft->refresh(), $this->entity());

        if ($findings === []) {
            return ['a draft broken in four places produced no findings at all'];
        }

        foreach ($findings as $finding) {
            if (trim($finding->messageAr) === '') {
                $violations[] = sprintf('"%s" has no Arabic message', $finding->code);
            }

            if ($finding->messageAr === $finding->messageEn) {
                $violations[] = sprintf('"%s" has the same text in both languages', $finding->code);
            }

            /*
             | Arabic, actually in Arabic.
             |
             | An English sentence in the Arabic slot passes a "not empty"
             | check and fails a customer. The range is the Arabic block.
             */
            if (preg_match('/\p{Arabic}/u', $finding->messageAr) !== 1) {
                $violations[] = sprintf('"%s" has no Arabic script in its Arabic message', $finding->code);
            }

            if (trim($finding->messageEn) === '' || $finding->field === '' || $finding->code === '') {
                $violations[] = sprintf('a finding is incomplete: %s', json_encode($finding));
            }
        }

        return $violations;
    }
}
