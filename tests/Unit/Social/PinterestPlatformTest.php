<?php

declare(strict_types=1);

use App\Modules\Social\Actions\PostContentRules;
use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Adapters\PinterestPlatform;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\Exceptions\ReauthorizationRequired;
use App\Modules\Social\Platforms\MediaFile;
use App\Modules\Social\Platforms\MediaInfo;
use App\Modules\Social\Platforms\PlatformAvailability;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\PublishSession;
use App\Modules\Social\Platforms\SocialPlatforms;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Pinterest API v5 adapter (ADR 0033): OAuth with HTTP Basic, one connection per board, image / carousel /
 * video Pins, error mapping and analytics. Every call is faked; nothing here has reached Pinterest.
 */

function pinterestHttp(): void
{
    Http::swap(new Factory);
}

function pinterestImage(string $name = 'a.png', string $mime = 'image/png', string $bytes = 'png-bytes', ?string $alt = null): MediaFile
{
    return new MediaFile($name, $mime, false, $alt, fn (): string => $bytes, strlen($bytes),
        publicUrl: fn (): string => throw new LogicException('Pinterest must not ask for a public URL.'));
}

function pinterestVideo(string $bytes = 'video-bytes-0123456789'): MediaFile
{
    return new MediaFile('clip.mp4', 'video/mp4', true, null, fn (): string => $bytes, strlen($bytes),
        ranger: fn (int $offset, int $length): string => substr($bytes, $offset, $length));
}

beforeEach(function () {
    pinterestHttp();
    config([
        'social.platforms.pinterest.client_id' => 'pin-app-id',
        'social.platforms.pinterest.client_secret' => 'pin-app-secret',
        'social.publishing.processing_poll_seconds' => 30,
    ]);
    $this->pinterest = app(PinterestPlatform::class);
    $this->credentials = new AccountCredentials('9988776655', 'live-pin-token');
});

it('is the registered pinterest adapter and says honestly when its app is not configured', function () {
    expect(app(SocialPlatforms::class)->get('pinterest'))->toBeInstanceOf(PinterestPlatform::class)
        ->and($this->pinterest->availability()->available)->toBeTrue();

    config(['social.platforms.pinterest.client_secret' => null]);
    $availability = app(PinterestPlatform::class)->availability();

    expect($availability->available)->toBeFalse()
        ->and($availability->code)->toBe(PlatformAvailability::NOT_CONFIGURED)
        ->and($availability->reason)->toContain('Pinterest');
});

it('declares media-only publishing, the carousel size and the configured video bounds', function () {
    $capabilities = $this->pinterest->capabilities();

    expect($capabilities->publish)->toBeTrue()
        ->and($capabilities->images)->toBeTrue()
        ->and($capabilities->video)->toBeTrue()
        ->and($capabilities->analytics)->toBeTrue()
        ->and($capabilities->comments)->toBeFalse()
        ->and($capabilities->requiresMedia)->toBeTrue()
        ->and($capabilities->requiresVideo)->toBeFalse()
        ->and($capabilities->maxText)->toBe(500)
        ->and($capabilities->maxMedia)->toBe(5)
        ->and($capabilities->minVideoSeconds)->toBe(4)
        ->and($capabilities->maxVideoSeconds)->toBe(900)
        ->and($capabilities->maxVideoBytes)->toBe(2 * 1024 * 1024 * 1024);
});

it('sends the person to Pinterest with the exact redirect URI, state and comma-separated scopes', function () {
    $request = $this->pinterest->authorizationRequest('https://oyosend.test/social/oauth/callback/pinterest', 'the.state');
    $parts = parse_url($request->url);
    parse_str($parts['query'] ?? '', $query);

    expect($parts['scheme'].'://'.$parts['host'].$parts['path'])->toBe('https://www.pinterest.com/oauth/')
        ->and($query)->toMatchArray([
            'client_id' => 'pin-app-id',
            'redirect_uri' => 'https://oyosend.test/social/oauth/callback/pinterest',
            'response_type' => 'code',
            'scope' => 'boards:read,pins:read,pins:write,user_accounts:read',
            'state' => 'the.state',
        ])
        ->and($request->codeVerifier)->toBeNull();
});

