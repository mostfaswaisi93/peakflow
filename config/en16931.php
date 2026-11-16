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
        'EN16931-CARDINALITY' => [
            'en' => 'A business term or group appears a number of times EN 16931 does not permit.',
            'ar' => 'ظهر مصطلح أو مجموعة أعمال بعدد مرات لا يسمح به EN 16931.',
        ],

    ],

];
