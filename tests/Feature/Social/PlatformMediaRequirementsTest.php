<?php

declare(strict_types=1);

use App\Modules\Social\Actions\MediaLibrary;
use App\Modules\Social\Actions\PostManager;
use App\Modules\Social\Actions\PostPublisher;
use App\Modules\Social\Actions\PostWorkflow;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\AuthorizationRequest;
use App\Modules\Social\Platforms\MediaInfo;
use App\Modules\Social\Platforms\PlatformAvailability;
use App\Modules\Social\Platforms\PlatformCapabilities;
use App\Modules\Social\Platforms\PostMetricsSnapshot;
use App\Modules\Social\Platforms\PublishedPost;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\SocialPlatform;
use App\Modules\Social\Platforms\SocialPlatforms;
use App\Modules\Social\Platforms\TokenSet;
use App\Modules\Social\Platforms\ValidatesMedia;
use App\Modules\Social\Support\PublicMediaUrls;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Fixtures\FakeSocialPlatform;
use Tests\Support\Fixtures\Mp4Builder;
use Tests\Support\InteractsWithSocial;

/*
 * The shared groundwork for Instagram, TikTok, YouTube and Pinterest (ADR 0033): media requirements in the
 * platform capabilities and the optional ValidatesMedia contract (checked by the composer and again before
 * scheduling), and the public, expiring media URL a platform that fetches media itself is given.
 */

uses(InteractsWithSocial::class);

beforeEach(function () {
    $this->disk = (string) config('tenancy.filesystem.disk', 'local');
    Storage::fake($this->disk);
    $this->tenant = $this->socialTenant('soc-reqs');
    $this->url = fn (string $path = '') => $this->socialUrl($this->tenant, $path);

    // A media-only platform (Instagram, Pinterest) and a video-only one with length and size bounds (TikTok, YouTube).
    $this->mediaOnly = new FakeSocialPlatform('mediaonly', new PlatformCapabilities(
        publish: true, schedule: true, images: true, video: true, maxText: 2200, maxMedia: 10, requiresMedia: true,
    ));
    $this->videoOnly = new FakeSocialPlatform('videoonly', new PlatformCapabilities(
        publish: true, schedule: true, video: true, maxText: 2200, maxMedia: 1,
        requiresVideo: true, maxVideoBytes: 5 * 1024 * 1024, maxVideoSeconds: 60, minVideoSeconds: 3,
    ));
    $registry = app(SocialPlatforms::class);
    $registry->reset();
    $registry->extend($this->mediaOnly);
    $registry->extend($this->videoOnly);

    $this->mediaAccount = $this->socialAccount($this->tenant, ['platform' => 'mediaonly', 'name' => 'Media Account']);
    $this->videoAccount = $this->socialAccount($this->tenant, ['platform' => 'videoonly', 'name' => 'Video Account']);

    $this->photo = fn (string $name = 'a.png'): Media => $this->inTenant($this->tenant, fn (): Media => app(MediaLibrary::class)
        ->store(UploadedFile::fake()->image($name, 40, 30), null));
    $this->video = fn (float $seconds, string $name = 'clip.mp4'): Media => $this->inTenant($this->tenant, fn (): Media => app(MediaLibrary::class)
        ->store(UploadedFile::fake()->createWithContent($name, Mp4Builder::make($seconds, 1080, 1920))->mimeType('video/mp4'), null));
    $this->preflight = fn (array $accountIds, array $mediaIds, string $type = 'post', string $body = 'Hello') => collect($this->postJson(($this->url)('/posts/preflight'), [
        'post_type' => $type,
        'body' => $body,
        'targets' => array_map(static fn (string $id): array => ['account_id' => $id], $accountIds),
        'media_ids' => $mediaIds,
    ])->assertOk()->json('previews'))->keyBy('accountName');
});