it('exchanges the code with HTTP Basic and returns one connection per board across bookmark pages', function () {
    Http::fake([
        'api.pinterest.com/v5/oauth/token' => Http::response([
            'access_token' => 'pina_access', 'refresh_token' => 'pinr_refresh', 'expires_in' => 2592000,
            'refresh_token_expires_in' => 5184000, 'scope' => 'boards:read,pins:read,pins:write,user_accounts:read',
            'token_type' => 'bearer',
        ]),
        'api.pinterest.com/v5/user_account' => Http::response(['username' => 'jane', 'profile_image' => 'https://i.pinimg.com/jane.jpg']),
        'api.pinterest.com/v5/boards?page_size=100' => Http::response([
            'items' => [['id' => '111', 'name' => 'Recipes'], ['id' => '222', 'name' => 'Travel']],
            'bookmark' => 'page-2',
        ]),
        'api.pinterest.com/v5/boards?page_size=100&bookmark=page-2' => Http::response([
            'items' => [['id' => '333', 'name' => 'Gardens'], ['name' => 'no id, skipped']],
            'bookmark' => null,
        ]),
    ]);

    $profiles = $this->pinterest->completeAuthorization('the-code', 'https://oyosend.test/social/oauth/callback/pinterest', null);

    expect($profiles)->toHaveCount(3)
        ->and(array_map(fn ($p) => $p->externalId, $profiles))->toBe(['111', '222', '333'])
        ->and($profiles[0]->name)->toBe('jane · Recipes')
        ->and($profiles[2]->name)->toBe('jane · Gardens')
        ->and($profiles[0]->type)->toBe('board')
        ->and($profiles[0]->avatarUrl)->toBe('https://i.pinimg.com/jane.jpg')
        ->and($profiles[1]->tokens->accessToken)->toBe('pina_access')
        ->and($profiles[2]->tokens->refreshToken)->toBe('pinr_refresh')
        ->and($profiles[0]->tokens->expiresAt)->not->toBeNull()
        ->and($profiles[0]->tokens->refreshExpiresAt)->not->toBeNull()
        ->and($profiles[0]->tokens->scopes)->toBe(['boards:read', 'pins:read', 'pins:write', 'user_accounts:read']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pinterest.com/v5/oauth/token'
        && $request->method() === 'POST'
        && $request->header('Authorization') === ['Basic '.base64_encode('pin-app-id:pin-app-secret')]
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'the-code'
        && $request['redirect_uri'] === 'https://oyosend.test/social/oauth/callback/pinterest'
        && ! isset($request['client_secret']));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'bookmark=page-2')
        && $request->header('Authorization') === ['Bearer pina_access']);
});

it('refuses to connect an account without boards and tells the person to create one', function () {
    Http::fake([
        'api.pinterest.com/v5/oauth/token' => Http::response(['access_token' => 'pina_access', 'expires_in' => 3600]),
        'api.pinterest.com/v5/user_account' => Http::response(['username' => 'jane']),
        'api.pinterest.com/v5/boards*' => Http::response(['items' => [], 'bookmark' => null]),
    ]);

    expect(fn () => $this->pinterest->completeAuthorization('code', 'https://ws.test/cb', null))
        ->toThrow(ReauthorizationRequired::class, 'Create a board on Pinterest first');
});

it('reports a refused code as a reauthorization without echoing anything secret', function () {
    Http::fake(['api.pinterest.com/v5/oauth/token' => Http::response(['code' => 1, 'message' => 'Invalid authorization code'], 400)]);

    try {
        $this->pinterest->completeAuthorization('bad-code', 'https://ws.test/cb', null);
        $this->fail('Expected a reauthorization.');
    } catch (ReauthorizationRequired $exception) {
        expect($exception->getMessage())->toBe('Invalid authorization code')
            ->not->toContain('pin-app-secret');
    }
});

