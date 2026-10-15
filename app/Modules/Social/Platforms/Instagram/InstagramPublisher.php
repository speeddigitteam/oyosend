<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Instagram;

use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\MediaFile;
use App\Modules\Social\Platforms\PublishedPost;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\PublishProgress;
use App\Modules\Social\Platforms\PublishSession;
use Illuminate\Http\Client\Response;

/**
 * Instagram content publishing (Instagram Graph API with Facebook Login), as documented by Meta:
 *
 *  1. optional `GET {ig-user}/content_publishing_limit` (the account's 24-hour quota);
 *  2. containers: `POST {ig-user}/media` with `image_url` (photo) or `media_type=REELS` + `video_url` (a video is
 *     always a Reel); for a carousel one child per file (`is_carousel_item=true`, videos `media_type=VIDEO`), each
 *     waited on until `FINISHED`, then `media_type=CAROUSEL` with `children`;
 *  3. `GET {container}?fields=status_code,status` until `FINISHED` (`IN_PROGRESS` waits for the next run,
 *     `ERROR` / `EXPIRED` fail), across worker runs;
 *  4. `POST {ig-user}/media_publish` with `creation_id`, the only call that makes anything public;
 *  5. `GET {media}?fields=permalink`, best effort.
 *
 * Instagram fetches each file itself from a temporary signed URL ({@see MediaFile::publicUrl()}), made right
 * before the container call and never stored, logged or put in a message. The session meta holds container ids
 * only. A failure before step 4 is retryable (unpublished containers expire on their own after 24 hours); only
 * step 4 can be an unknown outcome, and it is never repeated once it was accepted.
 */
final class InstagramPublisher
{
    /** Requests started in this run: the first always runs, later ones only before the deadline. */
    private int $requests = 0;

    private float $deadline = 0.0;

    public function __construct(private readonly InstagramGraph $graph) {}

    /** @throws PublishFailed */
    public function advance(AccountCredentials $account, PublishPayload $payload, PublishSession $session, float $deadline): PublishProgress
    {
        $this->requests = 0;
        $this->deadline = $deadline;
        $user = $account->externalId;

        if ($session->externalId !== null) {
            // media_publish was accepted in an earlier run: never call it again.
            return PublishProgress::published(new PublishedPost($session->externalId, $this->permalink($account, $session->externalId)));
        }

        $files = $payload->media;
        $this->assertPublishable($payload, $files);
        $caption = $this->caption($payload);
        $poll = max(1, (int) config('social.publishing.processing_poll_seconds', 60));
        $meta = $this->meta($session->meta);
        $container = is_string($meta['container'] ?? null) ? $meta['container'] : null;

        if ($container === null && ($meta['quota'] ?? false) !== true) {
            $this->tick();
            $this->checkQuota($account);
            $meta['quota'] = true;
        }

        if ($container === null && count($files) === 1) {
            if (! $this->may()) {
                return $this->pause('uploading', null, $meta);
            }

            $container = $this->createContainer($account, $files[0], $this->single($files[0], $caption));
            $meta['container'] = $container;
        } elseif ($container === null) {
            /** @var list<string> $children */
            $children = $meta['children'] ?? [];

            for ($index = count($children); $index < count($files); $index++) {
                if (! $this->may()) {
                    return $this->pause('uploading', null, [...$meta, 'children' => $children]);
                }

                $children[] = $this->createContainer($account, $files[$index], $this->child($files[$index]));
            }

            $meta['children'] = $children;
            $ready = (int) ($meta['ready'] ?? 0);

            while ($ready < count($children)) {
                if (! $this->may()) {
                    return $this->pause('processing', null, [...$meta, 'ready' => $ready]);
                }

                if (! $this->finished($account, $children[$ready])) {
                    return new PublishProgress('processing', null, 0, [...$meta, 'ready' => $ready], retryAfterSeconds: $poll);
                }

                $ready++;
            }

            $meta['ready'] = $ready;

            if (! $this->may()) {
                return $this->pause('processing', null, $meta);
            }

            $container = $this->createContainer($account, null, [
                'media_type' => 'CAROUSEL',
                'children' => implode(',', $children),
                'caption' => $caption,
            ]);
            $meta['container'] = $container;
        }

        if (($meta['finished'] ?? false) !== true) {
            if (! $this->may()) {
                return $this->pause('processing', $container, $meta);
            }

            if (! $this->finished($account, $container)) {
                return new PublishProgress('processing', $container, 0, $meta, retryAfterSeconds: $poll);
            }

            // Remembered, so a run that stops here publishes next time without asking again.
            $meta['finished'] = true;
        }

        if (! $this->may()) {
            return $this->pause('processing', $container, $meta);
        }

        $mediaId = $this->publish($account, $user, $container);

        return PublishProgress::published(new PublishedPost($mediaId, $this->permalink($account, $mediaId)));
    }

