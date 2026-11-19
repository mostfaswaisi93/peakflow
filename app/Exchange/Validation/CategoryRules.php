<?php

declare(strict_types=1);

namespace App\Exchange\Validation;

use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticNode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The per-category EN 16931 rules - PF-116.
 *
 * The `BR-S-*`, `BR-Z-*`, `BR-E-*`, `BR-AE-*`, `BR-IC-*`, `BR-G-*`,
 * `BR-O-*`, `BR-IG-*` and `BR-IP-*` families: one set of ten rules per VAT
 * category, of which the rate rules (`-05`, `-06`, `-07`) and the
 * calculation rules (`-08`, `-09`) are implemented here.
 *
 * ## Why these waited, and what changed
 *
 * They were deliberately absent until now, and the reason was not effort.
 * There are nine families and their numbering is not uniform - `BR-O-05`
 * forbids a rate where `BR-S-05` requires a positive one, and `BR-IC-*`
 * is the family for category code `K`, not `IC` - so a rule implemented
 * from a guess at its number fires with an identifier the recipient's
 * validator does not use, and whoever looks it up finds a different rule.
 *
 * What changed is that the **official abstract rule set is vendored**:
 * `schematron/en16931/EN16931-model.sch`, published by CEN/TC 434 through
 * the Commission's CEF eInvoicing repository. Every identifier below and
 * every condition below is read from it, and
 * `EInvoicingCoverageRules` fails the build if an identifier here is
 * not in that file.
 *
 * ## The TOLERANCE is read from the compiled binding, not from the prose
 *
 * This is the part that cannot be got from the `.sch` alone, and it is the
 * part that decides whether a rule is the standard's or ours.
 *
 * `BR-S-08` says the taxable amount "shall equal" a sum. The compiled
 * binding (`ubl/xslt/EN16931-UBL-validation.xslt`, release 1.3.17) evaluates
 * it as
 *
 * ```
 * TaxableAmount - 1 < sum(...)  and  TaxableAmount + 1 > sum(...)
 * ```
 *
 * which is a window of **one whole currency unit, exclusive** - and the same
 * window applies to `BR-S-09`, `BR-IG-09` and `BR-IP-09`. Implementing
 * "shall equal" as equality would have made PeakFlow stricter than every
 * access point that runs the official artefact: a document the recipient
 * accepts would be refused here, under the recipient's own rule identifier,
 * which is the one outcome that makes a rule worse than absent.
 *
 * The zero-rated families are different in the artefact too, and the
 * difference is kept: `BR-Z-08` and its siblings are evaluated with plain
 * equality, and `BR-Z-09`'s test is literally `TaxAmount = 0`. There is
 * nothing to round when nothing is multiplied.
 *
 * > `BR-CO-17` in {@see CalculationRules} uses a window of `0.02`. The
 * > official binding for it is the same `± 1` as `BR-S-09`. They are left
 * > disagreeing rather than quietly reconciled, because changing a tax
 * > tolerance is a decision with a reviewer's name on it - see the PF-116
 * > note in the report that introduced this file.
 *
 * ## What is not implemented, and why
 *
 * - `-01` to `-04` (a category on a line obliges the document to carry a
 *   seller VAT identifier, and to carry a breakdown group for that
 *   category). `BR-CO-26` and the cardinality walk cover the identifier;
 *   the breakdown-group obligation needs the inverse walk and is honest
 *   work rather than a line here.
 * - `-10` for `S`, `Z`, `IG`, `IP`, which forbid an exemption reason where
 *   the others require one. {@see ConditionalRules} implements the
 *   requiring half for `E`, `AE`, `K`, `G` and `O`.
 * - `BR-IC-11`, `BR-IC-12`, `BR-O-11` to `BR-O-14`.
 *
 * Each of those is listed in `EInvoicingCoverageRules` as a rule the
 * vendored set carries and this code does not, with the reason - so the gap
 * is a line in a report rather than a surprise.
 *
 * ## Severity
 *
 * `internal`, like every other calculation rule. The document is built from
 * stored figures the ledger has already posted: if the breakdown disagrees
 * with the lines, the document and the journal entry disagree, and that is
 * ours to fix rather than a message for a customer.
 */
final class CategoryRules
{
    /**
     * EN 16931's stated window on the per-rate families, per the compiled
     * binding. One whole currency unit, exclusive.
     */
    private const WINDOW = '1';

