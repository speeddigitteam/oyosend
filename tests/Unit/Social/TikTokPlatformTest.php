<?php

declare(strict_types=1);

use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Adapters\TikTokPlatform;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\Exceptions\ReauthorizationRequired;
use App\Modules\Social\Platforms\MediaFile;
use App\Modules\Social\Platforms\MediaInfo;
use App\Modules\Social\Platforms\PlatformAvailability;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\PublishProgress;
use App\Modules\Social\Platforms\PublishSession;
use App\Modules\Social\Platforms\SocialPlatforms;
use App\Modules\Social\Platforms\TikTok\TikTokChunkPlan;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const TIKTOK_UPLOAD_URL = 'https://open-upload.tiktokapis.com/video/?upload_id=6789&upload_token=SECRET-UPLOAD-TOKEN';

/**
 * A stateful stand-in for TikTok's documented Content Posting flow: creator info, init, chunk PUTs
 * (206, then 201 for the chunk that completes the file) and status fetch. One-shot failures per step.
 */
function tikTokUnitFake(): object
{
    Http::swap(new Factory);

    $fake = new class
    {
        /** @var list<array{step: string, url: string, method: string, body: array<string, mixed>, headers: array<string, string>, bytes: int}> */
        public array $log = [];

        /** @var array<string, list<PromiseInterface|Closure>> */
        public array $failures = [];

        /** @var array<string, mixed> */
        public array $creator = [
            'creator_username' => 'brand.creator',
            'creator_nickname' => 'Brand',
            'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'SELF_ONLY'],
            'comment_disabled' => false,
            'duet_disabled' => true,
            'stitch_disabled' => false,
            'max_video_post_duration_sec' => 600,
        ];

        /** @var list<array<string, mixed>> */
        public array $statuses = [['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => [7350000000000000001]]];

        public function install(): void
        {
            Http::fake(fn (Request $request) => $this->respond($request));
        }

        public function failNext(string $step, PromiseInterface|Closure $response): void
        {
            $this->failures[$step][] = $response;
        }

        /** @return list<array{step: string, url: string, method: string, body: array<string, mixed>, headers: array<string, string>, bytes: int}> */
        public function calls(string $step): array
        {
            return array_values(array_filter($this->log, fn (array $call): bool => $call['step'] === $step));
        }

        public static function ok(array $data): PromiseInterface
        {
            return Http::response(['data' => $data, 'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'log-1']]);
        }

        public static function error(string $code, int $status = 400, string $message = 'Error', array $headers = []): PromiseInterface
        {
            return Http::response(['data' => new stdClass, 'error' => ['code' => $code, 'message' => $message, 'log_id' => 'log-1']], $status, $headers);
        }

        private function respond(Request $request): mixed
        {
            $url = $request->url();
            $step = match (true) {
                str_starts_with($url, 'https://open-upload.tiktokapis.com/') => 'chunk',
                str_contains($url, '/v2/oauth/token/') => 'token',
                str_contains($url, '/v2/user/info/') => 'user',
                str_contains($url, '/creator_info/query/') => 'creator',
                str_contains($url, '/video/init/') => 'init',
                str_contains($url, '/status/fetch/') => 'status',
                default => 'other',
            };
            $range = $request->header('Content-Range')[0] ?? '';
            $this->log[] = [
                'step' => $step,
                'url' => $url,
                'method' => $request->method(),
                'body' => $step === 'chunk' ? [] : $request->data(),
                'headers' => array_map(fn (array $values): string => (string) ($values[0] ?? ''), $request->headers()),
                'bytes' => $step === 'chunk' ? strlen($request->body()) : 0,
            ];

            $injected = empty($this->failures[$step]) ? null : array_shift($this->failures[$step]);

            if ($injected instanceof Closure) {
                return $injected();
            }

            if ($injected !== null) {
                return $injected;
            }

            return match ($step) {
                'creator' => self::ok($this->creator),
                'init' => self::ok(['publish_id' => 'v_pub_file~v2-1.42', 'upload_url' => TIKTOK_UPLOAD_URL]),
                'chunk' => preg_match('~^bytes (\d+)-(\d+)/(\d+)$~', $range, $m) === 1 && (int) $m[2] === (int) $m[3] - 1
                    ? Http::response('', 201)
                    : Http::response('', 206),
                'status' => self::ok(array_shift($this->statuses) ?? ['status' => 'PROCESSING_DOWNLOAD']),
                default => self::error('invalid_params', 400, 'Unsupported fake call'),
            };
        }
    };
    $fake->install();
    Http::preventStrayRequests();

    return $fake;
}

function tikTokVideo(int $size, ?int $durationMs = 30_000, string $mime = 'video/mp4'): MediaFile
{
    $bytes = str_repeat('v', $size);

    return new MediaFile('clip.mp4', $mime, true, null, fn (): string => $bytes, sizeBytes: $size,
        width: 1080, height: 1920, durationMs: $durationMs,
        ranger: fn (int $offset, int $length): string => substr($bytes, $offset, $length));
}

/** Resolved after each fake, so it uses the faked HTTP client. */
function tikTokAdapter(): TikTokPlatform
{
    return app(TikTokPlatform::class);
}

/** Feed a progress back as the next run's session, the way the publisher stores it. */
function tikTokSession(PublishProgress $progress): PublishSession
{
    return new PublishSession($progress->remoteId, $progress->state, $progress->offset, $progress->meta, $progress->externalId);
}

function tikTokFailure(Closure $call): PublishFailed
{
    try {
        $call();
    } catch (PublishFailed $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a PublishFailed.');
}

beforeEach(function () {
    config([
        'social.platforms.tiktok.client_id' => 'tt-client-key',
        'social.platforms.tiktok.client_secret' => 'tt-secret',
        'social.platforms.tiktok.limits.chunk_bytes' => TikTokChunkPlan::MIN_CHUNK,
        'social.publishing.processing_poll_seconds' => 45,
        'social.publishing.step_failures_max' => 3,
    ]);
    Cache::flush();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    $this->account = new AccountCredentials('open-1', 'live-access-token');
    $this->past = microtime(true) - 1;
    $this->later = microtime(true) + 600;
});

it('replaces the placeholder and reports not configured until the client key and secret are set', function () {
    expect(app(SocialPlatforms::class)->get('tiktok'))->toBeInstanceOf(TikTokPlatform::class)
        ->and(tikTokAdapter()->availability()->available)->toBeTrue();

    config(['social.platforms.tiktok.client_secret' => null]);
    $availability = tikTokAdapter()->availability();

    expect($availability->available)->toBeFalse()
        ->and($availability->code)->toBe(PlatformAvailability::NOT_CONFIGURED)
        ->and($availability->reason)->toContain('TikTok');
});

it('declares a video-only platform with the configured limits', function () {
    $caps = tikTokAdapter()->capabilities();

    expect($caps->publish)->toBeTrue()->and($caps->schedule)->toBeTrue()
        ->and($caps->video)->toBeTrue()->and($caps->images)->toBeFalse()
        ->and($caps->analytics)->toBeFalse()->and($caps->comments)->toBeFalse()
        ->and($caps->requiresVideo)->toBeTrue()->and($caps->toArray()['requiresMedia'])->toBeTrue()
        ->and($caps->maxMedia)->toBe(1)->and($caps->maxText)->toBe(2200)
        ->and($caps->maxVideoSeconds)->toBe(600)->and($caps->minVideoSeconds)->toBe(3)
        ->and($caps->maxVideoBytes)->toBe(4 * 1024 * 1024 * 1024);
});

it('counts the caption in UTF-16 units as TikTok does', function () {
    expect(tikTokAdapter()->textLength('abc'))->toBe(3)
        ->and(tikTokAdapter()->textLength('আমি'))->toBe(3)
        ->and(tikTokAdapter()->textLength('hi 😀'))->toBe(5);
});

it('builds the authorization URL with client_key, comma-separated scopes and the exact redirect and state', function () {
    $request = tikTokAdapter()->authorizationRequest('https://oyosend.test/social/oauth/callback/tiktok', 'envelope.state');
    parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);

    expect($request->url)->toStartWith('https://www.tiktok.com/v2/auth/authorize/?')
        ->and($query['client_key'])->toBe('tt-client-key')
        ->and($query)->not->toHaveKey('client_id')
        ->and($query['scope'])->toBe('user.info.basic,video.publish')
        ->and($query['response_type'])->toBe('code')
        ->and($query['redirect_uri'])->toBe('https://oyosend.test/social/oauth/callback/tiktok')
        ->and($query['state'])->toBe('envelope.state')
        ->and($request->codeVerifier)->toBeNull();
});

it('exchanges the code and reads the creator profile', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'open.tiktokapis.com/v2/oauth/token/' => Http::response([
            'access_token' => 'act.live', 'expires_in' => 86400, 'open_id' => 'open-77',
            'refresh_token' => 'rft.live', 'refresh_expires_in' => 31536000,
            'scope' => 'user.info.basic,video.publish', 'token_type' => 'Bearer',
        ]),
        'open.tiktokapis.com/v2/user/info/*' => Http::response([
            'data' => ['user' => ['open_id' => 'open-77', 'display_name' => 'Brand Creator', 'avatar_url' => 'https://p16.tiktokcdn.com/a.jpeg']],
            'error' => ['code' => 'ok', 'message' => ''],
        ]),
    ]);

    $profiles = tikTokAdapter()->completeAuthorization('auth-code', 'https://oyosend.test/cb', null);

    expect($profiles)->toHaveCount(1)
        ->and($profiles[0]->externalId)->toBe('open-77')
        ->and($profiles[0]->name)->toBe('Brand Creator')
        ->and($profiles[0]->avatarUrl)->toBe('https://p16.tiktokcdn.com/a.jpeg')
        ->and($profiles[0]->type)->toBe('profile')
        ->and($profiles[0]->tokens->accessToken)->toBe('act.live')
        ->and($profiles[0]->tokens->refreshToken)->toBe('rft.live')
        ->and($profiles[0]->tokens->expiresAt?->isFuture())->toBeTrue()
        ->and($profiles[0]->tokens->refreshExpiresAt?->gt(now()->addDays(300)))->toBeTrue()
        ->and($profiles[0]->tokens->scopes)->toBe(['user.info.basic', 'video.publish']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://open.tiktokapis.com/v2/oauth/token/'
        && $request['client_key'] === 'tt-client-key' && $request['client_secret'] === 'tt-secret'
        && $request['grant_type'] === 'authorization_code' && $request['code'] === 'auth-code'
        && $request['redirect_uri'] === 'https://oyosend.test/cb' && ! isset($request['client_id']));
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://open.tiktokapis.com/v2/user/info/')
        && $request['fields'] === 'open_id,display_name,avatar_url'
        && $request->header('Authorization') === ['Bearer act.live']);
});

