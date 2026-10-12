<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Toast;
use App\Modules\Automation\Enums\VersionStatus;
use App\Modules\Automation\Http\Presenters\Presenter;
use App\Modules\Automation\Http\Requests\WebhookEndpointRequest;
use App\Modules\Automation\Models\WebhookEndpoint;
use App\Modules\Automation\Models\WorkflowVersion;
use App\Modules\Automation\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Outbound webhook endpoints a workflow's webhook node may call. */
final class WebhookEndpointController
{
    public function __construct(private readonly Actor $actor, private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        return Inertia::render('Modules/Automation/Pages/Webhooks/Index', [
            'endpoints' => WebhookEndpoint::query()->orderBy('name')->get()
                ->map(static fn (WebhookEndpoint $endpoint): array => Presenter::endpoint($endpoint))->values()->all(),
        ]);
    }

    public function store(WebhookEndpointRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->assertNameFree((string) $data['name'], null);

        $endpoint = WebhookEndpoint::query()->create([
            'name' => $data['name'],
            'url' => $data['url'],
            'secret' => ($data['secret'] ?? '') === '' ? null : $data['secret'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by_membership_id' => $this->actor->membership($request)->id,
        ]);

        $this->audit->record('automation.webhook_endpoint.created', $endpoint, ['url' => $endpoint->url]);
        Toast::success(__('Webhook endpoint added.'));

        return back();
    }

    public function update(WebhookEndpointRequest $request, WebhookEndpoint $endpoint): RedirectResponse
    {
        $data = $request->validated();
        $this->assertNameFree((string) $data['name'], $endpoint->id);

        $endpoint->fill([
            'name' => $data['name'],
            'url' => $data['url'],
            'is_active' => (bool) ($data['is_active'] ?? $endpoint->is_active),
        ]);

        // A blank secret keeps the stored one: the secret is write-only.
        if (($data['secret'] ?? '') !== '') {
            $endpoint->secret = $data['secret'];
        }

        $endpoint->save();

        $this->audit->record('automation.webhook_endpoint.updated', $endpoint, ['url' => $endpoint->url, 'secret_changed' => ($data['secret'] ?? '') !== '']);
        Toast::success(__('Webhook endpoint saved.'));

        return back();
    }

    public function destroy(Request $request, WebhookEndpoint $endpoint): RedirectResponse
    {
        $inUse = WorkflowVersion::query()
            ->where('status', VersionStatus::Published->value)
            ->where('graph', 'like', '%'.$endpoint->id.'%')
            ->exists();

        if ($inUse) {
            throw ValidationException::withMessages(['endpoint' => __('A live workflow uses this endpoint. Switch it off instead.')]);
        }

        $this->audit->record('automation.webhook_endpoint.deleted', $endpoint, ['url' => $endpoint->url]);
        $endpoint->delete();
        Toast::success(__('Webhook endpoint removed.'));

        return back();
    }

    private function assertNameFree(string $name, ?string $ignore): void
    {
        $taken = WebhookEndpoint::query()->where('name', $name)->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('An endpoint with this name already exists.')]);
        }
    }
}
