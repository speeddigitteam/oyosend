<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\MetaAds\Api\MarketingApi;
use App\Modules\MetaAds\Api\MetaApiException;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Enums\ChangeAction;
use App\Modules\MetaAds\Enums\ChangeStatus;
use App\Modules\MetaAds\Enums\ObjectLevel;
use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Models\AdObject;
use App\Modules\MetaAds\Models\Change;
use App\Modules\MetaAds\Support\MinorUnits;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only writes this app makes to Meta: pause or resume a campaign, ad set or ad, and edit a campaign's or
 * ad set's own daily or lifetime budget.
 *
 * Safety, in order of checking:
 *  - management is switched on for the workspace, the platform app asks for `ads_management`, and the
 *    authorisation was granted it;
 *  - nothing is created, archived or deleted: status moves only between ACTIVE and PAUSED;
 *  - a budget is edited only where the object already has that kind of budget, only to a value at or under the
 *    ad account's configured ceiling, and a single increase is bounded by `changes.max_increase_percent`;
 *  - the live state on Meta is read first and must equal what the person confirmed (before/after dialog);
 *  - a bounded number of changes per rolling day;
 *  - every confirmation has a `request_id`: a replay returns the stored receipt and never calls Meta twice.
 *
 * A receipt row is written before Meta is called and finished afterwards, and each outcome is audited.
 */
final class ChangeManager
{
    private const array ENDED = ['ARCHIVED', 'DELETED'];

