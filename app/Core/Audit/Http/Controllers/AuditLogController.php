<?php

declare(strict_types=1);

namespace App\Core\Audit\Http\Controllers;

use App\Core\Audit\Models\AuditLog;
use App\Core\Support\Like;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

final class AuditLogController
{
    public function index(Request $request, TenantContext $context): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $contains = Like::contains($search);

        // The page shows workspace time, so "from 8 October" means that day as the workspace's clock has it.
        $timezone = $context->require()->timezone;

        // Tenant-scoped by the model; cursor pagination keeps deep pages cheap.
        $entries = AuditLog::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('actor_label', 'like', $contains)
                ->orWhere('subject_label', 'like', $contains)))
            // A whole family (`member`) or one event (`member.invited`); never a mere prefix of another name.
            ->when($filters['event'] ?? null, fn ($query, string $event) => $query->where(fn ($match) => $match
                ->where('event', $event)
                ->orWhere('event', 'like', Like::escape($event).'.%')))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query
                ->where('created_at', '>=', Carbon::parse($from, $timezone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query
                ->where('created_at', '<=', Carbon::parse($to, $timezone)->endOfDay()->utc()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(30)
            ->withQueryString();

        return Inertia::render('Core/Pages/Audit/Index', [
            // Built by hand rather than with through(): the paginator derives its
            // cursors from the sort columns of the rows it holds, so the rows
            // must still be models when the page links are read.
            'entries' => [
                'data' => $entries->getCollection()->map(fn (AuditLog $entry): array => [
                    'id' => $entry->id,
                    'event' => $entry->event,
                    'actor' => $entry->actor_label,
                    'actorType' => $entry->actor_type,
                    'subject' => $entry->subject_label,
                    'subjectType' => $entry->subject_type,
                    'properties' => $entry->properties,
                    'ipAddress' => $entry->ip_address,
                    'createdAt' => $entry->created_at->toIso8601String(),
                ])->all(),
                'per_page' => $entries->perPage(),
                'prev_page_url' => $entries->previousPageUrl(),
                'next_page_url' => $entries->nextPageUrl(),
            ],
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
