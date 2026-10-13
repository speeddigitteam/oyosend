<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\PublishProgress;
use App\Modules\Social\Platforms\PublishSession;

/**
 * A Facebook Reel through the documented three-phase `{page-id}/video_reels` flow:
 * `upload_phase=start` (returns the video id), the upload to `rupload.facebook.com/video-upload/{version}/{video-id}`
 * (`Authorization: OAuth`, `offset` and `file_size` headers, binary body) and `upload_phase=finish` with
 * `video_state=PUBLISHED`; then `status.video_status` is polled until `ready`.
 *
 * Our own scheduler decides when the Reel is published, so `finish` always asks for PUBLISHED. The upload
 * is sent in chunks (the `offset` header carries the resume point); nothing is public before `finish`.
 */
final class FacebookReelPublisher
{
    public function __construct(private readonly FacebookGraph $graph) {}

    /** @throws PublishFailed */
    public function advance(AccountCredentials $account, PublishPayload $payload, PublishSession $session, float $deadline): PublishProgress
    {
        $file = $payload->media[0] ?? throw PublishFailed::rejected(__('A Reel needs a video.'), 'media_missing');
        $page = $account->externalId;
        $poll = (int) config('social.publishing.processing_poll_seconds', 60);

        if ($session->externalId !== null) {
            return $this->poll($account, $session->externalId, $session->meta, $poll);
        }

        $size = $file->sizeBytes ?? strlen($file->contents());

        if ($size < 1) {
            throw PublishFailed::rejected(__('The video file is empty.'), 'media_missing');
        }

        $videoId = $session->remoteId;

        if ($videoId === null) {
            $start = $this->graph->beforePublish(fn () => $this->graph->request()->withToken($account->accessToken)
                ->asForm()->post($this->graph->url($page.'/video_reels'), ['upload_phase' => 'start']));
            $id = $start->json('video_id');

            if (! is_string($id) && ! is_int($id)) {
                throw PublishFailed::rejected(__('Facebook did not start the Reel upload.'), 'upload_not_started');
            }

            $videoId = (string) $id;
        }

        $chunk = max(256 * 1024, (int) config('social.platforms.facebook.limits.reel.chunk_bytes', 2 * 1024 * 1024));
        $offset = $session->offset;
        $first = true;

        while ($offset < $size) {
            if (! $first && microtime(true) >= $deadline) {
                return new PublishProgress('uploading', $videoId, $offset, $session->meta);
            }

            $first = false;
            $length = min($chunk, $size - $offset);
            $bytes = $file->range($offset, $length);

            if (strlen($bytes) !== $length) {
                throw PublishFailed::rejected(__('A media file is missing from storage.'), 'media_missing');
            }

            $at = $offset;
            $reply = $this->graph->beforePublish(fn () => $this->graph->request()->timeout(30)
                ->withHeaders([
                    'Authorization' => 'OAuth '.$account->accessToken,
                    'offset' => (string) $at,
                    'file_size' => (string) $size,
                ])
                ->withBody($bytes, 'application/octet-stream')
                ->post($this->graph->uploadUrl($videoId)));

            if ($reply->json('success') !== true) {
                throw PublishFailed::rejected(__('Facebook did not confirm the Reel upload.'), 'upload_stalled');
            }

            $offset += $length;
        }

        $text = $payload->linkUrl === null ? $payload->text : trim($payload->text."\n\n".$payload->linkUrl);
        $finish = $this->graph->send(fn () => $this->graph->request()->withToken($account->accessToken)->asForm()
            ->post($this->graph->url($page.'/video_reels'), [
                'upload_phase' => 'finish',
                'video_id' => $videoId,
                'video_state' => 'PUBLISHED',
                'description' => $text,
            ]));
        $this->graph->ensure($finish);

        if ($finish->json('success') !== true) {
            throw PublishFailed::rejected(__('Facebook did not accept the Reel.'), 'finish_rejected');
        }

        return new PublishProgress('processing', $videoId, $size, $session->meta, externalId: $videoId, retryAfterSeconds: $poll);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function poll(AccountCredentials $account, string $videoId, array $meta, int $seconds): PublishProgress
    {
        $response = $this->graph->beforePublish(fn () => $this->graph->request()->withToken($account->accessToken)
            ->get($this->graph->url($videoId), FacebookVideoStatus::query()));
        $published = FacebookVideoStatus::interpret($response, $videoId, 'https://www.facebook.com/reel/');

        if ($published !== null) {
            return PublishProgress::published($published);
        }

        return new PublishProgress('processing', $videoId, 0, $meta, externalId: $videoId, retryAfterSeconds: $seconds);
    }
}