    /**
     * Likes and comments of a published item (`like_count`, `comments_count` on the IG Media node).
     *
     * @return array{likes: ?int, comments: ?int}
     *
     * @throws PublishFailed
     */
    public function counts(AccountCredentials $account, string $mediaId): array
    {
        $response = $this->graph->ensure($this->graph->send(fn (): Response => $this->graph->request($account->accessToken)
            ->get($this->graph->url($mediaId), ['fields' => 'like_count,comments_count'])));
        $int = static fn (mixed $value): ?int => is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;

        return ['likes' => $int($response->json('like_count')), 'comments' => $int($response->json('comments_count'))];
    }

    /**
     * @param  list<MediaFile>  $files
     *
     * @throws PublishFailed
     */
    private function assertPublishable(PublishPayload $payload, array $files): void
    {
        if ($files === []) {
            throw PublishFailed::rejected(__('Instagram does not publish text on its own. Add a photo or a video.'), 'media_missing');
        }

        $max = max(1, (int) config('social.platforms.instagram.limits.carousel_max', 10));

        if (count($files) > $max) {
            throw PublishFailed::rejected(__('Instagram takes at most :max photos and videos in one carousel.', ['max' => $max]), 'too_many_media');
        }

        if ($payload->postType->needsVideo() && (count($files) !== 1 || ! $files[0]->isVideo)) {
            throw PublishFailed::rejected(__('A Reel is exactly one video. Remove everything else.'), 'unsupported_media');
        }

        foreach ($files as $file) {
            if (! $file->hasPublicUrl()) {
                throw PublishFailed::rejected(__('This media file cannot be offered to Instagram.'), 'media_url_unavailable');
            }
        }
    }

    /** The caption: the text, plus the post's link as plain text (Instagram does not make caption links clickable). */
    private function caption(PublishPayload $payload): string
    {
        $text = $payload->text;

        if ($payload->linkUrl !== null && $payload->linkUrl !== '' && ! str_contains($text, $payload->linkUrl)) {
            $text = trim($text."\n\n".$payload->linkUrl);
        }

        $max = (int) config('social.platforms.instagram.limits.caption_max', 2200);

        if ($max > 0 && mb_strlen($text) > $max) {
            throw PublishFailed::rejected(__('The caption, with the link, is longer than Instagram accepts (:max characters).', ['max' => $max]), 'caption_too_long');
        }

        return $text;
    }

    /** @return array<string, string> */
    private function single(MediaFile $file, string $caption): array
    {
        return $file->isVideo
            ? ['media_type' => 'REELS', 'caption' => $caption, 'share_to_feed' => 'true']
            : ['caption' => $caption];
    }

    /** @return array<string, string> */
    private function child(MediaFile $file): array
    {
        return $file->isVideo
            ? ['media_type' => 'VIDEO', 'is_carousel_item' => 'true']
            : ['is_carousel_item' => 'true'];
    }

    /**
     * Creates one container. The file's public URL is made inside the request, right before it is sent.
     *
     * @param  array<string, string>  $fields
     *
     * @throws PublishFailed
     */
    private function createContainer(AccountCredentials $account, ?MediaFile $file, array $fields): string
    {
        $response = $this->graph->beforePublish(function () use ($account, $file, $fields): Response {
            if ($file !== null) {
                $fields[$file->isVideo ? 'video_url' : 'image_url'] = $file->publicUrl();
            }

            return $this->graph->request($account->accessToken)->asForm()
                ->post($this->graph->url($account->externalId.'/media'), $fields);
        });
        $id = $response->json('id');

        if ((! is_string($id) || $id === '') && ! is_int($id)) {
            throw new PublishFailed(__('Instagram did not return an id for the uploaded media.'), 'missing_container_id', retryable: true);
        }

        return (string) $id;
    }

