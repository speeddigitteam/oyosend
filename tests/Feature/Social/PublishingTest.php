<?php

declare(strict_types=1);

use App\Modules\Social\Actions\AccountManager;
use App\Modules\Social\Actions\PostPublisher;
use App\Modules\Social\Actions\PostWorkflow;
use App\Modules\Social\Actions\SocialSettings;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Enums\AttemptOutcome;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Events\PostFailed;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Models\PublishAttempt;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

beforeEach(function () {
    $this->tenant = $this->socialTenant('soc-pub');
    $this->fakePlatform();
    $this->account = $this->socialAccount($this->tenant);
    // A queued post, due now.
    $this->queued = function (array $attributes = [], ?SocialAccount $account = null): Post {
        $post = $this->socialPost($this->tenant, [($account ?? $this->account)->id], $attributes);

        return $this->inTenant($this->tenant, fn (): Post => app(PostWorkflow::class)->schedule($post, null));
    };
    $this->run = fn (): int => $this->inTenant($this->tenant, fn (): int => app(PostPublisher::class)->runDue());
    $this->target = fn (Post $post): PostTarget => $this->inTenant($this->tenant, fn () => PostTarget::query()->where('post_id', $post->id)->sole());
});

it('publishes a due post once and a second run publishes nothing', function () {
    $post = ($this->queued)();

    expect(($this->run)())->toBe(1)
        ->and($this->fake->published)->toHaveCount(1)
        ->and($this->fake->published[0]->text)->toBe('Hello world');

    expect(($this->run)())->toBe(0)->and($this->fake->published)->toHaveCount(1);

    $target = ($this->target)($post);
    $this->inTenant($this->tenant, function () use ($post, $target): void {
        expect(Post::query()->findOrFail($post->id)->status)->toBe(PostStatus::Published)
            ->and($target->status)->toBe(TargetStatus::Published)
            ->and($target->external_id)->toBe('post-1')
            ->and($target->external_url)->toBe('https://fake.test/p/post-1')
            ->and($target->attempts)->toBe(1)
            ->and(PublishAttempt::query()->where('target_id', $target->id)->sole()->outcome)->toBe(AttemptOutcome::Success);
    });
});

it('never publishes a target twice even when two workers race for it', function () {
    $post = ($this->queued)();
    $target = ($this->target)($post);

    $first = $this->inTenant($this->tenant, fn () => app(PostPublisher::class)->publishTarget($target->id));
    $second = $this->inTenant($this->tenant, fn () => app(PostPublisher::class)->publishTarget($target->id));

    expect($first)->toBe(AttemptOutcome::Success)
        ->and($second)->toBeNull()
        ->and($this->fake->published)->toHaveCount(1);
});

it('does not publish a post that is not scheduled, or whose time has not come', function () {
    $draft = $this->socialPost($this->tenant, [$this->account->id]);
    $future = $this->socialPost($this->tenant, [$this->account->id], ['body' => 'Later']);
    $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->schedule($future, CarbonImmutable::now()->addHours(2)));

    expect(($this->run)())->toBe(0)->and($this->fake->published)->toBe([]);

    $this->travel(3)->hours();

    expect(($this->run)())->toBe(1)->and($this->fake->published)->toHaveCount(1);
    $this->inTenant($this->tenant, fn () => expect(Post::query()->findOrFail($draft->id)->status)->toBe(PostStatus::Draft));
});

it('retries a rate-limited publish with backoff and then succeeds', function () {
    $post = ($this->queued)();
    $this->fake->script = [PublishFailed::rateLimited('Slow down')];

    expect(($this->run)())->toBe(1);

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Pending)
        ->and($target->attempts)->toBe(1)
        ->and($target->last_error_code)->toBe('rate_limited')
        ->and($target->next_attempt_at?->isFuture())->toBeTrue();
    $this->inTenant($this->tenant, fn () => expect(Post::query()->findOrFail($post->id)->status)->toBe(PostStatus::Scheduled));

    // Not due yet: nothing happens.
    expect(($this->run)())->toBe(0);

    $this->travel(2)->minutes();
    expect(($this->run)())->toBe(1);

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Published)
        ->and($target->attempts)->toBe(2)
        ->and($this->fake->published)->toHaveCount(1);
});

it('fails after the attempts are used and records every try', function () {
    config(['social.publishing.max_attempts' => 2]);
    Event::fake([PostFailed::class]);
    $post = ($this->queued)();
    $this->fake->script = [PublishFailed::rateLimited('Busy'), PublishFailed::rateLimited('Still busy')];

    ($this->run)();
    $this->travel(2)->minutes();
    ($this->run)();

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Failed)
        ->and($target->attempts)->toBe(2)
        ->and($target->last_error)->toBe('Still busy')
        ->and($target->needs_manual_check)->toBeFalse();

    $this->inTenant($this->tenant, function () use ($post, $target): void {
        expect(Post::query()->findOrFail($post->id)->status)->toBe(PostStatus::Failed)
            ->and(PublishAttempt::query()->where('target_id', $target->id)->orderBy('attempt')->pluck('outcome')->all())
            ->toBe([AttemptOutcome::Retry, AttemptOutcome::Failed]);
    });

    Event::assertDispatched(PostFailed::class);
});

