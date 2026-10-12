<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Core\Ai\ToolContext;
use App\Modules\Ai\Enums\AiState;
use App\Modules\Ai\Enums\Feature;
use App\Modules\Ai\Enums\MessageRole;
use App\Modules\Ai\Models\Conversation;
use App\Modules\Ai\Models\Message;
use App\Modules\Ai\Providers\ProviderManager;
use App\Modules\Ai\Support\AiRefusal;
use App\Modules\Ai\Support\CompletionRequest;
use App\Modules\Ai\Support\SystemPrompt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One assistant turn: the member message, bounded rounds of model calls and tool runs, and the answer.
 *
 * Tools run as the member through {@see ToolRunner}; what a tool returns is data in a tool_result, never
 * an instruction. The loop is bounded by rounds and by tokens, and every provider call is metered.
 */
final class AssistantService
{
    /** Tool calls honoured from a single model answer. */
    private const int MAX_CALLS_PER_ROUND = 5;

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly ToolRegistry $tools,
        private readonly ToolRunner $runner,
        private readonly ProviderManager $providers,
    ) {}

    /**
     * @throws AiRefusal
     */
    public function send(Conversation $conversation, ToolContext $context, string $text): Message
    {
        $state = $this->gateway->state(Feature::Chat);

        if ($state !== AiState::Ready) {
            throw new AiRefusal($state);
        }

        $userMessage = new Message(['role' => MessageRole::User, 'content' => $text]);
        $userMessage->conversation_id = $conversation->id;
        $userMessage->save();
        $conversation->forceFill(['last_message_at' => Carbon::now()])->save();

        $messages = $this->history($conversation);
        $tools = $this->tools->definitionsFor($context->user);
        $assistantId = (string) Str::ulid();
        $maxRounds = max(1, (int) config('ai.limits.max_tool_rounds', 4));
        $maxTurnTokens = max(1, (int) config('ai.limits.max_turn_tokens', 20000));

        $inputTokens = 0;
        $outputTokens = 0;
        $trace = [];
        $answer = '';
        $model = $this->providers->provider()->model();
        $provider = $this->providers->provider()->name();

        for ($round = 1; $round <= $maxRounds; $round++) {
            $response = $this->gateway->complete(
                Feature::Chat,
                new CompletionRequest(SystemPrompt::assistant(), $messages, $tools, (int) config('ai.limits.max_output_tokens', 1024)),
                "ai:{$assistantId}:{$round}",
                $context->membership->id,
            );

            $inputTokens += $response->inputTokens;
            $outputTokens += $response->outputTokens;
            $model = $response->model;
            $provider = $response->provider;
            $answer = $response->text();
            $uses = $response->toolUses();

            if ($uses === [] || $response->stopReason !== 'tool_use') {
                if ($answer === '' && $response->stopReason === 'refusal') {
                    $answer = __('The assistant could not help with that request.');
                }

                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $results = [];

            foreach ($uses as $index => $use) {
                if ($index >= self::MAX_CALLS_PER_ROUND) {
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $use['id'], 'content' => '{"error":"Too many tool calls in one step."}', 'is_error' => true];

                    continue;
                }

                $run = $this->runner->run($use['name'], $use['input'], $context, $conversation);
                $trace[] = $run->trace();
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $use['id'], 'content' => $run->json, 'is_error' => $run->failed];
            }

            $messages[] = ['role' => 'user', 'content' => $results];

            if ($inputTokens + $outputTokens >= $maxTurnTokens || $round === $maxRounds) {
                $answer = $answer !== '' ? $answer : __('I could not finish that request within the limits for one answer. Try a narrower question.');

                break;
            }

            $answer = '';
        }

        if ($answer === '') {
            $answer = __('I could not produce an answer. Try rephrasing the question.');
        }

        $assistant = new Message([
            'role' => MessageRole::Assistant,
            'content' => $answer,
            'tool_calls' => $trace === [] ? null : $trace,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'prompt_version' => SystemPrompt::VERSION,
            'provider' => $provider,
            'model' => $model,
        ]);
        $assistant->id = $assistantId;
        $assistant->conversation_id = $conversation->id;
        $assistant->save();

        $conversation->forceFill(['last_message_at' => Carbon::now()])->save();

        return $assistant;
    }

    /**
     * The last messages as plain text turns; earlier tool results are not replayed (they are fetched again
     * when needed, as the member, with the member permissions of that moment).
     *
     * @return list<array{role: string, content: string|list<array<string, mixed>>}>
     */
    private function history(Conversation $conversation): array
    {
        $limit = max(1, (int) config('ai.limits.history_messages', 20));

        $recent = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $messages = [];

        foreach ($recent as $message) {
            if ($messages === [] && $message->role !== MessageRole::User) {
                continue;
            }

            $messages[] = ['role' => $message->role->value, 'content' => $message->content];
        }

        return $messages;
    }
}
