<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\TikTok;

use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Platforms\Adapters\TikTokPlatform;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\MediaInfo;

/**
 * The TikTok settings a creator chooses per post (TikTok's Content Posting guidelines): who can see it, whether
 * others may comment, duet or stitch, and whether it promotes the creator's own brand or a third party's
 * (branded content). Stored per target as:
 *
 *   privacy_level        one of TikTok's privacy levels, or null until the creator chose one (never defaulted)
 *   allow_comment        bool, off unless turned on
 *   allow_duet           bool, off unless turned on
 *   allow_stitch         bool, off unless turned on
 *   disclose_commercial  bool, the "Disclose commercial content" switch
 *   brand_organic        bool, "Your brand" (TikTok's `brand_organic_toggle`)
 *   brand_content        bool, "Branded content" (TikTok's `brand_content_toggle`)
 *
 * Pure helpers only; the creator info is read by {@see TikTokPlatform}.
 */
final class TikTokPostOptions
{
    /** TikTok's documented privacy levels. */
    public const array PRIVACY_LEVELS = ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'];

    /** The keys a stored option set may have. */
    public const array KEYS = ['privacy_level', 'allow_comment', 'allow_duet', 'allow_stitch', 'disclose_commercial', 'brand_organic', 'brand_content'];

    /**
     * @param  array<string, mixed>  $input
     * @return array{privacy_level: string|null, allow_comment: bool, allow_duet: bool, allow_stitch: bool, disclose_commercial: bool, brand_organic: bool, brand_content: bool}
     */
    public static function normalize(array $input): array
    {
        $privacy = $input['privacy_level'] ?? null;
        $disclose = self::flag($input['disclose_commercial'] ?? false);

        return [
            'privacy_level' => is_string($privacy) && in_array($privacy, self::PRIVACY_LEVELS, true) ? $privacy : null,
            'allow_comment' => self::flag($input['allow_comment'] ?? false),
            'allow_duet' => self::flag($input['allow_duet'] ?? false),
            'allow_stitch' => self::flag($input['allow_stitch'] ?? false),
            'disclose_commercial' => $disclose,
            // A brand choice only counts while the disclosure is switched on.
            'brand_organic' => $disclose && self::flag($input['brand_organic'] ?? false),
            'brand_content' => $disclose && self::flag($input['brand_content'] ?? false),
        ];
    }

    /**
     * What blocks scheduling: no privacy chosen, a disclosure without a brand choice, private branded content.
     *
     * @param  array<string, mixed>|null  $options
     * @param  list<MediaInfo>  $media
     * @return list<array{blocking: bool, message: string}>
     */
    public static function issues(?array $options, PostType $type, array $media): array
    {
        $chosen = self::normalize($options ?? []);
        $items = [];

        if ($chosen['privacy_level'] === null) {
            $items[] = ['blocking' => true, 'message' => __('Choose who can see this TikTok post in its TikTok settings.')];
        }

        if ($chosen['disclose_commercial'] && ! $chosen['brand_organic'] && ! $chosen['brand_content']) {
            $items[] = ['blocking' => true, 'message' => __('You chose to disclose commercial content on TikTok: tick "Your brand", "Branded content" or both, or turn the disclosure off.')];
        }

        if ($chosen['brand_content'] && $chosen['privacy_level'] === 'SELF_ONLY') {
            $items[] = ['blocking' => true, 'message' => __('Branded content cannot be private on TikTok. Choose another privacy level.')];
        }

        return $items;
    }

    /**
     * The creator info turned into what the composer may offer. Unknown privacy levels are left out.
     *
     * @param  array<string, mixed>  $creator
     * @return array{privacy_levels: list<string>, comment_disabled: bool, duet_disabled: bool, stitch_disabled: bool, max_video_seconds: int|null, nickname: string|null}
     */
    public static function fromCreatorInfo(array $creator): array
    {
        $offered = is_array($creator['privacy_level_options'] ?? null) ? $creator['privacy_level_options'] : [];
        $max = $creator['max_video_post_duration_sec'] ?? null;
        $nickname = $creator['creator_nickname'] ?? null;
        $nickname = is_string($nickname) ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $nickname) ?? '') : '';

        return [
            'privacy_levels' => array_values(array_filter(self::PRIVACY_LEVELS, static fn (string $level): bool => in_array($level, $offered, true))),
            'comment_disabled' => ($creator['comment_disabled'] ?? false) === true,
            'duet_disabled' => ($creator['duet_disabled'] ?? false) === true,
            'stitch_disabled' => ($creator['stitch_disabled'] ?? false) === true,
            'max_video_seconds' => is_numeric($max) && (int) $max > 0 ? (int) $max : null,
            'nickname' => $nickname === '' ? null : mb_substr($nickname, 0, 100),
        ];
    }

    /**
     * The `post_info` settings for `video/init/` from the creator's choices, checked against a fresh creator info.
     * A privacy level the account no longer offers is refused (nothing is created on TikTok yet). A switch the
     * creator has disabled in TikTok stays off whatever was chosen.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $creator
     * @return array{privacy_level: string, disable_comment: bool, disable_duet: bool, disable_stitch: bool, brand_content_toggle: bool, brand_organic_toggle: bool}
     *
     * @throws PublishFailed
     */
    public static function postInfo(array $options, array $creator): array
    {
        $chosen = self::normalize($options);
        $offered = is_array($creator['privacy_level_options'] ?? null) ? $creator['privacy_level_options'] : [];

        if ($chosen['privacy_level'] === null) {
            throw PublishFailed::rejected(
                __('No privacy level was chosen for this TikTok post. Edit the post, choose who can see it and schedule it again.'),
                'tiktok_privacy_missing',
            );
        }

        if (! in_array($chosen['privacy_level'], $offered, true)) {
            throw PublishFailed::rejected(
                __('The TikTok account no longer offers the privacy level chosen for this post. Nothing was posted; edit the post, choose it again and schedule it again.'),
                'tiktok_privacy_not_offered',
            );
        }

        if ($chosen['brand_content'] && $chosen['privacy_level'] === 'SELF_ONLY') {
            throw PublishFailed::rejected(__('Branded content cannot be private on TikTok. Choose another privacy level.'), 'tiktok_branded_private');
        }

        return [
            'privacy_level' => $chosen['privacy_level'],
            'disable_comment' => ! $chosen['allow_comment'] || ($creator['comment_disabled'] ?? false) === true,
            'disable_duet' => ! $chosen['allow_duet'] || ($creator['duet_disabled'] ?? false) === true,
            'disable_stitch' => ! $chosen['allow_stitch'] || ($creator['stitch_disabled'] ?? false) === true,
            'brand_content_toggle' => $chosen['brand_content'],
            'brand_organic_toggle' => $chosen['brand_organic'],
        ];
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
