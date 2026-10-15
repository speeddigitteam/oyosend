<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Modules\Social\Actions\PostPreflight;
use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Http\Requests\PreflightRequest;
use Illuminate\Http\JsonResponse;

/** Live "will this fit?" answer for the post composer. Reads only; stores nothing. */
final class PreflightController
{
    public function __construct(private readonly PostPreflight $preflight) {}

    public function __invoke(PreflightRequest $request): JsonResponse
    {
        $input = $request->validated();
        $targets = array_values(array_map(static fn (array $target): array => [
            'account_id' => (string) $target['account_id'],
            'body_override' => isset($target['body_override']) && trim((string) $target['body_override']) !== '' ? (string) $target['body_override'] : null,
            'options' => is_array($target['options'] ?? null) ? $target['options'] : null,
        ], (array) ($input['targets'] ?? [])));

        $previews = $this->preflight->previews(
            PostType::from((string) $input['post_type']),
            isset($input['body']) ? (string) $input['body'] : null,
            isset($input['link_url']) && trim((string) $input['link_url']) !== '' ? (string) $input['link_url'] : null,
            isset($input['hashtag_set_id']) && $input['hashtag_set_id'] !== '' ? (string) $input['hashtag_set_id'] : null,
            $targets,
            array_values(array_map('strval', (array) ($input['media_ids'] ?? []))),
        );

        return response()->json(['previews' => $previews]);
    }
}
