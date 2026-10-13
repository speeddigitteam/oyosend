<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use App\Modules\Social\Enums\PostType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create or edit a post. Account and media ids are checked against the workspace by the manager. */
final class PostRequest extends FormRequest
{
    /** Access is decided by the route's `can:` middleware and, for one record, by RecordAccess. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:5000', 'required_without:media_ids'],
            'link_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'post_type' => ['nullable', Rule::enum(PostType::class)],
            'scheduled_for' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'campaign_id' => ['nullable', 'string', 'size:26'],
            'category_id' => ['nullable', 'string', 'size:26'],
            'hashtag_set_id' => ['nullable', 'string', 'size:26'],
            // Tags accepted from AI suggestions on top of the chosen set; merged into the post's hashtag snapshot.
            'extra_hashtags' => ['nullable', 'array', 'max:30'],
            'extra_hashtags.*' => ['string', 'max:101', 'regex:/^#?[\p{L}\p{M}\p{N}_]{1,100}$/u'],
            // Informational marker: AI suggestions were used on this draft. Honoured only for `social.posts.ai_assist`.
            'ai_assisted' => ['nullable', 'boolean'],
            'targets' => ['nullable', 'array', 'max:20'],
            'targets.*.account_id' => ['required', 'string', 'size:26', 'distinct'],
            'targets.*.body_override' => ['nullable', 'string', 'max:5000'],
            'media_ids' => ['nullable', 'array', 'max:10'],
            'media_ids.*' => ['string', 'size:26', 'distinct'],
        ];
    }
}
