<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use App\Modules\Calendar\Access\CalendarAccess;
use App\Modules\Calendar\Actions\Preferences;
use App\Modules\Calendar\Enums\CalendarView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A member's own calendar settings. */
final class PreferenceController
{
    public function __construct(
        private readonly CalendarAccess $access,
        private readonly Preferences $preferences,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 401);
        $me = $this->access->membership($user);

        $input = $request->validate([
            'timezone' => ['nullable', 'string', 'timezone:all'],
            'week_starts_on' => ['required', 'integer', Rule::in([0, 1, 6])],
            'time_format' => ['required', Rule::in(['12h', '24h'])],
            'default_view' => ['required', Rule::enum(CalendarView::class)],
            'default_duration' => ['required', 'integer', Rule::in([15, 30, 45, 60, 90, 120])],
            'default_reminder' => ['nullable', 'integer', 'min:0', 'max:40320'],
            'show_declined' => ['required', 'boolean'],
        ]);

        $this->preferences->save($me, $input);
        Toast::success(__('Calendar settings saved.'));

        return back();
    }
}
