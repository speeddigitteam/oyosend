<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Talent\Actions\TrainingManager;
use App\Modules\Talent\Enums\EnrolmentStatus;
use App\Modules\Talent\Enums\SessionStatus;
use App\Modules\Talent\Http\Presenters\TalentPresenter;
use App\Modules\Talent\Http\Requests\CourseRequest;
use App\Modules\Talent\Http\Requests\SessionRequest;
use App\Modules\Talent\Models\Course;
use App\Modules\Talent\Models\Enrolment;
use App\Modules\Talent\Models\TrainingSession;
use App\Modules\Talent\Support\TalentPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The course catalogue and its sessions. */
final class CourseController
{
    public function __construct(
        private readonly TalentSupport $support,
        private readonly TrainingManager $training,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->user($request);
        $filters = $request->validate(['active' => ['nullable', 'string', 'in:1,0,all']]);
        $active = $filters['active'] ?? '1';
        $query = Course::query()->withCount([
            'sessions as upcoming_sessions_count' => fn ($sessions) => $sessions->where('status', SessionStatus::Scheduled)->where('starts_at', '>=', now()),
        ]);

        if ($active !== 'all') {
            $query->where('is_active', $active === '1');
        }

        $page = $query->orderBy('title')->paginate(25)->withQueryString()
            ->through(static fn (Course $course): array => TalentPresenter::course($course) + ['upcomingSessions' => (int) $course->getAttribute('upcoming_sessions_count')]);

        return Inertia::render('Modules/Talent/Pages/Courses/Index', [
            'courses' => $page,
            'filters' => ['active' => $active],
            'canManage' => $this->support->can($actor, TalentPermissions::COURSES_MANAGE),
        ]);
    }

    public function show(Request $request, Course $course): Response
    {
        $actor = $this->support->user($request);
        $timezone = $this->support->timezone();
        $course->load('sessions');
        $counts = Enrolment::query()->where('course_id', $course->id)->whereIn('status', [EnrolmentStatus::Enrolled, EnrolmentStatus::Completed])
            ->whereNotNull('session_id')->selectRaw('session_id, count(*) as total')->groupBy('session_id')->pluck('total', 'session_id');

        $enrolments = $this->support->can($actor, TalentPermissions::ENROLMENTS_VIEW)
            ? $this->support->scoped(Enrolment::query(), $actor, TalentPermissions::ENROLMENTS_VIEW)->where('course_id', $course->id)->with('course')
                ->orderByDesc('created_at')->limit(200)->get()
            : collect();
        $people = $this->support->people($enrolments->pluck('employee_id')->all());

        return Inertia::render('Modules/Talent/Pages/Courses/Show', [
            'course' => TalentPresenter::course($course),
            'sessions' => $course->sessions->map(static fn (TrainingSession $session): array => TalentPresenter::session($session, $timezone, (int) ($counts[$session->id] ?? 0)))->values()->all(),
            'enrolments' => $enrolments->map(static fn (Enrolment $enrolment): array => TalentPresenter::enrolment($enrolment, $people))->values()->all(),
            'sessionStatuses' => SessionStatus::values(),
            'timezone' => $timezone,
            'abilities' => [
                'manage' => $this->support->can($actor, TalentPermissions::COURSES_MANAGE),
                'enrol' => $course->is_active && $this->support->can($actor, TalentPermissions::ENROLMENTS_MANAGE),
            ],
            'employees' => $this->support->can($actor, TalentPermissions::ENROLMENTS_MANAGE) ? $this->support->employeeOptions($actor, TalentPermissions::ENROLMENTS_MANAGE) : [],
        ]);
    }

    public function store(CourseRequest $request): RedirectResponse
    {
        $this->support->user($request);
        $course = $this->training->saveCourse(null, $request->validated());
        Toast::success(__('Course created.'));

        return to_route('talent.courses.show', $course);
    }

    public function update(CourseRequest $request, Course $course): RedirectResponse
    {
        $this->support->user($request);
        $this->training->saveCourse($course, $request->validated());
        Toast::success(__('Course saved.'));

        return back();
    }

    public function storeSession(SessionRequest $request, Course $course): RedirectResponse
    {
        $this->support->user($request);
        /** @var array{date: string, time: string, duration_minutes?: int|string|null, location?: string|null, trainer?: string|null, capacity?: int|string|null, status?: string|null} $data */
        $data = $request->validated();
        $this->training->saveSession($course, null, $data);
        Toast::success(__('Session scheduled.'));

        return back();
    }

    public function updateSession(SessionRequest $request, TrainingSession $session): RedirectResponse
    {
        $this->support->user($request);
        $course = Course::query()->findOrFail($session->course_id);
        /** @var array{date: string, time: string, duration_minutes?: int|string|null, location?: string|null, trainer?: string|null, capacity?: int|string|null, status?: string|null} $data */
        $data = $request->validated();
        $this->training->saveSession($course, $session, $data);
        Toast::success(__('Session saved.'));

        return back();
    }
}
