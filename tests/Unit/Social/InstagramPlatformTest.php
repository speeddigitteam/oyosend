<?php

declare(strict_types=1);

use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Adapters\InstagramPlatform;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\Exceptions\ReauthorizationRequired;
use App\Modules\Social\Platforms\MediaFile;
use App\Modules\Social\Platforms\MediaInfo;
use App\Modules\Social\Platforms\PlatformAvailability;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\PublishProgress;
use App\Modules\Social\Platforms\PublishSession;
use App\Modules\Social\Platforms\SocialPlatforms;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const IG_TOKEN = 'page-token-secret-123';
const IG_SIGNATURE = 'sig-secret-456';

/**
 * Scripted Graph API: `"METHOD path"` => list of `[body, status]` (or a Closure that throws / answers).
 * Each call takes the next answer; the last one repeats. Unknown routes answer 500 so a stray call fails loudly.
 * Calling it again replaces the script and forgets the requests sent so far.
 *
 * @param  array<string, list<array{0: array<string, mixed>, 1?: int}|Closure>>  $script
 */
function igGraph(array $script): void
{
    $GLOBALS['igScript'] = $script;
    $GLOBALS['igSent'] = [];
}

function igPath(Request $request): string
{
    return Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/v23.0/');
}

/** @return list<Request> */
function igSent(?string $method = null, ?string $path = null): array
{
    return array_values(array_filter($GLOBALS['igSent'] ?? [], fn (Request $request): bool => ($method === null || $request->method() === $method)
        && ($path === null || igPath($request) === $path)));
}

beforeEach(function () {
    igGraph([]);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $GLOBALS['igSent'][] = $request;
        $key = $request->method().' '.igPath($request);

        if (! isset($GLOBALS['igScript'][$key])) {
            return Http::response(['error' => ['message' => 'unexpected '.$key]], 500);
        }

        $answer = count($GLOBALS['igScript'][$key]) > 1 ? array_shift($GLOBALS['igScript'][$key]) : $GLOBALS['igScript'][$key][0];

        return $answer instanceof Closure ? $answer($request) : Http::response($answer[0], $answer[1] ?? 200);
    });
    config([
        'social.platforms.facebook' => ['client_id' => 'fb-id', 'client_secret' => 'fb-secret', 'api_version' => 'v23.0'],
        'social.platforms.instagram.publishing' => true,
        'social.publishing.processing_poll_seconds' => 45,
    ]);
    $this->ig = app(InstagramPlatform::class);
    $this->account = new AccountCredentials('IG1', IG_TOKEN);
    $this->urls = 0;
    $this->file = function (string $name, bool $video = false, int $width = 1080, int $height = 1080): MediaFile {
        return new MediaFile(
            $name, $video ? 'video/mp4' : 'image/jpeg', $video, null, fn (): string => 'bytes',
            sizeBytes: 2048, width: $width, height: $height, durationMs: $video ? 10_000 : null,
            publicUrl: function () use ($name): string {
                $this->urls++;

                return 'https://ws.oyosend.test/social/public-media/'.$name.'?expires=1&w=ws&signature='.IG_SIGNATURE;
            },
        );
    };
    $this->quota = [[['data' => [['quota_usage' => 2, 'config' => ['quota_total' => 50, 'quota_duration' => 86400]]]]]];
    $this->advance = fn (PublishPayload $payload, ?PublishSession $session = null, ?float $deadline = null): PublishProgress => $this->ig->advance(
        $this->account, $payload, $session ?? new PublishSession, $deadline ?? microtime(true) + 30,
    );
    $this->resume = fn (PublishProgress $progress): PublishSession => new PublishSession(
        $progress->remoteId, $progress->state, $progress->offset, $progress->meta, $progress->externalId,
    );
});

