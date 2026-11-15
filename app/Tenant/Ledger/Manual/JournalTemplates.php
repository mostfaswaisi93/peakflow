<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Manual;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Exceptions\TemplateRefused;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Templates and recurring journals - PF-055.
 *
 * ## A template is the shape without the amounts
 *
 * The accrual posted every month is the same entry with a different figure,
 * and the way people do it without a template is to find last month's and
 * copy it. That works until somebody copies the one that was reversed, or the
 * one from before the chart changed - and the copy carries the original's
 * date, which is then edited, usually correctly.
 *
 * ## The lines hold a SHARE, so one figure drives the entry
 *
 * A template line carries a signed proportion rather than an amount. Giving
 * the template an amount would mean a template that balanced when it was
 * written and silently does not after somebody edits one line; shares are
 * checked to cancel, by a deferred database constraint, so the template
 * cannot be saved in a state that produces an unbalanced entry.
 *
 * ## The apportionment residual goes to the LARGEST share
 *
 * Three equal shares of 100.00 are 33.33 each and 99.99 in total. The
 * remaining 0.01 has to land somewhere, and the choice is not arbitrary: the
 * largest share absorbs it, because a cent on the biggest line is the
 * smallest relative distortion and because "the largest" is deterministic
 * where "the first" depends on the order somebody typed the lines in.
 *
 * ## A recurring template PREPARES; it does not post
 *
 * An entry appearing in the ledger because a date passed is an entry nobody
 * decided to make, and the first time it is wrong is the month a rate changed
 * and nobody looked. So the scheduled run produces a draft and says so, and a
 * person posts it.
 */
