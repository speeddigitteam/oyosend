<?php

use App\Core\CoreServiceProvider;
use App\Modules\CRM\CRMServiceProvider;
use App\Modules\Invoicing\InvoicingServiceProvider;
use App\Modules\Projects\ProjectsServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Module service providers
    |--------------------------------------------------------------------------
    |
    | Every first-party module registers exactly one provider here. The provider
    | publishes the module's manifest (permissions, navigation, widgets,
    | automation triggers and actions) and loads its routes and migrations.
    |
    | Listing a module here makes its code available to the platform. Whether a
    | given tenant may use it is a separate, server-side entitlement check.
    |
    */

    'providers' => [
        CoreServiceProvider::class,
        CRMServiceProvider::class,
        ProjectsServiceProvider::class,
        InvoicingServiceProvider::class,
    ],

];