it('is unavailable with an honest reason while switched off or while the Facebook app is not configured', function () {
    config(['social.platforms.instagram.publishing' => false]);
    $off = $this->ig->availability();

    expect($off->available)->toBeFalse()
        ->and($off->code)->toBe(PlatformAvailability::NOT_CONFIGURED)
        ->and($off->reason)->toContain('switched off');

    config(['social.platforms.instagram.publishing' => true, 'social.platforms.facebook.client_secret' => null]);
    $unconfigured = $this->ig->availability();

    expect($unconfigured->available)->toBeFalse()
        ->and($unconfigured->code)->toBe(PlatformAvailability::NOT_CONFIGURED)
        ->and($unconfigured->reason)->toContain('Facebook (Meta) app');

    config(['social.platforms.facebook.client_secret' => 'fb-secret']);

    expect($this->ig->availability()->available)->toBeTrue()
        ->and(app(SocialPlatforms::class)->get('instagram'))->toBeInstanceOf(InstagramPlatform::class);
});

it('declares the media-only capabilities and limits from the config', function () {
    $caps = $this->ig->capabilities();

    expect($caps->publish)->toBeTrue()->and($caps->schedule)->toBeTrue()
        ->and($caps->images)->toBeTrue()->and($caps->video)->toBeTrue()
        ->and($caps->requiresMedia)->toBeTrue()->and($caps->requiresVideo)->toBeFalse()
        ->and($caps->maxText)->toBe(2200)->and($caps->maxMedia)->toBe(10)
        ->and($caps->maxVideoSeconds)->toBe(900)->and($caps->minVideoSeconds)->toBe(3)
        ->and($caps->maxVideoBytes)->toBe(300 * 1024 * 1024)
        ->and($caps->analytics)->toBeTrue()->and($caps->comments)->toBeFalse()->and($caps->insights)->toBeFalse()
        ->and($caps->toArray()['requiresMedia'])->toBeTrue()
        ->and(array_map(fn (PostType $type): string => $type->value, $this->ig->postTypes()))->toBe(['post', 'photos', 'video', 'reel'])
        ->and($this->ig->usesSteps(new PublishPayload('x')))->toBeTrue();
});

it('sends the person to the Facebook Login dialog with the Instagram scopes, the exact redirect URI and state', function () {
    $request = $this->ig->authorizationRequest('https://oyosend.test/social/oauth/callback/instagram', 'state-abc');
    parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);

    expect($request->url)->toStartWith('https://www.facebook.com/v23.0/dialog/oauth?')
        ->and($request->codeVerifier)->toBeNull()
        ->and($query['client_id'])->toBe('fb-id')
        ->and($query['redirect_uri'])->toBe('https://oyosend.test/social/oauth/callback/instagram')
        ->and($query['state'])->toBe('state-abc')
        ->and($query['response_type'])->toBe('code')
        ->and(explode(',', $query['scope']))->toContain('instagram_basic', 'instagram_content_publish', 'pages_show_list');
});

it('lists one profile per Instagram account linked to a granted Page, acting with that Page token', function () {
    igGraph([
        'POST oauth/access_token' => [[['access_token' => 'short-user']], [['access_token' => 'long-user']]],
        'GET me/accounts' => [[['data' => [
            ['id' => 'P1', 'name' => 'Brand Page', 'access_token' => 'page-1-token', 'instagram_business_account' => [
                'id' => '1784', 'username' => 'brand', 'name' => 'Brand', 'profile_picture_url' => 'https://cdn.example/p.jpg',
            ]],
            ['id' => 'P2', 'name' => 'Unlinked Page', 'access_token' => 'page-2-token'],
            ['id' => 'P3', 'name' => 'Shop Page', 'access_token' => 'page-3-token', 'instagram_business_account' => ['id' => '1785', 'username' => 'shop']],
        ]]]],
    ]);

    $profiles = $this->ig->completeAuthorization('code-1', 'https://oyosend.test/cb', null);

    expect($profiles)->toHaveCount(2)
        ->and($profiles[0]->externalId)->toBe('1784')
        ->and($profiles[0]->name)->toBe('Brand')
        ->and($profiles[0]->handle)->toBe('brand')
        ->and($profiles[0]->avatarUrl)->toBe('https://cdn.example/p.jpg')
        ->and($profiles[0]->tokens->accessToken)->toBe('page-1-token')
        ->and($profiles[0]->tokens->expiresAt)->toBeNull()
        ->and($profiles[1]->externalId)->toBe('1785')
        ->and($profiles[1]->name)->toBe('shop')
        ->and($profiles[1]->tokens->accessToken)->toBe('page-3-token');

    $exchange = igSent('POST', 'oauth/access_token');
    $pages = igSent('GET', 'me/accounts');
    expect($exchange)->toHaveCount(2)
        ->and($exchange[0]['code'])->toBe('code-1')
        ->and($exchange[1]['grant_type'])->toBe('fb_exchange_token')
        ->and($exchange[1]['fb_exchange_token'])->toBe('short-user')
        ->and($pages[0]->header('Authorization')[0])->toBe('Bearer long-user')
        ->and($pages[0]->url())->toContain('instagram_business_account');
});

