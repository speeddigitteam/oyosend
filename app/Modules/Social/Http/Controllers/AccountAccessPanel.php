<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Core\Access\Models\Role;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Social\Actions\AccountAllowList;
use App\Modules\Social\Models\AccountDiagnostic;
use App\Modules\Social\Models\SocialAccount;

/**
 * What the connected-accounts page shows about access: which platform tasks and permissions
 * each token holds, which Oyosend feature needs which task, and the per-account allow-list
 * with the members and roles it can name. Never contains a token.
 */
final class AccountAccessPanel
{
    /**
     * Oyosend feature => the Meta Page task and the app permission it relies on. A task is what the
     * person who connected the Page holds on it; a permission is what the Meta app was granted.
     *
     * @var list<array{feature: string, task: string, permissions: list<string>}>
     */
    public const array FEATURES = [
        ['feature' => 'publish', 'task' => 'CREATE_CONTENT', 'permissions' => ['pages_manage_posts']],
        ['feature' => 'insights', 'task' => 'ANALYZE', 'permissions' => ['read_insights', 'pages_read_engagement']],
        ['feature' => 'comments', 'task' => 'MODERATE', 'permissions' => ['pages_read_engagement']],
        ['feature' => 'ads', 'task' => 'ADVERTISE', 'permissions' => []],
    ];

    public function __construct(private readonly AccountAllowList $allowList) {}

    /**
     * @param  list<SocialAccount>|iterable<SocialAccount>  $accounts
     * @return array<string, mixed>
     */
    public function props(iterable $accounts, bool $canManage): array
    {
        $list = [];

        foreach ($accounts as $account) {
            $list[] = $account;
        }

        $ids = array_map(static fn (SocialAccount $account): string => $account->id, $list);
        $diagnostics = AccountDiagnostic::query()->whereIn('account_id', $ids)->get()->keyBy('account_id');
        $byAccount = [];

        foreach ($list as $account) {
            $diagnostic = $diagnostics->get($account->id);
            $entries = $this->allowList->entries($account);
            $byAccount[$account->id] = [
                'tasks' => $diagnostic?->tasks,
                'tasksStatus' => $diagnostic?->tasks_status,
                'tasksCheckedAt' => $diagnostic?->tasks_checked_at?->toIso8601String(),
                'scopes' => $diagnostic?->granted_scopes,
                'insightsStatus' => $diagnostic?->insights_status,
                'insightsSyncedAt' => $diagnostic?->insights_synced_at?->toIso8601String(),
                'restricted' => $entries !== [],
                'entries' => $canManage ? $entries : [],
                'entryCount' => count($entries),
            ];
        }

        return [
            'accounts' => $byAccount,
            'features' => self::FEATURES,
            'canManage' => $canManage,
            'members' => $canManage ? $this->members() : [],
            'roles' => $canManage ? $this->roles() : [],
        ];
    }

    /** @return list<array{id: string, name: string}> */
    private function members(): array
    {
        return TenantMembership::query()
            ->where('status', MembershipStatus::Active)
            ->with('user:id,name')
            ->get()
            ->map(static fn (TenantMembership $membership): array => [
                'id' => $membership->id,
                'name' => $membership->user->name ?? $membership->id,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /** @return list<array{id: string, name: string}> */
    private function roles(): array
    {
        return Role::query()->forCurrentTenant()->orderBy('name')->get(['id', 'name'])
            ->map(static fn (Role $role): array => ['id' => (string) $role->id, 'name' => $role->name])
            ->all();
    }
}
