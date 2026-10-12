<?php

declare(strict_types=1);

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Contracts\AiProvider;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves the configured provider. `fake` is honoured only in `local` and `testing`; anywhere else it,
 * and any unknown name, resolves to the Anthropic provider, which reports `not_configured` without a key.
 */
final class ProviderManager
{
    public function __construct(private readonly Application $app) {}

    public function provider(): AiProvider
    {
        $name = (string) config('ai.default', 'anthropic');

        if ($name === 'fake' && $this->app->environment(['local', 'testing'])) {
            return $this->app->make(FakeAiProvider::class);
        }

        return $this->app->make(AnthropicProvider::class);
    }
}
