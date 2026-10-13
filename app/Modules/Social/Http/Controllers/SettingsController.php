<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Social\Actions\SocialSettings;
use App\Modules\Social\Ai\CommentSentiment;
use App\Modules\Social\Http\Requests\SettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SettingsController
{
    public function __construct(
        private readonly SocialSettings $settings,
        private readonly SocialSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $this->support->actor($request);
        $settings = $this->settings->current();

        return Inertia::render('Modules/Social/Pages/Settings/Index', [
            'settings' => [
                'requireApproval' => $settings->require_approval,
                'accountHourlyLimit' => $settings->account_hourly_limit,
                'aiDailyCap' => $settings->ai_daily_cap,
            ],
            'timezone' => $this->settings->timezone(),
            // Comments analysed by AI today (our own count). Token figures are not exposed by a public contract.
            'aiUsedToday' => app(CommentSentiment::class)->usedToday(),
        ]);
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        $this->support->actor($request);
        $this->settings->update($request->validated());
        Toast::success(__('Settings saved.'));

        return back();
    }
}