it('refuses a connection whose Pages have no linked Instagram account, and cannot refresh', function () {
    igGraph([
        'POST oauth/access_token' => [[['access_token' => 'user']]],
        'GET me/accounts' => [[['data' => [['id' => 'P2', 'name' => 'Unlinked', 'access_token' => 'page-2-token']]]]],
    ]);

    expect(fn () => $this->ig->completeAuthorization('code', 'https://oyosend.test/cb', null))
        ->toThrow(ReauthorizationRequired::class, 'Instagram professional account')
        ->and(fn () => $this->ig->refresh('anything'))->toThrow(ReauthorizationRequired::class);
});

it('publishes one photo: container from a fresh public URL, status, media_publish, permalink', function () {
    igGraph([
        'GET IG1/content_publishing_limit' => $this->quota,
        'POST IG1/media' => [[['id' => 'C1']]],
        'GET C1' => [[['status_code' => 'FINISHED']]],
        'POST IG1/media_publish' => [[['id' => 'M1']]],
        'GET M1' => [[['permalink' => 'https://www.instagram.com/p/abc/']]],
    ]);

    $progress = ($this->advance)(new PublishPayload('Hello #brand', 'https://shop.example', [($this->file)('a.jpg')]));

    expect($progress->published?->externalId)->toBe('M1')
        ->and($progress->published?->url)->toBe('https://www.instagram.com/p/abc/')
        ->and($this->urls)->toBe(1);

    $container = igSent('POST', 'IG1/media')[0];
    expect($container['image_url'])->toContain('/social/public-media/a.jpg')
        ->and($container['caption'])->toBe("Hello #brand\n\nhttps://shop.example")
        ->and($container->data())->not->toHaveKey('media_type')
        ->and($container->url())->not->toContain(IG_TOKEN)
        ->and($container->header('Authorization')[0])->toBe('Bearer '.IG_TOKEN)
        ->and(igSent('POST', 'IG1/media_publish')[0]['creation_id'])->toBe('C1');
});

it('publishes a Reel, waiting for the container across runs without uploading it again', function () {
    igGraph([
        'GET IG1/content_publishing_limit' => $this->quota,
        'POST IG1/media' => [[['id' => 'C1']]],
        'GET C1' => [[['status_code' => 'IN_PROGRESS']], [['status_code' => 'IN_PROGRESS']], [['status_code' => 'FINISHED']]],
        'POST IG1/media_publish' => [[['id' => 'M1']]],
        'GET M1' => [[['permalink' => 'https://www.instagram.com/reel/xyz/']]],
    ]);
    $payload = new PublishPayload('Reel time', null, [($this->file)('clip.mp4', true)], postType: PostType::Reel);

    $first = ($this->advance)($payload);

    expect($first->published)->toBeNull()
        ->and($first->state)->toBe('processing')
        ->and($first->remoteId)->toBe('C1')
        ->and($first->externalId)->toBeNull()
        ->and($first->retryAfterSeconds)->toBe(45)
        ->and(json_encode($first->meta))->not->toContain('http');

    $second = ($this->advance)($payload, ($this->resume)($first));
    expect($second->published)->toBeNull()->and($second->remoteId)->toBe('C1');

    $third = ($this->advance)($payload, ($this->resume)($second));

    expect($third->published?->externalId)->toBe('M1')
        ->and($third->published?->url)->toBe('https://www.instagram.com/reel/xyz/')
        ->and($this->urls)->toBe(1)
        ->and(igSent('POST', 'IG1/media'))->toHaveCount(1)
        ->and(igSent('GET', 'IG1/content_publishing_limit'))->toHaveCount(1)
        ->and(igSent('POST', 'IG1/media_publish'))->toHaveCount(1);

    $container = igSent('POST', 'IG1/media')[0];
    expect($container['media_type'])->toBe('REELS')
        ->and($container['video_url'])->toContain('/social/public-media/clip.mp4')
        ->and($container['caption'])->toBe('Reel time')
        ->and($container->data())->not->toHaveKey('image_url');
});

