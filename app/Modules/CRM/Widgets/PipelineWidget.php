<?php

declare(strict_types=1);

namespace App\Modules\CRM\Widgets;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Modules\CRM\Actions\SalesMath;
use App\Modules\CRM\Enums\ActivityStatus;
use App\Modules\CRM\Enums\DealStatus;
use App\Modules\CRM\Enums\LeadStatus;
use App\Modules\CRM\Http\Controllers\ActivityController;
use App\Modules\CRM\Http\Controllers\DealController;
use App\Modules\CRM\Http\Controllers\LeadController;
use App\Modules\CRM\Models\Activity;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;

final class PipelineWidget
{
    public function __construct(private readonly RecordAccess $records, private readonly TenantContext $context) {}

    /** @return array<string, int|string> */
    public function __invoke(User $user): array
    {
        $deals = $this->records->constrain(Deal::query(), $user, DealController::VIEW)
            ->where('status', DealStatus::Open)
            ->where('currency', $this->context->require()->currency)
            ->with('stage:id,probability')
            ->get(['id', 'stage_id', 'amount', 'currency']);
        $openAmount = SalesMath::sum($deals->pluck('amount'));
        $weightedAmount = SalesMath::sum($deals->map(
            fn (Deal $deal): int => SalesMath::multiplyDivide($deal->amount, $deal->stage->probability, 100),
        ));
        $newLeads = $user->can(LeadController::VIEW)
            ? $this->records->constrain(Lead::query(), $user, LeadController::VIEW)->where('status', LeadStatus::New)->count()
            : 0;
        $overdue = $user->can(ActivityController::VIEW)
            ? $this->records->constrain(Activity::query(), $user, ActivityController::VIEW)->where('status', ActivityStatus::Planned)->where('due_at', '<', now())->count()
            : 0;

        return [
            'openDeals' => $deals->count(),
            'openAmount' => $openAmount,
            'weightedAmount' => $weightedAmount,
            'newLeads' => $newLeads,
            'overdueActivities' => $overdue,
            'currency' => $this->context->require()->currency,
        ];
    }
}