it('refreshes with HTTP Basic, and tells a refused refresh token from an outage', function () {
    Http::fake([
        'api.pinterest.com/v5/oauth/token' => Http::sequence()
            ->push(['access_token' => 'pina_new', 'refresh_token' => 'pinr_new', 'expires_in' => 2592000])
            ->push(['code' => 1, 'message' => 'Refresh token expired'], 400)
            ->push(['message' => 'Service unavailable'], 503),
    ]);

    $tokens = $this->pinterest->refresh('pinr_old');

    expect($tokens->accessToken)->toBe('pina_new')->and($tokens->refreshToken)->toBe('pinr_new');
    Http::assertSent(fn (Request $request) => $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'pinr_old'
        && $request->header('Authorization') === ['Basic '.base64_encode('pin-app-id:pin-app-secret')]);

    expect(fn () => $this->pinterest->refresh('pinr_old'))->toThrow(ReauthorizationRequired::class);

    try {
        $this->pinterest->refresh('pinr_old');
        $this->fail('Expected a retryable failure.');
    } catch (PublishFailed $failure) {
        expect($failure->retryable)->toBeTrue()->and($failure->getMessage())->not->toContain('pinr_old');
    }
});

it('creates an image Pin on the board with title, description, link, alt text and base64 media', function () {
    Http::fake(['api.pinterest.com/v5/pins' => Http::response(['id' => '813744226420795884'], 201)]);

    $published = $this->pinterest->publish($this->credentials, new PublishPayload(
        "  Summer salad  \nFresh, quick and green. #salad",
        'https://shop.example/salad',
        [pinterestImage('salad.jpg', 'image/jpeg', 'jpeg-bytes', 'A bowl of salad')],
    ));

    expect($published->externalId)->toBe('813744226420795884')
        ->and($published->url)->toBe('https://www.pinterest.com/pin/813744226420795884/');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pinterest.com/v5/pins'
        && $request->header('Authorization') === ['Bearer live-pin-token']
        && $request['board_id'] === '9988776655'
        && $request['title'] === 'Summer salad'
        && $request['description'] === "Summer salad  \nFresh, quick and green. #salad"
        && $request['link'] === 'https://shop.example/salad'
        && $request['alt_text'] === 'A bowl of salad'
        && $request['media_source'] === ['source_type' => 'image_base64', 'content_type' => 'image/jpeg', 'data' => base64_encode('jpeg-bytes')]);
});

it('creates a carousel Pin from several images and leaves out what the post does not have', function () {
    Http::fake(['api.pinterest.com/v5/pins' => Http::response(['id' => '42'], 201)]);

    $this->pinterest->publish($this->credentials, new PublishPayload('', null, [
        pinterestImage('1.png', 'image/png', 'one'), pinterestImage('2.jpg', 'image/jpeg', 'two'), pinterestImage('3.png', 'image/png', 'three'),
    ]));

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $data['media_source']['source_type'] === 'multiple_image_base64'
            && $data['media_source']['items'] === [
                ['content_type' => 'image/png', 'data' => base64_encode('one')],
                ['content_type' => 'image/jpeg', 'data' => base64_encode('two')],
                ['content_type' => 'image/png', 'data' => base64_encode('three')],
            ]
            && ! array_key_exists('title', $data)
            && ! array_key_exists('description', $data)
            && ! array_key_exists('link', $data)
            && ! array_key_exists('alt_text', $data);
    });
});

it('shortens a first line longer than the title limit', function () {
    Http::fake(['api.pinterest.com/v5/pins' => Http::response(['id' => '7'], 201)]);

    $this->pinterest->publish($this->credentials, new PublishPayload(str_repeat('a', 130)."\nBody", null, [pinterestImage()]));

    Http::assertSent(fn (Request $request) => $request['title'] === str_repeat('a', 100));
});

it('refuses a text-only post and an unsupported image type before calling Pinterest', function () {
    Http::fake();

    expect(fn () => $this->pinterest->publish($this->credentials, new PublishPayload('Only words')))
        ->toThrow(PublishFailed::class, 'does not publish text on its own');
    expect(fn () => $this->pinterest->publish($this->credentials, new PublishPayload('x', null, [pinterestImage('a.gif', 'image/gif')])))
        ->toThrow(PublishFailed::class, 'JPEG and PNG');

    Http::assertNothingSent();
});

