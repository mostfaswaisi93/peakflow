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

    'driver' => 'fake',

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
