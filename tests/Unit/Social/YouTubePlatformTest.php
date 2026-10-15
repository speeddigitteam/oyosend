<?php

declare(strict_types=1);

use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Adapters\YouTubePlatform;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\Exceptions\ReauthorizationRequired;
use App\Modules\Social\Platforms\MediaFile;
use App\Modules\Social\Platforms\MediaInfo;
use App\Modules\Social\Platforms\PlatformAvailability;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\PublishProgress;
use App\Modules\Social\Platforms\PublishSession;
use App\Modules\Social\Platforms\SocialPlatforms;
use App\Modules\Social\Platforms\TokenSet;
use App\Modules\Social\Platforms\YouTube\YouTubeVideoText;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures\Mp4Builder;

const YT_SESSION = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status&upload_id=SECRET-SESSION-42';
const YT_KIB = 1024;

/** A clean HTTP fake (stubs accumulate and the first match wins) and a fresh adapter bound to it. */
function ytAdapter(): YouTubePlatform
{
    Http::swap(new Factory);

    return app(YouTubePlatform::class);
}

/**
 * A stand-in for Google's resumable upload endpoint: start answers with a session URI, each chunk is
 * accepted (up to `$accept` bytes) and answered 308 + Range until the file is complete, then 200 + video.
 */
function ytServer(): object
{
    return new class
    {
        public int $received = 0;

        public int $starts = 0;

        /** @var list<array{range: string, bytes: int, auth: string}> */
        public array $puts = [];

        /** @var list<array<string, mixed>> */
        public array $startBodies = [];

        /** @var list<array<string, string>> */
        public array $startHeaders = [];

        /** @var list<Closure> */
        public array $failNext = [];

        public ?int $accept = null;

        public function install(): YouTubePlatform
        {
            $adapter = ytAdapter();
            Http::fake(fn (Request $request) => $this->respond($request));

            return $adapter;
        }

        private function respond(Request $request): mixed
        {
            if ($request->method() === 'POST' && str_starts_with($request->url(), 'https://www.googleapis.com/upload/youtube/v3/videos?')) {
                $this->starts++;
                $this->startBodies[] = $request->data();
                $this->startHeaders[] = [
                    'length' => $request->header('X-Upload-Content-Length')[0] ?? '',
                    'type' => $request->header('X-Upload-Content-Type')[0] ?? '',
                    'auth' => $request->header('Authorization')[0] ?? '',
                    'url' => $request->url(),
                ];

                return Http::response('', 200, ['Location' => YT_SESSION]);
            }

            if ($request->method() === 'PUT' && $request->url() === YT_SESSION) {
                $range = $request->header('Content-Range')[0] ?? '';
                $this->puts[] = ['range' => $range, 'bytes' => strlen($request->body()), 'auth' => $request->header('Authorization')[0] ?? ''];

                if ($this->failNext !== []) {
                    return (array_shift($this->failNext))();
                }

                if (preg_match('~^bytes \*/(\d+)$~', $range, $m) === 1) {
                    return $this->received === (int) $m[1] ? $this->video() : $this->incomplete();
                }

                preg_match('~^bytes (\d+)-(\d+)/(\d+)$~', $range, $m);
                expect((int) $m[1])->toBe($this->received)
                    ->and((int) $m[2] - (int) $m[1] + 1)->toBe(strlen($request->body()));
                $this->received += min(strlen($request->body()), $this->accept ?? PHP_INT_MAX);

                return $this->received === (int) $m[3] ? $this->video() : $this->incomplete();
            }

            return Http::response(['error' => ['code' => 404, 'message' => 'unexpected '.$request->method().' '.$request->url()]], 404);
        }

        private function incomplete(): mixed
        {
            return Http::response('', 308, $this->received > 0 ? ['Range' => 'bytes=0-'.($this->received - 1)] : []);
        }

        private function video(): mixed
        {
            return Http::response(['kind' => 'youtube#video', 'id' => 'vid-123', 'status' => ['uploadStatus' => 'uploaded', 'privacyStatus' => 'private']], 200);
        }
    };
}

function ytVideo(int $bytes): MediaFile
{
    $content = Mp4Builder::make(20, 1920, 1080, ['payload' => $bytes]);

    return new MediaFile('clip.mp4', 'video/mp4', true, null, fn () => $content, strlen($content),
        ranger: fn (int $offset, int $length): string => substr($content, $offset, $length));
}

