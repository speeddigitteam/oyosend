<?php

declare(strict_types=1);

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Contracts\Transcriber;
use App\Modules\Ai\Support\AudioClip;
use App\Modules\Ai\Support\Transcript;
use LogicException;

/**
 * A scripted transcriber for local development and tests: whatever the clip, it "hears"
 * `ai.transcription.fake_text`. Refuses to exist outside `local` and `testing`.
 */
final class FakeTranscriber implements Transcriber
{
    /**
     * Clips received, for assertions (sizes and languages only).
     *
     * @var list<array{bytes: int, language: string|null}>
     */
    public array $clips = [];

    public function __construct()
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('The fake transcriber is only available in local and testing environments.');
        }
    }

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-transcriber';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function transcribe(AudioClip $clip): Transcript
    {
        $this->clips[] = ['bytes' => $clip->size(), 'language' => $clip->language];

        return new Transcript((string) config('ai.transcription.fake_text', 'Contacts'), $this->name(), $this->model());
    }
}
