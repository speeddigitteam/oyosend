<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\Gateways\GatewayRegistry;
use App\Core\Settings\Enums\RegistrationMode;
use App\Core\Settings\PlatformSettings;
use App\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-wide settings an operator changes at runtime: who may sign up, and
 * the timings and wording of billing. Everything else is configuration.
 */
final class SettingsController
{
    public function edit(PlatformSettings $settings, GatewayRegistry $gateways): Response
    {
        return Inertia::render('Platform/Pages/Settings', [
            'settings' => [
                'registrationMode' => $settings->registrationMode()->value,
                'graceDays' => $settings->graceDays(),
                'invoiceLeadDays' => $settings->invoiceLeadDays(),
                'offlineInstructions' => $settings->offlineInstructions(),
            ],
            // Read-only: credentials live in the environment, never in the database or on a page.
            'gateways' => array_values(array_map(
                static fn ($gateway): array => [
                    'key' => $gateway->key(),
                    'name' => $gateway->name(),
                    'configured' => $gateway->isConfigured(),
                ],
                $gateways->all(),
            )),
        ]);
    }

    public function update(Request $request, PlatformSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $input = $request->validate([
            'registration_mode' => ['required', Rule::enum(RegistrationMode::class)],
            'grace_days' => ['required', 'integer', 'min:0', 'max:90'],
            'invoice_lead_days' => ['required', 'integer', 'min:0', 'max:60'],
            'offline_instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        $values = [
            'registration.mode' => $input['registration_mode'],
            'billing.grace_days' => (int) $input['grace_days'],
            'billing.invoice_lead_days' => (int) $input['invoice_lead_days'],
            'billing.offline_instructions' => $input['offline_instructions'] ?? null,
        ];

        $old = array_combine(array_keys($values), array_map($settings->get(...), array_keys($values)));
        $changed = array_filter($values, static fn (mixed $value, string $key): bool => $value !== $old[$key], ARRAY_FILTER_USE_BOTH);

        if ($changed !== []) {
            DB::transaction(function () use ($settings, $audit, $values, $old, $changed): void {
                $settings->put($values);

                $audit->record('platform.settings.updated', null, [
                    'old' => array_intersect_key($old, $changed),
                    'attributes' => $changed,
                ]);
            });
        }

        Toast::success(__('Settings saved.'));

        return back();
    }
}
