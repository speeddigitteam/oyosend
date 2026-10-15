<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Support\Actions\LiveChatSettings;
use App\Modules\Support\Enums\VisitorDetails;
use App\Modules\Support\Http\Requests\LiveChatSettingsRequest;
use App\Modules\Support\Support\LiveChatLimits;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Website chat widget settings and the embed snippet (`support.settings.manage`). */
final class LiveChatSettingsController
{
    public function __construct(private readonly LiveChatSettings $settings) {}

    public function show(): Response
    {
        $settings = $this->settings->forDisplay();

        return Inertia::render('Modules/Support/Pages/LiveChat/Settings', [
            'settings' => [
                'enabled' => $settings->enabled,
                'allowedOrigins' => $settings->origins(),
                'widgetTitle' => $settings->widget_title,
                'greeting' => $settings->greeting,
                'visitorDetails' => $settings->visitor_details->value,
                'offlineNote' => $settings->offline_note,
            ],
            'snippet' => '<script src="'.route('support.live_chat.widget').'" defer></script>',
            'visitorDetailOptions' => array_map(static fn (VisitorDetails $details): string => $details->value, VisitorDetails::cases()),
            'maxOrigins' => LiveChatLimits::MAX_ORIGINS,
            'insecureLocal' => LiveChatSettingsRequest::local(),
        ]);
    }

    public function update(LiveChatSettingsRequest $request): RedirectResponse
    {
        $this->settings->update($request->settings());
        Toast::success(__('Website chat settings saved.'));

        return back();
    }
}
