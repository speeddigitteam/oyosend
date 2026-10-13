<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Http\Requests;

use App\Modules\Social\Comments\Support\KeywordMatcher;
use Illuminate\Foundation\Http\FormRequest;

/** The shape of a comment rule. Meaning (permissions, names, scope existence) is checked by `RuleManager`. */
final class CommentRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'match_mode' => ['required', 'in:any,all'],
            'match_type' => ['required', 'in:word,contains'],
            'keywords' => ['required', 'array', 'min:1', 'max:'.KeywordMatcher::MAX_KEYWORDS],
            'keywords.*' => ['string', 'max:'.KeywordMatcher::MAX_KEYWORD_LENGTH],
            'account_id' => ['nullable', 'string', 'size:26'],
            'post_id' => ['nullable', 'string', 'size:26'],
            'campaign_id' => ['nullable', 'string', 'size:26'],
            'actions' => ['required', 'array', 'min:1', 'max:6'],
            'actions.*' => ['array'],
            'actions.*.type' => ['required', 'string', 'max:24'],
            'actions.*.message' => ['nullable', 'string', 'max:2000'],
            'actions.*.membership_id' => ['nullable', 'string', 'size:26'],
            'actions.*.tag' => ['nullable', 'string', 'max:200'],
            'reply_templates' => ['nullable', 'array', 'max:10'],
            'reply_templates.*' => ['nullable', 'string', 'max:2000'],
            'hourly_cap' => ['required', 'integer', 'min:1', 'max:200'],
            'cooldown_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'quiet_start' => ['nullable', 'date_format:H:i'],
            'quiet_end' => ['nullable', 'date_format:H:i'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