it('publishes a carousel of photos and a video: children, wait for each, CAROUSEL container, publish', function () {
    igGraph([
        'GET IG1/content_publishing_limit' => $this->quota,
        'POST IG1/media' => [[['id' => 'C1']], [['id' => 'C2']], [['id' => 'C3']], [['id' => 'P1']]],
        'GET C1' => [[['status_code' => 'FINISHED']]],
        'GET C2' => [[['status_code' => 'FINISHED']]],
        'GET C3' => [[['status_code' => 'IN_PROGRESS']], [['status_code' => 'FINISHED']]],
        'GET P1' => [[['status_code' => 'FINISHED']]],
        'POST IG1/media_publish' => [[['id' => 'M9']]],
        'GET M9' => [[[]]],
    ]);
    $payload = new PublishPayload('Three things', null, [
        ($this->file)('a.jpg'), ($this->file)('b.jpg', false, 1080, 1350), ($this->file)('c.mp4', true),
    ], postType: PostType::Photos);

    $first = ($this->advance)($payload);

    expect($first->published)->toBeNull()
        ->and($first->state)->toBe('processing')
        ->and($first->meta['children'])->toBe(['C1', 'C2', 'C3'])
        ->and($first->meta['ready'])->toBe(2);

    $done = ($this->advance)($payload, ($this->resume)($first));

    expect($done->published?->externalId)->toBe('M9')
        ->and($done->published?->url)->toBeNull()
        ->and($this->urls)->toBe(3);

    $creates = igSent('POST', 'IG1/media');
    expect($creates)->toHaveCount(4)
        ->and($creates[0]['is_carousel_item'])->toBe('true')
        ->and($creates[0]['image_url'])->toContain('a.jpg')
        ->and($creates[0]->data())->not->toHaveKey('caption')
        ->and($creates[2]['media_type'])->toBe('VIDEO')
        ->and($creates[2]['video_url'])->toContain('c.mp4')
        ->and($creates[3]['media_type'])->toBe('CAROUSEL')
        ->and($creates[3]['children'])->toBe('C1,C2,C3')
        ->and($creates[3]['caption'])->toBe('Three things')
        ->and(igSent('GET', 'C1'))->toHaveCount(1)
        ->and(igSent('POST', 'IG1/media_publish')[0]['creation_id'])->toBe('P1');
});

it('resumes from the saved session one request per run once the run budget is spent', function () {
    igGraph([
        'GET IG1/content_publishing_limit' => $this->quota,
        'POST IG1/media' => [[['id' => 'C1']], [['id' => 'C2']], [['id' => 'P1']]],
        'GET C1' => [[['status_code' => 'FINISHED']]],
        'GET C2' => [[['status_code' => 'FINISHED']]],
        'GET P1' => [[['status_code' => 'FINISHED']]],
        'POST IG1/media_publish' => [[['id' => 'M1']]],
        'GET M1' => [[['permalink' => 'https://www.instagram.com/p/q/']]],
    ]);
    $payload = new PublishPayload('Two', null, [($this->file)('a.jpg'), ($this->file)('b.jpg')], postType: PostType::Photos);
    $session = new PublishSession;
    $runs = 0;

    do {
        $runs++;
        $progress = ($this->advance)($payload, $session, microtime(true) - 1);
        $session = ($this->resume)($progress);
    } while ($progress->published === null && $runs < 20);

    // quota, 2 children, 2 child statuses, carousel, its status, media_publish (+ the permalink in the same run).
    expect($progress->published?->externalId)->toBe('M1')
        ->and($runs)->toBe(8)
        ->and(igSent('POST', 'IG1/media'))->toHaveCount(3)
        ->and(igSent('POST', 'IG1/media_publish'))->toHaveCount(1)
        ->and($this->urls)->toBe(2);
});

