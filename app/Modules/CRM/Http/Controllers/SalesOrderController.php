<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\CRM\Actions\SalesOrderManager;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class SalesOrderController
{
    public const string VIEW = 'crm.orders.view';

    public const string MANAGE = 'crm.orders.manage';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly SalesOrderManager $orders,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(SalesOrderStatus::class)],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $orders = $this->records->constrain(SalesOrder::query(), $actor, self::VIEW)
            ->with(['contact:id,first_name,last_name', 'company:id,name', 'quote:id,number', 'owner.user:id,name'])
            ->when($search !== '', fn ($query) => $query->where('number', 'like', Like::contains($search)))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->latest('ordered_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SalesOrder $order): array => $this->summary($order, $actor));

        return Inertia::render('Modules/CRM/Pages/Sales/Orders/Index', [
            'orders' => $orders,
            'filters' => ['search' => $search, 'status' => $filters['status'] ?? null],
            'statuses' => array_map(static fn (SalesOrderStatus $status): string => $status->value, SalesOrderStatus::cases()),
        ]);
    }

    public function show(Request $request, SalesOrder $salesOrder): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $this->records->authorize($actor, self::VIEW, $salesOrder);
        $salesOrder->load(['lines', 'contact:id,first_name,last_name,email', 'company:id,name,email', 'quote:id,number', 'owner.user:id,name']);

        return Inertia::render('Modules/CRM/Pages/Sales/Orders/Show', [
            'order' => [
                ...$this->summary($salesOrder, $actor),
                'terms' => $salesOrder->terms,
                'notes' => $salesOrder->notes,
                'fulfilledAt' => $salesOrder->fulfilled_at?->toIso8601String(),
                'canceledAt' => $salesOrder->canceled_at?->toIso8601String(),
                'lines' => $salesOrder->lines->map(fn (SalesOrderLine $line): array => [
                    'id' => $line->id,
                    'quoteLineId' => $line->quote_line_id,
                    'productId' => $line->product_id,
                    'position' => $line->position,
                    'itemType' => $line->item_type->value,
                    'sku' => $line->sku,
                    'name' => $line->name,
                    'unit' => $line->unit,
                    'description' => $line->description,
                    'quantityMilli' => $line->quantity_milli,
                    'currency' => $line->currency,
                    'unitPriceAmount' => $line->unit_price_amount,
                    'discountAmount' => $line->discount_amount,
                    'taxBps' => $line->tax_bps,
                    'taxAmount' => $line->tax_amount,
                    'lineTotalAmount' => $line->line_total_amount,
                ])->all(),
            ],
        ]);
    }

    public function updateStatus(Request $request, SalesOrder $salesOrder): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $this->records->authorize($actor, self::MANAGE, $salesOrder);
        $input = $request->validate(['status' => ['required', Rule::enum(SalesOrderStatus::class)]]);
        $this->orders->transition($salesOrder, SalesOrderStatus::from((string) $input['status']));
        Toast::success(__('Sales-order status saved.'));

        return back();
    }

    /** @return array<string, mixed> */
    private function summary(SalesOrder $order, User $actor): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'currency' => $order->currency,
            'subtotalAmount' => $order->subtotal_amount,
            'discountAmount' => $order->discount_amount,
            'taxAmount' => $order->tax_amount,
            'totalAmount' => $order->total_amount,
            'quote' => $order->quote?->only(['id', 'number']),
            'contact' => $order->contact === null ? null : ['id' => $order->contact->id, 'name' => $order->contact->fullName()],
            'company' => $order->company?->only(['id', 'name']),
            'owner' => $order->owner === null ? null : ['id' => $order->owner->id, 'name' => $order->owner->user->name],
            'orderedAt' => $order->ordered_at->toIso8601String(),
            'editable' => $order->status === SalesOrderStatus::Confirmed && $this->records->allows($actor, self::MANAGE, $order),
        ];
    }
}
