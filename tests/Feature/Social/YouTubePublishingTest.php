<?php

declare(strict_types=1);

use App\Modules\Social\Actions\MediaLibrary;
use App\Modules\Social\Actions\PostManager;
use App\Modules\Social\Actions\PostPublisher;
use App\Modules\Social\Actions\PostWorkflow;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Models\PublishAttempt;
use App\Modules\Social\Platforms\Adapters\YouTubePlatform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Fixtures\Mp4Builder;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

const YTF_SESSION = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=FEATURE-SESSION-77';

beforeEach(function () {
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));
    config([
        'social.platforms.youtube.client_id' => 'yt-id',
        'social.platforms.youtube.client_secret' => 'yt-secret',
        'social.platforms.youtube.limits.chunk_bytes' => 256 * 1024,
        // One chunk per run, so the upload spans several runs of the publish job.
        'social.publishing.run_budget_seconds' => 0,
    ]);
    $this->tenant = $this->socialTenant('soc-youtube');
    $this->account = $this->socialAccount($this->tenant, ['platform' => 'youtube', 'external_id' => 'UC1', 'name' => 'Brand Channel', 'account_type' => 'channel']);

    // Google's resumable upload: a session URI, then 308 + Range per chunk, then the video.
    $this->google = new class
    {
        public int $received = 0;

        public int $starts = 0;

        public int $completions = 0;

        /** @var list<Closure> */
        public array $failNext = [];

        public function respond(Request $request): mixed
        {
            if ($request->method() === 'POST' && str_contains($request->url(), '/upload/youtube/v3/videos?')) {
                $this->starts++;

                return Http::response('', 200, ['Location' => YTF_SESSION]);
            }

            if ($request->method() === 'PUT' && $request->url() === YTF_SESSION) {
                if ($this->failNext !== []) {
                    return (array_shift($this->failNext))();
                }

                $range = $request->header('Content-Range')[0] ?? '';
                preg_match('~^bytes (\d+|\*)(?:-(\d+))?/(\d+)$~', $range, $m);
                $this->received += strlen($request->body());

                if ($this->received === (int) $m[3]) {
                    $this->completions += $m[1] === '*' ? 0 : 1;

                    return Http::response(['id' => 'yt-video-1', 'status' => ['privacyStatus' => 'public']], 200);
                }

                return Http::response('', 308, $this->received > 0 ? ['Range' => 'bytes=0-'.($this->received - 1)] : []);
            }

            return Http::response('', 404);
        }
    };
    Http::swap(new Factory);
    Http::fake(fn (Request $request) => $this->google->respond($request));

    $this->video = function (string $body = "Launch day\nAll about it."): Post {
        $file = UploadedFile::fake()->createWithContent('clip.mp4', Mp4Builder::make(30, 1920, 1080, ['payload' => 600 * 1024]))->mimeType('video/mp4');
        $media = $this->inTenant($this->tenant, fn (): Media => app(MediaLibrary::class)->store($file, null));
        $post = $this->inTenant($this->tenant, fn (): Post => app(PostManager::class)->create(
            ['body' => $body, 'post_type' => 'video'],
            [['account_id' => $this->account->id]],
            [$media->id],
        ));

        return $this->inTenant($this->tenant, fn (): Post => app(PostWorkflow::class)->schedule($post, null));
    };
    $this->run = function (): int {
        $this->travel(2)->minutes();

        return $this->inTenant($this->tenant, fn (): int => app(PostPublisher::class)->runDue());
    };
    $this->target = fn (Post $post): PostTarget => $this->inTenant($this->tenant, fn () => PostTarget::query()->where('post_id', $post->id)->sole());
});

it('uploads a video to YouTube across several publish runs and publishes it once', function () {
    $post = ($this->video)();

    ($this->run)();
    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Processing)
        ->and($target->remote_state)->toBe('uploading')
        ->and($this->google->starts)->toBe(1)
        ->and($this->google->received)->toBe(0);

    // The saved session never holds the upload URI in the clear.
    $raw = (string) DB::table('social_post_targets')->where('id', $target->id)->value('remote_meta');
    expect($raw)->not->toContain('FEATURE-SESSION-77')->not->toContain('googleapis');

    ($this->run)();
    expect(($this->target)($post)->upload_offset)->toBe(256 * 1024);

    for ($i = 0; $i < 5 && ($this->target)($post)->status !== TargetStatus::Published; $i++) {
        ($this->run)();
    }

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Published)
        ->and($target->external_id)->toBe('yt-video-1')
        ->and($target->external_url)->toBe('https://www.youtube.com/watch?v=yt-video-1')
        ->and($target->remote_meta)->toBeNull()
        ->and($this->google->starts)->toBe(1)
        ->and($this->google->completions)->toBe(1)
        ->and($this->inTenant($this->tenant, fn () => Post::query()->findOrFail($post->id)->status))->toBe(PostStatus::Published);
});

it('resumes after a failed chunk from what YouTube confirms, without starting a new upload', function () {
    $post = ($this->video)();
    ($this->run)();
    ($this->run)();

    $this->google->failNext[] = fn () => Http::response(['error' => ['code' => 503, 'message' => 'Backend Error']], 503);
    ($this->run)();
    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Processing)
        ->and($target->last_error_code)->toBe('platform_error')
        ->and($target->needs_manual_check)->toBeFalse();

    for ($i = 0; $i < 6 && ($this->target)($post)->status !== TargetStatus::Published; $i++) {
        ($this->run)();
    }

    expect(($this->target)($post)->status)->toBe(TargetStatus::Published)
        ->and($this->google->starts)->toBe(1)
        ->and($this->google->completions)->toBe(1);
});

it('flags a lost answer to the completing chunk for a manual check and never sends it again', function () {
    $post = ($this->video)();

    for ($i = 0; $i < 3; $i++) {
        ($this->run)();
    }

    expect(($this->target)($post)->upload_offset)->toBe(512 * 1024);
    $this->google->failNext[] = fn () => throw new ConnectionException('cURL error 28: Operation timed out for '.YTF_SESSION);
    ($this->run)();

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Failed)
        ->and($target->last_error_code)->toBe('outcome_unknown')
        ->and($target->needs_manual_check)->toBeTrue()
        ->and((string) $target->last_error)->not->toContain('FEATURE-SESSION-77');

    $messages = $this->inTenant($this->tenant, fn () => PublishAttempt::query()->pluck('message')->implode(' '));
    expect($messages)->not->toContain('FEATURE-SESSION-77');

    $puts = $this->google->received;
    ($this->run)();
    expect($this->google->received)->toBe($puts);
});

it('refuses to schedule a YouTube post without a title line or without a video', function () {
    try {
        ($this->video)('<>');
        $this->fail('expected a validation error');
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->implode(' '))->toContain('first line becomes the video title');
    }

    $post = $this->inTenant($this->tenant, fn (): Post => app(PostManager::class)->create(
        ['body' => 'Text only'], [['account_id' => $this->account->id]], [],
    ));
    expect(fn () => $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->schedule($post, null)))
        ->toThrow(ValidationException::class);
    expect(app(YouTubePlatform::class)->capabilities()->requiresVideo)->toBeTrue();
});
