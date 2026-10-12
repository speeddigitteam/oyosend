<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Automation\Engine\ApprovalManager;
use App\Modules\Automation\Enums\ApprovalStatus;
use App\Modules\Automation\Http\Presenters\Presenter;
use App\Modules\Automation\Http\Requests\DecideApprovalRequest;
use App\Modules\Automation\Models\Approval;
use App\Modules\Automation\Models\Workflow;
use App\Modules\Automation\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The approval inbox: what waits for a decision, and what was decided. */
final class ApprovalController
{
    public function __construct(private readonly Actor $actor, private readonly ApprovalManager $approvals) {}

    public function index(Request $request): Response
    {
        $membership = $this->actor->membership($request);

        $pending = Approval::query()
            ->where('status', ApprovalStatus::Pending->value)
            ->orderBy('expires_at')
            ->limit(200)
            ->get()
            // Only what this member may actually decide: the same rule decide() enforces.
            ->filter(fn (Approval $approval): bool => $this->approvals->refusal($approval, $membership) === null)
            ->values();

        $history = Approval::query()
            ->where('status', '!=', ApprovalStatus::Pending->value)
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $all = $pending->concat($history);
        $names = Presenter::names([
            ...$all->pluck('approver_membership_id')->all(),
            ...$all->pluck('requested_by_membership_id')->all(),
            ...$all->pluck('decided_by_membership_id')->all(),
        ]);
        $workflows = Workflow::query()->whereIn('id', $all->pluck('workflow_id')->unique()->all())->pluck('name', 'id');

        $present = static fn (Approval $approval): array => Presenter::approval($approval, $names, $workflows[$approval->workflow_id] ?? null);

        return Inertia::render('Modules/Automation/Pages/Approvals/Index', [
            'pending' => $pending->map($present)->all(),
            'history' => $history->map($present)->all(),
        ]);
    }

    public function decide(DecideApprovalRequest $request, Approval $approval): RedirectResponse
    {
        $approve = $request->validated('decision') === 'approve';
        $this->approvals->decide($approval, $this->actor->membership($request), $approve, $request->validated('comment'));

        Toast::success($approve ? __('Approved.') : __('Rejected.'));

        return back();
    }
}
