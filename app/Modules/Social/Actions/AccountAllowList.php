<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Access\Models\Role;
use App\Core\Audit\AuditLogger;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Modules\Social\Models\AccountAccess;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who may use a connected account, on top of the permissions they already need.
 *
 * An account with no entries is open to every member who holds the matching permission (the
 * default, unchanged behaviour). Once an account has entries it is restricted: only the owner
 * and the members or roles an entry names, with the ability the entry grants, may use it.
 * Abilities: `publish` (draft for it, submit, schedule, retry), `approve`, `analytics`.
 * Checked server-side everywhere a post, approval or report reaches an account.
 */
final class AccountAllowList
{
    public const string PUBLISH = 'publish';

    public const string APPROVE = 'approve';

    public const string ANALYTICS = 'analytics';

    /** @var list<string> */
    public const array ABILITIES = [self::PUBLISH, self::APPROVE, self::ANALYTICS];

    public function __construct(
        private readonly AccessChecker $access,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Of `$accountIds` (all of the workspace's accounts when null) the ones `$user` may use for `$ability`.
     *
     * @param  list<string>|null  $accountIds
     * @return list<string>
     */
    public function allowedIds(User $user, string $ability, ?array $accountIds = null): array
    {
        $this->assertAbility($ability);

        $ids = $accountIds ?? SocialAccount::query()->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $ids = array_values(array_unique(array_map('strval', $ids)));

        if ($ids === []) {
            return [];
        }

        $entries = AccountAccess::query()->whereIn('account_id', $ids)->get()->groupBy('account_id');

        if ($entries->isEmpty()) {
            return $ids;
        }

        $membership = $this->access->membership($user);

        if ($membership !== null && $membership->is_owner) {
            return $ids;
        }

        $roleIds = $this->roleIds($user);
        $column = 'can_'.$ability;
        $allowed = [];

        foreach ($ids as $id) {
            $rows = $entries->get($id);

            if ($rows === null || $rows->isEmpty()) {
                $allowed[] = $id;

                continue;
            }

            foreach ($rows as $row) {
                $matches = ($row->subject_type === AccountAccess::MEMBERSHIP && $membership !== null && $row->subject_id === $membership->id)
                    || ($row->subject_type === AccountAccess::ROLE && in_array($row->subject_id, $roleIds, true));

                if ($matches && $row->{$column}) {
                    $allowed[] = $id;
                    break;
                }
            }
        }

        return $allowed;
    }

    /**
     * The account ids `$user` may see analytics for when the workspace has any allow-list entry;
     * null (no narrowing) when none exists, so unrestricted workspaces behave as before.
     *
     * @return list<string>|null
     */
    public function analyticsScope(User $user): ?array
    {
        if (! AccountAccess::query()->exists()) {
            return null;
        }

        return $this->allowedIds($user, self::ANALYTICS);
    }

    public function allows(User $user, string $ability, string $accountId): bool
    {
        return $this->allowedIds($user, $ability, [$accountId]) !== [];
    }

    /**
     * @param  iterable<string>  $accountIds
     *
     * @throws AuthorizationException when any account is outside what `$user` may do
     */
    public function assert(User $user, string $ability, iterable $accountIds): void
    {
        $ids = array_values(array_unique(array_map('strval', is_array($accountIds) ? $accountIds : iterator_to_array($accountIds, false))));
        $allowed = $this->allowedIds($user, $ability, $ids);
        $denied = array_values(array_diff($ids, $allowed));

        if ($denied === []) {
            return;
        }

        $names = SocialAccount::query()->whereIn('id', $denied)->orderBy('name')->pluck('name')->all();

        throw new AuthorizationException(__('You are not on the allow-list of :accounts for this action.', ['accounts' => implode(', ', $names)]));
    }

    /**
     * The allow-list of one account, as the settings dialog edits it.
     *
     * @return list<array{subjectType: string, subjectId: string, canPublish: bool, canApprove: bool, canAnalytics: bool}>
     */
    public function entries(SocialAccount $account): array
    {
        return AccountAccess::query()->where('account_id', $account->id)->orderBy('subject_type')->orderBy('id')->get()
            ->map(static fn (AccountAccess $row): array => [
                'subjectType' => $row->subject_type,
                'subjectId' => $row->subject_id,
                'canPublish' => $row->can_publish,
                'canApprove' => $row->can_approve,
                'canAnalytics' => $row->can_analytics,
            ])->all();
    }

    /**
     * Replace the allow-list of `$account`. An empty list opens the account again.
     *
     * @param  list<array{subject_type: string, subject_id: string, can_publish?: bool, can_approve?: bool, can_analytics?: bool}>  $entries
     */
    public function replace(SocialAccount $account, array $entries): void
    {
        $rows = [];

        foreach ($entries as $entry) {
            $type = $entry['subject_type'];
            $subject = $entry['subject_id'];

            if ($type === AccountAccess::MEMBERSHIP) {
                $exists = TenantMembership::query()->whereKey($subject)->where('status', MembershipStatus::Active)->exists();
            } elseif ($type === AccountAccess::ROLE) {
                $exists = Role::query()->forCurrentTenant()->whereKey($subject)->exists();
            } else {
                $exists = false;
            }

            if (! $exists) {
                throw ValidationException::withMessages(['entries' => __('One of the chosen members or roles does not exist in this workspace.')]);
            }

            $key = $type.':'.$subject;
            $rows[$key] = [
                'subject_type' => $type,
                'subject_id' => $subject,
                'can_publish' => (bool) ($entry['can_publish'] ?? false),
                'can_approve' => (bool) ($entry['can_approve'] ?? false),
                'can_analytics' => (bool) ($entry['can_analytics'] ?? false),
            ];
        }

        DB::transaction(function () use ($account, $rows): void {
            AccountAccess::query()->where('account_id', $account->id)->delete();

            foreach ($rows as $row) {
                AccountAccess::query()->create(['account_id' => $account->id, ...$row]);
            }

            $this->audit->record('social.account.access_changed', $account, ['entries' => count($rows)]);
        });
    }

    /** @return list<string> */
    private function roleIds(User $user): array
    {
        $tenantId = $this->context->requireId();

        return (new Role)->getConnection()
            ->table('model_has_roles')
            ->where('tenant_id', $tenantId)
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->pluck('role_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    private function assertAbility(string $ability): void
    {
        if (! in_array($ability, self::ABILITIES, true)) {
            throw new \InvalidArgumentException('Unknown allow-list ability.');
        }
    }
}
