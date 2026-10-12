<?php

declare(strict_types=1);

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Support\CompletionRequest;
use App\Modules\Ai\Support\CompletionResponse;
use App\Modules\Ai\Support\ProviderFailure;
use App\Modules\Ai\Support\ProviderNotConfigured;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Messages API (`POST /v1/messages`) over the Laravel HTTP client.
 *
 * The credential is the platform-level `ANTHROPIC_API_KEY` from config. It is only ever placed in the
 * `x-api-key` request header: not in an exception message, a log line, a response or the database.
 * Failures are logged by status and error type only (never the request or response body).
 *
 * Deliberate deviation: the official PHP SDK is not installed (a new dependency needs the licence and
 * Laravel 13 check first); this is a thin adapter over the documented HTTP API.
 */
final class AnthropicProvider implements AiProvider
{
    public function __construct(private readonly HttpFactory $http) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return (string) config('ai.providers.anthropic.model', 'claude-sonnet-5-5');
    }

    public function isConfigured(): bool
    {
        return $this->key() !== '';
    }

    public function complete(CompletionRequest $request): CompletionResponse
    {
        $key = $this->key();

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

        $url = rtrim((string) config('ai.providers.anthropic.base_url', 'https://api.anthropic.com'), '/').'/v1/messages';

        try {
            $response = $this->http
                ->withHeaders([
                    'x-api-key' => $key,
                    'anthropic-version' => (string) config('ai.providers.anthropic.version', '2023-06-01'),
                ])
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('ai.providers.anthropic.timeout', 60))
                ->post($url, $body);
        } catch (ConnectionException) {
            Log::warning('ai.provider.failed', ['provider' => 'anthropic', 'reason' => 'connection']);

            throw new ProviderFailure('The AI provider could not be reached.');
        }

        if (! $response->successful()) {
            Log::warning('ai.provider.failed', [
                'provider' => 'anthropic',
                'status' => $response->status(),
                'type' => $response->json('error.type'),
                'request_id' => $response->header('request-id'),
            ]);

            throw new ProviderFailure('The AI provider failed the request (HTTP '.$response->status().').');
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

    private function key(): string
    {
        $key = config('ai.providers.anthropic.api_key');

        return is_string($key) ? trim($key) : '';
    }
}
