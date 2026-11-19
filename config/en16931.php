<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | EN 16931 rule explanations, by rule identifier
    |---------------------------------------------------------------------------
    |
    | PF-117. A validator says
    | `/Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount`; the person
    | reading it is a bookkeeper who has never seen UBL. This table is what
    | they are shown instead, in both languages, keyed by the rule the
    | recipient's own access point would quote - so one defect reads as one
    | defect wherever it is reported.
    |
    | These are explanations, not the standard's formal wording. The formal
    | wording is in EN 16931-1 and is written for implementers; "an invoice
    | with an amount due must state a due date or payment terms" is the same
    | rule said to somebody who has to act on it.
    |
    | ## Completeness is checked, in one direction honestly
    |
    | `ExchangeRules` greps the `Exchange` source for the rule identifiers
    | the code can emit and fails the build when one has no entry here. The
    | other direction - every rule in the official Schematron - cannot be
    | checked until PF-115 vendors it, and that is recorded as an open
    | criterion rather than claimed.
    |
    */

    'rules' => [

        'BR-01' => [
            'en' => 'An invoice must state which specification it follows (BT-24).',
            'ar' => 'يجب أن تحدد الفاتورة المواصفة التي تتبعها (BT-24).',
        ],
        'BR-02' => [
            'en' => 'An invoice must have a number (BT-1).',
            'ar' => 'يجب أن يكون للفاتورة رقم (BT-1).',
        ],
        'BR-03' => [
            'en' => 'An invoice must have an issue date (BT-2).',
            'ar' => 'يجب أن يكون للفاتورة تاريخ إصدار (BT-2).',
        ],
        'BR-04' => [
            'en' => 'An invoice must have a document type code (BT-3).',
            'ar' => 'يجب أن يكون للفاتورة رمز نوع المستند (BT-3).',
        ],
        'BR-05' => [
            'en' => 'An invoice must state its currency (BT-5).',
            'ar' => 'يجب أن تحدد الفاتورة عملتها (BT-5).',
        ],
        'BR-06' => [
            'en' => 'An invoice must state the seller\'s name (BT-27).',
            'ar' => 'يجب أن تذكر الفاتورة اسم البائع (BT-27).',
        ],
        'BR-07' => [
            'en' => 'An invoice must state the buyer\'s name (BT-44).',
            'ar' => 'يجب أن تذكر الفاتورة اسم المشتري (BT-44).',
        ],
        'BR-08' => [
            'en' => 'An invoice must carry the seller\'s postal address (BG-5).',
            'ar' => 'يجب أن تحتوي الفاتورة على العنوان البريدي للبائع (BG-5).',
        ],
        'BR-09' => [
            'en' => 'The seller\'s address must state a country (BT-40).',
            'ar' => 'يجب أن يذكر عنوان البائع الدولة (BT-40).',
        ],
        'BR-10' => [
            'en' => 'An invoice must carry the buyer\'s postal address (BG-8).',
            'ar' => 'يجب أن تحتوي الفاتورة على العنوان البريدي للمشتري (BG-8).',
        ],
        'BR-11' => [
            'en' => 'The buyer\'s address must state a country (BT-55).',
            'ar' => 'يجب أن يذكر عنوان المشتري الدولة (BT-55).',
        ],
        'BR-12' => [
            'en' => 'An invoice must state the sum of its line net amounts (BT-106).',
            'ar' => 'يجب أن تذكر الفاتورة مجموع صافي بنودها (BT-106).',
        ],
        'BR-13' => [
            'en' => 'An invoice must state its total without VAT (BT-109).',
            'ar' => 'يجب أن تذكر الفاتورة إجماليها قبل الضريبة (BT-109).',
        ],
        'BR-14' => [
            'en' => 'An invoice must state its total with VAT (BT-112).',
            'ar' => 'يجب أن تذكر الفاتورة إجماليها بعد الضريبة (BT-112).',
        ],
        'BR-15' => [
            'en' => 'An invoice must state the amount due for payment (BT-115).',
            'ar' => 'يجب أن تذكر الفاتورة المبلغ المستحق للدفع (BT-115).',
        ],
        'BR-16' => [
            'en' => 'An invoice must have at least one line (BG-25).',
            'ar' => 'يجب أن تحتوي الفاتورة على بند واحد على الأقل (BG-25).',
        ],
        'BR-17' => [
            'en' => 'A payee that is not the seller must be named (BT-59).',
            'ar' => 'إذا كان المستفيد غير البائع فيجب ذكر اسمه (BT-59).',
        ],
        'BR-18' => [
            'en' => 'A tax representative must be named (BT-62).',
            'ar' => 'يجب ذكر اسم الممثل الضريبي (BT-62).',
        ],
        'BR-19' => [
            'en' => 'A tax representative must carry a postal address (BG-12).',
            'ar' => 'يجب أن يكون للممثل الضريبي عنوان بريدي (BG-12).',
        ],
        'BR-20' => [
            'en' => 'The tax representative\'s address must state a country (BT-69).',
            'ar' => 'يجب أن يذكر عنوان الممثل الضريبي الدولة (BT-69).',
        ],
        'BR-21' => [
            'en' => 'Every line must have a line identifier (BT-126).',
            'ar' => 'يجب أن يكون لكل بند معرّف (BT-126).',
        ],
        'BR-22' => [
            'en' => 'Every line must state the invoiced quantity (BT-129).',
            'ar' => 'يجب أن يذكر كل بند الكمية المفوترة (BT-129).',
        ],
        'BR-23' => [
            'en' => 'Every line must state the unit of measure (BT-130).',
            'ar' => 'يجب أن يذكر كل بند وحدة القياس (BT-130).',
        ],
        'BR-24' => [
            'en' => 'Every line must state its net amount (BT-131).',
            'ar' => 'يجب أن يذكر كل بند صافي مبلغه (BT-131).',
        ],
        'BR-25' => [
            'en' => 'Every line must name the item (BT-153).',
            'ar' => 'يجب أن يذكر كل بند اسم الصنف (BT-153).',
        ],
        'BR-26' => [
            'en' => 'Every line must state the item\'s net price (BT-146).',
            'ar' => 'يجب أن يذكر كل بند سعر وحدة الصنف الصافي (BT-146).',
        ],
        'BR-31' => [
            'en' => 'Every document-level allowance must state its amount (BT-92).',
            'ar' => 'يجب أن يذكر كل خصم على مستوى المستند مبلغه (BT-92).',
        ],
        'BR-32' => [
            'en' => 'Every document-level allowance must state a VAT category (BT-95).',
            'ar' => 'يجب أن يذكر كل خصم على مستوى المستند فئته الضريبية (BT-95).',
        ],
        'BR-36' => [
            'en' => 'Every document-level charge must state its amount (BT-99).',
            'ar' => 'يجب أن يذكر كل رسم على مستوى المستند مبلغه (BT-99).',
        ],
        'BR-37' => [
            'en' => 'Every document-level charge must state a VAT category (BT-102).',
            'ar' => 'يجب أن يذكر كل رسم على مستوى المستند فئته الضريبية (BT-102).',
        ],
        'BR-41' => [
            'en' => 'Every line allowance must state its amount (BT-136).',
            'ar' => 'يجب أن يذكر كل خصم على مستوى البند مبلغه (BT-136).',
        ],
        'BR-43' => [
            'en' => 'Every line charge must state its amount (BT-141).',
            'ar' => 'يجب أن يذكر كل رسم على مستوى البند مبلغه (BT-141).',
        ],
        'BR-45' => [
            'en' => 'Every VAT breakdown must state the taxable amount (BT-116).',
            'ar' => 'يجب أن يذكر كل تفصيل ضريبي الوعاء الخاضع (BT-116).',
        ],
        'BR-46' => [
            'en' => 'Every VAT breakdown must state the tax amount (BT-117).',
            'ar' => 'يجب أن يذكر كل تفصيل ضريبي مبلغ الضريبة (BT-117).',
        ],
        'BR-47' => [
            'en' => 'Every VAT breakdown must state the category code (BT-118).',
            'ar' => 'يجب أن يذكر كل تفصيل ضريبي رمز الفئة (BT-118).',
        ],
        'BR-48' => [
            'en' => 'Every VAT breakdown must state the category rate (BT-119), unless the supply is '.
                'not subject to VAT at all. The rate is what the recipient multiplies the taxable '.
                'amount by to re-derive the tax.',
            'ar' => 'يجب أن يذكر كل تفصيل ضريبي نسبة الفئة (BT-119)، إلا إذا كان التوريد خارج نطاق '.
                'الضريبة أصلًا. النسبة هي ما يضرب فيه المستلم الوعاء الضريبي ليعيد استخراج الضريبة.',
        ],
        'BR-49' => [
            'en' => 'Payment instructions must state the payment means (BT-81).',
            'ar' => 'يجب أن تذكر تعليمات الدفع وسيلة الدفع (BT-81).',
        ],
        'BR-50' => [
            'en' => 'A credit transfer must state the receiving account (BT-84).',
            'ar' => 'يجب أن تذكر الحوالة المصرفية الحساب المستقبل (BT-84).',
        ],
        'BR-51' => [
            'en' => 'Payment card information must state the last digits of the card (BT-87).',
            'ar' => 'يجب أن تذكر بيانات البطاقة آخر أرقامها (BT-87).',
        ],
        'BR-52' => [
            'en' => 'Every supporting document must carry a reference (BT-122).',
            'ar' => 'يجب أن يحمل كل مستند مساند مرجعاً (BT-122).',
        ],
        'BR-53' => [
            'en' => 'A VAT accounting currency (BT-6) requires the VAT amount in that currency (BT-111).',
            'ar' => 'إذا ذُكرت عملة الاحتساب الضريبي (BT-6) فيجب ذكر مبلغ الضريبة بها (BT-111).',
        ],
        'BR-54' => [
            'en' => 'Every item attribute must have both a name and a value (BT-160, BT-161).',
            'ar' => 'يجب أن يكون لكل خاصية صنف اسم وقيمة (BT-160 و BT-161).',
        ],
        'BR-55' => [
            'en' => 'A preceding invoice reference must name the document it refers to (BT-25).',
            'ar' => 'يجب أن يذكر مرجع الفاتورة السابقة المستند المُشار إليه (BT-25).',
        ],
        'BR-56' => [
            'en' => 'A tax representative must have a VAT identifier (BT-63).',
            'ar' => 'يجب أن يكون للممثل الضريبي رقم تسجيل ضريبي (BT-63).',
        ],
        'BR-57' => [
            'en' => 'A deliver-to address must state a country (BT-80).',
            'ar' => 'يجب أن يذكر عنوان التسليم الدولة (BT-80).',
        ],
        'BR-62' => [
            'en' => 'The seller\'s electronic address must carry its scheme identifier (BT-34).',
            'ar' => 'يجب أن يحمل العنوان الإلكتروني للبائع رمز مخططه (BT-34).',
        ],
        'BR-63' => [
            'en' => 'The buyer\'s electronic address must carry its scheme identifier (BT-49).',
            'ar' => 'يجب أن يحمل العنوان الإلكتروني للمشتري رمز مخططه (BT-49).',
        ],
        'BR-64' => [
            'en' => 'An item standard identifier must carry its scheme (BT-157).',
            'ar' => 'يجب أن يحمل المعرّف القياسي للصنف رمز مخططه (BT-157).',
        ],
        'BR-65' => [
            'en' => 'An item classification identifier must carry its scheme (BT-158).',
            'ar' => 'يجب أن يحمل معرّف تصنيف الصنف رمز مخططه (BT-158).',
        ],
        'BR-E-10' => [
            'en' => 'A VAT-exempt breakdown must state the reason for the exemption.',
            'ar' => 'يجب أن يذكر التفصيل المعفى من الضريبة سبب الإعفاء.',
        ],
        'BR-AE-10' => [
            'en' => 'A reverse-charge breakdown must state the reason.',
            'ar' => 'يجب أن يذكر تفصيل التحصيل العكسي سببه.',
        ],
        'BR-IC-10' => [
            'en' => 'An intra-community supply breakdown must state the reason.',
            'ar' => 'يجب أن يذكر تفصيل التوريد داخل الاتحاد سببه.',
        ],
        'BR-G-10' => [
            'en' => 'An export breakdown must state the reason.',
            'ar' => 'يجب أن يذكر تفصيل التصدير سببه.',
        ],
        'BR-O-10' => [
            'en' => 'A not-subject-to-VAT breakdown must state the reason.',
            'ar' => 'يجب أن يذكر التفصيل غير الخاضع للضريبة سببه.',
        ],
        'BR-CO-3' => [
            'en' => 'The tax point date (BT-7) and the tax point date code (BT-8) cannot both be stated.',
            'ar' => 'لا يمكن ذكر تاريخ نقطة الضريبة (BT-7) ورمزها (BT-8) معاً.',
        ],
        'BR-CO-9' => [
            'en' => 'A VAT identifier must begin with the ISO country code of the country that issued it.',
            'ar' => 'يجب أن يبدأ رقم التسجيل الضريبي برمز الدولة المُصدِرة وفق ISO.',
        ],
        'BR-CO-10' => [
            'en' => 'The sum of line net amounts (BT-106) must equal the sum of the lines.',
            'ar' => 'يجب أن يساوي مجموع صافي البنود (BT-106) حصيلة جمع البنود.',
        ],
        'BR-CO-11' => [
            'en' => 'The sum of allowances (BT-107) must equal the allowances on the document.',
            'ar' => 'يجب أن يساوي مجموع الخصومات (BT-107) حصيلة خصومات المستند.',
        ],
        'BR-CO-12' => [
            'en' => 'The sum of charges (BT-108) must equal the charges on the document.',
            'ar' => 'يجب أن يساوي مجموع الرسوم (BT-108) حصيلة رسوم المستند.',
        ],
        'BR-CO-13' => [
            'en' => 'The total without VAT (BT-109) must equal BT-106 minus BT-107 plus BT-108.',
            'ar' => 'يجب أن يساوي الإجمالي قبل الضريبة (BT-109) القيمة BT-106 ناقص BT-107 زائد BT-108.',
        ],
        'BR-CO-14' => [
            'en' => 'The total VAT amount (BT-110) must equal the sum of the VAT breakdown.',
            'ar' => 'يجب أن يساوي إجمالي الضريبة (BT-110) حصيلة جمع التفصيل الضريبي.',
        ],
        'BR-CO-15' => [
            'en' => 'The total with VAT (BT-112) must equal BT-109 plus BT-110.',
            'ar' => 'يجب أن يساوي الإجمالي بعد الضريبة (BT-112) القيمة BT-109 زائد BT-110.',
        ],
        'BR-CO-16' => [
            'en' => 'The amount due (BT-115) must equal BT-112 minus the paid amount plus any rounding.',
            'ar' => 'يجب أن يساوي المبلغ المستحق (BT-115) القيمة BT-112 ناقص المدفوع زائد التقريب.',
        ],
        'BR-CO-17' => [
            'en' => 'Each VAT breakdown\'s tax amount must equal its taxable amount times its rate.',
            'ar' => 'يجب أن يساوي مبلغ الضريبة في كل تفصيل الوعاء مضروباً في النسبة.',
        ],
        'BR-CO-18' => [
            'en' => 'An invoice must have at least one VAT breakdown group (BG-23).',
            'ar' => 'يجب أن تحتوي الفاتورة على تفصيل ضريبي واحد على الأقل (BG-23).',
        ],
        'BR-CO-19' => [
            'en' => 'An invoicing period must state a start date or an end date.',
            'ar' => 'يجب أن تذكر فترة الفوترة تاريخ بداية أو نهاية.',
        ],
        'BR-CO-20' => [
            'en' => 'A line period must state a start date or an end date.',
            'ar' => 'يجب أن تذكر فترة البند تاريخ بداية أو نهاية.',
        ],
        'BR-CO-21' => [
            'en' => 'A document-level allowance must state a reason or a reason code.',
            'ar' => 'يجب أن يذكر الخصم على مستوى المستند سبباً أو رمز سبب.',
        ],
        'BR-CO-22' => [
            'en' => 'A document-level charge must state a reason or a reason code.',
            'ar' => 'يجب أن يذكر الرسم على مستوى المستند سبباً أو رمز سبب.',
        ],
        'BR-CO-23' => [
            'en' => 'A line allowance must state a reason or a reason code.',
            'ar' => 'يجب أن يذكر الخصم على مستوى البند سبباً أو رمز سبب.',
        ],
        'BR-CO-24' => [
            'en' => 'A line charge must state a reason or a reason code.',
            'ar' => 'يجب أن يذكر الرسم على مستوى البند سبباً أو رمز سبب.',
        ],
        'BR-CO-25' => [
            'en' => 'An invoice with an amount due must state a due date (BT-9) or payment terms (BT-20).',
            'ar' => 'يجب أن تذكر الفاتورة ذات المبلغ المستحق تاريخ استحقاق (BT-9) أو شروط دفع (BT-20).',
        ],
        'BR-CO-26' => [
            'en' => 'The seller must be identifiable by an identifier, a legal registration or a VAT number.',
            'ar' => 'يجب أن يكون البائع قابلاً للتعريف برقم تعريف أو سجل قانوني أو رقم ضريبي.',
        ],
        /*
         | The per-category families - PF-116.
         |
         | Ten rules per VAT category in EN 16931, of which the rate rules
         | (-05, -06, -07) and the calculation rules (-08, -09) are
         | implemented in `CategoryRules`. The identifiers are read from the
         | vendored abstract rule set, which is why `K` is `BR-IC-*` and
         | `L` and `M` are `BR-IG-*` and `BR-IP-*`: the family is named after
         | the regime, not after the code list value.
         */
        'BR-S-05' => [
            'en' => 'An invoice line that is standard-rated must state a VAT rate above zero (BT-152).',
            'ar' => 'يجب أن يذكر سطر الفاتورة المصنف ضمن الخضوع للنسبة الأساسية نسبة ضريبة أكبر من صفر (BT-152).',
        ],
        'BR-S-06' => [
            'en' => 'A document-level allowance that is standard-rated must state a VAT rate above zero (BT-96).',
            'ar' => 'يجب أن يذكر الخصم على مستوى المستند المصنف ضمن الخضوع للنسبة الأساسية نسبة ضريبة أكبر من صفر (BT-96).',
        ],
        'BR-S-07' => [
            'en' => 'A document-level charge that is standard-rated must state a VAT rate above zero (BT-103).',
            'ar' => 'يجب أن يذكر الرسم على مستوى المستند المصنف ضمن الخضوع للنسبة الأساسية نسبة ضريبة أكبر من صفر (BT-103).',
        ],
        'BR-S-08' => [
            'en' => 'The taxable amount (BT-116) for the standard-rated category must equal the line net amounts plus the charges less the allowances in it at that rate.',
            'ar' => 'يجب أن يساوي الوعاء الضريبي (BT-116) للفئة الخضوع للنسبة الأساسية مجموع صافي مبالغ السطور زائداً الرسوم ناقصاً الخصومات في تلك الفئة وبالنسبة نفسها.',
        ],
        'BR-S-09' => [
            'en' => 'The VAT amount (BT-117) for the standard-rated category must equal its taxable amount multiplied by its rate.',
            'ar' => 'يجب أن يساوي مبلغ الضريبة (BT-117) للفئة الخضوع للنسبة الأساسية وعاءها الضريبي مضروباً في نسبتها.',
        ],
        'BR-Z-05' => [
            'en' => 'An invoice line that is zero-rated must state a VAT rate of 0 (BT-152).',
            'ar' => 'يجب أن يذكر سطر الفاتورة المصنف ضمن النسبة الصفرية نسبة ضريبة صفراً (BT-152).',
        ],
        'BR-Z-06' => [
            'en' => 'A document-level allowance that is zero-rated must state a VAT rate of 0 (BT-96).',
            'ar' => 'يجب أن يذكر الخصم على مستوى المستند المصنف ضمن النسبة الصفرية نسبة ضريبة صفراً (BT-96).',
        ],
        'BR-Z-07' => [
            'en' => 'A document-level charge that is zero-rated must state a VAT rate of 0 (BT-103).',
            'ar' => 'يجب أن يذكر الرسم على مستوى المستند المصنف ضمن النسبة الصفرية نسبة ضريبة صفراً (BT-103).',
        ],
        'BR-Z-08' => [
            'en' => 'The taxable amount (BT-116) for the zero-rated category must equal the line net amounts plus the charges less the allowances in it.',
            'ar' => 'يجب أن يساوي الوعاء الضريبي (BT-116) للفئة النسبة الصفرية مجموع صافي مبالغ السطور زائداً الرسوم ناقصاً الخصومات في تلك الفئة.',
        ],
        'BR-Z-09' => [
            'en' => 'The VAT amount (BT-117) for the zero-rated category must be zero.',
            'ar' => 'يجب أن يكون مبلغ الضريبة (BT-117) للفئة النسبة الصفرية صفراً.',
        ],
        'BR-E-05' => [
            'en' => 'An invoice line that is VAT-exempt must state a VAT rate of 0 (BT-152).',
            'ar' => 'يجب أن يذكر سطر الفاتورة المصنف ضمن الإعفاء من الضريبة نسبة ضريبة صفراً (BT-152).',
        ],
        'BR-E-06' => [
            'en' => 'A document-level allowance that is VAT-exempt must state a VAT rate of 0 (BT-96).',
            'ar' => 'يجب أن يذكر الخصم على مستوى المستند المصنف ضمن الإعفاء من الضريبة نسبة ضريبة صفراً (BT-96).',
        ],
        'BR-E-07' => [
            'en' => 'A document-level charge that is VAT-exempt must state a VAT rate of 0 (BT-103).',
            'ar' => 'يجب أن يذكر الرسم على مستوى المستند المصنف ضمن الإعفاء من الضريبة نسبة ضريبة صفراً (BT-103).',
        ],
        'BR-E-08' => [
            'en' => 'The taxable amount (BT-116) for the VAT-exempt category must equal the line net amounts plus the charges less the allowances in it.',
            'ar' => 'يجب أن يساوي الوعاء الضريبي (BT-116) للفئة الإعفاء من الضريبة مجموع صافي مبالغ السطور زائداً الرسوم ناقصاً الخصومات في تلك الفئة.',
        ],
        'BR-E-09' => [
            'en' => 'The VAT amount (BT-117) for the VAT-exempt category must be zero.',
            'ar' => 'يجب أن يكون مبلغ الضريبة (BT-117) للفئة الإعفاء من الضريبة صفراً.',
        ],
        'BR-AE-05' => [
            'en' => 'An invoice line that is reverse-charge must state a VAT rate of 0 (BT-152).',
            'ar' => 'يجب أن يذكر سطر الفاتورة المصنف ضمن التحصيل العكسي نسبة ضريبة صفراً (BT-152).',
        ],
        'BR-AE-06' => [
            'en' => 'A document-level allowance that is reverse-charge must state a VAT rate of 0 (BT-96).',
            'ar' => 'يجب أن يذكر الخصم على مستوى المستند المصنف ضمن التحصيل العكسي نسبة ضريبة صفراً (BT-96).',
        ],
        'BR-AE-07' => [
            'en' => 'A document-level charge that is reverse-charge must state a VAT rate of 0 (BT-103).',
            'ar' => 'يجب أن يذكر الرسم على مستوى المستند المصنف ضمن التحصيل العكسي نسبة ضريبة صفراً (BT-103).',
        ],
        'BR-AE-08' => [
            'en' => 'The taxable amount (BT-116) for the reverse-charge category must equal the line net amounts plus the charges less the allowances in it.',
            'ar' => 'يجب أن يساوي الوعاء الضريبي (BT-116) للفئة التحصيل العكسي مجموع صافي مبالغ السطور زائداً الرسوم ناقصاً الخصومات في تلك الفئة.',
        ],
        'BR-AE-09' => [
            'en' => 'The VAT amount (BT-117) for the reverse-charge category must be zero.',
            'ar' => 'يجب أن يكون مبلغ الضريبة (BT-117) للفئة التحصيل العكسي صفراً.',
        ],
        'BR-IC-05' => [
            'en' => 'An invoice line that is intra-community supply must state a VAT rate of 0 (BT-152).',
            'ar' => 'يجب أن يذكر سطر الفاتورة المصنف ضمن التوريد البيني داخل الاتحاد الأوروبي نسبة ضريبة صفراً (BT-152).',
        ],
        'BR-IC-06' => [
            'en' => 'A document-level allowance that is intra-community supply must state a VAT rate of 0 (BT-96).',
            'ar' => 'يجب أن يذكر الخصم على مستوى المستند المصنف ضمن التوريد البيني داخل الاتحاد الأوروبي نسبة ضريبة صفراً (BT-96).',
        ],
        'BR-IC-07' => [
            'en' => 'A document-level charge that is intra-community supply must state a VAT rate of 0 (BT-103).',
            'ar' => 'يجب أن يذكر الرسم على مستوى المستند المصنف ضمن التوريد البيني داخل الاتحاد الأوروبي نسبة ضريبة صفراً (BT-103).',
        ],
        'BR-IC-08' => [
            'en' => 'The taxable amount (BT-116) for the intra-community supply category must equal the line net amounts plus the charges less the allowances in it.',
            'ar' => 'يجب أن يساوي الوعاء الضريبي (BT-116) للفئة التوريد البيني داخل الاتحاد الأوروبي مجموع صافي مبالغ السطور زائداً الرسوم ناقصاً الخصومات في تلك الفئة.',
        ],
        'BR-IC-09' => [
            'en' => 'The VAT amount (BT-117) for the intra-community supply category must be zero.',
            'ar' => 'يجب أن يكون مبلغ الضريبة (BT-117) للفئة التوريد البيني داخل الاتحاد الأوروبي صفراً.',
        ],
        'BR-G-05' => [
            'en' => 'An invoice line that is export outside the EU must state a VAT rate of 0 (BT-152).',
            'ar' => 'يجب أن يذكر سطر الفاتورة المصنف ضمن التصدير خارج الاتحاد الأوروبي نسبة ضريبة صفراً (BT-152).',
        ],
        'BR-G-06' => [
            'en' => 'A document-level allowance that is export outside the EU must state a VAT rate of 0 (BT-96).',
            'ar' => 'يجب أن يذكر الخصم على مستوى المستند المصنف ضمن التصدير خارج الاتحاد الأوروبي نسبة ضريبة صفراً (BT-96).',
        ],
        'BR-G-07' => [
            'en' => 'A document-level charge that is export outside the EU must state a VAT rate of 0 (BT-103).',
            'ar' => 'يجب أن يذكر الرسم على مستوى المستند المصنف ضمن التصدير خارج الاتحاد الأوروبي نسبة ضريبة صفراً (BT-103).',
        ],
        'BR-G-08' => [
            'en' => 'The taxable amount (BT-116) for the export outside the EU category must equal the line net amounts plus the charges less the allowances in it.',
            'ar' => 'يجب أن يساوي الوعاء الضريبي (BT-116) للفئة التصدير خارج الاتحاد الأوروبي مجموع صافي مبالغ السطور زائداً الرسوم ناقصاً الخصومات في تلك الفئة.',
        ],
        'BR-G-09' => [
            'en' => 'The VAT amount (BT-117) for the export outside the EU category must be zero.',
            'ar' => 'يجب أن يكون مبلغ الضريبة (BT-117) للفئة التصدير خارج الاتحاد الأوروبي صفراً.',
        ],
        'BR-O-05' => [
            'en' => 'An invoice line that is not subject to VAT must state no VAT rate (BT-152) - the absence of a rate is not a rate of zero.',
            'ar' => 'لا يجوز أن يذكر سطر الفاتورة المصنف ضمن عدم الخضوع للضريبة نسبة ضريبة (BT-152)، فغياب النسبة ليس نسبة صفرية.',
        ],
        'BR-O-06' => [
            'en' => 'A document-level allowance that is not subject to VAT must state no VAT rate (BT-96) - the absence of a rate is not a rate of zero.',
            'ar' => 'لا يجوز أن يذكر الخصم على مستوى المستند المصنف ضمن عدم الخضوع للضريبة نسبة ضريبة (BT-96)، فغياب النسبة ليس نسبة صفرية.',
        ],
        'BR-O-07' => [
            'en' => 'A document-level charge that is not subject to VAT must state no VAT rate (BT-103) - the absence of a rate is not a rate of zero.',
            'ar' => 'لا يجوز أن يذكر الرسم على مستوى المستند المصنف ضمن عدم الخضوع للضريبة نسبة ضريبة (BT-103)، فغياب النسبة ليس نسبة صفرية.',
        ],
        'BR-O-08' => [
            'en' => 'The taxable amount (BT-116) for the not subject to VAT category must equal the line net amounts plus the charges less the allowances in it.',
            'ar' => 'يجب أن يساوي الوعاء الضريبي (BT-116) للفئة عدم الخضوع للضريبة مجموع صافي مبالغ السطور زائداً الرسوم ناقصاً الخصومات في تلك الفئة.',
        ],
        'BR-O-09' => [
            'en' => 'The VAT amount (BT-117) for the not subject to VAT category must be zero.',
            'ar' => 'يجب أن يكون مبلغ الضريبة (BT-117) للفئة عدم الخضوع للضريبة صفراً.',
        ],
        'BR-IG-05' => [
            'en' => 'An invoice line that is IGIC must state a VAT rate of zero or more (BT-152).',
            'ar' => 'يجب أن يذكر سطر الفاتورة المصنف ضمن ضريبة جزر الكناري IGIC نسبة ضريبة صفراً أو أكبر (BT-152).',
        ],
        'BR-IG-06' => [
            'en' => 'A document-level allowance that is IGIC must state a VAT rate of zero or more (BT-96).',
            'ar' => 'يجب أن يذكر الخصم على مستوى المستند المصنف ضمن ضريبة جزر الكناري IGIC نسبة ضريبة صفراً أو أكبر (BT-96).',
        ],
        'BR-IG-07' => [
            'en' => 'A document-level charge that is IGIC must state a VAT rate of zero or more (BT-103).',
            'ar' => 'يجب أن يذكر الرسم على مستوى المستند المصنف ضمن ضريبة جزر الكناري IGIC نسبة ضريبة صفراً أو أكبر (BT-103).',
        ],
        'BR-IG-08' => [
            'en' => 'The taxable amount (BT-116) for the IGIC category must equal the line net amounts plus the charges less the allowances in it at that rate.',
            'ar' => 'يجب أن يساوي الوعاء الضريبي (BT-116) للفئة ضريبة جزر الكناري IGIC مجموع صافي مبالغ السطور زائداً الرسوم ناقصاً الخصومات في تلك الفئة وبالنسبة نفسها.',
        ],
        'BR-IG-09' => [
            'en' => 'The VAT amount (BT-117) for the IGIC category must equal its taxable amount multiplied by its rate.',
            'ar' => 'يجب أن يساوي مبلغ الضريبة (BT-117) للفئة ضريبة جزر الكناري IGIC وعاءها الضريبي مضروباً في نسبتها.',
        ],
        'BR-IP-05' => [
            'en' => 'An invoice line that is IPSI must state a VAT rate of zero or more (BT-152).',
            'ar' => 'يجب أن يذكر سطر الفاتورة المصنف ضمن ضريبة سبتة ومليلة IPSI نسبة ضريبة صفراً أو أكبر (BT-152).',
        ],
        'BR-IP-06' => [
            'en' => 'A document-level allowance that is IPSI must state a VAT rate of zero or more (BT-96).',
            'ar' => 'يجب أن يذكر الخصم على مستوى المستند المصنف ضمن ضريبة سبتة ومليلة IPSI نسبة ضريبة صفراً أو أكبر (BT-96).',
        ],
        'BR-IP-07' => [
            'en' => 'A document-level charge that is IPSI must state a VAT rate of zero or more (BT-103).',
            'ar' => 'يجب أن يذكر الرسم على مستوى المستند المصنف ضمن ضريبة سبتة ومليلة IPSI نسبة ضريبة صفراً أو أكبر (BT-103).',
        ],
        'BR-IP-08' => [
            'en' => 'The taxable amount (BT-116) for the IPSI category must equal the line net amounts plus the charges less the allowances in it at that rate.',
            'ar' => 'يجب أن يساوي الوعاء الضريبي (BT-116) للفئة ضريبة سبتة ومليلة IPSI مجموع صافي مبالغ السطور زائداً الرسوم ناقصاً الخصومات في تلك الفئة وبالنسبة نفسها.',
        ],
        'BR-IP-09' => [
            'en' => 'The VAT amount (BT-117) for the IPSI category must equal its taxable amount multiplied by its rate.',
            'ar' => 'يجب أن يساوي مبلغ الضريبة (BT-117) للفئة ضريبة سبتة ومليلة IPSI وعاءها الضريبي مضروباً في نسبتها.',
        ],
        /*
         | The Peppol CIUS - PF-120.
         |
         | Peppol's own identifiers, because the supplier reads our message
         | and then the access point's rejection: if the two quote different
         | identifiers for the same defect they spend the afternoon deciding
         | which of us is wrong.
         */
        'PEPPOL-DOCUMENT-TYPE' => [
            'en' => 'Peppol BIS Billing does not carry this document type.',
            'ar' => 'شبكة بيبول لا تنقل هذا النوع من المستندات.',
        ],
        'PEPPOL-PAYMENT-MEANS' => [
            'en' => 'The payment means is not one Peppol permits.',
            'ar' => 'وسيلة الدفع ليست من الوسائل التي تسمح بها بيبول.',
        ],
        'PEPPOL-COMMON-R050' => [
            'en' => 'An electronic address must carry a four-digit EAS scheme code.',
            'ar' => 'يجب أن يحمل العنوان الإلكتروني رمز مخطط EAS من أربعة أرقام.',
        ],
        'PEPPOL-EN16931-CL008' => [
            'en' => 'The electronic address scheme must be one in the Peppol participant identifier scheme code list.',
            'ar' => 'يجب أن يكون مخطط العنوان الإلكتروني من المخططات الواردة في قائمة رموز مخططات معرفات المشاركين في بيبول.',
        ],
        'PEPPOL-EN16931-R001' => [
            'en' => 'A Peppol document must state its business process (BT-23).',
            'ar' => 'يجب أن يذكر مستند بيبول عملية الأعمال الخاصة به (BT-23).',
        ],
        'PEPPOL-EN16931-R002' => [
            'en' => 'Peppol permits at most one note on the document.',
            'ar' => 'تسمح بيبول بملاحظة واحدة على الأكثر على مستوى المستند.',
        ],
        'PEPPOL-EN16931-R003' => [
            'en' => 'A buyer reference (BT-10) or a purchase order reference (BT-13) must be provided.',
            'ar' => 'يجب ذكر مرجع المشتري (BT-10) أو مرجع أمر الشراء (BT-13).',
        ],
        'PEPPOL-EN16931-R004' => [
            'en' => 'The specification identifier must be the EN 16931 one, narrowed by Peppol.',
            'ar' => 'يجب أن يكون معرّف المواصفة هو معرّف EN 16931 مُضيَّقاً ببيبول.',
        ],
        'PEPPOL-EN16931-R005' => [
            'en' => 'The VAT accounting currency must differ from the invoice currency.',
            'ar' => 'يجب أن تختلف عملة الاحتساب الضريبي عن عملة الفاتورة.',
        ],
        'PEPPOL-EN16931-R010' => [
            'en' => 'The buyer\'s electronic address (BT-49) must be provided.',
            'ar' => 'يجب ذكر العنوان الإلكتروني للمشتري (BT-49).',
        ],
        'PEPPOL-EN16931-R020' => [
            'en' => 'The seller\'s electronic address (BT-34) must be provided.',
            'ar' => 'يجب ذكر العنوان الإلكتروني للبائع (BT-34).',
        ],
        'PEPPOL-EN16931-R041' => [
            'en' => 'An allowance or charge percentage must come with its base amount.',
            'ar' => 'يجب أن تُرفق نسبة الخصم أو الرسم بالمبلغ الأساس المحتسبة عليه.',
        ],
        'PEPPOL-EN16931-R042' => [
            'en' => 'An allowance or charge base amount must come with its percentage.',
            'ar' => 'يجب أن يُرفق المبلغ الأساس للخصم أو الرسم بنسبته.',
        ],
        'PEPPOL-EN16931-R046' => [
            'en' => 'The item net price must equal the gross price less the discount.',
            'ar' => 'يجب أن يساوي سعر الصنف الصافي السعر الإجمالي ناقص الخصم.',
        ],
        'PEPPOL-EN16931-R110' => [
            'en' => 'A line period must start within the invoicing period.',
            'ar' => 'يجب أن تبدأ فترة البند داخل فترة الفوترة.',
        ],
        'PEPPOL-EN16931-R111' => [
            'en' => 'A line period must end within the invoicing period.',
            'ar' => 'يجب أن تنتهي فترة البند داخل فترة الفوترة.',
        ],
        'PEPPOL-EN16931-R120' => [
            'en' => 'The line net amount must equal its quantity, price, charges and allowances.',
            'ar' => 'يجب أن يساوي صافي البند حصيلة كميته وسعره ورسومه وخصوماته.',
        ],
        'PEPPOL-EN16931-R121' => [
            'en' => 'A price base quantity must be greater than zero.',
            'ar' => 'يجب أن تكون كمية أساس السعر أكبر من صفر.',
        ],
        'PEPPOL-EN16931-R130' => [
            'en' => 'The price base quantity unit must be the invoiced quantity unit.',
            'ar' => 'يجب أن تكون وحدة كمية أساس السعر هي وحدة الكمية المفوترة.',
        ],

        /*
         | The Saudi regime - PF-123.
         */
        'ZATCA-DOCUMENT-TYPE' => [
            'en' => 'The Saudi tax invoice is UNCL 1001 388; 380 is a commercial invoice.',
            'ar' => 'الفاتورة الضريبية السعودية هي 388 وفق UNCL 1001، و380 فاتورة تجارية.',
        ],
        'ZATCA-ICV-MISSING' => [
            'en' => 'The document carries no invoice counter value.',
            'ar' => 'لا يحمل المستند رقم العدّاد التسلسلي للفاتورة.',
        ],
        'ZATCA-PIH-MISSING' => [
            'en' => 'The document carries no previous invoice hash, so it is not part of a chain.',
            'ar' => 'لا يحمل المستند بصمة الفاتورة السابقة، فهو خارج السلسلة.',
        ],
        'ZATCA-ARABIC-SELLER-NAME' => [
            'en' => 'The seller name must be in Arabic.',
            'ar' => 'يجب أن يكون اسم البائع بالعربية.',
        ],
        'ZATCA-ARABIC-ITEM-NAME' => [
            'en' => 'What was supplied must be described in Arabic.',
            'ar' => 'يجب وصف ما تم توريده بالعربية.',
        ],
        'ZATCA-STANDARD-BUYER-VAT' => [
            'en' => 'A standard invoice must state the buyer\'s VAT registration.',
            'ar' => 'يجب أن تذكر الفاتورة القياسية رقم التسجيل الضريبي للمشتري.',
        ],
        'ZATCA-QR-TOO-LONG' => [
            'en' => 'A QR value is longer than the TLV length byte can describe.',
            'ar' => 'إحدى قيم رمز الاستجابة أطول مما تسمح به بايت الطول في TLV.',
        ],
        'ZATCA-QR-INCOMPLETE' => [
            'en' => 'The QR payload is missing a required value.',
            'ar' => 'ينقص رمز الاستجابة السريعة قيمة مطلوبة.',
        ],
        'ZATCA-SAR-TAX-AMOUNT' => [
            'en' => 'A document in another currency must state its tax amount in riyals.',
            'ar' => 'يجب أن يذكر المستند بعملة أخرى مبلغ الضريبة بالريال.',
        ],

        'EN16931-SERIALIZATION' => [
            'en' => 'The document could not be converted into its transmission syntax.',
            'ar' => 'لم يتمكن النظام من تحويل المستند إلى صيغة الإرسال.',
        ],
        'EN16931-WELLFORMED' => [
            'en' => 'The produced document is not well-formed XML.',
            'ar' => 'المستند المُنتَج ليس XML صحيح البنية.',
        ],
        'EN16931-SCHEMA' => [
            'en' => 'The produced document does not match the schema of its syntax.',
            'ar' => 'المستند المُنتَج لا يطابق مخطط صيغته.',
        ],

        /*
         | Reported when the SCHEMA, not the document, asks for something
         | outside resources/schemas - PF-235. A validator that fetches part
         | of its schema from a third party has made every invoice depend on
         | that party being up, and whoever answers for it decides what
         | counts as valid.
         */
        'EN16931-SCHEMA-REFERENCE' => [
            'en' => 'The schema used to validate this document refers to a file that is not vendored, so validation was refused rather than fetched over the network.',
            'ar' => 'يشير المخطط المستخدم للتحقق من هذا المستند إلى ملف غير مُضمَّن محليًا، فرُفض التحقق بدلًا من جلبه عبر الشبكة.',
        ],
        'EN16931-CARDINALITY' => [
            'en' => 'A business term or group appears a number of times EN 16931 does not permit.',
            'ar' => 'ظهر مصطلح أو مجموعة أعمال بعدد مرات لا يسمح به EN 16931.',
        ],

    ],

    /*
    |---------------------------------------------------------------------------
    | What the vendored rule set carries and this code does not - PF-117
    |---------------------------------------------------------------------------
    |
    | `EInvoicingCoverageRules` reads every rule identifier out of
    | `schematron/en16931/EN16931-model.sch` and requires each one to be either
    | implemented in `Exchange` or listed here with the reason it is not. The
    | check runs both ways: an identifier listed here that the code CAN emit fails
    | as well, so the register cannot go stale the day a rule is implemented.
    |
    | It is keyed by reason rather than by rule because the reasons are shared -
    | twenty-one `BR-DEC-*` rules are unimplemented for one reason, and twenty-one
    | copies of it would be read by nobody.
    |
    | This is the difference PF-117 asks to be *recorded rather than claimed*:
    | 201 rules in the vendored set, and the lists below say which of them a
    | PeakFlow document is not checked against.
    |
    */

    'not_implemented' => [

        'The `BR-DEC-*` family: the maximum number of decimals permitted for one amount. '.
            'Every amount in a PeakFlow document comes out of a PostgreSQL `numeric` column '.
            'and is written by the serializer at its currency ISO 4217 exponent, so this '.
            'family asserts a property of the money layer rather than a property of a '.
            'document. Implementing it would be the money configuration said a second time, '.
            'in a place where the two could disagree.' => [
            'BR-DEC-01', 'BR-DEC-02', 'BR-DEC-05', 'BR-DEC-06', 'BR-DEC-09',
            'BR-DEC-10', 'BR-DEC-11', 'BR-DEC-12', 'BR-DEC-13', 'BR-DEC-14',
            'BR-DEC-15', 'BR-DEC-16', 'BR-DEC-17', 'BR-DEC-18', 'BR-DEC-19',
            'BR-DEC-20', 'BR-DEC-23', 'BR-DEC-24', 'BR-DEC-25', 'BR-DEC-27',
            'BR-DEC-28',
        ],

        'The `-01` rule of every category family: a category used on a line, an allowance '.
            'or a charge obliges the document to carry a VAT breakdown group for that '.
            'category. It is the walk from the lines TO the summary, and the summary is built '.
            'from the same stored tax breakdown rows as the lines - so this is a gap in the '.
            'proof rather than a gap in the document.' => [
            'BR-S-01', 'BR-Z-01', 'BR-E-01', 'BR-AE-01', 'BR-IC-01',
            'BR-G-01', 'BR-O-01', 'BR-IG-01', 'BR-IP-01',
        ],

        'The `-02`, `-03` and `-04` rules of every category family: a category on a line, '.
            'on a document-level allowance or on a document-level charge obliges the document '.
            'to carry a particular combination of the seller VAT identifier, the seller tax '.
            'registration identifier, the tax representative identifier and the buyer VAT '.
            'identifier - and for `O`, to carry none of them. `BR-CO-26` requires the seller '.
            'to be identifiable at all and is implemented; the per-category refinement is '.
            'not.' => [
            'BR-S-02', 'BR-S-03', 'BR-S-04', 'BR-Z-02', 'BR-Z-03',
            'BR-Z-04', 'BR-E-02', 'BR-E-03', 'BR-E-04', 'BR-AE-02',
            'BR-AE-03', 'BR-AE-04', 'BR-IC-02', 'BR-IC-03', 'BR-IC-04',
            'BR-G-02', 'BR-G-03', 'BR-G-04', 'BR-O-02', 'BR-O-03',
            'BR-O-04', 'BR-IG-02', 'BR-IG-03', 'BR-IG-04', 'BR-IP-02',
            'BR-IP-03', 'BR-IP-04',
        ],

        'The `-10` rules that FORBID an exemption reason on a category that is taxed. '.
            '`ConditionalRules` implements the requiring half - `BR-E-10`, `BR-AE-10`, '.
            '`BR-IC-10`, `BR-G-10` and `BR-O-10` - and not the forbidding half.' => [
            'BR-S-10', 'BR-Z-10', 'BR-IG-10', 'BR-IP-10',
        ],

        'The reason-or-reason-code obligations the standard states twice. `BR-33`, '.
            '`BR-38`, `BR-42` and `BR-44` are the same requirements as `BR-CO-21`, '.
            '`BR-CO-22`, `BR-CO-23` and `BR-CO-24`, which are implemented. Reporting both '.
            'would report one defect under two identifiers, and the supplier would be told to '.
            'fix two things.' => [
            'BR-33', 'BR-38', 'BR-42', 'BR-44',
        ],

        '`BR-CO-05` to `BR-CO-08`: a coded reason and its free text shall indicate the '.
            'same type of allowance or charge. That is a judgement about whether two strings '.
            'mean the same thing, and a mechanical version of it would refuse documents for '.
            'their wording.' => [
            'BR-CO-05', 'BR-CO-06', 'BR-CO-07', 'BR-CO-08',
        ],

        '`BR-CO-04`: every invoice line is categorised with `BT-151`. The cardinality '.
            'walk already requires `BG-30` within a line and `BT-151` within it, so a line '.
            'with no category is refused - under `EN16931-CARDINALITY` rather than under this '.
            'identifier.' => [
            'BR-CO-04',
        ],

        '`BR-29` and `BR-30`: a period whose end date precedes its start date. '.
            '`ConditionalRules` implements `BR-CO-19` and `BR-CO-20`, which require a period '.
            'to state at least one boundary, and not the ordering of the two.' => [
            'BR-29', 'BR-30',
        ],

        '`BR-27` and `BR-28`: the item net price and the item gross price shall not be '.
            'negative.' => [
            'BR-27', 'BR-28',
        ],

        '`BR-61`: a payment means that is a credit transfer requires the payment account '.
            'identifier `BT-84`.' => [
            'BR-61',
        ],

        '`BR-CL-08`: the invoice note subject code is coded using UNCL 4451. A code-list '.
            'membership rule, and the vendored UNCL lists are PF-115.' => [
            'BR-CL-08',
        ],

        '`BR-B-01` and `BR-B-02`: the Italian split-payment category `B`, which the tax '.
            'category enumeration does not model at all.' => [
            'BR-B-01', 'BR-B-02',
        ],

        '`BR-IC-11` and `BR-IC-12`: an intra-community supply needs an actual delivery '.
            'date or an invoicing period, and a deliver-to country code.' => [
            'BR-IC-11', 'BR-IC-12',
        ],

        '`BR-O-11` to `BR-O-14`: a not-subject-to-VAT breakdown may not coexist with any '.
            'other breakdown group, line, allowance or charge.' => [
            'BR-O-11', 'BR-O-12', 'BR-O-13', 'BR-O-14',
        ],

    ],

    /*
    |---------------------------------------------------------------------------
    | Where this code's identifier is NOT the vendored set's - PF-117
    |---------------------------------------------------------------------------
    |
    | Every identifier the `Exchange` sources can emit has to be one the vendored
    | rule sets contain, or be listed here. The point of the register is that a
    | divergence is a thing somebody decided, with a date and a reason, rather
    | than a typo that reads as a rule.
    |
    | `official` names the identifier the recipient's access point would quote
    | for the same defect, or is null where there is none.
    |
    */

    'divergences' => [

        'BR-CO-3' => [
            'official' => 'BR-CO-03',
            'reason' => 'The official identifier is zero-padded. `schematron/en16931/EN16931-model.sch` '.
                'contains `BR-CO-03` and contains no `BR-CO-3`, so a supplier told `BR-CO-3` '.
                'finds nothing in the recipient\'s report and nothing in the standard\'s own '.
                'artefact. The rule itself - the tax point date and its code are mutually '.
                'exclusive - is implemented in `ConditionalRules`. Renaming it is a one-line '.
                'change and a change to `tests/Support/SemanticModelRules.php`, which pins the '.
                'unpadded string, and is recorded here rather than done quietly.',
        ],

        'BR-CO-9' => [
            'official' => 'BR-CO-09',
            'reason' => 'The same zero-padding divergence, for the rule that requires a VAT identifier '.
                'to carry its ISO 3166-1 country prefix.',
        ],

        'BR-CO-25' => [
            'official' => null,
            'reason' => 'EN 16931-1 defines `BR-CO-25` - an invoice with a positive amount due states a '.
                'due date or payment terms - and the CEF validation artefacts do not implement '.
                'it: release 1.3.17 runs `BR-CO-24` and then `BR-CO-26`. The rule is '.
                'implemented here and is worth keeping, because a document with money owed and '.
                'no date is a document nobody can pay. What the reader has to know is that no '.
                'access point will quote this identifier back.',
        ],

        'PEPPOL-DOCUMENT-TYPE' => [
            'official' => null,
            'reason' => 'PeakFlow\'s own refusal, before a number is allocated: Peppol BIS Billing '.
                'carries no flow for a debit note (383). It is not a Schematron rule because a '.
                'Schematron rule runs on a document that already exists, and a gap in a gapless '.
                'series cannot be closed.',
        ],

        'PEPPOL-PAYMENT-MEANS' => [
            'official' => null,
            'reason' => 'PeakFlow\'s own refusal for a payment means outside the profile\'s list. The '.
                'nearest official rule is `PEPPOL-EN16931-CL001` to `CL007`, which are '.
                'code-list rules over UNCL lists that are not vendored yet.',
        ],

    ],

];
