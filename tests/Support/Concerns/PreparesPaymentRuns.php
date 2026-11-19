<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Services\BankDetailRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Suppliers we can pay, and payables to pay them for - PF-174, PF-156.
 *
 * Shared by the settlement suite, which checks how a run is built, and the
 * matching suite, which checks how a released run is recognised on a
 * statement. Two copies of "an approved bill" would drift, and the first
 * symptom would be one suite proving something about a state the other
 * never produces.
 */
trait PreparesPaymentRuns
{
    private int $ibanCounter = 0;

    private ?\App\Tenant\Identity\Models\User $bankDetailsClerk = null;

    /**
     * A supplier the run can pay: verified details, and its preferences.
     *
     * The verification is done by a second person, because the registry
     * refuses a verifier who was the one that entered the details - which
     * is the control, and a fixture that worked round it would be standing
     * on a state the system refuses.
     */
    protected function aPayableSupplier(
        bool $groups = true,
        ?string $limit = null,
        bool $withAnAccount = true,
    ): Party {
        $party = Party::create([
            'code' => 'PAY-S-'.Str::upper(Str::random(6)),
            'legal_name' => 'Probe Supplier '.bin2hex(random_bytes(3)),
            'is_supplier' => true,
            'country' => 'SA',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        if ($withAnAccount) {
            /*
             | Entered by a REAL person, signed in - PF-249.
             |
             | `record()` asserts `supplier.bank_details.update`, which is
             | one half of the pair that "can never be overridden". The
             | fixture used to call it with nobody on the guard, and the
             | engine returned early for want of an actor - so nothing was
             | written to the trail under that duty, `whoLastChanged()`
             | found nobody, and the whole beneficiary pair was inert in
             | every check built on this supplier.
             |
             | It passed, because the only check that drove the pair signed
             | a user in itself. Which is the shape worth remembering: a
             | control proved by one check and bypassed by the fixture of
             | thirty-nine others.
             |
             | The clerk is not the second actor: `verify()` below is done
             | by somebody else, because the registry refuses a verifier who
             | entered the details, and a fixture that worked round that
             | would be standing on a state the system refuses.
             */
            $previous = auth()->guard('web')->user();

            auth()->guard('web')->setUser($this->aBankDetailsClerk());

            try {
                $account = app(BankDetailRegistry::class)->record(
                    $party,
                    iban: $this->anUnusedIban(),
                    bic: 'RIBLSARI',
                );
            } finally {
                if ($previous === null) {
                    auth()->guard('web')->logout();
                } else {
                    auth()->guard('web')->setUser($previous);
                }
            }

            app(BankDetailRegistry::class)->verify(
                $account->fresh(),
                $this->secondActorId(),
                'callback',
            );
        }

        DB::connection('tenant')->table('party_entity_settings')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'party_id' => (string) $party->getKey(),
            'group_payments' => $groups,
            'payment_limit' => $limit,
            'payment_limit_basis' => $limit === null
                ? null
                : 'their bank caps a single incoming credit, confirmed with their treasury on 2026-04-02',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $party;
    }

    /**
     * The person who enters supplier bank details in this fixture.
     *
     * One per run, reused: the duty is party-scoped, so the same clerk
     * entering details for twenty suppliers conflicts with nothing, and a
     * new user per supplier would only make the fixture slower.
     *
     * Deliberately NOT the user any check releases a payment as. The whole
     * point of the pair is that those two are different people, and a
     * fixture that made them the same would turn every release in the suite
     * into a refusal.
     */
    protected function aBankDetailsClerk(): \App\Tenant\Identity\Models\User
    {
        return $this->bankDetailsClerk ??= \App\Tenant\Identity\Models\User::create([
            'name' => 'Bank details clerk',
            'email' => 'bank-details-'.bin2hex(random_bytes(5)).'@probe.test',
            'password' => Str::password(32),
            'active' => true,
        ]);
    }

    /**
     * An approved payable, written at the table.
     *
     * Entering and approving a bill through the services is Phase 06's
     * subject and needs an approval chain, a tax decision and a posting.
     * What a payment run depends on is narrower - approved, unpaid, with
     * terms - so the fixture writes that state and satisfies every
     * constraint the table has rather than reaching past them.
     */
    protected function anApprovedBill(
        Party $supplier,
        string $gross,
        string $dueOn,
        ?string $discountPercent = null,
        ?string $discountUntil = null,
    ): string {
        $id = (string) Str::ulid();
        $number = 'SUP-'.Str::upper(Str::random(8));

        $terms = ['due_dates' => [['date' => $dueOn, 'amount' => $gross]]];

        if ($discountPercent !== null && $discountUntil !== null) {
            $terms['discount'] = ['percent' => $discountPercent, 'until' => $discountUntil];
        }

        DB::connection('tenant')->table('bills')->insert([
            'id' => $id,
            'entity_id' => $this->entityId,
            'document_type' => '380',
            'status' => 'approved',
            'party_id' => (string) $supplier->getKey(),
            'supplier_document_number' => $number,
            'supplier_document_key' => (string) $supplier->getKey().'|'.$number,
            'supplier_document_date' => '2026-05-01',
            'tax_point' => '2026-05-01',
            'posting_date' => '2026-05-01',
            'currency' => 'SAR',
            'net_amount' => $gross,
            'tax_amount' => '0.0000',
            'gross_amount' => $gross,
            'recoverable_tax_amount' => '0.0000',
            'non_recoverable_tax_amount' => '0.0000',
            'payable_amount' => $gross,
            'paid_amount' => '0.0000',
            'terms_snapshot' => json_encode($terms, JSON_THROW_ON_ERROR),
            'source' => 'manual',
            'match_status' => 'not_applicable',
            'created_by' => $this->actorId(),
            'approved_at' => '2026-05-02 10:00:00',
            'approved_by' => $this->secondActorId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * An IBAN no other supplier in this run has, with real check digits.
     *
     * The model validates the check digit on the way in, which is right -
     * so the fixture computes one rather than disabling the validation it
     * would otherwise be testing against.
     */
    protected function anUnusedIban(): string
    {
        $this->ibanCounter++;
        $bban = sprintf('80000000%012d', $this->ibanCounter);

        /*
         | ISO 13616: rearrange, letters to digits, 98 minus mod 97.
         |
         | 'S' is 28 and 'A' is 10, so SA becomes 2810, and the two zeroes
         | stand in for the check digits being computed.
         */
        $rearranged = $bban.'281000';
        $remainder = 0;

        foreach (str_split($rearranged) as $digit) {
            $remainder = (int) (($remainder.$digit) % 97);
        }

        return sprintf('SA%02d%s', 98 - $remainder, $bban);
    }

}