it('refuses a connection that did not grant video posting, and a rejected code', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['open.tiktokapis.com/v2/oauth/token/' => Http::response([
        'access_token' => 'act.live', 'expires_in' => 86400, 'open_id' => 'open-77', 'scope' => 'user.info.basic',
    ])]);

    expect(fn () => tikTokAdapter()->completeAuthorization('c', 'https://oyosend.test/cb', null))
        ->toThrow(ReauthorizationRequired::class, 'permission to post videos');

    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['open.tiktokapis.com/v2/oauth/token/' => Http::response([
        'error' => 'invalid_grant', 'error_description' => 'Authorization code is expired.', 'log_id' => 'x',
    ], 400)]);

    expect(fn () => tikTokAdapter()->completeAuthorization('c', 'https://oyosend.test/cb', null))
        ->toThrow(ReauthorizationRequired::class, 'Authorization code is expired.');
});

it('refreshes the access token, and tells a revoked refresh token from an outage', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['open.tiktokapis.com/v2/oauth/token/' => Http::response([
        'access_token' => 'act.new', 'expires_in' => 86400, 'refresh_token' => 'rft.new', 'refresh_expires_in' => 31536000,
        'scope' => 'user.info.basic,video.publish',
    ])]);

    $tokens = tikTokAdapter()->refresh('rft.old');

    expect($tokens->accessToken)->toBe('act.new')->and($tokens->refreshToken)->toBe('rft.new');
    Http::assertSent(fn (Request $request): bool => $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'rft.old' && $request['client_key'] === 'tt-client-key');

    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['open.tiktokapis.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid or expired.'], 400)]);
    expect(fn () => tikTokAdapter()->refresh('rft.revoked'))->toThrow(ReauthorizationRequired::class);

    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['open.tiktokapis.com/*' => Http::response('', 503)]);
    $outage = tikTokFailure(fn () => tikTokAdapter()->refresh('rft.old'));
    expect($outage->retryable)->toBeTrue()->and($outage->reauthorize)->toBeFalse();
});

