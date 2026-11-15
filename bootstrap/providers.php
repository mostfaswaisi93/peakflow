<?php

use App\Providers\AppServiceProvider;
use App\Providers\LedgerServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    LedgerServiceProvider::class,
];
