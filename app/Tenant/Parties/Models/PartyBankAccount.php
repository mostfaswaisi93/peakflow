<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Models;

use App\Support\Database\TenantModel;
use App\Support\Iso\Iban;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/**
 * Where a party's money goes - PF-061.
 *
 * The highest-value fraud surface in the product. Beneficiary substitution -
 * change the account, release the payment - is the attack this system is
 * shaped against, and every decision here follows from that.
 *
 * ## The IBAN is never an attribute
 *
 * `$hidden` would not be enough: an accessor is reachable by `toArray()`,
 * by a `dd()`, by a log line interpolating the model, by a webhook payload
 * that serialises it, and by whatever an exception handler decides to
 * include. There is no `iban` attribute at all - reading it is an explicit
 * method call, which is greppable and which shows up in a review as a
 * deliberate act.
 *
 * ## Four digits in clear, and that is deliberate
 *
 * `last_four` lets a person confirm "the account ending 4471" on a phone
 * call, which is the control that actually catches substitution. Storing
 * none would make verification impossible and push people to email the full
 * number to each other, which is worse than the risk it avoids.
 *
 * @property bool $requires_reverification
 */
final class PartyBankAccount extends TenantModel
{
    use SoftDeletes;

    protected $table = 'party_bank_accounts';

    protected $fillable = [
        'party_id', 'label', 'country', 'currency', 'last_four',
        'bic', 'bank_name', 'active',
    ];

    /**
     * The encrypted columns are not fillable and not hidden - they are not
     * part of the attribute surface at all. See the class note.
     */
    protected $hidden = ['iban_encrypted', 'account_number_encrypted'];

    protected $casts = [
        'requires_reverification' => 'boolean',
        'verified_at' => 'datetime',
        'active' => 'boolean',
    ];

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * Set the IBAN, validating it first.
     *
     * Validation happens here rather than in a form request because a bank
     * account can also arrive from an import, an API call or a supplier
     * portal, and a check that only one of those paths performs is a check
     * that the other three do not.
     */
    public function setIban(string $iban): void
    {
        // Throws on a bad check digit or an unknown country length.
        $validated = new Iban($iban);

        $this->attributes['iban_encrypted'] = Crypt::encryptString($validated->value);
        $this->attributes['last_four'] = substr($validated->value, -4);
        $this->attributes['country'] = $validated->countryCode;
    }

    /**
     * Set a domestic account number, where IBAN does not apply.
     *
     * Not every country uses IBAN - the United States, Canada and Australia
     * among them - and refusing those parties would make the product unusable
     * for anyone trading with them.
     */
    public function setAccountNumber(string $number, string $country): void
    {
        $digits = preg_replace('/\s+/', '', $number) ?? '';

        if ($digits === '') {
            throw new \InvalidArgumentException('An account number cannot be empty.');
        }

        $this->attributes['account_number_encrypted'] = Crypt::encryptString($digits);
        $this->attributes['last_four'] = substr($digits, -4);
        $this->attributes['country'] = strtoupper($country);
    }

    /**
     * Read the account, deliberately as a method.
     *
     * Every call site is a decision somebody can find. This is called by the
     * payment file builder and by nothing else.
     */
    public function revealIban(): ?string
    {
        $encrypted = $this->attributes['iban_encrypted'] ?? null;

        return $encrypted === null ? null : Crypt::decryptString((string) $encrypted);
    }

    public function revealAccountNumber(): ?string
    {
        $encrypted = $this->attributes['account_number_encrypted'] ?? null;

        return $encrypted === null ? null : Crypt::decryptString((string) $encrypted);
    }

    /** Safe to show anywhere: the masked form. */
    public function masked(): string
    {
        return $this->last_four === null ? '••••' : '•••• '.$this->last_four;
    }

    public function isUsableForPayment(): bool
    {
        return $this->active && ! $this->requires_reverification;
    }
}
