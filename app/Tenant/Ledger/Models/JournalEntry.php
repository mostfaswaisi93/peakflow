<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Ledger\Enums\EntryStatus;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A journal entry - PF-041, PF-045.
 *
 * ## Posted entries are not edited, and the model says so early
 *
 * The database refuses it with a trigger and the application refuses it here,
 * so the message a developer sees names the rule rather than a constraint.
 * The database is the one that matters: a console command, a migration or a
 * REPL session goes nowhere near this class.
 *
 * @property EntryStatus $status
 * @property \Illuminate\Support\Carbon $accounting_date
 */
final class JournalEntry extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'journal_entries';

    protected $fillable = [
        'entity_id', 'journal_id', 'period_id', 'number', 'accounting_date',
        'entered_at', 'narration', 'source_type', 'source_id', 'posting_rule',
        'status', 'created_by_user_id', 'posted_by_user_id', 'posted_at',
        'reverses_entry_id',
    ];

    protected $casts = [
        // A DATE, never a datetime. An accounting date that carries a time can
        // be timezone-shifted into the previous or next period.
        'accounting_date' => 'date',
        'entered_at' => 'datetime',
        'posted_at' => 'datetime',
        'status' => EntryStatus::class,
    ];

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /** @return BelongsTo<Period, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            /*
             | `getRawOriginal`, not `getOriginal`.
             |
             | `getOriginal()` APPLIES CASTS, so with an enum cast on `status`
             | it returns EntryStatus::Posted and a `!== 'posted'` comparison
             | is always true - the guard returned early on every posted
             | entry and never fired once. The database trigger caught the
             | edit, which is why nothing looked broken; the only symptom was
             | that developers saw a PostgreSQL error instead of this message.
             |
             | Compared against the case itself now, so the cast cannot make
             | the comparison silently false again.
             */
            if ($entry->getRawOriginal('status') !== EntryStatus::Posted->value) {
                return;
            }

            /*
             | The one permitted change: recording that a reversal happened.
             |
             | `updated_at` is excluded because Eloquent touches it on every
             | save - an exact match on the dirty keys therefore refused the
             | permitted transition as well as the forbidden ones, which left
             | a posted entry that could never be marked reversed.
             */
            $changed = array_values(array_diff(array_keys($entry->getDirty()), ['updated_at']));

            if ($changed === ['status'] && $entry->status === EntryStatus::Reversed) {
                return;
            }

            $number = $entry->number;

            /*
             | Discard the rejected change before throwing.
             |
             | Laravel fills the model and THEN fires `updating`, so a refused
             | attribute stays dirty on the in-memory instance. Caller catches
             | the exception, does something legitimate later, saves - and the
             | rejected edit rides along on that save, which is now permitted
             | because it travels with a permitted change.
             |
             | Restoring the originals means a refusal leaves the object as it
             | was, which is what anyone would assume it does.
             */
            $entry->setRawAttributes($entry->getRawOriginal(), sync: true);

            throw new \DomainException(sprintf(
                'Journal entry %s is posted and cannot be edited. Correct it with a reversal and a new entry: '.
                'the original was reported on, and a report that no longer reconciles to the ledger it came '.
                'from cannot be explained to anyone.',
                $number,
            ));
        });

        static::deleting(function (self $entry): void {
            if ($entry->status !== EntryStatus::Draft) {
                throw new \DomainException(sprintf(
                    'Journal entry %s is %s and cannot be deleted. A ledger with a hole in it is not a ledger.',
                    $entry->number,
                    $entry->status->value,
                ));
            }
        });
    }

    /** The signed total, which must be zero for a well-formed entry. */
    public function functionalTotal(): string
    {
        return (string) ($this->lines()->sum('functional_amount') ?? '0');
    }
}
