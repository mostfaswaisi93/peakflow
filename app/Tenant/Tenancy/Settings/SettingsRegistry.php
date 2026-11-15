<?php

declare(strict_types=1);

namespace App\Tenant\Tenancy\Settings;

use App\Tenant\Tenancy\Settings\Exceptions\SettingRejected;

/**
 * Every setting a tenant may decide - PF-074.
 *
 * ## One list, in one file, reviewable in a diff
 *
 * Settings could be registered at runtime by each module, and that would be
 * tidier and worse: the complete set would exist only at runtime, so "what
 * can a tenant change" would be answerable by starting the application and
 * not by reading anything. Adding a risk-bearing setting would be invisible
 * in review.
 *
 * So the list is here, grouped by the module that reads each setting, and a
 * new one is a visible line in a pull request - the same reasoning as
 * `deptrac.yaml` holding the module boundaries rather than each module
 * declaring its own.
 *
 * ## An unknown key is refused, not created
 *
 * A created key looks saved, is read by nothing, and the behaviour somebody
 * wanted never changes. That failure has no symptom at all until somebody
 * asks why the dunning schedule did not change.
 *
 * ## What is NOT here
 *
 * Anything a tenant may not legitimately decide. Session lifetimes, override
 * windows and MFA requirements are the operator's, enforced as platform
 * ceilings, and a setting that merely *reads* a ceiling would invite the
 * question of why it cannot be raised.
 */
final class SettingsRegistry
{
    /** @var array<string, SettingDefinition>|null */
    private static ?array $definitions = null;

    /** @return array<string, SettingDefinition> */
    public static function all(): array
    {
        return self::$definitions ??= self::build();
    }

