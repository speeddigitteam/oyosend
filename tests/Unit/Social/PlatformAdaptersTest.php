<?php

declare(strict_types=1);

use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Adapters\FacebookPagesPlatform;
use App\Modules\Social\Platforms\Adapters\LinkedInPlatform;
use App\Modules\Social\Platforms\Adapters\XPlatform;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\Exceptions\ReauthorizationRequired;
use App\Modules\Social\Platforms\MediaFile;
use App\Modules\Social\Platforms\PlatformAvailability;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\SocialPlatforms;
use App\Modules\Social\Platforms\TokenSet;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Http::fake() accumulates stubs and the first match wins: start every fake from a clean client. */
function freshHttp(): void
{
    Http::swap(new Factory);
}

beforeEach(function () {
    config(['social.platforms' => [
        'facebook' => ['client_id' => 'fb-id', 'client_secret' => 'fb-secret', 'api_version' => 'v23.0'],
        'linkedin' => ['client_id' => 'li-id', 'client_secret' => 'li-secret', 'api_version' => '202510'],
        'x' => ['client_id' => 'x-id', 'client_secret' => 'x-secret'],
    ]]);
    $this->credentials = new AccountCredentials('acct-1', 'live-access-token');
});

it('reports a platform as not configured, with the reason, until its app credentials are set', function () {
    config(['social.platforms.facebook.client_secret' => null]);

    $availability = app(FacebookPagesPlatform::class)->availability();

    expect($availability->available)->toBeFalse()
        ->and($availability->code)->toBe(PlatformAvailability::NOT_CONFIGURED)
        ->and($availability->reason)->toContain('app review');
    expect(app(LinkedInPlatform::class)->availability()->available)->toBeTrue();
});

it('lists unbuilt platforms as unavailable and refuses every call instead of simulating', function () {
    $platforms = app(SocialPlatforms::class);

    foreach (['instagram', 'youtube', 'tiktok', 'pinterest'] as $key) {
        $platform = $platforms->get($key);

        expect($platform->availability()->available)->toBeFalse()
            ->and($platform->availability()->code)->toBe(PlatformAvailability::NOT_IMPLEMENTED)
            ->and($platform->availability()->reason)->not->toBeEmpty()
            ->and($platform->capabilities()->publish)->toBeFalse()
            ->and($platform->capabilities()->analytics)->toBeFalse()
            ->and(fn () => $platform->publish($this->credentials, new PublishPayload('x')))->toThrow(RuntimeException::class);
    }
});

it('declares capabilities that match what each adapter really implements', function () {
    $facebook = app(FacebookPagesPlatform::class)->capabilities();
    $linkedin = app(LinkedInPlatform::class)->capabilities();
    $x = app(XPlatform::class)->capabilities();

    expect($facebook->publish)->toBeTrue()->and($facebook->images)->toBeTrue()->and($facebook->video)->toBeTrue()
        ->and($facebook->maxMedia)->toBe(10)
        ->and($facebook->analytics)->toBeTrue()->and($facebook->comments)->toBeTrue()
        ->and($linkedin->publish)->toBeTrue()->and($linkedin->images)->toBeFalse()->and($linkedin->analytics)->toBeFalse()
        ->and($x->publish)->toBeTrue()->and($x->maxText)->toBe(280);
});

it('reports no Facebook video capability while the operator has not enabled video publishing', function () {
    config(['social.platforms.facebook.video_publishing' => false]);
    $facebook = app(FacebookPagesPlatform::class);

    expect($facebook->capabilities()->video)->toBeFalse()
        ->and(array_map(fn ($type) => $type->value, $facebook->postTypes()))->toBe(['post', 'photos']);
});