it('cuts videos by TikTok chunk rules', function () {
    $mb = 1024 * 1024;

    $small = TikTokChunkPlan::for(3 * $mb, 10 * $mb);
    expect([$small->chunkSize, $small->count, $small->range(0)])->toBe([3 * $mb, 1, [0, 3 * $mb]]);

    $whole = TikTokChunkPlan::for(8 * $mb, 10 * $mb);
    expect([$whole->chunkSize, $whole->count])->toBe([8 * $mb, 1]);

    $split = TikTokChunkPlan::for(27 * $mb, 10 * $mb);
    expect([$split->chunkSize, $split->count, $split->range(0), $split->range(1)])
        ->toBe([10 * $mb, 2, [0, 10 * $mb], [10 * $mb, 17 * $mb]]);

    // Below 5 MB the chunk is raised to 5 MB; above 64 MB it is lowered to 64 MB.
    expect(TikTokChunkPlan::for(12 * $mb, $mb)->chunkSize)->toBe(5 * $mb)
        ->and(TikTokChunkPlan::for(300 * $mb, 200 * $mb)->chunkSize)->toBe(64 * $mb);

    // Never more than 1000 chunks.
    $huge = TikTokChunkPlan::for(6000 * $mb, 5 * $mb);
    expect($huge->count)->toBeLessThanOrEqual(1000)
        ->and($huge->range($huge->count - 1)[0] + $huge->range($huge->count - 1)[1])->toBe(6000 * $mb);

    expect(fn () => TikTokChunkPlan::for(0, 5 * $mb))->toThrow(PublishFailed::class);
});

