<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\CRM\Models\Deal;

final class DealWon extends TenantDomainEvent
{
    public readonly string $dealId;

    public readonly int $amount;

    public readonly string $currency;

    public readonly ?string $contactId;

    public readonly ?string $companyId;

    public function __construct(Deal $deal)
    {
        parent::__construct();
        $this->dealId = $deal->id;
        $this->amount = $deal->amount;
        $this->currency = $deal->currency;
        $this->contactId = $deal->contact_id;
        $this->companyId = $deal->company_id;
    }

    public static function name(): string
    {
        return 'crm.deal.won';
    }

    public function payload(): array
    {
        return ['deal_id' => $this->dealId, 'amount' => $this->amount, 'currency' => $this->currency, 'contact_id' => $this->contactId, 'company_id' => $this->companyId];
    }
}
