<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\Projects\Actions\ProjectSettingsManager;
use App\Modules\Projects\Enums\Priority;
use App\Modules\Projects\Models\ProjectCategory;
use App\Modules\Projects\Models\ProjectTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class SettingsController
{
    public function __construct(private readonly ProjectSettingsManager $settings, private readonly TenantContext $context) {}

    public function index(): Response
    {
        return Inertia::render('Modules/Projects/Pages/Settings/Index', [
            'categories' => ProjectCategory::query()->orderBy('position')->orderBy('name')->get(),
            'templates' => ProjectTemplate::query()->with('category:id,name')->orderBy('name')->get()->map(fn (ProjectTemplate $template): array => ['id' => $template->id, 'name' => $template->name, 'description' => $template->description, 'category' => $template->category?->only(['id', 'name']), 'taskCount' => count($template->task_blueprints ?? []), 'active' => $template->is_active])->all(),
        ]);
    }

    public function category(Request $request): RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('project_categories', 'name')->where('tenant_id', $this->context->requireId())], 'colour' => ['nullable', 'string', 'max:24', 'regex:/^#[0-9a-fA-F]{6}$/'], 'position' => ['nullable', 'integer', 'between:0,65535']]);
        $this->settings->createCategory(['is_active' => true, 'position' => 0, ...$input]);
        Toast::success(__('Project category created.'));

        return back();
    }

    public function template(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('project_templates', 'name')->where('tenant_id', $this->context->requireId())], 'description' => ['nullable', 'string', 'max:10000'], 'category_id' => ['nullable', 'string', 'size:26'],
            'task_blueprints' => ['nullable', 'array', 'max:100'], 'task_blueprints.*.title' => ['required', 'string', 'max:240'], 'task_blueprints.*.description' => ['nullable', 'string', 'max:10000'], 'task_blueprints.*.priority' => ['nullable', Rule::enum(Priority::class)], 'task_blueprints.*.estimated_minutes' => ['nullable', 'integer', 'between:0,10000000'],
        ]);

        if (isset($input['task_blueprints'])) {
            $input['task_blueprints'] = array_map(static function (array $blueprint): array {
                if (($blueprint['estimated_minutes'] ?? null) !== null) {
                    $blueprint['estimated_minutes'] = (int) $blueprint['estimated_minutes'];
                }

                return $blueprint;
            }, $input['task_blueprints']);
        }

        $this->settings->createTemplate(['is_active' => true, ...$input]);
        Toast::success(__('Project template created.'));

        return back();
    }
}
