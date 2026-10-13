<?php

declare(strict_types=1);

namespace App\Modules\Social\Ai;

use App\Modules\Social\Actions\SocialSettings;
use App\Modules\Social\Http\Controllers\SocialSupport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Page-level sentiment mix of analysed audience comments for a period (read only). */
final class SentimentSummaryController
{
    public function __construct(
        private readonly CommentSentiment $sentiment,
        private readonly SocialSettings $settings,
        private readonly SocialSupport $support,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->support->actor($request);
        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $timezone = $this->settings->timezone();
        $to = isset($input['to']) ? CarbonImmutable::parse($input['to'], $timezone)->endOfDay() : CarbonImmutable::now($timezone)->endOfDay();
        $from = isset($input['from']) ? CarbonImmutable::parse($input['from'], $timezone)->startOfDay() : $to->subDays(29)->startOfDay();

        if ($from->diffInDays($to) > 366) {
            $from = $to->subDays(366)->startOfDay();
        }

        return response()->json($this->sentiment->summary($from->utc(), $to->utc()));
    }
}
