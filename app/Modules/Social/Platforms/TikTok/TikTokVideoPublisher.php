<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\TikTok;

use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\MediaFile;
use App\Modules\Social\Platforms\PublishedPost;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\PublishProgress;
use App\Modules\Social\Platforms\PublishSession;
use Illuminate\Http\Client\Response;

/**
 * One TikTok video through the Content Posting API, Direct Post, `FILE_UPLOAD`:
 *
 *  1. `POST /v2/post/publish/creator_info/query/` - the privacy levels the creator may use, their comment /
 *     duet / stitch settings and the longest video they may post (a longer video is refused here);
 *  2. `POST /v2/post/publish/video/init/` - caption, privacy and the chunk plan; returns `publish_id` and an
 *     `upload_url`;
 *  3. one `PUT {upload_url}` per chunk with `Content-Range` and the video's `Content-Type`, read from storage
 *     with `MediaFile::range()` (206 per chunk, 201 when TikTok has the whole file);
 *  4. `POST /v2/post/publish/status/fetch/` across runs until `PUBLISH_COMPLETE` or `FAILED`.
 *
 * TikTok posts the video on its own once the upload is complete. Before `init` nothing exists on TikTok and
 * failures are retryable; a lost answer to `init` is an unknown outcome and is never retried. After `init`
 * the publish is never started again: one `publish_id` can only ever become one post, so a chunk that failed
 * is sent again to the same upload, and only a possibly-complete upload that cannot be confirmed is an
 * unknown outcome.
 *
 * Settings: the privacy level, the comment / duet / stitch switches and the commercial content disclosure come
 * from what the creator chose in the composer (`PublishPayload::$targetOptions`, {@see TikTokPostOptions}); the
 * chosen privacy level must still be offered by the fresh creator info, otherwise nothing is created. Only a
 * target saved before these settings existed (options null) keeps the earlier automatic choice:
 * `PUBLIC_TO_EVERYONE` when offered, otherwise `SELF_ONLY`, and the creator's own interaction settings.
 */
final class TikTokVideoPublisher
{
    public function __construct(
        private readonly TikTokApi $api,
        private readonly TikTokUploadUrls $urls,
        private readonly int $preferredChunk,
    ) {}

    /** @throws PublishFailed */
    public function advance(AccountCredentials $account, PublishPayload $payload, PublishSession $session, float $deadline): PublishProgress
    {
        $file = $payload->media[0] ?? null;

        if ($file === null || ! $file->isVideo || count($payload->media) !== 1) {
            throw PublishFailed::rejected(__('TikTok publishes exactly one video per post.'), 'unsupported_media');
        }

        $poll = max(1, (int) config('social.publishing.processing_poll_seconds', 60));

        if ($session->externalId !== null) {
            return $this->poll($account, $session->externalId, $session->meta, $poll);
        }

        $meta = $session->meta;
        $publishId = is_string($meta['publish_id'] ?? null) && $meta['publish_id'] !== '' ? $meta['publish_id'] : null;

        if ($publishId === null) {
            [$meta, $uploadUrl] = $this->start($account, $payload, $file, $meta);

            return $this->upload($file, (string) $meta['publish_id'], $uploadUrl, 0, $meta, $deadline, $poll);
        }

        return $this->upload($file, $publishId, null, $session->offset, $meta, $deadline, $poll);
    }

