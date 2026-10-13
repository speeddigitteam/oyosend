<?php

declare(strict_types=1);

use App\Modules\Ai\Actions\AiAvailability;
use App\Modules\Ai\Actions\AiGateway;
use App\Modules\Ai\Enums\AiDriver;
use App\Modules\Ai\Enums\Feature;
use App\Modules\Ai\Models\AiProviderAccount;
use App\Modules\Ai\Providers\ProviderManager;
use App\Modules\Ai\Support\AiRefusal;
use App\Modules\Ai\Support\CircuitBreaker;
use App\Modules\Ai\Support\CompletionRequest;
use App\Platform\Enums\PlatformRole;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithAi;
use Tests\Support\InteractsWithBilling;

uses(InteractsWithAi::class, InteractsWithBilling::class);

const AI_PROVIDERS_URL = 'http://admin.localhost/ai/providers';

beforeEach(function () {
    $this->admin = $this->createPlatformUser();
    app('cache.central')->flush();
    config(['ai.default' => 'anthropic', 'ai.providers.anthropic.api_key' => null]);
});

function aiProviderAccount(array $attributes = []): AiProviderAccount
{
    return AiProviderAccount::query()->create([
        'driver' => AiDriver::OpenAiCompatible,
        'name' => 'Gateway '.bin2hex(random_bytes(3)),
        'model' => 'gpt-test',
        'base_url' => 'https://llm.example.test/v1',
        'is_enabled' => true,
        'is_default' => false,
        'secrets' => ['api_key' => 'sk-stored-secret'],
        ...$attributes,
    ]);
}

function aiChatCompletion(string $text = 'Hello from the gateway.'): array
{
    return [
        'model' => 'gpt-test',
        'choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 4],
    ];
}

