<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

/** A stored file's bytes, handed back to an authorized caller. */
final readonly class MediaContents
{
    public function __construct(
        public StoredMedia $media,
        public string $bytes,
    ) {}

    /**
     * Response headers an app must send when it serves the file: the sniffed type, `nosniff`, a download (never
     * inline), no caching, and a sandboxing CSP so even an HTML/SVG file cannot run anything.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($this->media->filename ?? $this->media->id)) ?: $this->media->id;

        return [
            'Content-Type' => $this->media->mimeType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment; filename="'.mb_substr($name, 0, 120).'"',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Length' => (string) strlen($this->bytes),
        ];
    }
}