function ytSession(PublishProgress $progress, array $extra = []): PublishSession
{
    return new PublishSession($progress->remoteId, $progress->state, $progress->offset, [...$progress->meta, ...$extra], $progress->externalId);
}

function ytGoogleError(int $status, string $reason, string $message = 'Google says no.'): mixed
{
    return Http::response(['error' => ['code' => $status, 'message' => $message, 'errors' => [['reason' => $reason, 'domain' => 'youtube']]]], $status);
}

beforeEach(function () {
    config([
        'social.platforms.youtube.client_id' => 'yt-client.apps.googleusercontent.com',
        'social.platforms.youtube.client_secret' => 'yt-secret',
        'social.platforms.youtube.limits.chunk_bytes' => 256 * YT_KIB,
        'social.platforms.youtube.limits.privacy' => 'public',
    ]);
    $this->credentials = new AccountCredentials('UC-channel', 'live-access-token');
    $this->payload = fn (MediaFile $file, string $text = "Launch day\nEverything about the launch.") => new PublishPayload($text, null, [$file]);
});

it('is registered under youtube and stays not configured, with the reason, until the operator sets its credentials', function () {
    expect(app(SocialPlatforms::class)->get('youtube'))->toBeInstanceOf(YouTubePlatform::class);

    config(['social.platforms.youtube.client_secret' => null]);
    $availability = app(YouTubePlatform::class)->availability();

    expect($availability->available)->toBeFalse()
        ->and($availability->code)->toBe(PlatformAvailability::NOT_CONFIGURED)
        ->and($availability->reason)->toContain('YouTube');

    config(['social.platforms.youtube.client_secret' => 'yt-secret']);
    expect(app(YouTubePlatform::class)->availability()->available)->toBeTrue();
});

it('declares a video-only platform with the configured limits', function () {
    $capabilities = app(YouTubePlatform::class)->capabilities();

    expect($capabilities->publish)->toBeTrue()
        ->and($capabilities->video)->toBeTrue()
        ->and($capabilities->images)->toBeFalse()
        ->and($capabilities->requiresVideo)->toBeTrue()
        ->and($capabilities->toArray()['requiresMedia'])->toBeTrue()
        ->and($capabilities->maxMedia)->toBe(1)
        ->and($capabilities->maxText)->toBe(5000)
        ->and($capabilities->maxVideoSeconds)->toBe(15 * 60)
        ->and($capabilities->maxVideoBytes)->toBe(256 * 1024 * 1024 * 1024);
});

it('asks Google for offline access with consent, the configured scopes and PKCE S256', function () {
    $request = app(YouTubePlatform::class)->authorizationRequest('https://oyosend.test/social/oauth/callback/youtube', 'state-envelope');
    parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);

    expect($request->url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query['client_id'])->toBe('yt-client.apps.googleusercontent.com')
        ->and($query['redirect_uri'])->toBe('https://oyosend.test/social/oauth/callback/youtube')
        ->and($query['state'])->toBe('state-envelope')
        ->and($query['response_type'])->toBe('code')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['prompt'])->toBe('consent')
        ->and($query['include_granted_scopes'])->toBe('true')
        ->and($query['scope'])->toBe('https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($request->codeVerifier)->not->toBeNull()
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', (string) $request->codeVerifier, true)), '+/', '-_'), '='))
        ->and($request->url)->not->toContain('yt-secret');
});

it('exchanges the code with the verifier and lists the granted channel', function () {
    $adapter = ytAdapter();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'ya29.access', 'refresh_token' => '1//refresh', 'expires_in' => 3599,
            'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly', 'token_type' => 'Bearer',
        ]),
        'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [[
            'id' => 'UC123', 'snippet' => ['title' => 'Brand Channel', 'customUrl' => '@brand', 'thumbnails' => ['default' => ['url' => 'https://yt3.ggpht.com/a.jpg']]],
        ]]]),
    ]);

    $profiles = $adapter->completeAuthorization('auth-code', 'https://oyosend.test/social/oauth/callback/youtube', 'the-verifier');

    expect($profiles)->toHaveCount(1)
        ->and($profiles[0]->externalId)->toBe('UC123')
        ->and($profiles[0]->name)->toBe('Brand Channel')
        ->and($profiles[0]->handle)->toBe('@brand')
        ->and($profiles[0]->avatarUrl)->toBe('https://yt3.ggpht.com/a.jpg')
        ->and($profiles[0]->type)->toBe('channel')
        ->and($profiles[0]->tokens->accessToken)->toBe('ya29.access')
        ->and($profiles[0]->tokens->refreshToken)->toBe('1//refresh')
        ->and($profiles[0]->tokens->expiresAt)->not->toBeNull();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request['grant_type'] === 'authorization_code' && $request['code'] === 'auth-code'
        && $request['code_verifier'] === 'the-verifier' && $request['client_secret'] === 'yt-secret'
        && $request['redirect_uri'] === 'https://oyosend.test/social/oauth/callback/youtube');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/channels?')
        && $request['part'] === 'snippet' && $request['mine'] === 'true'
        && $request->header('Authorization')[0] === 'Bearer ya29.access');
});

