<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Support\Toast;
use App\Modules\Invoicing\Actions\CreditNoteManager;
use App\Modules\Invoicing\Actions\InvoiceDocument;
use App\Modules\Invoicing\Http\Requests\InvoicingRules;
use App\Modules\Invoicing\Models\CreditNote;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CreditNoteController
{
    public const string ISSUE = 'invoicing.credit_notes.issue';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly CreditNoteManager $notes,
        private readonly InvoiceDocument $documents,
        private readonly InvoicingControllerSupport $support,
    ) {}

    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::ISSUE, $invoice);
        $input = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            ...InvoicingRules::lines(),
        ]);
        /** @var list<array<string, mixed>> $lines */
        $lines = $input['lines'];
        $note = $this->notes->issue($invoice, $input['reason'], $lines, $this->support->membership($actor));
        Toast::success(__('Credit note :number issued.', ['number' => $note->number]));

        return back();
    }

    public function document(Request $request, Invoice $invoice, CreditNote $creditNote): Response
    {
        $actor = $this->support->actor($request);
        abort_unless($creditNote->invoice_id === $invoice->id, 404);
        $this->records->authorize($actor, InvoiceController::VIEW, $invoice);

        return response($this->documents->html($creditNote))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'private, no-store');
    }
}
