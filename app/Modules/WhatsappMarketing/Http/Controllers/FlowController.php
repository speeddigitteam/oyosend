<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\WhatsappMarketing\Actions\FlowDefinition;
use App\Modules\WhatsappMarketing\Actions\FlowManager;
use App\Modules\WhatsappMarketing\Actions\VariableResolver;
use App\Modules\WhatsappMarketing\Enums\FlowMatch;
use App\Modules\WhatsappMarketing\Enums\FlowTrigger;
use App\Modules\WhatsappMarketing\Http\Presenter;
use App\Modules\WhatsappMarketing\Models\Flow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Chatbot flows: keywords or a welcome trigger, then a closed list of steps. */
final class FlowController
{
    public function __construct(
        private readonly FlowManager $flows,
        private readonly Presenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $this->presenter->actor($request);

        return Inertia::render('Modules/WhatsappMarketing/Pages/Flows/Index', [
            'flows' => Flow::query()->orderBy('priority')->orderBy('name')->limit(200)->get()
                ->map(fn (Flow $flow): array => $this->presenter->flow($flow))->all(),
            'agents' => $this->presenter->agents(),
            'limits' => [
                'actions' => FlowDefinition::MAX_ACTIONS,
                'options' => FlowDefinition::MAX_OPTIONS,
                'optionActions' => FlowDefinition::MAX_OPTION_ACTIONS,
                'keywords' => FlowDefinition::MAX_KEYWORDS,
            ],
            'placeholders' => VariableResolver::FIELDS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $flow = $this->flows->save(null, $this->validated($request), $this->presenter->membership($actor)->id);
        Toast::success(__('Flow :name saved.', ['name' => $flow->name]));

        return back();
    }

    public function update(Request $request, Flow $flow): RedirectResponse
    {
        $this->flows->save($flow, $this->validated($request));
        Toast::success(__('Flow :name saved.', ['name' => $flow->name]));

        return back();
    }

    public function destroy(Flow $flow): RedirectResponse
    {
        $flow->delete();
        Toast::success(__('Flow deleted.'));

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', Rule::enum(FlowTrigger::class)],
            'match_type' => ['nullable', Rule::enum(FlowMatch::class)],
            'keywords' => ['nullable', 'array', 'max:'.FlowDefinition::MAX_KEYWORDS],
            'keywords.*' => ['string', 'max:40'],
            'actions' => ['required', 'array', 'min:1', 'max:'.FlowDefinition::MAX_ACTIONS],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