it('uploads in chunks across runs, then polls the status until TikTok has published', function () {
    $fake = tikTokUnitFake();
    $fake->statuses = [
        ['status' => 'PROCESSING_UPLOAD'],
        ['status' => 'PROCESSING_DOWNLOAD'],
        ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => [7350000000000000001]],
    ];
    $size = 12 * 1024 * 1024;
    $payload = new PublishPayload('New drop #launch', 'https://example.com/x', [tikTokVideo($size)], postType: PostType::Video);

    expect(tikTokAdapter()->usesSteps($payload))->toBeTrue();

    // Run 1: creator info, init and the first chunk; the deadline has passed, so it stops there.
    $first = tikTokAdapter()->advance($this->account, $payload, new PublishSession, $this->past);

    expect($first->state)->toBe('uploading')
        ->and($first->remoteId)->toBe('v_pub_file~v2-1.42')
        ->and($first->offset)->toBe(5 * 1024 * 1024)
        ->and($first->externalId)->toBeNull()
        ->and(json_encode($first->meta))->not->toContain('SECRET-UPLOAD-TOKEN')->not->toContain('open-upload');

    $init = $fake->calls('init')[0];
    expect($fake->calls('creator'))->toHaveCount(1)
        ->and($init['headers']['Authorization'])->toBe('Bearer live-access-token')
        ->and($init['body']['post_info'])->toBe([
            'title' => 'New drop #launch', 'privacy_level' => 'PUBLIC_TO_EVERYONE',
            'disable_comment' => false, 'disable_duet' => true, 'disable_stitch' => false,
        ])
        ->and($init['body']['source_info'])->toBe([
            'source' => 'FILE_UPLOAD', 'video_size' => $size, 'chunk_size' => 5 * 1024 * 1024, 'total_chunk_count' => 2,
        ]);

    // Run 2: the last chunk (5 MB + the remainder) completes the upload; the publish id is now the external id.
    $second = tikTokAdapter()->advance($this->account, $payload, tikTokSession($first), $this->past);

    $chunks = $fake->calls('chunk');
    expect($second->state)->toBe('processing')
        ->and($second->externalId)->toBe('v_pub_file~v2-1.42')
        ->and($second->retryAfterSeconds)->toBe(45)
        ->and($chunks)->toHaveCount(2)
        ->and($chunks[0]['method'])->toBe('PUT')
        ->and($chunks[0]['url'])->toBe(TIKTOK_UPLOAD_URL)
        ->and($chunks[0]['headers']['Content-Range'])->toBe('bytes 0-5242879/'.$size)
        ->and($chunks[0]['headers']['Content-Type'])->toBe('video/mp4')
        ->and($chunks[0]['headers'])->not->toHaveKey('Authorization')
        ->and($chunks[1]['headers']['Content-Range'])->toBe('bytes 5242880-'.($size - 1).'/'.$size)
        ->and(array_sum(array_column($chunks, 'bytes')))->toBe($size)
        ->and($fake->calls('init'))->toHaveCount(1);

    // Runs 3 and 4: still processing; nothing is uploaded or initialised again.
    $third = tikTokAdapter()->advance($this->account, $payload, tikTokSession($second), $this->later);
    $fourth = tikTokAdapter()->advance($this->account, $payload, tikTokSession($third), $this->later);
    expect($third->state)->toBe('processing')->and($third->published)->toBeNull()
        ->and($fourth->published)->toBeNull();

    // Run 5: published, with the public post id and its URL.
    $fifth = tikTokAdapter()->advance($this->account, $payload, tikTokSession($fourth), $this->later);

    expect($fifth->published?->externalId)->toBe('7350000000000000001')
        ->and($fifth->published?->url)->toBe('https://www.tiktok.com/@brand.creator/video/7350000000000000001')
        ->and($fake->calls('init'))->toHaveCount(1)
        ->and($fake->calls('chunk'))->toHaveCount(2)
        ->and($fake->calls('status'))->toHaveCount(3)
        ->and($fake->calls('status')[0]['body'])->toBe(['publish_id' => 'v_pub_file~v2-1.42']);
});

