<?php

declare(strict_types=1);

namespace App\Core\Billing\Http\Controllers;

use App\Core\Billing\Gateways\GatewayRegistry;
use App\Core\Billing\Models\Payment;
use App\Core\Billing\PaymentProcessor;
use App\Core\Tenancy\Support\TenantHost;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where payment gateways send the payer back to, and where they notify us.
 *
 * Both run on the workspace's own host, so the tenant comes from the hostname
 * like everywhere else. They are stateless on purpose - no session, no
 * cookies, no CSRF token: gateways return the payer with a cross-site POST,
 * which carries no session cookie, and starting a new session here would
 * replace the one the payer is signed in with.
 *
 * Neither believes what it is sent. Each only finds the payment and lets
 * {@see PaymentProcessor::confirm()} ask the gateway.
 */
final class GatewayCallbackController
{
    public function __construct(
        private readonly PaymentProcessor $payments,
        private readonly TenantContext $context,
    ) {}

    /**
     * The payer's browser returning from the gateway, whatever the outcome.
     */
    public function returned(Request $request, string $reference, TenantHost $hosts): RedirectResponse
    {
        $payment = Payment::query()->where('reference', $reference)->first();

        if ($payment !== null) {
            $this->payments->confirm($payment, $request->all());
        }

        // The billing page (signed in, with its session) reports what happened.
        return redirect()->away($hosts->url($this->context->require(), 'billing?payment='.$reference), 303);
    }

    /**
     * The gateway's own server-to-server notification (IPN, webhook).
     */
    public function notify(Request $request, string $gateway, GatewayRegistry $gateways): Response
    {
        $reference = $gateways->find($gateway)?->referenceFrom($request->all());

        $payment = $reference === null ? null : Payment::query()
            ->where('gateway', $gateway)
            ->where('reference', $reference)
            ->first();

        if ($payment !== null) {
            $this->payments->confirm($payment, $request->all());
        }

        // Always the same answer: it tells a caller nothing about which references exist.
        return response('OK', 200)->header('Content-Type', 'text/plain');
    }
}
