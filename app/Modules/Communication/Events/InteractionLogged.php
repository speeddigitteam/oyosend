<?php

declare(strict_types=1);

namespace App\Modules\Communication\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Communication\Models\Interaction;

/** An entry was added to a contact's or company's timeline. */
final class InteractionLogged extends TenantDomainEvent
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    private function __construct(private readonly array $data)
    {
        parent::__construct();
    }

    public static function from(Interaction $interaction): self
    {
        return new self([
            'interaction_id' => $interaction->id,
            'type' => $interaction->type->value,
            'direction' => $interaction->direction,
            'contact_id' => $interaction->contact_id,
            'company_id' => $interaction->company_id,
            'source_module' => $interaction->source_module,
        ]);
    }

    public static function name(): string
    {
        return 'communication.interaction.logged';
    }

    public function payload(): array
    {
        return $this->data;
    }
}
