<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Http\Controllers;

use App\Core\Support\Toast;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Core\Tenancy\TenantContext;
use App\Modules\Invoicing\Actions\InvoiceDocument;
use App\Modules\Invoicing\Actions\InvoicingSettings;
use App\Modules\Invoicing\Actions\LetterheadAssets;
use App\Modules\Invoicing\Enums\DocumentTemplate;
use App\Modules\Invoicing\Models\NumberSequence;
use App\Modules\Invoicing\Models\TaxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class SettingsController
{
    /** Minutes a letterhead preview URL stays valid; the page asks for a fresh one on every visit. */
    private const int URL_TTL = 60;

    public function __construct(
        private readonly InvoicingSettings $settings,
        private readonly InvoicingControllerSupport $support,
        private readonly LetterheadAssets $letterhead,
        private readonly TenantStorage $storage,
        private readonly InvoiceDocument $documents,
    ) {}

    public function index(): Response
    {
        $settings = $this->settings->current();
        $period = $settings->fiscal_year_numbering
            ? ($this->settings->today()->month >= $settings->fiscal_year_start_month ? $this->settings->today()->year : $this->settings->today()->year - 1)
            : 0;
        $last = static fn (string $kind): int => (int) NumberSequence::query()
            ->where('kind', $kind)->where('period', $period)->value('last_number');

        return Inertia::render('Modules/Invoicing/Pages/Settings/Index', [
            'settings' => [
                'invoicePrefix' => $settings->invoice_prefix,
                'creditNotePrefix' => $settings->credit_note_prefix,
                'numberPadding' => $settings->number_padding,
                'fiscalYearNumbering' => $settings->fiscal_year_numbering,
                'fiscalYearStartMonth' => $settings->fiscal_year_start_month,
                'defaultDueDays' => $settings->default_due_days,
                'defaultTerms' => $settings->default_terms,
                'defaultNotes' => $settings->default_notes,
                'remindersEnabled' => $settings->reminders_enabled,
                'reminderBeforeDays' => $settings->reminder_before_days,
                'reminderOverdueEveryDays' => $settings->reminder_overdue_every_days,
                'invoiceAcceptedQuotes' => $settings->invoice_accepted_quotes,
                'documentTemplate' => $settings->document_template->value,
                'letterheadHeaderUrl' => $this->letterheadUrl($settings->letterhead_header_path),
                'letterheadFooterUrl' => $this->letterheadUrl($settings->letterhead_footer_path),
            ],
            'sequences' => [
                'invoicesIssued' => $last('invoice'),
                'creditNotesIssued' => $last('credit_note'),
            ],
            'taxRates' => $this->support->taxRates(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'invoice_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._\/-]*$/'],
            'credit_note_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._\/-]*$/'],
            'number_padding' => ['required', 'integer', 'between:1,10'],
            'fiscal_year_numbering' => ['required', 'boolean'],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'default_due_days' => ['required', 'integer', 'between:0,365'],
            'default_terms' => ['nullable', 'string', 'max:20000'],
            'default_notes' => ['nullable', 'string', 'max:10000'],
            'reminders_enabled' => ['required', 'boolean'],
            'reminder_before_days' => ['required', 'integer', 'between:0,30'],
            'reminder_overdue_every_days' => ['required', 'integer', 'between:1,90'],
            'invoice_accepted_quotes' => ['required', 'boolean'],
            'document_template' => ['required', Rule::enum(DocumentTemplate::class)],
        ]);
        $this->settings->update($input);
        Toast::success(__('Invoicing settings saved.'));

        return back();
    }

    /** A sample document in the chosen template (`?template=`), for the settings preview. Persists nothing. */
    public function documentPreview(Request $request): HttpResponse
    {
        $template = DocumentTemplate::tryFrom((string) $request->query('template'))
            ?? $this->settings->current()->document_template;

        return response($this->documents->previewHtml($template))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'private, no-store');
    }

    /** Upload or replace one letterhead band (`header` or `footer`). */
    public function uploadLetterhead(Request $request, string $slot): RedirectResponse
    {
        abort_unless($this->letterhead->isSlot($slot), 404);
        $input = $request->validate(['file' => $this->letterhead->rules()]);
        $this->letterhead->replace($slot, $input['file']);
        Toast::success(__('Letterhead updated.'));

        return back();
    }

    /** Remove one letterhead band. */
    public function removeLetterhead(string $slot): RedirectResponse
    {
        abort_unless($this->letterhead->isSlot($slot), 404);
        $this->letterhead->remove($slot);
        Toast::success(__('Letterhead removed.'));

        return back();
    }

    /** A short-lived signed URL for a letterhead band, or null when there is none or the disk cannot sign. */
    private function letterheadUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        try {
            return $this->storage->temporaryUrl($path, Carbon::now()->addMinutes(self::URL_TTL));
        } catch (Throwable) {
            return null;
        }
    }

    public function storeTaxRate(Request $request): RedirectResponse
    {
        $input = $this->validatedRate($request);

        DB::transaction(function () use ($input): void {
            if ($input['is_default']) {
                TaxRate::query()->where('is_default', true)->update(['is_default' => false]);
            }

            TaxRate::query()->create($input);
        });
        Toast::success(__('Tax rate created.'));

        return back();
    }

    public function updateTaxRate(Request $request, TaxRate $taxRate): RedirectResponse
    {
        $input = $this->validatedRate($request, $taxRate);

        DB::transaction(function () use ($input, $taxRate): void {
            if ($input['is_default']) {
                TaxRate::query()->where('is_default', true)->whereKeyNot($taxRate->id)->update(['is_default' => false]);
            }

            $taxRate->update($input);
        });
        Toast::success(__('Tax rate saved.'));

        return back();
    }

    /**
     * Editing a rate never changes an issued invoice: each line stores its own tax name and rate.
     *
     * @return array{name: string, rate_bps: int, is_default: bool, is_active: bool}
     */
    private function validatedRate(Request $request, ?TaxRate $current = null): array
    {
        $input = $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('invoicing_tax_rates', 'name')
                    ->where('tenant_id', app(TenantContext::class)->requireId())
                    ->ignore($current?->id),
            ],
            'rate_bps' => ['required', 'integer', 'between:0,10000'],
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($input['is_default'] && ! $input['is_active']) {
            $input['is_default'] = false;
        }

        return $input;
    }
}
