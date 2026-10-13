<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A stateful stand-in for the documented Graph API flows used by the Facebook publishers
 * (photos + feed, resumable `videos`, `video_reels` + rupload, video `status`). It answers
 * what the documentation says and lets a test inject one-shot failures per step. Tests only.
 */
final class FakeFacebookGraph
{
    /** @var list<array{step: string, url: string, data: array<string, mixed>, headers: array<string, string>, bytes: int}> */
    public array $log = [];

    /** @var array<string, list<PromiseInterface|\Closure>> one-shot overrides per step */
    private array $failures = [];

    /** @var list<string> */
    public array $statuses = ['ready'];

    public int $chunk = 1000;

    private int $photos = 0;

    private int $videoSize = 0;

    public string $statusError = 'Video could not be processed';

    public function install(): void
    {
        Http::fake(fn (Request $request) => $this->respond($request));
    }

    /** The next call of `$step` is answered with `$response` (a Http::response() or a closure that throws). */
    public function failNext(string $step, PromiseInterface|\Closure $response): void
    {
        $this->failures[$step][] = $response;
    }

    /** @return list<array{step: string, url: string, data: array<string, mixed>, headers: array<string, string>, bytes: int}> */
    public function calls(string $step): array
    {
        return array_values(array_filter($this->log, fn (array $call): bool => $call['step'] === $step));
    }

    public function count(string $step): int
    {
        return count($this->calls($step));
    }

    public static function error(int $code, string $message = 'Error', int $status = 400): PromiseInterface
    {
        return Http::response(['error' => ['message' => $message, 'code' => $code, 'type' => 'OAuthException']], $status);
    }

    public static function timeout(): \Closure
    {
        return function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds');
        };
    }

    private function respond(Request $request): mixed
    {
        $url = strtok($request->url(), '?') ?: $request->url();
        $data = $this->data($request);
        $step = $this->step($request, $url, $data);
        $bytes = 0;

        if ($step === 'videos:transfer') {
            $part = $data['video_file_chunk'] ?? '';
            $bytes = is_string($part) ? strlen($part) : 0;
            unset($data['video_file_chunk']);
        } elseif ($step === 'reels:upload') {
            $bytes = strlen($request->body());
        }

        $this->log[] = [
            'step' => $step, 'url' => $url, 'data' => $data,
            'headers' => array_map(fn (array $values): string => (string) ($values[0] ?? ''), $request->headers()),
            'bytes' => $bytes,
        ];

        $injected = empty($this->failures[$step]) ? null : array_shift($this->failures[$step]);

        if ($injected instanceof \Closure) {
            return $injected();
        }

        if ($injected !== null) {
            return $injected;
        }

        return match ($step) {
            'photos' => Http::response(['id' => 'photo-'.++$this->photos]),
            'feed' => Http::response(['id' => 'PAGE1_post']),
            'videos:start' => $this->startVideo($data),
            'videos:transfer' => $this->transfer($data),
            'videos:finish' => Http::response(['success' => true]),
            'reels:start' => Http::response(['video_id' => 'reel-1', 'upload_url' => 'https://rupload.facebook.com/video-upload/v23.0/reel-1']),
            'reels:upload' => Http::response(['success' => true]),
            'reels:finish' => Http::response(['success' => true, 'post_id' => 'PAGE1_999']),
            'status' => $this->status(),
            default => Http::response(['error' => ['message' => 'Unsupported fake call '.$step, 'code' => 100]], 400),
        };
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        $data = $request->data();

        if ($request->isMultipart()) {
            $flat = [];

            foreach ($request->data() as $part) {
                if (is_array($part) && isset($part['name'])) {
                    $flat[(string) $part['name']] = $part['contents'] ?? '';
                }
            }

            return $flat !== [] ? $flat : $data;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function step(Request $request, string $url, array $data): string
    {
        if (str_starts_with($url, 'https://rupload.facebook.com/')) {
            return 'reels:upload';
        }

        $path = substr($url, strlen('https://graph.facebook.com/v23.0/'));

        if ($request->method() === 'GET') {
            return str_contains((string) ($request->data()['fields'] ?? $request->url()), 'status') ? 'status' : 'get';
        }

        return match (true) {
            str_ends_with($path, '/photos') => 'photos',
            str_ends_with($path, '/feed') => 'feed',
            str_ends_with($path, '/videos') => 'videos:'.($data['upload_phase'] ?? 'start'),
            str_ends_with($path, '/video_reels') => 'reels:'.($data['upload_phase'] ?? 'start'),
            default => 'other',
        };
    }

    /** @param array<string, mixed> $data */
    private function startVideo(array $data): PromiseInterface
    {
        $this->videoSize = (int) ($data['file_size'] ?? 0);

        return Http::response([
            'upload_session_id' => 'sess-1',
            'video_id' => 'vid-1',
            'start_offset' => '0',
            'end_offset' => (string) min($this->chunk, $this->videoSize),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function transfer(array $data): PromiseInterface
    {
        $start = (int) ($data['start_offset'] ?? 0);
        $next = min($this->videoSize, $start + $this->chunk);
        $end = min($this->videoSize, $next + $this->chunk);

        return Http::response(['start_offset' => (string) $next, 'end_offset' => (string) $end]);
    }

    private function status(): PromiseInterface
    {
        $state = array_shift($this->statuses) ?? 'ready';

        if ($state === 'error') {
            return Http::response(['status' => [
                'video_status' => 'error',
                'processing_phase' => ['status' => 'error', 'errors' => [['code' => 1, 'message' => $this->statusError]]],
            ]]);
        }

        return Http::response([
            'status' => ['video_status' => $state, 'publishing_phase' => ['status' => $state === 'ready' ? 'complete' : 'not_started']],
            'permalink_url' => '/PAGE1/videos/vid-1/',
        ]);
    }
}