    /**
     * Creator info, then `init`. Returns the session meta and the upload URL (kept in memory only).
     *
     * @param  array<string, mixed>  $meta
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws PublishFailed
     */
    private function start(AccountCredentials $account, PublishPayload $payload, MediaFile $file, array $meta): array
    {
        $plan = TikTokChunkPlan::for($file->sizeBytes ?? strlen($file->contents()), $this->preferredChunk);
        $creator = $this->api->call($account->accessToken, 'post/publish/creator_info/query/', [], read: true);
        $max = $creator['max_video_post_duration_sec'] ?? null;

        if (is_numeric($max) && (int) $max > 0 && $file->durationMs !== null && $file->durationMs > (int) $max * 1000) {
            throw PublishFailed::rejected(
                __('This TikTok account can post videos of up to :max seconds, and this video is longer.', ['max' => (string) (int) $max]),
                'video_too_long',
            );
        }

        $settings = $payload->targetOptions === null
            ? self::legacySettings($creator)
            : TikTokPostOptions::postInfo($payload->targetOptions, $creator);
        $privacy = $settings['privacy_level'];

        // From here on TikTok holds a pending post: a lost answer is an unknown outcome, never retried blindly.
        $init = $this->api->call($account->accessToken, 'post/publish/video/init/', [
            'post_info' => ['title' => $payload->text, ...$settings],
            'source_info' => [
                'source' => 'FILE_UPLOAD',
                'video_size' => $plan->size,
                'chunk_size' => $plan->chunkSize,
                'total_chunk_count' => $plan->count,
            ],
        ], read: false);

        $publishId = $init['publish_id'] ?? null;
        $uploadUrl = $init['upload_url'] ?? null;

        if (! is_string($publishId) || $publishId === '' || ! is_string($uploadUrl) || ! self::isUploadUrl($uploadUrl)) {
            throw PublishFailed::unknownOutcome(
                __('TikTok did not return the upload details. Check the TikTok account before trying again.'),
                'upload_not_started',
            );
        }

        $username = $creator['creator_username'] ?? null;

        return [[
            ...$meta,
            'publish_id' => $publishId,
            'size' => $plan->size,
            'chunk_size' => $plan->chunkSize,
            'chunk_count' => $plan->count,
            'mime' => $file->mime,
            'privacy' => $privacy,
            'username' => is_string($username) && preg_match('/^[A-Za-z0-9._]{1,64}$/', $username) === 1 ? $username : null,
        ], $uploadUrl];
    }

    /**
     * Send the chunks from `$offset` on until the deadline. The upload URL is kept between runs only in
     * {@see TikTokUploadUrls}.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws PublishFailed
     */
    private function upload(MediaFile $file, string $publishId, ?string $uploadUrl, int $offset, array $meta, float $deadline, int $poll): PublishProgress
    {
        $plan = new TikTokChunkPlan((int) ($meta['size'] ?? 0), (int) ($meta['chunk_size'] ?? 0), (int) ($meta['chunk_count'] ?? 0));

        if ($plan->size < 1 || $plan->chunkSize < 1 || $plan->count < 1) {
            throw PublishFailed::rejected(__('The TikTok upload could not continue. Nothing was posted; publish the post again.'), 'upload_state_invalid');
        }

        $uploadUrl ??= $this->urls->find($publishId);

        if ($uploadUrl === null) {
            if (($meta['uncertain'] ?? false) === true) {
                throw PublishFailed::unknownOutcome(
                    __('TikTok may have received the whole video, but did not confirm it. Check the TikTok account before trying again.'),
                    'upload_unconfirmed',
                );
            }

            throw PublishFailed::rejected(
                __('The TikTok upload link expired before the upload finished. Nothing was posted; publish the post again.'),
                'upload_expired',
            );
        }

        $mime = is_string($meta['mime'] ?? null) ? $meta['mime'] : $file->mime;
        $index = $plan->indexAt($offset);
        $first = true;

        while ($index < $plan->count) {
            [$start, $length] = $plan->range($index);

            if (! $first && microtime(true) >= $deadline) {
                $this->urls->remember($publishId, $uploadUrl);

                return new PublishProgress('uploading', $publishId, $start, $meta);
            }

            $first = false;
            $bytes = $file->range($start, $length);

            if (strlen($bytes) !== $length) {
                $this->urls->forget($publishId);

                throw PublishFailed::rejected(__('A media file is missing from storage.'), 'media_missing');
            }

            $last = $index === $plan->count - 1;
            $result = $this->put($uploadUrl, $bytes, $start, $plan->size, $mime);

            if ($result instanceof PublishFailed) {
                return $this->chunkFailed($publishId, $uploadUrl, $start, $meta, $result, $last, $poll);
            }

            unset($meta['chunk_failures']);
            $index++;

            if ($result === 'complete') {
                break;
            }
        }

        // TikTok has the whole video and posts it on its own: from now on only the status is read.
        $this->urls->forget($publishId);
        unset($meta['chunk_failures'], $meta['uncertain']);

        return new PublishProgress('processing', $publishId, $plan->size, $meta, externalId: $publishId, retryAfterSeconds: $poll);
    }