it('fails a rejected post at once and lets a person retry it', function () {
    $post = ($this->queued)();
    $this->fake->script = [PublishFailed::rejected('Duplicate content', 'duplicate')];

    ($this->run)();
    $target = ($this->target)($post);

    expect($target->status)->toBe(TargetStatus::Failed)
        ->and($target->attempts)->toBe(1)
        ->and($target->last_error_code)->toBe('duplicate')
        ->and(($this->run)())->toBe(0);

    $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->retryTarget($post, $target));
    expect(($this->run)())->toBe(1);

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Published)->and($this->fake->published)->toHaveCount(1);
    $this->inTenant($this->tenant, fn () => expect(Post::query()->findOrFail($post->id)->status)->toBe(PostStatus::Published));
});

it('does not retry when the platform may have processed the request', function () {
    $post = ($this->queued)();
    $this->fake->script = [PublishFailed::unknownOutcome('No confirmation')];

    ($this->run)();
    $this->travel(2)->hours();
    ($this->run)();

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Failed)
        ->and($target->needs_manual_check)->toBeTrue()
        ->and($target->attempts)->toBe(1)
        ->and($this->fake->published)->toBe([]);
});

it('flags a claimed target that never reported back instead of publishing it again', function () {
    $post = ($this->queued)();
    $target = ($this->target)($post);
    $this->inTenant($this->tenant, fn () => $target->forceFill([
        'status' => TargetStatus::Publishing, 'claimed_at' => now()->subMinutes(30), 'attempts' => 1,
    ])->save());
    $this->inTenant($this->tenant, fn () => Post::query()->whereKey($post->id)->update(['status' => PostStatus::Publishing]));

    expect(($this->run)())->toBe(0);

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Failed)
        ->and($target->last_error_code)->toBe('outcome_unknown')
        ->and($target->needs_manual_check)->toBeTrue()
        ->and($this->fake->published)->toBe([]);
    $this->inTenant($this->tenant, fn () => expect(Post::query()->findOrFail($post->id)->status)->toBe(PostStatus::Failed));
});

it('defers publishing when the account hourly limit is reached without spending an attempt', function () {
    $this->inTenant($this->tenant, fn () => app(SocialSettings::class)->update(['account_hourly_limit' => 1]));
    ($this->queued)(['body' => 'One']);
    ($this->queued)(['body' => 'Two']);

    // One published, one deferred: both were handled by the run.
    expect(($this->run)())->toBe(2)->and($this->fake->published)->toHaveCount(1);

    $this->inTenant($this->tenant, function (): void {
        $pending = PostTarget::query()->where('status', TargetStatus::Pending)->sole();

        expect($pending->attempts)->toBe(0)
            ->and($pending->next_attempt_at?->isFuture())->toBeTrue()
            ->and(PostTarget::query()->where('status', TargetStatus::Published)->count())->toBe(1);
    });
});

it('limits publishes per workspace per minute', function () {
    config(['social.publishing.tenant_per_minute' => 1]);
    ($this->queued)(['body' => 'One']);
    ($this->queued)(['body' => 'Two']);

    expect(($this->run)())->toBe(1)->and($this->fake->published)->toHaveCount(1);

    // The workspace window is a minute.
    $this->inTenant($this->tenant, fn () => RateLimiter::clear('social:publish'));
    expect(($this->run)())->toBe(1)->and($this->fake->published)->toHaveCount(2);
});

it('sends an account to needs reauthorization when the platform rejects its token', function () {
    $post = ($this->queued)();
    $this->fake->script = [PublishFailed::reauthorization('Token revoked')];

    ($this->run)();

    $this->inTenant($this->tenant, function () use ($post): void {
        $account = SocialAccount::query()->findOrFail($this->account->id);

        expect($account->status)->toBe(AccountStatus::NeedsReauthorization)
            ->and($account->access_token)->toBeNull()
            ->and($account->refresh_token)->toBeNull()
            ->and(PostTarget::query()->where('post_id', $post->id)->sole()->status)->toBe(TargetStatus::Failed);
    });
});

