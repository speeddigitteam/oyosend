<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Support\Toast;
use App\Modules\Invoicing\Actions\InvoiceMath;
use App\Modules\Invoicing\Actions\PaymentManager;
use App\Modules\Invoicing\Enums\PaymentMethod;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PaymentController
{
    public const string RECORD = 'invoicing.payments.record';

    public const string REVERSE = 'invoicing.payments.reverse';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly PaymentManager $payments,
        private readonly InvoicingControllerSupport $support,
    ) {}

    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::RECORD, $invoice);
        $input = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:'.InvoiceMath::MAX_AMOUNT],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:120'],
            'paid_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);
        $this->payments->record($invoice, $input, $this->support->membership($actor));
        Toast::success(__('Payment recorded.'));

        return back();
    }

    public function reverse(Request $request, Invoice $invoice, Payment $payment): RedirectResponse
    {
        $actor = $this->support->actor($request);
        abort_unless($payment->invoice_id === $invoice->id, 404);
        $this->records->authorize($actor, self::REVERSE, $invoice);
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->payments->reverse($payment, $input['reason'], $this->support->membership($actor));
        Toast::success(__('Payment reversed.'));

        return back();
    }
}
