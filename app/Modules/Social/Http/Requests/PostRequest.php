<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'scheduled_for' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'campaign_id' => ['nullable', 'string', 'size:26'],
            'category_id' => ['nullable', 'string', 'size:26'],
            'hashtag_set_id' => ['nullable', 'string', 'size:26'],
            'targets' => ['nullable', 'array', 'max:20'],
            'targets.*.account_id' => ['required', 'string', 'size:26', 'distinct'],
            'targets.*.body_override' => ['nullable', 'string', 'max:5000'],
            'media_ids' => ['nullable', 'array', 'max:10'],
            'media_ids.*' => ['string', 'size:26', 'distinct'],
        ];
    }
}
