<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Social\Actions\CsvImporter;
use App\Modules\Social\Http\Requests\ImportRequest;
use App\Modules\Social\Models\Import;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Bulk scheduling from a CSV file. */
final class ImportController
{
    public function __construct(
        private readonly CsvImporter $importer,
        private readonly SocialSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);

        return Inertia::render('Modules/Social/Pages/Imports/Index', [
            'imports' => Import::query()->latest()->limit(25)->get()->map(fn (Import $import): array => [
                'id' => $import->id,
                'fileName' => $import->file_name,
                'mode' => $import->mode,
                'totalRows' => $import->total_rows,
                'createdRows' => $import->created_rows,
                'failedRows' => $import->failed_rows,
                'errors' => $import->errors ?? [],
                'createdAt' => $import->created_at?->toIso8601String(),
            ])->all(),
            'columns' => CsvImporter::COLUMNS,
            'maxRows' => (int) config('social.import.max_rows'),
            'maxBytes' => (int) config('social.import.max_bytes'),
            'canSchedule' => $actor->can(PostController::SCHEDULE),
        ]);
    }

    public function store(ImportRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $mode = (string) $request->validated('mode');

        if ($mode === CsvImporter::MODE_SCHEDULE && ! $actor->can(PostController::SCHEDULE)) {
            throw ValidationException::withMessages(['mode' => __('You may import drafts only.')]);
        }

        $import = $this->importer->import($request->file('file'), $mode, $this->support->membership($actor));

        if ($import->created_rows === 0) {
            Toast::error(__('No rows were imported. See the errors below.'));
        } elseif ($import->failed_rows > 0) {
            Toast::warning(__(':created rows imported, :failed rows had errors.', ['created' => $import->created_rows, 'failed' => $import->failed_rows]));
        } else {
            Toast::success(trans_choice(':count post imported.|:count posts imported.', $import->created_rows, ['count' => $import->created_rows]));
        }

        return redirect()->route('social.imports.index');
    }

    public function template(Request $request): StreamedResponse
    {
        $this->support->actor($request);
        $csv = CsvImporter::template();

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, 'social-posts-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
