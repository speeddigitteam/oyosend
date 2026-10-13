<?php

declare(strict_types=1);

namespace App\Modules\MetaAds;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\ScheduledJobDefinition;
use App\Core\Billing\Enums\LimitKind;
use App\Core\Modules\AppOffer;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Modules\WidgetDefinition;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\MetaAds\Actions\ConnectionManager;
use App\Modules\MetaAds\Automation\Triggers\ConnectionNeedsReauthorizationTrigger;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Jobs\SyncAdAccounts;
use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Models\AdObject;
use App\Modules\MetaAds\Models\Change;
use App\Modules\MetaAds\Models\Connection;
use App\Modules\MetaAds\Models\Insight;
use App\Modules\MetaAds\Models\MetaAdsSetting;
use App\Modules\MetaAds\Reporting\ReportProviders;
use App\Modules\MetaAds\Widgets\MetaAdsOverviewWidget;
use Illuminate\Support\Facades\Route;

/**
 * Meta Ads: read and report on Meta (Facebook / Instagram) ad accounts through the documented Marketing API,
 * and make a small set of safe changes (pause, resume, edit an existing budget under a ceiling).
 * See docs/META_ADS.md and ADR 0027.
 */
final class MetaAdsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'meta_ads',
            name: 'Meta Ads',
            version: '1.0.0',
            dependencies: ['core', 'social'],
            permissions: [
                new PermissionDefinition('meta_ads.insights.view', 'meta_ads_insights'),
                new PermissionDefinition('meta_ads.insights.export', 'meta_ads_insights'),
                new PermissionDefinition('meta_ads.campaigns.view', 'meta_ads_campaigns'),
                new PermissionDefinition('meta_ads.campaigns.manage', 'meta_ads_campaigns', sensitive: true),
                new PermissionDefinition('meta_ads.accounts.view', 'meta_ads_accounts'),
                new PermissionDefinition('meta_ads.accounts.manage', 'meta_ads_accounts', sensitive: true),
                new PermissionDefinition('meta_ads.settings.manage', 'meta_ads_settings', sensitive: true),
            ],
            navigation: [
                new NavigationItem('metaAdsPerformance', 'meta_ads.performance.index', 'megaphone', 'meta_ads.insights.view', 'apps', 120, 'meta_ads.performance.*'),
                new NavigationItem('metaAdsCampaigns', 'meta_ads.campaigns.index', 'target', 'meta_ads.campaigns.view', 'apps', 121, 'meta_ads.campaigns.*'),
                new NavigationItem('metaAdsAccounts', 'meta_ads.accounts.index', 'layers', 'meta_ads.accounts.view', 'apps', 122, 'meta_ads.accounts.*'),
                new NavigationItem('metaAdsSettings', 'meta_ads.settings.index', 'settings', 'meta_ads.settings.manage', 'apps', 901, 'meta_ads.settings.*'),
            ],
            widgets: [
                new WidgetDefinition('meta_ads.overview', 'MetaAdsOverviewWidget', MetaAdsOverviewWidget::class, 'meta_ads.insights.view', span: 4, order: 20),
            ],
            triggers: [ConnectionNeedsReauthorizationTrigger::class],
            morphMap: [
                'meta_ads_connection' => Connection::class,
                'meta_ads_account' => AdAccount::class,
                'meta_ads_object' => AdObject::class,
                'meta_ads_insight' => Insight::class,
                'meta_ads_change' => Change::class,
                'meta_ads_setting' => MetaAdsSetting::class,
            ],
            limits: [
                new LimitDefinition(
                    ConnectionManager::LIMIT,
                    LimitKind::Gauge,
                    static fn (): int => AdAccount::query()->where('status', AdAccountStatus::Tracked->value)->count(),
                ),
            ],
            description: 'See what your Meta (Facebook and Instagram) ads spend and deliver, and pause or adjust budgets safely, from one workspace.',
            category: AppCategory::SocialMedia,
            icon: 'megaphone',
            features: [
                'Connect Meta ad accounts with Facebook Login',
                'Campaigns, ad sets and ads synced on a schedule',
                'Spend, reach, clicks and leads with trends and comparisons',
                'Formula-safe CSV export and reports',
                'Pause, resume and edit an existing budget, with confirmation and a ceiling',
                'Quota-aware syncing with honest status',
            ],
            offers: [new AppOffer('free', 'Free', AppBillingType::Free)],
            releaseNotes: 'Initial Meta Ads release. Reads ad accounts, campaigns and insights; pauses, resumes and edits budgets under a ceiling. Creating campaigns or ads and boosting posts are not included. Needs a Meta app approved for ads_read and ads_management.',
            routes: [
                'meta_ads.performance.index',
                'meta_ads.performance.export',
                'meta_ads.campaigns.index',
                'meta_ads.campaigns.status',
                'meta_ads.campaigns.budget',
                'meta_ads.accounts.index',
                'meta_ads.accounts.connect',
                'meta_ads.accounts.callback',
                'meta_ads.accounts.track',
                'meta_ads.accounts.untrack',
                'meta_ads.accounts.sync',
                'meta_ads.accounts.destroy',
                'meta_ads.settings.index',
                'meta_ads.settings.update',
            ],
            scheduledJobs: [
                new ScheduledJobDefinition('meta_ads.sync', SyncAdAccounts::class, ScheduledJobFrequency::Hourly),
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');

        // The central OAuth redirect broker (stateless; see docs/META_ADS.md).
        Route::domain((string) config('platform.domain'))
            ->middleware('central.callback')
            ->group(__DIR__.'/centralRoutes.php');
    }
}