it('connects Facebook pages through the documented token and accounts calls', function () {
    freshHttp();
    Http::fake([
        'graph.facebook.com/v23.0/oauth/access_token' => Http::sequence()
            ->push(['access_token' => 'short-user', 'token_type' => 'bearer'])
            ->push(['access_token' => 'long-user', 'token_type' => 'bearer', 'expires_in' => 5000000]),
        'graph.facebook.com/v23.0/me/accounts*' => Http::response(['data' => [
            ['id' => '111', 'name' => 'Page One', 'access_token' => 'page-token-1', 'picture' => ['data' => ['url' => 'https://img.test/1.jpg']]],
            ['id' => '222', 'name' => 'Page Two', 'access_token' => 'page-token-2'],
        ]]),
    ]);

    $profiles = app(FacebookPagesPlatform::class)->completeAuthorization('the-code', 'https://ws.test/cb', null);

    expect($profiles)->toHaveCount(2)
        ->and($profiles[0]->externalId)->toBe('111')
        ->and($profiles[0]->tokens->accessToken)->toBe('page-token-1')
        ->and($profiles[0]->tokens->expiresAt)->toBeNull()
        ->and($profiles[0]->avatarUrl)->toBe('https://img.test/1.jpg');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'oauth/access_token')
        && ($request['fb_exchange_token'] ?? null) === 'short-user' && $request['grant_type'] === 'fb_exchange_token');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'me/accounts')
        && $request->header('Authorization') === ['Bearer long-user']);
});

it('publishes a Facebook text post with link to the page feed using the page token', function () {
    freshHttp();
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '111_999'])]);

    $published = app(FacebookPagesPlatform::class)->publish(
        $this->credentials,
        new PublishPayload('Hello page', 'https://example.com/a'),
    );

    expect($published->externalId)->toBe('111_999')->and($published->url)->toBe('https://www.facebook.com/111_999');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v23.0/acct-1/feed'
        && $request['message'] === 'Hello page' && $request['link'] === 'https://example.com/a'
        && $request->header('Authorization') === ['Bearer live-access-token']);
});

it('publishes a Facebook photo with the file as multipart source', function () {
    freshHttp();
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'photo-1', 'post_id' => '111_555'])]);
    $media = new MediaFile('cat.png', 'image/png', false, null, fn (): string => 'PNGBYTES');

    $published = app(FacebookPagesPlatform::class)->publish($this->credentials, new PublishPayload('A cat', null, [$media]));

    expect($published->externalId)->toBe('111_555');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v23.0/acct-1/photos'
        && $request->isMultipart());
});

it('maps Facebook errors to reauthorization, retryable, unknown-outcome and rejected', function () {
    $publish = fn () => app(FacebookPagesPlatform::class)->publish($this->credentials, new PublishPayload('x'));
    $failure = function (array $body, int $status = 400) use ($publish): PublishFailed {
        freshHttp();
        Http::fake(['graph.facebook.com/*' => Http::response($body, $status)]);

        try {
            $publish();
        } catch (PublishFailed $exception) {
            return $exception;
        }

        throw new RuntimeException('Expected a failure.');
    };

    $expired = $failure(['error' => ['code' => 190, 'message' => 'Error validating access token']], 401);
    expect($expired->reauthorize)->toBeTrue()->and($expired->retryable)->toBeFalse();

    $limited = $failure(['error' => ['code' => 4, 'message' => 'Application request limit reached']]);
    expect($limited->retryable)->toBeTrue()->and($limited->errorCode)->toBe('rate_limited');

    $down = $failure(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 500);
    expect($down->outcomeUnknown)->toBeTrue()->and($down->retryable)->toBeFalse();

    $bad = $failure(['error' => ['code' => 100, 'message' => 'Invalid parameter']]);
    expect($bad->retryable)->toBeFalse()->and($bad->outcomeUnknown)->toBeFalse()->and($bad->reauthorize)->toBeFalse();
});

