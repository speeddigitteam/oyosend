<?php

declare(strict_types=1);

use App\Modules\Social\Actions\PostManager;
use App\Modules\Social\Actions\PostWorkflow;
use App\Modules\Social\Actions\SocialSettings;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Models\Campaign;
use App\Modules\Social\Models\Comment;
use App\Modules\Social\Models\HashtagSet;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostTarget;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

beforeEach(function () {
    $this->tenant = $this->socialTenant('soc-posts');
    $this->fakePlatform();
    $this->account = $this->socialAccount($this->tenant, ['name' => 'Main Page']);
    $this->url = fn (string $path = '') => $this->socialUrl($this->tenant, $path);
    $this->owner = $this->ownerOf($this->tenant);
    $this->approval = fn (bool $on = true) => $this->inTenant($this->tenant, fn () => app(SocialSettings::class)->update(['require_approval' => $on]));
    $this->state = fn (Post $post): PostStatus => $this->inTenant($this->tenant, fn () => Post::query()->findOrFail($post->id)->status);
});

it('creates a draft with per-account text, a hashtag set and a campaign, and previews each account', function () {
    $other = $this->socialAccount($this->tenant, ['name' => 'Second Page']);
    $set = $this->inTenant($this->tenant, fn () => HashtagSet::query()->create(['name' => 'Brand', 'tags' => ['#brand', '#launch']]));
    $campaign = $this->inTenant($this->tenant, fn () => Campaign::query()->create(['name' => 'Spring', 'status' => 'active']));
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/posts'), [
        'title' => 'Launch day',
        'body' => 'We are live',
        'link_url' => 'https://example.com/launch',
        'hashtag_set_id' => $set->id,
        'campaign_id' => $campaign->id,
        'targets' => [
            ['account_id' => $this->account->id],
            ['account_id' => $other->id, 'body_override' => 'Short variant'],
        ],
    ])->assertRedirect();

    $post = $this->inTenant($this->tenant, fn () => Post::query()->with('targets')->sole());
    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->owner_id)->toBe($this->membershipOf($this->tenant, $this->owner)->id)
        ->and($post->hashtags)->toBe(['#brand', '#launch'])
        ->and($post->targets)->toHaveCount(2);

    $this->get(($this->url)("/posts/{$post->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('post.status', 'draft')
            ->where('post.campaign.name', 'Spring')
            ->has('post.previews', 2)
            ->where('post.previews.0.text', "We are live\n\n#brand #launch")
            ->where('post.previews.1.text', "Short variant\n\n#brand #launch")
            ->where('post.abilities.edit', true)
            ->where('post.abilities.schedule', true)
            ->where('post.abilities.approve', false));
});

it('validates post input and never reads the owner, status or tenant from it', function () {
    $this->actingAsOwner($this->tenant);
    $ok = ['body' => 'x', 'targets' => [['account_id' => $this->account->id]]];

    $this->post(($this->url)('/posts'), [...$ok, 'body' => str_repeat('x', 5001)])->assertSessionHasErrors('body');
    $this->post(($this->url)('/posts'), [...$ok, 'link_url' => 'javascript:alert(1)'])->assertSessionHasErrors('link_url');
    $this->post(($this->url)('/posts'), [...$ok, 'scheduled_for' => 'tomorrow'])->assertSessionHasErrors('scheduled_for');
    $this->post(($this->url)('/posts'), ['targets' => []])->assertSessionHasErrors('body');
    $this->post(($this->url)('/posts'), [...$ok, 'status' => 'published', 'owner_id' => 'x', 'tenant_id' => 'y', 'approved_by' => 'z'])->assertRedirect();

    $post = $this->inTenant($this->tenant, fn () => Post::query()->sole());
    expect($post->status)->toBe(PostStatus::Draft)->and($post->approved_by)->toBeNull();
});

