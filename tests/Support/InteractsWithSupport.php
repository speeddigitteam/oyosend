<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Access\RoleManager;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Support\Actions\SlaConfiguration;
use App\Modules\Support\Actions\SupportCatalogue;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Models\Agent;
use App\Modules\Support\Models\Calendar;
use App\Modules\Support\Models\Queue;
use App\Modules\Support\Models\SlaPolicy;
use App\Modules\Support\Models\Ticket;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Building blocks for Support tests: a workspace entitled to the app (Dhaka timezone), members holding exact
 * permissions with scopes, enrolled agents, queues, calendars, SLA policies and tickets.
 *
 * @mixin TestCase
 */
trait InteractsWithSupport
{
    protected function supportTenant(string $slug = 'support-ws'): Tenant
    {
        $tenant = $this->createTenant($slug, ['timezone' => 'Asia/Dhaka', 'currency' => 'BDT', 'name' => 'Support Workspace']);
        $this->entitle($tenant, 'support');

        return $tenant;
    }

    protected function supportUrl(Tenant $tenant, string $path): string
    {
        return $this->tenantUrl($tenant, '/support'.$path);
    }

    /**
     * A member holding exactly the given permissions (scopes: permission => own|department|branch|all).
     *
     * @param  list<string>  $permissions
     * @param  array<string, string>  $scopes
     * @return array{0: User, 1: TenantMembership}
     */
    protected function supportMember(Tenant $tenant, array $permissions, array $scopes = []): array
    {
        $owner = $this->ownerOf($tenant);
        $role = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
            $owner,
            'Role '.bin2hex(random_bytes(4)),
            null,
            $permissions,
            $scopes,
        ));
        $user = $this->addMember($tenant, $role);

        return [$user, $this->membershipOf($tenant, $user)];
    }

    /** Enrols the member as a support agent. */
    protected function supportAgent(Tenant $tenant, TenantMembership $membership, array $attributes = []): Agent
    {
        return $this->inTenant($tenant, fn (): Agent => app(SupportCatalogue::class)->enrolAgent($membership->id, $attributes));
    }

    /** An enrolled agent who is the owner of the workspace. */
    protected function supportOwnerAgent(Tenant $tenant): TenantMembership
    {
        $membership = $this->membershipOf($tenant, $this->ownerOf($tenant));
        $this->supportAgent($tenant, $membership);

        return $membership;
    }

    /** @param array<string, mixed> $attributes */
    protected function supportQueue(Tenant $tenant, array $attributes = []): Queue
    {
        return $this->inTenant($tenant, fn (): Queue => app(SupportCatalogue::class)->saveQueue(null, [
            'name' => 'Queue '.bin2hex(random_bytes(3)),
            'assignment_mode' => 'manual',
            'is_active' => true,
            ...$attributes,
        ]));
    }

    /**
     * Monday to Friday 09:00-17:00 in the workspace timezone unless told otherwise.
     *
     * @param  array<string, mixed>  $hours
     * @param  list<string>  $holidays
     */
    protected function supportCalendar(Tenant $tenant, ?array $hours = null, array $holidays = [], ?string $timezone = null): Calendar
    {
        $hours ??= array_fill_keys([1, 2, 3, 4, 5], [['09:00', '17:00']]);

        return $this->inTenant($tenant, fn (): Calendar => app(SlaConfiguration::class)->saveCalendar(null, [
            'name' => 'Hours '.bin2hex(random_bytes(3)),
            'timezone' => $timezone,
            'hours' => $hours,
            'holidays' => $holidays,
        ]));
    }

    /**
     * @param  array<string, array{first_response: int, resolution: int}>|null  $targets
     */
    protected function supportPolicy(Tenant $tenant, ?array $targets = null, ?Calendar $calendar = null, bool $pause = true): SlaPolicy
    {
        return $this->inTenant($tenant, fn (): SlaPolicy => app(SlaConfiguration::class)->savePolicy(null, [
            'name' => 'Policy '.bin2hex(random_bytes(3)),
            'calendar_id' => $calendar?->id,
            'targets' => $targets ?? [
                'low' => ['first_response' => 240, 'resolution' => 960],
                'normal' => ['first_response' => 60, 'resolution' => 480],
                'high' => ['first_response' => 30, 'resolution' => 240],
                'urgent' => ['first_response' => 15, 'resolution' => 120],
            ],
            'pause_on_waiting' => $pause,
        ]));
    }

    /**
     * A queue governed by a policy; returns the queue.
     *
     * @param  array<string, mixed>  $queue
     */
    protected function supportQueueWithPolicy(Tenant $tenant, SlaPolicy $policy, array $queue = []): Queue
    {
        return $this->supportQueue($tenant, ['sla_policy_id' => $policy->id, ...$queue]);
    }

    /**
     * Opens a ticket through the real manager.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function supportTicket(Tenant $tenant, array $overrides = [], ?TenantMembership $actor = null, TicketChannel $channel = TicketChannel::Web): Ticket
    {
        return $this->inTenant($tenant, fn (): Ticket => app(TicketManager::class)->create([
            'subject' => 'Cannot sign in',
            'body' => 'I cannot sign in to my account.',
            'requester_name' => 'Nadia Karim',
            'requester_email' => 'nadia@example.test',
            ...$overrides,
        ], $actor, $channel));
    }

    /** Sets the clock to a local Dhaka date and time. */
    protected function supportAt(string $local): void
    {
        $this->travelTo(CarbonImmutable::parse($local, 'Asia/Dhaka'));
    }

    /** @return list<string> */
    protected function supportAllPermissions(): array
    {
        return [
            'support.tickets.view', 'support.tickets.create', 'support.tickets.reply', 'support.tickets.edit',
            'support.tickets.assign', 'support.tickets.resolve', 'support.tickets.merge',
            'support.canned.view', 'support.canned.manage', 'support.kb.view', 'support.kb.manage', 'support.kb.publish',
            'support.queues.manage', 'support.sla.manage', 'support.settings.manage', 'support.reports.view',
        ];
    }
}
