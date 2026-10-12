<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Access\RoleManager;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\EmailMarketing\Actions\CampaignManager;
use App\Modules\EmailMarketing\Actions\CampaignRunner;
use App\Modules\EmailMarketing\Actions\EmailMarketingSettings;
use App\Modules\EmailMarketing\Actions\ListManager;
use App\Modules\EmailMarketing\Actions\SequenceRunner;
use App\Modules\EmailMarketing\Actions\SubscriberManager;
use App\Modules\EmailMarketing\Enums\SubscriptionSource;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\Subscription;
use Tests\TestCase;

/**
 * Building blocks for Email Marketing tests: a workspace entitled to the app and to the communication platform
 * it sends through, members with exact permissions, contacts with platform consent, lists, subscribers,
 * campaigns and a way to run the every-minute runner by hand.
 *
 * @mixin TestCase
 */
trait InteractsWithEmailMarketing
{
    use InteractsWithCommunication;

    /** The block list of a small, valid email. */
    protected const array BLOCKS = [
        ['type' => 'heading', 'text' => 'Hello {{first_name|friend}}', 'level' => 1, 'align' => 'left'],
        ['type' => 'text', 'text' => "Our **spring sale** starts today.\n\nRead more at [our site](https://example.com/sale).", 'align' => 'left'],
        ['type' => 'button', 'label' => 'Shop now', 'url' => 'https://example.com/shop', 'align' => 'center', 'style' => 'primary'],
    ];

    protected function emTenant(string $slug = 'em-ws', bool $footer = true): Tenant
    {
        $tenant = $this->commTenant($slug);
        $this->entitle($tenant, 'email_marketing');

        if ($footer) {
            $this->inTenant($tenant, fn () => app(EmailMarketingSettings::class)->update(['footer_address' => '12 Gulshan Avenue, Dhaka 1212']));
        }

        return $tenant;
    }

    protected function emUrl(Tenant $tenant, string $path = ''): string
    {
        return $this->tenantUrl($tenant, '/email-marketing'.$path);
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, string>  $scopes
     * @return array{0: User, 1: TenantMembership}
     */
    protected function emMember(Tenant $tenant, array $permissions, array $scopes = []): array
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

    protected function emList(Tenant $tenant, string $name = 'Newsletter'): MailingList
    {
        return $this->inTenant($tenant, fn (): MailingList => app(ListManager::class)->create(['name' => $name]));
    }

    /** A contact with a unique address. */
    protected function emContact(Tenant $tenant, array $attributes = []): Contact
    {
        return $this->commContact($tenant, $attributes);
    }

    /**
     * Subscribe a contact with evidence on the list. With `$platformConsent` the communication app also holds an
     * opt-in, which is what makes the contact sendable.
     */
    protected function emSubscribe(Tenant $tenant, MailingList $list, Contact $contact, bool $platformConsent = true, bool $evidence = true): Subscription
    {
        if ($platformConsent) {
            $this->optIn($tenant, $contact);
        }

        return $this->inTenant($tenant, fn (): Subscription => app(SubscriberManager::class)->add(
            $list,
            $contact->id,
            $evidence ? ['consent_method' => 'website_form', 'consent_evidence' => 'Ticked the box on the newsletter form'] : [],
            SubscriptionSource::Manual,
        ));
    }

    /** @param  array<string, mixed>  $overrides */
    protected function emCampaign(Tenant $tenant, MailingList $list, array $overrides = [], ?TenantMembership $owner = null): Campaign
    {
        return $this->inTenant($tenant, fn (): Campaign => app(CampaignManager::class)->create([
            'name' => 'Spring sale',
            'subject' => 'Spring sale for {{first_name|you}}',
            'blocks' => self::BLOCKS,
            'list_id' => $list->id,
            ...$overrides,
        ], $owner));
    }

    /** Run one pass of the runner (campaigns, then sequences) for the workspace. */
    protected function emRun(Tenant $tenant): int
    {
        return $this->inTenant($tenant, function (): int {
            $campaigns = app(CampaignRunner::class);
            $handed = $campaigns->run();
            app(SequenceRunner::class)->run($campaigns->leftover($handed));

            return $handed;
        });
    }
}
