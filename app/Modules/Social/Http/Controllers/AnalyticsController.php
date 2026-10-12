<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Modules\Social\Actions\SocialAnalytics;
use App\Modules\Social\Actions\SocialSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AnalyticsController
{
    public function __construct(
        private readonly SocialAnalytics $analytics,
        private readonly SocialSettings $settings,
        private readonly SocialSupport $support,
    ) {}

    public function index(Request $request): Response
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

        return Inertia::render('Modules/Social/Pages/Analytics/Index', [
            'report' => $this->analytics->overview($from->utc(), $to->utc()),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => $timezone],
        ]);
    }
}