    /**
     * The rule family for each BT-118 code.
     *
     * `rate` is what `-05`, `-06` and `-07` require of BT-152, BT-96 and
     * BT-103 respectively. `grouped` says whether `-08` sums per distinct
     * rate (the families that multiply) or per category (the families whose
     * tax is zero).
     *
     * @var array<string, array{
     *     line: string, allowance: string, charge: string,
     *     taxable: string, tax: string,
     *     rate: 'positive'|'zero'|'absent'|'zero_or_positive',
     *     grouped: bool, name: string,
     * }>
     */
    private const FAMILIES = [
        'S' => [
            'line' => 'BR-S-05', 'allowance' => 'BR-S-06', 'charge' => 'BR-S-07',
            'taxable' => 'BR-S-08', 'tax' => 'BR-S-09',
            'rate' => 'positive', 'grouped' => true, 'name' => 'Standard rated',
        ],
        'Z' => [
            'line' => 'BR-Z-05', 'allowance' => 'BR-Z-06', 'charge' => 'BR-Z-07',
            'taxable' => 'BR-Z-08', 'tax' => 'BR-Z-09',
            'rate' => 'zero', 'grouped' => false, 'name' => 'Zero rated',
        ],
        'E' => [
            'line' => 'BR-E-05', 'allowance' => 'BR-E-06', 'charge' => 'BR-E-07',
            'taxable' => 'BR-E-08', 'tax' => 'BR-E-09',
            'rate' => 'zero', 'grouped' => false, 'name' => 'Exempt from VAT',
        ],
        'AE' => [
            'line' => 'BR-AE-05', 'allowance' => 'BR-AE-06', 'charge' => 'BR-AE-07',
            'taxable' => 'BR-AE-08', 'tax' => 'BR-AE-09',
            'rate' => 'zero', 'grouped' => false, 'name' => 'Reverse charge',
        ],
        'K' => [
            'line' => 'BR-IC-05', 'allowance' => 'BR-IC-06', 'charge' => 'BR-IC-07',
            'taxable' => 'BR-IC-08', 'tax' => 'BR-IC-09',
            'rate' => 'zero', 'grouped' => false, 'name' => 'Intra-community supply',
        ],
        'G' => [
            'line' => 'BR-G-05', 'allowance' => 'BR-G-06', 'charge' => 'BR-G-07',
            'taxable' => 'BR-G-08', 'tax' => 'BR-G-09',
            'rate' => 'zero', 'grouped' => false, 'name' => 'Export outside the EU',
        ],
        'O' => [
            'line' => 'BR-O-05', 'allowance' => 'BR-O-06', 'charge' => 'BR-O-07',
            'taxable' => 'BR-O-08', 'tax' => 'BR-O-09',
            'rate' => 'absent', 'grouped' => false, 'name' => 'Not subject to VAT',
        ],
        'L' => [
            'line' => 'BR-IG-05', 'allowance' => 'BR-IG-06', 'charge' => 'BR-IG-07',
            'taxable' => 'BR-IG-08', 'tax' => 'BR-IG-09',
            'rate' => 'zero_or_positive', 'grouped' => true, 'name' => 'IGIC',
        ],
        'M' => [
            'line' => 'BR-IP-05', 'allowance' => 'BR-IP-06', 'charge' => 'BR-IP-07',
            'taxable' => 'BR-IP-08', 'tax' => 'BR-IP-09',
            'rate' => 'zero_or_positive', 'grouped' => true, 'name' => 'IPSI',
        ],
    ];

    /**
     * The BT-118 codes this class has a rule family for.
     *
     * @return list<string>
     */
    public static function categories(): array
    {
        return array_keys(self::FAMILIES);
    }

    /**
     * Every rule identifier this class can emit.
     *
     * Read by the coverage check, which compares it against the vendored
     * rule set. Derived from the table rather than listed again, because two
     * lists of the same rules is how one of them goes stale.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (self::FAMILIES as $family) {
            foreach (['line', 'allowance', 'charge', 'taxable', 'tax'] as $key) {
                $rules[] = $family[$key];
            }
        }

        sort($rules);

        return $rules;
    }

    /** @return list<SemanticViolation> */
    public function check(SemanticNode $root): array
    {
        return [
            ...$this->rateRules($root),
            ...$this->breakdownRules($root),
        ];
    }

