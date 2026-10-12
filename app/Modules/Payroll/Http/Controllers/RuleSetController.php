<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Support\Toast;
use App\Modules\Payroll\Actions\RuleSetManager;
use App\Modules\Payroll\Calculation\RuleSetEngines;
use App\Modules\Payroll\Enums\RuleBasis;
use App\Modules\Payroll\Enums\RuleCategory;
use App\Modules\Payroll\Enums\RuleMethod;
use App\Modules\Payroll\Enums\RulePayer;
use App\Modules\Payroll\Http\Presenters\PayrollPresenter;
use App\Modules\Payroll\Http\Requests\RuleSetRequest;
use App\Modules\Payroll\Models\RuleSet;
use App\Modules\Payroll\Support\PayrollPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurable compliance rule sets (tax and contribution slab tables). The workspace enters every
 * number; nothing is preloaded.
 */
final class RuleSetController
{
    public function __construct(
        private readonly RuleSetManager $rules,
        private readonly RuleSetEngines $engines,
        private readonly AccessChecker $access,
        private readonly PayrollControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);

        return Inertia::render('Modules/Payroll/Pages/Rules/Index', [
            'ruleSets' => RuleSet::query()->with('slabs')->orderBy('code')->orderByDesc('effective_from')->get()
                ->map(static fn (RuleSet $rule): array => PayrollPresenter::ruleSet($rule))->all(),
            'options' => $this->options(),
            'currency' => $this->support->currency(),
            'canManage' => $this->access->allows($actor, PayrollPermissions::RULES_MANAGE),
        ]);
    }

    public function show(Request $request, RuleSet $ruleSet): Response
    {
        $actor = $this->support->actor($request);
        $ruleSet->load('slabs');

        return Inertia::render('Modules/Payroll/Pages/Rules/Show', [
            'ruleSet' => PayrollPresenter::ruleSet($ruleSet),
            'options' => $this->options(),
            'currency' => $this->support->currency(),
            'canManage' => $this->access->allows($actor, PayrollPermissions::RULES_MANAGE),
        ]);
    }

    public function store(RuleSetRequest $request): RedirectResponse
    {
        $rule = $this->rules->save(null, $request->validated());
        Toast::success(__('Rule set created.'));

        return redirect()->route('payroll.rules.show', $rule);
    }

    public function update(RuleSetRequest $request, RuleSet $ruleSet): RedirectResponse
    {
        $this->rules->save($ruleSet, $request->validated());
        Toast::success(__('Rule set saved.'));

        return back();
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'engines' => $this->engines->keys(),
            'categories' => RuleCategory::values(),
            'payers' => RulePayer::values(),
            'bases' => RuleBasis::values(),
            'methods' => RuleMethod::values(),
            'employmentTypes' => ['full_time', 'part_time', 'contract', 'intern', 'consultant'],
        ];
    }
}
