<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Support\CompletionRequest;
use App\Modules\Ai\Support\CompletionResponse;
use App\Modules\Ai\Support\ProviderFailure;
use App\Modules\Ai\Support\ProviderNotConfigured;

/**
 * A text-generation provider with tool use. Provider independent: the rest of the module talks only to
 * this contract and the neutral request/response shapes.
 *
 * Streaming is not part of the contract yet: every call returns the complete answer.
 */
interface AiProvider
{
    /** Stable id: `anthropic`, `fake`. */
    public function name(): string;

    /** The model id used for calls. */
    public function model(): string;

    /** Whether the platform credential exists. Never reveals it. */
    public function isConfigured(): bool;

    /**
     * @throws ProviderNotConfigured When no credential exists.
     * @throws ProviderFailure When the provider could not answer (message is generic, never secret).
     */
    public function complete(CompletionRequest $request): CompletionResponse;
}
