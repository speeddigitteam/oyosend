<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Support\Toast;
use App\Modules\Hrm\Actions\EmployeeResolver;
use App\Modules\Hrm\Actions\RegularizationManager;
use App\Modules\Hrm\Enums\RegularizationStatus;
use App\Modules\Hrm\Http\Presenters\HrmPresenter;
use App\Modules\Hrm\Http\Requests\CorrectionRequest;
use App\Modules\Hrm\Http\Requests\DecisionRequest;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Regularization;
use App\Modules\Hrm\Support\HrmPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Attendance correction (regularization) requests and their approval. */
final class CorrectionController
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
        private readonly EmployeeResolver $resolver,
        private readonly RegularizationManager $corrections,
        private readonly HrmControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $status = $request->validate(['status' => ['nullable', 'string', 'in:pending,approved,rejected,cancelled,all']])['status'] ?? 'pending';

        $scoped = $this->records->constrain(Regularization::query(), $actor, HrmPermissions::ATTENDANCE_VIEW)->select('id');
        $own = $this->support->ownEmployee($actor);
        $reportIds = $own !== null && $this->access->allows($actor, HrmPermissions::ATTENDANCE_APPROVE)
            ? Employee::query()->where('manager_id', $own->id)->pluck('id')->all()
            : [];

        $query = Regularization::query()
            ->with('employee:id,employee_number,first_name,last_name,manager_id,owner_id')
            ->where(function ($match) use ($scoped, $reportIds): void {
                $match->whereIn('id', $scoped);

                if ($reportIds !== []) {
                    $match->orWhereIn('employee_id', $reportIds);
                }
            });

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $page = $query->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")->orderByDesc('work_date')->paginate(25)->withQueryString()
            ->through(function (Regularization $correction) use ($actor): array {
                $isSelf = $this->resolver->isSelf($actor, $correction->employee);

                return [
                    ...HrmPresenter::correction($correction),
                    'abilities' => [
                        'decide' => $correction->status === RegularizationStatus::Pending && $this->corrections->mayDecide($actor, $correction),
                        'cancel' => $correction->status === RegularizationStatus::Pending
                            && ($isSelf || $this->records->allows($actor, HrmPermissions::ATTENDANCE_MANAGE, $correction)),
                    ],
                ];
            });

        return Inertia::render('Modules/Hrm/Pages/Attendance/Corrections', [
            'corrections' => $page,
            'status' => $status,
            'canRequest' => $own !== null && $this->access->allows($actor, HrmPermissions::ATTENDANCE_REGULARIZE),
            'windowDays' => RegularizationManager::WINDOW_DAYS,
            'today' => $this->support->today()->toDateString(),
        ]);
    }

    public function store(CorrectionRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validated();
        $own = $this->support->ownEmployee($actor);
        $employee = $own;

        if (($input['employee_id'] ?? null) !== null && $input['employee_id'] !== $own?->id) {
            // On behalf of someone else: only HR with attendance management over that person.
            $employee = Employee::query()->findOrFail($input['employee_id']);
            $this->records->authorize($actor, HrmPermissions::ATTENDANCE_MANAGE, $employee);
        }

        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => __('Your account is not linked to an employee record.')]);
        }

        $this->corrections->request($employee, $input['work_date'], $input['clock_in_at'], $input['clock_out_at'], $input['reason'], $this->support->membership($actor));
        Toast::success(__('Correction requested.'));

        return back();
    }

    public function decision(DecisionRequest $request, Regularization $correction): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $correction->load('employee');
        $input = $request->validated();
        $this->corrections->decide($correction, $actor, $input['decision'] === 'approve', $input['note'] ?? null, $this->support->membership($actor));
        Toast::success($input['decision'] === 'approve' ? __('Correction approved.') : __('Correction rejected.'));

        return back();
    }

    public function cancel(Request $request, Regularization $correction): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $correction->load('employee');
        $this->corrections->cancel($correction, $actor);
        Toast::success(__('Correction cancelled.'));

        return back();
    }
}
