<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Which channel implementation is in effect
    |---------------------------------------------------------------------------
    |
    | PF-257. `fake` records everything and sends nothing, which is the right
    | default: a deployment that has not configured a provider still issues
    | invoices, and the undelivered ones are rows somebody can see.
    |
    | A real provider is added as a second implementation of
    | `App\Exchange\Messaging\Contracts\MessageChannel` and named here. The
    | interface exists for exactly that: a product wired to one vendor's SDK
    | at every call site is a product that cannot change vendor, and WhatsApp
    | Business is reached through resellers whose pricing and approval queues
    | differ.
    |
    */

    'driver' => env('MESSAGING_DRIVER', 'fake'),

    /*
    |---------------------------------------------------------------------------
    | Email - PF-104
    |---------------------------------------------------------------------------
    |
    | The templates are HERE and not in the channel adapter, so adding a
    | document type to the delivery path is a configuration change rather
    | than a change to a provider's code. The variables are positional -
    | `{1}`, `{2}` - which is the scheme the WhatsApp templates use, so a
    | document type's variables are one list rather than one per channel.
    |
    | Rendered in the RECIPIENT'S language, which is why every template
    | carries both: a customer who reads Arabic should not receive an
    | English invoice because the person who issued it had English on.
    |
    */

    'email' => [
        'mailer' => env('MESSAGING_MAILER'),

        'templates' => [
            'invoice_issued' => [
                'category' => 'utility',
                'languages' => ['ar', 'en'],
                'variables' => ['the document number', 'the entity name', 'the gross amount', 'the due date'],
                'subject' => [
                    'ar' => 'فاتورة {1} من {2}',
                    'en' => 'Invoice {1} from {2}',
                ],
                'body' => [
                    'ar' => "تجدون مرفقًا الفاتورة {1} الصادرة عن {2}.

المبلغ المستحق: {3}
تاريخ الاستحقاق: {4}",
                    'en' => "Attached is invoice {1} issued by {2}.

Amount due: {3}
Due date: {4}",
                ],
            ],

            'statement_ready' => [
                'category' => 'utility',
                'languages' => ['ar', 'en'],
                'variables' => ['the entity name', 'the period', 'the closing balance'],
                'subject' => [
                    'ar' => 'كشف حساب من {1}',
                    'en' => 'Statement of account from {1}',
                ],
                'body' => [
                    'ar' => "تجدون مرفقًا كشف الحساب عن {2}.

الرصيد الختامي: {3}",
                    'en' => "Attached is your statement of account for {2}.

Closing balance: {3}",
                ],
            ],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | What a template may be used for
    |---------------------------------------------------------------------------
    |
    | Declared so that a marketing template cannot be used to deliver an
    | invoice. The provider's own categories carry different consent rules and
    | different pricing: a utility message is transactional and permitted
    | against an existing relationship, and a marketing one is not.
    |
    | Sending an invoice through a marketing template is a compliance problem
    | rather than a billing one, and the provider will not tell us - they
    | approved the template.
    |
    */

    'document_template_categories' => ['utility'],

];
