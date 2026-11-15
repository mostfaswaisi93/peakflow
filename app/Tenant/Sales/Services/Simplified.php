<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Money\Money;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Exceptions\SimplifiedRefused;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\SimplifiedForm;
use Illuminate\Support\Facades\DB;

/**
 * The simplified form, and the threshold that ends it - PF-101.
 *
 * ## Exceeding the threshold upgrades the document
 *
 * It does not make a simplified invoice slightly wrong. The regimes that set
 * a limit set it as a condition of using the form at all, so a document above
 * it has to be issued as a full invoice - with the customer's registration
 * and address, which is exactly what somebody chose the simplified form to
 * avoid typing.
 *
 * So `mark()` refuses and names the figure. The alternative is a document
 * already filed under a form that does not apply to it, discovered when the
 * authority rejects a batch.
 */
final class Simplified
{
    public function formFor(string $profile): SimplifiedForm
    {
        /** @var array<string, array<string, mixed>> $map */
        $map = (array) config('jurisdictions.simplified', []);

        if (! \array_key_exists($profile, $map)) {
            throw SimplifiedRefused::anUndeclaredProfile($profile);
        }

        $declared = $map[$profile];
        $threshold = $declared['threshold'] ?? null;
        $currency = $declared['currency'] ?? null;

        return new SimplifiedForm(
            permitted: (bool) ($declared['permitted'] ?? false),
            threshold: $threshold === null || $currency === null
                ? null
                : Money::of((string) $threshold, (string) $currency),
            omits: array_values(array_map('strval', (array) ($declared['omits'] ?? []))),
        );
    }

    /**
     * Mark a draft as simplified, or refuse and say why.
     *
     * @throws SimplifiedRefused
     */
    public function mark(Invoice $draft, ?string $profile = null): Invoice
    {
        if (! $draft->isDraft()) {
            throw SimplifiedRefused::anIssuedDocument((string) $draft->number);
        }

        if ($draft->document_type !== DocumentType::Invoice
            && $draft->document_type !== DocumentType::CreditNote) {
            throw SimplifiedRefused::theWrongType($draft->document_type->label('en'));
        }

        $profile ??= (string) ($draft->profile ?? config('jurisdictions.default_profile', 'generic'));
        $form = $this->formFor($profile);

        if (! $form->permitted) {
            throw SimplifiedRefused::notPermitted($profile);
        }

        $gross = Money::of((string) $draft->gross_amount, (string) $draft->currency);

        if (! $form->admits($gross)) {
            throw SimplifiedRefused::aboveTheThreshold(
                $profile,
                (string) $gross->amount,
                (string) $gross->currency,
                $form->threshold === null ? null : (string) $form->threshold->amount,
                $form->threshold === null ? null : (string) $form->threshold->currency,
            );
        }

        return DB::connection('tenant')->transaction(function () use ($draft, $profile): Invoice {
            $draft->update(['simplified' => true, 'profile' => $profile]);

            return $draft->refresh();
        });
    }

    /**
     * Which document type a gross of this size has to be issued as.
     *
     * The sales screens call this as somebody types: a simplified document
     * that goes over the limit while being edited has to say so then, not at
     * the moment of issue, because at the moment of issue the only options
     * are to abandon it or to change its form - and the second is what
     * people do.
     */
    public function formIsStillAvailable(Invoice $draft, ?string $profile = null): bool
    {
        $profile ??= (string) ($draft->profile ?? config('jurisdictions.default_profile', 'generic'));

        return $this->formFor($profile)->admits(
            Money::of((string) $draft->gross_amount, (string) $draft->currency),
        );
    }
}
