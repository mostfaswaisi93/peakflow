<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Platform\Enums\PlanFeature as F;
use App\Platform\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The plans.
 *
 * Note what is NOT gated: the ledger, invoicing, banking and reconciliation
 * core is in every plan. A financial system that lets you issue invoices but
 * not reconcile them is not a cheaper product, it is a broken one - so the
 * tiers differ on scale, automation and jurisdictions, never on correctness.
 */
final class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'key' => 'starter',
                'name_ar' => 'المبتدئ',
                'name_en' => 'Starter',
                'features' => [F::EInvoicing->value],
                'max_entities' => 1,
                'max_users' => 3,
                'max_documents_per_month' => 200,
                'jurisdictions' => ['generic'],
                'sort_order' => 10,
            ],
            [
                'key' => 'business',
                'name_ar' => 'الأعمال',
                'name_en' => 'Business',
                'features' => [
                    F::EInvoicing->value, F::ApiAccess->value, F::Webhooks->value,
                    F::BankFeeds->value, F::PaymentRuns->value,
                    F::ApprovalWorkflows->value, F::RevenueRecognition->value,
                ],
                'max_entities' => 3,
                'max_users' => 15,
                'max_documents_per_month' => 5000,
                'jurisdictions' => ['generic', 'peppol', 'jofotara'],
                'sort_order' => 20,
            ],
            [
                'key' => 'group',
                'name_ar' => 'المجموعات',
                'name_en' => 'Group',
                'features' => F::values(),
                'max_entities' => 25,
                'max_users' => 200,
                'max_documents_per_month' => null,
                'jurisdictions' => ['generic', 'peppol', 'zatca', 'facturx', 'eta', 'jofotara'],
                'sort_order' => 30,
            ],
        ];

        foreach ($plans as $attributes) {
            Plan::query()->updateOrCreate(['key' => $attributes['key']], $attributes);
        }
    }
}
