<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Support\Actions\SupportCatalogue;
use App\Modules\Support\Http\Requests\CannedRequest;
use App\Modules\Support\Models\CannedResponse;
use App\Modules\Support\Support\PlaceholderRenderer;
use App\Modules\Support\Support\SupportPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CannedController
{
    public function __construct(
        private readonly SupportCatalogue $catalogue,
        private readonly SupportControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $manage = $actor->can(SupportPermissions::CANNED_MANAGE);
        $search = trim((string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? ''));

        $responses = CannedResponse::query()
            ->when(! $manage, fn ($query) => $query->where('is_active', true))
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('name', 'like', Like::contains($search))
                ->orWhere('body', 'like', Like::contains($search))))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CannedResponse $response): array => [
                'id' => $response->id,
                'name' => $response->name,
                'body' => $response->body,
                'isActive' => $response->is_active,
                'updatedAt' => $response->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('Modules/Support/Pages/Canned/Index', [
            'responses' => $responses,
            'filters' => ['search' => $search],
            'placeholders' => PlaceholderRenderer::ALLOWED,
            'canManage' => $manage,
        ]);
    }

    public function store(CannedRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->catalogue->saveCanned(null, $request->validated(), $this->support->membership($actor));
        Toast::success(__('Canned response saved.'));

        return back();
    }

    public function update(CannedRequest $request, CannedResponse $canned): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->catalogue->saveCanned($canned, $request->validated(), $this->support->membership($actor));
        Toast::success(__('Canned response saved.'));

        return back();
    }

    public function destroy(CannedResponse $canned): RedirectResponse
    {
        $this->catalogue->deleteCanned($canned);
        Toast::success(__('Canned response deleted.'));

        return back();
    }
}
