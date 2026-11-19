<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Authorization\RequiresStepUp;
use App\Tenant\Tax\Exceptions\FilingRefused;
use App\Tenant\Tax\Models\ReturnFiling;
use App\Tenant\Tax\Models\ReturnPeriod;
use App\Tenant\Tax\PreFilingFinding;
use App\Tenant\Tenancy\Models\Entity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Files a return, and freezes what was filed - PF-086.
 *
 * ## The order of operations is the design
 *
 *   1. generate the return from the ledger
 *   2. run the checklist and refuse unless it is clear, or an override is
 *      given WITH a reason
 *   3. re-prove the filer's second factor - `tax_return.file` requires MFA
 *   4. write the figures, the supporting line ids and the hash
 *   5. mark the period filed
 *   6. record it in the audit trail
 *
 * Step 4 before step 5, inside one transaction: a period marked filed with no
 * filing behind it is a return nobody can produce, and the trigger on
 * `return_periods` means it could never be corrected.
 *
 * ## Through the Support interfaces, not through Identity
 *
 * `RequiresStepUp` and `AuditTrail` both live in `Support` precisely so that a
 * module can demand a second factor and record who acted without depending on
 * `Identity` - and the layer rules do not let Tax depend on it. The filer
 * takes the actor as an id rather than as a `User` for the same reason: the
 * audit implementation resolves the person from the session, which is where
 * that knowledge belongs.
 *
 * ## The override exists, and it demands a sentence
 *
 * Refusing outright would be the wrong call: a controller sometimes has to
 * file with a known problem because the deadline is today and a penalty is
 * worse than a disclosed error. What must not happen is filing over a
 * checklist silently. So the override takes a reason, the reason goes in the
 * audit record, and somebody reads it during an audit - which is why
 * "override" is refused as one.
 */
final class ReturnFiler
{
    public function __construct(
        private readonly ReturnGenerator $generator,
        private readonly PreFilingChecklist $checklist,
        private readonly ReturnPeriods $periods,
        private readonly RequiresStepUp $stepUp,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  string|null  $override  a reason to file over a blocking checklist
     *
     * @throws FilingRefused
     */
    public function file(
        Entity $entity,
        ReturnPeriod $period,
        string $filedBy,
        ?string $submissionReference = null,
        ?string $override = null,
    ): ReturnFiling {
        /*
         | `tax_return.file` is kept at any sample rate - PF-240.
         |
         | The last of the five names in `telemetry.sampling.always_sample`.
         | Filing is irreversible from the authority's side, so the trace of
         | the one that went wrong is the only account of what was sent.
         */
        return app(\App\Support\Telemetry\Tracer::class)->measure(
            'tax_return.file',
            \App\Support\Telemetry\SpanKind::Internal,
            [
                'jurisdiction' => (string) $period->jurisdiction,
                'period_id' => (string) $period->getKey(),
            ],
            fn (): ReturnFiling => $this->fileNow($entity, $period, $filedBy, $submissionReference, $override),
        );
    }

    private function fileNow(
        Entity $entity,
        ReturnPeriod $period,
        string $filedBy,
        ?string $submissionReference = null,
        ?string $override = null,
    ): ReturnFiling {
        if ($period->isFiled()) {
            throw FilingRefused::alreadyFiled($period->label);
        }

        $return = $this->generator->generate($entity, $period);
        $findings = $this->checklist->for($entity, $period, $return);
        $blocking = $this->checklist->blocking($findings);

        if ($blocking !== [] && $override === null) {
            throw FilingRefused::theChecklistIsNotClear($period->label, array_map(
                static fn (PreFilingFinding $finding): string => $finding->check,
                $blocking,
            ));
        }

        if ($blocking !== [] && trim((string) $override) === '') {
            throw FilingRefused::overridesNeedAReason($period->label);
        }

        /*
         | The second factor, re-proved at the moment of the act.
         |
         | `tax_return.file` is declared requires_mfa, so the guard refuses
         | unless the filer has asserted a factor recently. A permission check
         | alone would let a session that was authenticated this morning file
         | a statutory return this afternoon on a laptop somebody walked away
         | from.
         */
        $this->stepUp->assert('tax_return.file', (string) $period->getKey(), $period->label);

        $canonical = $return->canonical();
        $hash = $return->hash();
        $at = CarbonImmutable::now();

        return DB::connection('tenant')->transaction(function () use (
            $entity, $period, $filedBy, $submissionReference, $override,
            $return, $findings, $canonical, $hash, $at,
        ): ReturnFiling {
            $filing = ReturnFiling::create([
                'entity_id' => (string) $entity->getKey(),
                'return_period_id' => (string) $period->getKey(),
                'jurisdiction' => (string) $period->jurisdiction,
                'form' => $return->form,
                'label' => $period->label,
                'currency' => $return->currency,
                'figures' => $return->jsonSerialize(),
                'canonical' => $canonical,
                'hash' => $hash,
                'filed_at' => $at->toDateTimeString(),
                'filed_by' => $filedBy,
                'submission_reference' => $submissionReference,
            ]);

            $this->freezeTheLines($filing, $return);

            $this->periods->file($period, $filedBy, $submissionReference, $at);

            $this->audit->record(
                category: 'tax',
                action: 'return.filed',
                subjectType: 'return_period',
                subjectId: (string) $period->getKey(),
                subjectLabel: sprintf('%s %s', $period->jurisdiction, $period->label),
                after: [
                    'hash' => $hash,
                    'net_due' => (string) $return->netDue(),
                    'output_tax' => (string) $return->outputTax(),
                    'input_tax' => (string) $return->inputTax(),
                    'lines' => $return->lineCount(),
                    'submission_reference' => $submissionReference,
                    'checklist' => array_map(
                        static fn (PreFilingFinding $finding): array => $finding->jsonSerialize(),
                        $findings,
                    ),
                ],
                reason: $override,
                entityId: (string) $entity->getKey(),
            );

            return $filing;
        });
    }

    /**
     * The drill-down, frozen.
     *
     * Written as rows in chunks rather than one insert: a quarter's return can
     * stand behind tens of thousands of lines, and a single statement with
     * that many bindings is how a filing fails at the last step.
     */
    private function freezeTheLines(ReturnFiling $filing, \App\Tenant\Tax\TaxReturn $return): void
    {
        $rows = [];

        foreach ($return->boxes as $box) {
            foreach ($box->lines as $line) {
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'entity_id' => $filing->entity_id,
                    'return_filing_id' => $filing->getKey(),
                    'box' => $box->box,
                    'journal_line_id' => $line['id'],
                    'tax_point' => $line['tax_point'],
                    'amount' => $line['amount'],
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection('tenant')->table('return_filing_lines')->insert($chunk);
        }
    }
}