it('reads Facebook engagement and returns only the figures it got', function () {
    freshHttp();
    Http::fake(['graph.facebook.com/*' => Http::response([
        'id' => '111_999',
        'likes' => ['summary' => ['total_count' => 12]],
        'comments' => ['summary' => ['total_count' => 3]],
    ])]);

    $metrics = app(FacebookPagesPlatform::class)->metrics($this->credentials, '111_999');

    expect($metrics?->likes)->toBe(12)->and($metrics?->comments)->toBe(3)
        ->and($metrics?->shares)->toBeNull()->and($metrics?->impressions)->toBeNull();

    freshHttp();
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '111_999'])]);
    expect(app(FacebookPagesPlatform::class)->metrics($this->credentials, '111_999'))->toBeNull();
});

it('publishes a LinkedIn text post with the versioned headers and reads the id from the header', function () {
    freshHttp();
    Http::fake(['api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:7001'])]);

    $published = app(LinkedInPlatform::class)->publish($this->credentials, new PublishPayload('Hello (world) #launch', 'https://example.com'));

    expect($published->externalId)->toBe('urn:li:share:7001')
        ->and($published->url)->toBe('https://www.linkedin.com/feed/update/urn:li:share:7001');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.linkedin.com/rest/posts'
            && $request->header('LinkedIn-Version') === ['202510']
            && $request->header('X-Restli-Protocol-Version') === ['2.0.0']
            && $request->header('Authorization') === ['Bearer live-access-token']
            && $body['author'] === 'urn:li:person:acct-1'
            && $body['lifecycleState'] === 'PUBLISHED'
            && $body['visibility'] === 'PUBLIC'
            && str_contains($body['commentary'], 'Hello \\(world\\) #launch')
            && str_contains($body['commentary'], 'https://example.com');
    });
});

it('escapes LinkedIn reserved characters but keeps hashtags', function () {
    expect(LinkedInPlatform::escape('a_b *bold* [x] (y) <z> {w} @me | ~ \\ #tag # alone'))
        ->toBe('a\\_b \\*bold\\* \\[x\\] \\(y\\) \\<z\\> \\{w\\} \\@me \\| \\~ \\\\ #tag \\# alone');
});

it('refuses media on LinkedIn and X instead of dropping it silently', function () {
    $media = new MediaFile('a.png', 'image/png', false, null, fn (): string => 'x');

    foreach ([LinkedInPlatform::class, XPlatform::class] as $class) {
        freshHttp();
        Http::fake();

        expect(fn () => app($class)->publish($this->credentials, new PublishPayload('x', null, [$media])))
            ->toThrow(PublishFailed::class);
        Http::assertNothingSent();
    }
});

it('maps LinkedIn failures', function () {
    $try = function (int $status): PublishFailed {
        freshHttp();
        Http::fake(['api.linkedin.com/*' => Http::response(['message' => 'nope'], $status)]);

        try {
            app(LinkedInPlatform::class)->publish($this->credentials, new PublishPayload('x'));
        } catch (PublishFailed $exception) {
            return $exception;
        }

        throw new RuntimeException('Expected a failure.');
    };

    expect($try(401)->reauthorize)->toBeTrue()
        ->and($try(429)->retryable)->toBeTrue()
        ->and($try(503)->outcomeUnknown)->toBeTrue()
        ->and($try(422)->retryable)->toBeFalse();
});

it('exchanges a LinkedIn code and reads the member id from userinfo', function () {
    freshHttp();
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'li-access', 'expires_in' => 5184000, 'refresh_token' => 'li-refresh',
            'refresh_token_expires_in' => 31536000, 'scope' => 'openid,profile,w_member_social',
        ]),
        'api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'member-77', 'name' => 'Jo Writer', 'picture' => 'https://img.test/p.jpg']),
    ]);

    $profiles = app(LinkedInPlatform::class)->completeAuthorization('code', 'https://ws.test/cb', null);

    expect($profiles)->toHaveCount(1)
        ->and($profiles[0]->externalId)->toBe('member-77')
        ->and($profiles[0]->tokens->refreshToken)->toBe('li-refresh')
        ->and($profiles[0]->tokens->expiresAt?->isFuture())->toBeTrue()
        ->and($profiles[0]->tokens->scopes)->toBe(['openid', 'profile', 'w_member_social']);
});

