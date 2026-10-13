<?php

declare(strict_types=1);

use App\Modules\Social\Actions\MediaLibrary;
use App\Modules\Social\Actions\PostContentRules;
use App\Modules\Social\Actions\PostManager;
use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\Post;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Fixtures\Mp4Builder;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

beforeEach(function () {
    $this->disk = (string) config('tenancy.filesystem.disk', 'local');
    Storage::fake($this->disk);
    config([
        'social.platforms.facebook.client_id' => 'fb-id',
        'social.platforms.facebook.client_secret' => 'fb-secret',
        'social.platforms.facebook.video_publishing' => true,
    ]);
    $this->tenant = $this->socialTenant('soc-video');
    $this->fakePlatform();
    $this->facebook = $this->socialAccount($this->tenant, ['platform' => 'facebook', 'external_id' => 'PAGE1', 'name' => 'Brand Page']);
    $this->url = fn (string $path = '') => $this->socialUrl($this->tenant, $path);
    $this->clip = function (string $bytes, string $name = 'clip.mp4', string $mime = 'video/mp4'): UploadedFile {
        return UploadedFile::fake()->createWithContent($name, $bytes)->mimeType($mime);
    };
    $this->mediaOf = fn (string $name): Media => $this->inTenant($this->tenant, fn () => Media::query()->where('name', $name)->sole());
});