describe('media requirements', function () {
    it('refuses a text-only post for a platform that needs media, live and before scheduling', function () {
        $this->actingAsOwner($this->tenant);

        $previews = ($this->preflight)([$this->mediaAccount->id, $this->videoAccount->id], []);

        expect($previews['Media Account']['issues'])->toContain('This platform does not publish text on its own. Add a photo or a video.')
            ->and($previews['Video Account']['issues'])->toContain('This platform publishes video only. Add a video.')
            ->and($previews['Media Account']['capabilities']['requiresMedia'])->toBeTrue()
            ->and($previews['Video Account']['capabilities'])->toMatchArray([
                'requiresMedia' => true, 'requiresVideo' => true, 'maxVideoBytes' => 5 * 1024 * 1024, 'maxVideoSeconds' => 60, 'minVideoSeconds' => 3,
            ]);

        $post = $this->socialPost($this->tenant, [$this->mediaAccount->id]);

        expect(fn () => $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->schedule($post, null)))
            ->toThrow(ValidationException::class, 'Media Account: This platform does not publish text on its own.');
        expect($this->inTenant($this->tenant, fn () => Post::query()->findOrFail($post->id)->status->value))->toBe('draft');
    });

    it('accepts the same post once it carries a photo', function () {
        $this->actingAsOwner($this->tenant);
        $photo = ($this->photo)();

        expect(($this->preflight)([$this->mediaAccount->id], [$photo->id])['Media Account']['issues'])->toBe([]);

        $post = $this->inTenant($this->tenant, fn (): Post => app(PostManager::class)->create(['body' => 'Hi'], [['account_id' => $this->mediaAccount->id]], [$photo->id]));
        $scheduled = $this->inTenant($this->tenant, fn (): Post => app(PostWorkflow::class)->schedule($post, null));

        expect($scheduled->status->value)->toBe('scheduled');
    });

    it('refuses photos for a video-only platform and checks the video length bounds', function () {
        $this->actingAsOwner($this->tenant);
        $photo = ($this->photo)();
        $long = ($this->video)(90, 'long.mp4');
        $short = ($this->video)(1, 'short.mp4');
        $fine = ($this->video)(30, 'fine.mp4');

        expect(($this->preflight)([$this->videoAccount->id], [$photo->id])['Video Account']['issues'])
            ->toContain('This platform publishes video only. Add a video.', 'This platform publishes video only. Remove the photos or deselect the account.');
        expect(($this->preflight)([$this->videoAccount->id], [$long->id], 'video')['Video Account']['issues'])
            ->toContain('The video is longer than this platform accepts (60 seconds).');
        expect(($this->preflight)([$this->videoAccount->id], [$short->id], 'video')['Video Account']['issues'])
            ->toContain('The video is shorter than this platform accepts (3 seconds).');
        expect(($this->preflight)([$this->videoAccount->id], [$fine->id], 'video')['Video Account']['issues'])->toBe([]);
    });

    it('warns instead of guessing when a video length could not be read', function () {
        $this->actingAsOwner($this->tenant);
        $odd = $this->inTenant($this->tenant, fn (): Media => app(MediaLibrary::class)->store(
            UploadedFile::fake()->createWithContent('odd.mp4', "\0\0\0\x18ftypmp42\0\0\0\0mp42isom".str_repeat("\0", 400))->mimeType('video/mp4'),
            null,
        ));

        $preview = ($this->preflight)([$this->videoAccount->id], [$odd->id], 'video')['Video Account'];

        expect($preview['issues'])->toBe([])
            ->and(implode(' ', $preview['warnings']))->toContain('length could not be read');
    });

    it('asks an adapter that validates media itself, with the text it would publish', function () {
        $this->actingAsOwner($this->tenant);
        $inner = new FakeSocialPlatform('strict', new PlatformCapabilities(publish: true, schedule: true, images: true, maxText: 500, maxMedia: 4));
        $strict = new class($inner) implements SocialPlatform, ValidatesMedia
        {
            /** @var list<array{media: list<MediaInfo>, text: string}> */
            public array $asked = [];

            public function __construct(private readonly FakeSocialPlatform $inner) {}

            public function mediaIssues(array $media, string $text): array
            {
                $this->asked[] = ['media' => $media, 'text' => $text];

                return [
                    ['blocking' => true, 'message' => 'Needs a square picture.'],
                    ['blocking' => false, 'message' => 'Captions over 125 characters are cut off.'],
                ];
            }

            public function key(): string
            {
                return $this->inner->key();
            }

            public function label(): string
            {
                return $this->inner->label();
            }

            public function capabilities(): PlatformCapabilities
            {
                return $this->inner->capabilities();
            }

            public function textLength(string $text): int
            {
                return $this->inner->textLength($text);
            }

            public function availability(): PlatformAvailability
            {
                return $this->inner->availability();
            }

            public function authorizationRequest(string $redirectUri, string $state): AuthorizationRequest
            {
                return $this->inner->authorizationRequest($redirectUri, $state);
            }

            public function completeAuthorization(string $code, string $redirectUri, ?string $codeVerifier): array
            {
                return $this->inner->completeAuthorization($code, $redirectUri, $codeVerifier);
            }

            public function refresh(string $refreshToken): TokenSet
            {
                return $this->inner->refresh($refreshToken);
            }

            public function publish(AccountCredentials $account, PublishPayload $payload): PublishedPost
            {
                return $this->inner->publish($account, $payload);
            }

            public function metrics(AccountCredentials $account, string $externalPostId): ?PostMetricsSnapshot
            {
                return $this->inner->metrics($account, $externalPostId);
            }
        };
        app(SocialPlatforms::class)->extend($strict);
        $account = $this->socialAccount($this->tenant, ['platform' => 'strict', 'name' => 'Strict Account']);
        $photo = ($this->photo)();

        $preview = ($this->preflight)([$account->id], [$photo->id], 'post', 'Caption')['Strict Account'];

        expect($preview['issues'])->toBe(['Needs a square picture.'])
            ->and($preview['warnings'])->toBe(['Captions over 125 characters are cut off.'])
            ->and($strict->asked[0]['text'])->toBe('Caption')
            ->and($strict->asked[0]['media'][0])->toBeInstanceOf(MediaInfo::class);
    });

    it('leaves a text platform without requirements exactly as it was', function () {
        $this->actingAsOwner($this->tenant);
        app(SocialPlatforms::class)->extend(new FakeSocialPlatform);
        $plain = $this->socialAccount($this->tenant, ['name' => 'Plain']);

        $preview = ($this->preflight)([$plain->id], [])['Plain'];

        expect($preview['issues'])->toBe([])
            ->and($preview['capabilities'])->toMatchArray(['requiresMedia' => false, 'requiresVideo' => false, 'maxVideoBytes' => 0, 'maxVideoSeconds' => 0]);
    });
});

