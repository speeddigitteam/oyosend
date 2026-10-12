<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Support\Toast;
use App\Modules\Payroll\Actions\StructureManager;
use App\Modules\Payroll\Enums\ComponentCalc;
use App\Modules\Payroll\Enums\ComponentKind;
use App\Modules\Payroll\Http\Presenters\PayrollPresenter;
use App\Modules\Payroll\Http\Requests\ComponentRequest;
use App\Modules\Payroll\Http\Requests\StructureRequest;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Payroll\Models\StructureComponent;
use App\Modules\Payroll\Support\PayrollPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Salary structures and their recurring components. */
final class StructureController
{
    public function __construct(
        private readonly StructureManager $structures,
        private readonly AccessChecker $access,
        private readonly PayrollControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);

        return Inertia::render('Modules/Payroll/Pages/Structures/Index', [
            'structures' => SalaryStructure::query()->withCount('components')->orderBy('name')->get()
                ->map(static fn (SalaryStructure $structure): array => [
                    ...PayrollPresenter::structure($structure),
                    'componentCount' => (int) $structure->components_count,
                ])->all(),
            'canManage' => $this->access->allows($actor, PayrollPermissions::STRUCTURES_MANAGE),
        ]);
    }

    public function show(Request $request, SalaryStructure $structure): Response
    {
        $actor = $this->support->actor($request);
        $structure->load('components');

        return Inertia::render('Modules/Payroll/Pages/Structures/Show', [
            'structure' => PayrollPresenter::structure($structure),
            'components' => $structure->components->map(static fn (StructureComponent $component): array => PayrollPresenter::component($component))->all(),
            'options' => [
                'kinds' => ComponentKind::values(),
                'calcs' => ComponentCalc::values(),
            ],
            'currency' => $this->support->currency(),
            'canManage' => $this->access->allows($actor, PayrollPermissions::STRUCTURES_MANAGE),
        ]);
    }

    public function store(StructureRequest $request): RedirectResponse
    {
        $structure = $this->structures->saveStructure(null, $request->validated());
        Toast::success(__('Salary structure created.'));

        return redirect()->route('payroll.structures.show', $structure);
    }

    public function update(StructureRequest $request, SalaryStructure $structure): RedirectResponse
    {
        $this->structures->saveStructure($structure, $request->validated());
        Toast::success(__('Salary structure saved.'));

        return back();
    }

    public function storeComponent(ComponentRequest $request, SalaryStructure $structure): RedirectResponse
    {
        $this->structures->saveComponent($structure, null, $request->validated());
        Toast::success(__('Component added.'));

        return back();
    }

    public function updateComponent(ComponentRequest $request, SalaryStructure $structure, StructureComponent $component): RedirectResponse
    {
        abort_unless($component->structure_id === $structure->id, 404);
        $this->structures->saveComponent($structure, $component, $request->validated());
        Toast::success(__('Component saved.'));

        return back();
    }

    public function destroyComponent(SalaryStructure $structure, StructureComponent $component): RedirectResponse
    {
        abort_unless($component->structure_id === $structure->id, 404);
        $this->structures->deleteComponent($component);
        Toast::success(__('Component removed.'));

        return back();
    }
}
