<?php

declare(strict_types=1);

use App\Modules\Social\Actions\AccountAllowList;
use App\Modules\Social\Actions\MediaLibrary;
use App\Modules\Social\Actions\PostContentRules;
use App\Modules\Social\Actions\PostManager;
use App\Modules\Social\Actions\PostPublisher;
use App\Modules\Social\Actions\PostWorkflow;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Models\AccountAccess;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostTarget;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Fixtures\Mp4Builder;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

/*
 * TikTok settings chosen by the creator (ADR 0033, amendment 2026-10-14): the creator info endpoint, storing and
 * validating the per-target options, the scheduling block without a privacy level, publishing with the chosen
 * settings (re-checked against a fresh creator info) and the automatic fallback kept for older targets.
 */
beforeEach(function () {
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));
    config([
        'social.platforms.tiktok.client_id' => 'tt-client-key',
        'social.platforms.tiktok.client_secret' => 'tt-secret',
        'social.publishing.run_budget_seconds' => 30,
    ]);
    $this->tenant = $this->socialTenant('soc-tt-options');
    $this->account = $this->socialAccount($this->tenant, [
        'platform' => 'tiktok', 'external_id' => 'open-1', 'name' => 'Brand Creator', 'account_type' => 'profile',
        'access_token' => 'tt-access-TOKEN-123', 'avatar_url' => 'https://p16-sign-va.tiktokcdn.com/avatar.jpeg',
    ]);
    $this->url = fn (string $path = '') => $this->socialUrl($this->tenant, $path);

    $this->creator = [
        'creator_username' => 'brand.creator', 'creator_nickname' => 'Brand Creator',
        'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'],
        'comment_disabled' => false, 'duet_disabled' => true, 'stitch_disabled' => false,
        'max_video_post_duration_sec' => 300,
    ];
    $this->creatorError = null;
    $this->statuses = [];
    $this->calls = [];
    $this->init = null;
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $url = $request->url();
        $ok = fn (array $data) => Http::response(['data' => $data, 'error' => ['code' => 'ok', 'message' => '']]);

        if (str_starts_with($url, 'https://open-upload.tiktokapis.com/')) {
            $this->calls[] = 'chunk';

            return Http::response('', 201);
        }

        if (str_contains($url, '/creator_info/query/')) {
            $this->calls[] = 'creator';

            return $this->creatorError ?? $ok($this->creator);
        }

        if (str_contains($url, '/video/init/')) {
            $this->calls[] = 'init';
            $this->init = json_decode($request->body(), true);

            return $ok(['publish_id' => 'v_pub_file~v2-1.9', 'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=9&upload_token=SECRET']);
        }

        if (str_contains($url, '/status/fetch/')) {
            $this->calls[] = 'status';

            return $ok(array_shift($this->statuses) ?? ['status' => 'PROCESSING_UPLOAD']);
        }

        return Http::response(['error' => ['code' => 'invalid_params', 'message' => 'unexpected']], 400);
    });

    $this->video = function (): Media {
        $file = UploadedFile::fake()->createWithContent('clip.mp4', Mp4Builder::make(20, 720, 1280, ['payload' => 2500]))->mimeType('video/mp4');

        return $this->inTenant($this->tenant, fn (): Media => app(MediaLibrary::class)->store($file, null));
    };
    $this->create = fn (?array $options, array $media = []) => $this->inTenant($this->tenant, fn (): Post => app(PostManager::class)->create(
        ['body' => 'New drop', 'post_type' => 'video'],
        [['account_id' => $this->account->id, 'options' => $options]],
        array_map(fn (Media $item): string => $item->id, $media),
    ));
    $this->schedule = fn (Post $post): Post => $this->inTenant($this->tenant, fn (): Post => app(PostWorkflow::class)->schedule($post, null));
    $this->run = fn (): int => $this->inTenant($this->tenant, fn (): int => app(PostPublisher::class)->runDue());
    $this->target = fn (Post $post): PostTarget => $this->inTenant($this->tenant, fn () => PostTarget::query()->where('post_id', $post->id)->sole());
});

