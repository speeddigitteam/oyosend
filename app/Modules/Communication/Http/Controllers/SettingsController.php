<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Communication\Actions\ChannelConnections;
use App\Modules\Communication\Actions\CommunicationSettings;
use App\Modules\Communication\Channels\CredentialResolver;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Http\Requests\ChannelRequest;
use App\Modules\Communication\Http\Requests\SettingsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class SettingsController
{
    public function __construct(
        private readonly CommunicationSettings $settings,
        private readonly ChannelConnections $connections,
        private readonly CredentialResolver $credentials,
    ) {}

    public function index(): Response
    {
        $settings = $this->settings->current();

        return Inertia::render('Modules/Communication/Pages/Settings/Index', [
            'settings' => [
                'fromName' => $settings->from_name,
                'replyToEmail' => $settings->reply_to_email,
                'quietHoursEnabled' => $settings->quiet_hours_enabled,
                'quietHoursStart' => $settings->quiet_hours_start,
                'quietHoursEnd' => $settings->quiet_hours_end,
                'footerText' => $settings->footer_text,
            ],
            'channels' => array_map(fn (Channel $channel): array => $this->connections->describe($channel), Channel::cases()),
            // Names only. A value is never sent to the browser.
            'credentialRefs' => $this->credentials->available(),
            // Guidance, not a verification: custom sender domains are set up by the operator.
            'senderGuidance' => [
                'platformSender' => (string) config('mail.from.address'),
                'customDomainVerified' => false,
            ],
        ]);
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        $this->settings->update($request->validated());
        Toast::success(__('Communication settings saved.'));

        return back();
    }

    public function updateChannel(ChannelRequest $request, Channel $channel): RedirectResponse
    {
        $this->connections->save($channel, $request->validated());
        Toast::success(__('Channel saved.'));

        return back();
    }
}