it('refuses a Google account without a channel, a missing verifier and a rejected code', function () {
    $adapter = ytAdapter();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.access', 'expires_in' => 3599]),
        'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => []]),
    ]);

    expect(fn () => $adapter->completeAuthorization('code', 'https://x.test/cb', 'v'))
        ->toThrow(ReauthorizationRequired::class, 'no YouTube channel');
    expect(fn () => $adapter->completeAuthorization('code', 'https://x.test/cb', null))
        ->toThrow(ReauthorizationRequired::class);

    $adapter = ytAdapter();
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Bad Request'], 400)]);
    expect(fn () => $adapter->completeAuthorization('code', 'https://x.test/cb', 'v'))
        ->toThrow(ReauthorizationRequired::class, 'Bad Request');
});

it('refreshes the access token and keeps the refresh token Google does not repeat', function () {
    $adapter = ytAdapter();
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.new', 'expires_in' => 3599, 'scope' => 'a b'])]);

    $tokens = $adapter->refresh('1//kept');

    expect($tokens)->toBeInstanceOf(TokenSet::class)
        ->and($tokens->accessToken)->toBe('ya29.new')
        ->and($tokens->refreshToken)->toBe('1//kept')
        ->and($tokens->scopes)->toBe(['a', 'b']);
    Http::assertSent(fn (Request $request) => $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === '1//kept'
        && $request['client_id'] === 'yt-client.apps.googleusercontent.com');

    $adapter = ytAdapter();
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.new', 'refresh_token' => '1//rotated', 'expires_in' => 3599])]);
    expect($adapter->refresh('1//old')->refreshToken)->toBe('1//rotated');

    $adapter = ytAdapter();
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);
    expect(fn () => $adapter->refresh('1//revoked'))->toThrow(ReauthorizationRequired::class, 'expired or revoked');

    // A Google outage is not a reason to send the channel through the re-connect prompt.
    $adapter = ytAdapter();
    Http::fake(['oauth2.googleapis.com/token' => Http::response('', 503)]);
    try {
        $adapter->refresh('1//kept');
        $this->fail('expected a failure');
    } catch (PublishFailed $failure) {
        expect($failure->retryable)->toBeTrue()->and($failure->reauthorize)->toBeFalse();
    }
});

it('derives the title from the first line and demands text', function () {
    $text = YouTubeVideoText::from("\n  \n<b>Launch</b> day  is   here\nSecond line <3", 100);

    expect($text->title)->toBe('bLaunch/b day is here')
        ->and($text->description)->toBe("bLaunch/b day  is   here\nSecond line 3")
        ->and($text->removedBrackets)->toBeTrue()
        ->and($text->titleShortened)->toBeFalse();

    $long = YouTubeVideoText::from(str_repeat('অ', 150)."\nrest", 100);
    expect(mb_strlen($long->title))->toBe(100)->and($long->titleShortened)->toBeTrue();

    expect(YouTubeVideoText::from("  \n <> \n", 100)->hasTitle())->toBeFalse();

    $adapter = app(YouTubePlatform::class);
    $video = [new MediaInfo(true, 'video/mp4', 1000, 1920, 1080, 20_000)];

    $blocking = fn (array $issues) => array_values(array_filter($issues, fn ($issue) => $issue['blocking']));
    expect($blocking($adapter->mediaIssues($video, '')))->toHaveCount(1)
        ->and($blocking($adapter->mediaIssues($video, '')))->toBe([['blocking' => true, 'message' => 'YouTube needs text: its first line becomes the video title.']])
        ->and($adapter->mediaIssues($video, "Title\nBody"))->toBe([['blocking' => false, 'message' => 'Until Google audits the operator\'s project, YouTube keeps uploaded videos private.']])
        ->and($blocking($adapter->mediaIssues($video, str_repeat('অ', 1700))))->toHaveCount(1)
        ->and($blocking($adapter->mediaIssues([new MediaInfo(true, 'video/x-msvideo', 1000)], 'Title')))->toHaveCount(1);

    $warnings = $adapter->mediaIssues($video, str_repeat('x', 120).' <tag>');
    expect($warnings)->toHaveCount(3)->and(array_column($warnings, 'blocking'))->toBe([false, false, false]);

    // Uploading as private already: nothing to warn about.
    config(['social.platforms.youtube.limits.privacy' => 'private']);
    expect($adapter->mediaIssues($video, "Title\nBody"))->toBe([]);
});