    /**
     * PUT one chunk. A failure is returned, not thrown, so the caller can keep the session.
     *
     * @return 'accepted'|'complete'|PublishFailed
     */
    private function put(string $uploadUrl, string $bytes, int $start, int $size, string $mime): string|PublishFailed
    {
        $end = $start + strlen($bytes) - 1;

        try {
            $response = $this->api->send(fn (): Response => $this->api->request()->timeout(25)
                ->withHeaders(['Content-Range' => 'bytes '.$start.'-'.$end.'/'.$size])
                ->withBody($bytes, $mime)
                ->put($uploadUrl));
        } catch (PublishFailed $failure) {
            return $failure;
        }

        if ($response->status() === 201) {
            return 'complete';
        }

        if ($response->successful()) {
            return 'accepted';
        }

        if ($response->status() === 429 || $response->serverError()) {
            $header = $response->header('Retry-After');

            return new PublishFailed(
                __('TikTok did not confirm a part of the video upload.'),
                'upload_chunk_failed',
                retryable: ! $response->serverError(),
                retryAfterSeconds: is_numeric($header) ? max(1, min(3600, (int) $header)) : null,
                outcomeUnknown: $response->serverError(),
            );
        }

        return PublishFailed::rejected(__('TikTok did not accept a part of the video upload.'), 'upload_chunk_rejected');
    }

    /**
     * A chunk did not go through. A transient failure keeps the upload and sends the same chunk again
     * later (bounded by `step_failures_max`); a definite one ends it. Only the last chunk can have
     * completed the upload, so only its lost answer makes the outcome uncertain.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws PublishFailed
     */
    private function chunkFailed(string $publishId, string $uploadUrl, int $offset, array $meta, PublishFailed $failure, bool $last, int $poll): PublishProgress
    {
        $uncertain = ($meta['uncertain'] ?? false) === true || ($last && $failure->outcomeUnknown);

        if (! $failure->retryable && ! $failure->outcomeUnknown) {
            $this->urls->forget($publishId);

            if ($uncertain) {
                // An earlier try of the last chunk may have completed the upload: watch what TikTok does with it.
                return new PublishProgress('processing', $publishId, $offset, $meta, externalId: $publishId, retryAfterSeconds: $poll);
            }

            throw PublishFailed::rejected(__('TikTok did not accept the video upload. Nothing was posted.'), $failure->errorCode);
        }

        $failures = (int) ($meta['chunk_failures'] ?? 0) + 1;

        if ($failures >= max(1, (int) config('social.publishing.step_failures_max', 6))) {
            $this->urls->forget($publishId);

            throw $uncertain
                ? PublishFailed::unknownOutcome(
                    __('TikTok may have received the whole video, but did not confirm it. Check the TikTok account before trying again.'),
                    'upload_unconfirmed',
                )
                : PublishFailed::rejected(__('TikTok did not accept the video upload. Nothing was posted.'), 'upload_failed');
        }

        $this->urls->remember($publishId, $uploadUrl);
        $meta['chunk_failures'] = $failures;

        if ($uncertain) {
            $meta['uncertain'] = true;
        }

        return new PublishProgress('uploading', $publishId, $offset, $meta, retryAfterSeconds: $failure->retryAfterSeconds ?? min(300, 30 * $failures));
    }

