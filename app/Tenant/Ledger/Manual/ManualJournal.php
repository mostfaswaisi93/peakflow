<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Manual;

use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;

/**
 * Validating a manual journal entry as it is typed - PF-055.
 *
 * ## Why validation is a service and not a form request
 *
 * Because it runs on every keystroke, not on submit. The running imbalance is
 * the whole value of the screen: it tells somebody they are out by 90 while
 * they can still see the line they transposed, rather than after they have
 * moved on.
 *
 * A form request answers "may this be submitted". This answers "what is the
 * state of the entry right now", which includes an entry that is perfectly
 * fine so far and not yet balanced.
 *
 * ## Every problem names its line and its field
 *
 * A screen that says "the entry is invalid" makes somebody read every field.
 * The field is what lets the message land next to the input, and the line
 * number is what lets it land in the right row.
 *
 * ## A control account is REFUSED and EXPLAINED
 *
 * Not hidden from the picker. Hiding it is what gets built by default - it
 * makes the screen simpler - and it leaves somebody hunting for an account
 * they can see in the chart and cannot select, with nothing saying why.
 *
 * The explanation is the point: the subledger IS the receivable. A manual
 * posting into it makes the two disagree, and afterwards nothing can say
 * which is right.
 */
final class ManualJournal
{
    /**
     * Read the draft and say what is wrong with it.
     *
     * @param  list<DraftLine>  $lines
     * @param  array<string, Rate>  $rates  "FROM/TO" => rate, for foreign lines
     */
    public function validate(Entity $entity, array $lines, array $rates = []): DraftVerdict
    {
        $functional = (string) $entity->functional_currency;
        $policy = RoundingPolicy::halfUp(Scale::Amount);

        $problems = [];
        $posting = [];
        $built = [];
        $debits = Money::zero($functional);
        $credits = Money::zero($functional);
        $blank = 0;

        $accounts = $this->accountsFor($entity, $lines);
        $enabled = $this->enabledCurrencies($entity);

        foreach ($lines as $index => $line) {
            $number = $index + 1;

            if ($line->isBlank()) {
                $blank++;

                continue;
            }

            $account = $line->accountCode === null ? null : ($accounts[$line->accountCode] ?? null);

            if ($line->accountCode === null) {
                $problems[] = LineProblem::of(
                    $number,
                    'account_code',
                    'account_missing',
                    'هذا السطر يحمل مبلغًا دون حساب.',
                    'This line has an amount and no account.',
                );
            } elseif ($account === null) {
                $problems[] = LineProblem::of(
                    $number,
                    'account_code',
                    'account_unknown',
                    sprintf('لا يوجد حساب بالرمز %s في هذا الكيان.', $line->accountCode),
                    sprintf('No account in this entity has the code %s.', $line->accountCode),
                );
            } else {
                $problems = [...$problems, ...$this->checkAccount($number, $account)];
            }

            $amount = $this->readAmount($number, $line, $problems);

            if ($amount === null) {
                continue;
            }

            $currency = $line->currency ?? $functional;

            if (! in_array($currency, $enabled, true)) {
                $problems[] = LineProblem::of(
                    $number,
                    'currency',
                    'currency_not_enabled',
                    sprintf(
                        'الكيان لا يتعامل بعملة %s. فعّلها أولًا بدل تحويلها بسعر لم يختره أحد.',
                        $currency,
                    ),
                    sprintf(
                        'This entity does not transact in %s. Enable it first rather than converting at '.
                        'a rate nobody chose.',
                        $currency,
                    ),
                );

                continue;
            }

            if ($account === null || $this->isUnusable($account)) {
                continue;
            }

            /*
             | A foreign line needs its rate, and the rate is not invented.
             |
             | Reporting the missing rate as a problem on the line is what
             | lets the screen ask for it in place, rather than refusing the
             | whole entry with a message about a currency the person can
             | see they typed.
             */
            if ($currency !== $functional) {
                $rate = $rates[$currency.'/'.$functional] ?? null;

                if (! $rate instanceof Rate) {
                    $problems[] = LineProblem::of(
                        $number,
                        'currency',
                        'rate_missing',
                        sprintf('لا يوجد سعر صرف من %s إلى %s لهذا التاريخ.', $currency, $functional),
                        sprintf('There is no %s to %s rate for this date.', $currency, $functional),
                    );

                    continue;
                }

                $line_ = PostingLine::translated(
                    accountId: (string) $account->id,
                    original: Money::of((string) $amount, $currency),
                    rate: $rate,
                    policy: $policy,
                    narration: $line->narration,
                    dimensions: $line->dimensions,
                );
            } else {
                $line_ = PostingLine::toAccount(
                    accountId: (string) $account->id,
                    amount: Money::of((string) $amount, $functional),
                    narration: $line->narration,
                    dimensions: $line->dimensions,
                );
            }

            $built[] = $line_;

            $posting[] = [
                'line' => (string) $number,
                'account_id' => (string) $account->id,
                'account_code' => (string) $account->code,
                'account_name_en' => (string) $account->name_en,
                'account_name_ar' => (string) $account->name_ar,
                'amount' => (string) $line_->amount->amount,
                'currency' => $currency,
                'original' => (string) $amount,
            ];

            $line_->amount->isPositive()
                ? $debits = $debits->plus($line_->amount)
                : $credits = $credits->plus($line_->amount->negated());
        }

        $imbalance = $debits->minus($credits);

        /*
         | Postable needs all three, and the order of the tests is the order
         | of the messages somebody sees.
         |
         | A problem is a problem with a line; an imbalance is a problem with
         | the entry; one line is a problem with neither and is still not an
         | entry.
         */
        $postable = $problems === []
            && $imbalance->isZero()
            && count($posting) >= 2;

        return new DraftVerdict(
            postable: $postable,
            problems: $problems,
            lines: $posting,
            debits: $debits,
            credits: $credits,
            imbalance: $imbalance,
            blankLines: $blank,
            postingLines: $built,
        );
    }

