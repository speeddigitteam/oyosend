<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Modules\WhatsappMarketing\Enums\FlowMatch;
use App\Modules\WhatsappMarketing\Enums\FlowTrigger;
use App\Modules\WhatsappMarketing\Models\Flow;
use App\Modules\WhatsappMarketing\Support\Text;
use Illuminate\Validation\ValidationException;

/** Saves chatbot flows. Everything stored passed {@see FlowDefinition}. */
final class FlowManager
{
    public function __construct(private readonly FlowDefinition $definition) {}

    /**
     * @param  array<string, mixed>  $input  name, trigger, match_type?, keywords?, actions, priority?, is_active?
     */
    public function save(?Flow $flow, array $input, ?string $actorMembershipId = null): Flow
    {
        $name = Text::clean((string) $input['name'], 120, false);
        $trigger = FlowTrigger::from((string) $input['trigger']);

        if (Flow::query()->where('name', $name)->when($flow !== null, fn ($query) => $query->whereKeyNot($flow?->id))->exists()) {
            throw ValidationException::withMessages(['name' => __('A flow with this name already exists.')]);
        }

        $keywords = null;
        $match = null;

        if ($trigger === FlowTrigger::Keyword) {
            $keywords = $this->definition->keywords((array) ($input['keywords'] ?? []));
            $match = FlowMatch::tryFrom((string) ($input['match_type'] ?? '')) ?? FlowMatch::Exact;
        }

        $flow ??= new Flow(['created_by' => $actorMembershipId]);
        $flow->fill([
            'name' => $name,
            'trigger' => $trigger,
            'match_type' => $match,
            'keywords' => $keywords,
            'actions' => $this->definition->actions((array) ($input['actions'] ?? [])),
            'priority' => max(1, min(1000, (int) ($input['priority'] ?? 100))),
            'is_active' => (bool) ($input['is_active'] ?? true),
        ])->save();

        return $flow;
    }
}
