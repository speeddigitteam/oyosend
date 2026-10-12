<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\EmailMarketing\Actions\EmailMarketingSettings;
use App\Modules\EmailMarketing\Support\ContentBlocks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SettingsController
{
    public function __construct(
        private readonly EmailMarketingSettings $settings,
        private readonly EmailMarketingSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $this->support->actor($request);
        $current = $this->settings->current();

        return Inertia::render('Modules/EmailMarketing/Pages/Settings/Index', [
            'settings' => [
                'trackClicks' => $current->track_clicks,
                'sendPerMinute' => $current->send_per_minute,
                'footerAddress' => $current->footer_address,
            ],
            'ceiling' => (int) config('email_marketing.send_per_minute_ceiling', 600),
            'timezone' => $this->settings->timezone(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->support->actor($request);
        $data = $request->validate([
            'track_clicks' => ['required', 'boolean'],
            'send_per_minute' => ['required', 'integer', 'between:1,'.(int) config('email_marketing.send_per_minute_ceiling', 600)],
            'footer_address' => ['nullable', 'string', 'max:500'],
        ]);

        $address = ContentBlocks::multiline($data['footer_address'] ?? '');

        $this->settings->update([
            'track_clicks' => $data['track_clicks'],
            'send_per_minute' => $data['send_per_minute'],
            'footer_address' => $address === '' ? null : $address,
        ]);
        Toast::success(__('Settings saved.'));

        return back();
    }
}
