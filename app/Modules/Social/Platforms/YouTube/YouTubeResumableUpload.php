<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\YouTube;

use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\MediaFile;
use App\Modules\Social\Platforms\PublishedPost;
use App\Modules\Social\Platforms\PublishProgress;
use App\Modules\Social\Platforms\PublishSession;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * One YouTube video through the documented resumable upload of `videos.insert`
 * (`POST {upload_base}/videos?uploadType=resumable&part=snippet,status`):
 *
 *  1. the start call sends the metadata (title, description, category, privacy) and the file size and type,
 *     and YouTube answers with an upload session URI in `Location`. That run ends there, so the session is
 *     saved before any byte is sent;
 *  2. each later run `PUT`s chunks (a multiple of 256 KiB) with `Content-Range: bytes a-b/total`; `308` with a
 *     `Range: bytes=0-n` header means "continue at n + 1";
 *  3. the chunk that completes the file is answered `200`/`201` with the video resource: the video exists from
 *     then on. Nothing exists on YouTube before it, so every failure up to then is retried (the next run asks
 *     for the upload status with an empty `PUT` and `Content-Range: bytes * /total` and resumes). A lost answer
 *     to the completing chunk is an unknown outcome and is never repeated.
 *
 * The session URI is a capability for this one upload: it is kept only in the publish session the publisher
 * persists, encrypted with the application key, and never logged or put in a message. It is sent only to the
 * Google upload host, together with the access token in the Authorization header.
 */
final class YouTubeResumableUpload
{
    /** Resumable chunks must be multiples of 256 KiB (except the last). */
    public const int CHUNK_UNIT = 256 * 1024;

    /**
     * @param  Closure(): PendingRequest  $client
     * @param  Closure(Closure(): Response): Response  $send
     */
    public function __construct(
        private readonly Closure $client,
        private readonly Closure $send,
        private readonly Encrypter $encrypter,
        private readonly string $uploadBase,
        private readonly int $chunkBytes,
        private readonly string $privacy,
    ) {}

    /** @throws PublishFailed */
    public function advance(AccountCredentials $account, MediaFile $file, YouTubeVideoText $text, PublishSession $session, float $deadline): PublishProgress
    {
        $size = $file->sizeBytes ?? strlen($file->contents());

        if ($size < 1) {
            throw PublishFailed::rejected(__('The video file is empty.'), 'media_missing');
        }

        $uri = $this->sessionUri($session->meta, $size);

        if ($uri === null) {
            return $this->start($account, $file, $text, $size);
        }

        // Only the saved session travels on: a run that makes progress resets the publisher's failure count.
        $meta = ['upload' => $session->meta['upload'], 'size' => $size];
        $offset = $session->offset;

        // The previous run failed part-way: ask YouTube how much it really has before sending more.
        if (isset($session->meta['failures'])) {
            $status = $this->status($account, $uri, $size, $file->mime);

            if ($status instanceof PublishedPost) {
                return PublishProgress::published($status);
            }

            if ($status === null) {
                return $this->start($account, $file, $text, $size);
            }

            $offset = $status;
        }

        $chunk = $this->chunkSize();
        $first = true;

        while ($offset < $size) {
            if (! $first && microtime(true) >= $deadline) {
                return new PublishProgress('uploading', null, $offset, $meta);
            }

            $first = false;
            $length = min($chunk, $size - $offset);
            $bytes = $file->range($offset, $length);

            if (strlen($bytes) !== $length) {
                throw PublishFailed::rejected(__('A media file is missing from storage.'), 'media_missing');
            }

            $reply = $this->put($account, $uri, $bytes, $offset, $size, $file->mime);

            if ($reply->status() === 308) {
                $next = $this->confirmed($reply);

                if ($next <= $offset || $next > $size) {
                    throw new PublishFailed(__('YouTube did not confirm the uploaded part of the video. The upload continues later.'), 'upload_stalled', retryable: true);
                }

                $offset = $next;

                continue;
            }

            return PublishProgress::published($this->video($reply));
        }

        // YouTube holds every byte but has not answered with the video: ask again on the next run.
        throw new PublishFailed(__('YouTube did not confirm the uploaded part of the video. The upload continues later.'), 'upload_stalled', retryable: true);
    }

    /**
     * Start an upload session. Nothing is created on YouTube until the last byte arrives, so any failure
     * here is safe to retry.
     *
     * @throws PublishFailed
     */
    private function start(AccountCredentials $account, MediaFile $file, YouTubeVideoText $text, int $size): PublishProgress
    {
        $url = $this->uploadBase.'/videos?'.http_build_query(['uploadType' => 'resumable', 'part' => 'snippet,status']);
        $response = $this->beforeCreated(fn (): Response => ($this->client)()
            ->withToken($account->accessToken)
            ->withHeaders([
                'X-Upload-Content-Length' => (string) $size,
                'X-Upload-Content-Type' => $file->mime,
            ])
            ->asJson()
            ->post($url, [
                'snippet' => [
                    'title' => $text->title,
                    'description' => $text->description,
                    'categoryId' => '22',
                ],
                'status' => [
                    'privacyStatus' => $this->privacy,
                    'selfDeclaredMadeForKids' => false,
                ],
            ]));

        $location = $response->header('Location');

        if (! $this->trustedUri($location)) {
            throw new PublishFailed(__('YouTube did not start the video upload.'), 'upload_not_started', retryable: true);
        }

        return new PublishProgress('uploading', null, 0, [
            'upload' => $this->encrypter->encrypt($location, false),
            'size' => $size,
        ]);
    }

