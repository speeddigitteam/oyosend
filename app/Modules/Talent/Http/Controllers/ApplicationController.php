<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Talent\Actions\ApplicationManager;
use App\Modules\Talent\Actions\CandidateManager;
use App\Modules\Talent\Actions\OfferManager;
use App\Modules\Talent\Enums\ApplicationStatus;
use App\Modules\Talent\Enums\OfferStatus;
use App\Modules\Talent\Http\Presenters\TalentPresenter;
use App\Modules\Talent\Http\Requests\HireRequest;
use App\Modules\Talent\Http\Requests\MoveRequest;
use App\Modules\Talent\Http\Requests\NoteOnlyRequest;
use App\Modules\Talent\Http\Requests\NoteRequest;
use App\Modules\Talent\Http\Requests\OfferRequest;
use App\Modules\Talent\Http\Requests\OfferStatusRequest;
use App\Modules\Talent\Http\Requests\RejectRequest;
use App\Modules\Talent\Http\Requests\ReopenRequest;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\ApplicationHistory;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\CandidateDocument;
use App\Modules\Talent\Models\JobOpening;
use App\Modules\Talent\Models\Note;
use App\Modules\Talent\Models\Offer;
use App\Modules\Talent\Models\Pipeline;
use App\Modules\Talent\Models\PipelineStage;
use App\Modules\Talent\Models\RejectionReason;
use App\Modules\Talent\Support\TalentPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The candidate pipeline: applications, their moves, offers and the hire. */
final class ApplicationController
{
    public function __construct(
        private readonly TalentSupport $support,
        private readonly ApplicationManager $applications,
        private readonly OfferManager $offers,
        private readonly CandidateManager $candidates,
        private readonly InterviewViews $interviews,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->user($request);
        $filters = $request->validate([
            'opening_id' => ['nullable', 'string', 'size:26'],
            'stage_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', 'string', 'in:open,hired,rejected,withdrawn,all'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'open';

        $query = $this->support->scoped(Application::query(), $actor, TalentPermissions::APPLICATIONS_VIEW)
            ->with(['candidate', 'opening', 'stage']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        foreach (['opening_id', 'stage_id'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $query->where($column, $filters[$column]);
            }
        }

        if (($filters['q'] ?? '') !== '') {
            $term = Like::contains((string) $filters['q']);
            // Candidate PII is searchable only inside the candidate scope of the person searching.
            $query->whereIn('candidate_id', $this->support->scoped(Candidate::query(), $actor, TalentPermissions::CANDIDATES_VIEW)
                ->where(fn ($match) => $match->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('email', 'like', $term))
                ->select('id'));
        }

        $page = $query->orderByDesc('status_changed_at')->orderByDesc('created_at')->paginate(25)->withQueryString()
            ->through(fn (Application $application): array => TalentPresenter::application(
                $application,
                $this->support->allows($actor, TalentPermissions::CANDIDATES_VIEW, $application->candidate),
            ));

        $stages = [];

        if (($filters['opening_id'] ?? '') !== '') {
            $opening = JobOpening::query()->find($filters['opening_id']);

            if ($opening !== null) {
                $stages = PipelineStage::query()->where('pipeline_id', $opening->pipeline_id)->orderBy('position')->get()
                    ->map(static fn (PipelineStage $stage): array => TalentPresenter::stage($stage))->all();
            }
        }

        return Inertia::render('Modules/Talent/Pages/Applications/Index', [
            'applications' => $page,
            'filters' => ['opening_id' => $filters['opening_id'] ?? '', 'stage_id' => $filters['stage_id'] ?? '', 'status' => $status, 'q' => $filters['q'] ?? ''],
            'stages' => $stages,
            'openings' => $this->support->scoped(JobOpening::query(), $actor, TalentPermissions::OPENINGS_VIEW)->orderBy('title')->limit(200)->get(['id', 'title', 'status'])
                ->map(static fn (JobOpening $opening): array => ['id' => $opening->id, 'title' => $opening->title, 'status' => $opening->status->value])->all(),
            'statuses' => ApplicationStatus::values(),
        ]);
    }

    public function show(Request $request, Application $application): Response
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::APPLICATIONS_VIEW, $application);
        $application->load(['candidate', 'opening.department', 'opening.branch', 'stage', 'history', 'interviews.panel', 'interviews.scorecards']);
        // Candidate details, documents and notes follow the candidate record scope, not just the permission.
        $detail = $this->support->allows($actor, TalentPermissions::CANDIDATES_VIEW, $application->candidate);
        $manage = $this->support->allows($actor, TalentPermissions::APPLICATIONS_MANAGE, $application);
        $isOpen = $application->status === ApplicationStatus::Open;

        $employeeIds = [$application->opening->hiring_manager_id];

        foreach ($application->interviews as $interview) {
            foreach ($interview->panel as $member) {
                $employeeIds[] = $member->employee_id;
            }

            foreach ($interview->scorecards as $card) {
                $employeeIds[] = $card->employee_id;
            }
        }

        $people = $this->support->people($employeeIds);
        $canOffers = $this->support->allows($actor, TalentPermissions::OFFERS_MANAGE, $application);
        $stageIds = $application->history->flatMap(static fn (ApplicationHistory $row): array => [$row->from_stage_id, $row->to_stage_id])->filter()->unique()->all();
        $stageNames = PipelineStage::query()->whereIn('id', $stageIds)->pluck('name', 'id');
        $reasons = RejectionReason::query()->orderBy('name')->get();
        $pipeline = Pipeline::query()->with('stages')->find($application->opening->pipeline_id);

        $live = Offer::query()->where('application_id', $application->id)->whereNotNull('live_marker')->first();

        return Inertia::render('Modules/Talent/Pages/Applications/Show', [
            'application' => TalentPresenter::application($application, $detail),
            'opening' => TalentPresenter::opening($application->opening, $people),
            'history' => $application->history->map(static fn (ApplicationHistory $row): array => [
                'id' => $row->id,
                'event' => $row->event->value,
                'from' => $row->from_stage_id === null ? null : ($stageNames[$row->from_stage_id] ?? null),
                'to' => $row->to_stage_id === null ? null : ($stageNames[$row->to_stage_id] ?? null),
                'note' => $row->note,
                'at' => $row->created_at?->toIso8601String(),
            ])->values()->all(),
            'interviews' => $this->interviews->present($application->interviews, $actor, $people),
            'notes' => $detail ? Note::query()->where('application_id', $application->id)->orderByDesc('created_at')->limit(100)->get()
                ->map(static fn (Note $note): array => TalentPresenter::note($note))->all() : [],
            'documents' => $detail ? CandidateDocument::query()->where('candidate_id', $application->candidate_id)->orderByDesc('created_at')->get()
                ->map(static fn (CandidateDocument $document): array => TalentPresenter::document($document))->all() : [],
            'offer' => $canOffers && $live !== null ? TalentPresenter::offer($live) : null,
            'offers' => $canOffers ? $application->offers()->orderByDesc('created_at')->get()->map(static fn (Offer $offer): array => TalentPresenter::offer($offer))->all() : [],
            'stages' => $pipeline === null ? [] : $pipeline->stages->map(static fn (PipelineStage $stage): array => TalentPresenter::stage($stage))->all(),
            'rejectionReasons' => $reasons->where('is_active', true)->map(static fn (RejectionReason $reason): array => ['id' => $reason->id, 'name' => $reason->name])->values()->all(),
            'abilities' => [
                'move' => $manage && $isOpen,
                'reject' => $manage && $isOpen,
                'withdraw' => $manage && $isOpen,
                'reopen' => $manage && in_array($application->status, [ApplicationStatus::Rejected, ApplicationStatus::Withdrawn], true),
                'hire' => $isOpen && $this->support->allows($actor, TalentPermissions::APPLICATIONS_HIRE, $application),
                'addNote' => $manage,
                'schedule' => $isOpen && $this->support->allows($actor, TalentPermissions::INTERVIEWS_MANAGE, $application),
                'manageOffer' => $canOffers && $isOpen,
                'viewCandidate' => $detail,
            ],
            'offerStatuses' => OfferStatus::values(),
            // The panel picker of the schedule dialog: only for people who may schedule this interview.
            'employees' => $isOpen && $this->support->allows($actor, TalentPermissions::INTERVIEWS_MANAGE, $application) ? $this->support->employeeOptions($actor, TalentPermissions::INTERVIEWS_MANAGE) : [],
            'timezone' => $this->support->timezone(),
        ]);
    }

    public function move(MoveRequest $request, Application $application): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::APPLICATIONS_MANAGE, $application);
        $stage = PipelineStage::query()->findOrFail($request->validated('stage_id'));
        $this->applications->move($application, $stage, $request->validated('note'), $this->support->membership($actor));
        Toast::success(__('Application moved.'));