it('requires a different member to approve, and the owner of the post can never approve it', function () {
    ($this->approval)();
    [$author, $authorMember] = $this->socialMember($this->tenant, ['social.posts.view', 'social.posts.create', 'social.posts.edit', 'social.posts.schedule', 'social.posts.approve']);
    [$reviewer, $reviewerMember] = $this->socialMember($this->tenant, ['social.posts.view', 'social.posts.approve']);
    $post = $this->socialPost($this->tenant, [$this->account->id], [], $authorMember);

    // Scheduling is refused until it is approved.
    $this->actingAsMember($this->tenant, $author);
    $this->post(($this->url)("/posts/{$post->id}/schedule"), ['publish_now' => true])->assertSessionHasErrors('post');
    $this->post(($this->url)("/posts/{$post->id}/submit"))->assertRedirect();
    expect(($this->state)($post))->toBe(PostStatus::PendingApproval);

    // Four-eyes: the author (who also submitted) cannot approve, despite holding the permission.
    $this->post(($this->url)("/posts/{$post->id}/approve"))->assertSessionHasErrors('approval');
    expect(($this->state)($post))->toBe(PostStatus::PendingApproval);
    $this->get(($this->url)("/posts/{$post->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('post.abilities.approve', false)->where('post.abilities.reject', true));

    // A different member can.
    $this->actingAsMember($this->tenant, $reviewer);
    $this->get(($this->url)("/posts/{$post->id}"))->assertInertia(fn (AssertableInertia $page) => $page->where('post.abilities.approve', true));
    $this->post(($this->url)("/posts/{$post->id}/approve"))->assertRedirect();

    $fresh = $this->inTenant($this->tenant, fn () => Post::query()->findOrFail($post->id));
    expect($fresh->status)->toBe(PostStatus::Approved)
        ->and($fresh->approved_by)->toBe($reviewerMember->id)
        ->and($fresh->submitted_by)->toBe($authorMember->id);
});

it('does not let a submitter approve even when someone else wrote the post', function () {
    ($this->approval)();
    [, $writer] = $this->socialMember($this->tenant, ['social.posts.view']);
    [$submitter, $submitterMember] = $this->socialMember($this->tenant, ['social.posts.view', 'social.posts.schedule', 'social.posts.approve']);
    $post = $this->socialPost($this->tenant, [$this->account->id], [], $writer);

    $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->submit($post, $submitterMember));
    $this->actingAsMember($this->tenant, $submitter);

    $this->post(($this->url)("/posts/{$post->id}/approve"))->assertSessionHasErrors('approval');

    // The workspace owner (neither author nor submitter) may.
    $this->actingAsOwner($this->tenant);
    $this->post(($this->url)("/posts/{$post->id}/approve"))->assertRedirect();
    expect(($this->state)($post))->toBe(PostStatus::Approved);
});

it('sends a rejected post back with the reason and clears the submission', function () {
    ($this->approval)();
    $post = $this->socialPost($this->tenant, [$this->account->id]);
    $reviewerMembership = $this->membershipOf($this->tenant, $this->owner);
    [, $author] = $this->socialMember($this->tenant, ['social.posts.view']);
    $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->submit($post, $author));

    $this->actingAsOwner($this->tenant);
    $this->post(($this->url)("/posts/{$post->id}/reject"), [])->assertSessionHasErrors('reason');
    $this->post(($this->url)("/posts/{$post->id}/reject"), ['reason' => 'Fix the tone'])->assertRedirect();

    $fresh = $this->inTenant($this->tenant, fn () => Post::query()->findOrFail($post->id));
    expect($fresh->status)->toBe(PostStatus::Draft)
        ->and($fresh->rejection_reason)->toBe('Fix the tone')
        ->and($fresh->rejected_by)->toBe($reviewerMembership->id)
        ->and($fresh->submitted_by)->toBeNull();
});