it('refreshes an expiring token before publishing and stores the new one encrypted', function () {
    $account = $this->socialAccount($this->tenant, ['token_expires_at' => CarbonImmutable::now()->addMinutes(2)]);
    ($this->queued)([], $account);

    expect(($this->run)())->toBe(1)->and($this->fake->refreshes)->toBe(1);

    $this->inTenant($this->tenant, function () use ($account): void {
        $fresh = SocialAccount::query()->findOrFail($account->id);
        $raw = DB::table('social_accounts')->where('id', $account->id)->value('access_token');

        expect($fresh->access_token)->toBe('access-secret-refreshed')
            ->and($raw)->not->toBe('access-secret-refreshed')
            ->and($raw)->not->toContain('access-secret')
            ->and($fresh->refresh_token)->toBe('refresh-secret-2');
    });
});

it('asks for reauthorization when the token cannot be refreshed', function () {
    $this->fake->refreshFails = true;
    $account = $this->socialAccount($this->tenant, ['token_expires_at' => CarbonImmutable::now()->subMinute()]);
    $post = ($this->queued)([], $account);

    ($this->run)();

    $this->inTenant($this->tenant, function () use ($account, $post): void {
        $fresh = SocialAccount::query()->findOrFail($account->id);
        $target = PostTarget::query()->where('post_id', $post->id)->sole();

        expect($fresh->status)->toBe(AccountStatus::NeedsReauthorization)
            ->and($fresh->status_reason)->toBe('The refresh token was revoked.')
            ->and($target->status)->toBe(TargetStatus::Failed)
            ->and($target->last_error_code)->toBe('reauthorization_required');
    });
    expect($this->fake->published)->toBe([]);
});

it('fails the queued posts of an account that is disconnected', function () {
    $post = ($this->queued)();

    $this->inTenant($this->tenant, fn () => app(AccountManager::class)->disconnect($this->account));

    $this->inTenant($this->tenant, function () use ($post): void {
        $account = SocialAccount::query()->findOrFail($this->account->id);

        expect($account->status)->toBe(AccountStatus::Disconnected)->and($account->access_token)->toBeNull()
            ->and(PostTarget::query()->where('post_id', $post->id)->sole()->last_error_code)->toBe('account_disconnected')
            ->and(Post::query()->findOrFail($post->id)->status)->toBe(PostStatus::Failed);
    });
    expect(($this->run)())->toBe(0);
});

it('refuses to schedule content the platform cannot take', function () {
    $tooLong = $this->socialPost($this->tenant, [$this->account->id], ['body' => str_repeat('x', 101)]);

    expect(fn () => $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->schedule($tooLong, null)))
        ->toThrow(ValidationException::class);

    $noAccounts = $this->socialPost($this->tenant, []);
    expect(fn () => $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->schedule($noAccounts, null)))
        ->toThrow(ValidationException::class);

    $this->fake->available = false;
    $ok = $this->socialPost($this->tenant, [$this->account->id]);
    expect(fn () => $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->schedule($ok, null)))
        ->toThrow(ValidationException::class, 'Not configured for this test.');
});

it('counts a scheduled post once against the monthly limit and blocks the one over it', function () {
    $plan = $this->createFreePlan(['key' => 'one-post', 'modules' => ['social'], 'limits' => ['social.scheduled_posts_per_month' => 1]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);

    $first = $this->socialPost($this->tenant, [$this->account->id]);
    $second = $this->socialPost($this->tenant, [$this->account->id]);
    $workflow = fn (Post $post, ?CarbonImmutable $at) => $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->schedule($post, $at));

    $workflow($first, null);
    // Moving the same post back and forth does not use a second allowance.
    $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->unschedule($first));
    $workflow($this->inTenant($this->tenant, fn () => Post::query()->findOrFail($first->id)), CarbonImmutable::now()->addHour());

    expect(fn () => $workflow($second, null))->toThrow(ValidationException::class);
});

it('publishes nothing when the post was unscheduled or moved after the due ids were read', function () {
    $post = ($this->queued)();
    $target = ($this->target)($post);

    $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->unschedule($post));

    expect($this->inTenant($this->tenant, fn () => app(PostPublisher::class)->publishTarget($target->id)))->toBeNull()
        ->and($this->fake->published)->toBe([]);

    $this->inTenant($this->tenant, function () use ($post, $target): void {
        expect(PostTarget::query()->findOrFail($target->id)->status)->toBe(TargetStatus::Pending)
            ->and(PostTarget::query()->findOrFail($target->id)->attempts)->toBe(0)
            ->and(Post::query()->findOrFail($post->id)->status)->toBe(PostStatus::Draft);
    });

    $later = ($this->queued)(['body' => 'Moved']);
    $laterTarget = ($this->target)($later);
    $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->reschedule($later, CarbonImmutable::now()->addHours(3)));

    expect($this->inTenant($this->tenant, fn () => app(PostPublisher::class)->publishTarget($laterTarget->id)))->toBeNull()
        ->and($this->fake->published)->toBe([]);
});
