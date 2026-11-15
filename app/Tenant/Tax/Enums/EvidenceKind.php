<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Enums;

/**
 * What has to be on record before a treatment may be claimed - PF-080.
 *
 * ## Three kinds, because three different things go wrong
 *
 * These are not paperwork categories. Each one is a specific assessment that
 * an authority raises years after the return was filed, and each has a
 * different remedy at the moment the document is issued.
 */
enum EvidenceKind: string
{
    /**
     * Proof the goods left the jurisdiction.
     *
     * A customs declaration, an export entry, a bill of lading. The single
     * most common VAT assessment there is: an export rate claimed on the
     * strength of a foreign billing address, with nothing showing the goods
     * ever moved. The customer's address is not evidence of export.
     */
    case ExportProof = 'export_proof';

    /**
     * Proof the goods reached another member state.
     *
     * Different from export proof, and the difference is the one that catches
     * people. An intra-Community supply is exempt in the state of dispatch
     * ONLY if the goods arrived somewhere else in the Community - and there is
     * no customs declaration to prove it, because there is no customs
     * frontier. A transport document, a signed delivery note, a carrier's
     * confirmation.
     */
    case DispatchProof = 'dispatch_proof';

    /**
     * A customer registration that was valid on the tax point.
     *
     * PF-079 checks it for a reverse charge; this is the same fact recorded as
     * evidence on the document, because the document has to be defensible
     * after the customer record has changed.
     */
    case CustomerRegistration = 'customer_registration';

    public function label(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::ExportProof => $arabic ? 'إثبات التصدير' : 'proof of export',
            self::DispatchProof => $arabic ? 'إثبات وصول السلع' : 'proof of dispatch',
            self::CustomerRegistration => $arabic ? 'رقم تسجيل العميل' : 'the customer\'s registration',
        };
    }

    /** What an authority accepts, for the message somebody has to act on. */
    public function examples(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::ExportProof => $arabic
                ? 'بيان جمركي أو بوليصة شحن'
                : 'a customs declaration, an export entry or a bill of lading',
            self::DispatchProof => $arabic
                ? 'مستند نقل أو إشعار تسليم موقّع'
                : 'a transport document, a signed delivery note or a carrier confirmation',
            self::CustomerRegistration => $arabic
                ? 'رقم تسجيل ضريبي سارٍ في تاريخ الاستحقاق'
                : 'a tax registration valid on the tax point date',
        };
    }
}
