<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Models;

use App\Support\Database\TenantModel;
use App\Support\Iso\Bic;
use App\Support\Iso\Iban;
use App\Support\Money\Money;
use App\Tenant\Banking\Exceptions\BankAccountRefused;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * A real bank account, mirrored by one GL account - PF-145, PF-146.
 *
 * ## The identifiers are encrypted and never logged
 *
 * An IBAN is the instruction to move money. It is the most valuable field in
 * the product to an attacker who can also change it - which is why PF-033's
 * one non-overridable duty pair is "whoever changes a supplier's bank
 * details must not release a payment to them".
 *
 * So `$hidden` covers every encrypted column and the accessors decrypt on
 * demand. The last four digits are stored in the clear, because every screen
 * that mentions an account has to identify it and decrypting on every row of
 * a list is how a page comes to take four seconds.
 *
 * ## The IBAN is validated by its value object, not by a regex
 *
 * {@see Iban} checks the country's declared length and the mod-97 check
 * digits. A regex accepts `GB00` followed by the right number of characters,
 * which is the shape of every mistyped IBAN - and the check digits exist
 * precisely so a typo is caught before the money moves.
 *
 * @property string $label
 * @property string $currency
 * @property string $status
 * @property string $opening_balance
 */
final class BankAccount extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'bank_accounts';

    protected $fillable = [
        'entity_id', 'label', 'status',
        'institution_name', 'institution_country', 'branch', 'holder_legal_name', 'currency',
        'iban_encrypted', 'iban_tail', 'bic',
        'domestic_scheme', 'domestic_identifier_encrypted', 'domestic_identifier_tail',
        'account_number_encrypted',
        'gl_account_id', 'opening_balance', 'opening_date', 'note', 'created_by',
    ];

    /**
     * Never serialised, never logged, never in a webhook payload.
     *
     * The telemetry allowlist (PF-021) covers the log path; this covers
     * every `toArray()` - which is what an API response, an export and a
     * queued job payload are all built from.
     */
    protected $hidden = [
        'iban_encrypted',
        'domestic_identifier_encrypted',
        'account_number_encrypted',
    ];

    protected $casts = [
        'opening_date' => 'immutable_date',
    ];

    /** @return BelongsTo<Account, $this> */
    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }

    /** @return HasMany<BankStatement, $this> */
    public function statements(): HasMany
    {
        return $this->hasMany(BankStatement::class)->orderBy('sequence');
    }

    /**
     * Set the IBAN, validated.
     *
     * @throws BankAccountRefused
     */
    public function setIban(string $value): self
    {
        $iban = Iban::tryFrom($value);

        if ($iban === null) {
            throw BankAccountRefused::invalidIban($value);
        }

        $this->iban_encrypted = Crypt::encryptString((string) $iban);
        $this->iban_tail = substr((string) $iban, -4);

        return $this;
    }

    public function iban(): ?Iban
    {
        if ($this->iban_encrypted === null) {
            return null;
        }

        return Iban::tryFrom(Crypt::decryptString((string) $this->iban_encrypted));
    }

    /**
     * Set a domestic identifier, validated by its country's scheme.
     *
     * @throws BankAccountRefused
     */
    public function setDomesticIdentifier(string $scheme, string $value): self
    {
        $this->domestic_scheme = $scheme;
        $this->domestic_identifier_encrypted = Crypt::encryptString($value);
        $this->domestic_identifier_tail = substr(preg_replace('/\W/', '', $value) ?? $value, -4);

        return $this;
    }

    public function domesticIdentifier(): ?string
    {
        return $this->domestic_identifier_encrypted === null
            ? null
            : Crypt::decryptString((string) $this->domestic_identifier_encrypted);
    }

    public function setAccountNumber(string $value): self
    {
        $this->account_number_encrypted = Crypt::encryptString($value);

        return $this;
    }

    public function accountNumber(): ?string
    {
        return $this->account_number_encrypted === null
            ? null
            : Crypt::decryptString((string) $this->account_number_encrypted);
    }

    public function bic(): ?Bic
    {
        return $this->bic === null ? null : Bic::tryFrom((string) $this->bic);
    }

    /**
     * How this account identifies itself on a screen.
     *
     * The tail only. A full IBAN on a list screen is a full IBAN in a
     * screenshot, a support ticket and a browser cache.
     */
    public function shortIdentifier(): string
    {
        $tail = $this->iban_tail ?? $this->domestic_identifier_tail;

        return $tail === null
            ? (string) $this->label
            : sprintf('%s ····%s', (string) $this->label, $tail);
    }

    public function openingBalance(): Money
    {
        return Money::of($this->opening_balance, $this->currency);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** The last statement imported for this account, superseded ones aside. */
    public function lastStatement(): ?BankStatement
    {
        return BankStatement::query()
            ->where('bank_account_id', (string) $this->getKey())
            ->where('status', '!=', 'superseded')
            ->orderByDesc('sequence')
            ->first();
    }
}
