<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Invoicing\Actions\InvoiceDelivery;
use App\Modules\Invoicing\Actions\InvoiceDocument;
use App\Modules\Invoicing\Actions\InvoiceManager;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\PaymentMethod;
use App\Modules\Invoicing\Http\Requests\InvoicingRules;
use App\Modules\Invoicing\Models\CreditNote;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class InvoiceController
{
    public const string VIEW = 'invoicing.invoices.view';

    public const string EDIT = 'invoicing.invoices.edit';

    public const string ISSUE = 'invoicing.invoices.issue';

    public const string SEND = 'invoicing.invoices.send';

    public const string VOID = 'invoicing.invoices.void';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly InvoiceManager $invoices,
        private readonly InvoiceDelivery $delivery,
        private readonly InvoiceDocument $documents,
        private readonly InvoicingControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'contact_id' => ['nullable', 'string', 'size:26'],
            'company_id' => ['nullable', 'string', 'size:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $invoices = $this->records->constrain(Invoice::query(), $actor, self::VIEW)
            ->with(['contact:id,first_name,last_name', 'company:id,name', 'owner.user:id,name'])
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('number', 'like', Like::contains($search))
                ->orWhere('title', 'like', Like::contains($search))
                ->orWhere('reference', 'like', Like::contains($search))
                ->orWhere('customer_name', 'like', Like::contains($search))))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['contact_id']), fn ($query) => $query->where('contact_id', $filters['contact_id']))
            ->when(isset($filters['company_id']), fn ($query) => $query->where('company_id', $filters['company_id']))
            ->when(isset($filters['from']), fn ($query) => $query->where('created_at', '>=', $filters['from'].' 00:00:00'))
            ->when(isset($filters['to']), fn ($query) => $query->where('created_at', '<=', $filters['to'].' 23:59:59'))
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Invoice $invoice): array => $this->support->summary($invoice));

        return Inertia::render('Modules/Invoicing/Pages/Invoices/Index', [
            'invoices' => $invoices,
            'filters' => [
                'search' => $search,
                'status' => $filters['status'] ?? null,
                'contactId' => $filters['contact_id'] ?? null,
                'companyId' => $filters['company_id'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'statuses' => array_map(static fn (InvoiceStatus $status): string => $status->value, InvoiceStatus::cases()),
            'canCreate' => $actor->can(self::EDIT),
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->support->actor($request);

        return Inertia::render('Modules/Invoicing/Pages/Invoices/Create', [
            'form' => $this->support->formOptions($actor),
            'prefill' => [
                'contactId' => $request->query('contact_id'),
                'companyId' => $request->query('company_id'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        [$attributes, $lines] = $this->validated($request, $actor);
        $invoice = $this->invoices->create($attributes, $lines, $this->support->membership($actor));
        Toast::success(__('Draft invoice created.'));

        return redirect()->route('invoicing.invoices.show', $invoice);
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::VIEW, $invoice);
        $invoice->load([
            'lines', 'payments.recorder.user:id,name', 'creditNotes', 'contact:id,first_name,last_name,email',
            'company:id,name,email', 'owner.user:id,name',
        ]);

        $draft = $invoice->status === InvoiceStatus::Draft;
        $open = $invoice->status->isOpen();
        $can = fn (string $permission): bool => $this->records->allows($actor, $permission, $invoice);
        $editable = $draft && $can(self::EDIT);

        return Inertia::render('Modules/Invoicing/Pages/Invoices/Show', [
            'invoice' => [
                ...$this->support->summary($invoice),
                'contactId' => $invoice->contact_id,
                'companyId' => $invoice->company_id,
                'subtotalAmount' => $invoice->subtotal_amount,
                'discountAmount' => $invoice->discount_amount,
                'taxAmount' => $invoice->tax_amount,
                'snapshot' => $draft ? null : [
                    'name' => $invoice->customer_name,
                    'contactName' => $invoice->customer_contact_name,
                    'email' => $invoice->customer_email,
                    'phone' => $invoice->customer_phone,
                    'address' => $invoice->customer_address,
                    'taxId' => $invoice->customer_tax_id,
                ],
                'customerEmail' => $invoice->customer_email ?? $invoice->contact->email ?? $invoice->company?->email,
                'seller' => $invoice->seller_snapshot,
                'terms' => $invoice->terms,
                'notes' => $invoice->notes,
                'issuedAt' => $invoice->issued_at?->toIso8601String(),
                'paidAt' => $invoice->paid_at?->toIso8601String(),
                'overdueAt' => $invoice->overdue_at?->toIso8601String(),
                'voidedAt' => $invoice->voided_at?->toIso8601String(),
                'voidReason' => $invoice->void_reason,
                'recurringTemplateId' => $invoice->recurring_template_id,
                'lines' => $invoice->lines->map(fn ($line): array => $this->support->line($line))->all(),
                'payments' => $invoice->payments->map(fn (Payment $payment): array => [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'method' => $payment->method->value,
                    'reference' => $payment->reference,
                    'paidOn' => $payment->paid_on->toDateString(),
                    'notes' => $payment->notes,
                    'recordedBy' => $payment->recorder?->user->name,
                    'reversedAt' => $payment->reversed_at?->toIso8601String(),
                    'reversalReason' => $payment->reversal_reason,
                    'createdAt' => $payment->created_at->toIso8601String(),
                ])->all(),
                'creditNotes' => $invoice->creditNotes->map(fn (CreditNote $note): array => [
                    'id' => $note->id,
                    'number' => $note->number,
                    'issuedOn' => $note->issued_on->toDateString(),
                    'reason' => $note->reason,
                    'subtotalAmount' => $note->subtotal_amount,
                    'taxAmount' => $note->tax_amount,
                    'totalAmount' => $note->total_amount,
                    'currency' => $note->currency,
                ])->all(),
            ],
            'abilities' => [
                'edit' => $editable,
                'delete' => $editable,
                'issue' => $draft && $can(self::ISSUE),
                'send' => ! $draft && $invoice->status !== InvoiceStatus::Void && $can(self::SEND),
                'void' => $open && $invoice->paid_amount === 0 && $invoice->credited_amount === 0 && $can(self::VOID),
                'recordPayment' => $open && $can(PaymentController::RECORD),
                'reversePayment' => ($open || $invoice->status === InvoiceStatus::Paid) && $can(PaymentController::REVERSE),
                'issueCreditNote' => $open && $can(CreditNoteController::ISSUE),
            ],
            'paymentMethods' => array_map(static fn (PaymentMethod $method): string => $method->value, PaymentMethod::cases()),
            'form' => $editable ? $this->support->formOptions($actor) : $this->support->emptyFormOptions(),
        ]);
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::EDIT, $invoice);
        [$attributes, $lines] = $this->validated($request, $actor);
        $this->invoices->update($invoice, $attributes, $lines);
        Toast::success(__('Draft invoice saved.'));

        return back();
    }

    public function destroy(Request $request, Invoice $invoice): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::EDIT, $invoice);
        $this->invoices->delete($invoice);
        Toast::success(__('Draft invoice deleted.'));

        return redirect()->route('invoicing.invoices.index');
    }

    public function issue(Request $request, Invoice $invoice): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::ISSUE, $invoice);
        $issued = $this->invoices->issue($invoice);
        Toast::success(__('Invoice :number issued.', ['number' => $issued->number]));

        return back();
    }

    public function send(Request $request, Invoice $invoice): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::SEND, $invoice);
        $input = $request->validate([
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->delivery->send($invoice, $input['email'] ?? null, $input['message'] ?? null);
        Toast::success(__('Invoice sent.'));

        return back();
    }

    public function void(Request $request, Invoice $invoice): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::VOID, $invoice);
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->invoices->void($invoice, $input['reason']);
        Toast::success(__('Invoice voided.'));

        return back();
    }

    /**
     * The print-optimised document. Open it in its own tab (the app sends X-Frame-Options: DENY,
     * so it cannot be framed) and use the browser's print dialog to save a PDF.
     */
    public function document(Request $request, Invoice $invoice): HttpResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::VIEW, $invoice);
        $response = response($this->documents->html($invoice))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'private, no-store');

        if ($request->boolean('download')) {
            $response->header('Content-Disposition', 'attachment; filename="invoice-'.($invoice->number ?? $invoice->id).'.html"');
        }

        return $response;
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function validated(Request $request, User $actor): array
    {
        $input = $request->validate([
            ...InvoicingRules::customer(),
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'title' => ['nullable', 'string', 'max:200'],
            'reference' => ['nullable', 'string', 'max:120'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'terms' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:10000'],
            ...InvoicingRules::lines(),
        ]);

        $customer = $this->support->customer($input, $actor);
        /** @var list<array<string, mixed>> $lines */
        $lines = $input['lines'];
        unset($input['lines']);

        return [[
            ...$input,
            ...$customer,
            'currency' => strtoupper((string) $input['currency']),
        ], $lines];
    }
}
