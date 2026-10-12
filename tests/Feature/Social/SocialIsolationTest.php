<?php

declare(strict_types=1);

use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Enums\AttemptOutcome;
use App\Modules\Social\Enums\CampaignStatus;
use App\Modules\Social\Enums\MediaKind;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Enums\TargetStatus;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

$account = fn (): SocialAccount => SocialAccount::query()->create([
    'platform' => 'fake', 'external_id' => 'ext-'.Str::random(8), 'name' => 'Page', 'account_type' => 'page', 'status' => AccountStatus::Connected,
    'access_token' => 'secret-token',
]);
$post = fn (): Post => Post::query()->create(['body' => 'Hello', 'status' => PostStatus::Draft]);
$target = fn () => PostTarget::query()->create([
    'post_id' => $post()->id, 'account_id' => $account()->id, 'status' => TargetStatus::Pending, 'idempotency_key' => Str::random(40),
]);
$media = fn (): Media => Media::query()->create([
    'name' => 'a.png', 'path' => 'social/media/a.png', 'mime' => 'image/png', 'kind' => MediaKind::Image,
    'size_bytes' => 10, 'sha256' => hash('sha256', Str::random(20)),
]);

it('isolates connected accounts, posts and their parts between tenants', function () use ($account, $post, $target, $media) {
    $this->assertTenantIsolated(SocialAccount::class, fn () => $account());
    $this->assertTenantIsolated(Post::class, fn () => $post());
    $this->assertTenantIsolated(PostTarget::class, fn () => $target());
    $this->assertTenantIsolated(Media::class, fn () => $media());
    $this->assertTenantIsolated(PostMedia::class, fn () => PostMedia::query()->create(['post_id' => $post()->id, 'media_id' => $media()->id, 'position' => 0]));
    $this->assertTenantIsolated(Comment::class, fn () => Comment::query()->create(['post_id' => $post()->id, 'body' => 'Looks good']));
});

it('isolates publishing history, metrics and workspace lists between tenants', function () use ($target) {
    $this->assertTenantIsolated(PublishAttempt::class, fn () => PublishAttempt::query()->create([
        'target_id' => $target()->id, 'attempt' => 1, 'outcome' => AttemptOutcome::Success,
    ]));
    $this->assertTenantIsolated(PostMetric::class, fn () => PostMetric::query()->create(['target_id' => $target()->id, 'captured_at' => now(), 'likes' => 3]));
    $this->assertTenantIsolated(Campaign::class, fn () => Campaign::query()->create(['name' => 'Launch', 'status' => CampaignStatus::Active]));
    $this->assertTenantIsolated(Category::class, fn () => Category::query()->create(['name' => 'News']));
    $this->assertTenantIsolated(HashtagSet::class, fn () => HashtagSet::query()->create(['name' => 'Brand', 'tags' => ['#brand']]));
    $this->assertTenantIsolated(SocialSetting::class, fn () => SocialSetting::query()->create([]));
    $this->assertTenantIsolated(Import::class, fn () => Import::query()->create(['file_name' => 'a.csv', 'file_sha256' => hash('sha256', Str::random(20)), 'mode' => 'draft']));
    $this->assertTenantIsolated(AutomationActionReceipt::class, fn () => AutomationActionReceipt::query()->create([
        'action' => 'social.post.create_draft', 'idempotency_key' => Str::random(12), 'output' => [],
    ]));
});

it('answers 404 from another workspace for every route that takes an id', function () {
    $home = $this->socialTenant('soc-home');
    $intruder = $this->socialTenant('soc-intruder');
    $this->fakePlatform();
    $account = $this->socialAccount($home);
    $post = $this->socialPost($home, [$account->id]);
    $target = $this->inTenant($home, fn () => PostTarget::query()->where('post_id', $post->id)->sole());
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));
    $this->inTenant($home, fn () => app(TenantStorage::class)->put('social/media/a.png', 'bytes'));
    $media = $this->inTenant($home, fn () => Media::query()->create([
        'name' => 'a.png', 'path' => 'social/media/a.png', 'mime' => 'image/png', 'kind' => MediaKind::Image,
        'size_bytes' => 10, 'sha256' => hash('sha256', 'a'),
    ]));
    $campaign = $this->inTenant($home, fn () => Campaign::query()->create(['name' => 'C', 'status' => CampaignStatus::Active]));
    $category = $this->inTenant($home, fn () => Category::query()->create(['name' => 'K']));
    $set = $this->inTenant($home, fn () => HashtagSet::query()->create(['name' => 'S', 'tags' => ['#s']]));
    $comment = $this->inTenant($home, fn () => Comment::query()->create(['post_id' => $post->id, 'body' => 'Note']));

    $check = fn (string $method, string $path, array $data = []) => $this->assertNotFoundFromOtherTenant($method, '/social'.$path, $home, $data, $intruder);

    $check('GET', "/posts/{$post->id}");
    $check('GET', "/posts/{$post->id}/edit");
    $check('PUT', "/posts/{$post->id}", ['body' => 'x', 'targets' => [['account_id' => $account->id]]]);
    $check('POST', "/posts/{$post->id}/submit");
    $check('POST', "/posts/{$post->id}/approve");
    $check('POST', "/posts/{$post->id}/reject", ['reason' => 'No']);
    $check('POST', "/posts/{$post->id}/schedule", ['publish_now' => true]);
    $check('POST', "/posts/{$post->id}/reschedule", ['scheduled_for' => '2030-01-01T10:00']);
    $check('POST', "/posts/{$post->id}/unschedule");
    $check('POST', "/posts/{$post->id}/targets/{$target->id}/retry");
    $check('POST', "/posts/{$post->id}/comments", ['body' => 'Hi']);
    $check('DELETE', "/posts/{$post->id}/comments/{$comment->id}");
    $check('GET', "/media/{$media->id}/file");
    $check('PUT', "/media/{$media->id}", ['alt_text' => 'x']);
    $check('PUT', "/library/campaigns/{$campaign->id}", ['name' => 'N', 'status' => 'active']);
    $check('PUT', "/library/categories/{$category->id}", ['name' => 'N']);
    $check('PUT', "/library/hashtag-sets/{$set->id}", ['name' => 'N', 'tags' => '#n']);
    $check('DELETE', "/library/campaigns/{$campaign->id}");
    $check('DELETE', "/accounts/{$account->id}");
});

it('keeps another workspace\'s account, media and campaign out of a post', function () {
    $home = $this->socialTenant('soc-a');
    $other = $this->socialTenant('soc-b');
    $this->fakePlatform();
    $foreignAccount = $this->socialAccount($other);
    $homeAccount = $this->socialAccount($home);
    $foreignCampaign = $this->inTenant($other, fn () => Campaign::query()->create(['name' => 'Theirs', 'status' => CampaignStatus::Active]));

    $this->actingAsOwner($home);

    $this->post($this->socialUrl($home, '/posts'), ['body' => 'x', 'targets' => [['account_id' => $foreignAccount->id]]])
        ->assertSessionHasErrors('targets');
    $this->post($this->socialUrl($home, '/posts'), ['body' => 'x', 'targets' => [['account_id' => $homeAccount->id]], 'campaign_id' => $foreignCampaign->id])
        ->assertSessionHasErrors('campaign_id');
    $this->post($this->socialUrl($home, '/posts'), ['body' => 'x', 'targets' => [['account_id' => $homeAccount->id]], 'media_ids' => [(string) Str::ulid()]])
        ->assertSessionHasErrors('media');

    $this->inTenant($home, fn () => expect(Post::query()->count())->toBe(0));
});
