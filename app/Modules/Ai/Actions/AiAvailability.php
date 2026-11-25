<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Modules\Ai\Enums\AiState;
use App\Modules\Ai\Enums\Feature;
use App\Modules\Ai\Providers\ProviderManager;
use App\Modules\Ai\Providers\TranscriberManager;

/**
 * What the UI and API callers are told about AI right now: honest, never a secret. The platform credential
 * itself is never read out; only whether one exists.
 */
final class AiAvailability
{
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly ProviderManager $providers,
        private readonly TranscriberManager $transcribers,
    ) {}

    /**
     * @return array{state: string, message: string, configured: bool, provider: string, model: string, features: array<string, array{state: string, message: string}>, transcription: array{configured: bool, model: string}}
     */
    public function describe(): array
    {
        $provider = $this->providers->provider();
        $transcriber = $this->transcribers->transcriber();
        $features = [];

        foreach (Feature::cases() as $feature) {
            $state = $this->gateway->state($feature);
            $features[$feature->value] = ['state' => $state->value, 'message' => $state->message()];
        }

        // The overall state is the chat state unless chat itself is merely switched off.
        $overall = $this->gateway->state(Feature::Chat);

        if ($overall === AiState::Disabled) {
            $overall = $this->gateway->state(Feature::Drafting);
        }

        return [
            'state' => $overall->value,
            'message' => $overall->message(),
            'configured' => $provider->isConfigured(),
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'features' => $features,
            // Speech to text may be a different service from the chat provider (ADR 0038).
            'transcription' => [
                'configured' => $transcriber->isConfigured(),
                'model' => $transcriber->model(),
            ],
        ];
    }
}
