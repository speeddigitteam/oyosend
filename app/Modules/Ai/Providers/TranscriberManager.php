<?php

declare(strict_types=1);

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Contracts\Transcriber;
use App\Modules\Ai\Enums\AiDriver;
use App\Modules\Ai\Models\AiProviderAccount;
use App\Modules\Ai\Support\ProviderSettings;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Resolves who transcribes speech (ADR 0038), on the same rules as {@see ProviderManager}:
 *
 * 1. The operator's enabled transcription account (Super Admin > AI providers), when one is marked.
 * 2. When accounts with a transcription model exist but none is the enabled transcription default, nothing:
 *    `not_configured`, never a silent fall back to the environment.
 * 3. Otherwise the environment (`ai.transcription.*`): `fake` only in `local` and `testing`, else the
 *    OpenAI-compatible adapter, which reports `not_configured` without an endpoint and a model.
 */
final class TranscriberManager
{
    public function __construct(
        private readonly Application $app,
        private readonly HttpFactory $http,
    ) {}

    public function transcriber(): Transcriber
    {
        $account = AiProviderAccount::query()
            ->where('is_transcription_default', true)
            ->where('is_enabled', true)
            ->first();

        if ($account !== null) {
            return $this->forAccount($account);
        }

        if (AiProviderAccount::query()->whereNotNull('transcription_model')->exists()) {
            return $this->unconfigured();
        }

        if (config('ai.transcription.driver') === 'fake' && $this->app->environment(['local', 'testing'])) {
            return $this->app->make(FakeTranscriber::class);
        }

        return new OpenAiTranscriber($this->http, new ProviderSettings(
            AiDriver::OpenAiCompatible,
            trim((string) config('ai.transcription.api_key', '')),
            trim((string) config('ai.transcription.model', '')),
            trim((string) config('ai.transcription.base_url', '')),
            (int) config('ai.transcription.timeout', 30),
        ));
    }

    public function forAccount(AiProviderAccount $account): Transcriber
    {
        if (! $account->driver->supportsTranscription()) {
            return $this->unconfigured();
        }

        return new OpenAiTranscriber($this->http, new ProviderSettings(
            AiDriver::OpenAiCompatible,
            trim($account->secrets['api_key'] ?? ''),
            trim((string) $account->transcription_model),
            (string) ($account->base_url ?? ''),
            (int) config('ai.transcription.timeout', 30),
        ));
    }

    private function unconfigured(): Transcriber
    {
        return new OpenAiTranscriber($this->http, new ProviderSettings(AiDriver::OpenAiCompatible, '', '', ''));
    }
}