    /**
     * `-05`, `-06`, `-07`: the rate a category permits.
     *
     * Three rules per family and the only difference between them is where
     * the pair was found - on a line, on a document-level allowance or on a
     * document-level charge - so they are one walk with three identifiers.
     *
     * @return list<SemanticViolation>
     */
    private function rateRules(SemanticNode $root): array
    {
        $violations = [];

        foreach ($root->children(BG::InvoiceLine) as $index => $line) {
            $vat = $line->first(BG::LineVatInformation);

            if ($vat === null) {
                continue;
            }

            $violations = [
                ...$violations,
                ...$this->rate(
                    'line',
                    $vat->get(BT::LineVatCategoryCode),
                    $vat->has(BT::LineVatRate) ? $vat->get(BT::LineVatRate) : null,
                    BT::LineVatRate,
                    BG::InvoiceLine,
                    sprintf('document / BG-25[%d] / BG-30', $index + 1),
                ),
            ];
        }

        $adjustments = [
            ['allowance', BG::DocumentLevelAllowances, BT::DocumentAllowanceVatCategoryCode, BT::DocumentAllowanceVatRate],
            ['charge', BG::DocumentLevelCharges, BT::DocumentChargeVatCategoryCode, BT::DocumentChargeVatRate],
        ];

        foreach ($adjustments as [$key, $group, $category, $rate]) {
            foreach ($root->children($group) as $index => $node) {
                $violations = [
                    ...$violations,
                    ...$this->rate(
                        $key,
                        $node->get($category),
                        $node->has($rate) ? $node->get($rate) : null,
                        $rate,
                        $group,
                        sprintf('document / %s[%d]', $group->value, $index + 1),
                    ),
                ];
            }
        }

        return $violations;
    }

    /**
     * One category-and-rate pair, against its family's rate rule.
     *
     * @param  'line'|'allowance'|'charge'  $key
     * @return list<SemanticViolation>
     */
    private function rate(string $key, ?string $code, ?string $rate, BT $term, BG $group, string $path): array
    {
        $family = self::FAMILIES[$this->normalize($code)] ?? null;

        if ($family === null) {
            // An unknown category code is BR-CL-17's business, and this
            // walk saying something about it would be a second opinion
            // about a value neither of us recognises.
            return [];
        }

        $violation = static fn (string $detail): SemanticViolation => SemanticViolation::internal(
            $family[$key],
            term: $term,
            value: $rate,
            detail: $detail,
        )->withPath($path);

        if ($family['rate'] === 'absent') {
            return $rate === null ? [] : [$violation(sprintf(
                '%s states a VAT rate of %s. "%s" is not a rate of zero - it is the absence of a rate, '.
                'and a document that states 0%% says the supply was taxed at nothing rather than that it '.
                'was outside the tax altogether.',
                $term->value,
                $rate,
                $family['name'],
            ))];
        }

        if ($rate === null) {
            // BR-48 is the rule about a breakdown with no rate, and BR-S-05
            // is about the rate a line DOES carry.
            return [];
        }

        $value = $this->decimal($rate);

        return match ($family['rate']) {
            'positive' => $value->isGreaterThan(0) ? [] : [$violation(sprintf(
                '%s is %s and "%s" needs a rate above zero. A standard-rated line at 0%% is a '.
                'zero-rated line under a category that says tax was charged.',
                $term->value,
                $rate,
                $family['name'],
            ))],
            'zero' => $value->isEqualTo(0) ? [] : [$violation(sprintf(
                '%s is %s and "%s" carries no VAT, so its rate is 0. A non-zero rate under this '.
                'category gives the recipient a tax amount to deduct that nobody has declared.',
                $term->value,
                $rate,
                $family['name'],
            ))],
            'zero_or_positive' => $value->isGreaterThanOrEqualTo(0) ? [] : [$violation(sprintf(
                '%s is %s, and a negative rate is not a rate.',
                $term->value,
                $rate,
            ))],
            default => [],
        };
    }

    /**
     * `-08` and `-09`: the breakdown against the lines it is a summary of.
     *
     * @return list<SemanticViolation>
     */
    private function breakdownRules(SemanticNode $root): array
    {
        $violations = [];

        foreach ($root->children(BG::VatBreakdown) as $index => $group) {
            $code = $this->normalize($group->get(BT::VatCategoryCode));
            $family = self::FAMILIES[$code] ?? null;

            if ($family === null) {
                continue;
            }

            $path = sprintf('document / BG-23[%d]', $index + 1);
            $rate = $family['grouped'] && $group->has(BT::VatCategoryRate)
                ? $this->decimal($group->get(BT::VatCategoryRate))
                : null;

            $violations = [
                ...$violations,
                ...$this->breakdownStatesARate($group, $family, $path),
                ...$this->taxableAmount($root, $group, $family, $code, $rate, $path),
                ...$this->taxAmount($group, $family, $path),
            ];
        }

        return $violations;
    }

