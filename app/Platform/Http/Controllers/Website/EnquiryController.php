<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers\Website;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Core\Website\Enums\EnquiryStatus;
use App\Core\Website\Models\WebsiteEnquiry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Messages from the public website's contact form (docs/WEBSITE.md). Each was also mailed to the contact
 * recipient; here an operator sees them all and marks the ones answered.
 */
final class EnquiryController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['new', 'handled', 'all'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $status = $filters['status'] ?? 'new';
        $search = trim((string) ($filters['search'] ?? ''));

        $enquiries = WebsiteEnquiry::query()
            ->with('handler:id,name')
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('name', 'like', Like::contains($search))
                ->orWhere('email', 'like', Like::contains($search))
                ->orWhere('company', 'like', Like::contains($search))
                ->orWhere('message', 'like', Like::contains($search))))
            ->latest()
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (WebsiteEnquiry $enquiry): array => [
                'id' => $enquiry->id,
                'name' => $enquiry->name,
                'email' => $enquiry->email,
                'phone' => $enquiry->phone,
                'company' => $enquiry->company,
                'topic' => $enquiry->topic,
                'message' => $enquiry->message,
                'locale' => $enquiry->locale,
                'status' => $enquiry->status->value,
                'createdAt' => $enquiry->created_at->toIso8601String(),
                'handledAt' => $enquiry->handled_at?->toIso8601String(),
                'handledBy' => $enquiry->handler?->name,
            ]);

        return Inertia::render('Platform/Pages/Website/Messages', [
            'enquiries' => $enquiries,
            'counts' => [
                'new' => WebsiteEnquiry::query()->where('status', EnquiryStatus::New)->count(),
                'handled' => WebsiteEnquiry::query()->where('status', EnquiryStatus::Handled)->count(),
            ],
            'filters' => ['status' => $status, 'search' => $search],
        ]);
    }

    public function handled(Request $request, WebsiteEnquiry $enquiry, AuditLogger $audit): RedirectResponse
    {
        if ($enquiry->status !== EnquiryStatus::Handled) {
            DB::transaction(function () use ($request, $enquiry, $audit): void {
                $enquiry->update(['status' => EnquiryStatus::Handled, 'handled_at' => now(), 'handled_by' => $request->user('platform')?->getAuthIdentifier()]);
                $audit->record('platform.website.enquiry_handled', $enquiry, subjectLabel: $enquiry->name);
            });
        }

        Toast::success(__('Message marked as answered.'));

        return back();
    }

    public function reopen(WebsiteEnquiry $enquiry, AuditLogger $audit): RedirectResponse
    {
        if ($enquiry->status !== EnquiryStatus::New) {
            DB::transaction(function () use ($enquiry, $audit): void {
                $enquiry->update(['status' => EnquiryStatus::New, 'handled_at' => null, 'handled_by' => null]);
                $audit->record('platform.website.enquiry_reopened', $enquiry, subjectLabel: $enquiry->name);
            });
        }

        Toast::success(__('Message moved back to new.'));

        return back();
    }

    public function destroy(WebsiteEnquiry $enquiry, AuditLogger $audit): RedirectResponse
    {
        DB::transaction(function () use ($enquiry, $audit): void {
            // The visitor's words go with the row; the trail keeps only who and when.
            $audit->record('platform.website.enquiry_deleted', null, ['topic' => $enquiry->topic], subjectLabel: $enquiry->name);
            $enquiry->delete();
        });

        Toast::success(__('Message deleted.'));

        return back();
    }
}
