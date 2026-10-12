<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Talent\Actions\ChecklistManager;
use App\Modules\Talent\Actions\RecruitmentCatalogue;
use App\Modules\Talent\Actions\TalentSettings;
use App\Modules\Talent\Enums\AssigneeRole;
use App\Modules\Talent\Enums\ChecklistKind;
use App\Modules\Talent\Enums\StageType;
use App\Modules\Talent\Http\Presenters\TalentPresenter;
use App\Modules\Talent\Http\Requests\PipelineRequest;
use App\Modules\Talent\Http\Requests\RejectionReasonRequest;
use App\Modules\Talent\Http\Requests\SettingsRequest;
use App\Modules\Talent\Http\Requests\StageRequest;
use App\Modules\Talent\Http\Requests\TemplateRequest;
use App\Modules\Talent\Models\ChecklistTemplate;
use App\Modules\Talent\Models\Pipeline;
use App\Modules\Talent\Models\PipelineStage;
use App\Modules\Talent\Models\RejectionReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Workspace configuration: retention and reminder windows, pipelines, rejection reasons, checklist templates. */
final class SettingsController
{
    public function __construct(
        private readonly TalentSupport $support,
        private readonly TalentSettings $settings,
        private readonly RecruitmentCatalogue $catalogue,
        private readonly ChecklistManager $checklists,
    ) {}

    public function index(Request $request): Response
    {
        $this->support->user($request);
        // Make sure the standard pipeline exists the first time an administrator opens the page.
        $this->catalogue->defaultPipeline();
        $templates = ChecklistTemplate::query()->with('tasks')->orderBy('kind')->orderBy('name')->get();
        $people = $this->support->people($templates->flatMap(static fn (ChecklistTemplate $template): array => $template->tasks->pluck('assignee_employee_id')->all())->all());
        $settings = $this->settings->current();

        return Inertia::render('Modules/Talent/Pages/Settings/Index', [
            'settings' => [
                'defaultProbationDays' => $settings->default_probation_days,
                'probationReminderDays' => $settings->probation_reminder_days,
                'certificationReminderDays' => $settings->certification_reminder_days,
                'candidateRetentionMonths' => $settings->candidate_retention_months,
            ],
            'pipelines' => Pipeline::query()->with('stages')->orderByDesc('is_default')->orderBy('name')->get()
                ->map(static fn (Pipeline $pipeline): array => TalentPresenter::pipeline($pipeline))->all(),
            'rejectionReasons' => RejectionReason::query()->orderBy('name')->get()
                ->map(static fn (RejectionReason $reason): array => ['id' => $reason->id, 'name' => $reason->name, 'isActive' => $reason->is_active])->all(),
            'templates' => $templates->map(static fn (ChecklistTemplate $template): array => TalentPresenter::template($template, $people))->all(),
            'employees' => $this->support->employeeOptions(),
            'options' => [
                'stageTypes' => StageType::values(),
                'checklistKinds' => ChecklistKind::values(),
                'assigneeRoles' => AssigneeRole::values(),
            ],
        ]);
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        $this->settings->update($request->validated());
        Toast::success(__('Talent settings saved.'));

        return back();
    }

    public function storePipeline(PipelineRequest $request): RedirectResponse
    {
        $this->catalogue->savePipeline(null, $request->validated());
        Toast::success(__('Pipeline created.'));

        return back();
    }

    public function updatePipeline(PipelineRequest $request, Pipeline $pipeline): RedirectResponse
    {
        $this->catalogue->savePipeline($pipeline, $request->validated());
        Toast::success(__('Pipeline saved.'));

        return back();
    }

    public function storeStage(StageRequest $request, Pipeline $pipeline): RedirectResponse
    {
        /** @var array{name: string, type: string, position?: int|null} $data */
        $data = $request->validated();
        $this->catalogue->saveStage($pipeline, null, $data);
        Toast::success(__('Stage added.'));

        return back();
    }

    public function updateStage(StageRequest $request, Pipeline $pipeline, PipelineStage $stage): RedirectResponse
    {
        abort_unless($stage->pipeline_id === $pipeline->id, 404);
        /** @var array{name: string, type: string, position?: int|null} $data */
        $data = $request->validated();
        $this->catalogue->saveStage($pipeline, $stage, $data);
        Toast::success(__('Stage saved.'));

        return back();
    }

    public function storeReason(RejectionReasonRequest $request): RedirectResponse
    {
        $this->catalogue->saveReason(null, $request->validated());
        Toast::success(__('Rejection reason added.'));

        return back();
    }

    public function updateReason(RejectionReasonRequest $request, RejectionReason $reason): RedirectResponse
    {
        $this->catalogue->saveReason($reason, $request->validated());
        Toast::success(__('Rejection reason saved.'));

        return back();
    }

    public function storeTemplate(TemplateRequest $request): RedirectResponse
    {
        /** @var array{name: string, kind: string, is_default?: bool, is_active?: bool, tasks?: list<array<string, mixed>>} $data */
        $data = $request->validated();
        $this->checklists->saveTemplate(null, $data);
        Toast::success(__('Template created.'));

        return back();
    }

    public function updateTemplate(TemplateRequest $request, ChecklistTemplate $template): RedirectResponse
    {
        /** @var array{name: string, kind: string, is_default?: bool, is_active?: bool, tasks?: list<array<string, mixed>>} $data */
        $data = $request->validated();
        $this->checklists->saveTemplate($template, $data);
        Toast::success(__('Template saved.'));

        return back();
    }
}
