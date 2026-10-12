<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers;

use App\Core\Reporting\ReportRegistry;
use App\Core\Support\Toast;
use App\Modules\Reports\Actions\ReportExporter;
use App\Modules\Reports\Actions\ReportOutput;
use App\Modules\Reports\Actions\ReportRunner;
use App\Modules\Reports\Actions\SavedReportAccess;
use App\Modules\Reports\Actions\SavedReportManager;
use App\Modules\Reports\Http\Requests\SavedReportRequest;
use App\Modules\Reports\Models\SavedReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Saved reports. Opening one runs the provider as the person opening it; a record outside the
 * visible set is a 404, and so is a report whose provider the person may not run.
 */
final class SavedReportController
{
    public function __construct(
        private readonly SavedReportManager $manager,
        private readonly SavedReportAccess $access,
        private readonly ReportRegistry $registry,
        private readonly ReportRunner $runner,
        private readonly ReportExporter $exporter,
        private readonly ReportsSupport $support,
    ) {}

    public function show(Request $request, SavedReport $saved): Response
    {
        $actor = $this->support->actor($request);
        $output = $this->output($request, $saved);

        return $this->support->page($actor, $output, [
            'from' => $output->from,
            'to' => $output->to,
            'columns' => array_map(static fn ($column): string => $column->key, $output->columns),
            'filters' => $output->filters,
        ], (int) $request->query('rows_page', 1), $saved);
    }

    public function export(Request $request, SavedReport $saved): HttpResponse
    {
        return $this->exporter->download($this->output($request, $saved), $saved);
    }

    public function store(SavedReportRequest $request): RedirectResponse
    {
        $saved = $this->manager->create($this->support->actor($request), $request->validated());
        Toast::success(__('Saved report created.'));

        return to_route('reports.saved.show', ['saved' => $saved->id]);
    }

    public function update(SavedReportRequest $request, SavedReport $saved): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->authorizeChange($request, $saved);
        $this->manager->update($actor, $saved, $request->validated());
        Toast::success(__('Saved report updated.'));

        return back();
    }

    public function destroy(Request $request, SavedReport $saved): RedirectResponse
    {
        $this->authorizeChange($request, $saved);
        $this->manager->delete($saved);
        Toast::success(__('Saved report deleted.'));

        return to_route('reports.index');
    }

    private function output(Request $request, SavedReport $saved): ReportOutput
    {
        $actor = $this->support->actor($request);
        abort_unless($this->access->canOpen($actor, $saved), 404);
        $provider = $this->registry->find($saved->provider_key, $actor) ?? abort(404);

        return $this->runner->run($actor, $provider, $this->runner->savedInput($saved));
    }

    private function authorizeChange(Request $request, SavedReport $saved): void
    {
        $actor = $this->support->actor($request);
        abort_unless($this->access->canOpen($actor, $saved) && $this->access->canManage($actor, $saved), 404);
    }
}