it('uploads in 256 KiB chunks across several runs, resuming from the Range YouTube confirms', function () {
    $server = ytServer();
    $adapter = $server->install();
    $file = ytVideo(700 * YT_KIB);
    $payload = ($this->payload)($file);
    $past = microtime(true) - 1;

    expect($adapter->usesSteps($payload))->toBeTrue();

    // Run 1: only the session is started; it is saved before any byte is sent.
    $progress = $adapter->advance($this->credentials, $payload, new PublishSession, $past);
    expect($progress->state)->toBe('uploading')
        ->and($progress->offset)->toBe(0)
        ->and($progress->externalId)->toBeNull()
        ->and($server->starts)->toBe(1)
        ->and($server->puts)->toBe([])
        ->and($server->startHeaders[0]['url'])->toBe('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet%2Cstatus')
        ->and($server->startHeaders[0]['length'])->toBe((string) $file->sizeBytes)
        ->and($server->startHeaders[0]['type'])->toBe('video/mp4')
        ->and($server->startHeaders[0]['auth'])->toBe('Bearer live-access-token')
        ->and($server->startBodies[0])->toBe([
            'snippet' => ['title' => 'Launch day', 'description' => "Launch day\nEverything about the launch.", 'categoryId' => '22'],
            'status' => ['privacyStatus' => 'public', 'selfDeclaredMadeForKids' => false],
        ]);
    // The session URI is kept only encrypted, never in the clear.
    expect(json_encode($progress->meta))->not->toContain('SECRET-SESSION-42')->not->toContain('googleapis');

    // Run 2 and 3: one chunk each (the deadline has passed, but each run does at least one unit of work).
    $progress = $adapter->advance($this->credentials, $payload, ytSession($progress), $past);
    expect($progress->offset)->toBe(256 * YT_KIB)->and($server->puts[0]['range'])->toBe('bytes 0-262143/'.$file->sizeBytes)
        ->and($server->puts[0]['auth'])->toBe('Bearer live-access-token');

    // YouTube may keep less than it was sent: the next chunk starts where its Range ends.
    $server->accept = 100 * YT_KIB;
    $progress = $adapter->advance($this->credentials, $payload, ytSession($progress), $past);
    expect($progress->offset)->toBe(356 * YT_KIB)->and($server->starts)->toBe(1);
    $server->accept = null;

    // Last run with time to spare: the remaining chunks, then the video.
    $progress = $adapter->advance($this->credentials, $payload, ytSession($progress), microtime(true) + 30);
    expect($progress->published?->externalId)->toBe('vid-123')
        ->and($progress->published?->url)->toBe('https://www.youtube.com/watch?v=vid-123')
        ->and($server->received)->toBe($file->sizeBytes)
        ->and($server->starts)->toBe(1)
        ->and(max(array_column($server->puts, 'bytes')))->toBe(256 * YT_KIB);
});

