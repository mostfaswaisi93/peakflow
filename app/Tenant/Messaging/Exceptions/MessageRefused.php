<?php

declare(strict_types=1);

namespace App\Tenant\Messaging\Exceptions;

use RuntimeException;

/**
 * Messages that must not be sent, and why - PF-257.
 *
 * Every refusal here is about somebody's personal data leaving the
 * building, so each one says what is missing rather than that something
 * is wrong.
 */
final class MessageRefused extends RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }

    /**
     * No consent on record for this number and channel.
     *
     * Refused rather than sent-and-logged. Sending an invoice to a
     * number nobody agreed to is a data breach with a timestamp on it,
     * and the timestamp is ours.
     */
    public static function withoutConsent(string $address, string $channel): self
    {
        return new self(sprintf(
            'There is no consent on record for %s on %s. An invoice carries a name and an amount, and '.
            'the person who receives it at the wrong number has no relationship with this business - '.
            'which makes sending it a breach with a date on it. Record the consent, with how it was '.
            'given, first.',
            $address,
            $channel,
        ));
    }

    public static function withdrawnConsent(string $address, string $on, string $reason): self
    {
        return new self(sprintf(
            'Consent for %s was withdrawn on %s: %s. The row is kept rather than deleted, because "did '.
            'we have consent in March" is asked after a complaint.',
            $address,
            $on,
            $reason,
        ));
    }

    /**
     * The number was never verified as reachable.
     *
     * Consent is a decision the customer made; reachability is a fact
     * about the number. A customer can agree and give a number with a
     * digit missing, and that digit belongs to somebody else.
     */
    public static function unverified(string $address): self
    {
        return new self(sprintf(
            '%s has consent and has never been verified. Consent is a decision the customer made; '.
            'reachability is a fact about the number - and a customer can agree and give a number with '.
            'a digit missing, which belongs to somebody else.',
            $address,
        ));
    }

    /**
     * A template the provider has not approved.
     *
     * Refused BEFORE sending. The provider refuses it too, with a
     * generic error, hours later - by which time the invoice is simply
     * not delivered and nobody has connected the two.
     *
     * @param  list<string>  $approved
     */
    public static function anUnapprovedTemplate(string $template, string $language, array $approved): self
    {
        return new self(sprintf(
            'The provider has not approved a template "%s" in %s, so this is refused before anything '.
            'is sent. Sending it anyway produces a generic refusal hours later, and the invoice is '.
            'simply not delivered. Approved: %s.',
            $template,
            $language,
            $approved === [] ? 'none at all' : implode(', ', $approved),
        ));
    }

    public static function theWrongNumberOfVariables(string $template, int $wanted, int $given): self
    {
        return new self(sprintf(
            'Template "%s" takes %d placeholder(s) and %d were given. Too many is refused by the '.
            'provider; too few renders a literal {{n}} in the message, which the customer reads.',
            $template,
            $wanted,
            $given,
        ));
    }
}
