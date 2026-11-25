<?php

declare(strict_types=1);

namespace App\Modules\Ai\Providers;

use App\Modules\Ai\Contracts\Transcriber;
use App\Modules\Ai\Support\AudioClip;
use App\Modules\Ai\Support\ProviderFailure;
use App\Modules\Ai\Support\ProviderNotConfigured;
use App\Modules\Ai\Support\ProviderSettings;
use App\Modules\Ai\Support\Transcript;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Any server that speaks the OpenAI transcription API (`POST {base_url}/audio/transcriptions`, multipart):
 * OpenAI (`whisper-1`, `gpt-4o-transcribe`) and self-hosted Whisper servers that copy it. The base URL includes
 * the version prefix (for example `https://api.openai.com/v1`).
 *
 * The audio goes only into the request body; the key, when there is one, only into the bearer header. Neither
 * appears in a log line, an exception message or a response. Failures are logged by status only.
 *
 * Not verified against a live server: it is exercised against faked HTTP responses (tests/Feature/Ai/VoiceTest.php).
 */
final class OpenAiTranscriber implements Transcriber
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ProviderSettings $settings,
    ) {}

    public function name(): string
    {
        return 'openai_compatible';
    }

    public function model(): string
    {
        return $this->settings->model;
    }

    public function isConfigured(): bool
    {
        return $this->settings->baseUrl !== '' && $this->settings->model !== '';
    }

    public function transcribe(AudioClip $clip): Transcript
    {
        if (! $this->isConfigured()) {
            throw new ProviderNotConfigured('No transcription provider is configured.');
        }

        // `json` is the one response format every compatible server accepts; it carries the text only.
        $fields = ['model' => $this->settings->model, 'response_format' => 'json'];

        if ($clip->language !== null) {
            $fields['language'] = $clip->language;
        }

        $pending = $this->http->acceptJson()->timeout($this->settings->timeout)
            ->attach('file', $clip->bytes, 'speech.'.$clip->extension, ['Content-Type' => $clip->mimeType]);

        if ($this->settings->apiKey !== '') {
            $pending = $pending->withToken($this->settings->apiKey);
        }

        try {
            $response = $pending->post(rtrim($this->settings->baseUrl, '/').'/audio/transcriptions', $fields);
        } catch (ConnectionException) {
            Log::warning('ai.transcription.failed', ['provider' => $this->name(), 'reason' => 'connection']);

            throw new ProviderFailure('The transcription provider could not be reached.', 'unreachable');
        }

        if (! $response->successful()) {
            Log::warning('ai.transcription.failed', ['provider' => $this->name(), 'status' => $response->status()]);

            throw new ProviderFailure(
                'The transcription provider failed the request (HTTP '.$response->status().').',
                match (true) {
                    in_array($response->status(), [401, 403], true) => 'unauthorized',
                    $response->status() === 429 => 'rate_limited',
                    default => 'http_error',
                },
            );
        }

        $text = $response->json('text');

        if (! is_string($text)) {
            throw new ProviderFailure('The transcription provider returned an unexpected answer.', 'bad_response');
        }

        return new Transcript($text, $this->name(), $this->model());
    }
}