it('uploads a small video in one run and keeps the publish id when a private post has no public id', function () {
    $fake = tikTokUnitFake();
    $fake->creator['privacy_level_options'] = ['SELF_ONLY'];
    $fake->statuses = [['status' => 'PUBLISH_COMPLETE']];
    $payload = new PublishPayload('Private test', null, [tikTokVideo(2000)]);

    $uploaded = tikTokAdapter()->advance($this->account, $payload, new PublishSession, $this->later);

    expect($uploaded->state)->toBe('processing')
        ->and($fake->calls('init')[0]['body']['post_info']['privacy_level'])->toBe('SELF_ONLY')
        ->and($fake->calls('init')[0]['body']['source_info'])->toMatchArray(['video_size' => 2000, 'chunk_size' => 2000, 'total_chunk_count' => 1])
        ->and($fake->calls('chunk')[0]['headers']['Content-Range'])->toBe('bytes 0-1999/2000');

    $done = tikTokAdapter()->advance($this->account, $payload, tikTokSession($uploaded), $this->later);

    expect($done->published?->externalId)->toBe('v_pub_file~v2-1.42')->and($done->published?->url)->toBeNull();
});

it('refuses a video longer than the creator may post, before anything is created', function () {
    $fake = tikTokUnitFake();
    $fake->creator['max_video_post_duration_sec'] = 60;

    $failure = tikTokFailure(fn () => tikTokAdapter()->advance(
        $this->account, new PublishPayload('Long', null, [tikTokVideo(2000, 90_000)]), new PublishSession, $this->later,
    ));

    expect($failure->errorCode)->toBe('video_too_long')
        ->and($failure->getMessage())->toContain('60 seconds')
        ->and($failure->retryable)->toBeFalse()
        ->and($fake->calls('init'))->toBeEmpty();
});

