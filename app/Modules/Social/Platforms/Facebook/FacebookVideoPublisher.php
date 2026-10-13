<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\PublishProgress;
use App\Modules\Social\Platforms\PublishSession;

/**
 * A Facebook Page video through the documented resumable upload on `{page-id}/videos`:
 * `upload_phase=start` (file size), `transfer` (chunk by the offsets Facebook returns) and `finish`,
 * then `status.video_status` is polled until `ready`.
 *
 * The bytes are read in the chunks Facebook asks for, from tenant storage, never as one string.
 * Nothing is public before `finish`: failures up to then are retryable. The `finish` call is the only
 * one whose outcome can be unknown.
 */
final class FacebookVideoPublisher
{
    /** Never send more than this in one request, whatever range Facebook names. */
    private const int MAX_CHUNK_BYTES = 64 * 1024 * 1024;

    public function __construct(private readonly FacebookGraph $graph) {}

    /** @throws PublishFailed */
    public function advance(AccountCredentials $account, PublishPayload $payload, PublishSession $session, float $deadline): PublishProgress
    {
        $file = $payload->media[0] ?? throw PublishFailed::rejected(__('A video post needs a video.'), 'media_missing');
        $page = $account->externalId;
        $poll = (int) config('social.publishing.processing_poll_seconds', 60);

        if ($session->externalId !== null) {
            return $this->poll($account, $session->externalId, $session->meta, $poll);
        }

        $size = $file->sizeBytes ?? strlen($file->contents());

        if ($size < 1) {
            throw PublishFailed::rejected(__('The video file is empty.'), 'media_missing');
        }

        $meta = $session->meta;
        $videoId = $session->remoteId;

        if (! is_string($meta['session'] ?? null) || $videoId === null) {
            $start = $this->graph->beforePublish(fn () => $this->graph->request()->withToken($account->accessToken)
                ->asForm()->post($this->graph->url($page.'/videos'), ['upload_phase' => 'start', 'file_size' => $size]));
            $sessionId = $start->json('upload_session_id');
            $videoId = $start->json('video_id');
            $from = $start->json('start_offset');
            $to = $start->json('end_offset');

            if (! is_string($sessionId) && ! is_int($sessionId) || ! is_string($videoId) && ! is_int($videoId) || ! $this->isOffset($from) || ! $this->isOffset($to)) {
                throw PublishFailed::rejected(__('Facebook did not start the video upload.'), 'upload_not_started');
            }

            $meta = ['session' => (string) $sessionId, 'start' => (int) $from, 'end' => (int) $to];
            $videoId = (string) $videoId;
        }

        $startAt = (int) $meta['start'];
        $endAt = (int) $meta['end'];
        $first = true;

        while ($startAt < $endAt) {
            if (! $first && microtime(true) >= $deadline) {
                return new PublishProgress('uploading', $videoId, $startAt, $meta);
            }

            $first = false;
            $length = $endAt - $startAt;

            if ($length > self::MAX_CHUNK_BYTES || $endAt > $size) {
                throw PublishFailed::rejected(__('Facebook asked for an unexpected part of the video.'), 'upload_range_invalid');
            }

            $chunk = $file->range($startAt, $length);

            if (strlen($chunk) !== $length) {
                throw PublishFailed::rejected(__('A media file is missing from storage.'), 'media_missing');
            }

            $reply = $this->graph->beforePublish(fn () => $this->graph->request()->withToken($account->accessToken)->timeout(30)
                ->attach('video_file_chunk', $chunk, 'chunk')
                ->post($this->graph->url($page.'/videos'), [
                    'upload_phase' => 'transfer',
                    'upload_session_id' => $meta['session'],
                    'start_offset' => (string) $startAt,
                ]));
            $nextStart = $reply->json('start_offset');
            $nextEnd = $reply->json('end_offset');

            if (! $this->isOffset($nextStart) || ! $this->isOffset($nextEnd) || (int) $nextStart <= $startAt) {
                throw PublishFailed::rejected(__('Facebook did not confirm the video upload.'), 'upload_stalled');
            }

            $startAt = (int) $nextStart;
            $endAt = (int) $nextEnd;
            $meta['start'] = $startAt;
            $meta['end'] = $endAt;
        }

        // Every byte is with Facebook: this call makes the video public, so it is the one that may be unknown.
        $text = $payload->linkUrl === null ? $payload->text : trim($payload->text."\n\n".$payload->linkUrl);
        $finish = $this->graph->send(fn () => $this->graph->request()->withToken($account->accessToken)->asForm()
            ->post($this->graph->url($page.'/videos'), [
                'upload_phase' => 'finish',
                'upload_session_id' => $meta['session'],
                'description' => $text,
            ]));
        $this->graph->ensure($finish);

        if ($finish->json('success') !== true) {
            throw PublishFailed::rejected(__('Facebook did not accept the video.'), 'finish_rejected');
        }

        return new PublishProgress('processing', $videoId, $size, $meta, externalId: $videoId, retryAfterSeconds: $poll);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function poll(AccountCredentials $account, string $videoId, array $meta, int $seconds): PublishProgress
    {
        // A read: a failure whose outcome is unknown is retried, never treated as a publish problem.
        $response = $this->graph->beforePublish(fn () => $this->graph->request()->withToken($account->accessToken)
            ->get($this->graph->url($videoId), FacebookVideoStatus::query()));
        $published = FacebookVideoStatus::interpret($response, $videoId);

        if ($published !== null) {
            return PublishProgress::published($published);
        }

        return new PublishProgress('processing', $videoId, 0, $meta, externalId: $videoId, retryAfterSeconds: $seconds);
    }

    private function isOffset(mixed $value): bool
    {
        return is_int($value) ? $value >= 0 : (is_string($value) && ctype_digit($value));
    }
}
