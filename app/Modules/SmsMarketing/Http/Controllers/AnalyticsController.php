<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Controllers;

use App\Modules\SmsMarketing\Actions\SmsAnalytics;
use App\Modules\SmsMarketing\Actions\SmsSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AnalyticsController
{
    public function __construct(
        private readonly SmsAnalytics $analytics,
        private readonly SmsSettings $settings,
        private readonly SmsSupport $support,
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

        $setting = $this->settings->current();

        return Inertia::render('Modules/SmsMarketing/Pages/Analytics/Index', [
            'report' => $this->analytics->overview($from->utc(), $to->utc()),
            'standing' => $this->analytics->standing(),
            'campaigns' => $this->analytics->campaigns(10),
            'cost' => [
                'segmentAmount' => $setting->segment_cost_amount,
                'currency' => $setting->segment_cost_amount === null ? null : ($setting->currency ?? $this->settings->currency()),
            ],
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => $timezone],
        ]);
    }
}
