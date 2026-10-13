<?php

declare(strict_types=1);

use App\Modules\Social\Actions\AccountManager;
use App\Modules\Social\Actions\MediaLibrary;
use App\Modules\Social\Actions\PostManager;
use App\Modules\Social\Actions\PostPublisher;
use App\Modules\Social\Actions\PostWorkflow;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Models\PublishAttempt;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Fixtures\FakeFacebookGraph;
use Tests\Support\Fixtures\Mp4Builder;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

beforeEach(function () {
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));
    config([
        'social.platforms.facebook.client_id' => 'fb-id',
        'social.platforms.facebook.client_secret' => 'fb-secret',
        'social.platforms.facebook.video_publishing' => true,
        'social.publishing.run_budget_seconds' => 30,
    ]);
    $this->tenant = $this->socialTenant('soc-fbmedia');
    $this->fakePlatform();
    $this->account = $this->socialAccount($this->tenant, ['platform' => 'facebook', 'external_id' => 'PAGE1', 'name' => 'Brand Page']);
    $this->graph = new FakeFacebookGraph;
    $this->graph->install();

    $this->store = function (string $name, string $bytes, string $mime): Media {
        // The fake file reports a type from its extension; a real upload is sniffed from the bytes.
        $file = UploadedFile::fake()->createWithContent($name, $bytes)->mimeType($mime);

        return $this->inTenant($this->tenant, fn (): Media => app(MediaLibrary::class)->store($file, null));
    };
    $this->video = fn (float $seconds = 30, int $w = 1080, int $h = 1920, array $options = []): Media => ($this->store)(
        'clip-'.bin2hex(random_bytes(3)).'.mp4',
        Mp4Builder::make($seconds, $w, $h, ['payload' => 2500, ...$options]),
        (($options['brand'] ?? 'isom') === 'qt  ') ? 'video/quicktime' : 'video/mp4',
    );
    $counter = 0;
    $this->photo = function () use (&$counter): Media {
        $counter++;

        return ($this->store)('p'.$counter.'.png', UploadedFile::fake()->image('x.png', 40 + $counter, 30)->getContent(), 'image/png');
    };

    $this->queue = function (string $type, array $media, ?SocialAccount $account = null, string $body = 'Caption') {
        $account ??= $this->account;
        $post = $this->inTenant($this->tenant, fn (): Post => app(PostManager::class)->create(
            ['body' => $body, 'post_type' => $type],
            [['account_id' => $account->id]],
            array_map(fn (Media $item): string => $item->id, $media),
        ));

        return $this->inTenant($this->tenant, fn (): Post => app(PostWorkflow::class)->schedule($post, null));
    };
    $this->run = fn (): int => $this->inTenant($this->tenant, fn (): int => app(PostPublisher::class)->runDue());
    $this->target = fn (Post $post): PostTarget => $this->inTenant($this->tenant, fn () => PostTarget::query()->where('post_id', $post->id)->sole());
    $this->postStatus = fn (Post $post): PostStatus => $this->inTenant($this->tenant, fn () => Post::query()->findOrFail($post->id)->status);
});

it('publishes several photos as unpublished uploads attached to one feed post', function () {
    $media = [($this->photo)(), ($this->photo)(), ($this->photo)()];
    $post = ($this->queue)('photos', $media);

    expect(($this->run)())->toBe(1);

    expect($this->graph->count('photos'))->toBe(3)->and($this->graph->count('feed'))->toBe(1);

    foreach ($this->graph->calls('photos') as $call) {
        expect($call['data']['published'])->toBe('false')
            ->and($call['url'])->toBe('https://graph.facebook.com/v23.0/PAGE1/photos');
    }

    $feed = $this->graph->calls('feed')[0]['data'];
    expect($feed['message'])->toBe('Caption')
        ->and(json_decode($feed['attached_media[0]'], true))->toBe(['media_fbid' => 'photo-1'])
        ->and(json_decode($feed['attached_media[2]'], true))->toBe(['media_fbid' => 'photo-3']);

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Published)
        ->and($target->external_id)->toBe('PAGE1_post')
        ->and(($this->postStatus)($post))->toBe(PostStatus::Published);
});

