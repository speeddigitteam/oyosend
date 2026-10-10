<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\CRM\Actions\ContractManager;
use App\Modules\CRM\Enums\ContractStatus;
use App\Modules\CRM\Models\Contract;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ContractController
{
    public const string VIEW = 'crm.contracts.view';

    public const string MANAGE = 'crm.contracts.manage';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly ContractManager $contracts,
        private readonly CrmControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ContractStatus::class)],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $contracts = $this->records->constrain(Contract::query(), $actor, self::VIEW)
            ->with(['contact:id,first_name,last_name', 'company:id,name', 'salesOrder:id,number', 'owner.user:id,name'])
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('number', 'like', Like::contains($search))
                ->orWhere('title', 'like', Like::contains($search))))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('starts_on')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Contract $contract): array => [
                'id' => $contract->id,
                'number' => $contract->number,
                'title' => $contract->title,
                'status' => $contract->status->value,
                'currency' => $contract->currency,
                'valueAmount' => $contract->value_amount,
                'startsOn' => $contract->starts_on->toDateString(),
                'endsOn' => $contract->ends_on?->toDateString(),
                'renewsOn' => $contract->renews_on?->toDateString(),
                'signedAt' => $contract->signed_at?->toIso8601String(),
                'terminatedAt' => $contract->terminated_at?->toIso8601String(),
                'terms' => $contract->terms,
                'salesOrder' => $contract->salesOrder?->only(['id', 'number']),
                'contact' => $contract->contact === null ? null : ['id' => $contract->contact->id, 'name' => $contract->contact->fullName()],
                'company' => $contract->company?->only(['id', 'name']),
                'owner' => $contract->owner === null ? null : ['id' => $contract->owner->id, 'name' => $contract->owner->user->name],
                'editable' => $contract->status === ContractStatus::Draft && $this->records->allows($actor, self::MANAGE, $contract),
                'statusable' => in_array($contract->status, [ContractStatus::Draft, ContractStatus::Active], true)
                    && $this->records->allows($actor, self::MANAGE, $contract),
            ]);

        return Inertia::render('Modules/CRM/Pages/Sales/Contracts/Index', [
            'contracts' => $contracts,
            'filters' => ['search' => $search, 'status' => $filters['status'] ?? null],
            'statuses' => array_map(static fn (ContractStatus $status): string => $status->value, ContractStatus::cases()),
            'form' => $this->formOptions($actor),
            'canCreate' => $actor->can(self::MANAGE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $contract = $this->contracts->create(
            $this->validated($request, $actor),
            $this->support->membership($actor),
        );
        Toast::success(__('Contract ":number" created.', ['number' => $contract->number]));

        return back();
    }

    public function update(Request $request, Contract $contract): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $this->records->authorize($actor, self::MANAGE, $contract);
        $this->contracts->update($contract, $this->validated($request, $actor));
        Toast::success(__('Contract saved.'));

        return back();
    }

    public function updateStatus(Request $request, Contract $contract): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $this->records->authorize($actor, self::MANAGE, $contract);
        $input = $request->validate(['status' => ['required', Rule::enum(ContractStatus::class)]]);
        $this->contracts->transition($contract, ContractStatus::from((string) $input['status']));
        Toast::success(__('Contract status saved.'));

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, User $actor): array
    {
        $input = $request->validate([
            'sales_order_id' => ['nullable', 'string', 'size:26'],
            'contact_id' => ['nullable', 'string', 'size:26', 'required_without_all:company_id,sales_order_id'],
            'company_id' => ['nullable', 'string', 'size:26', 'required_without_all:contact_id,sales_order_id'],
            'title' => ['required', 'string', 'max:200'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/', 'required_without:sales_order_id'],
            'value_amount' => ['nullable', 'integer', 'min:0', 'max:1000000000000000', 'required_without:sales_order_id'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'renews_on' => ['nullable', 'date_format:Y-m-d'],
            'terms' => ['nullable', 'string', 'max:20000'],
        ]);

        if (($input['sales_order_id'] ?? null) !== null) {
            $input['sales_order_id'] = $this->records->constrain(SalesOrder::query(), $actor, SalesOrderController::VIEW)
                ->findOrFail($input['sales_order_id'])->id;
        }

        if (($input['contact_id'] ?? null) !== null) {
            $input['contact_id'] = $this->records->constrain(Contact::query(), $actor, 'core.contacts.view')
                ->findOrFail($input['contact_id'])->id;
        }

        if (($input['company_id'] ?? null) !== null) {
            $input['company_id'] = $this->records->constrain(Company::query(), $actor, 'core.contacts.view')
                ->findOrFail($input['company_id'])->id;
        }

        if (isset($input['currency'])) {
            $input['currency'] = strtoupper((string) $input['currency']);
        }

        return $input;
    }

    /** @return array<string, mixed> */
    private function formOptions(User $actor): array
    {
        return [
            'orders' => $this->records->constrain(SalesOrder::query(), $actor, SalesOrderController::VIEW)
                ->latest('ordered_at')->limit(500)->get(['id', 'number', 'contact_id', 'company_id', 'currency', 'total_amount'])
                ->map(fn (SalesOrder $order): array => [
                    'id' => $order->id,
                    'number' => $order->number,
                    'contactId' => $order->contact_id,
                    'companyId' => $order->company_id,
                    'currency' => $order->currency,
                    'totalAmount' => $order->total_amount,
                ])->all(),
            'contacts' => $this->records->constrain(Contact::query(), $actor, 'core.contacts.view')
                ->orderBy('first_name')->limit(500)->get(['id', 'first_name', 'last_name'])
                ->map(fn (Contact $contact): array => ['id' => $contact->id, 'name' => $contact->fullName()])->all(),
            'companies' => $this->records->constrain(Company::query(), $actor, 'core.contacts.view')
                ->orderBy('name')->limit(500)->get(['id', 'name'])
                ->map(fn (Company $company): array => $company->only(['id', 'name']))->all(),
        ];
    }
}
