<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use App\Modules\CRM\Actions\SalesAnalytics;
use App\Modules\CRM\Actions\SalesMath;
use App\Modules\CRM\Actions\SalesTargetManager;
use App\Modules\CRM\Models\SalesTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class SalesTargetController
{
    public const string VIEW = 'crm.targets.view';

    public const string MANAGE = 'crm.targets.manage';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly SalesTargetManager $targets,
        private readonly SalesAnalytics $analytics,
        private readonly CrmControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $targets = $this->records->constrain(SalesTarget::query(), $actor, self::VIEW)
            ->with('owner.user:id,name')
            ->orderByDesc('period_start')
            ->paginate(25)
            ->through(function (SalesTarget $target) use ($actor): array {
                $actual = $this->analytics->targetActual($target);

                return [
                    'id' => $target->id,
                    'name' => $target->name,
                    'currency' => $target->currency,
                    'targetAmount' => $target->target_amount,
                    'actualAmount' => $actual,
                    'attainmentBps' => SalesMath::ratioBps($actual, $target->target_amount),
                    'periodStart' => $target->period_start->toDateString(),
                    'periodEnd' => $target->period_end->toDateString(),
                    'owner' => $target->owner === null ? null : ['id' => $target->owner->id, 'name' => $target->owner->user->name],
                    'editable' => $this->records->allows($actor, self::MANAGE, $target),
                ];
            });

        return Inertia::render('Modules/CRM/Pages/Sales/Targets/Index', [
            'targets' => $targets,
            'canCreate' => $actor->can(self::MANAGE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $input = $this->validated($request);
        $membership = $this->support->membership($actor);
        $this->guardScope($actor, $input, new SalesTarget, $membership);
        $this->targets->create($input, $membership);
        Toast::success(__('Sales target created.'));

        return back();
    }

    public function update(Request $request, SalesTarget $salesTarget): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $this->records->authorize($actor, self::MANAGE, $salesTarget);
        $input = $this->validated($request);
        $this->guardScope($actor, $input, $salesTarget->replicate(), null);
        $this->targets->update($salesTarget, $input);
        Toast::success(__('Sales target saved.'));

        return back();
    }

    /**
     * A target may only be pointed at a person, team, branch or the whole
     * company by someone whose `crm.targets.manage` scope reaches that record.
     *
     * @param  array<string, mixed>  $input
     */
    private function guardScope(User $actor, array $input, SalesTarget $probe, ?TenantMembership $creator): void
    {
        if (! isset($input['scope'])) {
            return;
        }

        $this->targets->applyScope($probe, $creator, [
            'scope' => (string) $input['scope'],
            'owner_id' => is_string($input['owner_id'] ?? null) ? $input['owner_id'] : null,
            'department_id' => is_string($input['department_id'] ?? null) ? $input['department_id'] : null,
            'branch_id' => is_string($input['branch_id'] ?? null) ? $input['branch_id'] : null,
        ]);
        abort_unless($this->records->allows($actor, self::MANAGE, $probe), 403);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'target_amount' => ['required', 'integer', 'min:0', 'max:1000000000000000'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d'],
            'scope' => ['sometimes', 'string', Rule::in(['owner', 'department', 'branch', 'company'])],
            'owner_id' => ['nullable', 'string', 'size:26'],
            'department_id' => ['nullable', 'string', 'size:26'],
            'branch_id' => ['nullable', 'string', 'size:26'],
        ]);
        $input['currency'] = strtoupper((string) $input['currency']);

        return $input;
    }
}