describe('managing accounts', function () {
    it('lists accounts without ever exposing a key', function () {
        aiProviderAccount(['name' => 'Primary', 'is_default' => true, 'secrets' => ['api_key' => 'sk-list-secret-1']]);

        $response = $this->actingAsPlatformUser($this->admin)->get(AI_PROVIDERS_URL)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Pages/Ai/Providers')
                ->has('accounts', 1)
                ->where('accounts.0.name', 'Primary')
                ->where('accounts.0.hasKey', true)
                ->where('accounts.0.isDefault', true)
                ->missing('accounts.0.secrets')
                ->missing('accounts.0.api_key')
                ->where('usesEnvironment', false));

        expect($response->getContent())->not->toContain('sk-list-secret-1');
    });

    it('creates, updates and deletes an account; the first usable one becomes the default', function () {
        $as = $this->actingAsPlatformUser($this->admin);

        $as->post(AI_PROVIDERS_URL, [
            'driver' => 'anthropic', 'name' => 'Claude', 'model' => 'claude-sonnet-5-5', 'api_key' => 'sk-ant-create-1',
        ])->assertRedirect();

        $claude = AiProviderAccount::query()->where('name', 'Claude')->firstOrFail();
        expect($claude->is_default)->toBeTrue()
            ->and($claude->secrets)->toBe(['api_key' => 'sk-ant-create-1']);

        $as->post(AI_PROVIDERS_URL, [
            'driver' => 'openai_compatible', 'name' => 'Local', 'model' => 'llama3', 'base_url' => 'http://localhost:11434/v1/',
        ])->assertRedirect();

        $local = AiProviderAccount::query()->where('name', 'Local')->firstOrFail();
        expect($local->is_default)->toBeFalse()
            ->and($local->base_url)->toBe('http://localhost:11434/v1')
            ->and($local->secrets)->toBeNull();

        $as->put(AI_PROVIDERS_URL.'/'.$local->id, ['name' => 'Local LLM', 'model' => 'llama3.1', 'base_url' => 'http://localhost:11434/v1'])
            ->assertRedirect();
        expect($local->refresh()->name)->toBe('Local LLM')->and($local->model)->toBe('llama3.1');

        $as->delete(AI_PROVIDERS_URL.'/'.$local->id)->assertRedirect();
        expect(AiProviderAccount::query()->count())->toBe(1);
    });

    it('validates the driver specific requirements and unique names', function () {
        $as = $this->actingAsPlatformUser($this->admin);

        $as->post(AI_PROVIDERS_URL, ['driver' => 'anthropic', 'name' => 'No key', 'model' => 'm'])
            ->assertSessionHasErrors('api_key');
        $as->post(AI_PROVIDERS_URL, ['driver' => 'openai_compatible', 'name' => 'No url', 'model' => 'm'])
            ->assertSessionHasErrors('base_url');
        $as->post(AI_PROVIDERS_URL, ['driver' => 'openai_compatible', 'name' => 'Bad url', 'model' => 'm', 'base_url' => 'ftp://x'])
            ->assertSessionHasErrors('base_url');
        $as->post(AI_PROVIDERS_URL, ['driver' => 'nope', 'name' => 'X', 'model' => 'm'])
            ->assertSessionHasErrors('driver');

        aiProviderAccount(['name' => 'Taken']);
        $as->post(AI_PROVIDERS_URL, ['driver' => 'openai_compatible', 'name' => 'Taken', 'model' => 'm', 'base_url' => 'https://x.test/v1'])
            ->assertSessionHasErrors('name');
    });

    it('keeps the stored key when the key is empty on update and replaces it when given', function () {
        $account = aiProviderAccount(['secrets' => ['api_key' => 'sk-keep-me']]);
        $as = $this->actingAsPlatformUser($this->admin);

        $as->put(AI_PROVIDERS_URL.'/'.$account->id, [
            'name' => $account->name, 'model' => 'gpt-new', 'base_url' => $account->base_url, 'api_key' => '',
        ])->assertRedirect();
        expect($account->refresh()->secrets)->toBe(['api_key' => 'sk-keep-me'])->and($account->model)->toBe('gpt-new');

        $as->put(AI_PROVIDERS_URL.'/'.$account->id, [
            'name' => $account->name, 'model' => 'gpt-new', 'base_url' => $account->base_url, 'api_key' => 'sk-replaced',
        ])->assertRedirect();
        expect($account->refresh()->secrets)->toBe(['api_key' => 'sk-replaced']);
    });

    it('stores the key encrypted and leaves it out of audit rows, logs and serialization', function () {
        $secret = 'sk-never-in-audit-9f3';

        $this->actingAsPlatformUser($this->admin)->post(AI_PROVIDERS_URL, [
            'driver' => 'anthropic', 'name' => 'Audited', 'model' => 'claude-sonnet-5-5', 'api_key' => $secret,
        ])->assertRedirect();

        $account = AiProviderAccount::query()->where('name', 'Audited')->firstOrFail();
        $raw = (string) DB::table('ai_provider_accounts')->where('id', $account->id)->value('secrets');

        expect($raw)->not->toBe('')->and($raw)->not->toContain($secret)
            ->and(json_encode($account->toArray()))->not->toContain($secret)
            ->and(array_key_exists('secrets', $account->toArray()))->toBeFalse();

        $audit = json_encode(DB::table('audit_logs')->where('event', 'like', 'platform.ai_provider.%')->get()->all());
        expect($audit)->toContain('platform.ai_provider.created')->and($audit)->not->toContain($secret);

    });

    it('switches the default, refuses a disabled account and drops the flag when the default is disabled', function () {
        $first = aiProviderAccount(['name' => 'First', 'is_default' => true]);
        $second = aiProviderAccount(['name' => 'Second']);
        $disabled = aiProviderAccount(['name' => 'Off', 'is_enabled' => false]);
        $as = $this->actingAsPlatformUser($this->admin);

        $as->post(AI_PROVIDERS_URL.'/'.$second->id.'/default')->assertRedirect();
        expect($first->refresh()->is_default)->toBeFalse()->and($second->refresh()->is_default)->toBeTrue()
            ->and(AiProviderAccount::query()->where('is_default', true)->count())->toBe(1);

        $as->post(AI_PROVIDERS_URL.'/'.$disabled->id.'/default')->assertSessionHasErrors('account');
        expect($disabled->refresh()->is_default)->toBeFalse();

        $as->put(AI_PROVIDERS_URL.'/'.$second->id, ['name' => 'Second', 'model' => 'gpt-test', 'base_url' => $second->base_url, 'is_enabled' => false])
            ->assertRedirect();
        expect($second->refresh()->is_default)->toBeFalse();
    });

    it('answers 404 for an unknown account', function () {
        $this->actingAsPlatformUser($this->admin)->delete(AI_PROVIDERS_URL.'/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
        $this->actingAsPlatformUser($this->admin)->post(AI_PROVIDERS_URL.'/01ARZ3NDEKTSV4RRFFQ69G5FAV/test')->assertNotFound();
    });
});

