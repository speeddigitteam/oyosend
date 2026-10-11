<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Http\Controllers;

use App\Modules\Invoicing\Actions\Receivables;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ReportController
{
    public function __construct(private readonly Receivables $receivables) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
        ]);
        $report = $this->receivables->report($filters['from'] ?? null, $filters['to'] ?? null, $filters['currency'] ?? null);

        return Inertia::render('Modules/Invoicing/Pages/Reports/Index', [
            'report' => $report,
            'filters' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? $report['asOf'],
                'currency' => isset($filters['currency']) ? strtoupper($filters['currency']) : null,
            ],
            'buckets' => Receivables::BUCKETS,
        ]);
    }
}
