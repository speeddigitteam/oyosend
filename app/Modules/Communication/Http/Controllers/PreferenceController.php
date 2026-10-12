<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Communication\Actions\ChannelConnections;
use App\Modules\Communication\Actions\NotificationPreferences;
use App\Modules\Communication\Enums\Channel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A member's own notification preferences: which categories reach the center and their inbox. */
final class PreferenceController
{
    public function __construct(
        private readonly CommunicationControllerSupport $support,
        private readonly NotificationPreferences $preferences,
        private readonly ChannelConnections $connections,
    ) {}

    public function index(Request $request): Response
    {
        $membership = $this->support->membership($this->support->actor($request));

        return Inertia::render('Modules/Communication/Pages/Notifications/Preferences', [
            'preferences' => $this->preferences->matrix($membership->id),
            // Email notifications are only delivered when the workspace's email channel can send.
            'emailAvailable' => $this->connections->state(Channel::Email)->value === 'configured',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $membership = $this->support->membership($this->support->actor($request));
        $categories = $this->preferences->categories();

        $input = $request->validate([
            'preferences' => ['required', 'array', 'max:50'],
            'preferences.*.category' => ['required', 'string', 'in:'.implode(',', $categories)],
            'preferences.*.in_app' => ['required', 'boolean'],
            'preferences.*.email' => ['required', 'boolean'],
        ]);

        $values = [];

        foreach ($input['preferences'] as $row) {
            $values[$row['category']] = ['in_app' => (bool) $row['in_app'], 'email' => (bool) $row['email']];
        }

        $this->preferences->update($membership->id, $values);
        Toast::success(__('Notification preferences saved.'));

        return back();
    }
}
