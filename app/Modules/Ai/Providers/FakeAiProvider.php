<?php

declare(strict_types=1);

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Support\CompletionRequest;
use App\Modules\Ai\Support\CompletionResponse;
use App\Modules\Ai\Support\ProviderFailure;
use Closure;

/**
 * A scripted provider for tests and local development. It is bound ONLY in the `testing` and `local`
 * environments (see `ProviderManager`): it can never answer in production and is never presented as a
 * real integration.
 */
final class FakeAiProvider implements AiProvider
{
    /** @var list<CompletionResponse|Closure|ProviderFailure> */
    private array $queue = [];

    /** @var list<CompletionRequest> */
    public array $requests = [];

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function push(CompletionResponse|Closure|ProviderFailure $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function pushText(string $text, int $in = 10, int $out = 5): self
    {
        return $this->push($this->text($text, $in, $out));
    }

    /** @param array<string, mixed> $input */
    public function pushToolUse(string $name, array $input, string $id = 'toolu_1', int $in = 10, int $out = 5): self
    {
        return $this->push(new CompletionResponse(
            [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]],
            'tool_use',
            $in,
            $out,
            $this->model(),
            $this->name(),
        ));
    }

    public function text(string $text, int $in = 10, int $out = 5): CompletionResponse
    {
        return new CompletionResponse([['type' => 'text', 'text' => $text]], 'end_turn', $in, $out, $this->model(), $this->name());
    }

    public function complete(CompletionRequest $request): CompletionResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);

        if ($next instanceof ProviderFailure) {
            throw $next;
        }

        if ($next instanceof Closure) {
            return $next($request);
        }

        return $next ?? $this->text('Fake reply.');
    }
}