    public function __construct(
        private readonly MarketingApi $api,
        private readonly MetaAdsSettings $settings,
        private readonly ConnectionManager $connections,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  string  $expected  What the person saw before confirming: `ACTIVE` / `PAUSED` for a status change,
     *                            the current budget in minor units for a budget change.
     * @param  int|null  $budget  The new budget in minor units (budget changes only).
     *
     * @throws ValidationException
     */
    public function apply(AdObject $object, ChangeAction $action, string $expected, ?int $budget, string $requestId, ?TenantMembership $by): Change
    {
        $existing = Change::query()->where('request_id', $requestId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $account = AdAccount::query()->with('connection')->findOrFail($object->account_id);
        $this->assertAllowed($account, $object);

        [$field, $before, $after, $currency] = $this->plan($account, $object, $action, $expected, $budget);

        $change = $this->open($account, $object, $action, $field, $before, $after, $currency, $requestId, $by);

        if ($change->status !== ChangeStatus::Pending || $change->wasRecentlyCreated === false) {
            return $change;
        }

        $token = (string) $account->connection?->access_token;

        try {
            $live = $this->api->get($token, $object->external_id, ['fields' => 'status,effective_status,daily_budget,lifetime_budget'])->data;

            if (! $this->matchesLive($live, $field, $before)) {
                $this->refresh($object, $live);

                return $this->fail($change, 'stale', __('This changed on Meta since you opened it. Review the current value and try again.'), true);
            }

            $value = $field === 'status' ? ($after === 'PAUSED' ? 'PAUSED' : 'ACTIVE') : (string) $budget;
            $this->api->post($token, $object->external_id, [$field => $value]);

            $confirmed = $this->api->get($token, $object->external_id, ['fields' => 'status,effective_status,daily_budget,lifetime_budget'])->data;
            $this->refresh($object, $confirmed);
        } catch (MetaApiException $exception) {
            if ($exception->needsReauthorization() && $account->connection !== null) {
                $this->connections->markNeedsReauthorization($account->connection, $exception->getMessage());
            }

            return $this->fail($change, $exception->errorCode, $exception->getMessage(), true);
        }

        $change->forceFill(['status' => ChangeStatus::Applied, 'applied_at' => now(), 'error_code' => null, 'error_message' => null])->save();

        $this->audit->record('meta_ads.change.applied', $object, [
            'action' => $action->value,
            'field' => $field,
            'level' => $object->level->value,
            'account' => $account->external_id,
            'object' => $object->external_id,
            'before' => $before,
            'after' => $after,
            'currency' => $currency,
            'change' => $change->id,
        ], subjectLabel: $object->name);

        return $change;
    }

    /** Why changes cannot be made for this ad account right now, or null when they can. */
    public function accountBlockReason(AdAccount $account): ?string
    {
        try {
            $this->assertAccountAllowed($account->loadMissing('connection'));
        } catch (ValidationException $exception) {
            return (string) collect($exception->errors())->flatten()->first();
        }

        return null;
    }

    /** Why this object itself cannot be changed (archived, gone), or null. */
    public function objectBlockReason(AdObject $object): ?string
    {
        if ($object->missing_at !== null || in_array((string) $object->effective_status, self::ENDED, true)) {
            return __('This item is archived or no longer exists on Meta.');
        }

        return null;
    }

    /** @throws ValidationException */
    private function assertAllowed(AdAccount $account, AdObject $object): void
    {
        $this->assertAccountAllowed($account);
        $reason = $this->objectBlockReason($object);

        if ($reason !== null) {
            throw ValidationException::withMessages(['change' => $reason]);
        }
    }

    /** @throws ValidationException */
    private function assertAccountAllowed(AdAccount $account): void
    {
        $fail = static fn (string $message): never => throw ValidationException::withMessages(['change' => $message]);

        if (! $this->settings->managementEnabled()) {
            $fail(__('Changing campaigns from here is switched off for this workspace. Turn it on in Meta Ads settings.'));
        }

        $connection = $account->connection;

        if ($account->status !== AdAccountStatus::Tracked || $connection === null || ! $connection->isUsable()) {
            $fail(__('This ad account is not connected. Authorise Meta again.'));
        }

        if (! $connection->canManage()) {
            $fail(__('The Meta authorisation does not include permission to manage ads. Authorise again and allow it.'));
        }

        if ($account->isThrottled()) {
            $fail(__('Meta is limiting requests for this ad account right now. Try again later.'));
        }

        $today = Change::query()->where('requested_at', '>=', now()->subDay())->where('status', '!=', ChangeStatus::Failed->value)->count();

        if ($today >= (int) config('meta_ads.changes.max_changes_per_day', 30)) {
            $fail(__('The daily limit for changes from this workspace has been reached. Try again tomorrow.'));
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string|null} field, before, after, currency
     *
     * @throws ValidationException
     */
    private function plan(AdAccount $account, AdObject $object, ChangeAction $action, string $expected, ?int $budget): array
    {
        $fail = static fn (string $field, string $message): never => throw ValidationException::withMessages([$field => $message]);

        if ($action !== ChangeAction::Budget) {
            $current = strtoupper((string) $object->status);
            $needs = $action === ChangeAction::Pause ? 'ACTIVE' : 'PAUSED';

            if ($current !== $needs) {
                $fail('change', $action === ChangeAction::Pause
                    ? __('Only an active item can be paused.')
                    : __('Only a paused item can be resumed.'));
            }

            if ($expected !== $current) {
                $fail('change', __('This changed since you opened it. Review the current value and try again.'));
            }

            return ['status', $current, $action === ChangeAction::Pause ? 'PAUSED' : 'ACTIVE', null];
        }

        if ($object->level === ObjectLevel::Ad) {
            $fail('budget', __('Ads have no budget of their own. Edit the ad set or the campaign.'));
        }

        $field = $object->daily_budget !== null ? 'daily_budget' : ($object->lifetime_budget !== null ? 'lifetime_budget' : null);

        if ($field === null) {
            $fail('budget', __('This item has no budget of its own. The budget is set on its campaign or ad set.'));
        }

        $current = (int) $object->{$field};
        $ceiling = $field === 'daily_budget' ? $account->daily_budget_ceiling : $account->lifetime_budget_ceiling;

        if ($ceiling === null) {
            $fail('budget', __('Set a budget ceiling for this ad account in Meta Ads settings first.'));
        }

        if ($budget === null || $budget < 1) {
            $fail('budget', __('Enter a budget greater than zero.'));
        }

        if ($expected !== (string) $current) {
            $fail('budget', __('The budget changed since you opened it. Review the current value and try again.'));
        }

        if ($budget === $current) {
            $fail('budget', __('The new budget is the same as the current one.'));
        }

        if ($budget > $ceiling) {
            $fail('budget', __('That is above the ceiling you set for this ad account (:ceiling).', [
                'ceiling' => MinorUnits::toDecimal($ceiling, $account->currency).' '.$account->currency,
            ]));
        }

        $step = (int) config('meta_ads.changes.max_increase_percent', 100);

        if ($budget > $current && $budget * 100 > $current * (100 + $step)) {
            $fail('budget', __('A single increase can be at most :percent% above the current budget.', ['percent' => $step]));
        }

        return [$field, (string) $current, (string) $budget, $account->currency];
    }

    private function open(AdAccount $account, AdObject $object, ChangeAction $action, string $field, string $before, string $after, ?string $currency, string $requestId, ?TenantMembership $by): Change
    {
        try {
            return DB::transaction(fn (): Change => Change::query()->create([
                'account_id' => $account->id,
                'object_id' => $object->id,
                'requested_by' => $by?->id,
                'request_id' => $requestId,
                'action' => $action,
                'field' => $field,
                'level' => $object->level,
                'object_name' => Str::limit($object->name, 250, ''),
                'before_value' => $before,
                'after_value' => $after,
                'currency' => $currency,
                'status' => ChangeStatus::Pending,
                'requested_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return Change::query()->where('request_id', $requestId)->firstOrFail();
        }
    }

    private function fail(Change $change, string $code, string $message, bool $throw): Change
    {
        $change->forceFill([
            'status' => ChangeStatus::Failed,
            'error_code' => Str::limit($code, 60, ''),
            'error_message' => Str::limit($message, 290, ''),
        ])->save();

        $this->audit->record('meta_ads.change.failed', null, [
            'change' => $change->id,
            'action' => $change->action->value,
            'object' => $change->object_id,
            'code' => $change->error_code,
        ], subjectLabel: $change->object_name);

        if ($throw) {
            throw ValidationException::withMessages(['change' => $message]);
        }

        return $change;
    }

    /** @param array<string, mixed> $live */
    private function matchesLive(array $live, string $field, string $before): bool
    {
        $value = $live[$field] ?? null;

        if ($field === 'status') {
            return is_string($value) && strtoupper($value) === $before;
        }

        return MinorUnits::fromInteger($value) === (int) $before;
    }

    /** @param array<string, mixed> $live */
    private function refresh(AdObject $object, array $live): void
    {
        $status = $live['status'] ?? null;
        $effective = $live['effective_status'] ?? null;

        $budget = static function (mixed $value): ?int {
            $minor = MinorUnits::fromInteger($value);

            return $minor === null || $minor === 0 ? null : $minor;
        };

        $object->forceFill([
            'status' => is_string($status) ? Str::limit($status, 24, '') : $object->status,
            'effective_status' => is_string($effective) ? Str::limit($effective, 40, '') : $object->effective_status,
            'daily_budget' => array_key_exists('daily_budget', $live) ? $budget($live['daily_budget']) : $object->daily_budget,
            'lifetime_budget' => array_key_exists('lifetime_budget', $live) ? $budget($live['lifetime_budget']) : $object->lifetime_budget,
        ])->save();
    }
}
