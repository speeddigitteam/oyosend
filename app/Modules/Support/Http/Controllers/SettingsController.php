<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Support\Actions\SupportSettings;
use App\Modules\Support\Http\Requests\SettingsRequest;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\SlaPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class SettingsController
{
    public function __construct(private readonly SupportSettings $settings) {}

    public function index(): Response
    {
        $settings = $this->settings->current();

        return Inertia::render('Modules/Support/Pages/Settings/Index', [
            'settings' => [
                'ticketPrefix' => $settings->ticket_prefix,
                'numberPadding' => $settings->number_padding,
                'defaultPriority' => $settings->default_priority->value,
                'defaultQueueId' => $settings->default_queue_id,
                'defaultSlaPolicyId' => $settings->default_sla_policy_id,
                'csatEnabled' => $settings->csat_enabled,
                'csatExpiryDays' => $settings->csat_expiry_days,
                'csatSendEmail' => $settings->csat_send_email,
                'autoCloseDays' => $settings->auto_close_days,
            ],
            'queues' => Queue::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Queue $queue): array => ['id' => $queue->id, 'name' => $queue->name])->all(),
            'policies' => SlaPolicy::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (SlaPolicy $policy): array => ['id' => $policy->id, 'name' => $policy->name])->all(),
            'priorities' => ['low', 'normal', 'high', 'urgent'],
        ]);
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        $input = $request->validated();

        foreach ([['default_queue_id', Queue::class], ['default_sla_policy_id', SlaPolicy::class]] as [$field, $model]) {
            if (($input[$field] ?? null) !== null && ! $model::query()->whereKey($input[$field])->exists()) {
                throw ValidationException::withMessages([$field => __('The selected value is not valid.')]);
            }
        }

        $this->settings->update([...$input, 'ticket_prefix' => $input['ticket_prefix'] ?? '']);
        Toast::success(__('Support settings saved.'));

        return back();
    }
}
