<?php

declare(strict_types=1);

use App\Core\Billing\Models\UsageRecord;
use App\Core\Billing\UsageMeter;
use App\Modules\Ai\Actions\AiSettings;
use App\Modules\Ai\Models\Message;
use App\Modules\Ai\Providers\ProviderManager;
use App\Modules\Ai\Support\CircuitBreaker;
use App\Modules\Ai\Support\ProviderFailure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\InteractsWithAi;
use Tests\Support\InteractsWithBilling;

uses(InteractsWithAi::class, InteractsWithBilling::class);

beforeEach(function () {
    $this->tenant = $this->aiTenant('ai-guard');
    app('cache.central')->flush();
});

it('reports not_configured honestly and calls nothing when there is no platform credential', function () {
    Http::fake();
    config(['ai.default' => 'anthropic', 'ai.providers.anthropic.api_key' => null]);

    $this->actingAsOwner($this->tenant)
        ->get($this->aiUrl($this->tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Modules/Ai/Pages/Assistant/Index')
            ->where('availability.state', 'not_configured')
            ->where('availability.configured', false));

    $this->postJson($this->aiUrl($this->tenant, '/conversations'), ['content' => 'Hello'])
        ->assertStatus(503)
        ->assertJsonPath('state', 'not_configured');

    $this->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => 'Hello'])
        ->assertStatus(503)
        ->assertJsonPath('state', 'not_configured');

    Http::assertNothingSent();
    expect($this->inTenant($this->tenant, fn () => Message::query()->count()))->toBe(0);
});

it('never honours the fake provider outside local and testing', function () {
    config(['ai.default' => 'fake', 'ai.providers.anthropic.api_key' => null]);
    $this->app->detectEnvironment(fn (): string => 'production');

    $provider = app(ProviderManager::class)->provider();

    expect($provider->name())->toBe('anthropic')->and($provider->isConfigured())->toBeFalse();
});

it('keeps the platform credential out of responses, pages, logs and audit even when the provider fails', function () {
    $secret = 'sk-ant-super-secret-credential-123';
    config(['ai.default' => 'anthropic', 'ai.providers.anthropic.api_key' => $secret]);
    Log::spy();
    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'bad key '.$secret]], 401)]);

    $owner = $this->actingAsOwner($this->tenant);

    $page = $owner->get($this->aiUrl($this->tenant))->assertOk();
    $failed = $owner->postJson($this->aiUrl($this->tenant, '/conversations'), ['content' => 'Hello'])->assertStatus(503);
    $settings = $owner->get($this->aiUrl($this->tenant, '/settings'))->assertOk();

    foreach ([$page->getContent(), $failed->getContent(), $settings->getContent()] as $body) {
        expect($body)->not->toContain($secret);
    }

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) use ($secret): bool {
        return $message === 'ai.provider.failed' && ! str_contains(json_encode($context), $secret);
    });
    Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', $secret));
});

it('sends the Anthropic request in the documented shape with the credential only in a header', function () {
    config(['ai.default' => 'anthropic', 'ai.providers.anthropic.api_key' => 'sk-ant-test', 'ai.providers.anthropic.model' => 'claude-sonnet-5-5']);
    Http::fake(['api.anthropic.com/*' => Http::response([
        'id' => 'msg_1', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => 'Hello there']],
        'usage' => ['input_tokens' => 12, 'output_tokens' => 7],
    ])]);

    $this->actingAsOwner($this->tenant)
        ->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => 'hi', 'tone' => 'formal'])
        ->assertOk()
        ->assertJsonPath('text', 'Hello there')
        ->assertJsonPath('ai_generated', true)
        ->assertJsonPath('usage.input_tokens', 12);

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'sk-ant-test')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $body['model'] === 'claude-sonnet-5-5'
            && $body['max_tokens'] === 1024
            && ! array_key_exists('tools', $body)
            && ! str_contains(json_encode($body), 'sk-ant-test');
    });
});

it('meters tokens once per provider call and ignores a repeated idempotency key', function () {
    $fake = $this->fakeAi();
    $fake->pushText('One', 100, 20)->pushText('Two', 30, 10);

    $owner = $this->actingAsOwner($this->tenant);
    $owner->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => 'a'])->assertOk();
    $owner->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => 'b'])->assertOk();

    $used = $this->inTenant($this->tenant, fn () => app(UsageMeter::class)->used('ai.tokens'));
    expect($used)->toBe(160);

    // Recording the same event again (a retried job) counts nothing.
    $recorded = $this->inTenant($this->tenant, function () {
        $record = UsageRecord::query()->where('meter', 'ai.tokens')->firstOrFail();

        return app(UsageMeter::class)->record('ai.tokens', 999, $record->idempotency_key);
    });
    expect($recorded)->toBeFalse()
        ->and($this->inTenant($this->tenant, fn () => app(UsageMeter::class)->used('ai.tokens')))->toBe(160);
});

