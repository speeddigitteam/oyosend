<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Models\Invoice;
use App\Core\Billing\Models\Payment;
use App\Core\Billing\PaymentProcessor;
use App\Core\Billing\Support\BillingPresenter;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Platform\Models\PlatformUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every invoice the platform has issued, across workspaces - and the two
 * things an operator does to one: record a payment that arrived outside a
 * gateway, or withdraw it.
 */
final class InvoiceController
{
    /** How money can arrive outside a gateway. */
    public const array OFFLINE_METHODS = ['bank_transfer', 'cash', 'cheque', 'mobile_banking', 'other'];

    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        // Deliberate cross-tenant read: the platform's own receivables.
        $invoices = Invoice::withoutTenancy()
            ->with('tenant:id,name,slug')
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('number', 'like', Like::contains($search))
                ->orWhereHas('tenant', fn ($tenant) => $tenant
                    ->where('name', 'like', Like::contains($search))
                    ->orWhere('slug', 'like', Like::contains($search)))))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('issued_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Invoice $invoice): array => [
                ...BillingPresenter::invoice($invoice),
                'workspace' => $invoice->tenant === null ? null : [
                    'id' => $invoice->tenant->id,
                    'name' => $invoice->tenant->name,
                    'slug' => $invoice->tenant->slug,
                ],
            ]);

        return Inertia::render('Platform/Pages/Billing/Invoices', [
            'invoices' => $invoices,
            'filters' => ['search' => $search, 'status' => $filters['status'] ?? ''],
            'totals' => $this->totals(),
            'offlineMethods' => self::OFFLINE_METHODS,
            'canManage' => $request->user('platform')?->can('platform.billing.manage') === true,
        ]);
    }

    public function show(Request $request, string $invoice): Response
    {
        $invoice = Invoice::withoutTenancy()->with('tenant:id,name,slug')->findOrFail($invoice);

        return Inertia::render('Platform/Pages/Billing/Invoice', [
            'invoice' => BillingPresenter::invoiceDocument($invoice),
            'workspace' => $invoice->tenant?->only(['id', 'name', 'slug']),
            'payments' => Payment::forTenant($invoice->tenant_id)
                ->where('invoice_id', $invoice->getKey())
                ->latest('created_at')
                ->get()
                ->map(fn (Payment $payment): array => [
                    ...BillingPresenter::payment($payment),
                    'note' => $payment->details['note'] ?? null,
                    'bankReference' => $payment->details['bank_reference'] ?? null,
                ])
                ->all(),
            'offlineMethods' => self::OFFLINE_METHODS,
            'canManage' => $request->user('platform')?->can('platform.billing.manage') === true,
        ]);
    }

    /**
     * Money arrived outside a gateway: record it and settle the invoice.
     */
    public function recordPayment(Request $request, string $invoice, PaymentProcessor $payments): RedirectResponse
    {
        $invoice = Invoice::withoutTenancy()->with('tenant')->findOrFail($invoice);

        $input = $request->validate([
            'method' => ['required', Rule::in(self::OFFLINE_METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['required', 'date', 'before_or_equal:tomorrow'],
        ]);

        /** @var PlatformUser $operator */
        $operator = $request->user('platform');

        $tenant = $invoice->tenant()->firstOrFail();

        $this->context->run($tenant, function () use ($payments, $invoice, $operator, $input, $tenant): void {
            $payments->recordOffline(
                $invoice,
                $operator,
                $input['method'],
                $input['reference'] ?? null,
                $input['note'] ?? null,
                // The day it was received, on the workspace's clock; never later than now.
                Carbon::parse($input['paid_at'], $tenant->timezone)->startOfDay()->utc()->min(Carbon::now()),
            );
        });

        Toast::success(__('Payment recorded. Invoice :number is paid.', ['number' => $invoice->number]));

        return back();
    }

    /**
     * Withdraw an open invoice. The billing cycle issues another when one is due.
     */
    public function void(Request $request, string $invoice, InvoiceIssuer $invoices, AuditLogger $audit): RedirectResponse
    {
        $invoice = Invoice::withoutTenancy()->findOrFail($invoice);

        $input = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $tenant = $invoice->tenant()->firstOrFail();

        $this->context->run($tenant, function () use ($invoices, $invoice, $audit, $input): void {
            DB::transaction(function () use ($invoices, $invoice, $audit, $input): void {
                $invoices->void($invoice);

                $audit->record('billing.invoice_withdrawn', $invoice, ['reason' => $input['reason']]);
            });
        });

        Toast::success(__('Invoice :number withdrawn.', ['number' => $invoice->number]));

        return back();
    }

    /**
     * What is owed and what came in this month, per currency.
     *
     * @return array{open: list<array{currency: string, amount: int, count: int}>, paidThisMonth: list<array{currency: string, amount: int, count: int}>}
     */
    private function totals(): array
    {
        $sum = static fn ($query): array => $query
            ->selectRaw('currency, sum(total_amount) as amount, count(*) as invoices')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(static fn ($row): array => [
                'currency' => (string) $row->getAttribute('currency'),
                'amount' => (int) $row->getAttribute('amount'),
                'count' => (int) $row->getAttribute('invoices'),
            ])
            ->all();

        return [
            'open' => $sum(Invoice::withoutTenancy()->where('status', InvoiceStatus::Open)),
            'paidThisMonth' => $sum(Invoice::withoutTenancy()
                ->where('status', InvoiceStatus::Paid)
                ->where('paid_at', '>=', Carbon::now()->startOfMonth())),
        ];
    }
}
