<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Support\Toast;
use App\Modules\Payroll\Actions\ItemManager;
use App\Modules\Payroll\Enums\ItemStatus;
use App\Modules\Payroll\Enums\ItemType;
use App\Modules\Payroll\Enums\PeriodStatus;
use App\Modules\Payroll\Http\Presenters\PayrollPresenter;
use App\Modules\Payroll\Http\Requests\ItemRequest;
use App\Modules\Payroll\Models\Item;
use App\Modules\Payroll\Models\Period;
use App\Modules\Payroll\Support\PayrollPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** One-off pay items: allowances, bonuses, deductions, reimbursements. */
final class ItemController
{
    public function __construct(
        private readonly ItemManager $items,
        private readonly AccessChecker $access,
        private readonly PayrollControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'period_id' => ['nullable', 'string', 'size:26'],
            'employee_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', 'string', 'in:'.implode(',', ItemStatus::values())],
            'type' => ['nullable', 'string', 'in:'.implode(',', ItemType::values())],
        ]);

        $query = $this->support->records()->constrain(Item::query(), $actor, PayrollPermissions::ITEMS_VIEW)
            ->with('period:id,name')
            ->when(($filters['period_id'] ?? '') !== '', fn ($inner) => $inner->where('period_id', $filters['period_id']))
            ->when(($filters['employee_id'] ?? '') !== '', fn ($inner) => $inner->where('employee_id', $filters['employee_id']))
            ->when(($filters['status'] ?? '') !== '', fn ($inner) => $inner->where('status', $filters['status']))
            ->when(($filters['type'] ?? '') !== '', fn ($inner) => $inner->where('type', $filters['type']))
            ->orderByDesc('created_at')->orderByDesc('id');

        $page = $query->paginate(25)->withQueryString();
        $employees = $this->support->employees($page->getCollection()->pluck('employee_id')->all());
        $canManage = $this->access->allows($actor, PayrollPermissions::ITEMS_MANAGE);

        return Inertia::render('Modules/Payroll/Pages/Items/Index', [
            'items' => $page->through(static fn (Item $item): array => [
                ...PayrollPresenter::item($item),
                'employee' => PayrollPresenter::employee($employees[$item->employee_id] ?? null),
            ]),
            'filters' => [
                'period_id' => $filters['period_id'] ?? '',
                'employee_id' => $filters['employee_id'] ?? '',
                'status' => $filters['status'] ?? '',
                'type' => $filters['type'] ?? '',
            ],
            'periods' => Period::query()->where('status', '!=', PeriodStatus::Closed)->orderByDesc('starts_on')->get()
                ->map(static fn (Period $period): array => PayrollPresenter::period($period))->all(),
            'employees' => $canManage ? $this->support->employeeOptions($actor, PayrollPermissions::ITEMS_MANAGE) : [],
            'types' => ItemType::values(),
            'statuses' => ItemStatus::values(),
            'currency' => $this->support->currency(),
            'canManage' => $canManage,
        ]);
    }

    public function store(ItemRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validated();

        if (! isset($input['employee_id'], $input['period_id'])) {
            throw ValidationException::withMessages(['employee_id' => __('Choose an employee and a pay period.')]);
        }

        $employee = $this->support->employee($actor, (string) $input['employee_id'], PayrollPermissions::ITEMS_MANAGE);
        $period = Period::query()->findOrFail((string) $input['period_id']);
        $this->items->create($employee, $period, $input, $this->support->membership($actor));
        Toast::success(__('Pay item added.'));

        return back();
    }

    public function update(ItemRequest $request, Item $item): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->support->records()->authorize($actor, PayrollPermissions::ITEMS_MANAGE, $item);
        $this->items->update($item, $request->validated());
        Toast::success(__('Pay item saved.'));

        return back();
    }

    public function destroy(Request $request, Item $item): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->support->records()->authorize($actor, PayrollPermissions::ITEMS_MANAGE, $item);
        $this->items->cancel($item);
        Toast::success(__('Pay item cancelled.'));

        return back();
    }
}
