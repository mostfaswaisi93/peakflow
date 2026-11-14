<?php

declare(strict_types=1);

namespace App\Support\Hashing;

use JsonSerializable;

/**
 * One link in a document hash chain.
 *
 * Each issued document stores the SHA-256 of its own canonical form plus the
 * hash of the document that preceded it in the same series. That makes three
 * kinds of tampering detectable:
 *
 * - **Alteration** - the document's own hash no longer matches its content.
 * - **Deletion** - the following document's `previous` no longer matches.
 * - **Insertion** - the same, from the other side.
 *
 * It is the mechanism behind ZATCA's Previous Invoice Hash, and it is applied
 * in every jurisdiction profile because it costs nothing and answers "has this
 * ledger been edited" definitively.
 *
 * ## What it does not prove
 *
 * **Authorship.** A chain proves internal consistency, not who wrote it -
 * anyone who can rewrite the whole chain can produce a consistent one. Where a
 * jurisdiction requires proof of authorship, that is a cryptographic seal
 * applied in the Exchange plane with a key held in a KMS (PF-124), and it is a
 * separate field from this one.
 */
final readonly class ChainLink implements JsonSerializable
{
    /** The hash recorded for the first document in a series. */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private function __construct(
        public string $hash,
        public string $previous,
        public int $index,
    ) {}

    /**
     * Link a document onto the one before it.
     *
     * @param  array<array-key, mixed>|JsonSerializable  $legallySignificantFields
     *   Only the fields whose change would change the document's meaning. A
     *   cosmetic change must not invalidate a chain, and a material one must
     *   not be able to avoid it - which is why the caller passes a deliberate
     *   projection rather than the whole model.
     */
    public static function link(
        array|JsonSerializable $legallySignificantFields,
        ?self $previous = null,
    ): self {
        return new self(
            hash: Canonicalizer::hash($legallySignificantFields),
            previous: $previous?->hash ?? self::GENESIS,
            index: $previous === null ? 1 : $previous->index + 1,
        );
    }

    public static function fromStored(string $hash, string $previous, int $index): self
    {
        return new self($hash, $previous, $index);
    }

    public function isGenesis(): bool
    {
        return $this->previous === self::GENESIS;
    }

    /** Does this link still describe the content it claims to? */
    public function matches(array|JsonSerializable $legallySignificantFields): bool
    {
        return hash_equals($this->hash, Canonicalizer::hash($legallySignificantFields));
    }

    /** Does this link follow the one given? */
    public function follows(self $previous): bool
    {
        return hash_equals($this->previous, $previous->hash)
            && $this->index === $previous->index + 1;
    }

    /**
     * @return array{hash: string, previous: string, index: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'hash' => $this->hash,
            'previous' => $this->previous,
            'index' => $this->index,
        ];
    }
}