final class JournalTemplates
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Save a template.
     *
     * @param  list<array{account_code: string, share: string, narration?: string|null, dimensions?: array<int, string|null>}>  $lines
     *
     * @throws TemplateRefused
     */
    public function save(
        Entity $entity,
        string $key,
        string $nameAr,
        string $nameEn,
        array $lines,
        ?string $recurrence = null,
        ?Carbon $nextRunOn = null,
        ?string $narration = null,
        string $journalCode = 'GEN',
    ): string {
        $entityId = (string) $entity->getKey();

        if (count($lines) < 2) {
            throw TemplateRefused::needsTwoLines($key, count($lines));
        }

        if ($recurrence !== null && ! in_array($recurrence, ['monthly', 'quarterly', 'annually'], true)) {
            throw TemplateRefused::unknownRecurrence($key, $recurrence);
        }

        if ($recurrence !== null && $nextRunOn === null) {
            throw TemplateRefused::recurrenceNeedsADate($key);
        }

        $shares = BigDecimal::zero();
        $resolved = [];

        foreach ($lines as $position => $line) {
            $code = (string) $line['account_code'];

            $account = DB::connection('tenant')->table('accounts')
                ->where('entity_id', $entityId)
                ->where('code', $code)
                ->first(['id', 'postable']);

            if ($account === null) {
                throw TemplateRefused::unknownAccount($key, $code);
            }

            if ($account->postable !== true) {
                throw TemplateRefused::accountIsAHeading($key, $code);
            }

            /*
             | A control account is refused in a TEMPLATE, not only at
             | posting time.
             |
             | Because a template is written once and used every month: the
             | refusal at posting would arrive on the first run, after
             | somebody built the whole thing, and again every month until
             | they worked out it was the template.
             */
            $isControl = DB::connection('tenant')->table('account_roles')
                ->where('account_id', $account->id)
                ->exists();

            if ($isControl) {
                throw TemplateRefused::accountIsAControl($key, $code);
            }

            $share = BigDecimal::of((string) $line['share']);

            if ($share->isZero()) {
                throw TemplateRefused::shareIsZero($key, $code);
            }

            $shares = $shares->plus($share);

            $resolved[] = [
                'position' => $position + 1,
                'account_id' => (string) $account->id,
                'share' => (string) $share->toScale(12),
                'narration' => $line['narration'] ?? null,
                'dimensions' => $line['dimensions'] ?? [],
            ];
        }

        if (! $shares->isZero()) {
            throw TemplateRefused::sharesDoNotCancel($key, (string) $shares);
        }

        return DB::connection('tenant')->transaction(function () use (
            $entityId,
            $key,
            $nameAr,
            $nameEn,
            $narration,
            $journalCode,
            $recurrence,
            $nextRunOn,
            $resolved,
        ): string {
            $existing = DB::connection('tenant')->table('journal_templates')
                ->where('entity_id', $entityId)
                ->where('key', $key)
                ->first(['id']);

            $templateId = $existing->id ?? (string) Str::ulid();

            $attributes = [
                'entity_id' => $entityId,
                'key' => $key,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'narration' => $narration,
                'journal_code' => $journalCode,
                'recurrence' => $recurrence,
                'next_run_on' => $nextRunOn?->toDateString(),
                'active' => true,
                'updated_at' => now(),
            ];

            if ($existing === null) {
                DB::connection('tenant')->table('journal_templates')->insert($attributes + [
                    'id' => $templateId,
                    'created_by_user_id' => auth()->guard('web')->id(),
                    'created_at' => now(),
                ]);
            } else {
                DB::connection('tenant')->table('journal_templates')
                    ->where('id', $templateId)
                    ->update($attributes);

                /*
                 | The lines are replaced, not merged.
                 |
                 | A merge would leave a line somebody removed, and the
                 | shares would then not cancel - which the deferred
                 | constraint catches at commit, correctly and confusingly.
                 */
                DB::connection('tenant')->table('journal_template_lines')
                    ->where('journal_template_id', $templateId)
                    ->delete();
            }

            foreach ($resolved as $line) {
                $row = [
                    'id' => (string) Str::ulid(),
                    'entity_id' => $entityId,
                    'journal_template_id' => $templateId,
                    'position' => $line['position'],
                    'account_id' => $line['account_id'],
                    'share' => $line['share'],
                    'narration' => $line['narration'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                foreach ((array) $line['dimensions'] as $axis => $value) {
                    $row['dimension_'.$axis] = $value;
                }

                DB::connection('tenant')->table('journal_template_lines')->insert($row);
            }

            $this->audit->record(
                category: 'ledger',
                action: 'ledger.template_saved',
                subjectType: 'journal_templates',
                subjectId: $templateId,
                subjectLabel: $key,
                after: [
                    'lines' => count($resolved),
                    'recurrence' => $recurrence ?? 'none',
                    'next_run_on' => $nextRunOn?->toDateString(),
                ],
                reason: sprintf('Template "%s" saved with %d lines', $key, count($resolved)),
                entityId: $entityId,
            );

            return $templateId;
        });
    }

    /**
     * Turn a template and one amount into draft lines.
     *
     * @return list<DraftLine>
     *
     * @throws TemplateRefused
     */
    public function prepare(Entity $entity, string $key, Money $total): array
    {
        $entityId = (string) $entity->getKey();

        $template = DB::connection('tenant')->table('journal_templates')
            ->where('entity_id', $entityId)
            ->where('key', $key)
            ->first();

        if ($template === null) {
            throw TemplateRefused::unknownTemplate($key);
        }

        if ($template->active !== true) {
            throw TemplateRefused::inactive($key);
        }

        $lines = DB::connection('tenant')->table('journal_template_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.journal_template_id', $template->id)
            ->orderBy('l.position')
            ->get(['a.code', 'l.share', 'l.narration', 'l.dimension_1', 'l.dimension_2', 'l.dimension_3',
                'l.dimension_4', 'l.dimension_5', 'l.dimension_6', 'l.dimension_7', 'l.dimension_8']);

        if ($lines->count() < 2) {
            throw TemplateRefused::needsTwoLines($key, $lines->count());
        }

        /*
         | Each SIDE is apportioned to the total, separately.
         |
         | The first version multiplied every share by the total and then put
         | the whole residual on the largest-magnitude line, which is the -1
         | credit. The credit was already exact, so absorbing the residual
         | moved it: a 100.00 accrual came out as three debits of 33.3333 and
         | a credit of -99.9999. The entry balanced and the TOTAL was not what
         | anybody asked for, which is the kind of wrong that reconciles.
         |
         | Apportioning each side to the total instead gives 33.3334 /
         | 33.3333 / 33.3333 against -100.0000: the debits sum to the total,
         | the credits sum to the total, and the entry balances because both
         | are the same figure. `Money::allocate()` already does this,
         | residual rule and all, and has its own worked examples.
         */
        $debits = [];
        $credits = [];

        foreach ($lines as $index => $line) {
            BigDecimal::of((string) $line->share)->isNegative()
                ? $credits[$index] = BigDecimal::of((string) $line->share)->abs()
                : $debits[$index] = BigDecimal::of((string) $line->share);
        }

        if ($debits === [] || $credits === []) {
            throw TemplateRefused::sharesDoNotCancel($key, 'all of one sign');
        }

        $policy = RoundingPolicy::halfUp(Scale::Amount);
        $magnitude = $total->isNegative() ? $total->negated() : $total;

        $allocated = [];

        foreach ([$debits, $credits] as $sideIndex => $side) {
            $shares = $magnitude->allocate(array_values($side), $policy);

            foreach (array_keys($side) as $position => $index) {
                $allocated[$index] = $sideIndex === 1
                    ? $shares[$position]->negated()
                    : $shares[$position];
            }
        }

        ksort($allocated);

        $drafts = [];

        foreach ($lines as $index => $line) {
            $drafts[] = [
                'account_code' => (string) $line->code,
                'amount' => (string) $allocated[$index]->amount,
                'currency' => $total->currency,
                'narration' => $line->narration ?? ($template->narration ?? null),
                'dimensions' => array_filter([
                    1 => $line->dimension_1, 2 => $line->dimension_2, 3 => $line->dimension_3,
                    4 => $line->dimension_4, 5 => $line->dimension_5, 6 => $line->dimension_6,
                    7 => $line->dimension_7, 8 => $line->dimension_8,
                ]),
            ];
        }

        return array_map(static fn (array $row): DraftLine => DraftLine::fromArray($row), $drafts);
    }

    /**
     * The recurring templates due on or before a date.
     *
     * @return list<array{key: string, name_en: string, name_ar: string, next_run_on: string, recurrence: string}>
     */
    public function due(string $entityId, Carbon $asAt): array
    {
        return DB::connection('tenant')->table('journal_templates')
            ->where('entity_id', $entityId)
            ->where('active', true)
            ->whereNotNull('recurrence')
            ->whereDate('next_run_on', '<=', $asAt->toDateString())
            ->where(function ($query) use ($asAt): void {
                $query->whereNull('recurs_until')
                    ->orWhereDate('recurs_until', '>=', $asAt->toDateString());
            })
            ->orderBy('next_run_on')
            ->get(['key', 'name_en', 'name_ar', 'next_run_on', 'recurrence'])
            ->map(static fn (object $row): array => [
                'key' => (string) $row->key,
                'name_en' => (string) $row->name_en,
                'name_ar' => (string) $row->name_ar,
                'next_run_on' => (string) $row->next_run_on,
                'recurrence' => (string) $row->recurrence,
            ])
            ->all();
    }

    /**
     * Move a recurring template's next run forward.
     *
     * Called after a draft has been prepared, by whatever prepared it. The
     * date advances whether or not anybody posts the draft - because the
     * alternative is a template that prepares the same month again next
     * month, and two drafts for one accrual is worse than none.
     */
    public function advance(string $entityId, string $key): ?string
    {
        $template = DB::connection('tenant')->table('journal_templates')
            ->where('entity_id', $entityId)
            ->where('key', $key)
            ->first(['id', 'recurrence', 'next_run_on']);

        if ($template === null || $template->recurrence === null) {
            return null;
        }

        $next = Carbon::parse((string) $template->next_run_on);

        $next = match ((string) $template->recurrence) {
            'monthly' => $next->addMonthNoOverflow(),
            'quarterly' => $next->addMonthsNoOverflow(3),
            default => $next->addYear(),
        };

        DB::connection('tenant')->table('journal_templates')
            ->where('id', $template->id)
            ->update([
                'next_run_on' => $next->toDateString(),
                'last_prepared_at' => now(),
                'updated_at' => now(),
            ]);

        return $next->toDateString();
    }
}