it('fails with TikTok\'s reason when the post failed, and flags an inbox delivery for a manual check', function () {
    $fake = tikTokUnitFake();
    $fake->statuses = [['status' => 'FAILED', 'fail_reason' => 'frame_rate_check_failed']];
    $session = new PublishSession('v_pub_file~v2-1.42', 'processing', 2000, ['size' => 2000], 'v_pub_file~v2-1.42');
    $payload = new PublishPayload('x', null, [tikTokVideo(2000)]);

    $failed = tikTokFailure(fn () => tikTokAdapter()->advance($this->account, $payload, $session, $this->later));
    expect($failed->errorCode)->toBe('tiktok_frame_rate_check_failed')
        ->and($failed->getMessage())->toContain('frame rate')
        ->and($failed->retryable)->toBeFalse()->and($failed->outcomeUnknown)->toBeFalse();

    $fake->statuses = [['status' => 'SEND_TO_USER_INBOX']];
    $inbox = tikTokFailure(fn () => tikTokAdapter()->advance($this->account, $payload, $session, $this->later));
    expect($inbox->outcomeUnknown)->toBeTrue()->and($inbox->errorCode)->toBe('tiktok_sent_to_inbox');

    $fake->statuses = [['status' => 'FAILED', 'fail_reason' => 'auth_removed']];
    expect(tikTokFailure(fn () => tikTokAdapter()->advance($this->account, $payload, $session, $this->later))->reauthorize)->toBeTrue();
});

