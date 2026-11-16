<?php

declare(strict_types=1);

namespace App\Exchange\Serializers\Ubl;

use App\Exchange\Xml\Element as E;
use App\Exchange\Xml\XmlWriter;
use Illuminate\Support\Carbon;

/**
 * The standard's own answer to a document - PF-141.
 *
 * ## Why not an email
 *
 * PF-141's second criterion asks that a failing document be "rejected with
 * the standard's own response rather than half-imported", and the standard
 * has one: a UBL `ApplicationResponse`, which Peppol uses as the Message
 * Level Response and the Invoice Response Message. The sender's system
 * raised the document, and the sender's system is what has to be told - an
 * email reaches a person who then has to describe a BR rule number to a
 * developer.
 *
 * ## It carries the rule, not an opinion
 *
 * Each violation becomes a `cac:LineResponse` naming the rule and the
 * business term, because "invalid" is not an instruction. A supplier whose
 * document failed BR-CO-14 needs to know that the VAT breakdown does not add
 * up to the document total, and which figure we read.
 *
 * ## The response codes are UNCL 4343
 *
 * `AP` accepted, `RE` rejected, `CA` conditionally accepted. Conditional
 * acceptance exists for the case a human still has to decide - an unmatched
 * supplier - and saying `AP` there would tell the sender their invoice is in
 * our ledger when it is in a queue.
 */
final class ApplicationResponse
{
    private const NAMESPACES = [
        '' => 'urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
    ];

    /** UNCL 4343, the subset a billing response uses. */
    public const ACCEPTED = 'AP';

    public const REJECTED = 'RE';

    public const CONDITIONALLY_ACCEPTED = 'CA';

    private const SEQUENCE = [
        'ApplicationResponse' => [
            'cbc:CustomizationID', 'cbc:ProfileID', 'cbc:ID', 'cbc:IssueDate', 'cbc:IssueTime',
            'cbc:Note', 'cac:SenderParty', 'cac:ReceiverParty', 'cac:DocumentResponse',
        ],
        'cac:SenderParty' => ['cbc:EndpointID', 'cac:PartyIdentification'],
        'cac:ReceiverParty' => ['cbc:EndpointID', 'cac:PartyIdentification'],
        'cac:PartyIdentification' => ['cbc:ID'],
        'cac:DocumentResponse' => ['cac:Response', 'cac:DocumentReference', 'cac:LineResponse'],
        'cac:Response' => ['cbc:ResponseCode', 'cbc:Description'],
        'cac:DocumentReference' => ['cbc:ID', 'cbc:IssueDate', 'cbc:DocumentTypeCode'],
        'cac:LineResponse' => ['cac:LineReference', 'cac:Response'],
        'cac:LineReference' => ['cbc:LineID'],
    ];

