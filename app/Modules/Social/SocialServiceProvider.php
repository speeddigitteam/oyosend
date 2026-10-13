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
use App\Core\Social\Contracts\ConnectedPages;
use App\Modules\Social\Actions\AccountManager;
use App\Modules\Social\Actions\PostWorkflow;
use App\Modules\Social\Automation\Actions\CreateDraftPostAction;
use App\Modules\Social\Automation\Triggers\AccountNeedsReauthorizationTrigger;
use App\Modules\Social\Automation\Triggers\PostApprovedTrigger;
use App\Modules\Social\Automation\Triggers\PostFailedTrigger;
use App\Modules\Social\Automation\Triggers\PostPublishedTrigger;
use App\Modules\Social\Automation\Triggers\PostScheduledTrigger;
use App\Modules\Social\Comments\Automation\CommentReceivedTrigger;
use App\Modules\Social\Comments\Automation\HideCommentAction;
use App\Modules\Social\Comments\Automation\ReplyToCommentAction;
use App\Modules\Social\Comments\Jobs\PollRecentComments;
use App\Modules\Social\Comments\Models\AudienceComment;
use App\Modules\Social\Comments\Models\CommentNote;
use App\Modules\Social\Comments\Models\CommentRule;
use App\Modules\Social\Comments\Models\CommentSource;
use App\Modules\Social\Comments\Reporting\CommentVolumeReport;
use App\Modules\Social\Comments\Widgets\SocialCommentsWidget;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Jobs\MaintainSocialAccounts;
use App\Modules\Social\Jobs\PublishDuePosts;
use App\Modules\Social\LeadAds\Automation\LeadAdReceivedTrigger;
use App\Modules\Social\LeadAds\Jobs\SweepLeadAds;
use App\Modules\Social\LeadAds\Models\LeadAdLead;
use App\Modules\Social\LeadAds\Models\LeadAdsPage;
use App\Modules\Social\LeadAds\Models\LeadForm;
use App\Modules\Social\LeadAds\Reporting\LeadAdsReport;
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
use App\Modules\Social\Support\SocialConnectedPages;
use App\Modules\Social\Widgets\SocialOverviewWidget;
use Illuminate\Support\Facades\Route;

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
        $this->app->tag([LeadAdsReport::class], ReportProvider::TAG);
        $this->app->tag([CommentVolumeReport::class], ReportProvider::TAG);

        // One registry per process so a platform added by a test or local override is seen everywhere.
        $this->app->singleton(SocialPlatforms::class);

        // The token-free list of connected Facebook Pages other apps may read (ADR 0027).
        $this->app->bind(ConnectedPages::class, SocialConnectedPages::class);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'social',
            name: 'Social Media',
            version: '1.4.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('social.posts.view', 'social_posts', scopable: true),
                new PermissionDefinition('social.posts.create', 'social_posts'),
                new PermissionDefinition('social.posts.edit', 'social_posts', scopable: true),
                new PermissionDefinition('social.posts.delete', 'social_posts', scopable: true),
                new PermissionDefinition('social.posts.schedule', 'social_posts', scopable: true, sensitive: true),
                new PermissionDefinition('social.posts.approve', 'social_posts', sensitive: true),
                new PermissionDefinition('social.posts.comment', 'social_posts'),
                new PermissionDefinition('social.comments.view', 'social_comments', scopable: true),
                new PermissionDefinition('social.comments.reply', 'social_comments', scopable: true),
                new PermissionDefinition('social.comments.moderate', 'social_comments', scopable: true, sensitive: true),
                new PermissionDefinition('social.comments.manage_rules', 'social_comments', sensitive: true),
                new PermissionDefinition('social.posts.ai_assist', 'social_posts'),
                new PermissionDefinition('social.media.view', 'social_media'),
                new PermissionDefinition('social.media.manage', 'social_media'),
                new PermissionDefinition('social.accounts.view', 'social_accounts'),
                new PermissionDefinition('social.accounts.manage', 'social_accounts', sensitive: true),
                new PermissionDefinition('social.leadads.view', 'social_leadads'),
                new PermissionDefinition('social.leadads.manage', 'social_leadads', sensitive: true),
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
                new NavigationItem('socialComments', 'social.comments.index', 'message-circle', 'social.comments.view', 'apps', 118, 'social.comments.index'),
                new NavigationItem('socialCommentRules', 'social.comments.rules.index', 'list-checks', 'social.comments.manage_rules', 'apps', 119, 'social.comments.rules.*'),
                new NavigationItem('socialLeadAds', 'social.leadads.index', 'file-text', 'social.leadads.view', 'apps', 117, 'social.leadads.*'),
                new NavigationItem('socialLibrary', 'social.library.index', 'hash', 'social.library.view', 'apps', 114, 'social.library.*'),
                new NavigationItem('socialAnalytics', 'social.analytics.index', 'chart-no-axes-combined', 'social.analytics.view', 'apps', 115, 'social.analytics.*'),
                new NavigationItem('socialImports', 'social.imports.index', 'upload', 'social.imports.create', 'apps', 116, 'social.imports.*'),
                new NavigationItem('socialSettings', 'social.settings.index', 'settings', 'social.settings.manage', 'apps', 900, 'social.settings.*'),
            ],
            widgets: [
                new WidgetDefinition('social.overview', 'SocialOverviewWidget', SocialOverviewWidget::class, 'social.posts.view', span: 4, order: 19),
                new WidgetDefinition('social.comments', 'SocialCommentsWidget', SocialCommentsWidget::class, 'social.comments.view', span: 4, order: 20),
            ],
            triggers: [
                PostScheduledTrigger::class,
                PostApprovedTrigger::class,
                PostPublishedTrigger::class,
                PostFailedTrigger::class,
                AccountNeedsReauthorizationTrigger::class,
                CommentReceivedTrigger::class,
                LeadAdReceivedTrigger::class,
            ],
            actions: [CreateDraftPostAction::class, ReplyToCommentAction::class, HideCommentAction::class],
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
                'social_audience_comment' => AudienceComment::class,
                'social_comment_note' => CommentNote::class,
                'social_comment_rule' => CommentRule::class,
                'social_comment_source' => CommentSource::class,
                'social_lead_ads_page' => LeadAdsPage::class,
                'social_lead_form' => LeadForm::class,
                'social_lead_ad_lead' => LeadAdLead::class,
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
                'Facebook lead forms with field mapping, sent to the CRM',
                'Audience comments inbox for Facebook Pages with replies, moderation and keyword rules',
                'Engagement reports from what each platform returns',
                'Page performance dashboard: followers, reach, best posting times and campaign reports',
                'AI suggestions for captions and hashtags that a person always accepts',
                'Photo, video and Reels publishing for Facebook Pages, with checks before scheduling',
            ],
            offers: [new AppOffer('free', 'Free', AppBillingType::Free)],
            releaseNotes: 'Version 1.4 adds an audience comments inbox for Facebook Pages (read, reply, like, hide, delete, assign, internal notes and bulk actions), live comment webhooks with a polling fallback, and keyword rules that reply, hide, tag, assign or send comments to review within hourly caps, per-person cooldowns and quiet hours, with a test box and a pause switch. It needs the pages_read_engagement, pages_manage_engagement and pages_manage_metadata permissions from Meta app review, and pages_messaging for private replies. Version 1.3 adds Facebook lead forms (Lead forms page): after a signed Meta webhook the lead-form answers of a Page are fetched and handed to the CRM with a field mapping you choose, with a processing log, a fetch of recent leads, an automation trigger and a report. It needs the leads_retrieval, pages_manage_ads and pages_manage_metadata permissions from Meta app review and the Meta webhooks product; without the CRM app leads are kept and sent when it is available. Version 1.2 adds a Page performance dashboard to Analytics (followers, reach, impressions and engagement per Facebook Page from the Page Insights of the platform, 7/28/90-day periods, comparison of pages, best posting times from your own posts, top and bottom posts, content-type and campaign comparison, CSV export), page insights and campaign performance reports, the Meta tasks and permissions of each connected Page, and a per-account allow-list of who may publish, approve and see analytics. It needs the read_insights, pages_read_engagement and pages_show_list permissions from Meta app review. Version 1.1 adds AI assist in the post composer (captions, hashtags, rewrite, shorten, translate; suggestions only, marked on the post) and a sentiment summary for analysed audience comments. It needs the AI app and a configured provider. It also adds photo, video and Reels publishing for Facebook Pages (several photos in one post, resumable video upload, Reels), with checks before scheduling and follow-up until the platform finishes processing a video. Video and Reels need the publish_video permission from Meta app review. Initial Social Media release. Publishing adapters: Facebook Pages, LinkedIn (text), X (text); each needs the operator to configure and get approved a platform app.',
            routes: [
                'social.calendar.index',
                'social.posts.index',
                'social.posts.export',
                'social.posts.preflight',
                'social.posts.create',
                'social.posts.ai',
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
                'social.leadads.index',
                'social.leadads.enable',
                'social.leadads.disable',
                'social.leadads.forms.refresh',
                'social.leadads.backfill',
                'social.leadads.forms.switch',
                'social.leadads.forms.mapping',
                'social.leadads.forms.preview',
                'social.leadads.leads.retry',
                'social.leadads.webhook.verify',
                'social.leadads.webhook.receive',
                'social.accounts.access.update',
                'social.accounts.access.check',
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
                'social.analytics.export',
                'social.analytics.sentiment',
                'social.settings.index',
                'social.settings.update',
                'social.comments.index',
                'social.comments.bulk',
                'social.comments.read',
                'social.comments.reply',
                'social.comments.like',
                'social.comments.handled',
                'social.comments.notes.store',
                'social.comments.hidden',
                'social.comments.assign',
                'social.comments.tags.store',
                'social.comments.tags.destroy',
                'social.comments.destroy',
                'social.comments.rules.index',
                'social.comments.rules.store',
                'social.comments.rules.preview',
                'social.comments.rules.update',
                'social.comments.rules.toggle',
                'social.comments.rules.destroy',
                'social.comments.automation',
                'social.comments.limits',
                'social.comments.sources.enable',
                'social.comments.sources.disable',
                'social.comments.webhook.receive',
                'social.comments.webhook.verify',
            ],
            scheduledJobs: [
                new ScheduledJobDefinition('social.publish_due', PublishDuePosts::class, ScheduledJobFrequency::EveryMinute),
                new ScheduledJobDefinition('social.maintenance', MaintainSocialAccounts::class, ScheduledJobFrequency::Hourly),
                new ScheduledJobDefinition('social.comments_poll', PollRecentComments::class, ScheduledJobFrequency::EveryMinute),
                new ScheduledJobDefinition('social.lead_ads_sweep', SweepLeadAds::class, ScheduledJobFrequency::EveryMinute),
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');
        $this->loadTenantRoutes(__DIR__.'/LeadAds/routes.php');
        $this->loadTenantRoutes(__DIR__.'/Comments/routes.php');
        $this->loadTenantCallbackRoutes(__DIR__.'/Comments/callbacks.php');
        Route::domain((string) config('platform.domain'))
            ->middleware('central.callback')
            ->group(__DIR__.'/Comments/centralCallbacks.php');
        $this->loadTenantCallbackRoutes(__DIR__.'/LeadAds/callbacks.php');

        // The central OAuth redirect broker (stateless; see docs/SOCIAL.md).
        Route::domain((string) config('platform.domain'))
            ->middleware('central.callback')
            ->group(__DIR__.'/centralRoutes.php');
    }
}
