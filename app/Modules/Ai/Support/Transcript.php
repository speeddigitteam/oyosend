<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

/** What a transcription provider heard. Returned to the member who spoke; never stored. */
final readonly class Transcript
{
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
    ) {}
}