it('maps rate limits, expired tokens, missing boards and ambiguous answers', function () {
    $payload = new PublishPayload('Pin', null, [pinterestImage()]);
    $attempt = function () use ($payload): PublishFailed {
        try {
            $this->pinterest->publish($this->credentials, $payload);
        } catch (PublishFailed $failure) {
            return $failure;
        }

        throw new RuntimeException('Expected a failure.');
    };

    Http::fake(['api.pinterest.com/v5/pins' => Http::sequence()
        ->push(['code' => 8, 'message' => 'Rate limited'], 429, ['Retry-After' => '120'])
        ->push(['code' => 2, 'message' => 'Authentication failed'], 401)
        ->push(['code' => 3, 'message' => 'Board not found'], 404)
        ->push(['message' => 'Internal error'], 500)
        ->push(['no' => 'id'], 201)
        ->push(['code' => 1, 'message' => 'Invalid parameters'], 400),
    ]);

    $limited = $attempt();
    expect($limited->retryable)->toBeTrue()->and($limited->retryAfterSeconds)->toBe(120)->and($limited->outcomeUnknown)->toBeFalse();

    $expired = $attempt();
    expect($expired->reauthorize)->toBeTrue()->and($expired->retryable)->toBeFalse();

    $missing = $attempt();
    expect($missing->retryable)->toBeFalse()->and($missing->outcomeUnknown)->toBeFalse()
        ->and($missing->getMessage())->toContain('board no longer exists');

    $server = $attempt();
    expect($server->outcomeUnknown)->toBeTrue()->and($server->retryable)->toBeFalse();

    $noId = $attempt();
    expect($noId->outcomeUnknown)->toBeTrue()->and($noId->errorCode)->toBe('missing_post_id');

    $invalid = $attempt();
    expect($invalid->getMessage())->toBe('Invalid parameters')->and($invalid->errorCode)->toBe('pinterest_400');

    foreach ([$limited, $expired, $missing, $server, $noId, $invalid] as $failure) {
        expect($failure->getMessage())->not->toContain('live-pin-token');
    }
});

it('treats a timeout on the Pin creation as an unknown outcome and a refused connection as retryable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 40000 milliseconds'));
    $payload = new PublishPayload('Pin', null, [pinterestImage()]);

    try {
        $this->pinterest->publish($this->credentials, $payload);
        $this->fail('Expected a failure.');
    } catch (PublishFailed $failure) {
        expect($failure->outcomeUnknown)->toBeTrue()->and($failure->retryable)->toBeFalse();
    }

    pinterestHttp();
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to api.pinterest.com'));

    try {
        app(PinterestPlatform::class)->publish($this->credentials, $payload);
        $this->fail('Expected a failure.');
    } catch (PublishFailed $failure) {
        expect($failure->retryable)->toBeTrue()->and($failure->outcomeUnknown)->toBeFalse();
    }
});

it('uses steps only for a video Pin', function () {
    expect($this->pinterest->usesSteps(new PublishPayload('x', null, [pinterestImage()])))->toBeFalse()
        ->and($this->pinterest->usesSteps(new PublishPayload('x', null, [pinterestVideo()])))->toBeTrue()
        ->and($this->pinterest->usesSteps(new PublishPayload('x', null, [pinterestImage(), pinterestVideo()])))->toBeTrue();
});