    /**
     * The problems with the account a line names.
     *
     * @return list<LineProblem>
     */
    private function checkAccount(int $number, object $account): array
    {
        $problems = [];

        if ($account->postable !== true) {
            $problems[] = LineProblem::of(
                $number,
                'account_code',
                'account_is_a_heading',
                sprintf(
                    'الحساب %s عنوان مجموعة لا حساب ترحيل. الرصيد عليه لا يفسّره أيٌّ من فروعه وتفقده كل '.
                    'عملية تنقيب.',
                    (string) $account->code,
                ),
                sprintf(
                    'Account %s is a group heading, not a posting account. A balance on it is explained '.
                    'by none of its children and lost by every drill-down.',
                    (string) $account->code,
                ),
            );
        }

        if (($account->blocked ?? false) === true) {
            $problems[] = LineProblem::of(
                $number,
                'account_code',
                'account_blocked',
                sprintf('الحساب %s مُغلق لقيود جديدة ومحفوظ للتاريخ فقط.', (string) $account->code),
                sprintf(
                    'Account %s is closed to new postings and kept for history only.',
                    (string) $account->code,
                ),
            );
        }

        /*
         | The control account, refused with the reason - PF-055, PF-038.
         |
         | The message says what the account IS and what would break, not
         | that it is "not allowed". Somebody who knows the receivable is at
         | 1.1.2.1 and is told they may not use it will either find a way
         | round or conclude the software is wrong.
         */
        if (($account->control_role ?? null) !== null) {
            $problems[] = LineProblem::of(
                $number,
                'account_code',
                'account_is_a_control',
                sprintf(
                    'الحساب %s حساب مراقبة (%s): رصيده هو مجموع دفتر فرعي، ويُرحَّل إليه من المستندات '.
                    'نفسها. قيد يدوي عليه يجعل الدفترَين يختلفان ولا شيء بعدها يقول أيّهما الصحيح. '.
                    'رحِّل إلى الحساب المقابل، أو عدِّل المستند الذي أنشأ الرصيد.',
                    (string) $account->code,
                    (string) $account->control_role,
                ),
                sprintf(
                    'Account %s is a control account (%s): its balance is the total of a subledger, and '.
                    'the documents post to it. A manual entry makes the two disagree and nothing '.
                    'afterwards can say which is right. Post to the counter account, or correct the '.
                    'document that created the balance.',
                    (string) $account->code,
                    (string) $account->control_role,
                ),
            );
        }

        return $problems;
    }