it('fails a container Instagram could not process, without publishing and without leaking the media URL', function () {
    igGraph([
        'GET IG1/content_publishing_limit' => $this->quota,
        'POST IG1/media' => [[['id' => 'C1']]],
        'GET C1' => [[['status_code' => 'ERROR', 'status' => 'Error: Media download from https://ws.oyosend.test/social/public-media/a.jpg?signature='.IG_SIGNATURE.' failed']]],
    ]);

    try {
        ($this->advance)(new PublishPayload('x', null, [($this->file)('a.jpg')]));
        $this->fail('Expected a failure.');
    } catch (PublishFailed $failure) {
        expect($failure->errorCode)->toBe('media_processing_failed')
            ->and($failure->retryable)->toBeFalse()
            ->and($failure->outcomeUnknown)->toBeFalse()
            ->and($failure->getMessage())->toContain('Media download from [link] failed')
            ->and($failure->getMessage())->not->toContain(IG_SIGNATURE)
            ->and($failure->getMessage())->not->toContain('public-media');
    }

    expect(igSent('POST', 'IG1/media_publish'))->toBeEmpty();

    igGraph(['GET C1' => [[['status_code' => 'EXPIRED']]]]);
    expect(fn () => ($this->advance)(new PublishPayload('x', null, [($this->file)('a.jpg')]), new PublishSession('C1', 'processing', 0, ['quota' => true, 'container' => 'C1'])))
        ->toThrow(fn (PublishFailed $failure) => expect($failure->errorCode)->toBe('media_container_expired'));
});

it('treats a lost or failed media_publish as an unknown outcome and never repeats it once accepted', function (Closure $answer) {
    igGraph([
        'GET C1' => [[['status_code' => 'FINISHED']]],
        'POST IG1/media_publish' => [$answer],
    ]);
    $session = new PublishSession('C1', 'processing', 0, ['quota' => true, 'container' => 'C1']);

    try {
        ($this->advance)(new PublishPayload('x', null, [($this->file)('a.jpg')]), $session);
        $this->fail('Expected a failure.');
    } catch (PublishFailed $failure) {
        expect($failure->outcomeUnknown)->toBeTrue()
            ->and($failure->retryable)->toBeFalse()
            ->and($failure->getMessage())->not->toContain(IG_TOKEN);
    }

    expect(igSent('POST', 'IG1/media_publish'))->toHaveCount(1);

    // A session that already carries the published id only reads the permalink.
    igGraph(['GET M1' => [[['permalink' => 'https://www.instagram.com/p/z/']]]]);
    $progress = ($this->advance)(new PublishPayload('x', null, [($this->file)('a.jpg')]), new PublishSession('C1', 'processing', 0, [], 'M1'));

    expect($progress->published?->externalId)->toBe('M1')
        ->and(igSent('POST', 'IG1/media_publish'))->toBeEmpty()
        ->and(igSent('POST', 'IG1/media'))->toBeEmpty();
})->with([
    'server error' => [fn () => fn () => Http::response(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 500)],
    'timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
    'no id' => [fn () => fn () => Http::response(['success' => true])],
]);

