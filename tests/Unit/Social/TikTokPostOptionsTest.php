<?php

declare(strict_types=1);

use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\TikTok\TikTokPostOptions;

it('stores a typed choice and never defaults the privacy level', function () {
    expect(TikTokPostOptions::normalize([]))->toBe([
        'privacy_level' => null, 'allow_comment' => false, 'allow_duet' => false, 'allow_stitch' => false,
        'disclose_commercial' => false, 'brand_organic' => false, 'brand_content' => false,
    ])
        ->and(TikTokPostOptions::normalize(['privacy_level' => 'EVERYONE', 'allow_comment' => '1'])['privacy_level'])->toBeNull()
        ->and(TikTokPostOptions::normalize(['allow_comment' => '1'])['allow_comment'])->toBeTrue()
        ->and(TikTokPostOptions::normalize(['disclose_commercial' => false, 'brand_organic' => true])['brand_organic'])->toBeFalse();
});

it('reports what blocks scheduling', function () {
    $messages = fn (?array $options): array => array_column(TikTokPostOptions::issues($options, PostType::Video, []), 'message');

    expect($messages(null))->toHaveCount(1)
        ->and($messages(['privacy_level' => 'SELF_ONLY']))->toBe([])
        ->and($messages(['privacy_level' => 'SELF_ONLY', 'disclose_commercial' => true]))->toHaveCount(1)
        ->and($messages(['privacy_level' => 'SELF_ONLY', 'disclose_commercial' => true, 'brand_content' => true]))
        ->toBe(['Branded content cannot be private on TikTok. Choose another privacy level.']);
});

it('keeps only known privacy levels from the creator info and never returns more than it needs', function () {
    expect(TikTokPostOptions::fromCreatorInfo([
        'privacy_level_options' => ['SELF_ONLY', 'SOMETHING_NEW', 'PUBLIC_TO_EVERYONE'],
        'comment_disabled' => true, 'max_video_post_duration_sec' => '600', 'creator_nickname' => "Brand\x07 Creator",
        'creator_avatar_url' => 'https://example.com/a.jpg',
    ]))->toBe([
        'privacy_levels' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'],
        'comment_disabled' => true, 'duet_disabled' => false, 'stitch_disabled' => false,
        'max_video_seconds' => 600, 'nickname' => 'Brand Creator',
    ]);
});

it('builds the post settings and refuses a level that is not offered any more', function () {
    $creator = ['privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'], 'stitch_disabled' => true];

    expect(TikTokPostOptions::postInfo(['privacy_level' => 'SELF_ONLY', 'allow_comment' => true, 'allow_stitch' => true], $creator))->toBe([
        'privacy_level' => 'SELF_ONLY', 'disable_comment' => false, 'disable_duet' => true, 'disable_stitch' => true,
        'brand_content_toggle' => false, 'brand_organic_toggle' => false,
    ]);

    foreach ([
        [['privacy_level' => 'FOLLOWER_OF_CREATOR'], 'tiktok_privacy_not_offered'],
        [[], 'tiktok_privacy_missing'],
        [['privacy_level' => 'SELF_ONLY', 'disclose_commercial' => true, 'brand_content' => true], 'tiktok_branded_private'],
    ] as [$options, $code]) {
        try {
            TikTokPostOptions::postInfo($options, $creator);
            $this->fail('Expected a refusal for '.$code);
        } catch (PublishFailed $failure) {
            expect($failure->errorCode)->toBe($code)->and($failure->retryable)->toBeFalse()->and($failure->outcomeUnknown)->toBeFalse();
        }
    }
});
