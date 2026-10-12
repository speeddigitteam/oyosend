<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Payroll\Actions\PaymentManager;
use App\Modules\Payroll\Enums\RunStatus;
use App\Modules\Payroll\Http\Requests\PaymentRequest;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\Run;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/** Records payment outcomes (paid / failed) for payslips. Payroll itself never moves money. */
final class PaymentController
{
    public function __construct(
        private readonly PaymentManager $payments,
        private readonly PayrollControllerSupport $support,
    ) {}

    public function store(PaymentRequest $request, Payslip $payslip): RedirectResponse
    {
        $input = $request->validated();
        $membership = $this->support->membership($this->support->actor($request));
        $this->payments->record($payslip, $input, (string) $input['idempotency_key'], $membership);
        Toast::success($input['status'] === 'paid' ? __('Payment recorded.') : __('Failed payment recorded.'));

        return back();
    }

    /** Record the same decision for every unpaid payslip of an approved run. */
    public function storeForRun(PaymentRequest $request, Run $run): RedirectResponse
    {
        if ($run->status !== RunStatus::Approved) {
            throw ValidationException::withMessages(['run' => __('Only an approved run can be paid.')]);
        }

        if (! PaymentManager::isPayable($run)) {
            throw ValidationException::withMessages(['run' => __('This run has been reversed; its payslips can no longer be paid.')]);
        }

        $input = $request->validated();
        $membership = $this->support->membership($this->support->actor($request));
        $count = $this->payments->recordRun($run, $input, (string) $input['idempotency_key'], $membership);
        Toast::success(__(':count payslips recorded.', ['count' => $count]));

        return back();
    }
}
