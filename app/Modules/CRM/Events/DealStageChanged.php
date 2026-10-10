<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Core\Events\TenantDomainEvent;

final class DealStageChanged extends TenantDomainEvent
{
    public function __construct(public readonly string $dealId, public readonly ?string $fromStageId, public readonly string $toStageId, public readonly string $status)
    {
        parent::__construct();
    }

    public static function name(): string
    {
        return 'crm.deal.stage_changed';
    }

    public function payload(): array
    {
        return ['deal_id' => $this->dealId, 'from_stage_id' => $this->fromStageId, 'to_stage_id' => $this->toStageId, 'status' => $this->status];
    }
}