describe('who may manage accounts', function () {
    it('is forbidden for a platform role without settings.manage', function () {
        $support = $this->createPlatformUser(PlatformRole::SupportAdmin);
        $account = aiProviderAccount();

        $as = $this->actingAsPlatformUser($support);
        $as->get(AI_PROVIDERS_URL)->assertForbidden();
        $as->post(AI_PROVIDERS_URL, ['driver' => 'openai_compatible', 'name' => 'x', 'model' => 'm', 'base_url' => 'https://x.test/v1'])->assertForbidden();
        $as->put(AI_PROVIDERS_URL.'/'.$account->id, ['name' => 'x', 'model' => 'm'])->assertForbidden();
        $as->delete(AI_PROVIDERS_URL.'/'.$account->id)->assertForbidden();
        $as->post(AI_PROVIDERS_URL.'/'.$account->id.'/test')->assertForbidden();
        $as->post(AI_PROVIDERS_URL.'/'.$account->id.'/default')->assertForbidden();
    });

    it('is not reachable by a workspace member or a guest', function () {
        $tenant = $this->aiTenant('ai-nonplatform');
        $account = aiProviderAccount();

        $this->get(AI_PROVIDERS_URL)->assertRedirect();
        $this->actingAsOwner($tenant)->get(AI_PROVIDERS_URL)->assertRedirect();
        $this->actingAsOwner($tenant)->delete(AI_PROVIDERS_URL.'/'.$account->id)->assertRedirect();

        expect(AiProviderAccount::query()->count())->toBe(1);
    });
});

describe('test connection', function () {
    it('makes one minimal call and reports ok', function () {
        Http::fake(['llm.example.test/*' => Http::response(aiChatCompletion('ok'))]);
        $account = aiProviderAccount();

        $this->actingAsPlatformUser($this->admin)->post(AI_PROVIDERS_URL.'/'.$account->id.'/test')->assertRedirect();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://llm.example.test/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-stored-secret')
            && $request['max_tokens'] === 8);
        expect($account->refresh()->last_test_status)->toBe('ok')->and($account->last_test_reason)->toBeNull();
    });

    it('reports a safe reason code and never the provider text or the key', function () {
        Http::fake(['llm.example.test/*' => Http::response(['error' => ['message' => 'bad key sk-stored-secret']], 401)]);
        $account = aiProviderAccount();

        $response = $this->actingAsPlatformUser($this->admin)->post(AI_PROVIDERS_URL.'/'.$account->id.'/test');
        $response->assertRedirect();

        expect($account->refresh()->last_test_status)->toBe('failed')->and($account->last_test_reason)->toBe('unauthorized');

        $audit = json_encode(DB::table('audit_logs')->where('event', 'platform.ai_provider.tested')->get()->all());
        expect($audit)->toContain('unauthorized')->and($audit)->not->toContain('sk-stored-secret');

        $page = $this->actingAsPlatformUser($this->admin)->get(AI_PROVIDERS_URL)
            ->assertInertia(fn (AssertableInertia $p) => $p->where('accounts.0.lastTest.reason', 'unauthorized'));
        expect($page->getContent())->not->toContain('sk-stored-secret')->not->toContain('bad key');
    });

    it('reports unreachable when the connection fails and not_configured without an endpoint', function () {
        Http::fake(fn () => throw new ConnectionException('boom sk-stored-secret'));
        $account = aiProviderAccount();

        $this->actingAsPlatformUser($this->admin)->post(AI_PROVIDERS_URL.'/'.$account->id.'/test');
        expect($account->refresh()->last_test_reason)->toBe('unreachable');

        $broken = aiProviderAccount(['base_url' => null]);
        $this->actingAsPlatformUser($this->admin)->post(AI_PROVIDERS_URL.'/'.$broken->id.'/test');
        expect($broken->refresh()->last_test_reason)->toBe('not_configured');
    });
});

