<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * The registry of RFC 9457 problem types - PF-219.
 *
 * An error `type` is a CONTRACT a client can switch on, so it is declared here
 * rather than invented at the call site. A client that has to parse `detail`
 * to tell a closed period from a missing permission has no contract at all.
 *
 * The URI is stable and dereferenceable - it is where the documentation for
 * that failure lives. Adding a type is a deliberate, reviewable act.
 *
 * ## What is localized, and what must never be
 *
 * `type` and `title` are the machine-readable halves. A client that switched
 * on them would break the first time somebody sent `Accept-Language: ar`, and
 * it would break by taking the wrong branch rather than by failing - so they
 * are ASCII, fixed, and asserted to be.
 *
 * `detail` is for the person who will read the failure, and it is localized.
 * It is held here as a pair of sentences rather than as a catalogue key
 * because every one of them describes a condition this enum already names:
 * splitting the type from the sentence that explains it puts half the registry
 * in a translation file, where a reviewer asking "what does this type mean"
 * cannot see it.
 *
 * ## Each type is also documented in prose
 *
 * `docs/api/problems.md` is the published registry an integrator reads, and
 * `ApiContractRules` asserts it names every case here with the status it
 * carries. A type that is in the code and not in the document is a failure a
 * client cannot handle; one in the document and not in the code is a branch
 * somebody wrote and will never reach.
 */
enum ProblemType: string
{
    // Authentication and authorization
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case SegregationOfDutiesConflict = 'segregation-of-duties-conflict';
    case StepUpRequired = 'step-up-authentication-required';
    case MfaEnrolmentRequired = 'mfa-enrolment-required';
    case TokenScopeMissing = 'token-scope-missing';

    // Tenancy
    case TenantRequired = 'tenant-required';
    case EntityRequired = 'entity-required';
    case EntityNotPermitted = 'entity-not-permitted';

    /*
     | A SESSION in an entity the signed-in user holds no role in - PF-026.
     |
     | Separate from `entity-not-permitted`, which is the token's refusal,
     | because the two have different audiences and different remedies. A
     | machine is told which entities its token names; a person is told they
     | hold no role in that company and that somebody has to grant them one.
     |
     | `EntityNotInScope::notPermitted()` rendered as `entity-required` - a
     | 400 - so "you hold no role here" arrived as "you did not say which
     | entity", which sends the reader to check a header that was correct.
     */
    case EntityNotYours = 'entity-not-yours';
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
    case IdempotencyKeyInFlight = 'idempotency-key-in-flight';
    case StaleWrite = 'stale-write';
    case StateTransitionNotAllowed = 'state-transition-not-allowed';

    // Request shape
    case NotFound = 'not-found';
    case FilterNotAllowed = 'filter-not-allowed';
    case CursorNotReadable = 'cursor-not-readable';
    case PageNotReadable = 'page-not-readable';
    case PageSizeNotAllowed = 'page-size-not-allowed';
    case OffsetTooDeep = 'offset-too-deep';
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
            self::TokenScopeMissing,
            self::EntityNotPermitted,
            self::EntityNotYours,
            self::ControlAccountNotPostable => 403,

            self::TenantRequired,
            self::EntityRequired,
            self::FilterNotAllowed,
            self::CursorNotReadable,
            self::PageNotReadable,
            self::PageSizeNotAllowed,
            self::OffsetTooDeep => 400,

            self::NotFound => 404,

            self::SubscriptionInactive => 402,

            self::IdempotencyKeyRequired,
            self::ValidationFailed,
            self::DocumentValidationFailed,
            self::EntryNotBalanced,
            self::CurrencyNotEnabled,
            self::ExchangeRateUnavailable => 422,

