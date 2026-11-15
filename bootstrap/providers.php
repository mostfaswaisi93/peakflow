<?php

use App\Providers\AppServiceProvider;
use App\Providers\LedgerServiceProvider;
use App\Providers\QueueTenancyServiceProvider;
use App\Providers\SalesServiceProvider;
use App\Providers\TaxServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    QueueTenancyServiceProvider::class,
    LedgerServiceProvider::class,
    TaxServiceProvider::class,
    SalesServiceProvider::class,
];