it('retries a failed photo upload without ever creating a post, then publishes once', function () {
    $post = ($this->queue)('photos', [($this->photo)(), ($this->photo)()]);
    $this->graph->failNext('photos', FakeFacebookGraph::timeout());

    expect(($this->run)())->toBe(1);

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Processing)
        ->and($target->last_error_code)->toBe('network_unconfirmed')
        ->and($this->graph->count('feed'))->toBe(0)
        ->and($target->needs_manual_check)->toBeFalse();

    $this->travel(2)->minutes();
    expect(($this->run)())->toBe(1);

    expect(($this->target)($post)->status)->toBe(TargetStatus::Published)
        ->and($this->graph->count('feed'))->toBe(1);
});

it('uploads a video in the chunks Facebook asks for, finishes once and waits until it is ready', function () {
    $video = ($this->video)();
    $size = $video->size_bytes;
    $this->graph->chunk = 1000;
    $this->graph->statuses = ['uploading', 'processing', 'ready'];
    $post = ($this->queue)('video', [$video]);

    expect(($this->run)())->toBe(1);

    $target = ($this->target)($post);
    $start = $this->graph->calls('videos:start');
    $transfers = $this->graph->calls('videos:transfer');
    expect($start)->toHaveCount(1)
        ->and((int) $start[0]['data']['file_size'])->toBe($size)
        ->and(array_sum(array_column($transfers, 'bytes')))->toBe($size)
        ->and(max(array_column($transfers, 'bytes')))->toBeLessThanOrEqual(1000)
        ->and($this->graph->count('videos:finish'))->toBe(1)
        ->and($this->graph->calls('videos:finish')[0]['data']['description'])->toBe('Caption')
        ->and($target->status)->toBe(TargetStatus::Processing)
        ->and($target->external_id)->toBe('vid-1')
        ->and(($this->postStatus)($post))->toBe(PostStatus::Publishing);

    // Still processing: nothing is published and nothing is uploaded or finished again.
    $this->travel(2)->minutes();
    expect(($this->run)())->toBe(1);
    expect(($this->target)($post)->status)->toBe(TargetStatus::Processing);
    $this->travel(2)->minutes();
    expect(($this->run)())->toBe(1);
    expect(($this->target)($post)->status)->toBe(TargetStatus::Processing);
    $this->travel(2)->minutes();
    expect(($this->run)())->toBe(1);

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Published)
        ->and($target->external_url)->toBe('https://www.facebook.com/PAGE1/videos/vid-1/')
        ->and($target->remote_id)->toBeNull()
        ->and($this->graph->count('videos:start'))->toBe(1)
        ->and($this->graph->count('videos:finish'))->toBe(1)
        ->and($this->graph->count('status'))->toBe(3)
        ->and(($this->postStatus)($post))->toBe(PostStatus::Published);

    // Nothing is due any more: a further run does nothing.
    expect(($this->run)())->toBe(0)->and($this->graph->count('videos:finish'))->toBe(1);
});

it('resumes a video upload from the saved offset when a run ends before the file is sent', function () {
    config(['social.publishing.run_budget_seconds' => 0]);
    $video = ($this->video)();
    $this->graph->chunk = 700;
    $post = ($this->queue)('video', [$video]);

    $runs = 0;

    while (($this->target)($post)->status !== TargetStatus::Published && $runs < 20) {
        ($this->run)();
        $runs++;
        $this->travel(1)->minutes();
    }

    $transfers = $this->graph->calls('videos:transfer');
    $offsets = array_map(fn (array $call): int => (int) $call['data']['start_offset'], $transfers);

    expect($runs)->toBeGreaterThan(2)
        ->and(($this->target)($post)->status)->toBe(TargetStatus::Published)
        // A single upload session, continued: never restarted, and each chunk sent exactly once.
        ->and($this->graph->count('videos:start'))->toBe(1)
        ->and($this->graph->count('videos:finish'))->toBe(1)
        ->and($offsets)->toBe(array_values(array_unique($offsets)))
        ->and(array_sum(array_column($transfers, 'bytes')))->toBe($video->size_bytes);
});

