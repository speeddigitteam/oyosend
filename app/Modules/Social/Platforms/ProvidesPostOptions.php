<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;

/**
 * Optional: a platform whose posts carry settings the creator must choose per account in the composer, from
 * choices the platform reports for that account (TikTok: privacy level, comment / duet / stitch, commercial
 * content disclosure; ADR 0033, amendment 2026-10-14).
 *
 * The chosen settings are stored on the post target (`social_post_targets.options`, in the form
 * {@see normalizePostOptions()} returns) and handed to the adapter as `PublishPayload::$targetOptions`.
 * Null there means nothing was chosen: a target created before the column existed.
 */
interface ProvidesPostOptions
{
    /**
     * What the platform lets this account choose right now. One read-only API call; the result never carries a
     * token or anything secret, and is shown to the member composing the post.
     *
     * @return array{
     *     privacy_levels: list<string>, comment_disabled: bool, duet_disabled: bool, stitch_disabled: bool,
     *     max_video_seconds: int|null, nickname: string|null
     * }
     *
     * @throws PublishFailed when the platform does not answer or refuses (messages are already translated)
     */
    public function postOptions(AccountCredentials $account): array;

    /**
     * The stored form of options sent by the composer: unknown keys dropped, values typed. Pure.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizePostOptions(array $input): array;

    /**
     * What is missing or contradictory in the chosen options, as composer and scheduling issues. Pure: no HTTP
     * call, no token; the choices are checked against the live account again when publishing.
     *
     * @param  array<string, mixed>|null  $options  null when nothing was chosen
     * @param  list<MediaInfo>  $media
     * @return list<array{blocking: bool, message: string}>
     */
    public function postOptionIssues(?array $options, PostType $type, array $media): array;
}