    /**
     * Send one chunk. A lost answer is retried through the status query, except for the chunk that
     * completes the file: YouTube may have created the video, so that outcome stays unknown.
     *
     * @throws PublishFailed
     */
    private function put(AccountCredentials $account, string $uri, string $bytes, int $offset, int $size, string $mime): Response
    {
        $completes = $offset + strlen($bytes) === $size;
        $last = $offset + strlen($bytes) - 1;

        try {
            $reply = ($this->send)(fn (): Response => ($this->client)()
                ->withoutRedirecting()
                ->timeout(30)
                ->withToken($account->accessToken)
                ->withHeaders(['Content-Range' => 'bytes '.$offset.'-'.$last.'/'.$size])
                ->withBody($bytes, $mime)
                ->put($uri));
        } catch (PublishFailed $failure) {
            throw $this->classify($failure, $completes);
        }

        if ($reply->status() === 308 || $reply->successful()) {
            return $reply;
        }

        if ($this->sessionGone($reply)) {
            throw new PublishFailed(__('The YouTube upload session expired. The upload starts again.'), 'upload_session_expired', retryable: true);
        }

        throw $this->classify(YouTubeErrors::failure($reply), $completes);
    }

    /**
     * Ask how many bytes YouTube holds (`308` + `Range`), whether the video is complete (`200`/`201`), or
     * learn that the session is gone (`null`).
     *
     * @throws PublishFailed
     */
    private function status(AccountCredentials $account, string $uri, int $size, string $mime): int|PublishedPost|null
    {
        $reply = $this->beforeCreated(fn (): Response => ($this->client)()
            ->withoutRedirecting()
            ->withToken($account->accessToken)
            ->withHeaders(['Content-Range' => 'bytes */'.$size])
            ->withBody('', $mime)
            ->put($uri), allowResume: true);

        if ($reply->status() === 308) {
            return min($size, $this->confirmed($reply));
        }

        if ($this->sessionGone($reply)) {
            return null;
        }

        return $this->video($reply);
    }

    /**
     * A request made before the video exists: an unknown outcome there is retryable.
     *
     * @param  Closure(): Response  $request
     *
     * @throws PublishFailed
     */
    private function beforeCreated(Closure $request, bool $allowResume = false): Response
    {
        try {
            $response = ($this->send)($request);
        } catch (PublishFailed $failure) {
            throw $this->classify($failure, false);
        }

        if ($response->successful() || ($allowResume && ($response->status() === 308 || $this->sessionGone($response)))) {
            return $response;
        }

        throw $this->classify(YouTubeErrors::failure($response), false);
    }

    private function classify(PublishFailed $failure, bool $completes): PublishFailed
    {
        if (! $failure->outcomeUnknown) {
            return $failure;
        }

        if ($completes) {
            return PublishFailed::unknownOutcome(
                __('YouTube did not confirm the end of the upload; the video may exist. Check the channel before publishing again.'),
                'outcome_unknown',
            );
        }

        return new PublishFailed($failure->getMessage(), $failure->errorCode, retryable: true);
    }

    /** @throws PublishFailed */
    private function video(Response $response): PublishedPost
    {
        $id = $response->json('id');

        if (! is_string($id) || $id === '') {
            throw PublishFailed::unknownOutcome(
                __('YouTube finished the upload but returned no video id. Check the channel before publishing again.'),
                'missing_post_id',
            );
        }

        return new PublishedPost($id, 'https://www.youtube.com/watch?v='.rawurlencode($id));
    }

    /** The first byte YouTube does not have yet, from `Range: bytes=0-n` (no header: nothing received). */
    private function confirmed(Response $response): int
    {
        $range = $response->header('Range');

        if (preg_match('/^bytes=0-(\d+)$/', trim($range), $match) !== 1) {
            return 0;
        }

        return (int) $match[1] + 1;
    }

    private function sessionGone(Response $response): bool
    {
        return in_array($response->status(), [404, 410], true);
    }

    private function chunkSize(): int
    {
        return max(self::CHUNK_UNIT, intdiv(max(0, $this->chunkBytes), self::CHUNK_UNIT) * self::CHUNK_UNIT);
    }

    /** @param array<string, mixed> $meta */
    private function sessionUri(array $meta, int $size): ?string
    {
        if (! is_string($meta['upload'] ?? null) || (int) ($meta['size'] ?? 0) !== $size) {
            return null;
        }

        try {
            $uri = $this->encrypter->decrypt($meta['upload'], false);
        } catch (DecryptException) {
            return null;
        }

        return is_string($uri) && $this->trustedUri($uri) ? $uri : null;
    }

    /** The session URI must be an https URL on Google's upload host: the access token is sent to it. */
    private function trustedUri(mixed $uri): bool
    {
        if (! is_string($uri) || $uri === '') {
            return false;
        }

        $parts = parse_url($uri);
        $expected = parse_url($this->uploadBase, PHP_URL_HOST);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && is_string($expected)
            && strcasecmp($parts['host'], $expected) === 0;
    }
}
