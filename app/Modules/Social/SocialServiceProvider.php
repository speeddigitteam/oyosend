<?php

declare(strict_types=1);

namespace App\Modules\Social;

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
use App\Modules\Social\Actions\AccountManager;
use App\Modules\Social\Actions\PostWorkflow;
use App\Modules\Social\Automation\Actions\CreateDraftPostAction;
use App\Modules\Social\Automation\Triggers\AccountNeedsReauthorizationTrigger;
use App\Modules\Social\Automation\Triggers\PostApprovedTrigger;
use App\Modules\Social\Automation\Triggers\PostFailedTrigger;
use App\Modules\Social\Automation\Triggers\PostPublishedTrigger;
use App\Modules\Social\Automation\Triggers\PostScheduledTrigger;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Jobs\MaintainSocialAccounts;
use App\Modules\Social\Jobs\PublishDuePosts;
use App\Modules\Social\Models\AutomationActionReceipt;
use App\Modules\Social\Models\Campaign;
use App\Modules\Social\Models\Category;
use App\Modules\Social\Models\Comment;
use App\Modules\Social\Models\HashtagSet;
use App\Modules\Social\Models\Import;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostMedia;
use App\Modules\Social\Models\PostMetric;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Models\PublishAttempt;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialSetting;
use App\Modules\Social\Platforms\SocialPlatforms;
use App\Modules\Social\Reporting\ReportProviders;
use App\Modules\Social\Widgets\SocialOverviewWidget;

/**
 * Social media management: connected accounts, a content calendar, approval, scheduled
 * publishing through platform adapters, a media library and honest engagement reports.
 * See docs/SOCIAL.md.
 */
