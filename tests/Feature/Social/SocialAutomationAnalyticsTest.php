<?php

declare(strict_types=1);

use App\Core\Automation\AutomationRegistry;
use App\Modules\Social\Actions\MetricsCollector;
use App\Modules\Social\Actions\PostPublisher;
use App\Modules\Social\Actions\PostWorkflow;
use App\Modules\Social\Automation\Actions\CreateDraftPostAction;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Events\AccountNeedsReauthorization;
use App\Modules\Social\Events\PostFailed;
use App\Modules\Social\Events\PostPublished;
use App\Modules\Social\Models\AutomationActionReceipt;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostMetric;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\PostMetricsSnapshot;
use App\Modules\Social\Widgets\SocialOverviewWidget;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

beforeEach(function () {
    $this->tenant = $this->socialTenant('soc-auto');
    $this->fakePlatform();
    $this->account = $this->socialAccount($this->tenant);
    $this->url = fn (string $path = '') => $this->socialUrl($this->tenant, $path);
    $this->publish = function (array $attributes = []): Post {
        $post = $this->socialPost($this->tenant, [$this->account->id], $attributes);
        $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->schedule($post, null));
        $this->inTenant($this->tenant, fn () => app(PostPublisher::class)->runDue());

        return $this->inTenant($this->tenant, fn () => Post::query()->findOrFail($post->id));
    };
});

it('registers its triggers and its draft action with their permissions', function () {
    $registry = app(AutomationRegistry::class);

    foreach (['social.post.scheduled', 'social.post.approved', 'social.post.published', 'social.post.failed', 'social.account.needs_reauthorization'] as $key) {
        expect($registry->trigger($key))->not->toBeNull();
    }

    expect($registry->action('social.post.create_draft'))->toBe(CreateDraftPostAction::class);
});

it('creates a draft once per idempotency key and never schedules', function () {
    $action = $this->inTenant($this->tenant, fn () => app(CreateDraftPostAction::class));
    $input = ['body' => 'From a workflow', 'account_ids' => [$this->account->id], 'title' => 'Auto'];

    $first = $this->inTenant($this->tenant, fn () => $action->execute($input, 'run-1:step-1'));
    $again = $this->inTenant($this->tenant, fn () => $action->execute($input, 'run-1:step-1'));
    $other = $this->inTenant($this->tenant, fn () => $action->execute($input, 'run-2:step-1'));

    expect($first)->toBe($again)->and($other['post_id'])->not->toBe($first['post_id'])
        ->and($first['status'])->toBe('draft');

    $this->inTenant($this->tenant, function () use ($first): void {
        expect(Post::query()->count())->toBe(2)
            ->and(AutomationActionReceipt::query()->count())->toBe(2)
            ->and(Post::query()->findOrFail($first['post_id'])->status)->toBe(PostStatus::Draft);
    });
});

it('refuses an automation draft aimed at another workspace\'s account', function () {
    $other = $this->socialTenant('soc-auto-other');
    $foreign = $this->socialAccount($other);
    $action = $this->inTenant($this->tenant, fn () => app(CreateDraftPostAction::class));

    expect(fn () => $this->inTenant($this->tenant, fn () => $action->execute(['body' => 'x', 'account_ids' => [$foreign->id]], 'k-1')))
        ->toThrow(ValidationException::class);
    $this->inTenant($this->tenant, fn () => expect(Post::query()->count())->toBe(0)->and(AutomationActionReceipt::query()->count())->toBe(0));
});

it('announces published, failed and reauthorization events with ids only', function () {
    Event::fake([
        PostPublished::class,
        PostFailed::class,
        AccountNeedsReauthorization::class,
    ]);

    $post = ($this->publish)(['body' => 'Public words']);

    Event::assertDispatched(PostPublished::class, function ($event) use ($post): bool {
        $payload = $event->payload();

        return $payload['post_id'] === $post->id && $payload['target_count'] === 1 && ! in_array('Public words', $payload, true);
    });

    $this->fake->script = [PublishFailed::reauthorization('Revoked')];
    ($this->publish)();

    Event::assertDispatched(PostFailed::class);
    Event::assertDispatched(AccountNeedsReauthorization::class, fn ($event): bool => $event->payload() === [
        'account_id' => $this->account->id, 'platform' => 'fake',
    ]);
});

it('stores only the figures a platform returned and reports missing ones as not reported', function () {
    ($this->publish)(['body' => 'Measured']);
    $this->fake->metricsScript = [new PostMetricsSnapshot(likes: 7, comments: 2)];

    expect($this->inTenant($this->tenant, fn () => app(MetricsCollector::class)->run()))->toBe(1);

    $metric = $this->inTenant($this->tenant, fn () => PostMetric::query()->sole());
    expect($metric->likes)->toBe(7)->and($metric->comments)->toBe(2)
        ->and($metric->impressions)->toBeNull()->and($metric->shares)->toBeNull();

    // Fresh enough: not asked again until the refresh interval has passed.
    expect($this->inTenant($this->tenant, fn () => app(MetricsCollector::class)->run()))->toBe(0);

    $this->actingAsOwner($this->tenant);
    $this->get(($this->url)('/analytics'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('report.publishing.published', 1)
        ->where('report.engagement.reportedPosts', 1)
        ->where('report.engagement.totals.likes', 7)
        ->where('report.engagement.totals.comments', 2)
        ->where('report.engagement.totals.impressions', null)
        ->where('report.engagement.totals.reach', null)
        ->where('report.engagement.topPosts.0.figures.shares', null)
        ->where('report.platformsWithAnalytics', ['fake']));
});

it('invents nothing when no platform returned data', function () {
    ($this->publish)();
    $this->fake->metricsScript = [null];
    $this->inTenant($this->tenant, fn () => app(MetricsCollector::class)->run());

    $this->actingAsOwner($this->tenant);
    $this->get(($this->url)('/analytics'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('report.publishing.published', 1)
        ->where('report.engagement.reportedPosts', 0)
        ->where('report.engagement.totals.likes', null)
        ->where('report.engagement.capturedAt', null)
        ->has('report.engagement.topPosts', 0));

    $this->inTenant($this->tenant, fn () => expect(PostMetric::query()->count())->toBe(0));
});

it('does not ask a platform without analytics, or an account that must reauthorize', function () {
    ($this->publish)();
    $this->inTenant($this->tenant, fn () => PostTarget::query()->update(['metrics_synced_at' => null]));
    $this->inTenant($this->tenant, fn () => $this->account->forceFill(['status' => AccountStatus::NeedsReauthorization])->save());
    $this->fake->metricsScript = [new PostMetricsSnapshot(likes: 1)];

    expect($this->inTenant($this->tenant, fn () => app(MetricsCollector::class)->run()))->toBe(0)
        ->and($this->fake->metricsScript)->toHaveCount(1);
});

it('shows the dashboard widget counts for the viewer', function () {
    $this->socialPost($this->tenant, [$this->account->id]);
    $queued = $this->socialPost($this->tenant, [$this->account->id]);
    $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->schedule($queued, now()->addDay()->toImmutable()));

    $owner = $this->ownerOf($this->tenant);
    $data = $this->inTenant($this->tenant, fn () => app(SocialOverviewWidget::class)($owner));

    expect($data)->toMatchArray(['scheduled' => 1, 'awaitingApproval' => 0, 'failed' => 0, 'accountsNeedingAttention' => 0])
        ->and($data['nextScheduledFor'])->not->toBeNull();
});
