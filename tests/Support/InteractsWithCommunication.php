<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Access\RoleManager;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Communication\Actions\ConsentManager;
use App\Modules\Communication\Channels\HmacSignature;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Testing\InMemoryProvider;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Building blocks for Communication tests: an entitled workspace, members with exact permissions, contacts,
 * recorded consent, a connected test provider and signed webhook requests.
 *
 * @mixin TestCase
 */
trait InteractsWithCommunication
{
    protected function commTenant(string $slug = 'comm-ws'): Tenant
    {
        InMemoryProvider::reset();
        config(['communication.credentials' => []]);

        $tenant = $this->createTenant($slug, ['timezone' => 'Asia/Dhaka', 'currency' => 'BDT', 'locale' => 'en']);
        $this->entitle($tenant, 'communication');

        return $tenant;
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, string>  $scopes  permission => own|department|branch|all
     * @return array{0: User, 1: TenantMembership}
     */
    protected function commMember(Tenant $tenant, array $permissions, array $scopes = []): array
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function commContact(Tenant $tenant, array $attributes = [], ?TenantMembership $owner = null): Contact
    {
        return $this->inTenant($tenant, function () use ($attributes, $owner): Contact {
            $contact = Contact::factory()->create([
                'first_name' => 'Rahim',
                'last_name' => 'Uddin',
                'email' => 'rahim'.bin2hex(random_bytes(3)).'@example.test',
                'phone' => '+8801711000000',
                ...$attributes,
            ]);

            if ($owner !== null) {
                $contact->assignOwner($owner)->save();
            }

            return $contact;
        });
    }

    protected function commCompany(Tenant $tenant, string $name = 'Acme Ltd'): Company
    {
        return $this->inTenant($tenant, fn (): Company => Company::factory()->create(['name' => $name]));
    }

    protected function optIn(Tenant $tenant, Contact $contact, Channel $channel = Channel::Email): void
    {
        $this->inTenant($tenant, fn () => app(ConsentManager::class)->record(
            $contact, $channel, ConsentStatus::OptedIn, 'form', 'Ticked the box on the website form',
        ));
    }

    /**
     * Connect a channel to the in-memory test provider (testing environment only) with a webhook secret.
     */
    protected function connectFakeProvider(Tenant $tenant, Channel $channel = Channel::Sms, string $secret = 'whsec-test'): ChannelConnection
    {
        config(['communication.credentials.fake_webhook' => ['secret' => $secret, 'tenants' => null]]);

        return $this->inTenant($tenant, fn (): ChannelConnection => ChannelConnection::query()->create([
            'channel' => $channel,
            'provider' => $channel === Channel::Sms ? InMemoryProvider::KEY_SMS : InMemoryProvider::KEY_WHATSAPP,
            'is_enabled' => true,
            'webhook_secret_ref' => 'fake_webhook',
        ]));
    }

    /**
     * POST a raw webhook body to a workspace, signed (or not) the way the test provider expects.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function webhook(Tenant $tenant, string $provider, array $payload, ?string $secret = 'whsec-test', ?string $signature = null): TestResponse
    {
        $body = (string) json_encode($payload);
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($secret !== null || $signature !== null) {
            $server['HTTP_X_SIGNATURE'] = $signature ?? HmacSignature::sign($body, (string) $secret);
        }

        return $this->call('POST', $this->tenantUrl($tenant, '/communication/webhooks/'.$provider), [], [], [], $server, $body);
    }

    protected function commUrl(Tenant $tenant, string $path): string
    {
        return $this->tenantUrl($tenant, '/communication'.$path);
    }
}