it('builds an X authorization URL with a PKCE S256 challenge that matches the verifier', function () {
    $request = app(XPlatform::class)->authorizationRequest('https://ws.test/cb', 'state-1');
    parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);

    expect($request->codeVerifier)->not->toBeNull()
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', (string) $request->codeVerifier, true)), '+/', '-_'), '='))
        ->and($query['state'])->toBe('state-1')
        ->and($query['scope'])->toContain('tweet.write')
        ->and($query['client_id'])->toBe('x-id');
});

it('publishes an X post, refreshes a token and respects rate limit resets', function () {
    freshHttp();
    Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => '9001', 'text' => 'hi']], 201)]);

    $published = app(XPlatform::class)->publish($this->credentials, new PublishPayload('hi'));
    expect($published->externalId)->toBe('9001');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.x.com/2/tweets' && $request['text'] === 'hi');

    freshHttp();
    Http::fake(['api.x.com/2/oauth2/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 7200])]);
    $tokens = app(XPlatform::class)->refresh('old-refresh');
    expect($tokens->accessToken)->toBe('new-access')->and($tokens->refreshToken)->toBe('new-refresh');
    Http::assertSent(fn (Request $request): bool => $request['grant_type'] === 'refresh_token'
        && str_starts_with((string) $request->header('Authorization')[0], 'Basic '));

    freshHttp();
    Http::fake(['api.x.com/2/tweets' => Http::response(['title' => 'Too Many Requests'], 429, ['x-rate-limit-reset' => (string) (time() + 120)])]);

    try {
        app(XPlatform::class)->publish($this->credentials, new PublishPayload('hi'));
        $failure = null;
    } catch (PublishFailed $exception) {
        $failure = $exception;
    }

    expect($failure?->retryable)->toBeTrue()->and($failure?->retryAfterSeconds)->toBeGreaterThan(60);

    freshHttp();
    Http::fake(['api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_request', 'error_description' => 'Value passed for the token was invalid.'], 400)]);
    expect(fn () => app(XPlatform::class)->refresh('revoked'))->toThrow(ReauthorizationRequired::class);
});

it('counts X links as 23 characters', function () {
    $x = app(XPlatform::class);

    expect($x->textLength('Read https://example.com/a/very/long/path?with=query&and=more now'))->toBe(5 + 23 + 4);
});

it('treats a connection that never opened as retryable and a lost response as unknown', function () {
    freshHttp();
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: api.linkedin.com'));
    try {
        app(LinkedInPlatform::class)->publish($this->credentials, new PublishPayload('x'));
    } catch (PublishFailed $exception) {
        expect($exception->retryable)->toBeTrue()->and($exception->outcomeUnknown)->toBeFalse();
    }

    freshHttp();
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 20000 milliseconds'));
    try {
        app(LinkedInPlatform::class)->publish($this->credentials, new PublishPayload('x'));
    } catch (PublishFailed $exception) {
        expect($exception->retryable)->toBeFalse()->and($exception->outcomeUnknown)->toBeTrue();
    }
});

it('never puts a token in an exception message, var_dump, JSON or serialisation', function () {
    $tokens = new TokenSet('super-secret-access', 'super-secret-refresh');
    $credentials = new AccountCredentials('x', 'super-secret-access');

    ob_start();
    var_dump($tokens, $credentials);
    $dump = (string) ob_get_clean();

    foreach ([$dump, json_encode($tokens), serialize($tokens), serialize($credentials), print_r($tokens, true)] as $text) {
        expect($text)->not->toContain('super-secret');
    }

    freshHttp();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 100, 'message' => 'bad']], 400)]);

    try {
        app(FacebookPagesPlatform::class)->publish($credentials, new PublishPayload('x'));
    } catch (PublishFailed $exception) {
        expect($exception->getMessage())->not->toContain('super-secret');
    }
});
