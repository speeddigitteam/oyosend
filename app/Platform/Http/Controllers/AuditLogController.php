<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Core\Audit\Models\AuditLog;
use App\Core\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform's own audit trail: what happened at platform level (sign-ins
 * to this panel, sign-ups, plan changes) and everything an operator did
 * inside a workspace, whichever workspace it was.
 */
final class AuditLogController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        // Deliberate cross-tenant read, limited to platform-level entries and operators' own actions.
        $entries = AuditLog::withoutTenancy()
            ->with('tenant:id,name,slug')
            ->where(fn (Builder $scope) => $scope->whereNull('tenant_id')->orWhere('actor_type', 'platform_user'))
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('event', 'like', Like::contains($search))
                ->orWhere('actor_label', 'like', Like::contains($search))
                ->orWhere('subject_label', 'like', Like::contains($search))))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(30)
            ->withQueryString();

        return Inertia::render('Platform/Pages/Audit/Index', [
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
                    'workspace' => $entry->tenant?->only(['id', 'name', 'slug']),
                    'ipAddress' => $entry->ip_address,
                    'createdAt' => $entry->created_at->toIso8601String(),
                ])->all(),
                'per_page' => $entries->perPage(),
                'prev_page_url' => $entries->previousPageUrl(),
                'next_page_url' => $entries->nextPageUrl(),
            ],
            'filters' => ['search' => $search],
        ]);
    }
}