    /**
     * Read the amount, or say why it cannot be read.
     *
     * @param  list<LineProblem>  $problems
     */
    private function readAmount(int $number, DraftLine $line, array &$problems): ?BigDecimal
    {
        if ($line->amount === null) {
            $problems[] = LineProblem::of(
                $number,
                'amount',
                'amount_missing',
                'هذا السطر يحمل حسابًا دون مبلغ.',
                'This line has an account and no amount.',
            );

            return null;
        }

        /*
         | Thousands separators and Arabic-Indic digits are normalised, and
         | nothing else is.
         |
         | Somebody typing 12٬500 or ١٢٥٠٠ has typed a number and the form
         | should take it. Somebody typing "about 12k" has not, and guessing
         | would be worse than refusing - a figure the software invented is
         | a figure nobody can trace.
         */
        $normalised = $this->normalise($line->amount);

        try {
            $amount = BigDecimal::of($normalised);
        } catch (MathException) {
            $problems[] = LineProblem::of(
                $number,
                'amount',
                'amount_unreadable',
                sprintf('"%s" ليس مبلغًا.', $line->amount),
                sprintf('"%s" is not an amount.', $line->amount),
            );

            return null;
        }

        if ($amount->isZero()) {
            $problems[] = LineProblem::of(
                $number,
                'amount',
                'amount_is_zero',
                'سطر بصفر لا يسجّل شيئًا، والقيد يرفضه.',
                'A line of zero records nothing, and the entry refuses it.',
            );

            return null;
        }

        if ($amount->getScale() > Scale::Amount->value) {
            $problems[] = LineProblem::of(
                $number,
                'amount',
                'amount_too_precise',
                sprintf(
                    'المبلغ %s يحمل %d منزلة عشرية، والحد أربع. التقريب هنا يجب أن يكون قرارًا لا أثرًا '.
                    'جانبيًّا.',
                    $line->amount,
                    $amount->getScale(),
                ),
                sprintf(
                    'The amount %s has %d decimal places and the limit is four. Rounding here has to be '.
                    'a decision rather than a side effect.',
                    $line->amount,
                    $amount->getScale(),
                ),
            );

            return null;
        }

        return $amount;
    }

    private function normalise(string $amount): string
    {
        $arabicIndic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $easternArabicIndic = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

        $amount = str_replace($arabicIndic, range(0, 9), $amount);
        $amount = str_replace($easternArabicIndic, range(0, 9), $amount);

        // The Arabic decimal separator and thousands separator, and the Latin
        // thousands separators people actually type.
        $amount = str_replace(['٫', '٬'], ['.', ''], $amount);
        $amount = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], '', $amount);

        return $amount;
    }

    private function isUnusable(object $account): bool
    {
        return $account->postable !== true
            || ($account->blocked ?? false) === true
            || ($account->control_role ?? null) !== null;
    }

    /**
     * @param  list<DraftLine>  $lines
     * @return array<string, object>
     */
    private function accountsFor(Entity $entity, array $lines): array
    {
        $codes = array_values(array_filter(array_map(
            static fn (DraftLine $line): ?string => $line->accountCode,
            $lines,
        )));

        if ($codes === []) {
            return [];
        }

        /*
         | The control role comes from `account_roles`, joined once.
         |
         | A per-line lookup here is the N+1 that is invisible in every
         | functional test: the figures are right and the screen takes a
         | second per keystroke on a chart of four hundred accounts.
         */
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT
                a.id,
                a.code,
                a.name_ar,
                a.name_en,
                a.postable,
                a.blocked,
                (SELECT r.role FROM account_roles r WHERE r.account_id = a.id LIMIT 1) AS control_role
            FROM accounts a
            WHERE a.entity_id = cast(? AS char(26))
              AND a.code = ANY(?)
        SQL, [(string) $entity->getKey(), '{'.implode(',', array_map(
            static fn (string $code): string => '"'.$code.'"',
            array_unique($codes),
        )).'}']);

        $byCode = [];

        foreach ($rows as $row) {
            $byCode[(string) $row->code] = $row;
        }

        return $byCode;
    }

    /** @return list<string> */
    private function enabledCurrencies(Entity $entity): array
    {
        $codes = DB::connection('tenant')->table('entity_currencies')
            ->where('entity_id', $entity->getKey())
            ->where('enabled', true)
            ->pluck('currency')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();

        // The functional currency is always transactable, mirrored by a
        // trigger - but a draft may be validated before anything has been
        // posted, so it is included here rather than relied upon.
        return array_values(array_unique([...$codes, (string) $entity->functional_currency]));
    }
}
