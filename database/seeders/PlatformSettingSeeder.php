<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Platform\Models\PlatformSetting;
use Illuminate\Database\Seeder;

/**
 * Platform-wide settings, including the risk ceilings - PF-036.
 *
 * ## A ceiling is a bound on what a TENANT may configure
 *
 * An ordinary setting is a default. A ceiling is different in kind: it is the
 * furthest a customer is allowed to go, and no amount of configuration on
 * their side moves it.
 *
 * They exist because some settings are only safe within a range, and the
 * person adjusting them is not the person who carries the consequence. A
 * customer who sets their segregation-of-duties override expiry to ten years
 * has not configured a control, they have removed one - and they will do it
 * at 5pm on the day it blocks something urgent.
 *
 * ## A ceiling nobody enforces is a comment
 *
 * Each of these is read by code that refuses, and the key names the thing
 * that reads it. Adding a ceiling here without a reader would be worse than
 * not having it, because the console would then display a limit that does not
 * limit anything.
 */
final class PlatformSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key' => 'ceiling.sod_override_days',
                'type' => 'int',
                'value' => '30',
                'is_ceiling' => true,
                'description_ar' => 'أقصى مدة يمكن منح استثناء الفصل بين المهام لها، بالأيام.',
                'description_en' => 'The longest a segregation-of-duties override may be granted for, in days. '.
                    'Read by SodEngine::grantOverride, which refuses anything longer.',
            ],
            [
                'key' => 'ceiling.step_up_lifetime_seconds',
                'type' => 'int',
                'value' => '900',
                'is_ceiling' => true,
                'description_ar' => 'أقصى مدة صلاحية إثبات إعادة التحقق قبل انتهائه.',
                'description_en' => 'The longest a step-up assertion may remain usable. A longer window means a '.
                    'stolen session stays useful for longer.',
            ],
            [
                'key' => 'ceiling.session_absolute_minutes',
                'type' => 'int',
                'value' => '720',
                'is_ceiling' => true,
                'description_ar' => 'أقصى عمر مطلق للجلسة، بالدقائق، مهما كان نشاط المستخدم.',
                'description_en' => 'The longest a single authentication may be trusted, however active the user '.
                    'is. Not refreshed by activity.',
            ],
            [
                'key' => 'ceiling.recovery_codes',
                'type' => 'int',
                'value' => '10',
                'is_ceiling' => true,
                'description_ar' => 'أقصى عدد رموز الاسترداد التي يمكن إصدارها لمستخدم واحد.',
                'description_en' => 'The most recovery codes one user may hold. More codes means more chances '.
                    'for one to be written down somewhere.',
            ],
            [
                'key' => 'support.email',
                'type' => 'string',
                'value' => '',
                'is_ceiling' => false,
                'description_ar' => 'عنوان الدعم الظاهر على صفحات التعذر.',
                'description_en' => 'The support address shown on the pages that explain why someone cannot '.
                    'proceed. Blank hides the line - an address nobody reads is worse than none.',
            ],
        ];

        foreach ($settings as $setting) {
            PlatformSetting::query()->updateOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
