<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One voice clip (ADR 0038). The type is checked from the content (`mimetypes`), not from the name or the
 * type the browser declared. Authorization is the route permission.
 */
final class TranscribeRequest extends FormRequest
{
    /**
     * Containers a browser's `MediaRecorder` produces (WebM/Ogg Opus, MP4/AAC on Safari) and the usual
     * uploads, mapped to the file extension a transcription server recognises. Content sniffing labels WebM
     * and MP4 audio as `video/*` when it cannot tell, so both are accepted.
     */
    public const array EXTENSIONS = [
        'audio/webm' => 'webm',
        'video/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'mp4',
        'video/mp4' => 'mp4',
        'audio/x-m4a' => 'm4a',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave' => 'wav',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'audio' => [
                'required', 'file',
                'max:'.(int) config('ai.transcription.max_kilobytes', 2048),
                'mimetypes:'.implode(',', array_keys(self::EXTENSIONS)),
            ],
            'language' => ['nullable', 'string', Rule::in(array_keys((array) config('platform.locales', [])))],
        ];
    }
}
