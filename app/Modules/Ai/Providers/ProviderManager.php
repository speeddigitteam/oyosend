<?php

declare(strict_types=1);

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Enums\AiDriver;
use App\Modules\Ai\Models\AiProviderAccount;
use App\Modules\Ai\Support\ProviderSettings;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Resolves the active provider.
 *
 * 1. The operator's default, enabled account (Super Admin > AI providers), when one exists. Its key is
 *    decrypted here, for the adapter that makes the call, and nowhere else.
 * 2. When the operator has added accounts but none is an enabled default, nothing is active: the honest
 *    `not_configured` state, never a silent fall back to the environment.
 * 3. When no account exists at all, the environment: `fake` only in `local` and `testing`; anything else, and
 *    any unknown name, is the Anthropic adapter, which reports `not_configured` without `ANTHROPIC_API_KEY`.
 */
final class ProviderManager
{
    public function __construct(
        private readonly Application $app,
        private readonly HttpFactory $http,
    ) {}

    public function provider(): AiProvider
    {
        $default = AiProviderAccount::query()->where('is_default', true)->where('is_enabled', true)->first();

        if ($default !== null) {
            return $this->forAccount($default);
        }

        if (AiProviderAccount::query()->exists()) {
            // Accounts exist but none is the enabled default: an adapter with no credential, so nothing is called.
            return AnthropicProvider::using($this->http, new ProviderSettings(AiDriver::Anthropic, '', '', ''));
        }

        $name = (string) config('ai.default', 'anthropic');

        if ($name === 'fake' && $this->app->environment(['local', 'testing'])) {
            return $this->app->make(FakeAiProvider::class);
        }

        return $this->app->make(AnthropicProvider::class);
    }

    /** The adapter for one account (also used by "test connection"). */
    public function forAccount(AiProviderAccount $account): AiProvider
    {
        $key = trim($account->secrets['api_key'] ?? '');
        $timeout = (int) config('ai.providers.anthropic.timeout', 60);
        $baseUrl = (string) ($account->base_url ?? '');

        return match ($account->driver) {
            AiDriver::Anthropic => AnthropicProvider::using($this->http, new ProviderSettings(
                AiDriver::Anthropic,
                $key,
                $account->model,
                $baseUrl,
                $timeout,
                (string) config('ai.providers.anthropic.version', '2023-06-01'),
            )),
            AiDriver::OpenAiCompatible => new OpenAiCompatibleProvider($this->http, new ProviderSettings(
                AiDriver::OpenAiCompatible,
                $key,
                $account->model,
                $baseUrl,
                $timeout,
            )),
        };
    }
}
