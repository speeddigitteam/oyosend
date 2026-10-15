<?php

declare(strict_types=1);

use App\Core\AppStore\AppCatalogSynchronizer;
use App\Core\AppStore\AppStoreManager;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\Jobs\RunScheduledAppJob;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Social\Actions\PostWorkflow;
use App\Modules\Social\Comments\Jobs\PollRecentComments;
use App\Modules\Social\Jobs\MaintainSocialAccounts;
use App\Modules\Social\Jobs\PublishDuePosts;
use App\Modules\Social\LeadAds\Jobs\SweepLeadAds;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Platforms\SocialPlatforms;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

beforeEach(function () {
    $this->tenant = $this->socialTenant('soc-access');
    $this->fakePlatform();
    $this->account = $this->socialAccount($this->tenant);
    $this->url = fn (string $path = '') => $this->socialUrl($this->tenant, $path);
});

it('answers 404 for every Social page when the workspace is not entitled to the app', function () {
    $plain = $this->createTenant('soc-plain');
    $this->actingAsOwner($plain);

    foreach (['/calendar', '/posts', '/posts/create', '/media', '/accounts', '/library', '/imports', '/analytics', '/settings'] as $path) {
        $this->get($this->socialUrl($plain, $path))->assertNotFound();
    }

    $this->post($this->socialUrl($plain, '/posts'), ['body' => 'x'])->assertNotFound();
});

it('sends signed-out visitors to sign in', function () {
    foreach (['/calendar', '/posts', '/accounts'] as $path) {
        $this->get(($this->url)($path))->assertRedirect();
    }
});

it('denies each Social area to a member who lacks its permission', function () {
    [$viewer] = $this->socialMember($this->tenant, ['social.posts.view']);
    $post = $this->socialPost($this->tenant, [$this->account->id]);
    $this->actingAsMember($this->tenant, $viewer);

    $this->get(($this->url)('/calendar'))->assertOk();
    $this->get(($this->url)('/posts'))->assertOk();
    $this->get(($this->url)("/posts/{$post->id}"))->assertOk();

    $this->get(($this->url)('/posts/create'))->assertForbidden();
    $this->post(($this->url)('/posts'), ['body' => 'x'])->assertForbidden();
    $this->get(($this->url)("/posts/{$post->id}/edit"))->assertForbidden();
    $this->delete(($this->url)("/posts/{$post->id}"))->assertForbidden();
    $this->post(($this->url)("/posts/{$post->id}/submit"))->assertForbidden();
    $this->post(($this->url)("/posts/{$post->id}/schedule"), ['publish_now' => true])->assertForbidden();
    $this->post(($this->url)("/posts/{$post->id}/approve"))->assertForbidden();
    $this->post(($this->url)("/posts/{$post->id}/comments"), ['body' => 'x'])->assertForbidden();
    $this->get(($this->url)('/media'))->assertForbidden();
    $this->post(($this->url)('/media'), [])->assertForbidden();
    $this->get(($this->url)('/accounts'))->assertForbidden();
    $this->post(($this->url)('/accounts/connect/fake'))->assertForbidden();
    $this->delete(($this->url)("/accounts/{$this->account->id}"))->assertForbidden();
    $this->get(($this->url)('/library'))->assertForbidden();
    $this->post(($this->url)('/library/categories'), ['name' => 'x'])->assertForbidden();
    $this->get(($this->url)('/imports'))->assertForbidden();
    $this->get(($this->url)('/analytics'))->assertForbidden();
    $this->get(($this->url)('/settings'))->assertForbidden();
    $this->put(($this->url)('/settings'), ['require_approval' => true, 'account_hourly_limit' => 5])->assertForbidden();
});

