<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Ai\Actions\AiAvailability;
use App\Modules\Ai\Actions\AiSettings;
use App\Modules\Ai\Actions\ToolRegistry;
use App\Modules\Ai\Http\Requests\SettingsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Workspace AI settings: switches, readable data scopes, retention and redaction. */
final class SettingsController
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly ToolRegistry $tools,
        private readonly AiAvailability $availability,
    ) {}

    public function index(): Response
    {
        $setting = $this->settings->current();

        return Inertia::render('Modules/Ai/Pages/Settings/Index', [
            'settings' => [
                'enabled' => $setting->enabled,
                'chatEnabled' => $setting->chat_enabled,
                'draftingEnabled' => $setting->drafting_enabled,
                'automationEnabled' => $setting->automation_enabled,
                'voiceEnabled' => $setting->voice_enabled,
                'dataScopes' => $setting->data_scopes,
                'retentionDays' => $setting->retention_days,
                'redactPii' => $setting->redact_pii,
            ],
            'options' => [
                'dataScopes' => $this->tools->scopes(),
                'retention' => [
                    'min' => (int) config('ai.retention.min_days', 1),
                    'max' => (int) config('ai.retention.max_days', 365),
                ],
                'platformRedaction' => (bool) config('ai.redaction.enabled', false),
            ],
            'availability' => $this->availability->describe(),
        ]);
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        $this->settings->update($request->validated());
        Toast::success(__('AI settings saved.'));

        return back();
    }
}