it('asks for the upload status after an interrupted run and resumes, or starts a new session when it expired', function () {
    $server = ytServer();
    $adapter = $server->install();
    $file = ytVideo(600 * YT_KIB);
    $payload = ($this->payload)($file);
    $past = microtime(true) - 1;

    $started = $adapter->advance($this->credentials, $payload, new PublishSession, $past);
    $first = $adapter->advance($this->credentials, $payload, ytSession($started), $past);

    // The next chunk times out mid-way: retryable (nothing exists on YouTube), and the publisher keeps the old meta.
    $server->failNext[] = fn () => throw new ConnectionException('cURL error 28: Operation timed out for '.YT_SESSION);
    try {
        $adapter->advance($this->credentials, $payload, ytSession($first), $past);
        $this->fail('expected a failure');
    } catch (PublishFailed $failure) {
        expect($failure->retryable)->toBeTrue()
            ->and($failure->outcomeUnknown)->toBeFalse()
            ->and($failure->getMessage())->not->toContain('SECRET-SESSION-42')->not->toContain('live-access-token');
    }

    // YouTube did store part of that chunk: the status query tells, and the upload continues from there.
    $server->received = 300 * YT_KIB;
    $resumed = $adapter->advance($this->credentials, $payload, ytSession($first, ['failures' => 1]), $past);
    $statusQuery = $server->puts[count($server->puts) - 2];
    expect($statusQuery['range'])->toBe('bytes */'.$file->sizeBytes)
        ->and($statusQuery['bytes'])->toBe(0)
        ->and($server->puts[count($server->puts) - 1]['range'])->toStartWith('bytes 307200-')
        ->and($resumed->offset)->toBe(300 * YT_KIB + 256 * YT_KIB)
        ->and($resumed->meta)->not->toHaveKey('failures');

    // A session that expired (404) is replaced by a new one; nothing was ever created on YouTube.
    $server->failNext[] = fn () => Http::response('', 404);
    $restarted = $adapter->advance($this->credentials, $payload, ytSession($resumed, ['failures' => 2]), $past);
    expect($restarted->offset)->toBe(0)->and($server->starts)->toBe(2)->and($restarted->published)->toBeNull();

    // A status query that finds the upload complete reports the video, without sending anything again.
    $server->received = $file->sizeBytes;
    $done = $adapter->advance($this->credentials, $payload, ytSession($resumed, ['failures' => 1]), $past);
    expect($done->published?->externalId)->toBe('vid-123')
        ->and($server->puts[count($server->puts) - 1]['range'])->toBe('bytes */'.$file->sizeBytes);
});

it('retries a server error on a chunk but treats a lost answer to the completing chunk as an unknown outcome', function () {
    $server = ytServer();
    $adapter = $server->install();
    $file = ytVideo(300 * YT_KIB);
    $payload = ($this->payload)($file);
    $session = ytSession($adapter->advance($this->credentials, $payload, new PublishSession, microtime(true) - 1));

    $server->failNext[] = fn () => Http::response(['error' => ['code' => 503, 'message' => 'Backend Error']], 503);
    try {
        $adapter->advance($this->credentials, $payload, $session, microtime(true) + 30);
        $this->fail('expected a failure');
    } catch (PublishFailed $failure) {
        expect($failure->retryable)->toBeTrue()->and($failure->outcomeUnknown)->toBeFalse();
    }

    $server->received = 0;
    $server->accept = null;
    $server->failNext[] = fn () => Http::response('', 308, ['Range' => 'bytes=0-262143']);
    $server->failNext[] = fn () => throw new ConnectionException('cURL error 28: Operation timed out');
    $server->received = 256 * YT_KIB;

    try {
        $adapter->advance($this->credentials, $payload, ytSession(new PublishProgress('uploading', null, 0, $session->meta), ['failures' => 1]), microtime(true) + 30);
        $this->fail('expected a failure');
    } catch (PublishFailed $failure) {
        expect($failure->outcomeUnknown)->toBeTrue()
            ->and($failure->retryable)->toBeFalse()
            ->and($failure->errorCode)->toBe('outcome_unknown')
            ->and($failure->getMessage())->not->toContain('SECRET-SESSION-42');
    }

    // The completing chunk answered 5xx: YouTube may have the video, so it is not repeated either.
    $server->failNext[] = fn () => Http::response('', 500);
    try {
        $adapter->advance($this->credentials, $payload, new PublishSession(null, 'uploading', 256 * YT_KIB, $session->meta), microtime(true) + 30);
        $this->fail('expected a failure');
    } catch (PublishFailed $failure) {
        expect($failure->outcomeUnknown)->toBeTrue()->and($failure->retryable)->toBeFalse();
    }
});