it('publishes a Reel through start, a chunked upload to rupload and finish with PUBLISHED', function () {
    config(['social.platforms.facebook.limits.reel.chunk_bytes' => 262144]);
    $video = ($this->video)(30, 1080, 1920, ['payload' => 600000]);
    $this->graph->statuses = ['processing', 'ready'];
    $post = ($this->queue)('reel', [$video]);

    expect(($this->run)())->toBe(1);

    $uploads = $this->graph->calls('reels:upload');
    expect($this->graph->count('reels:start'))->toBe(1)
        ->and($this->graph->calls('reels:start')[0]['url'])->toBe('https://graph.facebook.com/v23.0/PAGE1/video_reels')
        ->and($uploads)->toHaveCount((int) ceil($video->size_bytes / 262144))
        ->and($uploads[0]['url'])->toBe('https://rupload.facebook.com/video-upload/v23.0/reel-1')
        ->and($uploads[0]['headers']['offset'])->toBe('0')
        ->and($uploads[0]['headers']['file_size'])->toBe((string) $video->size_bytes)
        ->and($uploads[0]['headers']['Authorization'])->toStartWith('OAuth ')
        ->and($uploads[1]['headers']['offset'])->toBe('262144')
        ->and(array_sum(array_column($uploads, 'bytes')))->toBe($video->size_bytes);

    $finish = $this->graph->calls('reels:finish');
    expect($finish)->toHaveCount(1)
        ->and($finish[0]['data']['video_state'])->toBe('PUBLISHED')
        ->and($finish[0]['data']['video_id'])->toBe('reel-1')
        ->and(($this->target)($post)->status)->toBe(TargetStatus::Processing);

    $this->travel(2)->minutes();
    ($this->run)();
    $this->travel(2)->minutes();
    ($this->run)();

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Published)
        ->and($target->external_id)->toBe('reel-1')
        ->and($target->external_url)->toBe('https://www.facebook.com/reel/reel-1')
        ->and($this->graph->count('reels:finish'))->toBe(1);
});

it('fails a video Facebook could not process, says why, and a retry uploads a fresh video', function () {
    $post = ($this->queue)('video', [($this->video)()]);
    $this->graph->statuses = ['error'];

    ($this->run)();
    $this->travel(2)->minutes();
    ($this->run)();

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Failed)
        ->and($target->last_error_code)->toBe('video_processing_failed')
        ->and($target->last_error)->toBe('Video could not be processed')
        ->and($target->needs_manual_check)->toBeFalse()
        ->and($target->external_id)->toBeNull()
        ->and($target->remote_id)->toBeNull()
        ->and(($this->postStatus)($post))->toBe(PostStatus::Failed);

    $this->graph->statuses = ['ready'];
    $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->retryTarget(Post::query()->findOrFail($post->id), $target));
    ($this->run)();
    $this->travel(2)->minutes();
    ($this->run)();

    expect($this->graph->count('videos:start'))->toBe(2)
        ->and($this->graph->count('videos:finish'))->toBe(2)
        ->and(($this->target)($post)->status)->toBe(TargetStatus::Published);
});

it('treats a lost answer to the final call as unknown and never publishes it again', function () {
    $post = ($this->queue)('reel', [($this->video)()]);
    $this->graph->failNext('reels:finish', FakeFacebookGraph::timeout());

    ($this->run)();

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Failed)
        ->and($target->needs_manual_check)->toBeTrue()
        ->and($target->last_error_code)->toBe('network_unconfirmed')
        ->and($this->graph->count('reels:finish'))->toBe(1);

    $this->travel(10)->minutes();
    ($this->run)();
    ($this->run)();

    expect($this->graph->count('reels:finish'))->toBe(1)->and($this->graph->count('reels:start'))->toBe(1);
});

it('retries a video upload that failed before anything was public, without a manual check', function () {
    $post = ($this->queue)('video', [($this->video)()]);
    $this->graph->failNext('videos:start', FakeFacebookGraph::timeout());

    ($this->run)();

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Processing)
        ->and($target->needs_manual_check)->toBeFalse()
        ->and($target->next_attempt_at)->not->toBeNull()
        ->and($target->remote_meta['failures'] ?? null)->toBe(1);

    // Not due yet: nothing happens.
    expect(($this->run)())->toBe(0);

    $this->travel(2)->minutes();
    $this->graph->statuses = ['ready'];
    ($this->run)();
    $this->travel(2)->minutes();
    ($this->run)();

    expect(($this->target)($post)->status)->toBe(TargetStatus::Published)
        ->and($this->graph->count('videos:finish'))->toBe(1);
});

