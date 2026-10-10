<?php

declare(strict_types=1);

namespace App\Core\Billing\Http\Controllers;

use App\Core\Billing\Gateways\GatewayRegistry;
use App\Core\Billing\Models\Invoice;
use App\Core\Billing\Models\Payment;
use App\Core\Billing\PaymentProcessor;
use App\Core\Billing\Support\BillingPresenter;
use App\Core\Identity\Models\User;
use App\Core\Settings\PlatformSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class InvoiceController
{
    public function show(Request $request, Invoice $invoice, GatewayRegistry $gateways, PlatformSettings $settings): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');

        return Inertia::render('Core/Pages/Billing/Invoice', [
            'invoice' => BillingPresenter::invoiceDocument($invoice),
            'payments' => $invoice->payments()
                ->latest('created_at')
                ->get()
                ->map(fn (Payment $payment): array => BillingPresenter::payment($payment))
                ->all(),
            // Empty when no gateway has credentials: the page then says so.
            'gateways' => $invoice->isOpen() ? array_map(
                static fn ($gateway): array => ['key' => $gateway->key(), 'name' => $gateway->name()],
                $gateways->usable($invoice->currency),
            ) : [],
            'offlineInstructions' => $invoice->isOpen() ? $settings->offlineInstructions() : null,
            'canPay' => $invoice->isOpen() && $invoice->total_amount > 0 && $actor->can('core.billing.manage'),
        ]);
    }

    /**
     * Start paying the invoice at a gateway. Answers with a redirect to it.
     */
    public function pay(Request $request, Invoice $invoice, PaymentProcessor $payments): HttpResponse
    {
        $input = $request->validate([
            'gateway' => ['required', 'string', 'max:40'],
        ]);

        /** @var User $actor */
        $actor = $request->user('web');

        $redirect = $payments->begin($invoice, $input['gateway'], $actor);

        // Another origin: Inertia has to leave the page for it.
        return Inertia::location($redirect->url);
    }
}