it('registers, uploads, waits for and then pins a video with the post photo as its cover', function () {
    Http::fake([
        'api.pinterest.com/v5/media' => Http::response([
            'media_id' => '12345', 'media_type' => 'video',
            'upload_url' => 'https://pinterest-media-upload.s3-accelerate.amazonaws.com/',
            'upload_parameters' => ['key' => 'uploads/abc', 'policy' => 'signed-policy', 'x-amz-signature' => 'sig', 'Content-Type' => 'multipart/form-data'],
        ], 201),
        'pinterest-media-upload.s3-accelerate.amazonaws.com/*' => Http::response('', 204),
        'api.pinterest.com/v5/media/12345' => Http::sequence()
            ->push(['media_id' => '12345', 'status' => 'processing'])
            ->push(['media_id' => '12345', 'status' => 'succeeded']),
        'api.pinterest.com/v5/pins' => Http::response(['id' => '555'], 201),
    ]);
    $payload = new PublishPayload("Video title\nMore words", 'https://shop.example', [pinterestVideo(), pinterestImage('cover.png', 'image/png', 'cover-bytes')]);
    $deadline = microtime(true) + 30;

    $first = $this->pinterest->advance($this->credentials, $payload, new PublishSession, $deadline);

    expect($first->state)->toBe('processing')->and($first->remoteId)->toBe('12345')
        ->and($first->externalId)->toBeNull()->and($first->published)->toBeNull()
        ->and($first->retryAfterSeconds)->toBe(30)
        ->and(json_encode($first->meta))->not->toContain('signed-policy');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pinterest.com/v5/media'
        && $request['media_type'] === 'video' && $request->header('Authorization') === ['Bearer live-pin-token']);
    Http::assertSent(function (Request $request) {
        if (! str_starts_with($request->url(), 'https://pinterest-media-upload.s3-accelerate.amazonaws.com')) {
            return false;
        }

        $names = array_map(fn (array $part) => $part['name'], $request->data());

        return $request->isMultipart()
            && $names === ['key', 'policy', 'x-amz-signature', 'Content-Type', 'file']
            && $request->data()[4]['filename'] === 'clip.mp4'
            && ! $request->hasHeader('Authorization');
    });

    $session = new PublishSession($first->remoteId, $first->state, $first->offset, $first->meta);
    $waiting = $this->pinterest->advance($this->credentials, $payload, $session, $deadline);

    expect($waiting->state)->toBe('processing')->and($waiting->published)->toBeNull();
    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://api.pinterest.com/v5/pins');

    $done = $this->pinterest->advance($this->credentials, $payload, $session, $deadline);

    expect($done->published?->externalId)->toBe('555')
        ->and($done->published?->url)->toBe('https://www.pinterest.com/pin/555/');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pinterest.com/v5/pins'
        && $request['board_id'] === '9988776655'
        && $request['title'] === 'Video title'
        && $request['link'] === 'https://shop.example'
        && $request['media_source'] === [
            'source_type' => 'video_id', 'media_id' => '12345',
            'cover_image_content_type' => 'image/png', 'cover_image_data' => base64_encode('cover-bytes'),
        ]);
    Http::assertSentCount(5);
});

it('uses a frame of the video as the cover when the post has no photo', function () {
    Http::fake([
        'api.pinterest.com/v5/media/777' => Http::response(['media_id' => '777', 'status' => 'succeeded']),
        'api.pinterest.com/v5/pins' => Http::response(['id' => '888'], 201),
    ]);

    $done = $this->pinterest->advance($this->credentials, new PublishPayload('Clip', null, [pinterestVideo()]),
        new PublishSession('777', 'processing'), microtime(true) + 30);

    expect($done->published?->externalId)->toBe('888');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pinterest.com/v5/pins'
        && $request['media_source'] === ['source_type' => 'video_id', 'media_id' => '777', 'cover_image_key_frame_time' => 1]);
});

it('never creates the video Pin twice once Pinterest accepted it', function () {
    Http::fake();

    $progress = $this->pinterest->advance($this->credentials, new PublishPayload('Clip', null, [pinterestVideo()]),
        new PublishSession('777', 'processing', externalId: '888'), microtime(true) + 30);

    expect($progress->published?->externalId)->toBe('888');
    Http::assertNothingSent();
});

it('retries a refused upload from a fresh registration and fails a video Pinterest could not process', function () {
    Http::fake([
        'api.pinterest.com/v5/media' => Http::response([
            'media_id' => '1', 'upload_url' => 'https://pinterest-media-upload.s3-accelerate.amazonaws.com/', 'upload_parameters' => ['key' => 'k'],
        ], 201),
        'pinterest-media-upload.s3-accelerate.amazonaws.com/*' => Http::response('<Error><Code>AccessDenied</Code></Error>', 403),
        'api.pinterest.com/v5/media/2' => Http::response(['media_id' => '2', 'status' => 'failed']),
    ]);
    $payload = new PublishPayload('Clip', null, [pinterestVideo()]);

    try {
        $this->pinterest->advance($this->credentials, $payload, new PublishSession, microtime(true) + 30);
        $this->fail('Expected a failure.');
    } catch (PublishFailed $failure) {
        // The storage host's 403 says nothing about the Pinterest account.
        expect($failure->retryable)->toBeTrue()->and($failure->reauthorize)->toBeFalse()->and($failure->errorCode)->toBe('upload_failed');
    }

    try {
        $this->pinterest->advance($this->credentials, $payload, new PublishSession('2', 'processing'), microtime(true) + 30);
        $this->fail('Expected a failure.');
    } catch (PublishFailed $failure) {
        expect($failure->retryable)->toBeFalse()->and($failure->outcomeUnknown)->toBeFalse()
            ->and($failure->errorCode)->toBe('video_processing_failed');
    }
});

