<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Pinterest;

use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\MediaFile;
use App\Modules\Social\Platforms\PublishedPost;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\PublishProgress;
use App\Modules\Social\Platforms\PublishSession;
use GuzzleHttp\Psr7\PumpStream;
use Illuminate\Http\Client\Response;

/**
 * A video Pin through Pinterest's documented media upload:
 *
 *  1. `POST /v5/media` `{"media_type": "video"}` registers the upload and returns `media_id`, `upload_url` and
 *     `upload_parameters` (a signed, short-lived S3 form);
 *  2. a multipart `POST {upload_url}` with every upload parameter and then the file as `file` (streamed from
 *     storage, never one string in memory);
 *  3. `GET /v5/media/{media_id}` until `status` is `succeeded` (`failed` ends the publish);
 *  4. `POST /v5/pins` with `media_source` `{"source_type": "video_id", "media_id": ...}` and a cover: the
 *     post's one photo (`cover_image_content_type` / `cover_image_data`), else a frame of the video
 *     (`cover_image_key_frame_time`).
 *
 * Steps 1 and 2 run together in one run: the signed upload form is a credential and is never stored, so an
 * upload that fails is retried from a fresh registration. Nothing is public before step 4, so every failure
 * before it is retryable; step 4 is the only unknown outcome and is never repeated.
 */
final class PinterestVideoPublisher
{
    /** Seconds into the video used as the cover when the post has no photo for it. */
    private const int COVER_FRAME_SECONDS = 1;

    public function __construct(private readonly PinterestApi $api) {}

    /** @throws PublishFailed */
    public function advance(AccountCredentials $account, PublishPayload $payload, PublishSession $session, float $deadline): PublishProgress
    {
        if ($session->externalId !== null) {
            // The Pin was created; never create it again.
            return PublishProgress::published(new PublishedPost(
                $session->externalId, 'https://www.pinterest.com/pin/'.rawurlencode($session->externalId).'/',
            ));
        }

        $videos = array_values(array_filter($payload->media, static fn (MediaFile $file): bool => $file->isVideo));
        $images = array_values(array_filter($payload->media, static fn (MediaFile $file): bool => ! $file->isVideo));

        if (count($videos) !== 1) {
            throw PublishFailed::rejected(__('A Pinterest Pin carries one video. Remove the other videos or deselect the account.'), 'unsupported_media');
        }

        if (count($images) > 1) {
            throw PublishFailed::rejected(__('A Pinterest video Pin can carry one photo, used as its cover. Remove the other photos or deselect the account.'), 'unsupported_media');
        }

        $poll = max(5, (int) config('social.publishing.processing_poll_seconds', 60));

        if ($session->remoteId === null || $session->state !== 'processing') {
            $mediaId = $this->upload($account, $videos[0], $deadline);

            return new PublishProgress('processing', $mediaId, $videos[0]->sizeBytes ?? 0, $session->meta, retryAfterSeconds: $poll);
        }

        $status = $this->api->beforePublish(
            fn (): Response => $this->api->request()->withToken($account->accessToken)->get($this->api->url('media/'.rawurlencode($session->remoteId))),
            __('Pinterest no longer has the uploaded video. Try publishing again.'),
        )->json('status');

        if ($status === 'failed') {
            throw PublishFailed::rejected(__('Pinterest could not process this video.'), 'video_processing_failed');
        }

        if ($status !== 'succeeded') {
            // `registered` or `processing`: not ready yet. The processing timeout flags one that never finishes.
            return new PublishProgress('processing', $session->remoteId, $session->offset, $session->meta, retryAfterSeconds: $poll);
        }

        $source = ['source_type' => 'video_id', 'media_id' => $session->remoteId];

        if ($images !== []) {
            $cover = PinterestPins::encoded($images[0]);
            $source['cover_image_content_type'] = $cover['content_type'];
            $source['cover_image_data'] = $cover['data'];
        } else {
            $source['cover_image_key_frame_time'] = self::COVER_FRAME_SECONDS;
        }

        return PublishProgress::published(
            (new PinterestPins($this->api))->create($account, $payload, $source, $images[0] ?? $videos[0]),
        );
    }

    /**
     * Register the upload and send the file. Returns the media id.
     *
     * @throws PublishFailed
     */
    private function upload(AccountCredentials $account, MediaFile $video, float $deadline): string
    {
        if (! in_array(strtolower($video->mime), PinterestMediaRules::videoMimes(), true)) {
            throw PublishFailed::rejected(__('Pinterest accepts MP4 and MOV videos only.'), 'unsupported_media');
        }

        $size = $video->sizeBytes ?? strlen($video->contents());

        if ($size < 1) {
            throw PublishFailed::rejected(__('The video file is empty.'), 'media_missing');
        }

        $registered = $this->api->beforePublish(fn (): Response => $this->api->request()->withToken($account->accessToken)
            ->asJson()->post($this->api->url('media'), ['media_type' => 'video']));
        $mediaId = $registered->json('media_id');
        $uploadUrl = $registered->json('upload_url');
        $parameters = $registered->json('upload_parameters');

        if ((! is_string($mediaId) && ! is_int($mediaId)) || ! is_string($uploadUrl) || ! str_starts_with($uploadUrl, 'https://') || ! is_array($parameters)) {
            throw new PublishFailed(__('Pinterest did not start the video upload.'), 'upload_not_started', retryable: true);
        }

        // Every signed form field first, the file last (S3 ignores fields after the file).
        $parts = [];

        foreach ($parameters as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value))) {
                $parts[] = ['name' => $name, 'contents' => (string) $value];
            }
        }

        $parts[] = ['name' => 'file', 'contents' => $this->stream($video, $size), 'filename' => $video->name];

        // The upload is one request; give it what is left of this worker run (the job ends after 60 s).
        $timeout = max(15, (int) ($deadline - microtime(true)) + 20);

        try {
            $uploaded = $this->api->send(fn (): Response => $this->api->request()->timeout($timeout)
                ->asMultipart()->post($uploadUrl, $parts));
        } catch (PublishFailed $failure) {
            throw new PublishFailed($failure->getMessage(), $failure->errorCode, retryable: true);
        }

        // The upload goes to Pinterest's storage host, not the API: its errors say nothing about the account,
        // so any refusal is retried from a fresh registration (bounded by `step_failures_max`).
        if (! $uploaded->successful()) {
            throw new PublishFailed(__('Pinterest did not accept the video upload.'), 'upload_failed', retryable: true);
        }

        return (string) $mediaId;
    }

    /** The file as a sized stream read from storage in slices. */
    private function stream(MediaFile $video, int $size): PumpStream
    {
        $offset = 0;

        return new PumpStream(function (int $length) use ($video, $size, &$offset): string|false {
            if ($offset >= $size) {
                return false;
            }

            $chunk = $video->range($offset, min(max($length, 1024 * 1024), $size - $offset));

            if ($chunk === '') {
                throw PublishFailed::rejected(__('A media file is missing from storage.'), 'media_missing');
            }

            $offset += strlen($chunk);

            return $chunk;
        }, ['size' => $size]);
    }
}
