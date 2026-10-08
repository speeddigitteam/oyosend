<?php

declare(strict_types=1);

namespace App\Core\Audit\Http\Controllers;

use App\Core\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

final class AuditLogController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        // Tenant-scoped by the model; cursor pagination keeps deep pages cheap.
        $entries = AuditLog::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('actor_label', 'like', "%{$search}%")
                ->orWhere('subject_label', 'like', "%{$search}%")))
            ->when($filters['event'] ?? null, fn ($query, string $event) => $query->where('event', 'like', $event.'%'))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(30)
            ->withQueryString()
            ->through(fn (AuditLog $entry): array => [
                'id' => $entry->id,
                'event' => $entry->event,
                'actor' => $entry->actor_label,
                'actorType' => $entry->actor_type,
                'subject' => $entry->subject_label,
                'subjectType' => $entry->subject_type,
                'properties' => $entry->properties,
                'ipAddress' => $entry->ip_address,
                'createdAt' => $entry->created_at->toIso8601String(),
            ]);

        return Inertia::render('Core/Pages/Audit/Index', [
            'entries' => $entries,
            'filters' => [
                'search' => $search,
                'event' => $filters['event'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            // Event families present in this tenant's trail, for the filter.
            'eventGroups' => AuditLog::query()
                ->selectRaw("SUBSTRING_INDEX(event, '.', 1) as family")
                ->distinct()
                ->orderBy('family')
                ->pluck('family')
                ->all(),
        ]);
    }
}
