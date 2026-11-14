<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * The registry of RFC 9457 problem types.
 *
 * An error `type` is a CONTRACT a client can switch on, so it is declared here
 * rather than invented at the call site. A client that has to parse `detail`
 * to tell a closed period from a missing permission has no contract at all.
 *
 * The URI is stable and dereferenceable - it is where the documentation for
 * that failure lives. Adding a type is a deliberate, reviewable act.
 */
enum ProblemType: string
{
    // Authentication and authorization
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case SegregationOfDutiesConflict = 'segregation-of-duties-conflict';
    case StepUpRequired = 'step-up-authentication-required';
    case MfaEnrolmentRequired = 'mfa-enrolment-required';

    // Tenancy
    case TenantRequired = 'tenant-required';
    case EntityRequired = 'entity-required';
    case SubscriptionInactive = 'subscription-inactive';
    case TenantMigrating = 'tenant-migrating';

    // Validation
    case ValidationFailed = 'validation-failed';
    case DocumentValidationFailed = 'document-validation-failed';

    // Ledger and period state
    case PeriodNotAcceptingPostings = 'period-not-accepting-postings';
    case EntryNotBalanced = 'entry-not-balanced';
    case PostedRecordImmutable = 'posted-record-immutable';
    case ControlAccountNotPostable = 'control-account-not-postable';

    // Money
    case CurrencyNotEnabled = 'currency-not-enabled';
    case ExchangeRateUnavailable = 'exchange-rate-unavailable';

    // Concurrency and idempotency
    case IdempotencyKeyRequired = 'idempotency-key-required';
    case IdempotencyKeyConflict = 'idempotency-key-conflict';
    case StaleWrite = 'stale-write';
    case StateTransitionNotAllowed = 'state-transition-not-allowed';

    // Request shape
    case NotFound = 'not-found';
    case FilterNotAllowed = 'filter-not-allowed';
    case RateLimited = 'rate-limited';

    case ServerError = 'server-error';

    public function uri(): string
    {
        return 'https://peakflow.app/problems/'.$this->value;
    }

    /** The default status. A call site may narrow it, never widen it. */
    public function status(): int
    {
        return match ($this) {
            self::Unauthenticated => 401,

            self::Forbidden,
            self::SegregationOfDutiesConflict,
            self::StepUpRequired,
            self::MfaEnrolmentRequired,
            self::ControlAccountNotPostable => 403,

            self::TenantRequired,
            self::EntityRequired,
            self::FilterNotAllowed => 400,

            self::NotFound => 404,

            self::SubscriptionInactive => 402,

            self::IdempotencyKeyRequired,
            self::ValidationFailed,
            self::DocumentValidationFailed,
            self::EntryNotBalanced,
            self::CurrencyNotEnabled,
            self::ExchangeRateUnavailable => 422,

            self::IdempotencyKeyConflict,
            self::StateTransitionNotAllowed,
            self::PostedRecordImmutable => 409,

            self::StaleWrite => 412,

            self::PeriodNotAcceptingPostings => 423,

            self::RateLimited => 429,

            self::TenantMigrating => 503,

            self::ServerError => 500,
        };
    }

    /**
     * The title. Short, stable, and NOT localized - `title` is part of the
     * machine-readable contract. Localized text goes in `detail`.
     */
    public function title(): string
    {
        return match ($this) {
            self::Unauthenticated => 'Authentication is required',
            self::Forbidden => 'Not permitted',
            self::SegregationOfDutiesConflict => 'Segregation of duties conflict',
            self::StepUpRequired => 'Re-authentication is required for this action',
            self::MfaEnrolmentRequired => 'A second factor must be enrolled first',
            self::TenantRequired => 'A tenant must be identified',
            self::EntityRequired => 'An entity must be identified',
            self::SubscriptionInactive => 'The subscription is not active',
            self::TenantMigrating => 'The workspace is being migrated',
            self::ValidationFailed => 'The request could not be validated',
            self::DocumentValidationFailed => 'The document does not satisfy its business rules',
            self::PeriodNotAcceptingPostings => 'The accounting period does not accept postings',
            self::EntryNotBalanced => 'The journal entry does not balance',
            self::PostedRecordImmutable => 'A posted record cannot be changed',
            self::ControlAccountNotPostable => 'A control account cannot be posted to directly',
            self::CurrencyNotEnabled => 'The currency is not enabled for this entity',
            self::ExchangeRateUnavailable => 'No exchange rate is available',
            self::IdempotencyKeyRequired => 'An Idempotency-Key header is required',
            self::IdempotencyKeyConflict => 'The Idempotency-Key was reused with a different body',
            self::StaleWrite => 'The record has changed since it was read',
            self::StateTransitionNotAllowed => 'That state transition is not allowed',
            self::NotFound => 'Not found',
            self::FilterNotAllowed => 'That filter or sort field is not permitted',
            self::RateLimited => 'Too many requests',
            self::ServerError => 'Something went wrong',
        };
    }
}
