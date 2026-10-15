<?php

declare(strict_types=1);

use App\Modules\Social\Actions\MediaLibrary;
use App\Modules\Social\Actions\PostManager;
use App\Modules\Social\Actions\PostPublisher;
use App\Modules\Social\Actions\PostWorkflow;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Platforms\SocialPlatforms;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Fixtures\Mp4Builder;
use Tests\Support\InteractsWithSocial;

/*
 * Pinterest through the real publisher (ADR 0033): a Pin needs media, an image post becomes one Pin on the
 * connected board, and a video Pin is registered, uploaded, polled and created over several runs, once.
 * Pinterest itself is faked with Http::fake; nothing here has reached Pinterest.
 */

uses(InteractsWithSocial::class);

beforeEach(function () {
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));
    config([
        'social.platforms.pinterest.client_id' => 'pin-app-id',
        'social.platforms.pinterest.client_secret' => 'pin-app-secret',
        'social.publishing.processing_poll_seconds' => 30,
        'social.publishing.run_budget_seconds' => 30,
    ]);
    app(SocialPlatforms::class)->reset();
    $this->tenant = $this->socialTenant('soc-pinterest');
    $this->account = $this->socialAccount($this->tenant, [
        'platform' => 'pinterest', 'external_id' => '4455667788', 'name' => 'jane · Recipes', 'account_type' => 'board',
    ]);

    $this->store = function (string $name, string $bytes, string $mime): Media {
        $file = UploadedFile::fake()->createWithContent($name, $bytes)->mimeType($mime);

        return $this->inTenant($this->tenant, fn (): Media => app(MediaLibrary::class)->store($file, null));
    };
    $this->photo = fn (): Media => ($this->store)('dish.png', UploadedFile::fake()->image('x.png', 60, 40)->getContent(), 'image/png');
    $this->video = fn (): Media => ($this->store)('clip.mp4', Mp4Builder::make(10, 1080, 1920, ['payload' => 2500]), 'video/mp4');
    $this->draft = fn (string $type, array $media, string $body = "Lemon tart\nBright and simple.") => $this->inTenant($this->tenant, fn (): Post => app(PostManager::class)->create(
        ['body' => $body, 'post_type' => $type, 'link_url' => 'https://shop.example/tart'],
        [['account_id' => $this->account->id]],
        array_map(fn (Media $item): string => $item->id, $media),
    ));
    $this->schedule = fn (Post $post): Post => $this->inTenant($this->tenant, fn (): Post => app(PostWorkflow::class)->schedule($post, null));
    $this->run = fn (): int => $this->inTenant($this->tenant, fn (): int => app(PostPublisher::class)->runDue());
    $this->target = fn (Post $post): PostTarget => $this->inTenant($this->tenant, fn () => PostTarget::query()->where('post_id', $post->id)->sole());
});

it('refuses to schedule a text-only post for a Pinterest board', function () {
    $post = ($this->draft)('post', []);

    try {
        ($this->schedule)($post);
        $this->fail('A text-only Pinterest post must be refused.');
    } catch (ValidationException $exception) {
        expect(implode(' ', $exception->errors()['targets']))->toContain('jane · Recipes')
            ->toContain('does not publish text on its own');
    }
});

it('publishes an image post as one Pin on the connected board', function () {
    Http::fake(['api.pinterest.com/v5/pins' => Http::response(['id' => '1001'], 201)]);
    $post = ($this->schedule)(($this->draft)('post', [($this->photo)()]));

    expect(($this->run)())->toBe(1);

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Published)
        ->and($target->external_id)->toBe('1001')
        ->and($target->external_url)->toBe('https://www.pinterest.com/pin/1001/');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pinterest.com/v5/pins'
        && $request['board_id'] === '4455667788'
        && $request['title'] === 'Lemon tart'
        && $request['link'] === 'https://shop.example/tart'
        && $request['media_source']['source_type'] === 'image_base64'
        && $request['media_source']['content_type'] === 'image/png'
        && $request['media_source']['data'] !== '');
});

it('publishes a video Pin over several runs and creates the Pin exactly once', function () {
    Http::fake([
        'api.pinterest.com/v5/media' => Http::response([
            'media_id' => '31337', 'media_type' => 'video',
            'upload_url' => 'https://pinterest-media-upload.s3-accelerate.amazonaws.com/',
            'upload_parameters' => ['key' => 'uploads/x', 'policy' => 'p', 'x-amz-signature' => 's'],
        ], 201),
        'pinterest-media-upload.s3-accelerate.amazonaws.com/*' => Http::response('', 204),
        'api.pinterest.com/v5/media/31337' => Http::sequence()
            ->push(['media_id' => '31337', 'status' => 'processing'])
            ->push(['media_id' => '31337', 'status' => 'succeeded']),
        'api.pinterest.com/v5/pins' => Http::response(['id' => '2002'], 201),
    ]);
    $post = ($this->schedule)(($this->draft)('video', [($this->video)()]));

    expect(($this->run)())->toBe(1);
    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Processing)
        ->and($target->remote_id)->toBe('31337')
        ->and(json_encode($target->remote_meta))->not->toContain('x-amz-signature');

    $this->travel(31)->seconds();
    expect(($this->run)())->toBe(1);
    expect(($this->target)($post)->status)->toBe(TargetStatus::Processing);

    $this->travel(31)->seconds();
    expect(($this->run)())->toBe(1);

    $target = ($this->target)($post);
    expect($target->status)->toBe(TargetStatus::Published)
        ->and($target->external_id)->toBe('2002')
        ->and($target->remote_id)->toBeNull();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pinterest.com/v5/pins'
        && $request['media_source'] === ['source_type' => 'video_id', 'media_id' => '31337', 'cover_image_key_frame_time' => 1]);
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->url() === 'https://api.pinterest.com/v5/pins'))->toHaveCount(1)
        ->and(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->url() === 'https://api.pinterest.com/v5/media'))->toHaveCount(1);

    $this->travel(5)->minutes();
    expect(($this->run)())->toBe(0);
});
