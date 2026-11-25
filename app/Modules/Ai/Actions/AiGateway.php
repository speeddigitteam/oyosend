<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Core\Billing\UsageLimits;
use App\Core\Billing\UsageMeter;
use App\Modules\Ai\Enums\AiState;
use App\Modules\Ai\Enums\Feature;
use App\Modules\Ai\Providers\ProviderManager;
use App\Modules\Ai\Providers\TranscriberManager;
use App\Modules\Ai\Support\AiRefusal;
use App\Modules\Ai\Support\AudioClip;
use App\Modules\Ai\Support\CircuitBreaker;
use App\Modules\Ai\Support\CompletionRequest;
use App\Modules\Ai\Support\CompletionResponse;
use App\Modules\Ai\Support\ProviderFailure;
use App\Modules\Ai\Support\ProviderNotConfigured;
use App\Modules\Ai\Support\Redactor;
use App\Modules\Ai\Support\SystemPrompt;
use App\Modules\Ai\Support\Transcript;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The single choke point for every provider call: feature switches, credential presence, circuit breaker,
 * plan limit, rate limits, output cap, redaction and idempotent usage metering all happen here and nowhere else.
 */
final class AiGateway
{
    public const string TOKENS = 'ai.tokens';

    /** Voice clips transcribed (ADR 0038): one per clip, each at most `ai.transcription.max_seconds` long. */
    public const string VOICE_CLIPS = 'ai.voice_clips';

    private readonly CircuitBreaker $transcriptionBreaker;

    public function __construct(
        private readonly ProviderManager $providers,
        private readonly TranscriberManager $transcribers,
        private readonly AiSettings $settings,
        private readonly CircuitBreaker $breaker,
        private readonly UsageLimits $limits,
        private readonly UsageMeter $meter,
        private readonly Redactor $redactor,
    ) {
        $this->transcriptionBreaker = CircuitBreaker::transcription();
    }

    /**
     * The honest state for a feature in the active workspace. Never calls the provider.
     */
    public function state(Feature $feature): AiState
    {
        if ($feature === Feature::Voice) {
            return $this->transcriptionState();
        }

        if (! $this->settings->allows($feature)) {
            return AiState::Disabled;
        }

        if (! $this->providers->provider()->isConfigured()) {
            return AiState::NotConfigured;
        }

        if ($this->breaker->isOpen()) {
            return AiState::Unavailable;
        }

        if (! $this->limits->allows(self::TOKENS, 1)) {
            return AiState::LimitReached;
        }

        return AiState::Ready;
    }

    /**
     * @param  string  $usageKey  Idempotency key of this provider call: a retry with the same key is not counted twice.
     * @param  string|null  $memberId  Membership id for the per-member rate limit (null: automation).
     *
     * @throws AiRefusal
     */
    public function complete(Feature $feature, CompletionRequest $request, string $usageKey, ?string $memberId = null): CompletionResponse
    {
        $state = $this->state($feature);

        if ($state !== AiState::Ready) {
            throw new AiRefusal($state);
        }

        $this->hit('ai:tenant', (int) config('ai.limits.tenant_per_minute', 60));

        if ($memberId !== null) {
            $this->hit('ai:member:'.$memberId, (int) config('ai.limits.member_per_minute', 20));
        }

        $maxTokens = min($request->maxTokens, max(1, (int) config('ai.limits.max_output_tokens', 1024)));
        $messages = $this->settings->redacts() ? $this->redactor->redactMessages($request->messages) : $request->messages;
        $system = $this->settings->redacts() ? $this->redactor->redact($request->system) : $request->system;
        $bounded = new CompletionRequest($system, $messages, $request->tools, $maxTokens);
        $provider = $this->providers->provider();

        try {
            $response = $provider->complete($bounded);
        } catch (ProviderNotConfigured) {
            throw new AiRefusal(AiState::NotConfigured);
        } catch (ProviderFailure) {
            $this->breaker->recordFailure();

            throw new AiRefusal(AiState::Unavailable);
        }

        $this->breaker->recordSuccess();

        $this->meter->record(self::TOKENS, max(1, $response->totalTokens()), $usageKey, [
            'feature' => $feature->value,
            'provider' => $response->provider,
            'model' => $response->model,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'prompt_version' => SystemPrompt::VERSION,
        ]);

        return $response;
    }

    /**
     * Voice has its own provider, breaker and limit: the assistant may be ready while speech is not, or the
     * reverse. Never calls the provider.
     */
    public function transcriptionState(): AiState
    {
        if (! $this->settings->allows(Feature::Voice)) {
            return AiState::Disabled;
        }

        if (! $this->transcribers->transcriber()->isConfigured()) {
            return AiState::NotConfigured;
        }

        if ($this->transcriptionBreaker->isOpen()) {
            return AiState::Unavailable;
        }

        if (! $this->limits->allows(self::VOICE_CLIPS, 1)) {
            return AiState::LimitReached;
        }

        return AiState::Ready;
    }

    /**
     * Speech to text for one member's clip. The clip and the transcript are not stored or logged here; the
     * usage record holds the provider, the model and the clip size, nothing heard.
     *
     * @param  string  $usageKey  Idempotency key of this clip: a retry with the same key is not counted twice.
     *
     * @throws AiRefusal
     */
    public function transcribe(AudioClip $clip, string $usageKey, string $memberId): Transcript
    {
        $state = $this->transcriptionState();

        if ($state !== AiState::Ready) {
            throw new AiRefusal($state);
        }

        $this->hit('ai:tenant', (int) config('ai.limits.tenant_per_minute', 60));
        $this->hit('ai:member:'.$memberId, (int) config('ai.limits.member_per_minute', 20));

        $transcriber = $this->transcribers->transcriber();

        try {
            $transcript = $transcriber->transcribe($clip);
        } catch (ProviderNotConfigured) {
            throw new AiRefusal(AiState::NotConfigured);
        } catch (ProviderFailure) {
            $this->transcriptionBreaker->recordFailure();

            throw new AiRefusal(AiState::Unavailable);
        }

        $this->transcriptionBreaker->recordSuccess();

        $this->meter->record(self::VOICE_CLIPS, 1, $usageKey, [
            'feature' => Feature::Voice->value,
            'provider' => $transcript->provider,
            'model' => $transcript->model,
            'bytes' => $clip->size(),
        ]);

        return $transcript;
    }

    private function hit(string $key, int $perMinute): void
    {
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            throw new AiRefusal(AiState::RateLimited);
        }

        RateLimiter::hit($key, 60);
    }
}
