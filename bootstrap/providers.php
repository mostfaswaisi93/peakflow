<?php

use App\Providers\AppServiceProvider;
use App\Providers\BankingServiceProvider;
use App\Providers\InventoryServiceProvider;
use App\Providers\LedgerServiceProvider;
use App\Providers\MessagingServiceProvider;
use App\Providers\PayrollServiceProvider;
use App\Providers\PurchasingServiceProvider;
use App\Providers\QueueTenancyServiceProvider;
use App\Providers\SalesServiceProvider;
use App\Providers\SettlementServiceProvider;
use App\Providers\TaxServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    QueueTenancyServiceProvider::class,
    LedgerServiceProvider::class,
    TaxServiceProvider::class,
    InventoryServiceProvider::class,
    SalesServiceProvider::class,
    PurchasingServiceProvider::class,
    BankingServiceProvider::class,
    SettlementServiceProvider::class,
    PayrollServiceProvider::class,
    MessagingServiceProvider::class,
];
