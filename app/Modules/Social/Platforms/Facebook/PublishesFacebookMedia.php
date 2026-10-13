<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\MediaInfo;
use App\Modules\Social\Platforms\PostTypeAware;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\PublishProgress;
use App\Modules\Social\Platforms\PublishSession;
use App\Modules\Social\Platforms\StepwisePublisher;
use Illuminate\Http\Client\Response;

/**
 * The media half of the Facebook Pages adapter ({@see PostTypeAware} and
 * {@see StepwisePublisher}), kept here so the adapter stays readable.
 * Uses the adapter's own `client()`, `send()` and `version()`.
 */
trait PublishesFacebookMedia
{
    /** Whether the operator enabled video and Reels (it needs the `publish_video` permission from app review). */
    protected function videoEnabled(): bool
    {
        return (bool) config('social.platforms.facebook.video_publishing', true);
    }

    /** @return list<string> */
    protected function scopes(): array
    {
        $scopes = ['pages_show_list', 'pages_manage_posts', 'pages_read_engagement'];

        if ($this->videoEnabled()) {
            $scopes[] = 'publish_video';
        }

        // Lead Ads: only once the operator's Meta app has these approved (config/social.php `lead_ads`).
        if (config('social.lead_ads.enabled') === true) {
            array_push($scopes, 'leads_retrieval', 'pages_manage_ads', 'pages_manage_metadata');
        }

        // Audience comments: reply, hide and delete need pages_manage_engagement; the webhook subscription needs
        // pages_manage_metadata (config/social_comments.php).
        array_push($scopes, ...FacebookComments::requestedScopes());

        return array_values(array_unique($scopes));
    }

    /** @return list<PostType> */
    public function postTypes(): array
    {
        return $this->videoEnabled()
            ? [PostType::Post, PostType::Photos, PostType::Video, PostType::Reel]
            : [PostType::Post, PostType::Photos];
    }

    public function checkPost(PostType $type, array $media): array
    {
        if (! in_array($type, $this->postTypes(), true)) {
            return [['blocking' => true, 'message' => $type === PostType::Reel
                ? __('Facebook Reels are not enabled for this installation.')
                : __('Facebook video is not enabled for this installation.')]];
        }

        return (new FacebookMediaRules)->check($type, $media);
    }

    public function mediaBadges(MediaInfo $media): array
    {
        if ($media->isVideo && ! $this->videoEnabled()) {
            return [['code' => 'unsupported', 'tone' => 'muted']];
        }

        return (new FacebookMediaRules)->badges($media);
    }

    public function usesSteps(PublishPayload $payload): bool
    {
        return match ($payload->postType) {
            PostType::Video, PostType::Reel => true,
            PostType::Photos => count($payload->media) > 1,
            PostType::Post => false,
        };
    }

    public function advance(AccountCredentials $account, PublishPayload $payload, PublishSession $session, float $deadline): PublishProgress
    {
        $graph = new FacebookGraph(
            fn () => $this->client(),
            fn (\Closure $request): Response => $this->send($request),
            $this->version(),
        );

        return match ($payload->postType) {
            PostType::Photos => (new FacebookPhotoPublisher($graph))->advance($account, $payload, $session, $deadline),
            PostType::Video => $this->videoEnabled()
                ? (new FacebookVideoPublisher($graph))->advance($account, $payload, $session, $deadline)
                : throw PublishFailed::rejected(__('Facebook video is not enabled for this installation.'), 'unsupported_media'),
            PostType::Reel => $this->videoEnabled()
                ? (new FacebookReelPublisher($graph))->advance($account, $payload, $session, $deadline)
                : throw PublishFailed::rejected(__('Facebook Reels are not enabled for this installation.'), 'unsupported_media'),
            PostType::Post => throw PublishFailed::rejected(__('This post does not need a stepwise upload.'), 'unsupported_media'),
        };
    }
}
