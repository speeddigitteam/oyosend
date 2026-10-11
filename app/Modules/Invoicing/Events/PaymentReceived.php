<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\Payment;

/** A member recorded a payment against an invoice. */
final class PaymentReceived extends TenantDomainEvent
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    private function __construct(private readonly array $data)
    {
        parent::__construct();
    }

    public static function from(Payment $payment, Invoice $invoice): self
    {
        return new self([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->number,
            'contact_id' => $invoice->contact_id,
            'company_id' => $invoice->company_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'method' => $payment->method->value,
            'paid_on' => $payment->paid_on->toDateString(),
            'balance_amount' => $invoice->balance_amount,
        ]);
    }

    public static function name(): string
    {
        return 'invoicing.payment.received';
    }

    public function payload(): array
    {
        return $this->data;
    }
}