it('maps quota, upload limit and authorisation errors to clear reasons without Google internals or secrets', function () {
    $file = ytVideo(10 * YT_KIB);

    $cases = [
        [ytGoogleError(403, 'quotaExceeded', 'The request cannot be completed because you have exceeded your quota.'), 'youtube_quota_exceeded', false, false],
        [ytGoogleError(400, 'uploadLimitExceeded'), 'youtube_upload_limit', false, false],
        [ytGoogleError(401, 'authError', 'Invalid Credentials'), 'reauthorization_required', true, false],
        [ytGoogleError(403, 'insufficientPermissions'), 'reauthorization_required', true, false],
        [ytGoogleError(429, 'rateLimitExceeded'), 'rate_limited', false, true],
        [ytGoogleError(400, 'invalidTitle', 'Invalid video title.'), 'youtube_invalid', false, false],
    ];

    foreach ($cases as [$response, $code, $reauthorize, $retryable]) {
        $adapter = ytAdapter();
        Http::fake(['www.googleapis.com/upload/*' => $response]);

        try {
            $adapter->advance($this->credentials, ($this->payload)($file), new PublishSession, microtime(true) + 30);
            $this->fail('expected a failure for '.$code);
        } catch (PublishFailed $failure) {
            expect($failure->errorCode)->toBe($code)
                ->and($failure->reauthorize)->toBe($reauthorize)
                ->and($failure->retryable)->toBe($retryable)
                ->and($failure->outcomeUnknown)->toBeFalse()
                ->and($failure->getMessage())->not->toContain('live-access-token');
        }
    }

    $adapter = ytAdapter();
    Http::fake(['www.googleapis.com/upload/*' => ytGoogleError(403, 'quotaExceeded')]);
    expect(fn () => $adapter->advance($this->credentials, ($this->payload)($file), new PublishSession, microtime(true) + 30))
        ->toThrow(PublishFailed::class, 'quota');

    // A chunk answered 401 sends the channel through the re-connect prompt too.
    $server = ytServer();
    $adapter = $server->install();
    $session = ytSession($adapter->advance($this->credentials, ($this->payload)($file), new PublishSession, microtime(true) + 30));
    $server->failNext[] = fn () => ytGoogleError(401, 'authError');
    expect(fn () => $adapter->advance($this->credentials, ($this->payload)($file), $session, microtime(true) + 30))
        ->toThrow(fn (PublishFailed $failure) => expect($failure->reauthorize)->toBeTrue());
});

it('refuses posts without a video or without a title before calling YouTube, and never trusts a foreign session host', function () {
    $adapter = ytAdapter();
    Http::fake();

    expect(fn () => $adapter->publish($this->credentials, new PublishPayload('Just text')))
        ->toThrow(PublishFailed::class, 'video only');
    expect(fn () => $adapter->advance($this->credentials, ($this->payload)(ytVideo(1000), " \n "), new PublishSession, microtime(true) + 30))
        ->toThrow(PublishFailed::class, 'first line becomes the video title');
    $photo = new MediaFile('a.png', 'image/png', false, null, fn () => 'png');
    expect(fn () => $adapter->advance($this->credentials, new PublishPayload('Title', null, [$photo]), new PublishSession, microtime(true) + 30))
        ->toThrow(PublishFailed::class);
    Http::assertNothingSent();

    $adapter = ytAdapter();
    Http::fake(['www.googleapis.com/upload/*' => Http::response('', 200, ['Location' => 'https://evil.example/upload?upload_id=x'])]);
    try {
        $adapter->advance($this->credentials, ($this->payload)(ytVideo(1000)), new PublishSession, microtime(true) + 30);
        $this->fail('expected a failure');
    } catch (PublishFailed $failure) {
        expect($failure->errorCode)->toBe('upload_not_started')->and($failure->retryable)->toBeTrue();
    }
});

it('never uploads again once the video id is known', function () {
    $adapter = ytAdapter();
    Http::fake();

    $progress = $adapter->advance($this->credentials, ($this->payload)(ytVideo(1000)), new PublishSession(null, 'uploading', 0, [], 'vid-9'), microtime(true) + 30);

    expect($progress->published?->externalId)->toBe('vid-9');
    Http::assertNothingSent();
});

it('reads likes and comments of a video, and nothing it did not return', function () {
    $adapter = ytAdapter();
    Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [['id' => 'vid-1', 'statistics' => [
        'viewCount' => '1200', 'likeCount' => '40', 'commentCount' => '7', 'favoriteCount' => '0',
    ]]]])]);

    $metrics = $adapter->metrics($this->credentials, 'vid-1');

    expect($metrics?->likes)->toBe(40)->and($metrics?->comments)->toBe(7)
        ->and($metrics?->impressions)->toBeNull()->and($metrics?->shares)->toBeNull();
    Http::assertSent(fn (Request $request) => $request['part'] === 'statistics' && $request['id'] === 'vid-1');

    $adapter = ytAdapter();
    Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => []])]);
    expect($adapter->metrics($this->credentials, 'gone'))->toBeNull();
});
