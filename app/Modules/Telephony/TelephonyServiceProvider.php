<?php

declare(strict_types=1);

namespace App\Modules\Telephony;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\Contacts\Contracts\CustomerRecordProvider;
use App\Core\Modules\AppOffer;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\Telephony\Automation\Triggers\CallEndedTrigger;
use App\Modules\Telephony\CustomerRecords\CallsSection;
use App\Modules\Telephony\Models\Call;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\TelephonySetting;
use App\Modules\Telephony\Pbx\FakePbxClient;
use App\Modules\Telephony\Reporting\ReportProviders;

/**
 * First-party telephony app (ADR 0036): a call log on the shared contacts, click-to-call, a dialer and a browser
 * softphone, through the workspace's own PBX that holds a licensed IPTSP's SIP trunk. Nothing rings until a PBX is
 * connected; every screen says so plainly.
 */
final class TelephonyServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        // The PBX that rings nobody exists only in local development and tests.
        if ($this->app->environment(['local', 'testing'])) {
            $this->app->singleton(FakePbxClient::class);
        }

        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);
        $this->app->tag([CallsSection::class], CustomerRecordProvider::TAG);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'telephony',
            name: 'Telephony',
            version: '1.0.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('telephony.calls.view', 'telephony', scopable: true),
                new PermissionDefinition('telephony.calls.make', 'telephony'),
                new PermissionDefinition('telephony.settings.manage', 'telephony'),
            ],
            navigation: [
                new NavigationItem('telephonyDialer', 'telephony.dialer', 'phone', 'telephony.calls.make', 'apps', 10, 'telephony.dialer'),
                new NavigationItem('telephonyCalls', 'telephony.calls.index', 'history', 'telephony.calls.view', 'apps', 11, 'telephony.calls.*'),
                new NavigationItem('telephonySettings', 'telephony.settings.index', 'settings', 'telephony.settings.manage', 'apps', 900, 'telephony.settings.*'),
            ],
            triggers: [
                CallEndedTrigger::class,
            ],
            morphMap: [
                'telephony_call' => Call::class,
                'telephony_extension' => Extension::class,
                'telephony_settings' => TelephonySetting::class,
            ],
            description: 'Call customers from the browser or your own phone through your company PBX, with every call logged on the contact.',
            category: AppCategory::CustomerSupport,
            icon: 'phone',
            features: [
                'Browser softphone: call and answer from the computer',
                'Dialer with Bangladeshi number formatting',
                'Click-to-call from a contact',
                'Every call logged on the contact timeline',
            ],
            offers: [
                new AppOffer('free', 'Free', AppBillingType::Free),
            ],
            releaseNotes: 'Initial telephony release. Needs the workspace\'s own PBX (Asterisk or FreeSWITCH) with an IPTSP SIP trunk.',
            routes: [
                'telephony.dialer',
                'telephony.softphone.credentials',
                'telephony.calls.start',
                'telephony.calls.report',
                'telephony.bridge',
                'telephony.calls.index',
                'telephony.settings.index',
                'telephony.settings.update',
                'telephony.settings.webhook-secret',
                'telephony.settings.extensions.store',
                'telephony.settings.extensions.update',
                'telephony.settings.extensions.destroy',
                'telephony.events',
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');
        $this->loadTenantCallbackRoutes(__DIR__.'/callbacks.php');
    }
}