it('gives up on a video after too many transient failures', function () {
    config(['social.publishing.step_failures_max' => 2]);
    $post = ($this->queue)('video', [($this->video)()]);

    for ($i = 0; $i < 3; $i++) {
        $this->graph->failNext('videos:start', FakeFacebookGraph::error(2, 'Service temporarily unavailable', 503));
    }

    // 503 on the upload start is an unknown outcome made retryable, because nothing is public yet.
    ($this->run)();
    expect(($this->target)($post)->status)->toBe(TargetStatus::Processing);
    $this->travel(10)->minutes();
    ($this->run)();

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Failed)
        ->and($target->needs_manual_check)->toBeFalse()
        ->and($this->graph->count('videos:finish'))->toBe(0);
});

it('flags media Facebook never finishes processing for a manual check instead of retrying', function () {
    $post = ($this->queue)('video', [($this->video)()]);
    $this->graph->statuses = array_fill(0, 500, 'processing');

    ($this->run)();
    expect(($this->target)($post)->status)->toBe(TargetStatus::Processing);

    $this->travel(181)->minutes();
    ($this->run)();

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Failed)
        ->and($target->last_error_code)->toBe('processing_timeout')
        ->and($target->needs_manual_check)->toBeTrue()
        ->and($this->graph->count('videos:finish'))->toBe(1);
});

it('explains a missing permission calmly and asks for the Page to be connected again', function () {
    $post = ($this->queue)('reel', [($this->video)()]);
    $this->graph->failNext('reels:start', FakeFacebookGraph::error(200, '(#200) Requires pages_manage_posts permission to manage the object', 403));

    ($this->run)();

    $target = ($this->target)($post);
    $account = $this->inTenant($this->tenant, fn () => SocialAccount::query()->findOrFail($this->account->id));
    expect($target->status)->toBe(TargetStatus::Failed)
        ->and($target->last_error_code)->toBe('facebook_permission_missing')
        ->and($target->last_error)->toContain('missing a permission')
        ->and($target->last_error)->not->toContain('(#200)')
        ->and($account->status)->toBe(AccountStatus::NeedsReauthorization);
});

it('sends an expired token to reauthorization', function () {
    $post = ($this->queue)('photos', [($this->photo)(), ($this->photo)()]);
    $this->graph->failNext('photos', FakeFacebookGraph::error(190, 'Error validating access token: Session has expired', 401));

    ($this->run)();

    $target = ($this->target)($post);
    $account = $this->inTenant($this->tenant, fn () => SocialAccount::query()->findOrFail($this->account->id));
    expect($target->last_error_code)->toBe('reauthorization_required')
        ->and($target->last_error)->toContain('Connect the Page again')
        ->and($account->status)->toBe(AccountStatus::NeedsReauthorization)
        ->and($account->access_token)->toBeNull();
});

it('fails the in-flight video of an account that is disconnected and asks for a manual check', function () {
    $post = ($this->queue)('video', [($this->video)()]);
    $this->graph->statuses = ['processing', 'processing'];
    ($this->run)();
    expect(($this->target)($post)->status)->toBe(TargetStatus::Processing);

    $this->inTenant($this->tenant, fn () => app(AccountManager::class)->disconnect(
        SocialAccount::query()->findOrFail($this->account->id),
    ));

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Failed)
        ->and($target->needs_manual_check)->toBeTrue();
});

it('never starts a second upload for a target that is already processing', function () {
    $post = ($this->queue)('video', [($this->video)()]);
    $this->graph->statuses = ['processing', 'processing'];
    ($this->run)();
    $target = ($this->target)($post);

    $first = $this->inTenant($this->tenant, fn () => app(PostPublisher::class)->publishTarget($target->id));

    expect($first)->toBeNull()
        ->and($this->inTenant($this->tenant, fn () => app(PostPublisher::class)->resumeTarget($target->id)))->toBeNull()
        ->and($this->graph->count('videos:start'))->toBe(1);
});

