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
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Fixtures\Mp4Builder;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

beforeEach(function () {
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));
    config([
        'social.platforms.facebook.client_id' => 'fb-id',
        'social.platforms.facebook.client_secret' => 'fb-secret',
        'social.platforms.facebook.api_version' => 'v23.0',
        'social.platforms.instagram.publishing' => true,
        'social.publishing.run_budget_seconds' => 30,
    ]);

    // Scripted Graph API: "METHOD path" => list of [body, status]; each call takes the next, the last one repeats.
    $this->script = [];
    $this->sent = [];
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => function (Request $request) {
        $key = $request->method().' '.Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/v23.0/');
        $this->sent[] = ['key' => $key, 'data' => $request->data(), 'request' => $request];

        if (! isset($this->script[$key])) {
            return Http::response(['error' => ['message' => 'unexpected '.$key]], 500);
        }

        [$body, $status] = count($this->script[$key]) > 1 ? array_shift($this->script[$key]) : $this->script[$key][0];

        return Http::response($body, $status);
    }]);
    $this->count = fn (string $key): int => count(array_filter($this->sent, fn (array $call): bool => $call['key'] === $key));

    $this->tenant = $this->socialTenant('soc-instagram');
    $this->fakePlatform();
    $this->account = $this->socialAccount($this->tenant, [
        'platform' => 'instagram', 'external_id' => 'IG1', 'name' => 'Brand', 'handle' => 'brand', 'account_type' => 'business_account',
        'refresh_token' => null, 'token_expires_at' => null,
    ]);

    $this->store = function (string $name, string $bytes, string $mime): Media {
        $file = UploadedFile::fake()->createWithContent($name, $bytes)->mimeType($mime);

        return $this->inTenant($this->tenant, fn (): Media => app(MediaLibrary::class)->store($file, null));
    };
    $this->jpeg = fn (int $w = 1080, int $h = 1080): Media => ($this->store)('photo-'.$w.'x'.$h.'.jpg', UploadedFile::fake()->image('x.jpg', $w, $h)->getContent(), 'image/jpeg');
    $this->create = fn (string $type, array $media, string $body = 'Caption #brand'): Post => $this->inTenant($this->tenant, fn (): Post => app(PostManager::class)->create(
        ['body' => $body, 'post_type' => $type],
        [['account_id' => $this->account->id]],
        array_map(fn (Media $item): string => $item->id, $media),
    ));
    $this->schedule = fn (Post $post): Post => $this->inTenant($this->tenant, fn (): Post => app(PostWorkflow::class)->schedule($post, null));
    $this->run = fn (): int => $this->inTenant($this->tenant, fn (): int => app(PostPublisher::class)->runDue());
    $this->target = fn (Post $post): PostTarget => $this->inTenant($this->tenant, fn () => PostTarget::query()->where('post_id', $post->id)->sole());
    $this->quota = [[['data' => [['quota_usage' => 0, 'config' => ['quota_total' => 50]]]], 200]];
});

it('publishes a JPEG photo through a container Instagram fetches from a signed URL, then media_publish', function () {
    $photo = ($this->jpeg)();
    $this->script = [
        'GET IG1/content_publishing_limit' => $this->quota,
        'POST IG1/media' => [[['id' => 'C1'], 200]],
        'GET C1' => [[['status_code' => 'FINISHED'], 200]],
        'POST IG1/media_publish' => [[['id' => '1790'], 200]],
        'GET 1790' => [[['permalink' => 'https://www.instagram.com/p/abc/'], 200]],
    ];
    $post = ($this->schedule)(($this->create)('post', [$photo]));

    expect(($this->run)())->toBe(1);

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Published)
        ->and($target->external_id)->toBe('1790')
        ->and($target->external_url)->toBe('https://www.instagram.com/p/abc/')
        ->and($target->remote_meta)->toBeNull()
        ->and($this->inTenant($this->tenant, fn () => Post::query()->findOrFail($post->id)->status))->toBe(PostStatus::Published);

    $container = collect($this->sent)->firstWhere('key', 'POST IG1/media');
    $url = (string) $container['data']['image_url'];

    expect($container['data']['caption'])->toBe('Caption #brand')
        ->and($url)->toStartWith($this->tenantUrl($this->tenant, '/social/public-media/'.$photo->id))
        ->and($url)->toContain('signature=')
        ->and($container['request']->header('Authorization')[0])->toBe('Bearer '.$this->account->access_token);

    // The URL Instagram receives really serves that one file.
    $this->get($url)->assertOk();

    // Neither the URL nor the token is kept anywhere.
    $log = $this->inTenant($this->tenant, fn () => PublishAttempt::query()->where('target_id', $target->id)->get()->toJson());
    expect($log)->not->toContain('signature')->not->toContain((string) $this->account->access_token);
});