it('narrows posts to the viewer\'s own scope in the list, the calendar, the export and by id', function () {
    [$user, $membership] = $this->socialMember($this->tenant, ['social.posts.view'], ['social.posts.view' => 'own']);
    $owner = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    $mine = $this->socialPost($this->tenant, [$this->account->id], ['title' => 'Mine'], $membership);
    $theirs = $this->socialPost($this->tenant, [$this->account->id], ['title' => 'Theirs'], $owner);
    $this->actingAsMember($this->tenant, $user);

    $this->get(($this->url)('/posts'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $mine->id));
    $this->get(($this->url)('/calendar'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('posts', 0));
    $this->get(($this->url)("/posts/{$mine->id}"))->assertOk();
    $this->get(($this->url)("/posts/{$theirs->id}"))->assertNotFound();

    $export = $this->get(($this->url)('/posts/export'))->assertOk()->streamedContent();
    expect($export)->toContain('Mine')->and($export)->not->toContain('Theirs');
});

it('declares its permissions with the module prefix and the scopable ones', function () {
    $manifest = app(ModuleRegistry::class)->get('social');
    $names = array_map(fn ($permission): string => $permission->name, $manifest->permissions);
    $scopable = array_map(fn ($permission): string => $permission->name, array_filter($manifest->permissions, fn ($p): bool => $p->scopable));

    expect($names)->toContain('social.posts.approve', 'social.accounts.manage', 'social.posts.schedule')
        ->and(array_values($scopable))->toBe([
            'social.posts.view', 'social.posts.edit', 'social.posts.delete', 'social.posts.schedule',
            'social.comments.view', 'social.comments.reply', 'social.comments.moderate',
            'social.messages.view', 'social.messages.reply',
        ])
        ->and(collect($names)->every(fn (string $name): bool => str_starts_with($name, 'social.')))->toBeTrue();
});

it('queues the publish and maintenance schedules for a workspace that holds the app', function () {
    app(AppCatalogSynchronizer::class)->sync();
    $app = CatalogApp::query()->where('module_key', 'social')->with('currentVersion')->sole();

    expect($app->currentVersion?->scheduled_jobs)->toEqual([
        ['key' => 'social.publish_due', 'job' => PublishDuePosts::class, 'frequency' => ScheduledJobFrequency::EveryMinute->value],
        ['key' => 'social.maintenance', 'job' => MaintainSocialAccounts::class, 'frequency' => ScheduledJobFrequency::Hourly->value],
        ['key' => 'social.comments_poll', 'job' => PollRecentComments::class, 'frequency' => ScheduledJobFrequency::EveryMinute->value],
        ['key' => 'social.lead_ads_sweep', 'job' => SweepLeadAds::class, 'frequency' => ScheduledJobFrequency::EveryMinute->value],
    ]);

    $this->travelTo(now()->startOfHour());
    $licensed = $this->createTenant('soc-licensed', ['timezone' => 'UTC']);
    $this->inTenant($licensed, fn () => app(AppStoreManager::class)->installFree($app, 'free'));
    Queue::fake();

    $this->artisan('apps:run-scheduled', ['--tenant' => $licensed->slug])->assertSuccessful();

    // At the top of the hour every job is due: the three every-minute jobs and the hourly maintenance.
    Queue::assertPushed(RunScheduledAppJob::class, 4);
    Queue::assertPushed(RunScheduledAppJob::class, fn (RunScheduledAppJob $job): bool => $job->definition === 'social.publish_due');
});

it('publishes nothing at worker time once the workspace is no longer entitled', function () {
    $post = $this->socialPost($this->tenant, [$this->account->id]);
    $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->schedule($post, null));

    // The entitlement is withdrawn between dispatch and execution.
    $stranger = $this->createTenant('soc-withdrawn', ['timezone' => 'UTC']);
    $this->fakePlatform();
    $account = $this->socialAccount($stranger);
    $queued = $this->socialPost($stranger, [$account->id]);
    $this->inTenant($stranger, fn () => Post::query()->whereKey($queued->id)->update([
        'status' => 'scheduled', 'scheduled_for' => now(),
    ]));
    $this->inTenant($stranger, fn () => PostTarget::query()->update(['next_attempt_at' => now()]));

    $job = new RunScheduledAppJob('social', 'social.publish_due', new PublishDuePosts);
    $this->inTenant($stranger, fn () => app()->call([$job, 'handle']));

    expect($this->fake->published)->toBe([]);
    $this->inTenant($stranger, fn () => expect(PostTarget::query()->sole()->status->value)->toBe('pending'));

    // The entitled workspace does publish through the same runner.
    $job = new RunScheduledAppJob('social', 'social.publish_due', new PublishDuePosts);
    $this->inTenant($this->tenant, fn () => app()->call([$job, 'handle']));

    expect($this->fake->published)->toHaveCount(1);
});

it('lists every platform with an honest availability in the accounts page', function () {
    $this->actingAsOwner($this->tenant);
    app(SocialPlatforms::class)->get('fake');

    $this->get(($this->url)('/accounts'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('platforms', function ($platforms): bool {
                $byKey = collect($platforms)->keyBy('key');

                return $byKey->has(['facebook', 'linkedin', 'x', 'instagram', 'youtube', 'tiktok', 'pinterest', 'fake'])
                    && $byKey['instagram']['available'] === false
                    // Instagram has an adapter; it stays unavailable until the operator switches it on (ADR 0033).
                    && $byKey['instagram']['unavailableCode'] === 'not_configured'
                    && $byKey['facebook']['available'] === false
                    && $byKey['facebook']['unavailableCode'] === 'not_configured'
                    && $byKey['fake']['available'] === true;
            }));
});
