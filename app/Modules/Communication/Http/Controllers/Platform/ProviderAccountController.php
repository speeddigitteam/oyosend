<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers\Platform;

use App\Core\Support\Toast;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Communication\Actions\ProviderAccounts;
use App\Modules\Communication\Channels\Contracts\PlatformAccountProvider;
use App\Modules\Communication\Channels\Data\SendResult;
use App\Modules\Communication\Models\ProviderAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Admin > Messaging providers. Routes live in routes/platform.php behind `can:platform.settings.manage`.
 * Secrets are write-only: no response of this controller carries one.
 */
final class ProviderAccountController
{
    public function __construct(private readonly ProviderAccounts $accounts) {}

    public function index(): Response
    {
        return Inertia::render('Platform/Pages/Messaging/Index', [
            'accounts' => ProviderAccount::query()->orderBy('channel')->orderBy('name')->get()
                ->map(fn (ProviderAccount $account): array => $this->accounts->present($account))
                ->all(),
            'providers' => array_map(static fn (PlatformAccountProvider $adapter): array => [
                'channel' => $adapter->channel()->value,
                'key' => $adapter->key(),
                'label' => $adapter->label(),
                'supportsTest' => $adapter->supportsTestSend(),
                'fields' => $adapter->accountFields(),
            ], $this->accounts->adapters()),
            'workspaces' => Tenant::query()->orderBy('name')->get(['id', 'name'])
                ->map(static fn (Tenant $tenant): array => ['id' => (string) $tenant->id, 'name' => (string) $tenant->name])
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->accounts->create($this->input($request));
        Toast::success(__('Provider account added.'));

        return back();
    }

    public function update(Request $request, string $account): RedirectResponse
    {
        $this->accounts->update(ProviderAccount::query()->findOrFail($account), $this->input($request));
        Toast::success(__('Provider account saved.'));

        return back();
    }

    public function destroy(string $account): RedirectResponse
    {
        $this->accounts->delete(ProviderAccount::query()->findOrFail($account));
        Toast::success(__('Provider account removed.'));

        return back();
    }

    public function test(Request $request, string $account): RedirectResponse
    {
        $input = $request->validate(['to' => ['required', 'string', 'max:190']]);
        $result = $this->accounts->sendTest(ProviderAccount::query()->findOrFail($account), $input['to']);

        if ($result->outcome === SendResult::ACCEPTED) {
            Toast::success(__('The provider accepted the test message.'));
        } else {
            // The provider's code, never its text.
            Toast::error(__('The test message was not sent (:code).', ['code' => $result->code ?? 'unknown']));
        }

        return back();
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return $request->validate([
            'channel' => ['sometimes', 'string', 'max:20'],
            'provider' => ['sometimes', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:80'],
            'is_enabled' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.*' => ['nullable'],
            'secrets' => ['sometimes', 'array'],
            'secrets.*' => ['nullable', 'string', 'max:4096'],
            'tenant_ids' => ['nullable', 'array'],
            'tenant_ids.*' => ['string', 'max:40'],
        ]);
    }
}