it('counts a stepwise publish once against the account and records the processing step', function () {
    $post = ($this->queue)('video', [($this->video)()]);
    $this->graph->statuses = ['ready'];
    ($this->run)();
    $this->travel(2)->minutes();
    ($this->run)();

    $target = ($this->target)($post);
    $log = $this->inTenant($this->tenant, fn () => PublishAttempt::query()->where('target_id', $target->id)->orderBy('created_at')->pluck('error_code', 'outcome')->all());

    expect($target->attempts)->toBe(1)->and($log)->toHaveKeys(['deferred', 'success']);
});

it('refuses a post whose media does not fit the chosen type before it can be scheduled', function () {
    $long = ($this->video)(120, 1080, 1920);
    $draft = $this->inTenant($this->tenant, fn (): Post => app(PostManager::class)->create(
        ['body' => 'Too long', 'post_type' => 'reel'], [['account_id' => $this->account->id]], [$long->id],
    ));

    $errors = [];

    try {
        $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->schedule($draft, null));
    } catch (ValidationException $exception) {
        $errors = $exception->errors()['targets'];
    }

    expect(implode(' ', $errors))->toContain('at most 90 seconds');

    // The same file is fine as a regular video.
    $ok = $this->inTenant($this->tenant, fn (): Post => app(PostManager::class)->update($draft, ['body' => 'Fine', 'post_type' => 'video'], null, null));
    $scheduled = $this->inTenant($this->tenant, fn (): Post => app(PostWorkflow::class)->schedule($ok, null));
    expect($scheduled->status)->toBe(PostStatus::Scheduled);
});

it('leaves CSV bulk scheduling as it was: imported rows are plain posts', function () {
    $this->actingAsOwner($this->tenant);
    $csv = UploadedFile::fake()->createWithContent('posts.csv', "body,accounts\nHello there,Brand Page\n");

    $this->post($this->socialUrl($this->tenant, '/imports'), ['file' => $csv, 'mode' => 'draft'])->assertRedirect();

    $post = $this->inTenant($this->tenant, fn () => Post::query()->sole());
    expect($post->post_type->value)->toBe('post')->and($post->body)->toBe('Hello there');
});

it('shows the processing state, its start and the retry of a failed video on the post page', function () {
    $post = ($this->queue)('video', [($this->video)()]);
    $this->graph->statuses = ['error'];
    ($this->run)();
    $this->actingAsOwner($this->tenant);

    $this->get($this->socialUrl($this->tenant, "/posts/{$post->id}"))->assertOk()->assertInertia(fn ($page) => $page
        ->where('post.postType', 'video')
        ->where('post.status', 'publishing')
        ->where('post.targets.0.status', 'processing')
        ->where('post.targets.0.remoteState', 'processing')
        ->where('post.abilities.unschedule', false)
        ->where('post.abilities.reschedule', false)
        ->whereType('post.targets.0.processingSince', 'string'));

    $this->travel(2)->minutes();
    ($this->run)();

    $this->get($this->socialUrl($this->tenant, "/posts/{$post->id}"))->assertInertia(fn ($page) => $page
        ->where('post.targets.0.status', 'failed')
        ->where('post.targets.0.lastErrorCode', 'video_processing_failed')
        ->where('post.targets.0.canRetry', true));

    $target = ($this->target)($post);
    $this->graph->statuses = ['ready'];
    $this->post($this->socialUrl($this->tenant, "/posts/{$post->id}/targets/{$target->id}/retry"))->assertRedirect();

    expect(($this->target)($post)->status)->toBe(TargetStatus::Pending);
});

it('does not let an edit or an unschedule touch a post while its video is processing', function () {
    $post = ($this->queue)('video', [($this->video)()]);
    $this->graph->statuses = ['processing'];
    ($this->run)();

    $this->inTenant($this->tenant, function () use ($post): void {
        $fresh = Post::query()->findOrFail($post->id);
        expect(fn () => app(PostWorkflow::class)->unschedule($fresh))->toThrow(ValidationException::class)
            ->and(fn () => app(PostManager::class)->update($fresh, ['body' => 'Changed'], null, null))->toThrow(ValidationException::class);
    });
    expect(($this->target)($post)->status)->toBe(TargetStatus::Processing);
});