it('gives the composer each account\'s profile picture, http(s) addresses only', function () {
    $this->socialAccount($this->tenant, ['platform' => 'linkedin', 'name' => 'Odd Avatar', 'avatar_url' => 'javascript:alert(1)']);
    $this->actingAsOwner($this->tenant);

    $this->get(($this->url)('/posts/create'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Social/Pages/Posts/Form')
            ->where('form.accounts', fn ($accounts) => collect($accounts)->firstWhere('name', 'Brand Creator')['avatarUrl'] === 'https://p16-sign-va.tiktokcdn.com/avatar.jpeg'
                && collect($accounts)->firstWhere('name', 'Odd Avatar')['avatarUrl'] === null
                && ! str_contains(json_encode($accounts), 'tt-access-TOKEN-123')));
});

it('lets the remote profile-picture hosts into the image policy, and nothing that is not https', function () {
    config(['platform.security.img_hosts' => [...config('platform.security.img_hosts'), 'http://plain.example.com', 'https://x.example.com; script-src *']]);
    $this->actingAsOwner($this->tenant);

    $policy = (string) $this->get(($this->url)('/posts/create'))->headers->get('Content-Security-Policy-Report-Only');

    expect($policy)->toContain("img-src 'self' data: blob: https://*.fbcdn.net")
        ->and($policy)->toContain('https://*.tiktokcdn.com')
        ->and($policy)->not->toContain('plain.example.com')
        ->and($policy)->not->toContain('x.example.com');
});

it('reads the creator info for the composer without ever returning the token', function () {
    $this->actingAsOwner($this->tenant);

    $response = $this->getJson(($this->url)("/posts/creator-info/{$this->account->id}"))->assertOk()
        ->assertJson(['state' => 'ok', 'options' => [
            'privacyLevels' => ['PUBLIC_TO_EVERYONE', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'],
            'commentDisabled' => false, 'duetDisabled' => true, 'stitchDisabled' => false,
            'maxVideoSeconds' => 300, 'nickname' => 'Brand Creator',
        ]]);

    expect($response->getContent())->not->toContain('tt-access-TOKEN-123')
        ->and($this->calls)->toBe(['creator']);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v2/post/publish/creator_info/query/')
        && $request->hasHeader('Authorization', 'Bearer tt-access-TOKEN-123'));
});

it('needs a member who can write posts, and an account on their publish allow-list', function () {
    [$viewer] = $this->socialMember($this->tenant, ['social.posts.view']);
    $this->actingAsMember($this->tenant, $viewer);
    $this->getJson(($this->url)("/posts/creator-info/{$this->account->id}"))->assertForbidden();

    [$writer, $membership] = $this->socialMember($this->tenant, ['social.posts.view', 'social.posts.create']);
    $this->startNewBrowserSession()->actingAsMember($this->tenant, $writer);
    $this->getJson(($this->url)("/posts/creator-info/{$this->account->id}"))->assertOk()->assertJsonPath('state', 'ok');

    // Someone else is the only one allowed to publish to the account.
    [, $other] = $this->socialMember($this->tenant, ['social.posts.view', 'social.posts.create']);
    $this->inTenant($this->tenant, fn () => app(AccountAllowList::class)->replace($this->account, [
        ['subject_type' => AccountAccess::MEMBERSHIP, 'subject_id' => $other->id, 'can_publish' => true],
    ]));
    $this->getJson(($this->url)("/posts/creator-info/{$this->account->id}"))->assertForbidden();

    expect($membership->id)->not->toBe($other->id);
});

it('answers 404 for another workspace\'s account and for a platform without post settings', function () {
    $this->assertNotFoundFromOtherTenant('GET', "/social/posts/creator-info/{$this->account->id}", $this->tenant, intruder: $this->socialTenant('soc-tt-intruder'));

    $linkedin = $this->socialAccount($this->tenant, ['platform' => 'linkedin', 'name' => 'Company']);
    $this->actingAsOwner($this->tenant);
    $this->getJson(($this->url)("/posts/creator-info/{$linkedin->id}"))->assertNotFound();
});

it('turns a TikTok failure into our own short message', function () {
    $this->actingAsOwner($this->tenant);

    $this->creatorError = Http::response(['error' => ['code' => 'access_token_invalid', 'message' => 'Token tt-access-TOKEN-123 is bad', 'log_id' => 'L1']], 401);
    $denied = $this->getJson(($this->url)("/posts/creator-info/{$this->account->id}"))->assertOk()
        ->assertJson(['state' => 'unavailable', 'message' => 'This account must be connected again before its posting settings can be loaded.']);

    $this->creatorError = Http::response(['error' => ['code' => 'weird_failure', 'message' => 'Internal detail https://open.tiktokapis.com/x?token=abc', 'log_id' => 'L2']], 400);
    $odd = $this->getJson(($this->url)("/posts/creator-info/{$this->account->id}"))->assertOk()
        ->assertJson(['state' => 'unavailable', 'message' => 'The posting settings of this account could not be loaded. Try again in a moment.']);

    $this->creatorError = Http::response(['error' => ['code' => 'spam_risk_too_many_posts', 'message' => 'x']], 400);
    $this->getJson(($this->url)("/posts/creator-info/{$this->account->id}"))
        ->assertJson(['state' => 'unavailable', 'message' => 'TikTok says this account cannot post more right now. Try again later.']);

    foreach ([$denied, $odd] as $response) {
        expect($response->getContent())->not->toContain('tt-access-TOKEN-123')
            ->and($response->getContent())->not->toContain('Internal detail')
            ->and($response->getContent())->not->toContain('token=abc');
    }
});

it('validates and stores the chosen options per target, and only for a platform that has them', function () {
    $linkedin = $this->socialAccount($this->tenant, ['platform' => 'linkedin', 'name' => 'Company']);
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/posts'), [
        'body' => 'Hello',
        'targets' => [['account_id' => $this->account->id, 'options' => ['privacy_level' => 'WORLD']]],
    ])->assertSessionHasErrors('targets.0.options.privacy_level');
    $this->post(($this->url)('/posts'), [
        'body' => 'Hello',
        'targets' => [['account_id' => $this->account->id, 'options' => ['privacy_level' => 'SELF_ONLY', 'allow_comment' => 'maybe']]],
    ])->assertSessionHasErrors('targets.0.options.allow_comment');
    $this->post(($this->url)('/posts'), [
        'body' => 'Hello',
        'targets' => [['account_id' => $this->account->id, 'options' => ['privacy_level' => 'SELF_ONLY', 'auto_add_music' => true]]],
    ])->assertSessionHasErrors('targets.0.options');
    expect($this->inTenant($this->tenant, fn () => Post::query()->count()))->toBe(0);

    $this->post(($this->url)('/posts'), [
        'body' => 'Hello',
        'targets' => [
            ['account_id' => $this->account->id, 'options' => [
                'privacy_level' => 'FOLLOWER_OF_CREATOR', 'allow_comment' => true, 'allow_duet' => false, 'allow_stitch' => true,
                // A brand choice without the disclosure switched on does not count.
                'disclose_commercial' => false, 'brand_content' => true,
            ]],
            ['account_id' => $linkedin->id, 'options' => ['privacy_level' => 'SELF_ONLY']],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $post = $this->inTenant($this->tenant, fn () => Post::query()->with('targets')->sole());
    $tiktok = $post->targets->firstWhere('account_id', $this->account->id);
    expect($tiktok->options)->toEqual([
        'privacy_level' => 'FOLLOWER_OF_CREATOR', 'allow_comment' => true, 'allow_duet' => false, 'allow_stitch' => true,
        'disclose_commercial' => false, 'brand_organic' => false, 'brand_content' => false,
    ])->and($post->targets->firstWhere('account_id', $linkedin->id)->options)->toBeNull();

    // An edit that sends the options changes them; one without the key leaves them as they are.
    $this->put(($this->url)("/posts/{$post->id}"), [
        'body' => 'Hello again',
        'targets' => [['account_id' => $this->account->id, 'options' => ['privacy_level' => 'PUBLIC_TO_EVERYONE', 'disclose_commercial' => true, 'brand_organic' => true]]],
    ])->assertSessionHasNoErrors();
    expect(($this->target)($post)->options)->toMatchArray(['privacy_level' => 'PUBLIC_TO_EVERYONE', 'disclose_commercial' => true, 'brand_organic' => true, 'allow_comment' => false]);

    $this->put(($this->url)("/posts/{$post->id}"), ['body' => 'Third', 'targets' => [['account_id' => $this->account->id]]])->assertSessionHasNoErrors();
    expect(($this->target)($post)->options['privacy_level'])->toBe('PUBLIC_TO_EVERYONE');

    // The post page shows what was chosen.
    $this->get(($this->url)("/posts/{$post->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('post.targets.0.hasPublishOptions', true)
            ->where('post.targets.0.publishOptions.privacy_level', 'PUBLIC_TO_EVERYONE'));
});

it('blocks scheduling and the composer check until a privacy level is chosen', function () {
    $video = ($this->video)();
    $post = ($this->create)(null, [$video]);

    $issues = $this->inTenant($this->tenant, fn () => app(PostContentRules::class)->previews($post)[0]['issues']);
    expect($issues)->toContain('Choose who can see this TikTok post in its TikTok settings.')
        ->and(fn () => ($this->schedule)($post))->toThrow(ValidationException::class);

    $this->actingAsOwner($this->tenant);
    $check = fn (?array $options) => $this->postJson(($this->url)('/posts/preflight'), [
        'post_type' => 'video', 'body' => 'New drop', 'media_ids' => [$video->id],
        'targets' => [['account_id' => $this->account->id, 'options' => $options]],
    ])->assertOk()->json('previews.0.issues');

    expect($check(null))->toContain('Choose who can see this TikTok post in its TikTok settings.')
        ->and($check(['privacy_level' => 'SELF_ONLY', 'disclose_commercial' => true]))
        ->toContain('You chose to disclose commercial content on TikTok: tick "Your brand", "Branded content" or both, or turn the disclosure off.')
        ->and($check(['privacy_level' => 'SELF_ONLY', 'disclose_commercial' => true, 'brand_content' => true]))
        ->toContain('Branded content cannot be private on TikTok. Choose another privacy level.')
        ->and($check(['privacy_level' => 'PUBLIC_TO_EVERYONE', 'disclose_commercial' => true, 'brand_content' => true]))->toBe([])
        ->and($this->calls)->toBe([]);
});

it('publishes with the settings the creator chose, re-checked against a fresh creator info', function () {
    $post = ($this->schedule)(($this->create)([
        'privacy_level' => 'PUBLIC_TO_EVERYONE', 'allow_comment' => true, 'allow_duet' => true, 'allow_stitch' => false,
        'disclose_commercial' => true, 'brand_organic' => true, 'brand_content' => false,
    ], [($this->video)()]));

    ($this->run)();

    expect($this->calls)->toBe(['creator', 'init', 'chunk'])
        ->and($this->init['post_info'])->toBe([
            'title' => 'New drop',
            'privacy_level' => 'PUBLIC_TO_EVERYONE',
            'disable_comment' => false,
            // The creator turned Duet off in TikTok: it stays off whatever was chosen.
            'disable_duet' => true,
            'disable_stitch' => true,
            'brand_content_toggle' => false,
            'brand_organic_toggle' => true,
        ])
        ->and(($this->target)($post)->status)->toBe(TargetStatus::Processing);
});

it('refuses a privacy level the account no longer offers, before anything is created on TikTok', function () {
    $post = ($this->schedule)(($this->create)(['privacy_level' => 'FOLLOWER_OF_CREATOR'], [($this->video)()]));
    $this->creator['privacy_level_options'] = ['SELF_ONLY'];

    ($this->run)();

    $target = ($this->target)($post);
    expect($this->calls)->toBe(['creator'])
        ->and($target->status)->toBe(TargetStatus::Failed)
        ->and($target->last_error_code)->toBe('tiktok_privacy_not_offered')
        ->and($target->last_error)->toContain('no longer offers the privacy level')
        ->and($target->needs_manual_check)->toBeFalse()
        ->and($target->attempts)->toBe(1);
});

it('keeps the earlier automatic settings only for a target saved before the settings existed', function () {
    $post = ($this->schedule)(($this->create)(['privacy_level' => 'SELF_ONLY'], [($this->video)()]));
    // A target scheduled before the `options` column existed.
    $this->inTenant($this->tenant, fn () => PostTarget::query()->whereKey(($this->target)($post)->id)->update(['options' => null]));

    ($this->run)();

    expect($this->init['post_info'])->toBe([
        'title' => 'New drop',
        'privacy_level' => 'PUBLIC_TO_EVERYONE',
        'disable_comment' => false,
        'disable_duet' => true,
        'disable_stitch' => false,
    ]);

    $this->actingAsOwner($this->tenant);
    $this->get(($this->url)("/posts/{$post->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('post.targets.0.hasPublishOptions', true)
            ->where('post.targets.0.publishOptions', null));
});
