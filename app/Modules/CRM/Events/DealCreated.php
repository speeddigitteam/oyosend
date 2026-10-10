<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\CRM\Models\Deal;

final class DealCreated extends TenantDomainEvent
{
    public readonly string $dealId;

    public readonly string $title;

    public readonly int $amount;

    public readonly string $currency;

    public readonly string $stageId;

    public function __construct(Deal $deal)
    {
        parent::__construct();
        $this->dealId = $deal->id;
        $this->title = $deal->title;
        $this->amount = $deal->amount;
        $this->currency = $deal->currency;
        $this->stageId = $deal->stage_id;
    }

    public static function name(): string
    {
        return 'crm.deal.created';
    }

    public function payload(): array
    {
        return ['deal_id' => $this->dealId, 'title' => $this->title, 'amount' => $this->amount, 'currency' => $this->currency, 'stage_id' => $this->stageId];
    }
}
