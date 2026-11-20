<?php

declare(strict_types=1);

namespace App\Tenant\Messaging\Services;

use App\Tenant\Messaging\Exceptions\MessageRefused;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Addresses that bounced or complained - PF-104.
 *
 * ## The criterion is "rather than silently retrying forever"
 *
 * A hard bounce means the mailbox does not exist. Retrying it does not
 * eventually work; it burns the sending domain's reputation, and the way
 * a business discovers that is when the invoices with the RIGHT
 * addresses stop arriving. The failure is both silent and
 * ever-widening, which is the worst shape a failure can have.
 *
 * So a bounce stops the sending and **surfaces**: the next attempt is
 * refused with the reason, and `Deliveries` writes that refusal into the
 * delivery log where somebody looking at the document can see it.
 *
 * ## A bounce and a complaint are not the same fact
 *
 * A bounce says the address does not exist - fixed by correcting it. A
 * complaint says it does and the person marked the message as spam -
 * fixed by asking the customer whether they want these at all, and NOT
 * by sending again from a different address, which is how a domain goes
 * from a complaint to a blocklist.
 *
 * Both stop the sending because the remedy in both cases is a person,
 * not a retry. `reason` is what tells the two apart for the person who
 * eventually looks.
 *
 * ## Soft bounces are deliberately not here
 *
 * A full mailbox or a greylisting answer is temporary, and suppressing
 * on one would stop a customer's invoices because their inbox was full
 * on a Tuesday. The transport's own retry handles those; this list is
 * for the permanent ones.
 */
final class Suppressions
{
    private const REASONS = ['bounce', 'complaint', 'manual'];

    /**
     * Record that an address must not be sent to again.
     *
     * Idempotent on `(channel, address)`: a provider that sends the same
     * bounce notification twice - which they do - must not produce two
     * rows, and the first `suppressed_at` is the one that is true.
     */
    public function suppress(
        string $entityId,
        string $address,
        string $reason,
        ?string $detail = null,
        ?string $partyId = null,
        string $channel = 'email',
    ): void {
        if (! \in_array($reason, self::REASONS, true)) {
            throw MessageRefused::because(sprintf(
                'A suppression reason is one of %s, and "%s" is none of them. The reason decides what '.
                'somebody does about it - a bounce is fixed by correcting the address and a complaint is '.
                'fixed by asking the customer whether they want these at all - so an unnamed reason is a '.
                'row nobody can act on.',
                implode(', ', self::REASONS),
                $reason,
            ));
        }

        DB::connection('tenant')->table('suppressed_addresses')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'entity_id' => $entityId,
            'channel' => $channel,
            'address' => Str::lower($address),
            'reason' => $reason,
            'detail' => $detail === null ? null : Str::limit($detail, 490),
            'party_id' => $partyId,
            'suppressed_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Allow an address again, with a reason that is recorded elsewhere.
     *
     * Deliberately NOT silent: lifting a suppression is somebody saying
     * the address was corrected or the customer asked to be sent to
     * again, and that is a decision. The row is deleted rather than
     * flagged because the question this table answers is "may we send",
     * and a history of suppressions lives in the delivery log.
     */
    public function release(string $address, string $channel = 'email'): bool
    {
        return DB::connection('tenant')->table('suppressed_addresses')
            ->where('channel', $channel)
            ->where('address', Str::lower($address))
            ->delete() > 0;
    }

    /** Whether this address is currently suppressed, and why. */
    public function reasonFor(string $address, string $channel = 'email'): ?string
    {
        $row = DB::connection('tenant')->table('suppressed_addresses')
            ->where('channel', $channel)
            ->where('address', Str::lower($address))
            ->first();

        return $row === null ? null : (string) $row->reason;
    }

    /**
     * Everything currently suppressed, newest first.
     *
     * For the screen that surfaces them, which is the other half of
     * "surfaces it rather than silently retrying": a list nobody can see
     * is the same as no list.
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $entityId, int $limit = 100): array
    {
        return DB::connection('tenant')->table('suppressed_addresses')
            ->where('entity_id', $entityId)
            ->orderByDesc('suppressed_at')
            ->limit($limit)
            ->get()
            ->map(static fn (object $row): array => [
                'address' => (string) $row->address,
                'reason' => (string) $row->reason,
                'detail' => $row->detail === null ? null : (string) $row->detail,
                'channel' => (string) $row->channel,
                'suppressed_at' => (string) $row->suppressed_at,
            ])
            ->all();
    }
}
