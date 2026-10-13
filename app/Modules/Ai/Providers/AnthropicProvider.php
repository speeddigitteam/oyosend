<?php

declare(strict_types=1);

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Enums\AiDriver;
use App\Modules\Ai\Support\CompletionRequest;
use App\Modules\Ai\Support\CompletionResponse;
use App\Modules\Ai\Support\ProviderFailure;
use App\Modules\Ai\Support\ProviderNotConfigured;
use App\Modules\Ai\Support\ProviderSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Messages API (`POST /v1/messages`) over the Laravel HTTP client.
 *
 * The credential is the default operator account's key (decrypted for this call only) or, when no account
 * exists, the platform-level `ANTHROPIC_API_KEY` from config. It is only ever placed in the `x-api-key`
 * request header: not in an exception message, a log line, a response or a page.
 * Failures are logged by status and error type only (never the request or response body).
 *
 * Deliberate deviation: the official PHP SDK is not installed (a new dependency needs the licence and
 * Laravel 13 check first); this is a thin adapter over the documented HTTP API.
 */
final class AnthropicProvider implements AiProvider
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ?ProviderSettings $settings = null,
    ) {}

    /** The adapter for an operator account's settings. */
    public static function using(HttpFactory $http, ProviderSettings $settings): self
    {
        return new self($http, $settings);
    }

    private function settings(): ProviderSettings
    {
        return $this->settings ?? new ProviderSettings(
            AiDriver::Anthropic,
            is_string($key = config('ai.providers.anthropic.api_key')) ? trim($key) : '',
            (string) config('ai.providers.anthropic.model', 'claude-sonnet-5-5'),
            (string) config('ai.providers.anthropic.base_url', 'https://api.anthropic.com'),
            (int) config('ai.providers.anthropic.timeout', 60),
            (string) config('ai.providers.anthropic.version', '2023-06-01'),
        );
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->settings()->model;
    }

    public function isConfigured(): bool
    {
        return $this->settings()->apiKey !== '';
    }

    public function complete(CompletionRequest $request): CompletionResponse
    {
        $settings = $this->settings();
        $key = $settings->apiKey;

        if ($key === '') {
            throw new ProviderNotConfigured('The AI provider has no platform credential configured.');
        }

        $body = [
            'model' => $this->model(),
            'max_tokens' => $request->maxTokens,
            'system' => $request->system,
            'messages' => $request->messages,
        ];

        if ($request->tools !== []) {
            $body['tools'] = $request->tools;
        }

        $url = rtrim($settings->baseUrl !== '' ? $settings->baseUrl : 'https://api.anthropic.com', '/').'/v1/messages';

        try {
            $response = $this->http
                ->withHeaders([
                    'x-api-key' => $key,
                    'anthropic-version' => $settings->version,
                ])
                ->acceptJson()
                ->asJson()
                ->timeout($settings->timeout)
                ->post($url, $body);
        } catch (ConnectionException) {
            Log::warning('ai.provider.failed', ['provider' => 'anthropic', 'reason' => 'connection']);

            throw new ProviderFailure('The AI provider could not be reached.', 'unreachable');
        }

        if (! $response->successful()) {
            Log::warning('ai.provider.failed', [
                'provider' => 'anthropic',
                'status' => $response->status(),
                'type' => $response->json('error.type'),
                'request_id' => $response->header('request-id'),
            ]);

            throw new ProviderFailure(
                'The AI provider failed the request (HTTP '.$response->status().').',
                match (true) {
                    in_array($response->status(), [401, 403], true) => 'unauthorized',
                    $response->status() === 429 => 'rate_limited',
                    default => 'http_error',
                },
            );
        }

        $content = $response->json('content');
        $blocks = [];

        foreach (is_array($content) ? $content : [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'text') {
                $blocks[] = ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $input = $block['input'] ?? [];
                $blocks[] = [
                    'type' => 'tool_use',
                    'id' => (string) ($block['id'] ?? ''),
                    'name' => (string) ($block['name'] ?? ''),
                    'input' => is_array($input) ? $input : [],
                ];
            }
        }

        return new CompletionResponse(
            content: $blocks,
            stopReason: (string) ($response->json('stop_reason') ?? 'end_turn'),
            inputTokens: max(0, (int) $response->json('usage.input_tokens', 0)),
            outputTokens: max(0, (int) $response->json('usage.output_tokens', 0)),
            model: (string) ($response->json('model') ?? $this->model()),
            provider: $this->name(),
        );
    }
}
