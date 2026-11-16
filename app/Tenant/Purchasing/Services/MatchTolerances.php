<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Purchasing\Exceptions\OrderRefused;
use App\Tenant\Purchasing\Models\SupplierMatchTolerance;

/**
 * Which tolerance applies - PF-135.
 *
 * ## Most specific wins, and the fallback permits nothing
 *
 * Supplier and line type beats supplier alone, which beats line type alone,
 * which beats the entity's default. Where nothing is configured the answer
 * is {@see SupplierMatchTolerance::strict()} - zero.
 *
 * Zero rather than a lenient default, because a tolerance nobody configured
 * is a tolerance nobody decided on, and the safe reading of "nobody decided"
 * is "ask a person". A permissive default would mean a business that never
 * opened the settings screen was silently accepting price differences, which
 * is the opposite of what a three-way match is for.
 *
 * ## The ceiling is the database's
 *
 * `set()` passes the figures to a table whose CHECK constraint refuses
 * anything above ten per cent. The constraint is there rather than here
 * because a limit enforced only in a service is a limit an import does not
 * have, and the error message below exists so the person setting it reads an
 * explanation rather than a constraint name.
 */
final class MatchTolerances
{
    /** The platform's ceiling, mirrored from the CHECK constraint for the message. */
    public const CEILING_PERCENT = '10';

    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * The tolerance for a supplier and line type.
     *
     * @throws OrderRefused never - the fallback is strict rather than an error
     */
    public function for(string $entityId, ?string $partyId, string $lineType): SupplierMatchTolerance
    {
        $candidates = SupplierMatchTolerance::query()
            ->where('entity_id', $entityId)
            ->where('active', true)
            ->where(static function ($query) use ($partyId): void {
                $query->whereNull('party_id');

                if ($partyId !== null) {
                    $query->orWhere('party_id', $partyId);
                }
            })
            ->where(static fn ($query) => $query->whereNull('line_type')->orWhere('line_type', $lineType))
            ->get();

        if ($candidates->isEmpty()) {
            return SupplierMatchTolerance::strict();
        }

        /*
         | Specificity, scored.
         |
         | A supplier-and-type row beats a supplier row beats a type row
         | beats the entity default. Ordering by a column could not express
         | this, and taking the first match would make the answer depend on
         | insertion order - which is how a tolerance somebody set for one
         | supplier silently applies to all of them.
         */
        $best = null;
        $bestScore = -1;

        foreach ($candidates as $candidate) {
            $score = ($candidate->party_id === null ? 0 : 2) + ($candidate->line_type === null ? 0 : 1);

            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best ?? SupplierMatchTolerance::strict();
    }

    /**
     * Set a tolerance.
     *
     * @throws OrderRefused
     */
    public function set(
        string $entityId,
        ?string $partyId,
        ?string $lineType,
        string $quantityPercent = '0',
        string $quantityAbsolute = '0',
        string $pricePercent = '0',
        string $priceAbsolute = '0',
        ?string $userId = null,
        ?string $reason = null,
    ): SupplierMatchTolerance {
        foreach (['quantity' => $quantityPercent, 'price' => $pricePercent] as $what => $percent) {
            if (\Brick\Math\BigDecimal::of($percent)->isGreaterThan(self::CEILING_PERCENT)) {
                throw OrderRefused::because(sprintf(
                    'A %s tolerance of %s%% is above the platform ceiling of %s%%. A tolerance that '.
                    'wide is the match turned off, and what a three-way match exists for is the invoice '.
                    'that is quietly twenty per cent too high. If a supplier genuinely needs more, that '.
                    'is a conversation rather than a setting.',
                    $what,
                    $percent,
                    self::CEILING_PERCENT,
                ));
            }
        }

        $tolerance = SupplierMatchTolerance::query()->updateOrCreate(
            ['entity_id' => $entityId, 'party_id' => $partyId, 'line_type' => $lineType],
            [
                'quantity_percent' => $quantityPercent,
                'quantity_absolute' => $quantityAbsolute,
                'price_percent' => $pricePercent,
                'price_absolute' => $priceAbsolute,
                'active' => true,
            ],
        );

        $this->audit->record(
            category: 'purchasing',
            action: 'match_tolerance.set',
            subjectType: 'party',
            subjectId: (string) ($partyId ?? $entityId),
            subjectLabel: $lineType ?? 'all line types',
            after: [
                'quantity_percent' => $quantityPercent,
                'quantity_absolute' => $quantityAbsolute,
                'price_percent' => $pricePercent,
                'price_absolute' => $priceAbsolute,
            ],
            reason: $reason,
            entityId: $entityId,
            partyId: $partyId,
        );

        return $tolerance;
    }
}