describe('the gateway uses the default account', function () {
    it('answers through the default OpenAI-compatible account and meters the call', function () {
        Http::fake(['llm.example.test/*' => Http::response(aiChatCompletion('Hi from account'))]);
        aiProviderAccount(['name' => 'Other', 'base_url' => 'https://other.example.test/v1']);
        aiProviderAccount(['name' => 'Chosen', 'is_default' => true]);
        $tenant = $this->aiTenant('ai-default');

        $response = $this->inTenant($tenant, fn () => app(AiGateway::class)->complete(
            Feature::Drafting,
            new CompletionRequest('System', [['role' => 'user', 'content' => 'Say hi']]),
            'ai:test:default-account',
        ));

        expect($response->text())->toBe('Hi from account')
            ->and($response->provider)->toBe('openai_compatible')
            ->and($response->totalTokens())->toBe(15);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://llm.example.test/v1/chat/completions')
            && $request['model'] === 'gpt-test'
            && $request['messages'][0] === ['role' => 'system', 'content' => 'System']
            && $request['messages'][1] === ['role' => 'user', 'content' => 'Say hi']);
    });

    it('sends the Anthropic request with the account key and model', function () {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-x', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Account answer']],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
        ])]);
        aiProviderAccount([
            'driver' => AiDriver::Anthropic, 'name' => 'Claude', 'model' => 'claude-x', 'base_url' => null,
            'is_default' => true, 'secrets' => ['api_key' => 'sk-ant-account'],
        ]);
        $tenant = $this->aiTenant('ai-anthropic-acct');

        $response = $this->inTenant($tenant, fn () => app(AiGateway::class)->complete(
            Feature::Drafting, new CompletionRequest('S', [['role' => 'user', 'content' => 'hi']]), 'ai:test:anthropic-account',
        ));

        expect($response->text())->toBe('Account answer');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'sk-ant-account')
            && $request['model'] === 'claude-x');
    });

    it('translates tool use for the OpenAI-compatible API in both directions', function () {
        Http::fake(['llm.example.test/*' => Http::response([
            'model' => 'gpt-test',
            'choices' => [['message' => ['content' => null, 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'contacts_search', 'arguments' => '{"query":"Rima"}']],
            ]], 'finish_reason' => 'tool_calls']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ])]);
        $provider = app(ProviderManager::class)->forAccount(aiProviderAccount());

        $response = $provider->complete(new CompletionRequest('S', [
            ['role' => 'user', 'content' => 'find'],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'call_0', 'name' => 'contacts_search', 'input' => []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'call_0', 'content' => '{"ok":true}']]],
        ], [['name' => 'contacts_search', 'description' => 'd', 'input_schema' => ['type' => 'object']]], 64));

        expect($response->toolUses())->toBe([['id' => 'call_1', 'name' => 'contacts_search', 'input' => ['query' => 'Rima']]])
            ->and($response->stopReason)->toBe('tool_use');

        Http::assertSent(function ($request): bool {
            $messages = $request['messages'];

            return $messages[2]['tool_calls'][0]['function']['arguments'] === '{}'
                && $messages[3] === ['role' => 'tool', 'tool_call_id' => 'call_0', 'content' => '{"ok":true}']
                && $request['tools'][0]['function']['name'] === 'contacts_search';
        });
    });

    it('falls back to the environment when no account exists', function () {
        config(['ai.providers.anthropic.api_key' => 'sk-ant-env', 'ai.providers.anthropic.model' => 'env-model']);

        $provider = app(ProviderManager::class)->provider();

        expect($provider->name())->toBe('anthropic')->and($provider->model())->toBe('env-model')->and($provider->isConfigured())->toBeTrue();
    });

    it('is honestly not configured when accounts exist but none is an enabled default', function () {
        config(['ai.providers.anthropic.api_key' => 'sk-ant-env']);
        Http::fake();
        aiProviderAccount(['is_default' => true, 'is_enabled' => false]);
        aiProviderAccount(['name' => 'Spare']);
        $tenant = $this->aiTenant('ai-nodefault');

        expect(app(ProviderManager::class)->provider()->isConfigured())->toBeFalse();

        $state = $this->inTenant($tenant, fn () => app(AiAvailability::class)->describe());
        expect($state['state'])->toBe('not_configured')->and($state['configured'])->toBeFalse();

        $this->inTenant($tenant, fn () => app(AiGateway::class)->complete(
            Feature::Drafting, new CompletionRequest('S', [['role' => 'user', 'content' => 'hi']]), 'ai:test:none',
        ));
    })->throws(AiRefusal::class);

    it('keeps the circuit breaker working for the account and resets it when the default changes', function () {
        Http::fake(['llm.example.test/*' => Http::response([], 500)]);
        config(['ai.circuit_breaker.failures' => 2]);
        $first = aiProviderAccount(['name' => 'Flaky', 'is_default' => true]);
        $second = aiProviderAccount(['name' => 'Backup', 'base_url' => 'https://backup.example.test/v1']);
        $tenant = $this->aiTenant('ai-breaker');

        foreach ([1, 2] as $n) {
            try {
                $this->inTenant($tenant, fn () => app(AiGateway::class)->complete(
                    Feature::Drafting, new CompletionRequest('S', [['role' => 'user', 'content' => 'hi']]), 'ai:test:fail:'.$n,
                ));
            } catch (AiRefusal) {
            }
        }

        expect(app(CircuitBreaker::class)->isOpen())->toBeTrue();

        $this->actingAsPlatformUser($this->admin)->post(AI_PROVIDERS_URL.'/'.$second->id.'/default')->assertRedirect();

        expect(app(CircuitBreaker::class)->isOpen())->toBeFalse()
            ->and($first->refresh()->is_default)->toBeFalse();
    });
});
