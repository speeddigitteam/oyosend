<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Talent\Actions\EmployeeLookup;
use App\Modules\Talent\Actions\TrainingManager;
use App\Modules\Talent\Enums\EnrolmentStatus;
use App\Modules\Talent\Http\Presenters\TalentPresenter;
use App\Modules\Talent\Http\Requests\CompleteEnrolmentRequest;
use App\Modules\Talent\Http\Requests\EnrolmentRequest;
use App\Modules\Talent\Models\Course;
use App\Modules\Talent\Models\Enrolment;
use App\Modules\Talent\Models\TrainingSession;
use App\Modules\Talent\Support\TalentPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Course enrolments and completions. Employees enrol themselves through the `own` scope. */
final class EnrolmentController
{
    public function __construct(
        private readonly TalentSupport $support,
        private readonly TrainingManager $training,
        private readonly EmployeeLookup $employees,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->user($request);
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:enrolled,completed,failed,cancelled,all'],
            'course_id' => ['nullable', 'string', 'size:26'],
            'employee_id' => ['nullable', 'string', 'size:26'],
            'expiring' => ['nullable', 'boolean'],
        ]);
        $status = $filters['status'] ?? 'all';
        $today = $this->support->today();

        $query = $this->support->scoped(Enrolment::query(), $actor, TalentPermissions::ENROLMENTS_VIEW)->with('course');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        foreach (['course_id', 'employee_id'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $query->where($column, $filters[$column]);
            }
        }

        if ($filters['expiring'] ?? false) {
            $query->where('status', EnrolmentStatus::Completed)->whereNotNull('expires_on')
                ->where('expires_on', '<=', $today->addDays(90)->toDateString());
        }

        $page = $query->orderByDesc('created_at')->paginate(25)->withQueryString();
        $people = $this->support->people($page->getCollection()->pluck('employee_id')->all());
        $page->through(static fn (Enrolment $enrolment): array => TalentPresenter::enrolment($enrolment, $people));

        return Inertia::render('Modules/Talent/Pages/Enrolments/Index', [
            'enrolments' => $page,
            'filters' => [
                'status' => $status, 'course_id' => $filters['course_id'] ?? '', 'employee_id' => $filters['employee_id'] ?? '',
                'expiring' => (bool) ($filters['expiring'] ?? false),
            ],
            'courses' => Course::query()->where('is_active', true)->orderBy('title')->get(['id', 'title'])
                ->map(static fn (Course $course): array => ['id' => $course->id, 'title' => $course->title])->all(),
            'employees' => $this->support->can($actor, TalentPermissions::ENROLMENTS_MANAGE) ? $this->support->employeeOptions($actor, TalentPermissions::ENROLMENTS_MANAGE) : [],
            'ownEmployeeId' => $this->support->employeeId($actor),
            'today' => $today->toDateString(),
            'canManage' => $this->support->can($actor, TalentPermissions::ENROLMENTS_MANAGE),
        ]);
    }

    public function store(EnrolmentRequest $request): RedirectResponse
    {
        $actor = $this->support->user($request);
        $data = $request->validated();
        $employeeId = $data['employee_id'] ?? $this->support->employeeId($actor);

        if ($employeeId === null) {
            abort(422, __('You have no employee record to enrol.'));
        }

        $employee = $this->employees->require($employeeId, 'employee_id');
        $probe = new Enrolment;
        $probe->placeForEmployee($employee);
        $this->support->authorize($actor, TalentPermissions::ENROLMENTS_MANAGE, $probe);
        $course = Course::query()->findOrFail($data['course_id']);
        $sessionId = $data['session_id'] ?? null;
        $session = $sessionId === null ? null : TrainingSession::query()->findOrFail($sessionId);
        $this->training->enrol($course, $employee, $session, $this->support->membership($actor));
        Toast::success(__('Enrolled.'));

        return back();
    }

    public function complete(CompleteEnrolmentRequest $request, Enrolment $enrolment): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::ENROLMENTS_MANAGE, $enrolment);
        abort_if($this->support->isOwnRecordBlocked($actor, TalentPermissions::ENROLMENTS_MANAGE, $enrolment->employee_id), 403);
        /** @var array{completed_on?: string|null, score?: int|string|null, notes?: string|null} $data */
        $data = $request->validated();
        $this->training->complete($enrolment, $data, $this->support->membership($actor));
        Toast::success(__('Training completed.'));

        return back();
    }

    public function fail(Request $request, Enrolment $enrolment): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::ENROLMENTS_MANAGE, $enrolment);
        abort_if($this->support->isOwnRecordBlocked($actor, TalentPermissions::ENROLMENTS_MANAGE, $enrolment->employee_id), 403);
        $this->training->close($enrolment, EnrolmentStatus::Failed);
        Toast::success(__('Enrolment marked as failed.'));

        return back();
    }

    public function cancel(Request $request, Enrolment $enrolment): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::ENROLMENTS_MANAGE, $enrolment);
        abort_if($this->support->isOwnRecordBlocked($actor, TalentPermissions::ENROLMENTS_MANAGE, $enrolment->employee_id), 403);
        $this->training->close($enrolment, EnrolmentStatus::Cancelled);
        Toast::success(__('Enrolment cancelled.'));

        return back();
    }
}