    /**
     * Whether a container is ready to publish. `IN_PROGRESS` (or no status yet) is "not yet".
     *
     * @throws PublishFailed when Instagram reports the media failed, expired or was already published
     */
    private function finished(AccountCredentials $account, string $container): bool
    {
        $response = $this->graph->beforePublish(fn (): Response => $this->graph->request($account->accessToken)
            ->get($this->graph->url($container), ['fields' => 'status_code,status']));
        $status = $response->json('status_code');

        return match ($status) {
            'FINISHED' => true,
            'ERROR' => throw PublishFailed::rejected(
                InstagramGraphErrors::scrub(is_string($response->json('status')) && trim((string) $response->json('status')) !== ''
                    ? __('Instagram could not process the media: :reason', ['reason' => trim((string) $response->json('status'))])
                    : __('Instagram could not process the media.')),
                'media_processing_failed',
            ),
            'EXPIRED' => throw PublishFailed::rejected(
                __('Instagram discarded the uploaded media before it was published. Try publishing again.'),
                'media_container_expired',
            ),
            'PUBLISHED' => throw PublishFailed::unknownOutcome(
                __('Instagram reports this media as already published. Check the account before trying again.'),
                'already_published',
            ),
            default => false,
        };
    }

    /**
     * The account's 24-hour publishing quota. Advisory: only a reached quota, a throttle or a token or
     * permission problem stops the publish; any other answer lets Instagram decide at `media_publish`.
     *
     * @throws PublishFailed
     */
    private function checkQuota(AccountCredentials $account): void
    {
        $response = $this->graph->send(fn (): Response => $this->graph->request($account->accessToken)
            ->get($this->graph->url($account->externalId.'/content_publishing_limit'), ['fields' => 'config,quota_usage']));

        if (! $response->successful()) {
            $failure = InstagramGraphErrors::failure($response);

            if ($failure->reauthorize || $failure->retryable) {
                throw $failure;
            }

            return;
        }

        $usage = $response->json('data.0.quota_usage');
        $total = $response->json('data.0.config.quota_total') ?? config('social.platforms.instagram.limits.posts_per_day');

        if (is_numeric($usage) && is_numeric($total) && (int) $total > 0 && (int) $usage >= (int) $total) {
            throw PublishFailed::rateLimited(
                __('This Instagram account reached its limit of posts published through the API in 24 hours. Publishing continues automatically later.'),
                3600,
            );
        }
    }

    /**
     * The final call. A lost answer or a server error may have published the post: an unknown outcome, never repeated.
     *
     * @throws PublishFailed
     */
    private function publish(AccountCredentials $account, string $user, string $container): string
    {
        $response = $this->graph->ensure($this->graph->send(fn (): Response => $this->graph->request($account->accessToken)->asForm()
            ->post($this->graph->url($user.'/media_publish'), ['creation_id' => $container])));
        $id = $response->json('id');

        if ((! is_string($id) || $id === '') && ! is_int($id)) {
            throw PublishFailed::unknownOutcome(__('Instagram accepted the request but returned no post id. Check the account before trying again.'), 'missing_post_id');
        }

        return (string) $id;
    }

    /** The post's public link, when Instagram returns one; never fails the publish. */
    private function permalink(AccountCredentials $account, string $mediaId): ?string
    {
        try {
            $response = $this->graph->send(fn (): Response => $this->graph->request($account->accessToken)
                ->get($this->graph->url($mediaId), ['fields' => 'permalink']));
        } catch (PublishFailed) {
            return null;
        }

        $link = $response->successful() ? $response->json('permalink') : null;

        return is_string($link) && str_starts_with($link, 'https://') ? $link : null;
    }

    /**
     * Only container ids and progress counters survive between runs.
     *
     * @param  array<string, mixed>  $meta
     * @return array{quota?: bool, container?: string, children?: list<string>, ready?: int, finished?: bool}
     */
    private function meta(array $meta): array
    {
        $out = [];

        if (($meta['quota'] ?? null) === true) {
            $out['quota'] = true;
        }

        if (is_string($meta['container'] ?? null) && $meta['container'] !== '') {
            $out['container'] = $meta['container'];
        }

        if (is_array($meta['children'] ?? null)) {
            $out['children'] = array_values(array_filter($meta['children'], static fn (mixed $id): bool => is_string($id) && $id !== ''));
        }

        if (is_int($meta['ready'] ?? null)) {
            $out['ready'] = max(0, $meta['ready']);
        }

        if (isset($out['container']) && ($meta['finished'] ?? null) === true) {
            $out['finished'] = true;
        }

        // The publisher's count of transient failures is dropped: progress resets it ("in a row").
        return $out;
    }

    /**
     * Whether another request may start in this run (the first one always may); counts it when it may.
     *
     * @phpstan-impure
     */
    private function may(): bool
    {
        if ($this->requests > 0 && microtime(true) >= $this->deadline) {
            return false;
        }

        $this->tick();

        return true;
    }

    private function tick(): void
    {
        $this->requests++;
    }

    /** @param array<string, mixed> $meta */
    private function pause(string $state, ?string $container, array $meta): PublishProgress
    {
        return new PublishProgress($state, $container, 0, $meta);
    }
}