it('puts an edited approved or scheduled post back to draft so approval always covers what is published', function () {
    ($this->approval)();
    $post = $this->socialPost($this->tenant, [$this->account->id], ['scheduled_for' => now()->addDay()]);
    [, $author] = $this->socialMember($this->tenant, ['social.posts.view']);
    $this->inTenant($this->tenant, function () use ($post, $author): void {
        $workflow = app(PostWorkflow::class);
        $workflow->submit($post, $author);
        $workflow->approve($post->refresh(), $this->membershipOf($this->tenant, $this->owner));
    });

    // Approved with a future planned time: queued straight away.
    expect(($this->state)($post))->toBe(PostStatus::Scheduled);

    $this->actingAsOwner($this->tenant);
    $this->put(($this->url)("/posts/{$post->id}"), ['body' => 'Changed after approval', 'targets' => [['account_id' => $this->account->id]]])->assertRedirect();

    $fresh = $this->inTenant($this->tenant, fn () => Post::query()->findOrFail($post->id));
    expect($fresh->status)->toBe(PostStatus::Draft)
        ->and($fresh->approved_by)->toBeNull()
        ->and($fresh->body)->toBe('Changed after approval');
    $this->inTenant($this->tenant, fn () => expect(PostTarget::query()->sole()->next_attempt_at)->toBeNull());

    // And it cannot be scheduled without a fresh approval.
    $this->post(($this->url)("/posts/{$post->id}/schedule"), ['publish_now' => true])->assertSessionHasErrors('post');
});

