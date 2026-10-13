<?php

declare(strict_types=1);

namespace App\Modules\Social\Ai;

use App\Modules\Social\Http\Controllers\SocialSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/**
 * One AI suggestion for the post composer. Answers with text and an honest state; never writes a post.
 * The route requires `social.posts.ai_assist`; the person also has to be able to write posts.
 */
final class AiAssistController
{
    public function __construct(
        private readonly PostAiAssist $assist,
        private readonly SocialSupport $support,
    ) {}

    public function __invoke(AiAssistRequest $request, string $operation): JsonResponse
    {
        $actor = $this->support->actor($request);
        $membership = $this->support->membership($actor);

        abort_unless($actor->can('social.posts.create') || $actor->can('social.posts.edit'), 403);

        $payload = $this->assist->run($operation, $membership->id, $request->validated());

        // A calm way forward for people who may change the AI settings; nobody else gets a link.
        $payload['settingsUrl'] = $payload['state'] !== 'ok' && Route::has('ai.settings.index') && $actor->can('ai.settings.manage')
            ? route('ai.settings.index', absolute: false)
            : null;

        return response()->json($payload);
    }
}