        return back();
    }

    public function reject(RejectRequest $request, Application $application): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::APPLICATIONS_MANAGE, $application);
        $reasonId = $request->validated('reason_id');
        $reason = $reasonId === null ? null : RejectionReason::query()->findOrFail($reasonId);
        $this->applications->reject($application, $reason, $request->validated('note'), $this->support->membership($actor));
        Toast::success(__('Application rejected.'));

        return back();
    }

    public function withdraw(NoteOnlyRequest $request, Application $application): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::APPLICATIONS_MANAGE, $application);
        $this->applications->withdraw($application, $request->validated('note'), $this->support->membership($actor));
        Toast::success(__('Application withdrawn.'));

        return back();
    }

    public function reopen(ReopenRequest $request, Application $application): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::APPLICATIONS_MANAGE, $application);
        $stageId = $request->validated('stage_id');
        $stage = $stageId === null ? null : PipelineStage::query()->findOrFail($stageId);
        $this->applications->reopen($application, $stage, $request->validated('note'), $this->support->membership($actor));
        Toast::success(__('Application reopened.'));

        return back();
    }

    public function hire(HireRequest $request, Application $application): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::APPLICATIONS_HIRE, $application);
        $this->applications->hire($application, ['start_on' => $request->validated('start_on')], $this->support->membership($actor));
        Toast::success(__('Candidate hired. HR can now create the employee record.'));

        return back();
    }

    public function storeNote(NoteRequest $request, Application $application): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::APPLICATIONS_MANAGE, $application);
        $candidate = $application->candidate;
        $this->candidates->addNote($candidate, $application, $request->validated('body'), $this->support->membership($actor));
        Toast::success(__('Note added.'));

        return back();
    }

    public function storeOffer(OfferRequest $request, Application $application): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::OFFERS_MANAGE, $application);
        $this->offers->create($application, $request->validated(), $this->support->membership($actor));
        Toast::success(__('Offer saved.'));

        return back();
    }

    public function updateOffer(OfferRequest $request, Offer $offer): RedirectResponse
    {
        $actor = $this->support->user($request);
        $application = Application::query()->findOrFail($offer->application_id);
        $this->support->authorize($actor, TalentPermissions::OFFERS_MANAGE, $application);
        $this->offers->update($offer, $request->validated());
        Toast::success(__('Offer saved.'));

        return back();
    }

    public function offerStatus(OfferStatusRequest $request, Offer $offer): RedirectResponse
    {
        $actor = $this->support->user($request);
        $application = Application::query()->findOrFail($offer->application_id);
        $this->support->authorize($actor, TalentPermissions::OFFERS_MANAGE, $application);
        $this->offers->transition($offer, OfferStatus::from($request->validated('status')), $this->support->membership($actor));
        Toast::success(__('Offer updated.'));

        return back();
    }
}