describe('public, expiring media URLs', function () {
    beforeEach(function () {
        $this->file = ($this->photo)('logo.png');
        $this->signed = fn (Media $media): string => $this->inTenant($this->tenant, fn (): string => app(PublicMediaUrls::class)->for($media));
    });

    it('serves exactly that file to anyone holding the signed URL, without a session or cookies', function () {
        $url = ($this->signed)($this->file);

        expect($url)->toStartWith($this->tenantUrl($this->tenant, '/social/public-media/'.$this->file->id))
            ->and($url)->toContain('signature=')->and($url)->toContain('expires=');

        $response = $this->get($url)->assertOk();
        $bytes = Storage::disk($this->disk)->get('tenants/'.$this->tenant->getTenantKey().'/'.$this->file->path);

        expect($response->streamedContent())->toBe($bytes)
            ->and($response->headers->get('Content-Type'))->toBe('image/png')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->getCookies())->toBe([]);
    });

    it('answers 404 to an unsigned, tampered or expired URL', function () {
        $url = ($this->signed)($this->file);

        $this->get(strtok($url, '?'))->assertNotFound();
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $url))->assertNotFound();
        $this->get(str_replace('expires=', 'expires=9', $url))->assertNotFound();

        $this->travel(PublicMediaUrls::lifetimeMinutes() + 1)->minutes();
        $this->get($url)->assertNotFound();
    });

    it('lives as long as configured, within its bounds', function () {
        config(['social.media.public_url_minutes' => 30]);
        expect(PublicMediaUrls::lifetimeMinutes())->toBe(30);

        config(['social.media.public_url_minutes' => 0]);
        expect(PublicMediaUrls::lifetimeMinutes())->toBe(5);

        config(['social.media.public_url_minutes' => 999999]);
        expect(PublicMediaUrls::lifetimeMinutes())->toBe(1440);
    });

    it('answers 404 for another workspace: its host, or an id that is not in this workspace', function () {
        $other = $this->socialTenant('soc-reqs-other');
        $theirs = $this->inTenant($other, fn (): Media => app(MediaLibrary::class)->store(UploadedFile::fake()->image('theirs.png', 10, 10), null));

        // Our URL replayed on the other workspace's host.
        $ours = ($this->signed)($this->file);
        $this->get(str_replace('soc-reqs.', 'soc-reqs-other.', $ours))->assertNotFound();

        // A validly signed URL of ours that names the other workspace's file.
        $foreign = $this->inTenant($this->tenant, fn (): string => app(PublicMediaUrls::class)->for((new Media)->forceFill(['id' => $theirs->id])));
        $this->get($foreign)->assertNotFound();

        // The other workspace's own URL still works on its own host.
        $this->get($this->inTenant($other, fn (): string => app(PublicMediaUrls::class)->for($theirs)))->assertOk();
    });

    it('is offered to adapters only on request, through the media file the publisher builds', function () {
        $post = $this->inTenant($this->tenant, fn (): Post => app(PostManager::class)->create(['body' => 'Hi'], [['account_id' => $this->mediaAccount->id]], [$this->file->id]));
        $this->inTenant($this->tenant, fn () => app(PostWorkflow::class)->schedule($post, null));

        $this->inTenant($this->tenant, fn () => app(PostPublisher::class)->runDue());

        $media = $this->mediaOnly->published[0]->media[0];
        expect($media->hasPublicUrl())->toBeTrue();

        $url = $this->inTenant($this->tenant, fn (): string => $media->publicUrl());
        expect($url)->toContain('/social/public-media/'.$this->file->id.'?');
        $this->get($url)->assertOk();
    });
});
