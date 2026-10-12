<?php

declare(strict_types=1);

namespace App\Modules\Ai\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\Ai\Actions\AiGateway;
use App\Modules\Ai\Enums\Feature;
use App\Modules\Ai\Models\AutomationActionReceipt;
use App\Modules\Ai\Support\CompletionRequest;
use App\Modules\Ai\Support\DataBlock;
use App\Modules\Ai\Support\SystemPrompt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Generates TEXT for a workflow step. It has no tools and returns text only: it can never read other
 * records, change data, move money or send anything. A durable receipt per tenant/action/idempotency key
 * makes a retried step return the first text instead of generating (and being billed) again, and the
 * usage record carries a key derived from the same idempotency key.
 */
final class GenerateTextAction implements ActionDefinition
{
    public function __construct(private readonly AiGateway $gateway) {}

    public static function key(): string
    {
        return 'ai.text.generate';
    }

    public static function label(): string
    {
        return 'automation.actions.ai.text.generate';
    }

    public static function module(): string
    {
        return 'ai';
    }

    public static function requiredPermission(): string
    {
        return 'ai.drafts.generate';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['instruction'],
            'properties' => [
                'instruction' => ['type' => 'string', 'maxLength' => 2000],
                'context' => ['type' => ['string', 'null'], 'maxLength' => 6000],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['text', 'ai_generated'],
            'properties' => [
                'text' => ['type' => 'string'],
                'ai_generated' => ['type' => 'boolean'],
                'input_tokens' => ['type' => 'integer'],
                'output_tokens' => ['type' => 'integer'],
                'prompt_version' => ['type' => 'string'],
            ],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 160) {
            throw new InvalidArgumentException('An idempotency key of 1 to 160 characters is required.');
        }

        $existing = $this->find($idempotencyKey);

        if ($existing !== null) {
            return $existing->output;
        }

        $valid = Validator::make($input, [
            'instruction' => ['required', 'string', 'max:2000'],
            'context' => ['nullable', 'string', 'max:6000'],
        ])->validate();

        $prompt = 'Task: '.$valid['instruction'];

        if (($valid['context'] ?? '') !== '') {
            $prompt .= "\n\n".DataBlock::text((string) $valid['context']);
        }

        $response = $this->gateway->complete(
            Feature::Automation,
            new CompletionRequest(SystemPrompt::automation(), [['role' => 'user', 'content' => $prompt]], [], (int) config('ai.limits.max_output_tokens', 1024)),
            'ai:auto:'.hash('sha256', $idempotencyKey),
        );

        $output = [
            'text' => $response->text(),
            'ai_generated' => true,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'prompt_version' => SystemPrompt::VERSION,
        ];

        try {
            AutomationActionReceipt::query()->create([
                'action' => self::key(),
                'idempotency_key' => $idempotencyKey,
                'output' => $output,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent worker stored its receipt first; its text is authoritative.
            return $this->find($idempotencyKey)->output ?? throw $exception;
        }

        return $output;
    }

    private function find(string $idempotencyKey): ?AutomationActionReceipt
    {
        return AutomationActionReceipt::query()
            ->where('action', self::key())
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }
}
