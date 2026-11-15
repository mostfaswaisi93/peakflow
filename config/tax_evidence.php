<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Exemption reason codes - PF-080
|--------------------------------------------------------------------------
|
| EN 16931 carries the reason a supply bears no tax as a CODE in `BT-121` and
| text in `BT-120`. The code is what a receiving system reads; the text is what
| a person reads. An invoice that says 0% with no reason is an invoice that
| cannot be defended, and one that says 0% with free text nobody can map is an
| invoice a receiving system rejects.
|
| ## This is an explicit SUBSET, and unknown codes are refused
|
| The published VATEX list is longer than this. A code absent from here is
| refused rather than accepted, which blocks a legitimate exemption whose code
| nobody has added yet - an availability problem, fixed by a row, and the right
| direction. Accepting any string would put codes on documents that the
| receiving authority rejects, after filing.
|
| ## `code spelling unverified here` means what it says
|
| Where the article is certain and the exact code spelling or letter case could
| not be verified in this environment, the source says so. The alternative is
| to present a guess as checked, and a code is the first thing a receiving
| system validates: a wrong one fails a document that was otherwise correct.
|
| This is the same stance database/iso/provenance.json takes, for the same
| reason.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Reason codes, by code
    |----------------------------------------------------------------------
    |
    | `category` is the UNCL 5305 category the code belongs to. A code used
    | with any other category is refused: `vatex-eu-ic` on an exempt supply
    | reports an intra-Community supply that did not happen, and the two
    | states' systems will not reconcile.
    */
    'reason_codes' => [

        'vatex-eu-ae' => [
            'category' => 'AE',
            'en' => 'Reverse charge',
            'ar' => 'التحصيل العكسي',
            'source' => 'Council Directive 2006/112/EC Art. 226(11a)',
        ],

        'vatex-eu-ic' => [
            'category' => 'K',
            'en' => 'Intra-Community supply',
            'ar' => 'توريد داخل الاتحاد الأوروبي',
            'source' => 'Council Directive 2006/112/EC Art. 138(1)',
        ],

        'vatex-eu-g' => [
            'category' => 'G',
            'en' => 'Export outside the EU',
            'ar' => 'تصدير خارج الاتحاد الأوروبي',
            'source' => 'Council Directive 2006/112/EC Art. 146(1)(a)-(b)',
        ],

        'vatex-eu-o' => [
            'category' => 'O',
            'en' => 'Not subject to VAT',
            'ar' => 'غير خاضع للضريبة',
            'source' => 'Council Directive 2006/112/EC Art. 2 and Art. 9 (outside the scope of the tax)',
        ],

        'vatex-eu-79-c' => [
            'category' => 'E',
            'en' => 'Disbursements recovered from the customer',
            'ar' => 'مبالغ مدفوعة بالنيابة عن العميل',
            'source' => 'Council Directive 2006/112/EC Art. 79(c) - code spelling unverified here',
        ],

        'vatex-eu-132' => [
            'category' => 'E',
            'en' => 'Exempt in the public interest',
            'ar' => 'معفى لأسباب تتعلق بالمصلحة العامة',
            'source' => 'Council Directive 2006/112/EC Art. 132 - code spelling unverified here',
        ],

        'vatex-eu-148' => [
            'category' => 'E',
            'en' => 'Exempt: international transport',
            'ar' => 'معفى: النقل الدولي',
            'source' => 'Council Directive 2006/112/EC Art. 148 - code spelling unverified here',
        ],

        'vatex-eu-151' => [
            'category' => 'E',
            'en' => 'Exempt: diplomatic and international bodies',
            'ar' => 'معفى: الهيئات الدبلوماسية والدولية',
            'source' => 'Council Directive 2006/112/EC Art. 151 - code spelling unverified here',
        ],

        'vatex-sa-32' => [
            'category' => 'Z',
            'en' => 'Zero-rated: export of goods',
            'ar' => 'خاضع لنسبة صفر: تصدير السلع',
            'source' => 'KSA VAT Implementing Regulations Art. 32 - code spelling unverified here',
        ],

        'vatex-sa-33' => [
            'category' => 'Z',
            'en' => 'Zero-rated: export of services',
            'ar' => 'خاضع لنسبة صفر: تصدير الخدمات',
            'source' => 'KSA VAT Implementing Regulations Art. 33 - code spelling unverified here',
        ],

        'vatex-sa-35' => [
            'category' => 'Z',
            'en' => 'Zero-rated: medicines and qualifying medical goods',
            'ar' => 'خاضع لنسبة صفر: الأدوية والسلع الطبية المؤهلة',
            'source' => 'KSA VAT Implementing Regulations Art. 35 - code spelling unverified here',
        ],

        'vatex-sa-36' => [
            'category' => 'Z',
            'en' => 'Zero-rated: qualifying metals',
            'ar' => 'خاضع لنسبة صفر: المعادن المؤهلة',
            'source' => 'KSA VAT Implementing Regulations Art. 36 - code spelling unverified here',
        ],

        'vatex-sa-edu' => [
            'category' => 'E',
            'en' => 'Exempt: private education for citizens',
            'ar' => 'معفى: التعليم الخاص للمواطنين',
            'source' => 'KSA VAT Implementing Regulations - article and code spelling unverified here',
        ],

        'vatex-sa-hea' => [
            'category' => 'E',
            'en' => 'Exempt: private healthcare for citizens',
            'ar' => 'معفى: الرعاية الصحية الخاصة للمواطنين',
            'source' => 'KSA VAT Implementing Regulations - article and code spelling unverified here',
        ],

        'vatex-sa-oos' => [
            'category' => 'O',
            'en' => 'Out of scope of the tax',
            'ar' => 'خارج نطاق الضريبة',
            'source' => 'GCC Unified Agreement Art. 2 (scope) - code spelling unverified here',
        ],

    ],

    /*
    |----------------------------------------------------------------------
    | Categories with no code shipped
    |----------------------------------------------------------------------
    |
    | `L` and `M` - the Canary Islands and Ceuta/Melilla regimes - require a
    | reason and no code for them is shipped, because inventing one would put
    | a code on a document that the receiving system rejects.
    |
    | Named here rather than left to be discovered: the refusal says the list
    | holds no code for the category and points at this file, which is a row
    | somebody can add. A system that silently accepted free text instead
    | would issue documents that fail at the recipient.
    */
    'categories_without_codes' => ['L', 'M'],

];
