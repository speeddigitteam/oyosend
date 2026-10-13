<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Core\Ai\Contracts\TextAssistant;
use App\Core\Ai\Text\TextResult;
use App\Core\Ai\Text\TextState;
use App\Core\Ai\Text\TextTask;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Enums\AiState;
use App\Modules\Ai\Enums\Feature;
use App\Modules\Ai\Support\AiRefusal;
use App\Modules\Ai\Support\CompletionRequest;
use App\Modules\Ai\Support\TextOutputParser;
use App\Modules\Ai\Support\TextPrompts;
use Illuminate\Support\Facades\Cache;

/**
 * The AI module's TextAssistant: every call goes through AiGateway (switches, credential, breaker, plan limit,
 * rate limits, redaction, idempotent metering). It has no tools, performs no action and returns text only.
 *
 * Gate: the workspace must be entitled to the `ai` app and have AI and `drafting` switched on. The metering
 * key and the replay cache derive from the task's purpose and fingerprint, so a retried job is neither billed
 * nor generated twice.
 */
final class GatewayTextAssistant implements TextAssistant
{
    public const int REPLAY_TTL_SECONDS = 3600;

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly TenantContext $context,
        private readonly EntitlementChecker $entitlements,
    ) {}

    public function generate(TextTask $task): TextResult
    {
        $tenant = $this->context->tenant();

        if ($tenant === null || ! $this->entitlements->allows($tenant, 'ai')) {
            return TextResult::unavailable(TextState::Disabled, $task->kind);
        }

        $state = $this->gateway->state(Feature::Drafting);

        if ($state !== AiState::Ready) {
            return $this->refusal($task, $state);
        }

        $key = hash('sha256', $task->purpose.'|'.$task->fingerprint());
        $cacheKey = 'ai:text:'.$key;
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && ($replay = TextResult::fromArray($cached)) !== null && $replay->kind === $task->kind) {
            return $replay->asReplay();
        }

        $request = new CompletionRequest(
            TextPrompts::system(),
            [['role' => 'user', 'content' => TextPrompts::message($task)]],
            [],
            TextPrompts::maxTokens($task),
        );

        try {
            $response = $this->gateway->complete(Feature::Drafting, $request, 'ai:text:'.$key, $task->actorMembershipId);
        } catch (AiRefusal $refusal) {
            return $this->refusal($task, $refusal->state);
        }

        $parsed = TextOutputParser::parse($task, $response->text());

        if (! $parsed['usable']) {
            return new TextResult(
                TextState::Failed,
                $task->kind,
                message: __('The assistant could not produce usable text for this request.'),
                retryable: true,
                inputTokens: $response->inputTokens,
                outputTokens: $response->outputTokens,
                promptVersion: TextPrompts::VERSION,
            );
        }

        $result = new TextResult(
            TextState::Ok,
            $task->kind,
            $parsed['text'],
            $parsed['hashtags'],
            $parsed['sentiment'],
            $parsed['score'],
            $parsed['language'],
            '',
            false,
            $response->inputTokens,
            $response->outputTokens,
            TextPrompts::VERSION,
        );

        Cache::put($cacheKey, $result->toArray(), self::REPLAY_TTL_SECONDS);

        return $result;
    }

    private function refusal(TextTask $task, AiState $state): TextResult
    {
        $mapped = match ($state) {
            AiState::Ready => TextState::Failed,
            AiState::NotConfigured => TextState::NotConfigured,
            AiState::Disabled => TextState::Disabled,
            AiState::LimitReached => TextState::LimitReached,
            AiState::RateLimited, AiState::TooLong => TextState::Refused,
            AiState::Unavailable => TextState::Failed,
        };

        return TextResult::unavailable(
            $mapped,
            $task->kind,
            $state->message(),
            retryable: in_array($state, [AiState::RateLimited, AiState::Unavailable], true),
        );
    }
}
