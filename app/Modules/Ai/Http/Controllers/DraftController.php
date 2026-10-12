<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Modules\Ai\Actions\AiAvailability;
use App\Modules\Ai\Actions\DraftService;
use App\Modules\Ai\Http\Requests\DraftRequest;
use Illuminate\Http\JsonResponse;

/**
 * AI-assisted drafting for other screens (summarise a record, draft a reply, rewrite). JSON in, JSON out:
 * the result is text only and carries the AI-generated label; nothing is saved or sent.
 */
final class DraftController
{
    public function __construct(
        private readonly AiControllerSupport $support,
        private readonly DraftService $drafts,
        private readonly AiAvailability $availability,
    ) {}

    public function store(DraftRequest $request): JsonResponse
    {
        $context = $this->support->context($request);

        /** @var array{kind: string, record_type?: string|null, record_id?: string|null, instruction?: string|null, tone?: string|null, text?: string|null} $input */
        $input = $request->validated();

        return new JsonResponse($this->drafts->generate($context, $input));
    }

    public function status(): JsonResponse
    {
        return new JsonResponse([
            ...$this->availability->describe(),
            'recordTypes' => DraftService::recordTypes(),
            'kinds' => [DraftService::SUMMARIZE, DraftService::REPLY, DraftService::REWRITE],
        ]);
    }
}
