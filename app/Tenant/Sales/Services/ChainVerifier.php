<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Hashing\Canonicalizer;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;

/**
 * Re-walks a series and reports the FIRST divergence - PF-096.
 *
 * ## Why the first and not all of them
 *
 * Because after the first one every later link is wrong too. A chain where
 * document 41 was tampered with reports documents 41 through 900 as broken,
 * and a report of 860 findings is a report nobody reads to the end - while the
 * one fact that matters is "41, and this field".
 *
 * ## What the chain proves, and what it does not
 *
 * It proves internal consistency: that nobody has changed an issued document
 * since it was issued, and that none has been removed from the middle of a
 * series.
 *
 * It does **not** prove authorship. Anyone who can write to this database can
 * recompute a chain from a tampered document onward, and the result verifies.
 * What stops that is a signature from a key the database does not hold, which
 * is PF-124 - and conflating the two is how a system comes to claim more than
 * it can.
 */
final class ChainVerifier
{
    /**
     * @return array{
     *     series: string,
     *     documents: int,
     *     verified: bool,
     *     divergence: array{number: string, index: int, reason: string, field: string|null}|null,
     * }
     */
    public function verify(string $entityId, string $seriesKey): array
    {
        $documents = Invoice::query()
            ->where('entity_id', $entityId)
            ->where('series_key', $seriesKey)
            ->where('status', 'issued')
            ->whereNotNull('chain_index')
            ->orderBy('chain_index')
            ->get();

        $genesis = str_repeat('0', 64);
        $expectedIndex = 1;
        $previousHash = $genesis;

        foreach ($documents as $document) {
            $number = (string) $document->number;

            if ($document->chain_index !== $expectedIndex) {
                return $this->divergence($seriesKey, $documents->count(), $number, $expectedIndex, sprintf(
                    'the chain jumps from %d to %d. A document has been removed from the middle of the '.
                    'series, which is the shape of an invoice that was issued and then made to '.
                    'disappear.',
                    $expectedIndex - 1,
                    (int) $document->chain_index,
                ), 'chain_index');
            }

            if ((string) $document->previous_hash !== $previousHash) {
                return $this->divergence($seriesKey, $documents->count(), $number, $expectedIndex, sprintf(
                    'it records the previous hash as %s and the document before it hashes to %s.',
                    substr((string) $document->previous_hash, 0, 16).'…',
                    substr($previousHash, 0, 16).'…',
                ), 'previous_hash');
            }

            /*
             | The stored bytes first.
             |
             | If the canonical form no longer hashes to the stored hash, the
             | bytes themselves were edited - which is a different fact from
             | the document having changed, and a louder one.
             */
            $stored = $document->canonicalForm() ?? '';

            if (hash('sha256', $stored) !== (string) $document->canonical_hash) {
                return $this->divergence($seriesKey, $documents->count(), $number, $expectedIndex,
                    'its stored canonical form does not hash to its stored hash, so the frozen bytes '.
                    'themselves have been edited rather than the document.', 'canonical');
            }

            $recomputed = Canonicalizer::encode($this->legallySignificant($document, $previousHash));

            if ($recomputed !== $stored) {
                $field = $this->firstFieldThatDiffers($stored, $recomputed);

                return $this->divergence($seriesKey, $documents->count(), $number, $expectedIndex, sprintf(
                    'its contents no longer match what was issued. The first field that differs is '.
                    '%s: it was %s and is now %s.',
                    $field['path'],
                    $this->show($field['was']),
                    $this->show($field['now']),
                ), $field['path']);
            }

            $previousHash = (string) $document->canonical_hash;
            $expectedIndex++;
        }

        return [
            'series' => $seriesKey,
            'documents' => $documents->count(),
            'verified' => true,
            'divergence' => null,
        ];
    }

    /** Every series an entity has issued into. */
    public function seriesOf(string $entityId): array
    {
        return Invoice::query()
            ->where('entity_id', $entityId)
            ->where('status', 'issued')
            ->distinct()
            ->orderBy('series_key')
            ->pluck('series_key')
            ->map(static fn (mixed $key): string => (string) $key)
            ->all();
    }