it('stops at the plan token limit and says so', function () {
    $plan = $this->createPlan(['limits' => ['ai.tokens' => 100]]);
    $this->subscribeAndPay($this->tenant, $plan);
    $fake = $this->fakeAi();
    $fake->pushText('Big answer', 80, 30)->pushText('Never reached');

    $owner = $this->actingAsOwner($this->tenant);
    // The call that crosses the cap is allowed (its cost is unknown beforehand) ...
    $owner->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => 'a'])->assertOk();

    // ... the next one is refused before the provider is called.
    $owner->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => 'b'])
        ->assertForbidden()
        ->assertJsonPath('state', 'limit_reached');

    expect($fake->requests)->toHaveCount(1);
});

it('clamps the output tokens of every call to the configured maximum', function () {
    config(['ai.limits.max_output_tokens' => 256]);
    $fake = $this->fakeAi();

    $this->actingAsOwner($this->tenant)
        ->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => 'a'])->assertOk();

    expect($fake->requests[0]->maxTokens)->toBe(256);
});

it('rate limits per member and answers 429 before calling the provider', function () {
    config(['ai.limits.member_per_minute' => 2, 'ai.limits.tenant_per_minute' => 100]);
    $fake = $this->fakeAi();
    $owner = $this->actingAsOwner($this->tenant);

    $owner->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => 'a'])->assertOk();
    $owner->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => 'b'])->assertOk();
    $owner->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => 'c'])
        ->assertStatus(429)
        ->assertJsonPath('state', 'rate_limited');

    expect($fake->requests)->toHaveCount(2);
});

it('opens the circuit breaker after repeated provider failures and stops calling', function () {
    config(['ai.circuit_breaker.failures' => 2]);
    $fake = $this->fakeAi();
    $fake->push(new ProviderFailure('down'))->push(new ProviderFailure('down'))->pushText('Would work');
    $owner = $this->actingAsOwner($this->tenant);

    $owner->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => 'a'])->assertStatus(503)->assertJsonPath('state', 'unavailable');
    $owner->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => 'b'])->assertStatus(503);

    expect(app(CircuitBreaker::class)->isOpen())->toBeTrue();

    $owner->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => 'c'])
        ->assertStatus(503)
        ->assertJsonPath('state', 'unavailable');

    expect($fake->requests)->toHaveCount(2);
});

it('refuses everything when the workspace switched AI off, and per feature', function () {
    $fake = $this->fakeAi();
    $owner = $this->actingAsOwner($this->tenant);

    $this->inTenant($this->tenant, fn () => app(AiSettings::class)->update(['drafting_enabled' => false]));
    $owner->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => 'a'])
        ->assertForbidden()->assertJsonPath('state', 'disabled');

    $this->inTenant($this->tenant, fn () => app(AiSettings::class)->update(['drafting_enabled' => true, 'enabled' => false]));
    $owner->postJson($this->aiUrl($this->tenant, '/conversations'), ['content' => 'hi'])
        ->assertForbidden()->assertJsonPath('state', 'disabled');

    expect($fake->requests)->toBe([]);
});

it('does not read a data scope that the workspace switched off', function () {
    $this->aiContact($this->tenant, ['first_name' => 'Scoped']);
    $fake = $this->fakeAi();
    $fake->pushText('No tools.');
    $this->inTenant($this->tenant, fn () => app(AiSettings::class)->update(['data_scopes' => ['companies']]));

    $this->actingAsOwner($this->tenant)
        ->post($this->aiUrl($this->tenant, '/conversations'), ['content' => 'Who is Scoped?'])->assertRedirect();

    $names = array_column($fake->requests[0]->tools, 'name');
    expect($names)->toContain('companies_search')->and($names)->not->toContain('contacts_search');
});

it('masks personal data before it reaches the provider only when redaction is on', function () {
    $fake = $this->fakeAi();
    $owner = $this->actingAsOwner($this->tenant);
    $text = 'Mail jane.doe@example.test or call +880 1711 223344 please';

    $owner->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => $text])->assertOk();
    expect($fake->requests[0]->messages[0]['content'])->toContain('jane.doe@example.test');

    $this->inTenant($this->tenant, fn () => app(AiSettings::class)->update(['redact_pii' => true]));
    $owner->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => $text])->assertOk();

    $sent = $fake->requests[1]->messages[0]['content'];
    expect($sent)->not->toContain('jane.doe@example.test')
        ->and($sent)->not->toContain('1711 223344')
        ->and($sent)->toContain('[email]')
        ->and($sent)->toContain('[phone]');
});

it('rejects an over-long message and text before anything is sent', function () {
    config(['ai.limits.max_input_chars' => 50]);
    $fake = $this->fakeAi();
    $owner = $this->actingAsOwner($this->tenant);

    $owner->postJson($this->aiUrl($this->tenant, '/conversations'), ['content' => str_repeat('a', 51)])->assertUnprocessable();
    $owner->postJson($this->aiUrl($this->tenant, '/drafts'), ['kind' => 'rewrite', 'text' => str_repeat('a', 51)])->assertUnprocessable();

    expect($fake->requests)->toBe([]);
});