it('maps token, scope, rate-limit and outage errors by where they happen', function () {
    $payload = new PublishPayload('x', null, [tikTokVideo(2000)]);
    $start = fn () => tikTokAdapter()->advance($this->account, $payload, new PublishSession, $this->later);

    $fake = tikTokUnitFake();
    $fake->failNext('creator', $fake::error('access_token_invalid', 401, 'The access token is invalid or not found in the request.'));
    $expired = tikTokFailure($start);
    expect($expired->reauthorize)->toBeTrue()->and($expired->retryable)->toBeFalse();

    $fake = tikTokUnitFake();
    $fake->failNext('init', $fake::error('scope_not_authorized', 403));
    expect(tikTokFailure($start)->reauthorize)->toBeTrue();

    $fake = tikTokUnitFake();
    $fake->failNext('creator', $fake::error('rate_limit_exceeded', 429, 'Too many requests', ['Retry-After' => '30']));
    $limited = tikTokFailure($start);
    expect($limited->retryable)->toBeTrue()->and($limited->retryAfterSeconds)->toBe(30)->and($limited->errorCode)->toBe('rate_limited');

    // Creator info only reads: an outage there is safe to retry.
    $fake = tikTokUnitFake();
    $fake->failNext('creator', $fake::error('internal_error', 500));
    expect(tikTokFailure($start)->retryable)->toBeTrue();

    // Init creates a pending post: a lost answer is never retried blindly.
    $fake = tikTokUnitFake();
    $fake->failNext('init', $fake::error('internal_error', 500));
    $down = tikTokFailure($start);
    expect($down->outcomeUnknown)->toBeTrue()->and($down->retryable)->toBeFalse();

    $fake = tikTokUnitFake();
    $fake->failNext('init', fn () => throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds'));
    expect(tikTokFailure($start)->outcomeUnknown)->toBeTrue();

    $fake = tikTokUnitFake();
    $fake->failNext('init', $fake::error('spam_risk_too_many_posts', 403));
    $daily = tikTokFailure($start);
    expect($daily->errorCode)->toBe('tiktok_spam_risk_too_many_posts')->and($daily->getMessage())->toContain('daily limit')
        ->and($daily->retryable)->toBeFalse();

    $fake = tikTokUnitFake();
    $fake->failNext('init', $fake::error('unaudited_client_can_only_post_to_private_accounts', 403));
    expect(tikTokFailure($start)->getMessage())->toContain('audited');

    expect($fake->calls('chunk'))->toBeEmpty();
});

it('sends a failed chunk again to the same upload, and ends with "nothing was posted" when it keeps failing', function () {
    $fake = tikTokUnitFake();
    $payload = new PublishPayload('x', null, [tikTokVideo(12 * 1024 * 1024)]);
    $fake->failNext('chunk', Http::response('', 503));

    $first = tikTokAdapter()->advance($this->account, $payload, new PublishSession, $this->later);

    expect($first->state)->toBe('uploading')->and($first->offset)->toBe(0)
        ->and($first->meta['chunk_failures'])->toBe(1)
        ->and($first->retryAfterSeconds)->toBe(30)
        ->and(json_encode($first->meta))->not->toContain('SECRET-UPLOAD-TOKEN');

    $second = tikTokAdapter()->advance($this->account, $payload, tikTokSession($first), $this->later);

    expect($second->state)->toBe('processing')
        ->and($fake->calls('init'))->toHaveCount(1)
        ->and(array_column(array_column($fake->calls('chunk'), 'headers'), 'Content-Range'))->toBe([
            'bytes 0-5242879/12582912', 'bytes 0-5242879/12582912', 'bytes 5242880-12582911/12582912',
        ]);

    // A first chunk that keeps failing: the upload ends, nothing was posted, no new upload is started.
    $fake = tikTokUnitFake();
    foreach (range(1, 3) as $ignored) {
        $fake->failNext('chunk', Http::response('', 500));
    }
    $progress = tikTokAdapter()->advance($this->account, $payload, new PublishSession, $this->later);
    $progress = tikTokAdapter()->advance($this->account, $payload, tikTokSession($progress), $this->later);
    $failure = tikTokFailure(fn () => tikTokAdapter()->advance($this->account, $payload, tikTokSession($progress), $this->later));

    expect($failure->errorCode)->toBe('upload_failed')->and($failure->outcomeUnknown)->toBeFalse()
        ->and($failure->getMessage())->toContain('Nothing was posted')
        ->and($fake->calls('init'))->toHaveCount(1);
});

it('treats a lost answer to the last chunk as possibly posted', function () {
    $fake = tikTokUnitFake();
    $payload = new PublishPayload('x', null, [tikTokVideo(2000)]);
    $fake->failNext('chunk', fn () => throw new ConnectionException('cURL error 28: Operation timed out after 25001 milliseconds'));
    $fake->failNext('chunk', Http::response('', 400));

    $first = tikTokAdapter()->advance($this->account, $payload, new PublishSession, $this->later);
    expect($first->state)->toBe('uploading')->and($first->meta['uncertain'])->toBeTrue();

    // TikTok refuses the repeat (it already had the file): the target watches the status instead of failing.
    $second = tikTokAdapter()->advance($this->account, $payload, tikTokSession($first), $this->later);
    expect($second->state)->toBe('processing')->and($second->externalId)->toBe('v_pub_file~v2-1.42');
});

it('fails honestly when the upload link is gone between runs', function () {
    $fake = tikTokUnitFake();
    $payload = new PublishPayload('x', null, [tikTokVideo(12 * 1024 * 1024)]);
    $first = tikTokAdapter()->advance($this->account, $payload, new PublishSession, $this->past);

    Cache::flush();
    $failure = tikTokFailure(fn () => tikTokAdapter()->advance($this->account, $payload, tikTokSession($first), $this->later));

    expect($failure->errorCode)->toBe('upload_expired')->and($failure->outcomeUnknown)->toBeFalse()
        ->and($fake->calls('init'))->toHaveCount(1);
});

it('refuses text-only and photo posts without calling TikTok', function () {
    $fake = tikTokUnitFake();
    $photo = new MediaFile('a.png', 'image/png', false, null, fn (): string => 'png');

    expect(tikTokAdapter()->usesSteps(new PublishPayload('Only text')))->toBeFalse()
        ->and(tikTokAdapter()->usesSteps(new PublishPayload('Photo', null, [$photo])))->toBeFalse()
        ->and(tikTokFailure(fn () => tikTokAdapter()->publish($this->account, new PublishPayload('Only text')))->errorCode)->toBe('media_missing')
        ->and(tikTokFailure(fn () => tikTokAdapter()->publish($this->account, new PublishPayload('Photo', null, [$photo])))->errorCode)->toBe('unsupported_media')
        ->and($fake->log)->toBeEmpty();
});

it('never puts the access token or the upload URL in a message', function () {
    $payload = new PublishPayload('x', null, [tikTokVideo(12 * 1024 * 1024)]);
    $messages = [];

    $fake = tikTokUnitFake();
    $fake->failNext('init', $fake::error('invalid_params', 400, 'Bad upload_url '.TIKTOK_UPLOAD_URL.' for live-access-token'));
    $messages[] = tikTokFailure(fn () => tikTokAdapter()->advance($this->account, $payload, new PublishSession, $this->later))->getMessage();

    $fake = tikTokUnitFake();
    foreach (range(1, 3) as $ignored) {
        $fake->failNext('chunk', Http::response(['error' => TIKTOK_UPLOAD_URL], 502));
    }
    $progress = tikTokAdapter()->advance($this->account, $payload, new PublishSession, $this->later);
    $progress = tikTokAdapter()->advance($this->account, $payload, tikTokSession($progress), $this->later);
    $messages[] = tikTokFailure(fn () => tikTokAdapter()->advance($this->account, $payload, tikTokSession($progress), $this->later))->getMessage();

    foreach ($messages as $message) {
        expect($message)->not->toContain('SECRET-UPLOAD-TOKEN')->not->toContain('open-upload')->not->toContain('live-access-token');
    }

    expect($messages[0])->toContain('[link]');
});

it('judges the post type, file type, picture size and says posts stay private until the audit', function () {
    $video = new MediaInfo(true, 'video/mp4', 1000, 1080, 1920, 30_000);

    expect(tikTokAdapter()->checkPost(PostType::Photos, [$video])[0]['blocking'])->toBeTrue()
        ->and(tikTokAdapter()->checkPost(PostType::Reel, [$video]))->toBe([])
        ->and(tikTokAdapter()->checkPost(PostType::Video, [$video, $video])[0]['message'])->toContain('exactly one video');

    $ok = tikTokAdapter()->mediaIssues([$video], 'caption');
    expect(array_column($ok, 'blocking'))->toBe([false])
        ->and($ok[0]['message'])->toContain('private');

    $bad = tikTokAdapter()->mediaIssues([new MediaInfo(true, 'video/x-msvideo', 1000, 200, 300, 30_000)], 'caption');
    expect(array_values(array_filter(array_column($bad, 'blocking'))))->toHaveCount(2);

    expect(tikTokAdapter()->mediaBadges($video))->toBe([['code' => 'video_ok', 'tone' => 'ok']])
        ->and(tikTokAdapter()->mediaBadges(new MediaInfo(true, 'video/mp4', 1000, 1080, 1920, 900_000)))->toBe([['code' => 'video_too_long', 'tone' => 'danger']])
        ->and(tikTokAdapter()->mediaBadges(new MediaInfo(false, 'image/png', 1000, 10, 10)))->toBe([['code' => 'unsupported', 'tone' => 'muted']]);
});