    /**
     * @throws SettingRejected
     */
    public static function get(string $key): SettingDefinition
    {
        return self::all()[$key] ?? throw SettingRejected::unknownKey($key, array_keys(self::all()));
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /**
     * @param  'tenant'|'entity'  $scope
     * @return array<string, SettingDefinition>
     */
    public static function forScope(string $scope): array
    {
        return array_filter(
            self::all(),
            static fn (SettingDefinition $definition): bool => $definition->scope === $scope,
        );
    }

    /** Settings whose value is bounded by a platform ceiling. */
    public static function riskBearing(): array
    {
        return array_filter(
            self::all(),
            static fn (SettingDefinition $definition): bool => $definition->bearsRisk(),
        );
    }

    /** @return array<string, SettingDefinition> */
    private static function build(): array
    {
        $definitions = [];

        foreach (self::declarations() as $definition) {
            $definitions[$definition->key] = $definition;
        }

        return $definitions;
    }

    /** @return list<SettingDefinition> */
    private static function declarations(): array
    {
        return [
            // ---- Money and rounding ---------------------------------------

            /*
             | The write-off tolerance: the largest residual a payment
             | allocation may write off without an approval.
             |
             | Risk-bearing, and the clearest example of why ceilings exist.
             | A tolerance of 5.00 absorbs rounding; a tolerance of 5,000.00
             | absorbs theft, one invoice at a time, and every individual
             | write-off looks like housekeeping.
             */
            new SettingDefinition(
                key: 'money.writeoff_tolerance',
                type: 'decimal',
                scope: 'entity',
                default: '1.00',
                descriptionAr: 'أكبر فرق متبقٍ يمكن إعدامه عند تسوية دفعة دون موافقة.',
                descriptionEn: 'The largest residual a payment allocation may write off without approval.',
                ceilingKey: 'ceiling.writeoff_tolerance',
                ceilingDirection: 'at_most',
                min: '0',
            ),

            new SettingDefinition(
                key: 'money.rounding_mode',
                type: 'string',
                scope: 'entity',
                default: 'half_up',
                descriptionAr: 'طريقة التقريب المستخدمة عند العرض والسداد.',
                descriptionEn: 'The rounding applied for presentation and payment.',
                allowed: ['half_up', 'half_even', 'down'],
            ),

            // ---- Foreign exchange -----------------------------------------

            new SettingDefinition(
                key: 'fx.default_rate_set',
                type: 'string',
                scope: 'entity',
                default: 'market',
                descriptionAr: 'مجموعة أسعار الصرف المستخدمة افتراضياً للمعاملات.',
                descriptionEn: 'The rate set used by default for transactions.',
                allowed: ['market', 'monthly_average', 'statutory'],
            ),

            new SettingDefinition(
                key: 'fx.fallback_window_days',
                type: 'int',
                scope: 'tenant',
                default: 30,
                descriptionAr: 'إلى أي مدى يُسمح باستخدام آخر سعر معروف عند انقطاع المغذّي.',
                descriptionEn: 'How far back the last known rate may be used when a feed is down.',
                ceilingKey: 'ceiling.fx_fallback_window_days',
                ceilingDirection: 'at_most',
                min: '1',
            ),

            // ---- Dunning ---------------------------------------------------

            // ---- The ledger -----------------------------------------------

            /*
             | Period 13, which is optional and defaults to ON - PF-051.
             |
             | It carries no calendar days: it starts and ends on the fiscal
             | year's last day. Without it, an auditor's adjustment has to be
             | re-dated into December, and afterwards nothing distinguishes
             | "what the business recorded in December" from "what the audit
             | changed afterwards" - which is the comparison every subsequent
             | year's review starts from.
             |
             | Defaulting to on, because the cost of having it and not using
             | it is an empty period, and the cost of not having it is a
             | December that is not what December was.
             */
            new SettingDefinition(
                key: 'ledger.adjusting_period',
                type: 'bool',
                scope: 'entity',
                default: true,
                descriptionAr: 'إنشاء فترة تسوية (الفترة ١٣) لكل سنة مالية، لا تحمل أيامًا تقويمية، '.
                    'لتقع قيود التسوية بعد الشهر الأخير دون إعادة تأريخها فيه.',
                descriptionEn: 'Create an adjusting period (period 13) for each fiscal year, carrying no '.
                    'calendar days, so audit adjustments land after the last month rather than being '.
                    're-dated into it.',
            ),

            new SettingDefinition(
                key: 'dunning.enabled',
                type: 'bool',
                scope: 'entity',
                default: true,
                descriptionAr: 'إرسال مطالبات تلقائية للفواتير المتأخرة.',
                descriptionEn: 'Send automatic reminders for overdue invoices.',
            ),

            new SettingDefinition(
                key: 'dunning.schedule_days',
                type: 'json',
                scope: 'entity',
                default: '[7,14,30,60]',
                descriptionAr: 'الأيام بعد تاريخ الاستحقاق التي تُرسل فيها المطالبات.',
                descriptionEn: 'Days after the due date on which reminders are sent.',
            ),

            /*
             | How many reminders may go out before a human has to look.
             |
             | Capped by the platform because an unbounded escalation sends a
             | customer fourteen letters about an invoice they have disputed,
             | and the reputational cost lands on the operator as much as on
             | the tenant.
             */
            new SettingDefinition(
                key: 'dunning.max_escalations',
                type: 'int',
                scope: 'entity',
                default: 4,
                descriptionAr: 'عدد مراحل التصعيد قبل أن يتدخل شخص.',
                descriptionEn: 'How many escalation steps run before a person must intervene.',
                ceilingKey: 'ceiling.dunning_escalations',
                ceilingDirection: 'at_most',
                min: '1',
            ),

            // ---- Credit control --------------------------------------------

            new SettingDefinition(
                key: 'credit.block_on_limit_breach',
                type: 'bool',
                scope: 'entity',
                default: true,
                descriptionAr: 'منع إصدار فواتير جديدة عند تجاوز حدّ الائتمان.',
                descriptionEn: 'Block new invoices when the credit limit is exceeded.',
            ),

            new SettingDefinition(
                key: 'credit.include_uninvoiced_orders',
                type: 'bool',
                scope: 'entity',
                default: true,
                descriptionAr: 'احتساب الطلبات غير المفوترة ضمن التعرض الائتماني.',
                descriptionEn: 'Count uninvoiced orders towards credit exposure.',
            ),

            // ---- Bank reconciliation ---------------------------------------

            /*
             | How far apart an amount and a statement line may be and still
             | be matched automatically.
             |
             | Risk-bearing for the same reason as the write-off tolerance: a
             | generous threshold makes reconciliation effortless and makes a
             | small persistent discrepancy invisible.
             */
            new SettingDefinition(
                key: 'banking.auto_match_tolerance',
                type: 'decimal',
                scope: 'entity',
                default: '0.50',
                descriptionAr: 'أكبر فرق يُسمح به للمطابقة التلقائية مع كشف الحساب.',
                descriptionEn: 'The largest difference an automatic statement match may absorb.',
                ceilingKey: 'ceiling.auto_match_tolerance',
                ceilingDirection: 'at_most',
                min: '0',
            ),

            /*
             | A percentage tolerance beside the absolute one.
             |
             | Both, because they catch different things: a flat 0.50
             | absorbs a bank's rounding on a small payment and is useless on
             | a large one, while 0.1% is proportionate and is far too
             | generous on a small payment. Real matching rules use whichever
             | is SMALLER.
             |
             | Bounded at 0.05 - five per cent - which is also what makes
             | the fraction-versus-percentage mistake catchable: somebody
             | typing 5 meaning five per cent is told so, rather than
             | configuring a tolerance a hundred times wider than they meant.
             */
            new SettingDefinition(
                key: 'banking.auto_match_ratio',
                type: 'decimal',
                scope: 'entity',
                default: '0.001',
                descriptionAr: 'نسبة الفرق المسموح بها للمطابقة التلقائية، كجزء من واحد.',
                descriptionEn: 'The proportion of the amount an automatic match may absorb, as a fraction of one.',
                min: '0',
                max: '0.05',
            ),

            new SettingDefinition(
                key: 'banking.auto_match_day_window',
                type: 'int',
                scope: 'entity',
                default: 3,
                descriptionAr: 'عدد الأيام حول تاريخ العملية للمطابقة التلقائية.',
                descriptionEn: 'How many days either side of a transaction date an automatic match may look.',
                min: '0',
                max: '30',
            ),

            // ---- Documents and delivery -------------------------------------

            new SettingDefinition(
                key: 'documents.default_locale',
                type: 'string',
                scope: 'entity',
                default: 'ar',
                descriptionAr: 'لغة المستندات حين لا يحدد المستلم لغة.',
                descriptionEn: "The language documents use when the recipient's own is unknown.",
                allowed: ['ar', 'en'],
            ),

            new SettingDefinition(
                key: 'documents.delivery_channel',
                type: 'string',
                scope: 'entity',
                default: 'email',
                descriptionAr: 'قناة التسليم الافتراضية للمستندات.',
                descriptionEn: 'The default delivery channel for documents.',
                allowed: ['email', 'portal', 'print', 'peppol', 'none'],
            ),

            // ---- Numbering --------------------------------------------------

            new SettingDefinition(
                key: 'numbering.invoice_format',
                type: 'string',
                scope: 'entity',
                default: 'INV-{YYYY}/{seq:5}',
                descriptionAr: 'قالب ترقيم الفواتير. يُجمَّد بعد إصدار أول فاتورة.',
                descriptionEn: 'The invoice numbering template. Frozen once the first invoice is issued.',
            ),

            // ---- Jurisdictions ----------------------------------------------

            new SettingDefinition(
                key: 'tax.enabled_jurisdictions',
                type: 'json',
                scope: 'entity',
                default: '["SA"]',
                descriptionAr: 'الدول التي تعمل هذه الشركة تحت أنظمتها الضريبية.',
                descriptionEn: 'The jurisdictions whose tax rules this company operates under.',
            ),

            // ---- Period control ---------------------------------------------

            /*
             | Whether a closed period may be reopened at all.
             |
             | A tenant may switch it OFF and may not switch it on past the
             | platform's own limit on how long a reopening stays possible -
             | reopening a period from three years ago restates a filed
             | return, and the person asking is rarely the person who will
             | explain it to the authority.
             */
            new SettingDefinition(
                key: 'periods.reopen_allowed',
                type: 'bool',
                scope: 'entity',
                default: true,
                descriptionAr: 'إمكانية إعادة فتح فترة مغلقة.',
                descriptionEn: 'Whether a closed period may be reopened.',
            ),

            new SettingDefinition(
                key: 'periods.reopen_window_days',
                type: 'int',
                scope: 'entity',
                default: 90,
                descriptionAr: 'المدة التي تبقى فيها الفترة المغلقة قابلة لإعادة الفتح.',
                descriptionEn: 'How long a closed period remains reopenable.',
                ceilingKey: 'ceiling.period_reopen_days',
                ceilingDirection: 'at_most',
                min: '0',
            ),
        ];
    }
}
