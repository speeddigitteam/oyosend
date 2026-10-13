<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\Gateways\GatewayRegistry;
use App\Core\Billing\Gateways\PaymentGatewayAccounts;
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
 * Platform-wide settings an operator changes at runtime: who may sign up, the timings, wording
 * and issuer of billing, and the payment gateway credentials. Gateway secrets are write-only:
 * they are stored encrypted and never returned to the page (docs/adr/0028).
 */
final class SettingsController
{
    public function edit(PlatformSettings $settings, GatewayRegistry $gateways, PaymentGatewayAccounts $accounts): Response
    {
        $issuer = $settings->invoiceIssuer();

        return Inertia::render('Platform/Pages/Settings', [
            'settings' => [
                'registrationMode' => $settings->registrationMode()->value,
                'graceDays' => $settings->graceDays(),
                'invoiceLeadDays' => $settings->invoiceLeadDays(),
                'offlineInstructions' => $settings->offlineInstructions(),
                'invoicePrefix' => $settings->invoicePrefix(),
                'issuerName' => $issuer['name'],
                'issuerAddress' => $issuer['address'],
                'issuerEmail' => $issuer['email'],
                'issuerTaxId' => $issuer['tax_id'],
            ],
            // Secrets are never sent. The page learns only whether a gateway is configured, where
            // its credentials come from (operator panel or the environment), and its sandbox flag.
            'gateways' => array_values(array_map(
                static function ($gateway) use ($accounts): array {
                    $fields = PaymentGatewayAccounts::FIELDS[$gateway->key()] ?? [];
                    $status = $accounts->status($gateway->key());

                    return [
                        'key' => $gateway->key(),
                        'name' => $gateway->name(),
                        'fields' => $fields,
                        'configured' => $gateway->isConfigured(),
                        'managed' => $status['managed'],
                        'source' => $status['managed'] ? 'panel' : ($gateway->isConfigured() ? 'environment' : 'none'),
                        'storedFields' => $status['managed'] ? array_values(array_diff($fields, $status['missingFields'])) : [],
                        'sandbox' => $status['sandbox'],
                        'enabled' => $status['managed'] ? $status['enabled'] : true,
                        'updatedAt' => $status['updatedAt'],
                    ];
                },
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
            'invoice_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'issuer_name' => ['nullable', 'string', 'max:150'],
            'issuer_address' => ['nullable', 'string', 'max:500'],
            'issuer_email' => ['nullable', 'email', 'max:150'],
            'issuer_tax_id' => ['nullable', 'string', 'max:60'],
        ]);

        $values = [
            'registration.mode' => $input['registration_mode'],
            'billing.grace_days' => (int) $input['grace_days'],
            'billing.invoice_lead_days' => (int) $input['invoice_lead_days'],
            'billing.offline_instructions' => $input['offline_instructions'] ?? null,
            'billing.invoice_prefix' => $input['invoice_prefix'],
            'billing.issuer_name' => $input['issuer_name'] ?? null,
            'billing.issuer_address' => $input['issuer_address'] ?? null,
            'billing.issuer_email' => $input['issuer_email'] ?? null,
            'billing.issuer_tax_id' => $input['issuer_tax_id'] ?? null,
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

    /**
     * Save a payment gateway's credentials. The secret values are stored encrypted and are never
     * read back; blank fields keep what is already stored.
     */
    public function updateGateway(
        Request $request,
        string $gateway,
        GatewayRegistry $gateways,
        PaymentGatewayAccounts $accounts,
        AuditLogger $audit,
    ): RedirectResponse {
        abort_unless($gateways->find($gateway) !== null, 404);

        $fields = PaymentGatewayAccounts::FIELDS[$gateway] ?? abort(404);

        $rules = [
            'sandbox' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
            'secrets' => ['array'],
        ];
        foreach ($fields as $field) {
            $rules["secrets.{$field}"] = ['nullable', 'string', 'max:255'];
        }

        $input = $request->validate($rules);

        $secrets = [];
        foreach ($fields as $field) {
            $secrets[$field] = (string) ($input['secrets'][$field] ?? '');
        }

        $accounts->save($gateway, $secrets, (bool) $input['sandbox'], (bool) $input['enabled']);

        // Never log the secret values: only which gateway and non-secret flags.
        $audit->record('platform.gateway.updated', null, [
            'gateway' => $gateway,
            'sandbox' => (bool) $input['sandbox'],
            'enabled' => (bool) $input['enabled'],
        ]);

        Toast::success(__('Gateway credentials saved.'));

        return back();
    }

    /**
     * Forget a gateway's panel credentials; it falls back to the environment.
     */
    public function clearGateway(
        string $gateway,
        GatewayRegistry $gateways,
        PaymentGatewayAccounts $accounts,
        AuditLogger $audit,
    ): RedirectResponse {
        abort_unless($gateways->find($gateway) !== null && isset(PaymentGatewayAccounts::FIELDS[$gateway]), 404);

        $accounts->clear($gateway);
        $audit->record('platform.gateway.cleared', null, ['gateway' => $gateway]);

        Toast::success(__('Gateway credentials cleared.'));

        return back();
    }
}
