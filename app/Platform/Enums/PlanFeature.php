<?php

declare(strict_types=1);

namespace App\Platform\Enums;

/**
 * What a plan switches on.
 *
 * A disabled feature is refused with an UPGRADE MESSAGE, never a 403 - a 403
 * reads as a bug, and a user who cannot tell "you may not" from "that is
 * broken" opens a support ticket either way.
 *
 * The ledger, invoicing and banking core is deliberately NOT here: a financial
 * system that lets you issue invoices but not reconcile them is not a cheaper
 * product, it is a broken one.
 */
enum PlanFeature: string
{
    case MultiEntity = 'multi_entity';
    case Consolidation = 'consolidation';
    case ApiAccess = 'api_access';
    case Webhooks = 'webhooks';
    case EInvoicing = 'e_invoicing';
    case BankFeeds = 'bank_feeds';
    case PaymentRuns = 'payment_runs';
    case DirectDebit = 'direct_debit';
    case ApprovalWorkflows = 'approval_workflows';
    case Budgets = 'budgets';
    case RevenueRecognition = 'revenue_recognition';
    case EclProvisioning = 'ecl_provisioning';
    case CustomReports = 'custom_reports';
    case AuditorAccess = 'auditor_access';

    public function label(): string
    {
        return match ($this) {
            self::MultiEntity => __('تعدد الكيانات'),
            self::Consolidation => __('التوحيد المحاسبي'),
            self::ApiAccess => __('واجهة برمجية'),
            self::Webhooks => __('إشعارات الويب'),
            self::EInvoicing => __('الفاتورة الإلكترونية'),
            self::BankFeeds => __('ربط البنوك'),
            self::PaymentRuns => __('دفعات السداد المجمعة'),
            self::DirectDebit => __('الخصم المباشر'),
            self::ApprovalWorkflows => __('مسارات الموافقات'),
            self::Budgets => __('الموازنات'),
            self::RevenueRecognition => __('الاعتراف بالإيراد'),
            self::EclProvisioning => __('مخصصات الخسائر الائتمانية'),
            self::CustomReports => __('تقارير مخصصة'),
            self::AuditorAccess => __('وصول المدقق'),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
