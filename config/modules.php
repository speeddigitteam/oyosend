<?php

use App\Core\CoreServiceProvider;
use App\Modules\Ai\AiServiceProvider;
use App\Modules\Automation\AutomationServiceProvider;
use App\Modules\Communication\CommunicationServiceProvider;
use App\Modules\CRM\CRMServiceProvider;
use App\Modules\EmailMarketing\EmailMarketingServiceProvider;
use App\Modules\Hrm\HrmServiceProvider;
use App\Modules\Invoicing\InvoicingServiceProvider;
use App\Modules\MetaAds\MetaAdsServiceProvider;
use App\Modules\Notes\NotesServiceProvider;
use App\Modules\Payroll\PayrollServiceProvider;
use App\Modules\Projects\ProjectsServiceProvider;
use App\Modules\Reports\ReportsServiceProvider;
use App\Modules\SmsMarketing\SmsMarketingServiceProvider;
use App\Modules\Social\SocialServiceProvider;
use App\Modules\Support\SupportServiceProvider;
use App\Modules\Talent\TalentServiceProvider;
use App\Modules\Telephony\TelephonyServiceProvider;
use App\Modules\WhatsappMarketing\WhatsappMarketingServiceProvider;

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
        HrmServiceProvider::class,
        PayrollServiceProvider::class,
        TalentServiceProvider::class,
        AutomationServiceProvider::class,
        AiServiceProvider::class,
        CommunicationServiceProvider::class,
        EmailMarketingServiceProvider::class,
        SocialServiceProvider::class,
        MetaAdsServiceProvider::class,
        SmsMarketingServiceProvider::class,
        SupportServiceProvider::class,
        WhatsappMarketingServiceProvider::class,
        ReportsServiceProvider::class,
        NotesServiceProvider::class,
        TelephonyServiceProvider::class,
    ],

];