            self::IdempotencyKeyConflict,
            self::IdempotencyKeyInFlight,
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
            self::TokenScopeMissing => 'The token does not carry this scope',
            self::TenantRequired => 'A tenant must be identified',
            self::EntityRequired => 'An entity must be identified',
            self::EntityNotPermitted => 'This token may not act in that entity',
            self::EntityNotYours => 'You hold no role in that company',
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
            self::IdempotencyKeyInFlight => 'The first request with this Idempotency-Key is still running',
            self::StaleWrite => 'The record has changed since it was read',
            self::StateTransitionNotAllowed => 'That state transition is not allowed',
            self::NotFound => 'Not found',
            self::FilterNotAllowed => 'That filter or sort field is not permitted',
            self::CursorNotReadable => 'That cursor could not be read',
            self::PageNotReadable => 'That page number could not be read',
            self::PageSizeNotAllowed => 'That page size is not one of the permitted sizes',
            self::OffsetTooDeep => 'That page number is beyond the offset limit',
            self::RateLimited => 'Too many requests',
            self::ServerError => 'Something went wrong',
        };
    }

    /**
     * What a person should read, in their own language - PF-219.
     *
     * The default sentence for the condition, used when a call site has
     * nothing more specific to say. A call site that DOES - the account code
     * that was refused, the amount an entry is out by - overrides it with
     * {@see Problem::withDetail()}, because a figure beats a category every
     * time.
     *
     * Every sentence says what to do next. "Not permitted" sends an
     * integrator to read our documentation; "this token carries invoice.draft
     * and the route needs invoice.issue" sends them to their own token
     * settings, which is where the fix is.
     */
    public function detail(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'ar' ? $this->detailAr() : $this->detailEn();
    }

    private function detailEn(): string
    {
        return match ($this) {
            self::Unauthenticated => 'Send a bearer token in the Authorization header. The API accepts no other credential.',
            self::Forbidden => 'The user behind this token holds no permission for this action in this entity.',
            self::SegregationOfDutiesConflict => 'The same person already performed the duty that conflicts with this one.',
            self::StepUpRequired => 'This action needs a fresh re-authentication, which a long-lived token cannot provide.',
            self::MfaEnrolmentRequired => 'The user behind this token must enrol a second factor before holding this permission.',
            self::TokenScopeMissing => 'This token was issued without the scope this route needs. Issue a new token with it; a live token is never widened.',
            self::TenantRequired => 'Send the workspace in the X-Tenant header. It is resolved before authentication, so it cannot be guessed from the token.',
            self::EntityRequired => 'Send the legal entity in the X-Entity header. Every figure in this product belongs to exactly one.',
            self::EntityNotPermitted => 'This token names the entities it may act in, and that is not one of them.',
            self::EntityNotYours => 'Access is granted per company: a role in one company of a group grants nothing in another. Ask an administrator of that company to grant you one.',
            self::SubscriptionInactive => 'This workspace is not currently entitled to be served.',
            self::TenantMigrating => 'This workspace is being upgraded. Retry after the interval in Retry-After.',
            self::ValidationFailed => 'One or more fields could not be accepted. Each is addressed by a JSON pointer in errors.',
            self::DocumentValidationFailed => 'The document breaks one or more business rules. Each is addressed by its EN 16931 business term.',
            self::PeriodNotAcceptingPostings => 'The period this date falls in has been closed. A closed period has been reported on.',
            self::EntryNotBalanced => 'The lines do not sum to zero in the functional currency. The amount they are out by is in detail.',
            self::PostedRecordImmutable => 'A posted entry is corrected by a reversal, never by an edit. The original stays visible.',
            self::ControlAccountNotPostable => 'That account is the total of a subledger. Post to the counter account, or correct the document that created the balance.',
            self::CurrencyNotEnabled => 'Enable the currency for this entity first, rather than converting at a rate nobody chose.',
            self::ExchangeRateUnavailable => 'No rate is available for that pair on that date, and one will not be invented.',
            self::IdempotencyKeyRequired => 'Send a unique Idempotency-Key header. A write that posts to the ledger must not be callable twice by accident.',
            self::IdempotencyKeyConflict => 'This key was already used with a different request. Use a new key, or send the original request again unchanged.',
            self::IdempotencyKeyInFlight => 'The first request with this key has not finished. Retry after the interval in Retry-After rather than sending it again now.',
            self::StaleWrite => 'The record changed since it was read. The current representation is in the body.',
            self::StateTransitionNotAllowed => 'The record is not in a state this operation can be applied to.',
            self::NotFound => 'No such record is visible to this token.',
            self::FilterNotAllowed => 'That field cannot be filtered or sorted on. The permitted fields are listed in errors.',
            self::CursorNotReadable => 'A cursor is opaque and signed. Send one this API returned, unaltered.',
            self::PageNotReadable => 'A page number is a whole number of 1 or more. Coercing anything else would answer with page one for ever.',
            self::PageSizeNotAllowed => 'Ask for one of the permitted page sizes, which are listed in errors.',
            self::OffsetTooDeep => 'Offset pagination stops at the documented page. Use the cursor for anything deeper.',
            self::RateLimited => 'Too many requests on this token. Retry after the interval in Retry-After.',
            self::ServerError => 'The request failed on our side. Nothing was half-saved; quote the correlation id when reporting it.',
        };
    }

    private function detailAr(): string
    {
        return match ($this) {
            self::Unauthenticated => 'أرسل رمزًا من نوع bearer في ترويسة Authorization. لا تقبل الواجهة أي اعتماد آخر.',
            self::Forbidden => 'المستخدم صاحب هذا الرمز لا يملك صلاحية هذا الإجراء في هذا الكيان.',
            self::SegregationOfDutiesConflict => 'الشخص نفسه نفّذ من قبل المهمة التي تتعارض مع هذه.',
            self::StepUpRequired => 'هذا الإجراء يحتاج إعادة توثيق لحظية، ولا يمكن لرمز طويل الأجل أن يقدّمها.',
            self::MfaEnrolmentRequired => 'على المستخدم صاحب هذا الرمز تسجيل عامل ثانٍ قبل أن يحمل هذه الصلاحية.',
            self::TokenScopeMissing => 'أُصدر هذا الرمز دون النطاق الذي يطلبه هذا المسار. أصدر رمزًا جديدًا يحمله؛ الرمز القائم لا يُوسَّع أبدًا.',
            self::TenantRequired => 'أرسل مساحة العمل في ترويسة X-Tenant. تُحَل قبل التوثيق، فلا يمكن استنتاجها من الرمز.',
            self::EntityRequired => 'أرسل الكيان القانوني في ترويسة X-Entity. كل رقم في هذا النظام يعود إلى كيان واحد بعينه.',
            self::EntityNotPermitted => 'هذا الرمز يسمّي الكيانات التي يعمل فيها، وهذا ليس أحدها.',
            self::EntityNotYours => 'الوصول يُمنح لكل شركة على حدة: دورٌ في شركة من المجموعة لا يمنح شيئًا في أخرى. اطلب من مسؤول تلك الشركة أن يمنحك دورًا.',
            self::SubscriptionInactive => 'مساحة العمل هذه غير مستحقة للخدمة حاليًا.',
            self::TenantMigrating => 'يجري ترقية مساحة العمل. أعد المحاولة بعد المدة المذكورة في Retry-After.',
            self::ValidationFailed => 'تعذّر قبول حقل أو أكثر. كل حقل مُعنوَن بمؤشّر JSON في errors.',
            self::DocumentValidationFailed => 'المستند يخالف قاعدة عمل أو أكثر. كل قاعدة مُعنوَنة بمصطلح الأعمال في EN 16931.',
            self::PeriodNotAcceptingPostings => 'الفترة التي يقع فيها هذا التاريخ مُقفلة، والفترة المقفلة قد صُدرت عنها تقارير.',
            self::EntryNotBalanced => 'مجموع السطور لا يساوي صفرًا بالعملة الوظيفية. مقدار الفرق مذكور في detail.',
            self::PostedRecordImmutable => 'القيد المرحَّل يُصحَّح بقيد عكسي لا بالتعديل، ويبقى الأصل ظاهرًا.',
            self::ControlAccountNotPostable => 'هذا الحساب هو مجموع دفتر فرعي. رحِّل إلى الحساب المقابل، أو عدِّل المستند الذي أنشأ الرصيد.',
            self::CurrencyNotEnabled => 'فعّل العملة لهذا الكيان أولًا بدل تحويلها بسعر لم يختره أحد.',
            self::ExchangeRateUnavailable => 'لا يوجد سعر صرف لهذا الزوج في هذا التاريخ، ولن يُفترض سعر.',
            self::IdempotencyKeyRequired => 'أرسل ترويسة Idempotency-Key فريدة. ما يُرحَّل إلى الدفتر لا يجوز أن يُنفَّذ مرتين بالخطأ.',
            self::IdempotencyKeyConflict => 'استُخدم هذا المفتاح مع طلب مختلف. استخدم مفتاحًا جديدًا، أو أعد إرسال الطلب الأصلي كما هو.',
            self::IdempotencyKeyInFlight => 'لم ينتهِ الطلب الأول بهذا المفتاح بعد. أعد المحاولة بعد المدة المذكورة في Retry-After بدل إرساله الآن.',
            self::StaleWrite => 'تغيّر السجل بعد قراءته. التمثيل الحالي موجود في جسم الرد.',
            self::StateTransitionNotAllowed => 'السجل ليس في حالة تسمح بتطبيق هذه العملية.',
            self::NotFound => 'لا يوجد سجل بهذا المعرّف ظاهر لهذا الرمز.',
            self::FilterNotAllowed => 'لا يمكن التصفية أو الترتيب بهذا الحقل. الحقول المسموحة مذكورة في errors.',
            self::CursorNotReadable => 'المؤشّر مُعتم وموقَّع. أرسل مؤشّرًا أعادته هذه الواجهة، دون تعديل.',
            self::PageNotReadable => 'رقم الصفحة عدد صحيح من 1 أو أكثر. تحويل أي شيء آخر يعني إعادة الصفحة الأولى إلى الأبد.',
            self::PageSizeNotAllowed => 'اطلب أحد أحجام الصفحات المسموحة، وهي مذكورة في errors.',
            self::OffsetTooDeep => 'ترقيم الصفحات بالإزاحة يتوقف عند الصفحة الموثَّقة. استخدم المؤشّر لما بعدها.',
            self::RateLimited => 'طلبات كثيرة على هذا الرمز. أعد المحاولة بعد المدة المذكورة في Retry-After.',
            self::ServerError => 'فشل الطلب عندنا. لم يُحفظ شيء نصف حفظ؛ اذكر معرّف الارتباط عند الإبلاغ.',
        };
    }
}
