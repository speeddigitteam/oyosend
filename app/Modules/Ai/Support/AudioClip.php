<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

/**
 * One short recording to transcribe (ADR 0038). Held in memory for the length of one request: it is never
 * written to storage, a queue, a log or the audit trail, and a dump of it shows no audio.
 */
final readonly class AudioClip
{
    /**
     * @param  string  $bytes  The recording as uploaded.
     * @param  string  $mimeType  As detected from the content on the server, not as the browser declared it.
     * @param  string  $extension  File extension the provider uses to recognise the container (`webm`, `ogg`, ...).
     * @param  string|null  $language  ISO 639-1 code of the spoken language (`bn`, `en`); null lets the model detect it.
     */
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public string $extension,
        public ?string $language = null,
    ) {}

    public function size(): int
    {
        return strlen($this->bytes);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['mimeType' => $this->mimeType, 'extension' => $this->extension, 'language' => $this->language, 'bytes' => $this->size()];
    }
}
