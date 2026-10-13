<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostMedia;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Platforms\PostTypeAware;
use App\Modules\Social\Platforms\SocialPlatforms;
use Illuminate\Validation\ValidationException;

/**
 * What a post must satisfy before it can be reviewed or queued, per account: the platform must
 * be usable, the account connected, the text within the platform's limit and the media of a type
 * the adapter really publishes. Also builds the per-account previews shown in the editor.
 */
final class PostContentRules
{
    public function __construct(private readonly SocialPlatforms $platforms) {}

    /**
     * @return list<array{
     *     targetId: string, accountId: string, platform: string, platformLabel: string, accountName: string,
     *     accountStatus: string, text: string, length: int, maxLength: int, mediaCount: int,
     *     capabilities: array<string, bool|int>, issues: list<string>, warnings: list<string>, postType: string
     * }>
     */
    public function previews(Post $post): array
    {
        $post->loadMissing(['targets.account', 'mediaLinks.media']);
        $media = $post->mediaLinks->map(fn (PostMedia $link) => $link->media)->filter()->values();
        $videos = $media->filter(fn ($item): bool => $item->kind->value === 'video')->count();
        $images = $media->count() - $videos;
        $hasText = trim($post->composedBody()) !== '' || $post->targets->contains(fn (PostTarget $t): bool => trim((string) $t->body_override) !== '');
        $type = $post->post_type ?? PostType::Post;
        $infos = $media->map(fn ($item) => $item->info())->values()->all();
        $previews = [];

        foreach ($post->targets as $target) {
            $account = $target->account;
            $platform = $this->platforms->find($account->platform);
            $issues = [];
            $warnings = [];
            $text = $post->composedBody($target->body_override);
            $capabilities = $platform?->capabilities();
            $length = $platform?->textLength($text) ?? mb_strlen($text);

            if ($platform === null || ! $platform->availability()->available) {
                $issues[] = $platform?->availability()->reason ?? __('This platform is not available.');
            } elseif (! $capabilities?->publish) {
                $issues[] = __('Publishing is not available for this platform.');
            }

            if ($account->status !== AccountStatus::Connected) {
                $issues[] = $account->status === AccountStatus::NeedsReauthorization
                    ? __('This account must be authorised again.')
                    : __('This account is disconnected.');
            }

            if ($capabilities !== null && $capabilities->publish) {
                if ($capabilities->maxText > 0 && $length > $capabilities->maxText) {
                    $issues[] = __('The text is :count characters over this platform\'s limit of :max.', [
                        'count' => $length - $capabilities->maxText, 'max' => $capabilities->maxText,
                    ]);
                }

                if ($platform instanceof PostTypeAware) {
                    // The adapter judges the media for the chosen post type (photos / video / Reel).
                    foreach ($platform->checkPost($type, $infos) as $item) {
                        if ($item['blocking']) {
                            $issues[] = $item['message'];
                        } else {
                            $warnings[] = $item['message'];
                        }
                    }
                } elseif ($type === PostType::Reel) {
                    $issues[] = __('This platform does not publish Reels.');
                } elseif ($type === PostType::Video && ! $capabilities->video) {
                    $issues[] = __('This platform cannot publish video yet.');
                } elseif ($media->count() > $capabilities->maxMedia) {
                    $issues[] = $capabilities->maxMedia === 0
                        ? __('This platform cannot publish media yet. Remove the media or deselect the account.')
                        : __('This platform accepts at most :max media items.', ['max' => $capabilities->maxMedia]);
                } elseif ($videos > 0 && ! $capabilities->video) {
                    $issues[] = __('This platform cannot publish video yet.');
                } elseif ($images > 0 && ! $capabilities->images) {
                    $issues[] = __('This platform cannot publish images yet.');
                }
            }

            if (trim($text) === '' && $media->isEmpty() && ! $hasText) {
                $issues[] = __('Add some text or media.');
            } elseif (trim($text) === '' && $media->isEmpty()) {
                $issues[] = __('This account has no text to publish.');
            }

            $previews[] = [
                'targetId' => $target->id,
                'accountId' => $account->id,
                'platform' => $account->platform,
                'platformLabel' => $platform?->label() ?? $account->platform,
                'accountName' => $account->name,
                'accountStatus' => $account->status->value,
                'text' => $text,
                'length' => $length,
                'maxLength' => $capabilities->maxText ?? 0,
                'mediaCount' => $media->count(),
                'capabilities' => $capabilities?->toArray() ?? [],
                'issues' => $issues,
                'warnings' => $warnings,
                'postType' => $type->value,
            ];
        }

        return $previews;
    }

    /**
     * Refuse a post that cannot be published as it stands.
     *
     * @throws ValidationException
     */
    public function assertPublishable(Post $post): void
    {
        $post->loadMissing('targets');

        if ($post->targets->isEmpty()) {
            throw ValidationException::withMessages(['targets' => __('Choose at least one account to publish to.')]);
        }

        $issues = [];

        foreach ($this->previews($post) as $preview) {
            foreach ($preview['issues'] as $issue) {
                $issues[] = $preview['accountName'].': '.$issue;
            }
        }

        if ($issues !== []) {
            throw ValidationException::withMessages(['targets' => array_values(array_unique($issues))]);
        }

        if ($post->link_url !== null && ! preg_match('~^https?://~i', $post->link_url)) {
            throw ValidationException::withMessages(['link_url' => __('The link must start with http:// or https://.')]);
        }
    }
}
