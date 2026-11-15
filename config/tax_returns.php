<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Return forms and their boxes - PF-085
|--------------------------------------------------------------------------
|
| A return is a form with numbered boxes, and a tax code has to say which box
| it lands in. Without that mapping the engine can total tax correctly and
| still produce nothing anybody can submit: an authority's portal takes figures
| per box, and "total output tax" is not a box on any form.
|
| ## The boxes are data, with their source
|
| A form changes. When it does, the change is a row here and a version bump,
| not a migration and a deploy - and the mapping on each tax code says which
| box it moved to.
|
| ## `side` is what makes a box checkable
|
|   output  tax the business owes on its sales
|   input   tax it is reclaiming on its purchases
|   net     a computed total, which no tax code maps to directly
|   memo    a figure the form asks for that is not part of the arithmetic
|
| PF-088 reconciles the movement on the output and input control accounts
| against the boxes that carry each side. A box with no side could not be
| reconciled against anything, so it is required.
|
| ## Numbering is marked unverified where it could not be checked here
|
| The structure of each form - which figures it asks for, and in what order -
| is from the published return. The exact box NUMBERS could not be verified in
| this environment, and a guessed number is worse than an unnumbered box: it
| reads as checked, and a figure in the wrong box is a mis-statement the
| authority sees before we do.
|
*/

return [

    'returns' => [

        'generic' => [
            'name_en' => 'VAT return',
            'name_ar' => 'إقرار ضريبة القيمة المضافة',
            'source' => 'EN 16931 category semantics mapped onto a minimal return; not a statutory form',
            'boxes' => [
                'standard_sales' => ['side' => 'output', 'en' => 'Standard rated sales', 'ar' => 'المبيعات الخاضعة للنسبة الأساسية'],
                'zero_sales' => ['side' => 'output', 'en' => 'Zero rated sales', 'ar' => 'المبيعات الخاضعة لنسبة صفر'],
                'exempt_sales' => ['side' => 'output', 'en' => 'Exempt sales', 'ar' => 'المبيعات المعفاة'],
                'reverse_charge_sales' => ['side' => 'output', 'en' => 'Sales under reverse charge', 'ar' => 'المبيعات بالتحصيل العكسي'],
                'standard_purchases' => ['side' => 'input', 'en' => 'Standard rated purchases', 'ar' => 'المشتريات الخاضعة للنسبة الأساسية'],
                'reverse_charge_purchases' => ['side' => 'input', 'en' => 'Purchases under reverse charge', 'ar' => 'المشتريات بالتحصيل العكسي'],
                'net' => ['side' => 'net', 'en' => 'Net tax due', 'ar' => 'صافي الضريبة المستحقة'],
            ],
        ],

        'sa' => [
            'name_en' => 'ZATCA VAT return',
            'name_ar' => 'إقرار ضريبة القيمة المضافة - هيئة الزكاة والضريبة والجمارك',
            'source' => 'Structure from the published ZATCA VAT return; box numbering unverified here',
            'boxes' => [
                'standard_sales' => ['side' => 'output', 'en' => 'Standard rated sales', 'ar' => 'المبيعات الخاضعة للنسبة الأساسية'],
                'citizen_services' => ['side' => 'output', 'en' => 'Private healthcare and education to citizens', 'ar' => 'الرعاية الصحية والتعليم الخاص للمواطنين'],
                'zero_sales' => ['side' => 'output', 'en' => 'Zero rated domestic sales', 'ar' => 'المبيعات المحلية بنسبة صفر'],
                'exports' => ['side' => 'output', 'en' => 'Exports', 'ar' => 'الصادرات'],
                'exempt_sales' => ['side' => 'output', 'en' => 'Exempt sales', 'ar' => 'المبيعات المعفاة'],
                'standard_purchases' => ['side' => 'input', 'en' => 'Standard rated domestic purchases', 'ar' => 'المشتريات المحلية الخاضعة للنسبة الأساسية'],
                'imports_at_customs' => ['side' => 'input', 'en' => 'Imports taxed at customs', 'ar' => 'الواردات الخاضعة للضريبة في الجمارك'],
                'imports_reverse_charge' => ['side' => 'input', 'en' => 'Imports accounted for by reverse charge', 'ar' => 'الواردات الخاضعة للتحصيل العكسي'],
                'zero_purchases' => ['side' => 'input', 'en' => 'Zero rated purchases', 'ar' => 'المشتريات بنسبة صفر'],
                'exempt_purchases' => ['side' => 'input', 'en' => 'Exempt purchases', 'ar' => 'المشتريات المعفاة'],
                'corrections' => ['side' => 'memo', 'en' => 'Corrections from a previous period', 'ar' => 'تصحيحات من فترة سابقة'],
                'credit_carried_forward' => ['side' => 'memo', 'en' => 'Credit carried forward', 'ar' => 'الرصيد الدائن المرحل'],
                'net' => ['side' => 'net', 'en' => 'Net VAT due', 'ar' => 'صافي ضريبة القيمة المضافة المستحقة'],
            ],
        ],

        'jo' => [
            'name_en' => 'General sales tax return',
            'name_ar' => 'إقرار ضريبة المبيعات العامة',
            'source' => 'Structure from the published ISTD general sales tax return; box numbering unverified here',
            'boxes' => [
                'standard_sales' => ['side' => 'output', 'en' => 'Taxable sales at the general rate', 'ar' => 'المبيعات الخاضعة للنسبة العامة'],
                'special_rate_sales' => ['side' => 'output', 'en' => 'Sales at a special rate', 'ar' => 'المبيعات الخاضعة لنسبة خاصة'],
                'zero_sales' => ['side' => 'output', 'en' => 'Zero rated sales and exports', 'ar' => 'المبيعات بنسبة صفر والصادرات'],
                'exempt_sales' => ['side' => 'output', 'en' => 'Exempt sales', 'ar' => 'المبيعات المعفاة'],
                'standard_purchases' => ['side' => 'input', 'en' => 'Taxable purchases and imports', 'ar' => 'المشتريات والواردات الخاضعة'],
                'reverse_charge_purchases' => ['side' => 'input', 'en' => 'Imported services, tax accounted for by the recipient', 'ar' => 'الخدمات المستوردة التي يحتسب المستلم ضريبتها'],
                'net' => ['side' => 'net', 'en' => 'Net tax due', 'ar' => 'صافي الضريبة المستحقة'],
            ],
        ],

    ],

    /*
    |----------------------------------------------------------------------
    | Which return a jurisdiction files
    |----------------------------------------------------------------------
    |
    | Separate from the forms so that two jurisdictions sharing a form is one
    | row each rather than a duplicated definition - and so that a
    | jurisdiction with no form of its own falls back to `generic` explicitly,
    | here, where somebody can see that it does.
    */
    'by_jurisdiction' => [
        'SA' => 'sa',
        'JO' => 'jo',
    ],

    'default' => 'generic',

];