    /**
     * @param  array<string, string|null>  $document  `number`, `issue_date`,
     *                                                `type_code`
     * @param  list<array<string, mixed>>  $violations  each as
     *                                                  {@see \App\Exchange\Validation\SemanticViolation::toArray()}
     *                                                  writes it
     */
    public function render(
        string $responseCode,
        array $document,
        array $violations = [],
        ?string $senderEndpoint = null,
        ?string $receiverEndpoint = null,
        ?string $reason = null,
        ?Carbon $at = null,
    ): string {
        $at ??= Carbon::now();

        $root = E::of('ApplicationResponse')->with(
            E::text('cbc:ID', sprintf('MLR-%s', $at->format('YmdHisv'))),
            E::text('cbc:IssueDate', $at->toDateString()),
            E::text('cbc:IssueTime', $at->format('H:i:s\Z')),
        );

        if ($reason !== null && trim($reason) !== '') {
            $root->with(E::text('cbc:Note', trim($reason)));
        }

        /*
         | Us as the sender, them as the receiver.
         |
         | The response travels the other way, so the parties swap - and
         | getting that backwards is the mistake that makes a response
         | undeliverable, because an access point routes on the receiver's
         | endpoint.
         */
        if ($receiverEndpoint !== null) {
            $root->with(E::of('cac:SenderParty')->with(E::text('cbc:EndpointID', $receiverEndpoint)));
        }

        if ($senderEndpoint !== null) {
            $root->with(E::of('cac:ReceiverParty')->with(E::text('cbc:EndpointID', $senderEndpoint)));
        }

        $response = E::of('cac:DocumentResponse')->with(
            E::of('cac:Response')->with(
                E::text('cbc:ResponseCode', $responseCode),
                E::text('cbc:Description', $this->describe($responseCode, $reason)),
            ),
        );

        if (($document['number'] ?? null) !== null) {
            $reference = E::of('cac:DocumentReference')->with(
                E::text('cbc:ID', (string) $document['number']),
            );

            if (($document['issue_date'] ?? null) !== null) {
                $reference->with(E::text('cbc:IssueDate', (string) $document['issue_date']));
            }

            if (($document['type_code'] ?? null) !== null) {
                $reference->with(E::text('cbc:DocumentTypeCode', (string) $document['type_code']));
            }

            $response->with($reference);
        }

        foreach ($violations as $index => $violation) {
            /*
             | Plain arrays, not typed violations.
             |
             | A response is frequently built from a report that was stored
             | months ago - the document in `inbound_documents` - and
             | rehydrating a stored report into objects to read three fields
             | from each would mean a `fromArray()` whose only caller is this
             | loop. The stored shape IS the report; a serializer takes
             | data.
             */
            /*
             | One LineResponse per violation, naming the rule.
             |
             | `cac:LineReference/cbc:LineID` is "0" - the document itself -
             | because a semantic violation carries the term and the group
             | it was found in rather than an invoice line number. Putting
             | an invented line number there would send the supplier to the
             | wrong place, which is worse than sending them to the document.
             */
            $response->with(
                E::of('cac:LineResponse')->with(
                    E::of('cac:LineReference')->with(E::text('cbc:LineID', '0')),
                    E::of('cac:Response')->with(
                        E::text('cbc:ResponseCode', (string) ($violation['rule'] ?? 'UNKNOWN')),
                        E::text('cbc:Description', $this->lineDescription($violation, $index)),
                    ),
                ),
            );
        }

        $root->with($response);

        return (new XmlWriter(self::SEQUENCE))->render($root, self::NAMESPACES);
    }

    private function describe(string $code, ?string $reason): string
    {
        return match ($code) {
            self::ACCEPTED => 'Accepted and recorded.',
            self::CONDITIONALLY_ACCEPTED => $reason ?? 'Accepted, pending a decision by a person.',
            self::REJECTED => $reason ?? 'Rejected: the document does not meet the rule set it declares.',
            default => $reason ?? 'Received.',
        };
    }

    /** @param array<string, mixed> $violation */
    private function lineDescription(array $violation, int $index): string
    {
        $pieces = array_values(array_filter(
            [
                isset($violation['term']) ? (string) $violation['term'] : null,
                isset($violation['group']) ? (string) $violation['group'] : null,
                /*
                 | The English explanation, where the rule has one.
                 |
                 | `config/en16931.php` holds a sentence per rule, and that
                 | sentence is the whole value of this response: "BR-CO-14"
                 | is a lookup somebody has to perform, and "the VAT
                 | breakdown does not add up to the document total" is an
                 | instruction.
                 */
                isset($violation['en']) ? (string) $violation['en'] : null,
                isset($violation['detail']) ? (string) $violation['detail'] : null,
            ],
            static fn (?string $piece): bool => $piece !== null && trim($piece) !== '',
        ));

        if ($pieces === []) {
            return sprintf('Violation %d: %s.', $index + 1, (string) ($violation['rule'] ?? 'unknown'));
        }

        return implode(' - ', $pieces);
    }
}