it('reads length and picture size of an uploaded video and shows what each platform can do with it', function () {
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/media'), ['files' => [($this->clip)(Mp4Builder::make(45.5, 1080, 1920), 'reel.mp4')]])->assertRedirect();
    $media = ($this->mediaOf)('reel.mp4');

    expect($media->kind->value)->toBe('video')
        ->and($media->duration_ms)->toBe(45500)
        ->and($media->width)->toBe(1080)->and($media->height)->toBe(1920)
        ->and($media->probe_status)->toBe('read');

    $page = $this->get(($this->url)('/media'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('media.data.0.durationMs', 45500)
        ->where('media.data.0.probe', 'read'));
    $compat = collect($page->viewData('page')['props']['media']['data'][0]['compat']);

    expect($compat->where('platform', 'facebook')->pluck('code')->all())->toContain('video_ok', 'reel_ready')
        // LinkedIn and X publish no media at all.
        ->and($compat->where('platform', 'linkedin')->pluck('code')->all())->toBe(['unsupported'])
        ->and($compat->where('platform', 'x')->pluck('code')->all())->toBe(['unsupported']);
    expect($page->getContent())->not->toContain($media->path);
});

it('marks a video that is too long, too small or not vertical for Reels without refusing the upload', function () {
    $this->actingAsOwner($this->tenant);
    $this->post(($this->url)('/media'), ['files' => [
        ($this->clip)(Mp4Builder::make(120, 1080, 1920), 'long.mp4'),
        ($this->clip)(Mp4Builder::make(30, 480, 640), 'small.mp4'),
        ($this->clip)(Mp4Builder::make(30, 1920, 1080), 'wide.mp4'),
        ($this->clip)(Mp4Builder::make(1, 1080, 1920), 'short.mp4'),
    ]])->assertRedirect();

    $codes = fn (string $name): array => collect($this->get(($this->url)('/media?q='.rawurlencode($name)))->viewData('page')['props']['media']['data'][0]['compat'])
        ->where('platform', 'facebook')->pluck('code')->all();

    expect($codes('long.mp4'))->toContain('video_ok', 'reel_too_long')
        ->and($codes('small.mp4'))->toContain('reel_low_resolution')
        ->and($codes('wide.mp4'))->toContain('reel_check')
        ->and($codes('short.mp4'))->toContain('reel_too_short');
});

it('keeps a video it cannot read, marks it unknown and warns instead of guessing', function () {
    $this->actingAsOwner($this->tenant);
    // Looks like an MP4 to the sniffer, but has no readable header boxes.
    $unreadable = "\0\0\0\x18ftypmp42\0\0\0\0mp42isom".str_repeat("\0", 400);

    $this->post(($this->url)('/media'), ['files' => [($this->clip)($unreadable, 'odd.mp4')]])->assertRedirect();
    $media = ($this->mediaOf)('odd.mp4');

    expect($media->probe_status)->toBe('unknown')
        ->and($media->duration_ms)->toBeNull()->and($media->width)->toBeNull();

    $compat = collect($this->get(($this->url)('/media'))->viewData('page')['props']['media']['data'][0]['compat'])->where('platform', 'facebook')->pluck('code')->all();
    expect($compat)->toContain('reel_check');

    $post = $this->inTenant($this->tenant, fn (): Post => app(PostManager::class)->create(
        ['body' => 'x', 'post_type' => 'reel'], [['account_id' => $this->facebook->id]], [$media->id],
    ));
    $preview = $this->inTenant($this->tenant, fn () => app(PostContentRules::class)->previews($post)[0]);

    expect($preview['issues'])->toBe([])
        ->and(implode(' ', $preview['warnings']))->toContain('could not read');
});

it('refuses a file whose real type is not on the allow-list, even with a video name', function () {
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/media'), ['files' => [($this->clip)('<?php echo 1;', 'clip.mp4', 'text/plain')]])->assertSessionHasErrors();
    $this->post(($this->url)('/media'), ['files' => [($this->clip)(Mp4Builder::make(5, 100, 100), 'clip.avi', 'video/x-msvideo')]])->assertSessionHasErrors();

    expect($this->inTenant($this->tenant, fn () => Media::query()->count()))->toBe(0);
});

it('applies the configured video size cap', function () {
    config(['social.media.video_max_kb' => 1]);
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/media'), ['files' => [($this->clip)(Mp4Builder::make(5, 100, 100, ['payload' => 4000]), 'big.mp4')]])->assertSessionHasErrors();

    expect($this->inTenant($this->tenant, fn () => Media::query()->count()))->toBe(0);
});

it('checks a draft without saving anything and answers per account', function () {
    $this->actingAsOwner($this->tenant);
    $this->post(($this->url)('/media'), ['files' => [($this->clip)(Mp4Builder::make(120, 1080, 1920), 'long.mp4')]]);
    $media = ($this->mediaOf)('long.mp4');
    $generic = $this->socialAccount($this->tenant, ['name' => 'Generic']);

    $response = $this->postJson(($this->url)('/posts/preflight'), [
        'post_type' => 'reel',
        'body' => 'Hello',
        'targets' => [['account_id' => $this->facebook->id], ['account_id' => $generic->id]],
        'media_ids' => [$media->id],
    ])->assertOk();

    $byAccount = collect($response->json('previews'))->keyBy('accountName');
    expect($byAccount['Brand Page']['issues'])->not->toBeEmpty()
        ->and(implode(' ', $byAccount['Brand Page']['issues']))->toContain('at most 90 seconds')
        ->and($byAccount['Generic']['issues'])->toContain('This platform does not publish Reels.')
        ->and($this->inTenant($this->tenant, fn () => Post::query()->count()))->toBe(0);

    $ok = $this->postJson(($this->url)('/posts/preflight'), [
        'post_type' => 'video', 'body' => 'Hello', 'targets' => [['account_id' => $this->facebook->id]], 'media_ids' => [$media->id],
    ])->assertOk();
    expect($ok->json('previews.0.issues'))->toBe([])->and($ok->json('previews.0.postType'))->toBe('video');
});

it('explains why a post type does not fit the chosen accounts', function () {
    $this->actingAsOwner($this->tenant);
    $photo = $this->inTenant($this->tenant, fn (): Media => app(MediaLibrary::class)->store(UploadedFile::fake()->image('a.png', 30, 20), null));
    $other = $this->inTenant($this->tenant, fn (): Media => app(MediaLibrary::class)->store(UploadedFile::fake()->image('b.png', 31, 20), null));

    $linkedin = $this->socialAccount($this->tenant, ['platform' => 'linkedin', 'name' => 'Company']);
    config(['social.platforms.linkedin.client_id' => 'li', 'social.platforms.linkedin.client_secret' => 'li-secret']);

    $response = $this->postJson(($this->url)('/posts/preflight'), [
        'post_type' => 'photos',
        'body' => 'Two photos',
        'targets' => [['account_id' => $this->facebook->id], ['account_id' => $linkedin->id]],
        'media_ids' => [$photo->id, $other->id],
    ])->assertOk();

    $byAccount = collect($response->json('previews'))->keyBy('accountName');
    expect($byAccount['Brand Page']['issues'])->toBe([])
        ->and(implode(' ', $byAccount['Company']['issues']))->toContain('cannot publish media');

    // A plain post may not carry two photos on Facebook; the message points at the right type.
    $plain = $this->postJson(($this->url)('/posts/preflight'), [
        'post_type' => 'post', 'body' => 'x', 'targets' => [['account_id' => $this->facebook->id]], 'media_ids' => [$photo->id, $other->id],
    ])->assertOk();
    expect(implode(' ', $plain->json('previews.0.issues')))->toContain('Photos post type');
});

it('offers only the post types an account really publishes', function () {
    $this->actingAsOwner($this->tenant);

    $this->get(($this->url)('/posts/create'))->assertOk()->assertInertia(function (AssertableInertia $page) {
        $accounts = collect($page->toArray()['props']['form']['accounts'])->keyBy('name');
        expect($accounts['Brand Page']['postTypes'])->toBe(['post', 'photos', 'video', 'reel']);
    });

    config(['social.platforms.facebook.video_publishing' => false]);
    $this->get(($this->url)('/posts/create'))->assertInertia(function (AssertableInertia $page) {
        $accounts = collect($page->toArray()['props']['form']['accounts'])->keyBy('name');
        expect($accounts['Brand Page']['postTypes'])->toBe(['post', 'photos'])
            ->and($accounts['Brand Page']['capabilities']['video'])->toBeFalse();
    });
});

it('saves and validates the post type', function () {
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/posts'), [
        'post_type' => 'reel', 'body' => 'Reel caption', 'targets' => [['account_id' => $this->facebook->id]],
    ])->assertRedirect();
    $post = $this->inTenant($this->tenant, fn () => Post::query()->sole());
    expect($post->post_type)->toBe(PostType::Reel);

    $this->post(($this->url)('/posts'), ['post_type' => 'carousel', 'body' => 'x'])->assertSessionHasErrors('post_type');

    // Omitting it keeps the classic behaviour (CSV import and older callers).
    $this->post(($this->url)('/posts'), ['body' => 'plain'])->assertRedirect();
    expect($this->inTenant($this->tenant, fn () => Post::query()->where('body', 'plain')->sole()->post_type))->toBe(PostType::Post);
});

