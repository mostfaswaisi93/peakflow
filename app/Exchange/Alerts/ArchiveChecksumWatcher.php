<?php

declare(strict_types=1);

namespace App\Exchange\Alerts;

use App\Exchange\Archive\Archive;
use App\Support\Telemetry\Alerts\Alert;
use App\Support\Telemetry\Alerts\InvariantWatcher;
use App\Support\Telemetry\Alerts\WatchScope;
use Illuminate\Support\Facades\DB;

/**
 * `archive:verify`, watched - PF-242.
 *
 * ## What an archive checksum mismatch actually is
 *
 * A sealed period's artefacts are the documents a tax authority will ask for,
 * years later. The manifest records their hashes; a mismatch means the bytes
 * that come back out of storage are not the bytes that went in. That is
 * either storage corruption or somebody editing history, and in both cases
 * the only moment it can be fixed is while a backup taken before the
 * divergence still exists.
 *
 * Nobody looks at an archive. That is the point of one. So the only thing
 * that can notice is a scheduled re-checksum, and there was none.
 *
 * ## A RANDOM sample per run, not the newest periods
 *
 * Bit rot and a quiet edit are both likeliest in the periods nobody has
 * touched for years, so checking the newest sealed periods would check the
 * ones least at risk. The sample is random and small, for the same reason
 * `Archive::drill()` samples randomly: over a month of hourly runs every
 * period gets re-checked, and no single run reads the whole archive - which
 * on a five-year archive would be the most expensive query in the product.
 *
 * `archive:verify` remains there for the whole-period answer on demand.
 */
final class ArchiveChecksumWatcher implements InvariantWatcher
{
    /** How many sealed periods one run re-checksums. */
    private const SAMPLE = 2;

    public function __construct(private readonly Archive $archive) {}

    /** @return list<string> */
    public function keys(): array
    {
        return ['archive_checksum_mismatch'];
    }

    public function scope(): WatchScope
    {
        return WatchScope::Entity;
    }

    /** @return list<Alert> */
    public function watch(string $tenant, ?string $entityId): array
    {
        if ($entityId === null) {
            return [];
        }

        $sealed = DB::connection('tenant')->table('archive_manifests')
            ->where('entity_id', $entityId)
            ->inRandomOrder()
            ->limit(self::SAMPLE)
            ->pluck('period_label');

        $alerts = [];

        foreach ($sealed as $label) {
            $report = $this->archive->verify($entityId, (string) $label);

            $wrong = [
                'missing from storage' => $report['missing'],
                'diverged from its hash' => $report['diverged'],
                'removed since the seal' => $report['removed'],
                'added since the seal' => $report['added'],
            ];

            $first = null;
            $count = 0;

            foreach ($wrong as $what => $hashes) {
                $count += count($hashes);

                if ($first === null && $hashes !== []) {
                    $first = sprintf('%s %s', $hashes[0], $what);
                }
            }

            $manifest = $report['manifest'];

            if ($manifest === 'diverged') {
                $count++;
                $first ??= 'the manifest itself no longer hashes to what was sealed';
            }

            if ($count === 0) {
                continue;
            }

            $alerts[] = Alert::critical(
                key: 'archive_checksum_mismatch',
                tenant: $tenant,
                entityId: $entityId,
                period: (string) $label,
                firstDivergence: (string) $first,
                messageAr: sprintf(
                    'لا تتطابق محتويات الأرشيف المختوم للفترة %s مع بيانها في %d موضع، أوّلها: %s. '.
                    'هذه مستندات ستطلبها جهة ضريبية بعد سنوات. استعِد من نسخة احتياطية سابقة للانحراف '.
                    'وقارِن - ولا تُعِد الختم: إعادة الختم تجعل البيان يصف ما هو موجود الآن.',
                    (string) $label,
                    $count,
                    (string) $first,
                ),
                messageEn: sprintf(
                    'The sealed archive for period %s no longer matches its manifest in %d place(s), '.
                    'the first being: %s. These are the documents a tax authority will ask for years '.
                    'from now. Restore from a backup taken before the divergence and compare. Do NOT '.
                    're-seal: re-sealing makes the manifest describe whatever is there now, which '.
                    'destroys the only record of what was sealed.',
                    (string) $label,
                    $count,
                    (string) $first,
                ),
                observed: (string) $count,
                threshold: '0',
            );
        }

        return $alerts;
    }
}
