<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Widgets;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Models\User;
use App\Modules\Payroll\Models\Payslip;

/** The signed-in person's own latest payslip, shown with `payroll.payslips.view` (own records only). */
final class PayrollSelfWidget
{
    public function __construct(private readonly AccessChecker $access) {}

    /**
     * @return array{count: int, latest: array{id: string, number: string|null, periodName: string, netAmount: int, currency: string, paymentStatus: string, paidOn: string|null}|null}
     */
    public function __invoke(User $user): array
    {
        $membership = $this->access->membership($user);

        if ($membership === null) {
            return ['count' => 0, 'latest' => null];
        }

        $own = Payslip::query()->where('owner_id', $membership->id)->whereNotNull('locked_at');
        $latest = (clone $own)->with('period:id,name')->orderByDesc('created_at')->orderByDesc('id')->first();

        return [
            'count' => (clone $own)->count(),
            'latest' => $latest === null ? null : [
                'id' => $latest->id,
                'number' => $latest->number,
                'periodName' => $latest->period->name,
                'netAmount' => $latest->net_amount,
                'currency' => $latest->currency,
                'paymentStatus' => $latest->payment_status->value,
                'paidOn' => $latest->paid_on?->toDateString(),
            ],
        ];
    }
}