it('waits for a Reel container across runs, creating it once and publishing it once', function () {
    $video = ($this->store)('clip.mp4', Mp4Builder::make(10, 1080, 1920, ['payload' => 2500]), 'video/mp4');
    $this->script = [
        'GET IG1/content_publishing_limit' => $this->quota,
        'POST IG1/media' => [[['id' => 'C1'], 200]],
        'GET C1' => [[['status_code' => 'IN_PROGRESS'], 200], [['status_code' => 'FINISHED'], 200]],
        'POST IG1/media_publish' => [[['id' => '1791'], 200]],
        'GET 1791' => [[['permalink' => 'https://www.instagram.com/reel/r/'], 200]],
    ];
    $post = ($this->schedule)(($this->create)('reel', [$video], 'Reel'));

    expect(($this->run)())->toBe(1);

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Processing)
        ->and($target->remote_id)->toBe('C1')
        ->and($target->external_id)->toBeNull()
        ->and(json_encode($target->remote_meta))->not->toContain('http')
        ->and(($this->count)('POST IG1/media_publish'))->toBe(0);

    $this->travel(2)->minutes();
    expect(($this->run)())->toBe(1);

    $target = ($this->target)($post);
    $container = collect($this->sent)->firstWhere('key', 'POST IG1/media');
    expect($target->status)->toBe(TargetStatus::Published)
        ->and($target->external_id)->toBe('1791')
        ->and($container['data']['media_type'])->toBe('REELS')
        ->and($container['data']['video_url'])->toContain('/social/public-media/'.$video->id)
        ->and(($this->count)('POST IG1/media'))->toBe(1)
        ->and(($this->count)('POST IG1/media_publish'))->toBe(1);

    $this->travel(2)->minutes();
    expect(($this->run)())->toBe(0);
});

it('flags a lost media_publish for a manual check and never sends it again', function () {
    $this->script = [
        'GET IG1/content_publishing_limit' => $this->quota,
        'POST IG1/media' => [[['id' => 'C1'], 200]],
        'GET C1' => [[['status_code' => 'FINISHED'], 200]],
        'POST IG1/media_publish' => [[['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 503]],
    ];
    $post = ($this->schedule)(($this->create)('post', [($this->jpeg)()]));

    ($this->run)();

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Failed)
        ->and($target->needs_manual_check)->toBeTrue()
        ->and($target->remote_id)->toBeNull();

    $this->travel(1)->hours();
    expect(($this->run)())->toBe(0)
        ->and(($this->count)('POST IG1/media_publish'))->toBe(1);
});

it('retries a container call that failed before anything was public, then publishes once', function () {
    $this->script = [
        'GET IG1/content_publishing_limit' => $this->quota,
        'POST IG1/media' => [[['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 503], [['id' => 'C1'], 200]],
        'GET C1' => [[['status_code' => 'FINISHED'], 200]],
        'POST IG1/media_publish' => [[['id' => '1792'], 200]],
        'GET 1792' => [[[], 200]],
    ];
    $post = ($this->schedule)(($this->create)('post', [($this->jpeg)()]));

    ($this->run)();

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Processing)
        ->and($target->needs_manual_check)->toBeFalse()
        ->and($target->last_error_code)->toBe('platform_error')
        ->and(($this->count)('POST IG1/media_publish'))->toBe(0);

    $this->travel(2)->minutes();
    ($this->run)();

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Published)
        ->and($target->external_id)->toBe('1792')
        ->and($target->external_url)->toBeNull()
        ->and(($this->count)('POST IG1/media'))->toBe(2)
        ->and(($this->count)('POST IG1/media_publish'))->toBe(1);
});

it('fails a container Instagram could not process, without publishing it', function () {
    $this->script = [
        'GET IG1/content_publishing_limit' => $this->quota,
        'POST IG1/media' => [[['id' => 'C1'], 200]],
        'GET C1' => [[['status_code' => 'ERROR', 'status' => 'Error: the aspect ratio is not supported'], 200]],
    ];
    $post = ($this->schedule)(($this->create)('post', [($this->jpeg)()]));

    ($this->run)();

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Failed)
        ->and($target->last_error_code)->toBe('media_processing_failed')
        ->and($target->last_error)->toContain('aspect ratio')
        ->and($target->needs_manual_check)->toBeFalse()
        ->and(($this->count)('POST IG1/media_publish'))->toBe(0);
});

it('refuses to schedule what Instagram cannot publish, with Instagram reasons', function () {
    $refused = function (Post $post): array {
        try {
            ($this->schedule)($post);
        } catch (ValidationException $exception) {
            return $exception->errors()['targets'] ?? [];
        }

        return [];
    };

    expect(implode(' ', $refused(($this->create)('post', []))))->toContain('does not publish text on its own');

    $png = ($this->store)('logo.png', UploadedFile::fake()->image('x.png', 1080, 1080)->getContent(), 'image/png');
    expect(implode(' ', $refused(($this->create)('post', [$png]))))->toContain('JPEG photos only');

    expect(implode(' ', $refused(($this->create)('post', [($this->jpeg)(1080, 1920)]))))->toContain('taller than Instagram allows');

    $tags = implode(' ', array_map(fn (int $i): string => '#t'.$i, range(1, 31)));
    expect(implode(' ', $refused(($this->create)('post', [($this->jpeg)()], $tags))))->toContain('at most 30 hashtags');

    // A Reel is a post type Instagram publishes.
    $video = ($this->store)('clip.mp4', Mp4Builder::make(10, 1080, 1920, ['payload' => 2500]), 'video/mp4');
    expect($refused(($this->create)('reel', [$video])))->toBe([]);

    config(['social.platforms.instagram.publishing' => false]);
    expect(implode(' ', $refused(($this->create)('post', [($this->jpeg)()]))))->toContain('Instagram publishing is switched off');
});
