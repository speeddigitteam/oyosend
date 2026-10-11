<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\Payment;

/** A recorded payment was reversed and the invoice balance restored. */
final class PaymentReversed extends TenantDomainEvent
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
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'balance_amount' => $invoice->balance_amount,
        ]);
    }

    public static function name(): string
    {
        return 'invoicing.payment.reversed';
    }

    public function payload(): array
    {
        return $this->data;
    }
}
