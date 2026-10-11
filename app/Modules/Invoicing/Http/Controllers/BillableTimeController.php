<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Invoicing\Actions\InvoiceMath;
use App\Modules\Invoicing\Actions\InvoiceSources;
use App\Modules\Invoicing\Http\Requests\InvoicingRules;
use App\Modules\Invoicing\Models\BillableTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Billing approved project time. The projects are opaque ids announced by the Projects
 * event contract; this module never reads a Projects table.
 */
final class BillableTimeController
{
    public function __construct(
        private readonly InvoiceSources $sources,
        private readonly InvoicingControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $groups = BillableTime::query()
            ->whereNull('invoice_id')
            ->select('project_id', 'currency', 'rate_amount')
            ->selectRaw('SUM(minutes) AS minutes')
            ->selectRaw('COUNT(*) AS entries')
            ->groupBy('project_id', 'currency', 'rate_amount')
            ->toBase()
            ->get();

        $projects = [];

        foreach ($groups as $row) {
            $key = $row->project_id.'|'.$row->currency;
            $projects[$key] ??= [
                'projectId' => (string) $row->project_id,
                'currency' => (string) $row->currency,
                'minutes' => 0,
                'entries' => 0,
                'estimatedAmount' => 0,
            ];
            $minutes = (int) $row->minutes;
            $projects[$key]['minutes'] += $minutes;
            $projects[$key]['entries'] += (int) $row->entries;
            $projects[$key]['estimatedAmount'] += InvoiceMath::scale(InvoiceMath::hoursMilli($minutes), (int) $row->rate_amount, 1000);
        }

        return Inertia::render('Modules/Invoicing/Pages/BillableTime/Index', [
            'projects' => array_values($projects),
            'form' => $actor->can(InvoiceController::EDIT) ? $this->support->formOptions($actor) : $this->support->emptyFormOptions(),
            'canBill' => $actor->can(InvoiceController::EDIT),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validate([
            'project_id' => ['required', 'string', 'size:26'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'label' => ['nullable', 'string', 'max:180'],
            ...InvoicingRules::customer(),
        ]);
        $customer = $this->support->customer($input, $actor);
        $invoice = $this->sources->fromBillableTime(
            $input['project_id'],
            $customer['contact_id'],
            $customer['company_id'],
            $input['currency'],
            $input['label'] ?? null,
            $this->support->membership($actor),
        );
        Toast::success(__('Draft invoice created from project time.'));

        return redirect()->route('invoicing.invoices.show', $invoice);
    }
}
