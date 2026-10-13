<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Actions;

use App\Core\Social\Contracts\ConnectedPages;
use App\Modules\MetaAds\Api\MarketingApi;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Enums\ConnectionStatus;
use App\Modules\MetaAds\Enums\SyncState;
use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Models\Connection;

/**
 * The setup checklist shown to the workspace: what is done, what is next, what only the platform operator can
 * do and what is optional. Each item is `{key, state}` with state `done`, `todo`, `attention`, `blocked`,
 * `optional` or `unavailable`; the interface owns the wording.
 */
final class SetupReadiness
{
    public function __construct(
        private readonly MarketingApi $api,
        private readonly MetaAdsSettings $settings,
        private readonly ConnectedPages $pages,
    ) {}

    /** @return list<array{key: string, state: string}> */
    public function items(): array
    {
        $connections = Connection::query()->get();
        $usable = $connections->contains(static fn (Connection $c): bool => $c->isUsable());
        $needsAuth = $connections->contains(static fn (Connection $c): bool => $c->status === ConnectionStatus::NeedsReauthorization);
        $tracked = AdAccount::query()->where('status', AdAccountStatus::Tracked->value)->get();
        $synced = $tracked->contains(static fn (AdAccount $a): bool => $a->last_synced_at !== null);
        $syncFailing = $tracked->contains(static fn (AdAccount $a): bool => $a->sync_state === SyncState::Error);
        $managementReady = $this->settings->managementEnabled()
            && $connections->contains(static fn (Connection $c): bool => $c->canManage())
            && $tracked->contains(static fn (AdAccount $a): bool => $a->daily_budget_ceiling !== null || $a->lifetime_budget_ceiling !== null);

        $items = [
            ['key' => 'meta_app', 'state' => $this->api->configured() ? 'done' : 'blocked'],
            ['key' => 'authorize', 'state' => $usable ? 'done' : ($needsAuth ? 'attention' : ($this->api->configured() ? 'todo' : 'blocked'))],
            ['key' => 'accounts', 'state' => $tracked->isNotEmpty() ? 'done' : ($usable ? 'todo' : 'blocked')],
            ['key' => 'first_sync', 'state' => $syncFailing ? 'attention' : ($synced ? 'done' : ($tracked->isNotEmpty() ? 'todo' : 'blocked'))],
            ['key' => 'social_page', 'state' => $this->socialState()],
            ['key' => 'management', 'state' => $this->api->scopes() === ['ads_read'] ? 'unavailable' : ($managementReady ? 'done' : 'optional')],
        ];

        return $items;
    }

    /** Whether the required steps are done (the optional ones never block). */
    public function ready(): bool
    {
        foreach ($this->items() as $item) {
            if (in_array($item['key'], ['meta_app', 'authorize', 'accounts', 'first_sync'], true) && $item['state'] !== 'done') {
                return false;
            }
        }

        return true;
    }

    private function socialState(): string
    {
        if (! $this->pages->isAvailable()) {
            return 'unavailable';
        }

        foreach ($this->pages->pages() as $page) {
            if ($page->isConnected()) {
                return 'done';
            }
        }

        return 'optional';
    }
}
