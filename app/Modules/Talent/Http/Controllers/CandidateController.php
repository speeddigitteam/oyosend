<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Talent\Actions\CandidateManager;
use App\Modules\Talent\Enums\ConsentBasis;
use App\Modules\Talent\Enums\DocumentType;
use App\Modules\Talent\Http\Presenters\TalentPresenter;
use App\Modules\Talent\Http\Requests\CandidateDocumentRequest;
use App\Modules\Talent\Http\Requests\CandidateRequest;
use App\Modules\Talent\Http\Requests\NoteRequest;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\CandidateDocument;
use App\Modules\Talent\Models\Note;
use App\Modules\Talent\Support\TalentPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** Candidate records, their private documents and notes. Candidate data is sensitive: every route is scoped. */
final class CandidateController
{
    public function __construct(
        private readonly TalentSupport $support,
        private readonly CandidateManager $candidates,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $actor = $this->support->user($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'include_anonymized' => ['nullable', 'boolean'],
        ]);

        $query = $this->support->scoped(Candidate::query(), $actor, TalentPermissions::CANDIDATES_VIEW)
            ->withCount(['applications', 'applications as open_applications_count' => fn ($applications) => $applications->where('status', 'open')]);

        if (! ($filters['include_anonymized'] ?? false)) {
            $query->whereNull('anonymized_at');
        }

