<?php

use App\Providers\ApiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BankingServiceProvider;
use App\Providers\InventoryServiceProvider;
use App\Providers\LedgerServiceProvider;
use App\Providers\MessagingServiceProvider;
use App\Providers\PayrollServiceProvider;
use App\Providers\PurchasingServiceProvider;
use App\Providers\QueueTenancyServiceProvider;
use App\Providers\ReportingServiceProvider;
use App\Providers\SalesServiceProvider;
use App\Providers\SettlementServiceProvider;
use App\Providers\TaxServiceProvider;
use App\Providers\TelemetryServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    ApiServiceProvider::class,
    QueueTenancyServiceProvider::class,

    /*
     | After QueueTenancyServiceProvider, and the order is load-bearing.
     |
     | `Queue::before` callbacks run in registration order. That provider's
     | callback adopts the correlation id from the job payload; this one opens
     | the job's span and takes its trace id from the correlation id. Opened
     | first, every job would be traced under whatever the worker last had -
     | which is the previous tenant's invoice.
     */
    TelemetryServiceProvider::class,
    LedgerServiceProvider::class,
    TaxServiceProvider::class,
    InventoryServiceProvider::class,
    SalesServiceProvider::class,
    PurchasingServiceProvider::class,
    BankingServiceProvider::class,
    SettlementServiceProvider::class,
    PayrollServiceProvider::class,
    MessagingServiceProvider::class,

    /*
     | Last, because the subledger tie it registers reaches the modules
     | above - and because a check that compares the documents to the
     | ledger is the last thing anybody should be able to say is missing.
     */
    ReportingServiceProvider::class,
];
