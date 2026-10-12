<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\SmsMarketing\Actions\SmsAnalytics;
use App\Modules\SmsMarketing\Actions\SmsSettings;
use App\Modules\SmsMarketing\Http\Requests\SettingsRequest;
use App\Modules\SmsMarketing\Models\SenderId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SettingsController
{
    public function __construct(
        private readonly SmsSettings $settings,
        private readonly SmsSupport $support,
        private readonly SmsPresenter $presenter,
        private readonly SmsAnalytics $analytics,
    ) {}

    public function index(Request $request): Response
    {
        $this->support->actor($request);

        return Inertia::render('Modules/SmsMarketing/Pages/Settings/Index', [
            'settings' => $this->presenter->settings($this->settings->current()),
            'senders' => SenderId::query()->orderBy('label')->get(['id', 'label', 'value'])
                ->map(static fn (SenderId $sender): array => ['id' => $sender->id, 'label' => $sender->label, 'value' => $sender->value])->all(),
            'channel' => $this->analytics->standing()['channel'],
            'timezone' => $this->settings->timezone(),
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