    /**
     * `BR-48`: a breakdown states a rate, unless the supply is outside VAT.
     *
     * > [BR-48]-Each VAT breakdown (BG-23) shall have a VAT category rate
     * > (BT-119), except if the Invoice is not subject to VAT.
     *
     * ## It was named by two rule sets and implemented by neither
     *
     * `CalculationRules::vatPerGroup()` skipped a group with no rate -
     * "nothing to recompute, and BR-48 is the rule about a missing rate" -
     * and `CategoryRules::taxAmount()` skipped one too - "BR-48 again:
     * there is nothing to multiply by". Both were right to skip, and both
     * pointed at a rule that did not exist, so a VAT breakdown with no rate
     * was refused by nothing at all. The backward completeness check
     * against the vendored rule set is what found it.
     *
     * The gap it leaves is not cosmetic. BT-119 is what the recipient
     * multiplies the taxable amount by to re-derive the tax, and
     * `BR-CO-17` is the rule that compares the two - which also skips a
     * group with no rate. So a breakdown stating a taxable amount and a tax
     * amount and no rate had **both** of its arithmetic checks stand down,
     * and the one figure that ties them together was the missing one.
     *
     * ## The exception is the `O` family and nothing else
     *
     * "Not subject to VAT" is the standard's own clause, and this class
     * already carries it: the families table declares `rate => 'absent'`
     * for `O` ("Not subject to VAT") and a rate for every other family.
     * So the exception is read from the same declaration that the `-05`
     * rules read, rather than written again here as a literal `'O'` - two
     * spellings of one exception is how they come apart.
     *
     * Reported under `BR-48` rather than a family identifier, because this
     * is the baseline rule and the recipient's validator will quote that
     * number back.
     *
     * @param  array<string, mixed>  $family
     * @return list<SemanticViolation>
     */
    private function breakdownStatesARate(SemanticNode $group, array $family, string $path): array
    {
        if ($family['rate'] === 'absent') {
            return [];
        }

        if ($group->has(BT::VatCategoryRate)) {
            return [];
        }

        return [SemanticViolation::internal(
            'BR-48',
            term: BT::VatCategoryRate,
            value: null,
            detail: sprintf(
                'The "%s" breakdown states no VAT rate. Only a supply outside VAT altogether may omit '.
                'it, and this category is not one: the rate is what the recipient multiplies the '.
                'taxable amount by to re-derive the tax, so without it BR-CO-17 and this category\'s '.
                'own tax rule both stand down and the summary is arithmetic nobody can check.',
                (string) $family['name'],
            ),
        )->withPath($path)];
    }

    /**
     * `-08`: BT-116 = Σ BT-131 + Σ BT-99 − Σ BT-92, for this category.
     *
     * For the families that multiply (`S`, `IG`, `IP`) the sum is taken over
     * the contributions whose rate equals BT-119 as well, because one
     * category with two rates is two breakdown groups.
     *
     * @param  array<string, mixed>  $family
     * @return list<SemanticViolation>
     */
    private function taxableAmount(
        SemanticNode $root,
        SemanticNode $group,
        array $family,
        string $code,
        ?BigDecimal $rate,
        string $path,
    ): array {
        if (! $group->has(BT::VatCategoryTaxableAmount)) {
            // Absent is the cardinality walk's answer (BR-45), and
            // arithmetic over an absent figure says less than it does.
            return [];
        }

        $sum = BigDecimal::zero();

        foreach ($root->children(BG::InvoiceLine) as $line) {
            $vat = $line->first(BG::LineVatInformation);

            if ($vat === null || $this->normalize($vat->get(BT::LineVatCategoryCode)) !== $code) {
                continue;
            }

            if ($rate !== null && ! $this->matches($rate, $vat->get(BT::LineVatRate))) {
                continue;
            }

            $sum = $sum->plus($this->decimal($line->get(BT::LineNetAmount)));
        }

        $adjustments = [
            [BG::DocumentLevelCharges, BT::DocumentChargeVatCategoryCode, BT::DocumentChargeVatRate, BT::DocumentChargeAmount, 1],
            [BG::DocumentLevelAllowances, BT::DocumentAllowanceVatCategoryCode, BT::DocumentAllowanceVatRate, BT::DocumentAllowanceAmount, -1],
        ];

        foreach ($adjustments as [$bg, $categoryTerm, $rateTerm, $amountTerm, $sign]) {
            foreach ($root->children($bg) as $node) {
                if ($this->normalize($node->get($categoryTerm)) !== $code) {
                    continue;
                }

                if ($rate !== null && ! $this->matches($rate, $node->get($rateTerm))) {
                    continue;
                }

                $amount = $this->decimal($node->get($amountTerm));
                $sum = $sign === 1 ? $sum->plus($amount) : $sum->minus($amount);
            }
        }

        $stated = $this->decimal($group->get(BT::VatCategoryTaxableAmount))->toScale(2, RoundingMode::HalfUp);
        $expected = $sum->toScale(2, RoundingMode::HalfUp);

        if ($this->within($family['grouped'] === true, $stated, $expected)) {
            return [];
        }

        return [SemanticViolation::internal(
            (string) $family['taxable'],
            term: BT::VatCategoryTaxableAmount,
            value: (string) $group->get(BT::VatCategoryTaxableAmount),
            detail: sprintf(
                'The "%s"%s breakdown states a taxable amount of %s and the lines, charges and '.
                'allowances that feed it come to %s. The summary and the document it summarises '.
                'disagree, so the recipient computes a different tax base from the same document.',
                (string) $family['name'],
                $rate === null ? '' : sprintf(' %s%% ', (string) $rate),
                (string) $stated,
                (string) $expected,
            ),
        )->withPath($path)];
    }

