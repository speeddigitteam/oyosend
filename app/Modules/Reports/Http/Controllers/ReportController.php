<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers;

use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportRegistry;
use App\Modules\Reports\Actions\ReportExporter;
use App\Modules\Reports\Actions\ReportRunner;
use App\Modules\Reports\Actions\SavedReportAccess;
use App\Modules\Reports\Http\ReportPresenter;
use App\Modules\Reports\Models\SavedReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** The catalogue of reports the member may run, running one, and exporting it. */
final class ReportController
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportRunner $runner,
        private readonly ReportExporter $exporter,
        private readonly SavedReportAccess $savedAccess,
        private readonly ReportsSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $modules = [];

        foreach ($this->registry->availableFor($actor) as $provider) {
            $definition = $provider->definition();
            $modules[$definition->module][] = [
                'key' => $definition->key,
                'label' => __($definition->label),
                'description' => __($definition->description),
                'usesDateRange' => $definition->usesDateRange,
            ];
        }

        ksort($modules);

        $saved = $this->savedAccess->visible($actor)->with('owner.user:id,name')->orderBy('name')->limit(200)->get()
            ->map(fn (SavedReport $report): array => $this->support->savedRow($actor, $report))
            ->all();

        return Inertia::render('Modules/Reports/Pages/Index', [
            'catalogue' => array_map(
                static fn (string $module, array $reports): array => ['module' => $module, 'reports' => $reports],
                array_keys($modules),
                array_values($modules),
            ),
            'saved' => $saved,
            'canSave' => $actor->can(SavedReportAccess::MANAGE),
            'canExport' => $actor->can(ReportsSupport::EXPORT),
            'bounds' => ReportPresenter::bounds(),
        ]);
    }

    public function run(Request $request, string $provider): Response
    {
        $actor = $this->support->actor($request);
        $report = $this->provider($provider, $request);
        $input = $this->input($request);
        $output = $this->runner->run($actor, $report, $input);

        return $this->support->page($actor, $output, [
            'from' => $output->from,
            'to' => $output->to,
            'columns' => array_map(static fn ($column): string => $column->key, $output->columns),
            'filters' => $output->filters,
        ], (int) $request->query('rows_page', 1), null);
    }

    public function export(Request $request, string $provider): HttpResponse
    {
        $actor = $this->support->actor($request);
        $report = $this->provider($provider, $request);

        return $this->exporter->download($this->runner->run($actor, $report, $this->input($request)));
    }

    private function provider(string $key, Request $request): ReportProvider
    {
        // Unknown, not entitled and not permitted are the same answer: nothing is disclosed.
        return $this->registry->find($key, $this->support->actor($request)) ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return $request->only(['from', 'to', 'columns', 'filters']);
    }
}
