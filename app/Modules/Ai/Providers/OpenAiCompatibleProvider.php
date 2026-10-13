<?php

declare(strict_types=1);

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Support\CompletionRequest;
use App\Modules\Ai\Support\CompletionResponse;
use App\Modules\Ai\Support\ProviderFailure;
use App\Modules\Ai\Support\ProviderNotConfigured;
use App\Modules\Ai\Support\ProviderSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * Any server that speaks the OpenAI chat-completions API (`POST {base_url}/chat/completions`): OpenAI,
 * Azure-style gateways, OpenRouter, Ollama-like local servers. The base URL is supplied by the operator and
 * includes the version prefix (for example `https://api.openai.com/v1`).
 *
 * The API key is optional (some local servers have none); when present it is sent only as the `Authorization`
 * bearer header, never in an exception message, a log line or a response. Failures are logged by status only.
 *
 * Not verified against live APIs: it is exercised against faked HTTP responses (see tests/Feature/Ai).
 */
final class OpenAiCompatibleProvider implements AiProvider
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ProviderSettings $settings,
    ) {}

    public function name(): string
    {
        return 'openai_compatible';
    }

    public function model(): string
    {
        return $this->settings->model;
    }

    /** A base URL and a model are enough: the key is optional for self-hosted servers. */
    public function isConfigured(): bool
    {
        return $this->settings->baseUrl !== '' && $this->settings->model !== '';
    }

    public function complete(CompletionRequest $request): CompletionResponse
    {
        if (! $this->isConfigured()) {
            throw new ProviderNotConfigured('The AI provider has no endpoint configured.');
        }

        $body = [
            'model' => $this->settings->model,
            'max_tokens' => $request->maxTokens,
            'messages' => $this->messages($request),
        ];

        if ($request->tools !== []) {
            $body['tools'] = array_map(static fn (array $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['input_schema'],
                ],
            ], $request->tools);
        }

        $pending = $this->http->acceptJson()->asJson()->timeout($this->settings->timeout);

        if ($this->settings->apiKey !== '') {
            $pending = $pending->withToken($this->settings->apiKey);
        }

        try {
            $response = $pending->post(rtrim($this->settings->baseUrl, '/').'/chat/completions', $body);
        } catch (ConnectionException) {
            Log::warning('ai.provider.failed', ['provider' => 'openai_compatible', 'reason' => 'connection']);

            throw new ProviderFailure('The AI provider could not be reached.', 'unreachable');
        }

        if (! $response->successful()) {
            Log::warning('ai.provider.failed', ['provider' => 'openai_compatible', 'status' => $response->status()]);

            throw new ProviderFailure(
                'The AI provider failed the request (HTTP '.$response->status().').',
                match (true) {
                    in_array($response->status(), [401, 403], true) => 'unauthorized',
                    $response->status() === 429 => 'rate_limited',
                    default => 'http_error',
                },
            );
        }

        $message = $response->json('choices.0.message');

        if (! is_array($message)) {
            throw new ProviderFailure('The AI provider returned an unexpected answer.', 'bad_response');
        }

        $blocks = [];
        $text = $message['content'] ?? null;

        if (is_string($text) && $text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        }

        foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $call) {
            if (! is_array($call) || ! is_array($call['function'] ?? null)) {
                continue;
            }

            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            $blocks[] = [
                'type' => 'tool_use',
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($call['function']['name'] ?? ''),
                'input' => is_array($arguments) ? $arguments : [],
            ];
        }

        $finish = (string) ($response->json('choices.0.finish_reason') ?? 'stop');

        return new CompletionResponse(
            content: $blocks,
            stopReason: match ($finish) {
                'tool_calls' => 'tool_use',
                'length' => 'max_tokens',
                default => 'end_turn',
            },
            inputTokens: max(0, (int) $response->json('usage.prompt_tokens', 0)),
            outputTokens: max(0, (int) $response->json('usage.completion_tokens', 0)),
            model: (string) ($response->json('model') ?? $this->model()),
            provider: $this->name(),
        );
    }

    /**
     * Neutral blocks to chat-completions messages: tool_use becomes `tool_calls` on an assistant message,
     * tool_result becomes one `tool` message each.
     *
     * @return list<array<string, mixed>>
     */
    private function messages(CompletionRequest $request): array
    {
        $out = [];

        if ($request->system !== '') {
            $out[] = ['role' => 'system', 'content' => $request->system];
        }

        foreach ($request->messages as $message) {
            $content = $message['content'];

            if (is_string($content)) {
                $out[] = ['role' => $message['role'], 'content' => $content];

                continue;
            }

            $text = [];
            $calls = [];

            foreach ($content as $block) {
                switch ($block['type'] ?? null) {
                    case 'text':
                        $text[] = (string) ($block['text'] ?? '');
                        break;
                    case 'tool_use':
                        $input = $block['input'] ?? [];
                        $calls[] = [
                            'id' => (string) ($block['id'] ?? ''),
                            'type' => 'function',
                            'function' => [
                                'name' => (string) ($block['name'] ?? ''),
                                'arguments' => json_encode($input === [] ? new stdClass : $input, JSON_UNESCAPED_UNICODE) ?: '{}',
                            ],
                        ];
                        break;
                    case 'tool_result':
                        $result = $block['content'] ?? '';
                        $out[] = [
                            'role' => 'tool',
                            'tool_call_id' => (string) ($block['tool_use_id'] ?? ''),
                            'content' => is_string($result) ? $result : (json_encode($result) ?: ''),
                        ];
                        break;
                }
            }

            if ($calls !== []) {
                $out[] = ['role' => 'assistant', 'content' => $text === [] ? null : implode("\n", $text), 'tool_calls' => $calls];
            } elseif ($text !== []) {
                $out[] = ['role' => $message['role'], 'content' => implode("\n", $text)];
            }
        }

        return $out;
    }
}