it('checks Pinterest media rules and refuses a text-only post through the generic requirements', function () {
    $image = new MediaInfo(false, 'image/png', 1000, 800, 600);
    $gif = new MediaInfo(false, 'image/gif', 1000, 800, 600);
    $huge = new MediaInfo(false, 'image/jpeg', 30 * 1024 * 1024, 800, 600);
    $video = new MediaInfo(true, 'video/mp4', 5000, 1080, 1920, 10_000);
    $blocking = fn (array $media, string $text = 'Title'): array => array_values(array_map(
        fn (array $item) => $item['message'],
        array_filter($this->pinterest->mediaIssues($media, $text), fn (array $item) => $item['blocking']),
    ));

    expect($blocking([$image]))->toBe([])
        ->and($blocking([$video]))->toBe([])
        ->and($blocking([$video, $image]))->toBe([])
        ->and($blocking([$gif])[0])->toContain('JPEG and PNG')
        ->and($blocking([$huge])[0])->toContain('larger than Pinterest accepts (20 MB)')
        ->and($blocking([$video, $video])[0])->toContain('one video')
        ->and($blocking([$video, $image, $image])[0])->toContain('used as its cover');

    $warnings = $this->pinterest->mediaIssues([$image], str_repeat('t', 101)."\nrest");
    expect($warnings)->toHaveCount(1)->and($warnings[0]['blocking'])->toBeFalse()->and($warnings[0]['message'])->toContain('100 characters');

    $requirements = app(PostContentRules::class)->requirements($this->pinterest->capabilities(), []);
    expect($requirements)->toHaveCount(1)->and($requirements[0]['blocking'])->toBeTrue()
        ->and($requirements[0]['message'])->toContain('does not publish text on its own');
});

it('reads Pin analytics into the shared metrics snapshot', function () {
    Http::fake(['api.pinterest.com/v5/pins/555/analytics*' => Http::response([
        'all' => [
            'lifetime_metrics' => ['TOTAL_COMMENTS' => 4, 'TOTAL_REACTIONS' => 9],
            'summary_metrics' => ['IMPRESSION' => 240, 'SAVE' => 20, 'PIN_CLICK' => 37, 'OUTBOUND_CLICK' => 20],
            'daily_metrics' => [],
        ],
    ])]);

    $snapshot = $this->pinterest->metrics($this->credentials, '555');

    expect($snapshot?->impressions)->toBe(240)
        ->and($snapshot?->likes)->toBe(9)
        ->and($snapshot?->comments)->toBe(4)
        ->and($snapshot?->shares)->toBe(20)
        ->and($snapshot?->clicks)->toBe(57)
        ->and($snapshot?->reach)->toBeNull();

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['metric_types'] === 'IMPRESSION,SAVE,PIN_CLICK,OUTBOUND_CLICK,TOTAL_COMMENTS,TOTAL_REACTIONS'
            && $query['end_date'] === now('UTC')->toDateString()
            && $query['start_date'] === now('UTC')->subDays(89)->toDateString()
            && $request->header('Authorization') === ['Bearer live-pin-token'];
    });
});

it('returns no metrics when Pinterest reports nothing and maps an expired token', function () {
    Http::fake(['api.pinterest.com/v5/pins/*' => Http::sequence()
        ->push(['all' => ['summary_metrics' => [], 'lifetime_metrics' => []]])
        ->push(['code' => 2, 'message' => 'Authentication failed'], 401),
    ]);

    expect($this->pinterest->metrics($this->credentials, '1'))->toBeNull();
    expect(fn () => $this->pinterest->metrics($this->credentials, '1'))->toThrow(PublishFailed::class);
});
