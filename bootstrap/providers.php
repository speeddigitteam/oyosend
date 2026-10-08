<?php

use App\Core\Modules\ModulesServiceProvider;
use App\Core\Tenancy\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    ModulesServiceProvider::class,
    HorizonServiceProvider::class,
];
