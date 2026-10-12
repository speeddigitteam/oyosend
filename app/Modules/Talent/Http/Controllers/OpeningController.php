<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Talent\Actions\ApplicationManager;
use App\Modules\Talent\Actions\OpeningManager;
use App\Modules\Talent\Actions\RecruitmentCatalogue;
use App\Modules\Talent\Enums\ApplicationStatus;
use App\Modules\Talent\Enums\OpeningStatus;
use App\Modules\Talent\Http\Presenters\TalentPresenter;
use App\Modules\Talent\Http\Requests\ApplyRequest;
use App\Modules\Talent\Http\Requests\OpeningRequest;
use App\Modules\Talent\Http\Requests\OpeningStatusRequest;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\JobOpening;
use App\Modules\Talent\Models\Pipeline;
use App\Modules\Talent\Support\TalentPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OpeningController
{
    public function __construct(
        private readonly TalentSupport $support,
        private readonly OpeningManager $openings,
        private readonly ApplicationManager $applications,
        private readonly RecruitmentCatalogue $catalogue,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->user($request);
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,open,on_hold,closed,all'],
            'department_id' => ['nullable', 'string', 'size:26'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'all';

        $query = $this->support->scoped(JobOpening::query(), $actor, TalentPermissions::OPENINGS_VIEW)
            ->with(['department', 'branch'])
            ->withCount([
                'applications',
                'applications as hired_count' => fn ($applications) => $applications->where('status', ApplicationStatus::Hired),
            ]);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if (($filters['department_id'] ?? '') !== '') {
            $query->where('department_id', $filters['department_id']);
        }

        if (($filters['q'] ?? '') !== '') {
            $query->where('title', 'like', Like::contains((string) $filters['q']));
        }

        $page = $query->orderByRaw("case status when 'open' then 0 when 'on_hold' then 1 when 'draft' then 2 else 3 end")
            ->orderByDesc('created_at')->paginate(25)->withQueryString();
        $people = $this->support->people($page->getCollection()->pluck('hiring_manager_id')->all());
        $page->through(fn (JobOpening $opening): array => TalentPresenter::opening(
            $opening, $people, (int) $opening->getAttribute('applications_count'), (int) $opening->getAttribute('hired_count'),
        ));

        return Inertia::render('Modules/Talent/Pages/Openings/Index', [
            'openings' => $page,
            'filters' => ['status' => $status, 'department_id' => $filters['department_id'] ?? '', 'q' => $filters['q'] ?? ''],
            'statuses' => OpeningStatus::values(),
            'departments' => $this->support->organization()['departments'],
            'canCreate' => $this->support->can($actor, TalentPermissions::OPENINGS_CREATE),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->support->user($request);

        return Inertia::render('Modules/Talent/Pages/Openings/Create', ['form' => $this->form()]);
    }

    public function store(OpeningRequest $request): RedirectResponse
    {
        $actor = $this->support->user($request);
        $opening = $this->openings->create($request->validated(), $this->support->membership($actor));
        Toast::success(__('Job opening created.'));

        return to_route('talent.openings.show', $opening);
    }

    public function show(Request $request, JobOpening $opening): Response
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::OPENINGS_VIEW, $opening);
        $opening->load(['department', 'branch']);
        $pipeline = Pipeline::query()->with('stages')->findOrFail($opening->pipeline_id);
        $hired = $this->openings->hiredCount($opening);
        $canViewApplications = $this->support->can($actor, TalentPermissions::APPLICATIONS_VIEW);
        $detail = $this->support->can($actor, TalentPermissions::CANDIDATES_VIEW);

        $applications = [];

        if ($canViewApplications) {
            $applications = $this->support->scoped(Application::query(), $actor, TalentPermissions::APPLICATIONS_VIEW)
                ->where('opening_id', $opening->id)->with(['candidate', 'opening', 'stage'])
                ->orderBy('applied_on')->limit(500)->get()
                ->map(fn (Application $application): array => TalentPresenter::application(
                    $application,
                    $this->support->allows($actor, TalentPermissions::CANDIDATES_VIEW, $application->candidate),
                ))->all();
        }

        $people = $this->support->people([$opening->hiring_manager_id]);
        $manage = $this->support->allows($actor, TalentPermissions::OPENINGS_MANAGE, $opening);

        return Inertia::render('Modules/Talent/Pages/Openings/Show', [
            'opening' => TalentPresenter::opening($opening, $people, count($applications), $hired),
            'pipeline' => TalentPresenter::pipeline($pipeline),
            'applications' => $applications,
            'statusOptions' => $manage ? $this->transitions($opening->status) : [],
            'abilities' => [
                'edit' => $manage,
                'changeStatus' => $manage,
                'viewApplications' => $canViewApplications,
                'addApplication' => $opening->status === OpeningStatus::Open && $this->support->can($actor, TalentPermissions::APPLICATIONS_MANAGE),
                'viewCandidates' => $detail,
            ],
            'form' => $manage ? $this->form($opening) : null,
        ]);
    }

    public function update(OpeningRequest $request, JobOpening $opening): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::OPENINGS_MANAGE, $opening);
        $this->openings->update($opening, $request->validated(), $this->support->membership($actor));
        Toast::success(__('Job opening saved.'));

        return back();
    }

    public function status(OpeningStatusRequest $request, JobOpening $opening): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::OPENINGS_MANAGE, $opening);
        $this->openings->changeStatus($opening, OpeningStatus::from($request->validated('status')), $this->support->membership($actor));
        Toast::success(__('Job opening status changed.'));

        return back();
    }

    public function apply(ApplyRequest $request, JobOpening $opening): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::OPENINGS_VIEW, $opening);
        $candidate = Candidate::query()->findOrFail($request->validated('candidate_id'));
        // The candidate must be inside the person's candidate scope too.
        $this->support->authorize($actor, TalentPermissions::CANDIDATES_VIEW, $candidate);
        $application = $this->applications->apply($opening, $candidate, ['source' => $request->validated('source')], $this->support->membership($actor));
        Toast::success(__('Candidate added to the opening.'));

        return to_route('talent.applications.show', $application);
    }

    /** @return list<string> */
    private function transitions(OpeningStatus $from): array
    {
        return array_map(static fn (OpeningStatus $status): string => $status->value, match ($from) {
            OpeningStatus::Draft => [OpeningStatus::Open, OpeningStatus::Closed],
            OpeningStatus::Open => [OpeningStatus::OnHold, OpeningStatus::Closed],
            OpeningStatus::OnHold => [OpeningStatus::Open, OpeningStatus::Closed],
            OpeningStatus::Closed => [OpeningStatus::Open],
        });
    }

    /** @return array<string, mixed> */
    private function form(?JobOpening $opening = null): array
    {
        return $this->support->organization() + [
            'hiringManagers' => $this->support->employeeOptions(),
            'pipelines' => Pipeline::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(static fn (Pipeline $pipeline): array => ['id' => $pipeline->id, 'name' => $pipeline->name])->all(),
            'employmentTypes' => ['full_time', 'part_time', 'contract', 'intern', 'consultant'],
            'defaultPipelineId' => $this->catalogue->defaultPipeline()->id,
            'canChangePipeline' => $opening === null || ! Application::query()->where('opening_id', $opening->id)->exists(),
        ];
    }
}