it('retries throttles and a reached publishing quota, and asks to reconnect on a token error', function () {
    $session = new PublishSession('C1', 'processing', 0, ['quota' => true, 'container' => 'C1']);
    $payload = new PublishPayload('x', null, [($this->file)('a.jpg')]);

    igGraph([
        'GET C1' => [[['status_code' => 'FINISHED']]],
        'POST IG1/media_publish' => [[['error' => ['code' => 4, 'message' => 'Application request limit reached']], 400]],
    ]);
    expect(fn () => ($this->advance)($payload, $session))->toThrow(fn (PublishFailed $failure) => expect($failure->retryable)->toBeTrue()
        ->and($failure->errorCode)->toBe('rate_limited')->and($failure->outcomeUnknown)->toBeFalse());

    igGraph([
        'GET C1' => [[['status_code' => 'FINISHED']]],
        'POST IG1/media_publish' => [[['error' => ['code' => 9, 'error_subcode' => 2207042, 'message' => 'limit']], 400]],
    ]);
    expect(fn () => ($this->advance)($payload, $session))->toThrow(fn (PublishFailed $failure) => expect($failure->retryable)->toBeTrue()
        ->and($failure->retryAfterSeconds)->toBe(3600));

    igGraph(['GET IG1/content_publishing_limit' => [[['data' => [['quota_usage' => 50, 'config' => ['quota_total' => 50]]]]]]]);
    expect(fn () => ($this->advance)($payload))->toThrow(fn (PublishFailed $failure) => expect($failure->retryable)->toBeTrue()
        ->and($failure->errorCode)->toBe('rate_limited'));
    expect(igSent('POST', 'IG1/media'))->toBeEmpty();

    igGraph([
        'GET IG1/content_publishing_limit' => $this->quota,
        'POST IG1/media' => [[['error' => ['code' => 190, 'message' => 'Invalid OAuth access token '.IG_TOKEN]], 400]],
    ]);
    expect(fn () => ($this->advance)($payload))->toThrow(fn (PublishFailed $failure) => expect($failure->reauthorize)->toBeTrue()
        ->and($failure->errorCode)->toBe('reauthorization_required')
        ->and($failure->getMessage())->toContain('Instagram')
        ->and($failure->getMessage())->not->toContain(IG_TOKEN));

    // A container call that timed out published nothing: safe to try again.
    igGraph([
        'GET IG1/content_publishing_limit' => $this->quota,
        'POST IG1/media' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
    ]);
    expect(fn () => ($this->advance)($payload))->toThrow(fn (PublishFailed $failure) => expect($failure->retryable)->toBeTrue()
        ->and($failure->outcomeUnknown)->toBeFalse());
});

it('keeps a rejected container reason readable but free of links and tokens', function () {
    igGraph([
        'GET IG1/content_publishing_limit' => $this->quota,
        'POST IG1/media' => [[['error' => [
            'code' => 36003, 'message' => 'Invalid parameter',
            'error_user_msg' => 'The image at https://ws.oyosend.test/social/public-media/a.jpg?signature='.IG_SIGNATURE.' has an unsupported aspect ratio. EAAB'.str_repeat('x', 30),
        ]], 400]],
    ]);

    expect(fn () => ($this->advance)(new PublishPayload('x', null, [($this->file)('a.jpg')])))->toThrow(function (PublishFailed $failure) {
        expect($failure->retryable)->toBeFalse()
            ->and($failure->outcomeUnknown)->toBeFalse()
            ->and($failure->getMessage())->toContain('unsupported aspect ratio')
            ->and($failure->getMessage())->not->toContain(IG_SIGNATURE)
            ->and($failure->getMessage())->not->toContain('https://')
            ->and($failure->getMessage())->not->toContain('EAAB');
    });
});

it('refuses what Instagram cannot publish before any call', function () {
    igGraph([]);

    expect(fn () => ($this->advance)(new PublishPayload('text only')))
        ->toThrow(fn (PublishFailed $failure) => expect($failure->errorCode)->toBe('media_missing'))
        ->and(fn () => ($this->advance)(new PublishPayload('x', null, [($this->file)('a.jpg')], postType: PostType::Reel)))
        ->toThrow(fn (PublishFailed $failure) => expect($failure->errorCode)->toBe('unsupported_media'))
        ->and(fn () => ($this->advance)(new PublishPayload(str_repeat('a', 2195), 'https://shop.example', [($this->file)('a.jpg')])))
        ->toThrow(fn (PublishFailed $failure) => expect($failure->errorCode)->toBe('caption_too_long'))
        ->and(fn () => ($this->advance)(new PublishPayload('x', null, [new MediaFile('a.jpg', 'image/jpeg', false, null, fn () => 'b')])))
        ->toThrow(fn (PublishFailed $failure) => expect($failure->errorCode)->toBe('media_url_unavailable'));

    expect(igSent())->toBeEmpty();
});