    /**
     * The fields the hash covers.
     *
     * The same shape the issuer hashed, and deliberately a SUBSET of the row:
     * a cosmetic change - a note, a timestamp, anything about presentation -
     * must not invalidate a chain, and a material one must not avoid it.
     *
     * Kept here as well as in the issuer because this is the side that has to
     * survive: if the two ever disagree, every document verifies as broken,
     * which is loud rather than silent.
     *
     * @return array<string, mixed>
     */
    public function legallySignificant(Invoice $document, string $previousHash): array
    {
        return [
            'number' => (string) $document->number,
            'series' => $document->series_key,
            'type' => $document->document_type->value,
            'issue_date' => $document->issue_date->toDateString(),
            'tax_point' => ($document->tax_point ?? $document->issue_date)->toDateString(),
            'currency' => $document->currency,
            'totals' => [
                'net' => $document->net_amount,
                'tax' => $document->tax_amount,
                'gross' => $document->gross_amount,
                'prepaid' => $document->prepaid_amount,
                'payable' => $document->payable_amount,
            ],
            'supplier' => $document->supplier_snapshot,
            'customer' => $document->customer_snapshot,
            'tax_decision' => $document->tax_decision_snapshot,
            'lines' => $document->lines()->get()->map(static fn (InvoiceLine $line): array => [
                'line_no' => $line->line_no,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'net' => $line->net_amount,
                'category' => $line->tax_category->value,
                'rate' => $line->tax_rate,
            ])->all(),
            'breakdown' => $document->taxBreakdown()->get()
                ->map(static fn (object $row): array => [
                    'category' => $row->category->value,
                    'rate' => $row->rate,
                    'taxable' => $row->taxable_amount,
                    'tax' => $row->tax_amount,
                ])->all(),
            'previous' => $previousHash,
        ];
    }

    /**
     * The first field whose value differs, by path.
     *
     * Possible because the canonical form is STORED: the old document and the
     * current one are both here, so the answer is a diff rather than an
     * inference. A verifier holding only a hash could say nothing beyond
     * "this is not what it was" - which is true, unhelpful, and what the
     * first version of this class did.
     *
     * @return array{path: string, was: mixed, now: mixed}
     */
    public function firstFieldThatDiffers(string $stored, string $recomputed): array
    {
        $was = json_decode($stored, true);
        $now = json_decode($recomputed, true);

        if (! \is_array($was) || ! \is_array($now)) {
            return ['path' => '(the whole document)', 'was' => $stored, 'now' => $recomputed];
        }

        $found = $this->walk($was, $now, '');

        return $found ?? ['path' => '(the whole document)', 'was' => null, 'now' => null];
    }

    /**
     * @param  array<mixed>  $was
     * @param  array<mixed>  $now
     * @return array{path: string, was: mixed, now: mixed}|null
     */
    private function walk(array $was, array $now, string $prefix): ?array
    {
        /*
         | Keys in order, and the missing ones too.
         |
         | A field that was REMOVED is a change as much as one that moved, and
         | walking only the current keys would miss it - which is the shape of
         | a tampered document that dropped a line.
         */
        $keys = array_values(array_unique([...array_keys($was), ...array_keys($now)]));

        foreach ($keys as $key) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            $oldValue = $was[$key] ?? null;
            $newValue = $now[$key] ?? null;

            if (\is_array($oldValue) && \is_array($newValue)) {
                $deeper = $this->walk($oldValue, $newValue, $path);

                if ($deeper !== null) {
                    return $deeper;
                }

                continue;
            }

            if ($oldValue !== $newValue) {
                return ['path' => $path, 'was' => $oldValue, 'now' => $newValue];
            }
        }

        return null;
    }

    private function show(mixed $value): string
    {
        if ($value === null) {
            return '(absent)';
        }

        if (\is_array($value)) {
            return '(a group of '.\count($value).' field(s))';
        }

        return '"'.(string) $value.'"';
    }

    /**
     * @return array{series: string, documents: int, verified: bool, divergence: array{number: string, index: int, reason: string, field: string|null}}
     */
    private function divergence(
        string $series,
        int $documents,
        string $number,
        int $index,
        string $reason,
        ?string $field,
    ): array {
        return [
            'series' => $series,
            'documents' => $documents,
            'verified' => false,
            'divergence' => [
                'number' => $number,
                'index' => $index,
                'reason' => $reason,
                'field' => $field,
            ],
        ];
    }
}
