<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\EmailMarketing\Actions\SubscriberImporter;
use App\Modules\EmailMarketing\Enums\ConsentMethod;
use App\Modules\EmailMarketing\Models\ImportRun;
use App\Modules\EmailMarketing\Models\MailingList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Bulk membership from a CSV file. Consent evidence decides pending versus subscribed, never the importer. */
final class ImportController
{
    public function __construct(
        private readonly SubscriberImporter $importer,
        private readonly EmailMarketingSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);

        return Inertia::render('Modules/EmailMarketing/Pages/Imports/Index', [
            'imports' => ImportRun::query()->with('list')->latest()->limit(25)->get()->map(fn (ImportRun $run): array => [
                'id' => $run->id,
                'fileName' => $run->file_name,
                'listName' => $run->list->name,
                'totalRows' => $run->total_rows,
                'subscribedRows' => $run->subscribed_rows,
                'pendingRows' => $run->pending_rows,
                'skippedRows' => $run->skipped_rows,
                'failedRows' => $run->failed_rows,
                'errors' => $run->errors ?? [],
                'createdAt' => $run->created_at->toIso8601String(),
            ])->all(),
            'lists' => $this->support->listOptions(),
            'columns' => SubscriberImporter::COLUMNS,
            'consentMethods' => array_map(static fn (ConsentMethod $method): string => $method->value, ConsentMethod::cases()),
            'maxRows' => (int) config('email_marketing.import.max_rows'),
            'maxBytes' => (int) config('email_marketing.import.max_bytes'),
            'canCreateContacts' => $actor->can('core.contacts.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:1024', 'extensions:csv,txt'],
            'list_id' => ['required', 'string', 'size:26'],
        ]);

        $list = MailingList::query()->findOrFail($data['list_id']);
        $run = $this->importer->import($request->file('file'), $list, $this->support->membership($actor), $actor->can('core.contacts.manage'));

        if ($run->subscribed_rows + $run->pending_rows === 0) {
            Toast::error(__('No rows were imported. See the errors below.'));
        } elseif ($run->failed_rows > 0) {
            Toast::warning(__(':count rows had errors. See the list below.', ['count' => $run->failed_rows]));
        } else {
            Toast::success(__('Import finished: :subscribed subscribed, :pending pending.', ['subscribed' => $run->subscribed_rows, 'pending' => $run->pending_rows]));
        }

        return redirect()->route('email_marketing.imports.index');
    }

    public function template(Request $request): StreamedResponse
    {
        $this->support->actor($request);
        $csv = SubscriberImporter::template();

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, 'subscribers-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