it('reports Instagram media rules: JPEG only, aspect ratio, video type, hashtags and post types', function () {
    $photo = fn (string $mime = 'image/jpeg', ?int $w = 1080, ?int $h = 1080, int $size = 1000): MediaInfo => new MediaInfo(false, $mime, $size, $w, $h);
    $video = fn (string $mime = 'video/mp4'): MediaInfo => new MediaInfo(true, $mime, 1000, 1080, 1920, 10_000);
    $blocking = fn (array $items): array => array_values(array_map(fn (array $item): string => $item['message'], array_filter($items, fn (array $item): bool => $item['blocking'])));
    $warnings = fn (array $items): array => array_values(array_map(fn (array $item): string => $item['message'], array_filter($items, fn (array $item): bool => ! $item['blocking'])));

    expect($this->ig->mediaIssues([$photo(), $photo('image/jpeg', 1080, 1350), $photo('image/jpeg', 1910, 1000), $video(), $video('video/quicktime')], 'Fine #one #two'))->toBe([])
        ->and($blocking($this->ig->mediaIssues([$photo('image/png'), $photo('image/png')], '')))->toBe(['Instagram publishes JPEG photos only. Replace the other photos with JPEG files.'])
        ->and($blocking($this->ig->mediaIssues([$photo('image/jpeg', 1080, 1920)], ''))[0])->toContain('taller than Instagram allows')
        ->and($blocking($this->ig->mediaIssues([$photo('image/jpeg', 2000, 1000)], ''))[0])->toContain('between 0.8 and 1.91')
        ->and($blocking($this->ig->mediaIssues([$photo(size: 9 * 1024 * 1024)], ''))[0])->toContain('8 MB')
        ->and($warnings($this->ig->mediaIssues([$photo('image/jpeg', null, null)], ''))[0])->toContain('could not be read')
        ->and($blocking($this->ig->mediaIssues([$video('video/webm')], '')))->toBe(['Instagram publishes MP4 and MOV videos only.'])
        ->and($this->ig->mediaIssues([$photo()], implode(' ', array_map(fn (int $i): string => '#tag'.$i, range(1, 30)))))->toBe([])
        ->and($blocking($this->ig->mediaIssues([$photo()], implode(' ', array_map(fn (int $i): string => '#tag'.$i, range(1, 31))))))
        ->toBe(['Instagram allows at most 30 hashtags in a caption.']);

    expect($this->ig->checkPost(PostType::Post, [$photo()]))->toBe([])
        ->and($blocking($this->ig->checkPost(PostType::Post, [$photo(), $photo()])))->not->toBeEmpty()
        ->and($blocking($this->ig->checkPost(PostType::Post, [$video()])))->not->toBeEmpty()
        ->and($this->ig->checkPost(PostType::Photos, [$photo(), $video()]))->toBe([])
        ->and($blocking($this->ig->checkPost(PostType::Photos, array_fill(0, 11, $photo())))[0])->toContain('at most 10')
        ->and($this->ig->checkPost(PostType::Reel, [$video()]))->toBe([])
        ->and($this->ig->checkPost(PostType::Video, [$video()]))->toBe([])
        ->and($blocking($this->ig->checkPost(PostType::Reel, [$video(), $photo()])))->not->toBeEmpty();

    expect($this->ig->mediaBadges($photo()))->toBe([['code' => 'photo_ok', 'tone' => 'ok']])
        ->and($this->ig->mediaBadges($photo('image/png')))->toBe([['code' => 'unsupported', 'tone' => 'muted']])
        ->and($this->ig->mediaBadges($video()))->toBe([['code' => 'reel_ready', 'tone' => 'ok']])
        ->and($this->ig->mediaBadges(new MediaInfo(true, 'video/mp4', 1000, 1080, 1920, 1000)))->toBe([['code' => 'reel_too_short', 'tone' => 'danger']])
        ->and($this->ig->mediaBadges(new MediaInfo(true, 'video/mp4', 1000)))->toBe([['code' => 'reel_check', 'tone' => 'warn']]);
});

it('reads likes and comments of a published item', function () {
    igGraph(['GET M1' => [[['like_count' => 12, 'comments_count' => 3, 'id' => 'M1']]]]);

    $metrics = $this->ig->metrics($this->account, 'M1');

    expect($metrics?->likes)->toBe(12)->and($metrics?->comments)->toBe(3)->and($metrics?->impressions)->toBeNull()
        ->and(igSent('GET', 'M1')[0]->url())->toContain('fields=like_count%2Ccomments_count');
});
