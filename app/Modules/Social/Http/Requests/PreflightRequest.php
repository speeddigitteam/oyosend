<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Platforms\TikTok\TikTokPostOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The composer's draft state, checked without saving. Ids are resolved inside the workspace only. */
final class PreflightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'post_type' => ['required', Rule::enum(PostType::class)],
            'body' => ['nullable', 'string', 'max:5000'],
            'link_url' => ['nullable', 'string', 'max:2048'],
            'hashtag_set_id' => ['nullable', 'string', 'size:26'],
            'targets' => ['nullable', 'array', 'max:20'],
            'targets.*.account_id' => ['required', 'string', 'size:26', 'distinct'],
            'targets.*.body_override' => ['nullable', 'string', 'max:5000'],
            // Per-account publishing options (TikTok); stored only for a platform that has them. The privacy level is
            // checked against the account's live choices again when publishing.
            'targets.*.options' => ['nullable', 'array:'.implode(',', TikTokPostOptions::KEYS)],
            'targets.*.options.privacy_level' => ['nullable', 'string', Rule::in(TikTokPostOptions::PRIVACY_LEVELS)],
            'targets.*.options.allow_comment' => ['sometimes', 'boolean'],
            'targets.*.options.allow_duet' => ['sometimes', 'boolean'],
            'targets.*.options.allow_stitch' => ['sometimes', 'boolean'],
            'targets.*.options.disclose_commercial' => ['sometimes', 'boolean'],
            'targets.*.options.brand_organic' => ['sometimes', 'boolean'],
            'targets.*.options.brand_content' => ['sometimes', 'boolean'],
            'media_ids' => ['nullable', 'array', 'max:10'],
            'media_ids.*' => ['string', 'size:26', 'distinct'],
        ];
    }
}
