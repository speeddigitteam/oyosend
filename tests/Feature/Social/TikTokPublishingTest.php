<?php

declare(strict_types=1);

use App\Modules\Social\Actions\MediaLibrary;
use App\Modules\Social\Actions\PostContentRules;
use App\Modules\Social\Actions\PostManager;
use App\Modules\Social\Actions\PostPublisher;
use App\Modules\Social\Actions\PostWorkflow;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Platforms\Adapters\TikTokPlatform;
use App\Modules\Social\Platforms\SocialPlatforms;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Fixtures\Mp4Builder;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

beforeEach(function () {
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));
    config([
        'social.platforms.tiktok.client_id' => 'tt-client-key',
        'social.platforms.tiktok.client_secret' => 'tt-secret',
        'social.publishing.run_budget_seconds' => 30,
    ]);
    $this->tenant = $this->socialTenant('soc-tiktok');
    $this->account = $this->socialAccount($this->tenant, ['platform' => 'tiktok', 'external_id' => 'open-1', 'name' => 'Brand Creator', 'account_type' => 'profile']);

    // TikTok's documented flow: creator info, init, one PUT (201 completes the file), then the status sequence.
    $this->statuses = [];
    $this->calls = [];
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $url = $request->url();
        $ok = fn (array $data) => Http::response(['data' => $data, 'error' => ['code' => 'ok', 'message' => '']]);

        if (str_starts_with($url, 'https://open-upload.tiktokapis.com/')) {
            $this->calls[] = 'chunk';

            return Http::response('', 201);
        }

        $step = match (true) {
            str_contains($url, '/creator_info/query/') => 'creator',
            str_contains($url, '/video/init/') => 'init',
            str_contains($url, '/status/fetch/') => 'status',
            default => 'other',
        };
        $this->calls[] = $step;

        return match ($step) {
            'creator' => $ok(['creator_username' => 'brand.creator', 'privacy_level_options' => ['SELF_ONLY'], 'max_video_post_duration_sec' => 600]),
            'init' => $ok(['publish_id' => 'v_pub_file~v2-1.9', 'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=9&upload_token=SECRET']),
            'status' => $ok(array_shift($this->statuses) ?? ['status' => 'PROCESSING_UPLOAD']),
            default => Http::response(['error' => ['code' => 'invalid_params', 'message' => 'unexpected']], 400),
        };
    });

    $this->store = function (string $name, string $bytes, string $mime): Media {
        $file = UploadedFile::fake()->createWithContent($name, $bytes)->mimeType($mime);

        return $this->inTenant($this->tenant, fn (): Media => app(MediaLibrary::class)->store($file, null));
    };
    // The creator chooses the TikTok settings in the composer; these posts choose the private level TikTok offers.
    $this->create = fn (string $type, array $media, string $body = 'New drop') => $this->inTenant($this->tenant, fn (): Post => app(PostManager::class)->create(
        ['body' => $body, 'post_type' => $type],
        [['account_id' => $this->account->id, 'options' => ['privacy_level' => 'SELF_ONLY']]],
        array_map(fn (Media $item): string => $item->id, $media),
    ));
    $this->schedule = fn (Post $post): Post => $this->inTenant($this->tenant, fn (): Post => app(PostWorkflow::class)->schedule($post, null));
    $this->run = fn (): int => $this->inTenant($this->tenant, fn (): int => app(PostPublisher::class)->runDue());
    $this->target = fn (Post $post): PostTarget => $this->inTenant($this->tenant, fn () => PostTarget::query()->where('post_id', $post->id)->sole());
});

it('is the registered TikTok adapter', function () {
    expect(app(SocialPlatforms::class)->get('tiktok'))->toBeInstanceOf(TikTokPlatform::class);
});

it('refuses to schedule a text-only or photo post for TikTok', function () {
    $text = ($this->create)('post', []);
    expect(fn () => ($this->schedule)($text))->toThrow(ValidationException::class);

    $photo = ($this->store)('p.png', UploadedFile::fake()->image('x.png', 400, 400)->getContent(), 'image/png');
    $photos = ($this->create)('post', [$photo]);
    $issues = $this->inTenant($this->tenant, fn () => app(PostContentRules::class)->previews($photos)[0]['issues']);

    expect($issues)->toContain('This platform publishes video only. Remove the photos or deselect the account.')
        ->and(fn () => ($this->schedule)($photos))->toThrow(ValidationException::class);
    expect($this->calls)->toBe([]);
});

it('publishes a video through the publisher: upload in the first run, published once TikTok says so', function () {
    $video = ($this->store)('clip.mp4', Mp4Builder::make(20, 720, 1280, ['payload' => 2500]), 'video/mp4');
    $post = ($this->schedule)(($this->create)('video', [$video], 'New drop #launch'));
    $this->statuses = [['status' => 'PROCESSING_DOWNLOAD'], ['status' => 'PUBLISH_COMPLETE']];

    $preview = $this->inTenant($this->tenant, fn () => app(PostContentRules::class)->previews($post)[0]);
    expect($preview['issues'])->toBe([])
        ->and(implode(' ', $preview['warnings']))->toContain('private');

    expect(($this->run)())->toBe(1);

    $target = ($this->target)($post);
    expect($this->calls)->toBe(['creator', 'init', 'chunk'])
        ->and($target->status)->toBe(TargetStatus::Processing)
        ->and($target->external_id)->toBe('v_pub_file~v2-1.9')
        ->and(json_encode($target->remote_meta))->not->toContain('SECRET');

    $this->travel(2)->minutes();
    ($this->run)();
    expect(($this->target)($post)->status)->toBe(TargetStatus::Processing);

    $this->travel(2)->minutes();
    ($this->run)();

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Published)
        ->and($target->external_id)->toBe('v_pub_file~v2-1.9')
        ->and($target->remote_meta)->toBeNull()
        ->and(array_count_values($this->calls))->toBe(['creator' => 1, 'init' => 1, 'chunk' => 1, 'status' => 2])
        ->and($this->inTenant($this->tenant, fn () => Post::query()->findOrFail($post->id)->status))->toBe(PostStatus::Published);
});

it('fails the target with TikTok\'s reason when the post failed', function () {
    $video = ($this->store)('clip.mp4', Mp4Builder::make(20, 720, 1280, ['payload' => 2500]), 'video/mp4');
    $post = ($this->schedule)(($this->create)('video', [$video]));
    $this->statuses = [['status' => 'FAILED', 'fail_reason' => 'spam_risk_too_many_posts']];

    ($this->run)();
    $this->travel(2)->minutes();
    ($this->run)();

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Failed)
        ->and($target->last_error_code)->toBe('tiktok_spam_risk_too_many_posts')
        ->and($target->last_error)->toContain('daily limit')
        ->and($target->needs_manual_check)->toBeFalse()
        ->and(array_count_values($this->calls)['init'])->toBe(1);
});
