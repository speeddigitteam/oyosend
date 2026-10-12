<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Communication\Enums\Channel;
use App\Modules\SmsMarketing\Actions\CampaignManager;
use App\Modules\SmsMarketing\Actions\CampaignRunner;
use App\Modules\SmsMarketing\Actions\GroupManager;
use App\Modules\SmsMarketing\Models\Campaign;
use App\Modules\SmsMarketing\Models\Group;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Building blocks for SMS Marketing tests: a workspace entitled to `communication` and `sms_marketing`,
 * contacts with Bangladeshi numbers, SMS consent, the in-memory SMS provider (testing environment only),
 * groups, campaigns and a direct call of the campaign runner (the testing queue is synchronous).
 *
 * @mixin TestCase
 */
trait InteractsWithSmsMarketing
{
    use InteractsWithCommunication;

    protected function smsTenant(string $slug = 'sms-ws'): Tenant
    {
        $tenant = $this->commTenant($slug);
        $this->entitle($tenant, 'sms_marketing');

        return $tenant;
    }

    protected function smsUrl(Tenant $tenant, string $path): string
    {
        return $this->tenantUrl($tenant, '/sms-marketing'.$path);
    }

    /**
     * A member with exactly these permissions.
     *
     * @param  list<string>  $permissions
     * @param  array<string, string>  $scopes
     * @return array{0: User, 1: TenantMembership}
     */
    protected function smsMember(Tenant $tenant, array $permissions, array $scopes = []): array
    {
        return $this->commMember($tenant, $permissions, $scopes);
    }

    /** A contact with a mobile number; `optedIn` records SMS marketing consent. */
    protected function smsContact(Tenant $tenant, string $phone = '+8801711000001', bool $optedIn = true, array $attributes = []): Contact
    {
        $contact = $this->commContact($tenant, ['phone' => $phone, ...$attributes]);

        if ($optedIn) {
            $this->optIn($tenant, $contact, Channel::Sms);
        }

        return $contact;
    }

    /** @param  list<string>  $contactIds */
    protected function smsGroup(Tenant $tenant, array $contactIds = [], string $name = 'Customers'): Group
    {
        return $this->inTenant($tenant, function () use ($contactIds, $name): Group {
            $group = app(GroupManager::class)->create(['name' => $name]);

            if ($contactIds !== []) {
                app(GroupManager::class)->addContacts($group, $contactIds);
            }

            return $group;
        });
    }

    /**
     * @param  list<string>  $groupIds
     * @param  array<string, mixed>  $attributes
     */
    protected function smsCampaign(Tenant $tenant, array $groupIds, array $attributes = []): Campaign
    {
        return $this->inTenant($tenant, fn (): Campaign => app(CampaignManager::class)->create([
            'name' => 'Eid offer '.bin2hex(random_bytes(3)),
            'body' => 'Hello {{first_name|friend}}, our Eid sale starts today.',
            ...$attributes,
        ], $groupIds));
    }

    protected function launchNow(Tenant $tenant, Campaign $campaign): Campaign
    {
        return $this->inTenant($tenant, fn (): Campaign => app(CampaignManager::class)->launch($campaign, null));
    }

    /** One run of the every-minute job for the workspace. */
    protected function runCampaigns(Tenant $tenant): array
    {
        return $this->inTenant($tenant, fn (): array => app(CampaignRunner::class)->run());
    }

    protected function inSmsMinutes(int $minutes): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes($minutes);
    }
}