    /**
     * @param  array<string, mixed>  $meta
     *
     * @throws PublishFailed
     */
    private function poll(AccountCredentials $account, string $publishId, array $meta, int $seconds): PublishProgress
    {
        try {
            $data = $this->api->call($account->accessToken, 'post/publish/status/fetch/', ['publish_id' => $publishId], read: true);
        } catch (PublishFailed $failure) {
            if ($failure->retryable) {
                throw $failure;
            }

            // TikTok has the whole video and posts it on its own: an error now does not prove it was not posted.
            throw new PublishFailed($failure->getMessage(), $failure->errorCode, outcomeUnknown: true, reauthorize: $failure->reauthorize);
        }

        return match ($data['status'] ?? null) {
            'PUBLISH_COMPLETE' => PublishProgress::published(self::published($publishId, $data, $meta)),
            'FAILED' => throw TikTokErrors::failedPost($data['fail_reason'] ?? null),
            'SEND_TO_USER_INBOX' => throw PublishFailed::unknownOutcome(
                __('TikTok sent the video to the creator\'s TikTok inbox instead of posting it. Finish or delete it in the TikTok app.'),
                'tiktok_sent_to_inbox',
            ),
            // PROCESSING_UPLOAD, PROCESSING_DOWNLOAD or anything new: not proven posted, keep waiting.
            default => new PublishProgress('processing', $publishId, (int) ($meta['size'] ?? 0), $meta, externalId: $publishId, retryAfterSeconds: $seconds),
        };
    }

    /**
     * The public post id once TikTok has one (`publicaly_available_post_id`, TikTok's spelling); a private
     * (`SELF_ONLY`) post never gets one, so the `publish_id` stands in.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private static function published(string $publishId, array $data, array $meta): PublishedPost
    {
        $postId = null;

        foreach ((array) ($data['publicaly_available_post_id'] ?? []) as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $postId = (string) $id;

                break;
            }
        }

        $username = $meta['username'] ?? null;
        $url = $postId !== null && is_string($username) && $username !== ''
            ? 'https://www.tiktok.com/@'.$username.'/video/'.$postId
            : null;

        return new PublishedPost($postId ?? $publishId, $url);
    }

    /**
     * A target saved before the creator chose its settings (options null): the earlier automatic choice, kept
     * only so such posts still publish as they were scheduled. The creator's own interaction settings are kept.
     *
     * @param  array<string, mixed>  $creator
     * @return array{privacy_level: string, disable_comment: bool, disable_duet: bool, disable_stitch: bool}
     *
     * @throws PublishFailed
     */
    private static function legacySettings(array $creator): array
    {
        return [
            'privacy_level' => self::privacy($creator['privacy_level_options'] ?? null),
            'disable_comment' => ($creator['comment_disabled'] ?? false) === true,
            'disable_duet' => ($creator['duet_disabled'] ?? false) === true,
            'disable_stitch' => ($creator['stitch_disabled'] ?? false) === true,
        ];
    }

    /** `PUBLIC_TO_EVERYONE` when the creator may use it, otherwise `SELF_ONLY` (legacy targets only). */
    private static function privacy(mixed $options): string
    {
        if (! is_array($options)) {
            return 'SELF_ONLY';
        }

        foreach (['PUBLIC_TO_EVERYONE', 'SELF_ONLY'] as $level) {
            if (in_array($level, $options, true)) {
                return $level;
            }
        }

        throw PublishFailed::rejected(__('TikTok offered no privacy level this app can post with for this account.'), 'tiktok_privacy_unavailable');
    }

    /** The upload URL TikTok returned must be an HTTPS address on TikTok's own domains. */
    private static function isUploadUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && (str_ends_with($host, '.tiktokapis.com') || str_ends_with($host, '.tiktok.com'));
    }
}