    /**
     * `-09`: the category's tax amount.
     *
     * `S`, `IG` and `IP` multiply; every other family's tax is zero, and the
     * official binding for those is literally `TaxAmount = 0`.
     *
     * @param  array<string, mixed>  $family
     * @return list<SemanticViolation>
     */
    private function taxAmount(SemanticNode $group, array $family, string $path): array
    {
        if (! $group->has(BT::VatCategoryTaxAmount)) {
            return [];
        }

        $stated = $this->decimal($group->get(BT::VatCategoryTaxAmount))->toScale(2, RoundingMode::HalfUp);

        if ($family['grouped'] !== true) {
            if ($stated->isEqualTo(0)) {
                return [];
            }

            return [SemanticViolation::internal(
                (string) $family['tax'],
                term: BT::VatCategoryTaxAmount,
                value: (string) $group->get(BT::VatCategoryTaxAmount),
                detail: sprintf(
                    'The "%s" breakdown states %s of VAT. This category charges none, so the amount '.
                    'is either tax the return does not account for or a category that is wrong.',
                    (string) $family['name'],
                    (string) $stated,
                ),
            )->withPath($path)];
        }

        if (! $group->has(BT::VatCategoryRate)) {
            // BR-48 again: there is nothing to multiply by.
            return [];
        }

        $expected = $this->decimal($group->get(BT::VatCategoryTaxableAmount))
            ->abs()
            ->multipliedBy($this->decimal($group->get(BT::VatCategoryRate)))
            ->dividedBy('100', 4, RoundingMode::HalfUp)
            ->toScale(2, RoundingMode::HalfUp);

        // `abs()` on both sides, as the official binding has it: a credit
        // note states the same figures with the opposite sign and is not a
        // different rule.
        if ($this->within(true, $stated->abs(), $expected)) {
            return [];
        }

        return [SemanticViolation::internal(
            (string) $family['tax'],
            term: BT::VatCategoryTaxAmount,
            value: (string) $group->get(BT::VatCategoryTaxAmount),
            detail: sprintf(
                '"%s" at %s%% on %s is %s and the breakdown states %s.',
                (string) $family['name'],
                (string) $group->get(BT::VatCategoryRate),
                (string) $group->get(BT::VatCategoryTaxableAmount),
                (string) $expected,
                (string) $stated,
            ),
        )->withPath($path)];
    }

    /**
     * The comparison the official binding uses for this family.
     *
     * A window of one currency unit for the families that multiply, plain
     * equality for the families that do not.
     */
    private function within(bool $grouped, BigDecimal $stated, BigDecimal $expected): bool
    {
        if (! $grouped) {
            return $stated->isEqualTo($expected);
        }

        return $stated->minus($expected)->abs()->isLessThan(self::WINDOW);
    }

    private function matches(BigDecimal $rate, ?string $other): bool
    {
        if ($other === null || trim($other) === '') {
            return false;
        }

        return $rate->isEqualTo($this->decimal($other));
    }

    private function normalize(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    private function decimal(?string $value): BigDecimal
    {
        if ($value === null || trim($value) === '') {
            return BigDecimal::zero();
        }

        try {
            return BigDecimal::of(trim($value));
        } catch (\Throwable) {
            return BigDecimal::zero();
        }
    }
}