it('keeps another workspace media and accounts out of the preflight', function () {
    $other = $this->socialTenant('soc-video-other');
    $this->inTenant($other, fn () => null);
    $foreignAccount = $this->socialAccount($other, ['platform' => 'facebook', 'external_id' => 'OTHERPAGE']);
    $foreignMedia = $this->inTenant($other, fn (): Media => app(MediaLibrary::class)->store(
        UploadedFile::fake()->createWithContent('theirs.mp4', Mp4Builder::make(20, 1080, 1920))->mimeType('video/mp4'), null,
    ));

    $this->actingAsOwner($this->tenant);
    $response = $this->postJson(($this->url)('/posts/preflight'), [
        'post_type' => 'reel', 'body' => 'x',
        'targets' => [['account_id' => $foreignAccount->id], ['account_id' => $this->facebook->id]],
        'media_ids' => [$foreignMedia->id],
    ])->assertOk();

    expect($response->json('previews'))->toHaveCount(1)
        ->and($response->json('previews.0.accountName'))->toBe('Brand Page')
        ->and($response->json('previews.0.mediaCount'))->toBe(0);
});

it('does not let a member without the view permission use the preflight', function () {
    [$user] = $this->socialMember($this->tenant, ['social.media.view']);
    $this->actingAsMember($this->tenant, $user);

    $this->postJson(($this->url)('/posts/preflight'), ['post_type' => 'post', 'body' => 'x'])->assertForbidden();
});