final class SocialServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);

        // One registry per process so a platform added by a test or local override is seen everywhere.
        $this->app->singleton(SocialPlatforms::class);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'social',
            name: 'Social Media',
            version: '1.0.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('social.posts.view', 'social_posts', scopable: true),
                new PermissionDefinition('social.posts.create', 'social_posts'),
                new PermissionDefinition('social.posts.edit', 'social_posts', scopable: true),
                new PermissionDefinition('social.posts.delete', 'social_posts', scopable: true),
                new PermissionDefinition('social.posts.schedule', 'social_posts', scopable: true, sensitive: true),
                new PermissionDefinition('social.posts.approve', 'social_posts', sensitive: true),
                new PermissionDefinition('social.posts.comment', 'social_posts'),
                new PermissionDefinition('social.media.view', 'social_media'),
                new PermissionDefinition('social.media.manage', 'social_media'),
                new PermissionDefinition('social.accounts.view', 'social_accounts'),
                new PermissionDefinition('social.accounts.manage', 'social_accounts', sensitive: true),
                new PermissionDefinition('social.library.view', 'social_library'),
                new PermissionDefinition('social.library.manage', 'social_library'),
                new PermissionDefinition('social.imports.create', 'social_imports'),
                new PermissionDefinition('social.analytics.view', 'social_analytics'),
                new PermissionDefinition('social.settings.manage', 'social_settings', sensitive: true),
            ],
            navigation: [
                new NavigationItem('socialCalendar', 'social.calendar.index', 'calendar-days', 'social.posts.view', 'apps', 110, 'social.calendar.*'),
                new NavigationItem('socialPosts', 'social.posts.index', 'send', 'social.posts.view', 'apps', 111, 'social.posts.*'),
                new NavigationItem('socialMedia', 'social.media.index', 'image', 'social.media.view', 'apps', 112, 'social.media.*'),
                new NavigationItem('socialAccounts', 'social.accounts.index', 'share-2', 'social.accounts.view', 'apps', 113, 'social.accounts.*'),
                new NavigationItem('socialLibrary', 'social.library.index', 'hash', 'social.library.view', 'apps', 114, 'social.library.*'),
                new NavigationItem('socialAnalytics', 'social.analytics.index', 'chart-no-axes-combined', 'social.analytics.view', 'apps', 115, 'social.analytics.*'),
                new NavigationItem('socialImports', 'social.imports.index', 'upload', 'social.imports.create', 'apps', 116, 'social.imports.*'),
                new NavigationItem('socialSettings', 'social.settings.index', 'settings', 'social.settings.manage', 'manage', 38, 'social.settings.*'),
            ],
            widgets: [
                new WidgetDefinition('social.overview', 'SocialOverviewWidget', SocialOverviewWidget::class, 'social.posts.view', span: 4, order: 19),
            ],
            triggers: [
                PostScheduledTrigger::class,
                PostApprovedTrigger::class,
                PostPublishedTrigger::class,
                PostFailedTrigger::class,
                AccountNeedsReauthorizationTrigger::class,
            ],
            actions: [CreateDraftPostAction::class],
            morphMap: [
                'social_account' => SocialAccount::class,
                'social_post' => Post::class,
                'social_post_target' => PostTarget::class,
                'social_post_media' => PostMedia::class,
                'social_media' => Media::class,
                'social_campaign' => Campaign::class,
                'social_category' => Category::class,
                'social_hashtag_set' => HashtagSet::class,
                'social_comment' => Comment::class,
                'social_setting' => SocialSetting::class,
                'social_import' => Import::class,
                'social_publish_attempt' => PublishAttempt::class,
                'social_post_metric' => PostMetric::class,
                'social_automation_receipt' => AutomationActionReceipt::class,
            ],
            limits: [
                new LimitDefinition(PostWorkflow::LIMIT),
                new LimitDefinition(
                    AccountManager::LIMIT,
                    LimitKind::Gauge,
                    static fn (): int => SocialAccount::query()->where('status', '!=', AccountStatus::Disconnected->value)->count(),
                ),
            ],
            description: 'Plan, approve and schedule posts across connected social accounts, with a shared calendar and media library.',
            category: AppCategory::SocialMedia,
            icon: 'share-2',
            features: [
                'Content calendar and drafts with per-account variants',
                'Approval before anything is published',
                'Scheduled publishing with retries and failure handling',
                'Media library, campaigns and hashtag sets',
                'Bulk scheduling from CSV',
                'Engagement reports from what each platform returns',
            ],
            offers: [new AppOffer('free', 'Free', AppBillingType::Free)],
            releaseNotes: 'Initial Social Media release. Publishing adapters: Facebook Pages, LinkedIn (text), X (text); each needs the operator to configure and get approved a platform app.',
            routes: [
                'social.calendar.index',
                'social.posts.index',
                'social.posts.export',
                'social.posts.create',
                'social.posts.store',
                'social.posts.show',
                'social.posts.edit',
                'social.posts.update',
                'social.posts.destroy',
                'social.posts.submit',
                'social.posts.schedule',
                'social.posts.reschedule',
                'social.posts.unschedule',
                'social.posts.targets.retry',
                'social.posts.approve',
                'social.posts.reject',
                'social.posts.comments.store',
                'social.posts.comments.destroy',
                'social.media.index',
                'social.media.file',
                'social.media.store',
                'social.media.update',
                'social.media.destroy',
                'social.accounts.index',
                'social.accounts.connect',
                'social.accounts.callback',
                'social.accounts.select',
                'social.accounts.select.store',
                'social.accounts.destroy',
                'social.library.index',
                'social.library.campaigns.store',
                'social.library.campaigns.update',
                'social.library.campaigns.destroy',
                'social.library.categories.store',
                'social.library.categories.update',
                'social.library.categories.destroy',
                'social.library.hashtag-sets.store',
                'social.library.hashtag-sets.update',
                'social.library.hashtag-sets.destroy',
                'social.imports.index',
                'social.imports.template',
                'social.imports.store',
                'social.analytics.index',
                'social.settings.index',
                'social.settings.update',
            ],
            scheduledJobs: [
                new ScheduledJobDefinition('social.publish_due', PublishDuePosts::class, ScheduledJobFrequency::EveryMinute),
                new ScheduledJobDefinition('social.maintenance', MaintainSocialAccounts::class, ScheduledJobFrequency::Hourly),
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');
    }
}
