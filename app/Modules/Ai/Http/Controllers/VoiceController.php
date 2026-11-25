<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Modules\Ai\Actions\AiGateway;
use App\Modules\Ai\Http\Requests\TranscribeRequest;
use App\Modules\Ai\Support\AudioClip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Voice input (ADR 0038): one clip in, its transcript out. The transcript goes back to the member who spoke,
 * to put in the command palette or the assistant box; nothing here runs it. The clip is read into memory,
 * handed to the gateway and dropped with the request: no file, no row, no log line holds it or its text.
 */
final class VoiceController
{
    public function __construct(
        private readonly AiControllerSupport $support,
        private readonly AiGateway $gateway,
    ) {}

    public function transcribe(TranscribeRequest $request): JsonResponse
    {
        $context = $this->support->context($request);

        /** @var UploadedFile $file */
        $file = $request->file('audio');
        $mime = (string) $file->getMimeType();
        $language = $request->validated('language');

        $clip = new AudioClip(
            (string) $file->get(),
            $mime,
            TranscribeRequest::EXTENSIONS[$mime] ?? 'webm',
            is_string($language) ? $language : app()->getLocale(),
        );

        // A new key per request: the browser cannot choose it, so it cannot replay a clip it was not charged for.
        $transcript = $this->gateway->transcribe($clip, 'ai:voice:'.Str::ulid(), $context->membership->id);

        // Plain text, one line, bounded like a typed message.
        $text = Str::limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags($transcript->text))), (int) config('ai.limits.max_input_chars', 6000), '');

        return new JsonResponse(['text' => $text]);
    }
}