it('schedules, reschedules, unschedules and deletes over HTTP with workspace-timezone input', function () {
    $this->actingAsOwner($this->tenant);
    $post = $this->socialPost($this->tenant, [$this->account->id]);

    $this->post(($this->url)("/posts/{$post->id}/schedule"), [])->assertSessionHasErrors('scheduled_for');
    $this->post(($this->url)("/posts/{$post->id}/schedule"), ['scheduled_for' => '2020-01-01T09:00'])->assertSessionHasErrors('scheduled_for');

    $local = now('Asia/Dhaka')->addDays(2)->setTime(9, 30);
    $this->post(($this->url)("/posts/{$post->id}/schedule"), ['scheduled_for' => $local->format('Y-m-d\TH:i')])->assertRedirect();

    $fresh = $this->inTenant($this->tenant, fn () => Post::query()->findOrFail($post->id));
    $target = $this->inTenant($this->tenant, fn () => PostTarget::query()->sole());
    expect($fresh->status)->toBe(PostStatus::Scheduled)
        // Dhaka is UTC+6: 09:30 local is 03:30 UTC.
        ->and($fresh->scheduled_for?->utc()->format('H:i'))->toBe('03:30')
        ->and($target->next_attempt_at?->equalTo($fresh->scheduled_for))->toBeTrue();

    $this->get(($this->url)("/posts/{$post->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('post.scheduledForLocal', $local->format('Y-m-d\TH:i')));

    $later = $local->copy()->addDay();
    $this->post(($this->url)("/posts/{$post->id}/reschedule"), ['scheduled_for' => $later->format('Y-m-d\TH:i')])->assertRedirect();
    $this->inTenant($this->tenant, fn () => expect(Post::query()->findOrFail($post->id)->scheduled_for?->utc()->format('Y-m-d H:i'))
        ->toBe($later->copy()->utc()->format('Y-m-d H:i')));

    // A scheduled post cannot be deleted; it has to leave the queue first.
    $this->delete(($this->url)("/posts/{$post->id}"))->assertSessionHasErrors('post');
    $this->post(($this->url)("/posts/{$post->id}/unschedule"))->assertRedirect();
    expect(($this->state)($post))->toBe(PostStatus::Draft);
    $this->delete(($this->url)("/posts/{$post->id}"))->assertRedirect(($this->url)('/posts'));
    $this->inTenant($this->tenant, fn () => expect(Post::query()->count())->toBe(0));
});

it('keeps team comments internal and lets only the author or a reviewer remove one', function () {
    [$writer, $writerMember] = $this->socialMember($this->tenant, ['social.posts.view', 'social.posts.comment']);
    [$peer] = $this->socialMember($this->tenant, ['social.posts.view', 'social.posts.comment']);
    $post = $this->socialPost($this->tenant, [$this->account->id]);

    $this->actingAsMember($this->tenant, $writer);
    $this->post(($this->url)("/posts/{$post->id}/comments"), ['body' => ''])->assertSessionHasErrors('body');
    $this->post(($this->url)("/posts/{$post->id}/comments"), ['body' => 'Please check the link'])->assertRedirect();

    $comment = $this->inTenant($this->tenant, fn () => Comment::query()->sole());
    expect($comment->author_id)->toBe($writerMember->id);

    $this->get(($this->url)("/posts/{$post->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('post.comments.0.body', 'Please check the link')
        ->where('post.comments.0.mine', true));

    $this->actingAsMember($this->tenant, $peer);
    $this->delete(($this->url)("/posts/{$post->id}/comments/{$comment->id}"))->assertForbidden();

    $this->actingAsMember($this->tenant, $writer);
    $this->delete(($this->url)("/posts/{$post->id}/comments/{$comment->id}"))->assertRedirect();
    $this->inTenant($this->tenant, fn () => expect(Comment::query()->count())->toBe(0));
});

it('records the review and scheduling steps in the audit trail without the post body or tokens', function () {
    $post = $this->socialPost($this->tenant, [$this->account->id], ['body' => 'Secret launch plan']);
    $this->actingAsOwner($this->tenant);
    $this->post(($this->url)("/posts/{$post->id}/schedule"), ['publish_now' => true])->assertRedirect();
    $this->post(($this->url)("/posts/{$post->id}/unschedule"))->assertRedirect();

    $events = $this->auditTrail($this->tenant)->pluck('event')->all();

    expect($events)->toContain('social.post.created', 'social.post.scheduled', 'social.post.unscheduled');
    expect($this->auditTrail($this->tenant)->toJson())->not->toContain('Secret launch plan');
});

it('lists, filters and searches posts and shows them on the calendar', function () {
    $this->socialPost($this->tenant, [$this->account->id], ['title' => 'Alpha news', 'body' => 'about 100% growth']);
    $scheduled = $this->socialPost($this->tenant, [$this->account->id], ['title' => 'Beta event']);
    $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->schedule($scheduled, now()->addHours(5)->toImmutable()));
    $this->actingAsOwner($this->tenant);

    $this->get(($this->url)('/posts'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('posts.data', 2));
    $this->get(($this->url)('/posts?status=scheduled'))->assertInertia(fn (AssertableInertia $page) => $page->has('posts.data', 1)->where('posts.data.0.title', 'Beta event'));
    $this->get(($this->url)('/posts?q=100%25'))->assertInertia(fn (AssertableInertia $page) => $page->has('posts.data', 1)->where('posts.data.0.title', 'Alpha news'));
    $this->get(($this->url)('/posts?q=%25'))->assertInertia(fn (AssertableInertia $page) => $page->has('posts.data', 1));
    $this->get(($this->url)('/posts?status=nonsense'))->assertSessionHasErrors('status');

    $month = now('Asia/Dhaka')->addHours(5)->format('Y-m');
    $this->get(($this->url)('/calendar?month='.$month))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('timezone', 'Asia/Dhaka')
            ->has('posts', 1)
            ->where('posts.0.title', 'Beta event'));
});

it('refuses to edit a published post', function () {
    $post = $this->socialPost($this->tenant, [$this->account->id]);
    $this->inTenant($this->tenant, fn () => Post::query()->whereKey($post->id)->update(['status' => PostStatus::Published]));
    $this->inTenant($this->tenant, fn () => PostTarget::query()->update(['status' => TargetStatus::Published]));
    $this->actingAsOwner($this->tenant);

    $this->get(($this->url)("/posts/{$post->id}/edit"))->assertRedirect(($this->url)("/posts/{$post->id}"));
    $this->put(($this->url)("/posts/{$post->id}"), ['body' => 'x', 'targets' => [['account_id' => $this->account->id]]])->assertSessionHasErrors('post');
    $this->delete(($this->url)("/posts/{$post->id}"))->assertSessionHasErrors('post');

    expect(fn () => $this->inTenant($this->tenant, fn () => app(PostManager::class)->update($post->refresh(), ['body' => 'x'], [], [])))
        ->toThrow(ValidationException::class);
});