        if (($filters['q'] ?? '') !== '') {
            $term = Like::contains((string) $filters['q']);
            $query->where(fn ($match) => $match->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)
                ->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term));
        }

        $page = $query->orderByDesc('created_at')->paginate(25)->withQueryString()
            ->through(static fn (Candidate $candidate): array => TalentPresenter::candidate($candidate) + [
                'applications' => (int) $candidate->getAttribute('applications_count'),
                'openApplications' => (int) $candidate->getAttribute('open_applications_count'),
            ]);

        return Inertia::render('Modules/Talent/Pages/Candidates/Index', [
            'candidates' => $page,
            'filters' => ['q' => $filters['q'] ?? '', 'include_anonymized' => (bool) ($filters['include_anonymized'] ?? false)],
            'consentBases' => ConsentBasis::values(),
            'canCreate' => $this->support->can($actor, TalentPermissions::CANDIDATES_MANAGE),
        ]);
    }

    /** Duplicate check by email for the add-candidate form (JSON): candidates the person may see. */
    public function lookup(Request $request): JsonResponse
    {
        $actor = $this->support->user($request);
        $data = $request->validate(['email' => ['nullable', 'string', 'max:190'], 'q' => ['nullable', 'string', 'max:100']]);
        $query = $this->support->scoped(Candidate::query()->whereNull('anonymized_at'), $actor, TalentPermissions::CANDIDATES_VIEW);

        if (($data['email'] ?? '') !== '') {
            $query->where('email', CandidateManager::normalizeEmail((string) $data['email']));
        } elseif (($data['q'] ?? '') !== '') {
            $term = Like::contains((string) $data['q']);
            $query->where(fn ($match) => $match->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('email', 'like', $term));
        } else {
            return response()->json(['matches' => []]);
        }

        return response()->json(['matches' => $query->orderBy('first_name')->limit(10)->get()
            ->map(static fn (Candidate $candidate): array => ['id' => $candidate->id, 'name' => $candidate->fullName(), 'email' => $candidate->email])->all()]);
    }

    public function store(CandidateRequest $request): RedirectResponse
    {
        $actor = $this->support->user($request);
        $data = $request->validated();
        $candidate = $this->candidates->create($data, $this->support->membership($actor), (bool) ($data['confirm_duplicate'] ?? false));
        Toast::success(__('Candidate added.'));

        return to_route('talent.candidates.show', $candidate);
    }

    public function show(Request $request, Candidate $candidate): InertiaResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::CANDIDATES_VIEW, $candidate);

        $applications = $this->support->can($actor, TalentPermissions::APPLICATIONS_VIEW)
            ? $this->support->scoped(Application::query(), $actor, TalentPermissions::APPLICATIONS_VIEW)
                ->where('candidate_id', $candidate->id)->with(['candidate', 'opening', 'stage'])->orderByDesc('applied_on')->get()
                ->map(static fn (Application $application): array => TalentPresenter::application($application, true))->all()
            : [];

        $manage = $this->support->allows($actor, TalentPermissions::CANDIDATES_MANAGE, $candidate);
        $duplicates = $candidate->email === null ? [] : $this->support->scoped(
            Candidate::query()->whereKeyNot($candidate->id)->where('email', $candidate->email)->whereNull('anonymized_at'),
            $actor,
            TalentPermissions::CANDIDATES_VIEW,
        )->get()->map(static fn (Candidate $other): array => ['id' => $other->id, 'name' => $other->fullName(), 'email' => $other->email])->all();

        return Inertia::render('Modules/Talent/Pages/Candidates/Show', [
            'candidate' => TalentPresenter::candidate($candidate, true),
            'applications' => $applications,
            'documents' => $candidate->documents()->orderByDesc('created_at')->get()->map(static fn (CandidateDocument $document): array => TalentPresenter::document($document))->all(),
            'notes' => Note::query()->where('candidate_id', $candidate->id)->orderByDesc('created_at')->limit(100)->get()
                ->map(static fn (Note $note): array => TalentPresenter::note($note))->all(),
            'duplicates' => $duplicates,
            'consentBases' => ConsentBasis::values(),
            'documentTypes' => DocumentType::values(),
            'abilities' => [
                'edit' => $manage && ! $candidate->isAnonymized(),
                'anonymize' => $manage && ! $candidate->isAnonymized(),
                'addNote' => $manage && ! $candidate->isAnonymized(),
                'uploadDocument' => $manage && ! $candidate->isAnonymized(),
            ],
        ]);
    }

    public function update(CandidateRequest $request, Candidate $candidate): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::CANDIDATES_MANAGE, $candidate);
        $data = $request->validated();
        $this->candidates->update($candidate, $data, (bool) ($data['confirm_duplicate'] ?? false));
        Toast::success(__('Candidate saved.'));

        return back();
    }

    public function anonymize(Request $request, Candidate $candidate): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::CANDIDATES_MANAGE, $candidate);
        $this->candidates->anonymize($candidate, 'request', $this->support->membership($actor));
        Toast::success(__('Candidate data erased.'));

        return back();
    }

    public function storeNote(NoteRequest $request, Candidate $candidate): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::CANDIDATES_MANAGE, $candidate);
        $applicationId = $request->validated('application_id');
        $application = $applicationId === null ? null : Application::query()->where('candidate_id', $candidate->id)->findOrFail($applicationId);
        $this->candidates->addNote($candidate, $application, $request->validated('body'), $this->support->membership($actor));
        Toast::success(__('Note added.'));

        return back();
    }

    public function storeDocument(CandidateDocumentRequest $request, Candidate $candidate): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::CANDIDATES_MANAGE, $candidate);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        /** @var array{type: string, title: string, application_id?: string|null} $attributes */
        $attributes = $request->safe()->only(['type', 'title', 'application_id']);
        $this->candidates->addDocument($candidate, $file, $attributes, $this->support->membership($actor));
        Toast::success(__('Document uploaded.'));

        return back();
    }

    /** Private files: an attachment with `nosniff`, only to people who may see the candidate. */
    public function downloadDocument(Request $request, Candidate $candidate, CandidateDocument $document): Response
    {
        $actor = $this->support->user($request);
        abort_unless($document->candidate_id === $candidate->id, 404);
        $this->support->authorize($actor, TalentPermissions::CANDIDATES_VIEW, $candidate);

        $contents = $this->candidates->documentContents($document);
        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'attachment; filename="'.addcslashes($document->original_name, '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroyDocument(Request $request, Candidate $candidate, CandidateDocument $document): RedirectResponse
    {
        $actor = $this->support->user($request);
        abort_unless($document->candidate_id === $candidate->id, 404);
        $this->support->authorize($actor, TalentPermissions::CANDIDATES_MANAGE, $candidate);
        $this->candidates->deleteDocument($document);
        Toast::success(__('Document removed.'));

        return back();
    }
}
